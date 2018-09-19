<?php

/*

	Imports data from PressPage JSON export. This file is referenced by
	'/site/web/app/themes/voices/inc/scripts.php'. After initial run,
	this file is automatically renamed to import_presspage_data-complete.php
	to avoid multiple executions.

*/

// A little hack to "catch" and save the image id with the post
function featuredImageTrick($att_id){
	$p = get_post($att_id);
	update_post_meta($p->post_parent, '_thumbnail_id', $att_id);
}


// Disable a time limit
set_time_limit(0);

// Data
$file_contents = file_get_contents( dirname(__FILE__) . '/data/feeds/releases-en-us.json' );
$file_contents = file_get_contents( dirname(__FILE__) . '/data/feeds/test-real.json' );
$json_content = json_decode('' . $file_contents, true);

// Require some Wordpress core files for processing images
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

// Succesfully loaded?
if($json_content !== FALSE) {

	// Loop through some items in the json
	foreach($json_content as $k => $v) {

		try {
			// Get object
			$presspage_post = $json_content[$k];

			// DEBUGGING
			if ( !array_key_exists('title', $presspage_post) ) {
				throw new Exception('Entry ' . $k + 1 . ' does not contain title');
			}

			if ( !array_key_exists('message', $presspage_post) ) {
				throw new Exception('Entry ' . $k + 1 . ' does not contain message');
			}

			$excerpt = wp_trim_words($presspage_post['message'], 60);
			if ( array_key_exists('summary', $presspage_post) ) {
				$excerpt = wp_trim_words($presspage_post['summary'], 60);
			} else if ( strpos($excerpt, '<!--more') != false ) {
				$excerpt = substr($presspage_post['summary'], 0, strpos($excerpt, '<!--more'));
			}

			// Let's start with creating the post itself
			$postCreated = array(
				'post_title'    => $presspage_post['title'],
				'post_content'  => $presspage_post['message'],
				'post_excerpt'  => $excerpt,
				'post_date'     => $presspage_post['date'],
				'post_status'   => 'publish',
				'post_type'     => 'post', // Or "page" or some custom post type
			);

			// Get the increment id from the inserted post
			$postInsertId = wp_insert_post( $postCreated );

			// Our custom post options, for now only some meta's for the
			// Yoast SEO plugin and a "flag" to determined if a
			// post was imported or not
			$postOptions = array(
				'_yoast_wpseo_title'    => $presspage_post['title'],
				'imported'              => true
			);

			// Loop through the post options
			foreach($postOptions as $key => $value){

				// Add the post options
				update_post_meta($postInsertId, $key, $value);
			}

			// This is a little trick to "catch" the image id
			// Attach/upload the "sideloaded" image
			// And remove the little trick
			if ( array_key_exists('images', $presspage_post) && count(array_keys($presspage_post['images'])) > 0 ) {

				// Point to last image (assumed to be local copy/highest res/latest version)
				$pp_post_images = $presspage_post['images'];
				end($pp_post_images);
				$post_image_url = $pp_post_images[key($pp_post_images)];

				// Check if image is URL or local file reference
				if ( substr($post_image_url, 0, 2) == '//' ) {
					// Media server
					media_sideload_image('http:' . $post_image_url, $postInsertId);
				} else {
					// Local
					media_sideload_image(dirname(__FILE__) . '/data/uploads/' . $post_image_url, $postInsertId);
				}

				add_action('add_attachment', 'featuredImageTrick');
				media_sideload_image($pp_post_images[key($pp_post_images)], $postInsertId);
				remove_action('add_attachment', 'featuredImageTrick');

			}

		} catch (Exception $exc) {
			echo $exc->getMessage() . '<br/>';
		}
	}
}
