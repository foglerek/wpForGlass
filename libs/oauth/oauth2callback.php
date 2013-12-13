<?php
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

	if (isset($_GET['code'])) {
		// Handle step 2 of the OAuth 2.0 dance - code exchange
		try {

			$client->authenticate();
			$access_token = $client->getAccessToken();
			$idGlassPress = new WpForGlass();
	
			// Use the identity service to get their ID
			$identity_client = $idGlassPress->get_google_api_client();
			$identity_client->setAccessToken($access_token);
			$identity_service = new Google_Oauth2Service($identity_client);
	
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
			echo $e->getMessage();
		}
	} elseif ($myGlass->get_credentials() == null) {
		// Handle step 1 of the OAuth 2.0 dance - redirect to Google
		header('Location: ' . $client->createAuthUrl());
	} else {
		// We're authenticated, redirect back to base_url
		$myGlass->logError('You are already authenticated. NBD');
		header('Location: ' . 	$_SESSION['base'].'&auth_code=2');
	}