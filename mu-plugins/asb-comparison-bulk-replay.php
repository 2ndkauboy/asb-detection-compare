<?php
/**
 * Plugin Name: ASB Comparison — Bulk Replay Helpers
 * Description: Neutralises WordPress core / notification behaviour that would
 *              otherwise contaminate a bulk in-process comment replay driven by
 *              asb-comparison/driver.php. Only relevant while running the
 *              comparison harness; safe to remove afterwards.
 */

// A 500k corpus contains many identical comment bodies. WordPress core
// duplicate-comment detection would otherwise reject them (wp_die / WP_Error),
// which is core behaviour, not the plugin's, and must not skew the comparison.
add_filter( 'duplicate_comment_id', '__return_zero' );

// Do not send any notification e-mails while replaying hundreds of thousands
// of comments (covers WP core notifications and Antispam Bee's own mails).
add_filter( 'pre_wp_mail', '__return_true' );
