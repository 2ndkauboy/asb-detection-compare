<?php
/**
 * Plugin Name: Save Original Comment ID
 * Description: Stores the source comment_ID (sent by the ASB comparison harness
 *              via the X-Original-Comment-ID header) in wp_commentmeta.
 */

add_action( 'comment_post', function ( $comment_id ) {
	if ( ! isset( $_SERVER['HTTP_X_ORIGINAL_COMMENT_ID'] ) ) {
		return;
	}

	$original_id = absint( wp_unslash( $_SERVER['HTTP_X_ORIGINAL_COMMENT_ID'] ) );
	if ( $original_id > 0 ) {
		add_comment_meta( $comment_id, 'original_comment_id', $original_id, true );
	}
}, 1, 1 );
