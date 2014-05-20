<?php
/*
 * Copyright (C) 2013 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// Based on original work by: Jenny Murphy - http://google.com/+JennyMurphy

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] != "POST") {
	header("HTTP/1.0 405 Method not supported");
	echo("Method not supported");
	exit();
} else {
	error_log("[wpForGlass] incoming request");
}

// Always respond with a 200 right away and then terminate the connection to prevent notification
// retries. How this is done depends on your HTTP server configs. I'll try a few common techniques
// here, but if none of these work, start troubleshooting here.
// First try: the content length header
header("Content-length: 0");

// Next, assuming it didn't work, attempt to close the output buffer by setting the time limit.
ignore_user_abort(true);
set_time_limit(0);

// And one more thing to try: forking the heavy lifting into a new process. Yeah, crazy eh?
if (function_exists('pcntl_fork')) {
	$pid = pcntl_fork();
	if ($pid == -1) {
		error_log("could not fork!");
		exit();
	} else if ($pid) {
		// fork worked! but I'm the parent. time to exit.
		exit();
	}
}

//*** INCLUDES
# No need for the template engine
define( 'WP_USE_THEMES', false );

//include wp_core but without the templating or output
$parse_uri = explode('wp-content', $_SERVER['SCRIPT_FILENAME']);
$wp_load = $parse_uri[0].'wp-load.php';

require_once($wp_load);
require_once(ABSPATH . 'wp-admin/includes/image.php');

require_once '../WpForGlass.php';
$myGlass = new WpForGlass();

//** NOW GET ON WITH PROCESSING THE DATA
$request_bytes = file_get_contents('php://input');
if ($request_bytes === false){
	$myGlass->logError("file_get_contents failed");
	exit();
} 
$request = json_decode($request_bytes, true);

// A notification has come in. If there's an attached photo, bounce it back to the user
$user_id = $request['userToken'];
$access_token = $myGlass->get_credentials($user_id);
$client = $myGlass->get_google_api_client();
$client->setAccessToken($access_token);

// A glass service for interacting with the Mirror API
$mirror_service = new Google_Service_Mirror($client);

switch ($request['collection']) {
	case 'timeline':
	// Verify that it's a share
		foreach ($request['userActions'] as $i => $user_action) {
			if ($user_action['type'] == 'SHARE') {
				$timeline_item_id = $request['itemId'];
				$timeline_item = $mirror_service->timeline->get($timeline_item_id);
				
				if ($timeline_item->getAttachments() != null) {
					$myGlass->logError('Incoming Google Glass Attachment');
					$timeline_caption = $timeline_item->getText();

					$attachments = $timeline_item->getAttachments();
					foreach ($attachments as $attachment){
						$myId = $timeline_item_id;
						$attachmentId = $attachment->getId();
						$contentType = $attachment->getContentType();
						$contentUrl = $attachment->getContentUrl();
						$isProcessing = $attachment->getIsProcessingContent();

						// Check if attachment is still processing.
						// If it is, then we add it to cron queue instead.
						// Since it doesn't even have an Url yet, we don't have a http response code.
						if ($isProcessing == "1") {
							$myGlass->add_content_to_queue(null, $contentType, $attachmentId, $myId);
							return null;
						}
						
						$isMovie = false;
						
						// USER SETTINGS
						//configurable options
						$configPostCategory 	= $myGlass->getDefaultPostCategory();
						$configImageSize 		= $myGlass->getDefaultImageSize();
						$configPostTitle 		= $myGlass->getDefaultPostTitlePrefix();
						$configPostStatus 		= $myGlass->getDefaultPostStatus();
						
						$isMovie = false;
						if ($contentType == 'video/mp4'){
							$isMovie = true;
						}
						
						$myGlass->logError("Content Type for Media Item is:" . $contentType);
						
						//munge paths and save the file to the filesystem
						$wp_upload_dir = wp_upload_dir();
						$savePath = $wp_upload_dir['path'];
						$fileName = 'wpForGlass_'.uniqid('', true);
						$fileExt = 	$myGlass->getExtension($contentType);
						$finalFilePath = $savePath."/".$fileName.".".$fileExt;
							
						//save the file down to the filesystem
						$fileData = $myGlass->download_attachment($myId, $attachment);
						
						//content is getting put in the queaue
						if (is_null($fileData)){
							
						} else {
							
							if ($isMovie){
								$myGlass->download_movie($fileData['responseBody'], $finalFilePath);
								
							} else {
								file_put_contents($finalFilePath, $fileData['responseBody']);
							}
							//set the caption, which is the speakable portion of any post
							$glassCaption = $timeline_caption;
						
							// POST CREATION
							// Create an empty WP post and get the post id
							// Create post object
							$my_post = array(
							  'post_title'    => $configPostTitle,
							  'post_content'  => $glassCaption,
							  'post_status'   => $configPostStatus,
							  'post_author'   => 1,
							  'post_category' => array($configPostCategory)
							);

							// Insert the post into the database
							$parent_post_id = wp_insert_post( $my_post );
						
							//WP ATTACHMENT METADATA SETUP
							$wp_filetype = wp_check_filetype(basename($finalFilePath), null );

							$wpAttachment = array(
							     'guid' => $wp_upload_dir['url'] . '/' . basename( $finalFilePath ), 
							     'post_mime_type' => $wp_filetype['type'],
							     'post_title' => preg_replace('/\.[^.]+$/', '', basename($finalFilePath)),
							     'post_content' => '',
							     'post_status' => 'inherit'
							  );
						
							  $attach_id = wp_insert_attachment( $wpAttachment, $finalFilePath, $parent_post_id);
							  // must first include the image.php and media.php
							  // for several below WP functions to work
							  require_once(ABSPATH . 'wp-admin/includes/image.php');
							  require_once(ABSPATH . 'wp-admin/includes/media.php');

							  $attach_data = wp_generate_attachment_metadata( $attach_id, $finalFilePath );
							  wp_update_attachment_metadata( $attach_id, $attach_data );
						
						
							//Set the post content depending on whether or not the attachment is an image or a movie
						
							if ($isMovie){
								//set the wp post format to movie
								if (current_theme_supports('post-formats')){
									set_post_format($parent_post_id, 'video');
								}

								$default_size = wp_embed_defaults();
								$videoWidth = $default_size['width'];
								$videoAttachmentSrc = wp_get_attachment_url($attach_id);
							
								$output = '[video width="1280" height="720" mp4="'.$videoAttachmentSrc.'"][/video]';
								$updatedPostContent = $output.'<br /><div class="glasscaption">'.$glassCaption."</div>";
							
							} else {
								//set the wp post format to image
								if (current_theme_supports('post-formats')){
									set_post_format($parent_post_id, 'image');
								}
							
								// grab the image attributes
						 		$image_attributes = wp_get_attachment_image_src( $attach_id);
								//set the thumbnail for the post
								set_post_thumbnail( $parent_post_id, $attach_id );
								//update the post
								$postImageSrc = wp_get_attachment_image($attach_id, $configImageSize, false, $image_attributes);
						
								$updatedPostContent = $postImageSrc.'<br /><div class="glasscaption">'.$glassCaption."</div>";
							}
						
							//FINAL POST MODIFICATION
							//set the title, add the time and date to the title, and then finally insert the attachment into the post
							$current_post_data = get_post($parent_post_id);
							$post_time = $current_post_data->post_date;
							$finalDate = date("jS M Y",strtotime($post_time));
							$finalTime = date("g:i A", strtotime($post_time));
						
							$my_post = array(
								'ID' => $parent_post_id,
								'post_content' => $updatedPostContent,
								'post_title' => $configPostTitle." ".$finalDate." at ".$finalTime
							);
						
							wp_update_post($my_post);
						}
					}
				}

				//insert new timeline item to give feedback that the request was received.
				$new_timeline_item = new Google_Service_Mirror_TimelineItem();
				$new_timeline_item->setText("[wpForGlass] Upload Received");
			
				$notification = new Google_Service_Mirror_NotificationConfig();
			    $notification->setLevel("DEFAULT");
			    
				$new_timeline_item->setNotification($notification);
				
				
				
				//$mirror_service->timeline->insert($new_timeline_item);

				$myGlass->logError('Finished receiving data');
				break;
			}
		}
	break;
	default:
		$myGlass->logError("I don't know how to process this notification: $request");
}




