<?php
/**
 * Plugin Name: Disable comment flood
 */

add_action( 'init', function () {
	remove_filter( 'check_comment_flood', 'wp_check_comment_flood' );
	remove_filter( 'wp_is_comment_flood', 'wp_check_comment_flood' );
	add_filter('wp_is_comment_flood', '__return_false', 9999);
} );