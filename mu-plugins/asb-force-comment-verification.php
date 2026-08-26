<?php
/**
 * Plugin Name: ASB Comparison — Force Comment Verification
 * Description: Keeps Antispam Bee's comment verification switched on while the
 *              comparison driver replays the corpus. The driver runs under
 *              WP-CLI, which Antispam Bee 3.x treats as a trusted context and
 *              would otherwise leave every replayed comment unverified. Only
 *              relevant while running the comparison harness; safe to remove
 *              afterwards.
 */

// Since https://github.com/pluginkollektiv/antispam-bee/pull/858, the Comment
// handler decides whether to verify from the request *context* instead of from
// `$_SERVER['SCRIPT_NAME']`, and skips installation, importers, WP-CLI and a
// moderator working in the admin. `lib/driver.php` replays comments in-process
// via `wp eval-file`, so `WP_CLI` is always defined and every comment would be
// returned unflagged — the whole corpus reads as ham and every verdict flips.
//
// The replay is a deliberate simulation of a public front-end submission (the
// driver rebuilds `$_SERVER` and `$_POST` as `wp-comments-post.php` would see
// them), so the trusted-context skip is exactly what must not apply here.
// Antispam Bee versions without that filter ignore this line.
add_filter( 'antispam_bee_skip_comment_verification', '__return_false', 9999 );
