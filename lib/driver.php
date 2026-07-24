<?php
/**
 * In-process Antispam Bee classification driver.
 *
 * Replays an exported corpus of comments through the Antispam Bee plugin that is
 * active in the *current* WordPress site and records each verdict, so the old
 * (2.x) and new (3.x) plugin versions can be compared over a large corpus.
 *
 * Unlike the previous approach it does NOT send HTTP requests. WordPress + the
 * plugin are bootstrapped once (by WP-CLI); every comment is then classified
 * in-process by calling WordPress' own {@see wp_new_comment()} - the exact path
 * `wp-comments-post.php` takes, minus the network and the per-request bootstrap.
 * The resulting `comment_approved` status and `antispam_bee_reason` meta are
 * written to this site's DB, keyed to the source id via `original_comment_id`
 * meta (see the `save-original-comment-id` mu-plugin).
 *
 * Run inside each site (e.g. via DDEV), one process per shard:
 *
 *     wp eval-file asb-comparison/driver.php <worker_index> <worker_count>
 *
 * All settings can be overridden via environment variables (see below); this is
 * how `run.sh` parametrises the workers.
 *
 * To skip the (order-dependent, cascade-prone) DbSpam rule for a fast,
 * deterministic run, set ASB_DISABLE_DB_SPAM=1. Pass it as an environment
 * variable, not a `--flag`: WP-CLI reserves `--options`, so `wp eval-file
 * driver.php 0 8 --disable-db-spam` fails. Use:
 *     ASB_DISABLE_DB_SPAM=1 wp eval-file driver.php 0 8
 * (run.sh accepts the friendlier `--disable-db-spam` and sets this for you.)
 *
 * @package AntispamBee\Comparison
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must be run through WP-CLI (wp eval-file).\n" );
	exit( 1 );
}

/**
 * Log a line, via WP-CLI when available, otherwise to stdout.
 *
 * @param string $message Message to log.
 */
function asb_driver_log( $message ) {
	if ( class_exists( '\WP_CLI' ) ) {
		\WP_CLI::log( $message );
	} else {
		echo $message, "\n";
	}
}

/**
 * Abort with an error message.
 *
 * @param string $message Message to log.
 */
function asb_driver_die( $message ) {
	if ( class_exists( '\WP_CLI' ) ) {
		\WP_CLI::error( $message );
	}
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Snapshot the current hook (filter/action) registry.
 *
 * Antispam Bee and WordPress register per-request callbacks - notably on
 * `pre_comment_approved` (the spam marker) and `comment_post` (which writes the
 * reason meta) - and never remove them, because in normal operation each
 * request is a fresh process. In this long-lived driver those callbacks would
 * otherwise accumulate across comments: after the first spam hit the sticky
 * `pre_comment_approved` closure would force every later comment to "spam", and
 * the reason writers would fire once per previously-processed comment. We
 * therefore capture a clean baseline and reset to it before each comment.
 *
 * @return array<string, array> Map of hook tag => callbacks array.
 */
function asb_snapshot_hooks() {
	$snapshot = [];
	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( $hook instanceof WP_Hook ) {
			$snapshot[ $tag ] = $hook->callbacks;
		}
	}
	return $snapshot;
}

/**
 * Reset the hook registry to a snapshot, dropping any callbacks registered
 * since it was taken (and clearing hooks that did not exist then).
 *
 * @param array<string, array> $snapshot Snapshot from {@see asb_snapshot_hooks()}.
 */
function asb_restore_hooks( $snapshot ) {
	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		if ( ! ( $hook instanceof WP_Hook ) ) {
			continue;
		}
		$hook->callbacks = isset( $snapshot[ $tag ] ) ? $snapshot[ $tag ] : [];
	}
}

/*
 * ---------------------------------------------------------------------------
 * Configuration (overridable via environment variables).
 * ---------------------------------------------------------------------------
 * The source corpus lives in a *different* DDEV project's database than the
 * receiving site, so we open our own connection to it rather than reusing the
 * site's $wpdb. Defaults assume the corpus is the `wp_2_`-prefixed tables in
 * the `theme-tests` DDEV database, reachable on the shared DDEV network.
 */
$src_db_host   = getenv( 'ASB_SRC_DB_HOST' ) ?: 'ddev-theme-tests-db';
$src_db_port   = (int) ( getenv( 'ASB_SRC_DB_PORT' ) ?: 3306 );
$src_db_name   = getenv( 'ASB_SRC_DB_NAME' ) ?: 'db';
$src_db_user   = getenv( 'ASB_SRC_DB_USER' ) ?: 'db';
$src_db_pass   = getenv( 'ASB_SRC_DB_PASS' ) ?: 'db';
$src_prefix    = getenv( 'ASB_SRC_PREFIX' ) ?: 'wp_2_';

// Optional identity-cluster shard table (built by build-shards.php). When set,
// this worker processes the comments whose shard equals its worker_index, which
// keeps all DbSpam-related comments together and makes parallel runs
// deterministic. When empty, we fall back to a simple MOD(comment_ID) shard.
$shard_table   = getenv( 'ASB_SHARD_TABLE' ) ?: '';

$target_post_id = (int) ( getenv( 'ASB_TARGET_POST_ID' ) ?: 1 );
$read_batch     = max( 1, (int) ( getenv( 'ASB_BATCH' ) ?: 2000 ) );
$progress_every = max( 1, (int) ( getenv( 'ASB_PROGRESS_EVERY' ) ?: 1000 ) );
$limit_total    = (int) ( getenv( 'ASB_LIMIT' ) ?: 0 ); // 0 = process the whole corpus.

// Optional flags may appear anywhere in the positional args (wp eval-file
// exposes them as $args); pull them out so the remaining args stay positional.
$flags = isset( $args ) && is_array( $args ) ? $args : [];
$disable_db_spam = getenv( 'ASB_DISABLE_DB_SPAM' ) || in_array( '--disable-db-spam', $flags, true );
$positional = array_values(
	array_filter(
		$flags,
		static function ( $a ) {
			return '' === $a || '-' !== $a[0]; // Drop --flags, keep positional values.
		}
	)
);

// Worker sharding: positional args first, then env, then a single-worker default.
$worker_index = isset( $positional[0] ) ? (int) $positional[0] : (int) ( getenv( 'ASB_WORKER_INDEX' ) ?: 0 );
$worker_count = isset( $positional[1] ) ? (int) $positional[1] : (int) ( getenv( 'ASB_WORKER_COUNT' ) ?: 1 );
if ( $worker_count < 1 ) {
	$worker_count = 1;
}
if ( $worker_index < 0 || $worker_index >= $worker_count ) {
	asb_driver_die( "worker_index ($worker_index) must be in [0, worker_count=$worker_count)." );
}

/*
 * ---------------------------------------------------------------------------
 * Detect which Antispam Bee version is active and wire up the version-specific
 * honeypot handling. The secret field name is derived live from the plugin so
 * we never depend on a stale hard-coded hash.
 * ---------------------------------------------------------------------------
 */
if ( class_exists( '\AntispamBee\Helpers\Honeypot' ) && class_exists( '\AntispamBee\Rules\Honeypot' ) ) {
	$asb_version = '3.x';
	$secret_name = \AntispamBee\Helpers\Honeypot::get_secret_name_for_post();
	$run_precheck = static function () {
		\AntispamBee\Rules\Honeypot::precheck();
	};
} elseif ( class_exists( '\Antispam_Bee' ) ) {
	$asb_version = '2.x';
	$secret_name = \Antispam_Bee::get_secret_name_for_post( $target_post_id );
	$run_precheck = static function () {
		\Antispam_Bee::precheck_incoming_request();
	};
} else {
	asb_driver_die( 'Antispam Bee does not appear to be active in this site.' );
}

// Optionally disable the DbSpam rule for this run (--disable-db-spam or
// ASB_DISABLE_DB_SPAM=1). DbSpam is order-dependent and, on densely linked
// corpora, both an unparallelizable and runaway cascade; turning it off gives a
// fast, deterministic run. This changes nothing in the DB - it only filters the
// plugin's option/ruleset in-process for this process.
if ( $disable_db_spam ) {
	// asb-2 (2.x): the local DB-spam check is gated by the `spam_ip` option.
	add_filter(
		'option_antispam_bee',
		static function ( $options ) {
			if ( is_array( $options ) ) {
				$options['spam_ip'] = 0;
			}
			return $options;
		}
	);
	// asb-3 (3.x): drop the DbSpam rule from the ruleset (rebuilt per reaction),
	// and force its active-flag off as a belt-and-braces measure.
	add_filter(
		'antispam_bee_rules',
		static function ( $rules ) {
			return array_values(
				array_filter(
					(array) $rules,
					static function ( $rule ) {
						return ltrim( (string) $rule, '\\' ) !== 'AntispamBee\Rules\DbSpam';
					}
				)
			);
		}
	);
	add_filter(
		'option_antispam_bee_options',
		static function ( $options ) {
			if ( is_array( $options ) ) {
				$options['rule_asb_db_spam_active'] = '';
			}
			return $options;
		}
	);
	// asb-2 caches its options in the object cache at init (wp_cache_get(
	// 'antispam_bee' )) BEFORE these filters exist, so the option_ filter would
	// otherwise never re-fire. Evict both keys so the next read re-applies the
	// filters. (asb-3's ruleset filter already takes effect per reaction.)
	wp_cache_delete( 'antispam_bee' );
	wp_cache_delete( 'antispam_bee_options' );
}

// Confirm the target post exists and can receive comments.
$target_post = get_post( $target_post_id );
if ( ! $target_post ) {
	asb_driver_die( "Target post #$target_post_id does not exist on this site." );
}

asb_driver_log(
	sprintf(
		'ASB %s | worker %d/%d | source %s.%scomments | honeypot field "%s" | target post #%d | DbSpam: %s',
		$asb_version,
		$worker_index,
		$worker_count,
		$src_db_name,
		$src_prefix,
		$secret_name,
		$target_post_id,
		$disable_db_spam ? 'DISABLED' : 'enabled'
	)
);

/*
 * ---------------------------------------------------------------------------
 * Build the set of already-processed source ids (resume support). We look at
 * the `original_comment_id` meta this site has written on previous runs so a
 * re-run skips work instead of duplicating comments.
 * ---------------------------------------------------------------------------
 */
global $wpdb;
$done = [];
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = 'original_comment_id'" ) as $processed_id ) {
	$done[ (int) $processed_id ] = true;
}
if ( $done ) {
	asb_driver_log( sprintf( 'Resume: %d source comments already processed on this site will be skipped.', count( $done ) ) );
}

/*
 * ---------------------------------------------------------------------------
 * Connect to the source corpus.
 * ---------------------------------------------------------------------------
 */
mysqli_report( MYSQLI_REPORT_OFF );
$src = @mysqli_connect( $src_db_host, $src_db_user, $src_db_pass, $src_db_name, $src_db_port );
if ( ! $src ) {
	asb_driver_die( sprintf( 'Cannot connect to source DB %s@%s:%d/%s: %s', $src_db_user, $src_db_host, $src_db_port, $src_db_name, mysqli_connect_error() ) );
}
mysqli_set_charset( $src, 'utf8mb4' );

$comments_table    = $src_prefix . 'comments';
$commentmeta_table = $src_prefix . 'commentmeta';

// One row per source comment; the css special-case needs the source reason.
// Two sharding modes: an identity-cluster shard table (DbSpam-safe, preferred),
// or a simple MOD(comment_ID) fallback.
if ( '' !== $shard_table ) {
	$shard_join   = "INNER JOIN `{$shard_table}` AS sh ON sh.comment_id = c.comment_ID AND sh.shard = ?";
	$shard_where  = '1 = 1';
	$shard_types  = 'iii'; // worker_index, offset, fetch.
} else {
	$shard_join  = '';
	$shard_where = 'MOD(c.comment_ID, ?) = ?';
	$shard_types = 'iiii'; // worker_count, worker_index, offset, fetch.
}

$select_sql = "SELECT c.comment_ID, c.comment_content, c.comment_author,
			c.comment_author_email, c.comment_author_url, c.comment_author_IP,
			c.comment_agent, c.comment_type, cm.meta_value AS antispam_bee_reason
		FROM `{$comments_table}` AS c
		{$shard_join}
		LEFT JOIN `{$commentmeta_table}` AS cm
			ON c.comment_ID = cm.comment_id AND cm.meta_key = 'antispam_bee_reason'
		WHERE {$shard_where}
		GROUP BY c.comment_ID
		ORDER BY c.comment_ID
		LIMIT ?, ?";

$stmt = mysqli_prepare( $src, $select_sql );
if ( ! $stmt ) {
	asb_driver_die( 'Failed to prepare source query: ' . mysqli_error( $src ) );
}

// Total number of comments this worker will iterate, for the progress bar/ETA.
if ( '' !== $shard_table ) {
	$count_sql = "SELECT COUNT(*) FROM `{$comments_table}` AS c
		INNER JOIN `{$shard_table}` AS sh ON sh.comment_id = c.comment_ID AND sh.shard = " . (int) $worker_index;
} else {
	$count_sql = "SELECT COUNT(*) FROM `{$comments_table}` AS c
		WHERE MOD(c.comment_ID, " . (int) $worker_count . ') = ' . (int) $worker_index;
}
$total_rows = 0;
$count_res  = mysqli_query( $src, $count_sql );
if ( $count_res ) {
	$row        = mysqli_fetch_row( $count_res );
	$total_rows = (int) ( $row[0] ?? 0 );
	mysqli_free_result( $count_res );
}
if ( $limit_total > 0 ) {
	$total_rows = min( $total_rows, $limit_total );
}

// Use WP-CLI's progress bar (with ETA) only on an interactive terminal - it
// draws nothing when piped/redirected. When output is redirected (e.g. run.sh
// sends each parallel worker to its own log file) we fall back to periodic text
// lines instead, so progress is still visible there.
$progress = null;
$is_tty   = function_exists( 'stream_isatty' ) && @stream_isatty( STDOUT );
if ( $is_tty && class_exists( '\WP_CLI' ) && function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
	$progress = \WP_CLI\Utils\make_progress_bar(
		sprintf( 'ASB %s worker %d/%d', $asb_version, $worker_index, $worker_count ),
		$total_rows
	);
}

/*
 * ---------------------------------------------------------------------------
 * Main loop: page through this worker's shard and classify each comment.
 * ---------------------------------------------------------------------------
 */
$default_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
$offset        = 0;
$seen          = 0; // Rows fetched for this shard.
$processed     = 0; // Comments actually classified this run.
$skipped       = 0; // Skipped because already processed.
$errors        = 0;
$started        = microtime( true );

// Clean baseline of all registered hooks, taken after WordPress + the plugin
// have fully initialised and before we simulate any comment submission.
$hook_baseline = asb_snapshot_hooks();

while ( true ) {
	$fetch = $read_batch;
	if ( $limit_total > 0 ) {
		$remaining = $limit_total - $seen;
		if ( $remaining <= 0 ) {
			break;
		}
		$fetch = min( $read_batch, $remaining );
	}

	if ( '' !== $shard_table ) {
		mysqli_stmt_bind_param( $stmt, $shard_types, $worker_index, $offset, $fetch );
	} else {
		mysqli_stmt_bind_param( $stmt, $shard_types, $worker_count, $worker_index, $offset, $fetch );
	}
	mysqli_stmt_execute( $stmt );
	$result = mysqli_stmt_get_result( $stmt );

	$rows = [];
	while ( $row = mysqli_fetch_assoc( $result ) ) {
		$rows[] = $row;
	}
	mysqli_free_result( $result );

	if ( ! $rows ) {
		break;
	}

	foreach ( $rows as $row ) {
		++$seen;
		$offset++;

		$source_id = (int) $row['comment_ID'];
		if ( isset( $done[ $source_id ] ) ) {
			++$skipped;
			continue;
		}

		// Reset to a clean hook state so this comment is classified exactly as a
		// fresh request would be, unaffected by callbacks added while processing
		// earlier comments.
		asb_restore_hooks( $hook_baseline );

		$content = (string) $row['comment_content'];
		$author  = (string) $row['comment_author'];
		$email   = (string) $row['comment_author_email'];
		$url     = (string) $row['comment_author_url'];
		$ip      = (string) $row['comment_author_IP'];
		$agent   = $row['comment_agent'] !== null && $row['comment_agent'] !== '' ? (string) $row['comment_agent'] : $default_agent;
		$reason  = $row['antispam_bee_reason'];
		$type    = (string) $row['comment_type'];

		// Common request context. IP/User-Agent/original-id are read by the
		// plugin + support mu-plugins from $_SERVER for every reaction type.
		$_SERVER['REQUEST_METHOD']             = 'POST';
		$_SERVER['HTTP_USER_AGENT']            = $agent;
		$_SERVER['HTTP_X_FORWARDED_FOR']       = $ip;
		$_SERVER['REMOTE_ADDR']                = $ip;
		$_SERVER['HTTP_X_ORIGINAL_COMMENT_ID'] = (string) $source_id;

		if ( 'trackback' === $type || 'pingback' === $type || 'pings' === $type ) {
			// Linkback (trackback/pingback). WordPress builds these without the
			// honeypot and without the require_name_email front door, and inserts
			// them with wp_new_comment() directly (see wp-trackback.php). Antispam
			// Bee's Linkback handler + linkback rules operate on the stored comment
			// array (comment_content / comment_author / url), not on $_POST, so we
			// replay the stored fields with the original comment_type. Note: a real
			// pingback is verified by fetching the remote source over XML-RPC; that
			// cannot be reproduced for historical data, so we replay the already
			// verified linkback so the spam rules are exercised.
			$_SERVER['SCRIPT_NAME'] = 'pingback' === $type ? '/xmlrpc.php' : '/wp-trackback.php';
			$_SERVER['PHP_SELF']    = $_SERVER['SCRIPT_NAME'];
			$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
			$_POST                  = [];

			$commentdata = [
				'comment_post_ID'      => $target_post_id,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_author_url'   => $url,
				'comment_content'      => $content,
				'comment_type'         => $type,
				'comment_parent'       => 0,
			];
			$new_comment = wp_new_comment( wp_slash( $commentdata ), true );
		} else {
			// Regular comment. Rebuild the request context exactly as
			// wp-comments-post.php would see it. Values in $_POST are slashed
			// because WordPress' wp_magic_quotes() slashes real request input
			// before the comment code runs.
			$_SERVER['SCRIPT_NAME'] = '/wp-comments-post.php';
			$_SERVER['PHP_SELF']    = '/wp-comments-post.php';
			$_SERVER['REQUEST_URI'] = '/wp-comments-post.php';

			$_POST = [
				'author'          => wp_slash( $author ),
				'email'           => wp_slash( $email ),
				'url'             => wp_slash( $url ),
				'comment_post_ID' => (string) $target_post_id,
			];
			if ( 'css' === $reason ) {
				// Historically caught via the visible (decoy) field: leave content there.
				$_POST['comment'] = wp_slash( $content );
			} else {
				// Legit-looking submission: content in the hashed field, decoy empty,
				// so the honeypot passes and the remaining rules decide the verdict.
				$_POST['comment']      = '';
				$_POST[ $secret_name ] = wp_slash( $content );
			}

			// Reproduce the honeypot field remap that normally happens on `init`
			// (init already fired during bootstrap, when $_POST was still empty).
			$run_precheck();

			// Mirror wp-comments-post.php exactly: run the full front-door submission,
			// which applies WordPress core's own checks (require_name_email,
			// comments-open, comment_registration, empty comment, ...) and then calls
			// wp_new_comment() internally. Using this - rather than wp_new_comment()
			// directly - means comments WP core would reject (e.g. missing email when
			// require_name_email is on) are rejected here too, with no row inserted,
			// exactly as over HTTP.
			$new_comment = wp_handle_comment_submission( wp_unslash( $_POST ) );
		}

		if ( is_wp_error( $new_comment ) ) {
			++$errors;
			if ( $errors <= 20 ) {
				asb_driver_log( sprintf( '  ! source #%d error: %s', $source_id, $new_comment->get_error_message() ) );
			}
		} else {
			$done[ $source_id ] = true;
			++$processed;
		}

		if ( $progress ) {
			$progress->tick();
		}

		if ( 0 === ( ( $processed + $skipped ) % $progress_every ) ) {
			if ( ! $progress ) {
				$elapsed = microtime( true ) - $started;
				$rate    = $elapsed > 0 ? $processed / $elapsed * 60 : 0;
				asb_driver_log( sprintf(
					'  worker %d: seen %d, processed %d, skipped %d, errors %d (%.0f/min)',
					$worker_index,
					$seen,
					$processed,
					$skipped,
					$errors,
					$rate
				) );
			}

			// The object cache accumulates every inserted comment/meta; flush
			// periodically so memory stays bounded across a full 500k run.
			wp_cache_flush();
		}
	}

	if ( count( $rows ) < $fetch ) {
		break; // Last page.
	}
}

if ( $progress ) {
	$progress->finish();
}

mysqli_stmt_close( $stmt );
mysqli_close( $src );

$elapsed = microtime( true ) - $started;
asb_driver_log( sprintf(
	'Done. worker %d/%d: seen %d, processed %d, skipped %d, errors %d in %.1fs (%.0f/min).',
	$worker_index,
	$worker_count,
	$seen,
	$processed,
	$skipped,
	$errors,
	$elapsed,
	$elapsed > 0 ? $processed / $elapsed * 60 : 0
) );
