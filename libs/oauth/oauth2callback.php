<?php
	error_log('[wpForGlass] oauth2callback accessed');

	session_start();
	if (isset($_GET['base']) == true){
		$_SESSION['base'] = $_GET['base'];
	}
	# No need for the template engine
	define( 'WP_USE_THEMES', false );
	//include wp_core but without the templating or output
	$parse_uri = explode('wp-content', $_SERVER['SCRIPT_FILENAME']);
	$wp_load = $parse_uri[0].'wp-load.php';
	require_once($wp_load);
	
	require_once('../WpForGlass.php');
	
	$myGlass = new WpForGlass();
	$client = $myGlass->get_google_api_client();
	// We set offline access to get the refresh token.
	// This prevents us from needing to re-authenticate all the time.
	$client->setAccessType("offline");
	$client->setApprovalPrompt("force");

	if (isset($_GET['code'])) {
		// Handle step 2 of the OAuth 2.0 dance - code exchange
		try {
			$myGlass->logError('doing code exchange');
			// In 1.0.0, default changed from offline to online.
			// We set offline access to get the refresh token.
			$client->setAccessType("offline");
			$client->setApprovalPrompt("force");
			$client->authenticate($_GET['code']);
			$access_token = $client->getAccessToken();
			$idGlassPress = new WpForGlass();
	
			// Use the identity service to get their ID
			$identity_client = $idGlassPress->get_google_api_client();
			$identity_client->setAccessToken($access_token);
			$identity_service = new Google_Service_Oauth2($identity_client);
	
			$user = $identity_service->userinfo->get();
			$user_id = $user->getId();
			
			// Store their credentials and register their ID with their session
			$credentials = $client->getAccessToken();
			$myGlass->store_credentials($user_id, $credentials);
	
			// Bootstrap the new user by inserting a welcome message, a contact,
			// and subscribing them to timeline notifications
			$myGlass->bootstrap_new_user($user_id);

			// have a successful auth, redirect back to the base url
			header('Location: ' . 	$_SESSION['base'].'&auth_code=1');
		} catch (Exception $e){
			$myGlass->logError('Exception Encountered: '.$e->getMessage());
			echo $e->getMessage();
		}
		
	} elseif ( $myGlass->get_credentials() === false ) {
		// Handle step 1 of the OAuth 2.0 dance - redirect to Google

		$myGlass->logError('No Creds in DB yet. Redirecting to Google for oAuth');
		header('Location: ' . $client->createAuthUrl());
		
	} elseif ( $myGlass->isConfigured() ) {
		//look's ilke we're already configured, but there might be some oauth issues
		try {
			$user_id 		= $myGlass->getOption( 'user_id' );
			$credentials 	= $myGlass->getOption( 'credentials' );
			$needs_reauth	= $myGlass->user_needs_reauth( $credentials );
			
			if ($needs_reauth === true) {
				$myGlass->logError('User Needs Reauth. Redirecting to Google for oAuth');
				header('Location: ' . $client->createAuthUrl());
			} elseif ($needs_reauth === false) {
				header('Location: ' . 	$_SESSION['base'].'&auth_code=2');
			}
		} catch ( Exception $e ) {
			$myGlass->logError ('oAuth '.$e->getCode() .'::'. $e->getMessage());
			$myGlass->logError('User Needs Reauth. Redirecting to Google for oAuth');
			header('Location: ' . $client->createAuthUrl());
		}
	
	} else {
		//seems like we're already authenticated and we're sure about it
		
		// We're authenticated, redirect back to base_url
		$myGlass->logError('You are already authenticated. NBD');
		header('Location: ' . 	$_SESSION['base'].'&auth_code=2');
	}