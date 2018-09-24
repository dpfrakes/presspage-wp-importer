<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://github.com/dpfrakes/presspage-wp-importer/
 * @since             1.0.0
 * @package           Presspage_WP_Importer
 *
 * @wordpress-plugin
 * Plugin Name:       Presspage WP Importer
 * Plugin URI:        https://github.com/dpfrakes/presspage-wp-importer/
 * Description:       Migration script to import Presspage JSON export to WordPress
 * Version:           1.0.0
 * Author:            Dan Frakes
 * Author URI:        https://github.com/dpfrakes/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       presspage-wp-importer
 * Domain Path:       /languages
 */

define( 'PWI_VERSION', '1.0' );

add_action( 'plugins_loaded', 'presspage_wp_importer_text_domain' );
/**
 * Load plugin textdomain.
 *
 * @since 1.0
 */
function presspage_wp_importer_text_domain() {
	load_plugin_textdomain( 'presspage-wp-importer' );
}

// A little hack to "catch" and save the image id with the post
function featuredImageTrick($att_id){
	$p = get_post($att_id);
	update_post_meta($p->post_parent, '_thumbnail_id', $att_id);
}


function presspage_wp_importer_run_import() {

	// Disable a time limit
	set_time_limit(0);

	// Data
	try {
		$file_contents = file_get_contents( '/home/wpe-user/sites/ngblogstage/wp-content/export-account/feeds/releases-en-us.json' );
		$json_content = json_decode('' . $file_contents, true);
	} catch {
		echo "Error retrieving and/or decoding JSON export file.";
		exit(1);
	}

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

				$excerpt = wp_trim_words(strip_tags($presspage_post['message'], '<a>'), 60);
				if ( array_key_exists('summary', $presspage_post) ) {
					$excerpt = wp_trim_words(strip_tags($presspage_post['summary'], '<a>'), 60);
				} else if ( strpos($excerpt, '<!--more') != false ) {
					$excerpt = substr(strip_tags($presspage_post['summary'], '<a>'), 0, strpos($excerpt, '<!--more'));
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
						media_sideload_image('/home/wpe-user/sites/ngblogstage/wp-content/export-account/uploads/' . $post_image_url, $postInsertId);
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
}

function presspage_wp_importer_import_complete() {
	echo "Complete";
}

register_activation_hook( __FILE__, 'presspage_wp_importer_run_import' );
register_deactivation_hook( __FILE__, 'presspage_wp_importer_import_complete' );
