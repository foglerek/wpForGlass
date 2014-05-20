<?php

// Add google api php client src to include path.
set_include_path(get_include_path() . PATH_SEPARATOR . plugin_dir_path( __FILE__ ) . 'google-api-php-client/src');

/** this is a non-standard fix and will require proper checks inside the Google_Client libraries **/
if ( !class_exists( 'Google_Client' ) )
	require_once( 'Google/Client.php' );

if (!class_exists( 'Google_Service_Oauth2' ) )
	require_once( 'Google/Service/Oauth2.php' );

if (!class_exists( 'Google_Service_Mirror' ) )
	require_once( 'Google/Service/Mirror.php' );

class WFGShareableContact extends Google_Service_Mirror_Contact {

	public $sharingFeatures;

	public function setSharingFeatures( /* array(Google_Command) */ $sharingFeatures ) {
		$this->assertIsArray( $sharingFeatures, 'Google_Command', __METHOD__ );
		$this->sharingFeatures = $sharingFeatures;
	}

}

class WpForGlass {

	const DEBUG = true;

	var $settings = ""; 
	var $config = array();
	var $error,
	    $conflict;

	public function __construct() {
		$this->settings = "";

		$originalNotifyUrl = plugins_url( 'oauth/notify.php', __FILE__ );
		$httpsNotifyUrl = preg_replace( "/^http:/", "https:", $originalNotifyUrl );
		
		$this->config = array(
		    'WPFORGLASS_VERSION' => '1.0.0',
		    'WPFORGLASS_PATH'  => dirname( __FILE__ ),
		    'WPFORGLASS_URL'  => plugin_dir_url( __FILE__ ),
		    'WPFORGLASS_OAUTH_URL'  => plugins_url( 'oauth/oauth2callback.php', __FILE__ ),
		    'WPFORGLASS_CRON_PATH'  => plugins_url( 'cron/wfgcron.php', __FILE__ ),
		    'WPFORGLASS_DEFAULT_POST_CATEGORY'  => '0',
		    'WPFORGLASS_DEFAULT_IMAGE_SIZE'  => 'full',
		    'WPFORGLASS_DEFAULT_POST_STATUS'  => 'publish',
		    'WPFORGLASS_DEFAULT_TITLE_PREFIX'  => '#throughglass',
			'WPFORGLASS_NOTIFY_URL' => $httpsNotifyUrl
		);
		


	}

	public function setupWPAdminPage() {
		add_action( 'admin_init', array( $this, 'adminInit' ) );
		add_action( 'admin_menu', array( $this, 'adminMenu' ) );
	}

	/**
	 * sets up options page and sections.
	 */
	function adminInit() {
		$this->checkForPostUpdates();
		$this->checkForOAuthSuccess();

		add_filter( 'plugin_action_links', array( $this, 'showPluginActionLinks' ), 10, 5 );

		register_setting( 'wpforglass', 'wpforglass', array( $this, 'formValidate' ) );

		add_settings_section( 'wpforglass-instructions', __( 'wpForGlass Setup Instructions', 'wpforglass' ), array( $this, 'showInstructions' ), 'wpforglass' );
		add_settings_section( 'wpforglass-api', __( 'Google Mirror API Settings', 'wpforglass' ), '__return_false', 'wpforglass' );

		add_settings_field( 'api-client-key', __( 'Mirror API Client ID', 'wpforglass' ), array( $this, 'askClientId' ), 'wpforglass', 'wpforglass-api' );
		add_settings_field( 'api-client-secret', __( 'Mirror API Client Secret', 'wpforglass' ), array( $this, 'askClientSecretKey' ), 'wpforglass', 'wpforglass-api' );
		add_settings_field( 'api-simple-key', __( 'Your Google API Simple Key', 'wpforglass' ), array( $this, 'askSimpleKey' ), 'wpforglass', 'wpforglass-api' );

		add_settings_section( 'wpforglass-contact', __( 'Contact Card Settings', 'wpforglass' ), '__return_false', 'wpforglass' );
		add_settings_field( 'contact-card-name', __( 'Contact Name', 'wpforglass' ), array( $this, 'askContactName' ), 'wpforglass', 'wpforglass-contact' );


		if ( $this->hasAuthenticated() && $this->isConfigured() ) {
			add_settings_section( 'wpforglass-tasks', __( 'CRON Settings', 'wpforglass' ), array( $this, 'showCronInstructions' ), 'wpforglass' );
			add_settings_field( 'cron-tasks-line', __( 'CRONTab Entry', 'wpforglass' ), array( $this, 'showCronTabSettings' ), 'wpforglass', 'wpforglass-tasks' );

			add_settings_section( 'wpforglass-post', __( 'Default Post Settings (Optional)', 'wpforglass' ), array( $this, 'showDefaultPostInstructions' ), 'wpforglass' );
			add_settings_field( 'wpforglass-post-type', __( 'Default Post Category', 'wpforglass' ), array( $this, 'askDefaultPostCats' ), 'wpforglass', 'wpforglass-post' );
			add_settings_field( 'wpforglass-post-imagesize', __( 'Default Post Image Size', 'wpforglass' ), array( $this,'askDefaultImageSizes' ),'wpforglass','wpforglass-post' );
			add_settings_field( 'wpforglass-post-status', __( 'Default Publishing Status', 'wpforglass' ), array( $this,'askDefaultPostStatus' ),'wpforglass','wpforglass-post' );
			//add_settings_field( 'wpforglass-post-tags', __( 'Default Tag to Attach to a Post', 'wpforglass' ), array( $this, 'askDefaultTags' ),'wpforglass','wpforglass-post' );
			//add_settings_field( 'wpforglass-post-title', __( 'Default Title Format for a Post', 'wpforglass' ), array( $this, 'askDefaultTitle' ), 'wpforglass', 'wpforglass-post' );
		}
	}

	function adminMenu() {
		$this->settings = add_options_page(
			__( 'wpForGlass Settings', 'wpforglass' ),
			__( 'wpForGlass', 'wpforglass' ),
			'manage_options',
			'wpforglass',
			array( $this,'showOptionsPage' )
		);
		wp_register_style( 'WPFORGLASS_stylesheet', plugins_url( '../css/wpforglass.css', __FILE__ ) );
		wp_enqueue_style( 'WPFORGLASS_stylesheet' );
	}

	/**
	 * Generates source of options page.
	 */
	function showOptionsPage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		//are we configured?
		if ( $this->isConfigured() ) {
			try {
				$user_id      = $this->getOption( 'user_id' );
				$credentials  = $this->getOption( 'credentials' );
				$needs_reauth = $this->user_needs_reauth( $credentials );

				$redirect_base = $this->config[ 'WPFORGLASS_OAUTH_URL' ];
				$redirect_base .= '?base=' . admin_url() . 'options-general.php?page=wpforglass';

				if ( $needs_reauth === true ) {
					echo '<div class="error"><b>wpForGlass Error:</b> Looks like you need to <a href="' . $redirect_base . '">re-connect wpForGlass with your Google Account</a><br /></div>';
				}
			} catch ( Exception $e ) {
				$redirect_base = $this->config[ 'WPFORGLASS_OAUTH_URL' ];
				$redirect_base .= '?base=' . admin_url() . 'options-general.php?page=wpforglass';

				echo '<div class="error"><b>wpForGlass Error:</b> Looks like you need to <a href="' . $redirect_base . '">re-connect wpForGlass with your Google Account</a><br /><code>'.$e->getCode() .'::'. $e->getMessage() . '</code></div>';
			}
		}

		$plugin_icon = plugins_url( '../img/icon32.png', __FILE__ );

		?>
		<div class="wrap">
			<h2><?php _e( 'wpForGlass Settings', 'wpforglass' ); ?></h2>
			<div style="float: left; width: 100%;">
				<form method="post" action="<?php echo admin_url() . 'options-general.php?page=wpforglass'; ?>">
					<div class="stuffbox">
						<?php settings_fields( 'wpforglass' ); ?>
						<?php do_settings_sections( 'wpforglass' ); ?>
					</div>
					<input type="hidden" name="update_made" value="1">
					<?php if ( $this->hasAuthenticated() && $this->isConfigured() ) : ?>
						<p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'wpforglass' ); ?>" /></p>
					<?php else : ?>
						<p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e( 'Verify Your API Settings with Google &raquo;', 'wpforglass' ) ?>" /></p>
					<?php endif; ?>
				</form>
			</div>
		</div>
	<?php
	} //showOptionsPage

	function showInstructions() {
		$http_auth_URL  = $this->config[ 'WPFORGLASS_OAUTH_URL' ];
		$this->logError($http_auth_URL);
		
		$https_auth_URL = preg_replace("/^http:/", " https:", $this->config[ 'WPFORGLASS_OAUTH_URL']);

		?>
		<div class="inside">
			<?php _e( 'So, you got Glass and want to hook it up to your WordPress Blog. To do so is not terribly complicated, but takes a couple extra steps.', 'wpforglass' ); ?><br /><br />
			<?php _e( '<b>Note:</b> You need to have SSL enabled with a certificate that is signed by a trusted signing authority setup for your server.', 'wpforglass' ); ?><br />
			<br />
			<?php printf( __( '<b>Instructions:</b> go to (<a href="%1$s" target="_blank">%1$s</a>)', 'wpforglass' ), 'http://labs.webershandwick.com/wpForGlass/installation' ); ?>
			<br /><br />
			<b><?php _e( "Your Authorized Redirect URI's for the Google API Console are:", 'wpforglass' ); ?></b><br />
			<div class="url_container">
				<code><?php echo $http_auth_URL; ?><br /><?php echo $https_auth_URL; ?></code>
			</div>
		</div>

	<?php } //showInstructions

	// Following methods generate parts of settings and test forms.
	function askClientId() {
		echo '<div class="inside">';
		$api_client_id = $this->getApiClientId();
		?>
		<input id='api_client_id' name='wpforglass[api_client_id]' size='45' type='text' value="<?php echo esc_attr( $api_client_id ); ?>" />
		<?php
		echo '</div>';
	}

	function askClientSecretKey() {
		echo '<div class="inside">';
		$api_client_secret = $this->getApiClientSecret();
		if ( empty( $api_client_secret ) || ! $this->hasAuthenticated() ) : ?>
			<input id='api_client_secret' name='wpforglass[api_client_secret]' size='45' type='text' value="<?php echo esc_attr( $api_client_secret ); ?>" />
			<br/><span class="setting-description"><small><em><?php _e( 'After successfully authenticating with the Mirror API, the data in this field will not be visible.', 'wpforglass' ); ?></em></small></span>
		<?php else : ?>
			<input id='api_client_secret' name='wpforglass[api_client_secret]' size='45' type='password' value="<?php echo esc_attr( $api_client_secret ); ?>" readonly />
			<br/><span class="setting-description"><small><em><?php _e( 'If you have reset your client secret and need to change it. You will have to de-activate the plugin, and re-activate it.', 'wpforglass' ); ?></em></small></span>
		<?php endif;
		echo '</div>';
	}

	function askSimpleKey() {
		echo '<div class="inside">';
		$api_simple_key = $this->getApiSimpleKey();
		?>
		<input id='api_simple_key' name='wpforglass[api_simple_key]' size='45' type='text' value="<?php echo esc_attr( $api_simple_key ); ?>" />
		<?php
		echo '</div>';
	}

	function askContactName() {
		echo '<div class="inside">';
		$contact_card_name = $this->getContactName();
		
		if (empty($contact_card_name)){
		?>
			<input id='contact_card_name' name='wpforglass[contact_card_name]' size='20' type='text' maxlength="20" value="<?php echo esc_attr( $contact_card_name ); ?>" />
			<br/><span class="setting-description"><small><em><?php _e( 'This is the name for the Google Glass Contact Card that you will share images to. Once you have set it, you will not be able to change it unless you de-activate the plugin.', 'wpforglass' ); ?></em></small></span>

		<?php
		} else {
		?>
			<input id='contact_card_name' name='wpforglass[contact_card_name]' size='20' type='text' maxlength="20" value="<?php echo esc_attr( $contact_card_name );?>" readonly />
		<?php
		}
		echo '</div>';
	}

	function showCronInstructions() {
		?>
		<div class="inside">
			<?php _e( "wpForGlass uses the CRON to download and post media that you share through Glass that may not have been immediately ready to consume. This is often the case for video files. In order to properly setup wpForGlass, you'll need access to the CRON on your server.", 'wpforglass' ); ?>
		</div>
		<?php
	}

	function showCronTabSettings() {
		$curlCommand = "*/1 * * * * curl ".$this->config[ 'WPFORGLASS_CRON_PATH' ].' >/dev/null 2>&1';

		echo '<div class="inside"><b>' . __( 'Using cURL:', 'wpforglass' ) . '</b><br />';
		echo '<textarea cols="55" rows="4" readonly>'.$curlCommand.'</textarea>';
		echo '<br/><span class="setting-description"><small><em>' . __( 'This is the value that you should place in your crontab, either manually, or through your hosting control panel.', 'wpforglass' ) . '</em></small></span></div>';
	}

	//@TODO: add in form validation

	/**
	* Processes submitted settings from.
	*/
	function formValidate( $input ) {
		return null;
	}

/******************************************************************
**   Managing OAuth & Storing Data
******************************************************************/

	function checkForOAuthSuccess() {
		if ( isset($_GET['auth_code'] ) ) {
			switch( $_GET['auth_code'] ) {
				//successful auth the first time, set the auth flag
				case "1":

					if (is_multisite()){
						$options = get_site_option( 'wpforglass' );
					} else {
						$options = get_option( 'wpforglass' );
					}

					$options['has_authenticated'] = '1';

					if (is_multisite()){
						$result = update_site_option('wpforglass', $options);
					} else {
						$result = update_option('wpforglass', $options);
					}

					echo '<div class="updated">' . __( 'oAuth setup was successful. Now you can set your post defaults below, and setup the cron.', 'wpforglass' ) . '</div>';
					$this->logError('OAUTH was successful');
				break;
				//had to reconnect, do nothing
				case "2":

				break;
			}
		}
	}

	function checkForPostUpdates() {

		if (isset($_POST['update_made'])){
			switch($_POST['update_made']){
				//step 1, verify oAuth
				case "1":
					$this->storeConsoleData();
					//go to the oauth page
					
					$redirect_base = $this->config[ 'WPFORGLASS_OAUTH_URL' ];
					$redirect_base .= '?base='.admin_url()."options-general.php?page=wpforglass";
					
					header('Location: '.$redirect_base);
					exit();
				break;
			}

		}
	}

	function storeConsoleData() {
		// pull the set options direct from the options list, set all of our values at once, and save back to the DB.
		// we don't use $this->setOption because setting multiple values like this can lead to a race condition
		// where the db is returning information faster than it is being written, especially with WP Multisite

		if ( is_multisite() ) {
			$options = get_site_option( 'wpforglass' );
		} else {
			$options = get_option( 'wpforglass' );
		}

		$wpfg = $_POST['wpforglass'];

		$options['api_client_id']         = $client_id           = $wpfg['api_client_id'];
		$options['api_client_secret']     = $client_secret       = $wpfg['api_client_secret'];
		$options['api_simple_key']        = $api_simple_key      = $wpfg['api_simple_key'];
		$options['contact_card_name']     = $contact_card_name   = $wpfg['contact_card_name'];

		$options['default_post_category'] = $default_post_cat    = $wpfg['default_post_category'];
		$options['default_image_size']    = $default_image_size  = $wpfg['default_image_size'];
		$options['default_post_status']   = $default_post_status = $wpfg['default_post_status'];

		/*
		$this->logError( "Saving API-Client_Id: " . $client_id );
		$this->logError( "Saving API-Client_Secret: " . $client_secret );
		$this->logError( "Saving API Simple Key: " . $api_simple_key );
		*/
		
		if ( is_multisite() ) {
			$result = update_site_option( 'wpforglass', $options );
		} else {
			$result = update_option( 'wpforglass', $options );
		}
	}

/******************************************************************
**   Google Mirror API
******************************************************************/

	// Returns an unauthenticated service
	function get_google_api_client() {

		if ( is_multisite() ) {
			$options = get_site_option( 'wpforglass' );
		} else {
			$options = get_option( 'wpforglass' );
		}

		$api_client_id     = $options['api_client_id'];
		$api_client_secret = $options['api_client_secret'];
		$api_simple_key    = $options['api_simple_key'];

		/*
		$this->logError( '----------------------------------------------' );
		$this->logError( 'GetGoogleApiClient' );
		$this->logError( 'client id: ' . $api_client_id );
		$this->logError( 'client secret: ' . $api_client_secret );
		$this->logError( 'simplekey: ' . $api_simple_key );
		*/

		$client = new Google_Client();
		$client->setApplicationName( 'wpforglass' );

		$client->setClientId( $api_client_id );
		$client->setClientSecret( $api_client_secret );
		// Setting developer key can lead to unexpected results with oauth 2.0. See below:
		// http://stackoverflow.com/questions/21020898/403-error-with-messageaccess-not-configured-please-use-google-developers-conso
		// $client->setDeveloperKey( $api_simple_key );

		$client->setRedirectUri( $this->config[ 'WPFORGLASS_OAUTH_URL' ] );

		$client->setScopes( array(
			'https://www.googleapis.com/auth/glass.timeline',
			'https://www.googleapis.com/auth/glass.location',
			'https://www.googleapis.com/auth/userinfo.profile',
		) );
		return $client;
	}

	// Returns authenticated google api client
	function get_auth_google_api_client() {

		if ( is_multisite() ) {
			$options = get_site_option( 'wpforglass' );
		} else {
			$options = get_option( 'wpforglass' );
		}

		// Get unauthenticated client
		$client = $this->get_google_api_client();
		// Verify and set credentials
		$this->verify_credentials($options['credentials']);
		$client->setAccessToken($options['credentials']);

		return $client;
	}

	function verify_credentials( $credentials ) {
		$client = $this->get_google_api_client();
		$client->setAccessToken( $credentials );
		$token_checker = new Google_Service_Oauth2( $client );
		try {
			$token_checker->userinfo->get();
		} catch ( Google_Service_Exception $e ) {
			if ( $e->getCode() == 401 ) {
				// This user may have disabled the Glassware on MyGlass.
				// Clean up the mess and attempt to re-auth.
				header( 'Location: ' . $this->config['WPFORGLASS_OAUTH_URL'] );
				exit;
			} else {
				// Let it go...
				throw $e;
			}
		}
	}

	function user_needs_reauth( $credentials ) {
		$this->logError('checking if user needs reauth');

		$client = $this->get_google_api_client();
		$client->setAccessToken( $credentials );
		$token_checker = new Google_Service_Oauth2( $client );
		try {
			$token_checker->userinfo->get();
			//error_log('test:');
			//error_log(print_r($token_checker->userinfo->get(), true));
			
			
		} catch ( Google_Service_Exception $e ) {
			$this->logError( 'user_needs_reauth throwing google_service_exception >> ' . $e->getMessage() );
			if ( $e->getCode() == 401 ) {
				// This user may have disabled the Glassware on MyGlass.
				// Clean up the mess and attempt to re-auth.
				
				return true;
			} else {
				// Let it go...
				$this->logError( 'user_needs_reauth is throwing exception >> ' . $e->getMessage() );
				throw $e; //return "".$e->getCode()."::".$e->getMessage();
			}
		}
		return false;
	}

	function insert_timeline_item( $service, $timeline_item, $content_type, $attachment ) {
		try {
			$opt_params = array();
			if ( $content_type != null && $attachment != null ) {
				$opt_params['data']     = $attachment;
				$opt_params['mimeType'] = $content_type;
			}
			return $service->timeline->insert( $timeline_item, $opt_params );
		} catch (Exception $e) {
			$this->logError( 'An error occurred inserting a timeline item: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Subscribe to notifications for the current user.
	 *
	 * @param Google_Service_Mirror $service Authorized Mirror service.
	 * @param string $collection Collection to subscribe to (supported
	 *                           values are "timeline" and "locations").
	 * @param string $user_token Opaque token used by the Service to
	 *                          identify the  user the notification pings
	 *                          are sent for (recommended).
	 * @param string $callback_url URL receiving notification pings (must be HTTPS).
	 */
	function subscribe_to_notifications( $service, $collection, $user_token, $callback_url ) {
		try {
			$subscription = new Google_Service_Mirror_Subscription();
			$subscription->setCollection( $collection );
			$subscription->setUserToken( $user_token );
			$subscription->setCallbackUrl( $callback_url );
			
			$service->subscriptions->insert( $subscription );
			
			$this->logError( "Subscription Inserted with callback url:" . $callback_url );
		} catch ( Exception $e ) {
			$this->logError( 'An error occurred while subscribing to notifications: ' . $e->getMessage() );
		}
	}

	function insert_contact( $service, $contact_id, $display_name, $icon_url ) {
		try {
			$contact = new WFGShareableContact();
			
			$contact->setId( $contact_id );
			$contact->setDisplayName( $display_name );
			$contact->setImageUrls( array($icon_url ));
			$contact->setSharingFeatures( array('ADD_CAPTION' ) );
		
			return $service->contacts->insert( $contact );
		} catch ( Exception $e ) {
			$this->logError( 'An error occurred while inserting contact card: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Delete a contact for the current user.
	 *
	 * @param Google_Service_Mirror $service Authorized Mirror service.
	 * @param string $contact_id ID of the Contact to delete.
	 */
	function delete_contact( $service, $contact_id ) {
		try {
			$service->contacts->delete($contact_id);
		} catch (Exception $e) {
			print 'An error occurred while deleting contact card: ' . $e->getMessage();
		}
	}

	/**
	 * Download an attachment's content.
	 *
	 * @param string item_id ID of the timeline item the attachment belongs to.
	 * @param Google_Attachment $attachment Attachment's metadata.
	 * @return array
	 */
	function download_attachment( $item_id, $attachment ) {
		$request             = new Google_Http_Request($attachment->getContentUrl(), 'GET', null, null);

		// Get authenticated client
  		$client 			 = $this->get_auth_google_api_client();

  		// Make authenticated request
  		$httpRequest         = $client->getAuth()->authenticatedRequest($request);

		$attachment_id       = $attachment->getId();
		$isProcessingContent = (int) $attachment->getIsProcessingContent();
		$contentUrl          = $attachment->getContentUrl();
		$contentType         = $attachment->getContentType();
		$httpResponseCode    = $httpRequest->getResponseHttpCode();

		$this->logError( "Downloading Attachment" );
		$this->logError( "----------------------" );
		$this->logError( "attachment id: " . $attachment_id );
		$this->logError( "http status: " . $httpResponseCode );
		$this->logError( "isProcessing: " . $isProcessingContent );
		$this->logError( "contentType: " . $contentType );
		$this->logError( "contentUrl: " . $contentUrl );
		$this->logError( "----------------------" );

		// currently there are only two use-cases for an attachment as far as WordPress is concerned, video or images
		// videos tend to take extra time to process and will most often have the isprocessing flag set to true
		// furthermore, videos currently return an http 302 status redirect
		// the best practice is to first check if an attachment is currently processing regardless of content-type
		// if so, we'll put it into the queaue for retrieval later
		// because attachments that are currently processing do not have a contentUrl we'll have to grab it later

		$attachmentInfo = array();
		$attachmentInfo['responseBody'] = '';
		$attachmentInfo['contentType']  = '';
		$attachmentInfo['attachmentId'] = '';
		$attachmentInfo['contentType']  = $contentType;
		$attachmentInfo['attachmentId'] = $attachment_id;

		if ( $isProcessingContent == 0 ){
			//content is ready to be sucked down
			switch ($contentType){
				case 'video/mp4':
					//$this->logError("---------- responsebody:");
					$this->logError($httpRequest->getResponseBody());
					$attachmentInfo['responseBody'] = $contentUrl;
				break;
				case 'image/jpeg':
				case 'image/png':
				case 'image/gif':
					$attachmentInfo['responseBody'] = $httpRequest->getResponseBody();
				break;
			}
			return $attachmentInfo;
		} else {
			//content is not ready, queaue it up for later
			$this->add_content_to_queue($httpResponseCode, $contentType, $attachment_id, $item_id);
			return null;
		}
	}

	function download_movie( $srcUrl, $dest ) {
		//props to george stephanis for the WP HTTP suggestion
		
		if( !class_exists( 'WP_Http' ) )
		    include_once( ABSPATH . WPINC. '/class-http.php' );

		$wpHttp = new WP_Http;
		$response = $wpHttp->request($srcUrl, array('timeout' => 180, 'sslverify' => false, 'redirection' =>10));
		$fp = fopen ($dest, 'w'); 
		if (fwrite($fp, wp_remote_retrieve_body($response)) === FALSE){
			$this->logError('Error downloading file :'.$srcUrl);
		}
		fclose($fp);
		$this->logError('File Downloaded and Saved');

	
		/*

		$ch = curl_init($srcUrl);
		$fp = fopen ($dest, 'w+'); //This is the file where we save the information
		curl_setopt($ch, CURLOPT_TIMEOUT, 180);
		curl_setopt($ch, CURLOPT_FILE, $fp); // write curl response to file
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_exec($ch); // get curl response
		curl_close($ch);
		fclose($fp);
		*/
	}

	function add_content_to_queue( $httpResponseCode, $contentType, $attachment_id, $item_id ) {
		//the queue is a different set of WP 'options' that we've setup

		if ( is_multisite() ) {
			$options = get_site_option( 'wfg-cron' );
		} else {
			$options = get_option( 'wfg-cron' );
		}

		$myArr = array();
		$myArr['response']      = $httpResponseCode;
		$myArr['mime']          = $contentType;
		$myArr['attachment_id'] = $attachment_id;
		$myArr['item_id']       = $item_id;
		$myArr['num_tries']     = 0;
		$myArr['isComplete']    = false;
		$myArr['isDownloading'] = false;

		// pull the current items in the list, if any
		$arrTasks = array();
		if ( !empty( $aOptions['tasks'] ) ) {
			$arrTasks = $aOptions['tasks'];
		}

		//if the item already exists in the q, don't add it. Just log and exit.
		$found = false;

		if ( count( $arrTasks ) > 0 ) {
			foreach( $arrTasks as $task ){
				if ( $item_id == $task['item_id'] ){
					$found = true;
				}
			}
		}
		if ( ! $found ) {

			//add to the list
			array_push( $arrTasks, $myArr );
			//assign
			$options['tasks'] = $arrTasks;
			//save
			if ( is_multisite() ){
				$result = update_site_option( 'wfg-cron', $options );
			} else {
				$result = update_option( 'wfg-cron', $options );
			}
			$this->logError( "Adding Content to queue [$httpResponseCode :: $contentType :: $attachment_id :: $item_id]" );
		} else {
			$this->logError( "Item: $item_id already exists in the queue. Skipping." );
		}
	}

	/**
	 * Delete a timeline item for the current user.
	 *
	 * @param Google_Service_Mirror $service Authorized Mirror service.
	 * @param string $item_id ID of the Timeline Item to delete.
	 */
	function delete_timeline_item( $service, $item_id ) {
		try {
			$service->timeline->delete( $item_id );
		} catch ( Exception $e ) {
			print 'An error occurred: ' . $e->getMessage();
		}
	}

/******************************************************************
**   User Card Setup and Config
******************************************************************/

	function bootstrap_new_user( $user_id ) {
		$client = $this->get_google_api_client();
		$client->setAccessToken( $this->get_credentials() );

		$contact_name = $this->getContactName();

		// A glass service for interacting with the Mirror API
		$mirror_service = new Google_Service_Mirror( $client );
		$timeline_item  = new Google_Service_Mirror_TimelineItem();
		$timeline_item->setText( "wpForGlass is now setup!" );

		$this->insert_timeline_item( $mirror_service, $timeline_item, null, null );
		$this->insert_contact( $mirror_service, "wpforglass-contact-name", $contact_name, plugins_url( 'img/contact_image.jpg', __FILE__ ) );
		$this->subscribe_to_notifications( $mirror_service, "timeline", $user_id, $this->config['WPFORGLASS_NOTIFY_URL'] );
	}

/******************************************************************
**   Credentialing
******************************************************************/

	function store_credentials( $user_id, $credentials ) {
		$user_id = strip_tags( $user_id );
		$credentials = strip_tags( $credentials );

		if ( is_multisite() ) {
			$options = get_site_option( 'wpforglass' );
		} else {
			$options = get_option( 'wpforglass' );
		}

		$options['user_id'] = $user_id;
		$options['credentials'] = $credentials;

		if ( is_multisite() ) {
			$result = update_site_option( 'wpforglass', $options );
		} else {
			$result = update_option( 'wpforglass', $options );
		}
	}

	function get_userid() {
		return $this->getOption( 'user_id' );
	}

	//todo: put into a table to properly support multiple glass devices
	function get_credentials( $user_id = '') {
		return $this->getOption( 'credentials' );
	}

/******************************************************************
**  Helper functions
*******************************************************************

	/**
	 * @return mixed
	 */
	function getOption( $name, $default = false ) {
		if ( is_multisite() ) {
			$options = get_site_option( 'wpforglass' );
		} else {
			$options = get_option( 'wpforglass' );
		}

		if ( isset( $options[ $name ] ) ) {
			return $options[ $name ];
		}
		return $default;
	}

	function getQueaueOptions() {
		if ( is_multisite() ) {
			$options = get_site_option( 'wfg-cron' );
		} else {
			$options = get_option( 'wfg-cron' );
		}

		return $options;
	}

/*
	function setOption( $name, $value ) {
		$options = $this->getOption( 'wpforglass' );
		$options[ $name ] = $value;

		if ( is_multisite() ) {
			 $result = update_site_option( 'wpforglass', $options );
		} else {
			 $result = update_option( 'wpforglass', $options );
		}

		return $result;
	}
*/

	function isConfigured() {
		return $this->getApiClientId() && $this->getApiClientSecret() && $this->getApiSimpleKey();
	}

	function hasAuthenticated() {
		return (bool) ( $this->getOption( 'has_authenticated' ) == '1' );
	}

	//MIRROR API STUFF

	function getContactName() {
		return $this->getOption( 'contact_card_name' );
	}

	function getApiClientId() {
		return $this->getOption( 'api_client_id' );
	}

	function getApiClientSecret() {
		return $this->getOption( 'api_client_secret' );
	}

	function getApiSimpleKey() {
		return $this->getOption( 'api_simple_key' );
	}

	//POST DEFAULTS

	function getDefaultPostCategory() {
		return $this->getOption( 'default_post_category', $this->config[ 'WPFORGLASS_DEFAULT_POST_CATEGORY' ] );
	}

	function getDefaultImageSize() {
		return $this->getOption( 'default_image_size', $this->config[ 'WPFORGLASS_DEFAULT_IMAGE_SIZE' ] );
	}

	function getDefaultPostStatus() {
		return $this->getOption( 'default_post_status', $this->config[ 'WPFORGLASS_DEFAULT_POST_STATUS' ] );
		
	}

	function getDefaultPostTitlePrefix()
	{
		return $this->getOption( 'default_post_title_prefix', $this->config[ 'WPFORGLASS_DEFAULT_TITLE_PREFIX' ] );
	}

	function removeOptions()
	{
		$hasDeleted = false;

		if ( is_multisite() ) {
			$hasDeleted = delete_site_option( 'wpforglass' );
		} else {
			$hasDeleted = delete_option( 'wpforglass' );
		}

		if ( ! $hasDeleted ) {
			$this->logError( 'removeOptions - Could not delete the option from options table' );
		} else {
			$this->logError( 'removeOptions - Deleted wpforglass options from options table' );
		}
	}

	function getExtension( $mime_type ) {
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'video/mp4'  => 'mp4',
		);
		return isset( $extensions[ $mime_type ] ) ? $extensions[ $mime_type ] : sanitize_file_name( $mime_type );
	}

	/**
	 * Logs an error to the error log if self::DEBUG is true
	 */
	function logError( $s ) {
		if ( self::DEBUG == true ){
			error_log( "[wpForGlass] > " . $s );
		}
	}

	function deactivate() {
		//remove the custom sharing card from glass
		//need to add removal of the contact card here
		$this->logError( 'wpForGlass - Removing Contact Card' );
		try {
			$client = $this->get_google_api_client();
			$client->setAccessToken( $this->get_credentials() );
			$mirror_service = new Google_Service_Mirror($client);
			$this->delete_contact( $mirror_service, 'wpforglass-contact-name' );
		} catch ( Exception $e ) {
			$this->logError( $e->getCode() . $e->getMessage );
		}

		//deregistration of the options we've set
		$this->removeOptions();
	}

	function showDefaultPostInstructions() {
		?>
		<div class="inside">
			<?php esc_html_e( 'You&#8217;ll want to set a couple options below to define how image and video posts from glass will show up on your blog by default.', 'wpforglass' ); ?>
		</div>
		<?php
	}

	function askDefaultPostCats() {
		$current_default_category = $this->getDefaultPostCategory();
		?>
		<div class="inside">
			<select id="default_post_category" name="wpforglass[default_post_category]">
			<option value="0"><?php esc_html_e( 'No Default Category', 'wpforglass' ); ?></option>
			<?php
				$args = array( 'hide_empty' => 0 );
				$categories = get_categories( $args );
				foreach ( $categories as $category ) {
					$selected = "";
					if ( $current_default_category == $category->term_id ) {
						$selected = "selected";
					}
					$option = '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>';
					$option .= esc_html( $category->cat_name );
					$option .= ' (' . intval( $category->category_count ) . ')';
					$option .= '</option>';
					echo $option;
				}
			?>
		</select>
		</div>
	<?php
	}

	function askDefaultTitle() {
	}

	function askDefaultTags() {
		echo '<div class="inside">';
		$tags = get_the_tags();
		//do we actually have tags setup?
		if ( ! empty( $tags ) ) {
			echo '<select id="default_tag" name="wpForGlass[default_tag]">';
			foreach ( $tags as $tag ) {
				echo '<option value="' . get_tag_link( $tag->term_id ) . '">' . esc_html( $tag->name ) . '</option>';
			}
			echo "</select>";
		} else {
			// we don't have tags at all
			esc_html_e( "Look's like you don't have any tags setup in your WordPress Install, set some up and then come back to this page!", 'wpforglass' );
		}
		echo '</div>';
	}

	function askDefaultPostStatus() {
		$statuses = array(
			'publish' => __( 'Publish', 'wpforglass' ),
			'pending' => __( 'Pending', 'wpforglass' ),
			'draft'   => __( 'Draft', 'wpforglass' ),
			'private' => __( 'Private', 'wpforglass' ),
		);
		$current_default_status = $this->getDefaultPostStatus();
		echo '<div class="inside"><select id="default_post_status" name="wpforglass[default_post_status]">';
		echo '<option value="draft">' . esc_html__( 'No Option Selected (draft)', 'wpforglass' ) . '</option>';
		foreach ( $statuses as $status => $label ){
			$selected = "";
			if ( $current_default_status == $status ){
				$selected = "selected";
			}
			echo '<option value="'. esc_attr( $status ) .'" '.$selected.'>'. esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
	}

	function askDefaultImageSizes() {
		// find all sizes
		$all_sizes = get_intermediate_image_sizes();
		// define default sizes
		$sizes = array();
		$sizes = array_merge( $sizes, array(
			'thumbnail' => __( "Thumbnail", 'wpforglass' ),
			'medium'    => __( "Medium", 'wpforglass' ),
			'large'     => __( "Large", 'wpforglass' ),
			'full'      => __( "Full", 'wpforglass' ),
		) );
		// add extra registered sizes
		foreach( $all_sizes as $size ){
			if( ! isset( $sizes[ $size ] ) ){
				$sizes[ $size ] = ucwords( str_replace( '-', ' ', $size ) );
			}
		}

		$current_default_image_size = $this->getDefaultImageSize();

		echo '<div class="inside"><select id="default_image_size" name="wpforglass[default_image_size]">';
			echo '<option value="full">No image size selected (full)</option>';
			while ( $size_name = current( $sizes ) ) {
					$selected = "";
					if ( $current_default_image_size == key( $sizes ) ){
						$selected = "selected";
					}

				echo '<option value="' . key( $sizes ) . '" ' . $selected . '>' . $size_name . '</option>';
				next ( $sizes );
			}
		echo '</select></div>';
	}

	/**
	 * Adds link to settings page in list of plugins
	 */
	static function showPluginActionLinks( $actions, $plugin_file ) {
		static $plugin;

		if ( ! isset( $plugin ) ) {
			$plugin = 'wpForGlass/wpForGlass.php';
		}

		if ( $plugin == $plugin_file ) {
			$settings = array(
				'settings' => '<a href="options-general.php?page=wpforglass">' . __( 'Settings', 'wpforglass' ) . '</a>'
			);
			$actions  = array_merge( $settings, $actions );
		}

		return $actions;
	}

}
