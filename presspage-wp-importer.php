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

if (!defined('PWI_VERSION')) define( 'PWI_VERSION', '1.0' );

add_action( 'plugins_loaded', 'presspage_wp_importer_text_domain' );
/**
 * Load plugin textdomain.
 *
 * @since 1.0
 */
if (!function_exists('presspage_wp_importer_text_domain')) {
	function presspage_wp_importer_text_domain() {
		load_plugin_textdomain( 'presspage-wp-importer' );
	}
}

if (!function_exists('featured_image_trick')) {
	// A little hack to "catch" and save the image id with the post
	function featured_image_trick($att_id){
		$p = get_post($att_id);
		update_post_meta($p->post_parent, '_thumbnail_id', $att_id);
	}
}

if (!function_exists('run_presspage_import')) {
	// Run import asynchronously
	function run_presspage_import() {

		// Set time limit to 2 hours
		set_time_limit(7200);

		// Data
		try {
			$file_contents = file_get_contents(plugin_dir_path(__FILE__) . 'export-account/feeds/releases-en-us.json');
			$json_content = json_decode('' . $file_contents, true);
		} catch (Exception $e) {
			echo "Error retrieving and/or decoding JSON export file.";
			echo "Exception message: ", $e->getMessage(), "\n";
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

					$post_title = $presspage_post['title'];
					if ( array_key_exists('subtitle', $presspage_post) ) {
						$post_title .= ': ' . $presspage_post['subtitle'];
					}

					$excerpt = wp_trim_words(strip_tags($presspage_post['message'], '<a>'), 60);
					if ( array_key_exists('summary', $presspage_post) ) {
						$excerpt = wp_trim_words(strip_tags($presspage_post['summary'], '<a>'), 60);
					} else if ( strpos($excerpt, '<!--more') != false ) {
						$excerpt = substr(strip_tags($presspage_post['summary'], '<a>'), 0, strpos($excerpt, '<!--more'));
					}

					$post_tags = array();
					if ( array_key_exists('tags', $presspage_post) ) {
						$post_tags = explode(',', $presspage_post['tags']);
					}

					// Author defaults to current user (admin)
					// Author not included in PressPage export
					$postCreated = array(
						'post_title'    => $post_title,
						'post_content'  => $presspage_post['message'],
						'post_excerpt'  => $excerpt,
						'post_date'     => $presspage_post['date'],
						'post_status'   => 'publish',
						'post_type'     => 'post',
						'tags_input'    => $post_tags,
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
							media_sideload_image(plugin_dir_path(__FILE__) . 'export-account/uploads/' . $post_image_url, $postInsertId);
						}

						add_action('add_attachment', 'featured_image_trick');
						media_sideload_image($pp_post_images[key($pp_post_images)], $postInsertId);
						remove_action('add_attachment', 'featured_image_trick');

					}

				} catch (Exception $exc) {
					error_log('[Presspage WP Importer] - ' . $exc->getMessage());
				}
			}
		}

		// Deactivate plugin when import finishes
		deactivate_plugins(plugin_basename(__FILE__));
	}
}

add_action( 'trigger_import', 'run_presspage_import' );

if (!function_exists('presspage_wp_importer_run_import')) {
	function presspage_wp_importer_run_import() {

		// Enable dismissable admin notice
		set_transient('presspage-import-admin-notice', true, 5);

		// Begin import as async process
		wp_schedule_single_event( time(), 'trigger_import' );
	}
}

if (!function_exists('presspage_wp_importer_import_complete')) {
	function presspage_wp_importer_import_complete() {
		// Cancel import
		wp_clear_scheduled_hook( 'trigger_import' );
		add_action( 'admin_notices', 'presspage_wp_importer_deactivation_message' );
	}
}

register_activation_hook( __FILE__, 'presspage_wp_importer_run_import' );
register_deactivation_hook( __FILE__, 'presspage_wp_importer_import_complete' );

add_action( 'admin_notices', 'presspage_wp_importer_activation_message' );

if (!function_exists('presspage_wp_importer_activation_message')) {
	function presspage_wp_importer_activation_message(){

		/* Check transient, if available display notice */
		if( get_transient( 'presspage-import-admin-notice' ) ){
			echo '<div class="notice notice-success is-dismissible"><p>Presspage import has started. Plugin will automatically deactivate when import is complete.</p></div>';
			delete_transient( 'presspage-import-admin-notice' );
		}
	}
}

if (!function_exists('presspage_wp_importer_deactivation_message')) {
	function presspage_wp_importer_deactivation_message(){
		echo '<div class="notice notice-success is-dismissible"><p>Import finished. Presspage import plugin has been deactivated.</p></div>';
	}
}
