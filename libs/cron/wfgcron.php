<?php

	set_time_limit(0);
	
	
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
	

	$myGlass->logError("Running Cron");
	
	// pull what we need from the options table and see if there is anything to even process.
	$options = $myGlass->getQueaueOptions();
	$arrTasks = $options['tasks'];

	$numTasks = count($arrTasks);

	if ($numTasks > 0){
		
		//connect to mirror api and parse through the timeline		
		$user_id 		= $myGlass->get_userid();
		$access_token 	= $myGlass->get_credentials($user_id);
		$client 		= $myGlass->get_google_api_client();

		$client->setAccessToken($access_token);

		// A glass service for interacting with the Mirror API
		$mirror_service = new Google_MirrorService($client);
				
		//loop through all attachments that need to be checked to see if they are ready
		//and if so, download the ones that are available for processing.
		
		foreach ($arrTasks as $myTask){

			if ( $myTask['isDownloading'] == false && $myTask['isComplete'] == false ) {
				$myTask['isDownloading'] = true;
				
				
				
				
				// immediately assign and re-save to options in case what we're downloading will take
				// longer than the cron interval

				//assign
				$options['tasks'] = $arrTasks;
				//save
				if (is_multisite()){
					$result = update_site_option('wfg-cron', $options);
				} else {
					$result = update_option('wfg-cron', $options);
				}
				
				
				
				$timeline_item_id = $myTask['item_id'];
				
				$timeline_item = $mirror_service->timeline->get($myTask['item_id']);
				$timeline_caption = $timeline_item->getText();
								
				if ($timeline_item->getAttachments() != null) {
					$attachments = $timeline_item->getAttachments();
					foreach ($attachments as $attachment){
						$myId = $timeline_item_id;
						$attachmentId 	= $attachment->getId();
						$contentType 	= $attachment->getContentType();
						$contentUrl		= $attachment->getContentUrl();
						$isProcessing	= $attachment->getIsProcessingContent();
						
						if ($isProcessing == '1'){
							break;
						} else {
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
								//do nothing
								
								//let the user know its processing with a patch-update to their timeline
								$patch = new Google_TimelineItem();
								$patch->setText("Processing Upload.".$timeline_item->getText());
								$mirror_service->timeline->patch($timeline_item_id, $patch);

								
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
									$postImageSrc = wp_get_attachment_image($attach_id, $configImageSize);
							
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
								//let the user know its been received and posted with a patch-update to their timeline
//								$patch = new Google_TimelineItem();
//								$patch->setText("Upload Completed! ".$timeline_item->getText());
//								$mirror_service->timeline->patch($timeline_item_id, $patch);
							}
							//should probably remove the movie from the task list
							$newOptions = array();							
							for ($i=0; $i < count($arrTasks); $i++){
								if ($arrTasks[$i]['item_id'] == $timeline_item_id){
									$garbage = array_splice($arrTasks, $i, 1);
								}
							}
							//assign
							$options['tasks'] = $arrTasks;
							//save
							if (is_multisite()){
								$result = update_site_option('wfg-cron', $options);
							} else {
								$result = update_option('wfg-cron', $options);
							}
															
							$myGlass->logError('Finished receiving data');
						}
					}//foreach
				}//if
			}//if
		}//foreach
		
	} else {
		$myGlass->logError("There are no files in the queue. Exiting.");
	} //$numTasks is not > 0
	
	
	
