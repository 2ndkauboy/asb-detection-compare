<?php
/**
 * Build a deterministic, DbSpam-safe shard assignment for the corpus.
 *
 * Antispam Bee's DbSpam rule flags a reaction as spam when the local DB already
 * contains a spam entry sharing its comment_author_url, comment_author_IP OR
 * comment_author_email. That makes classification order-dependent. To allow
 * parallel workers without corrupting DbSpam, we assign whole "identity
 * clusters" to the same worker: two comments are in the same cluster if they
 * share ANY of IP / email / URL (transitively). Different clusters can never
 * match each other's DbSpam, so workers processing different clusters never
 * interfere - each cluster is processed sequentially (in comment_ID order) by a
 * single worker, so its DbSpam behaviour is deterministic.
 *
 * This computes the clusters with a union-find over the three identity fields
 * and writes a `<prefix>asb_shard(comment_id, shard)` table into the SOURCE
 * database. driver.php reads it when ASB_SHARD_TABLE is set. Run once before a
 * sharded run:
 *
 *     ASB_WORKER_COUNT=8 php asb-comparison/build-shards.php
 *
 * Two modes, chosen with ASB_SHARD_MODE:
 *
 *   identity (default) - union over IP, e-mail AND URL, transitively. Required
 *     when DbSpam is active, because DbSpam matches on any of the three.
 *   email - group by e-mail address only. Valid ONLY when DbSpam is disabled
 *     (ASB_DISABLE_DB_SPAM=1), where the sole remaining order-dependent rule is
 *     ApprovedEmail, which keys on the e-mail address and nothing else.
 *
 * The distinction matters enormously on real corpora. Spam campaigns reuse IPs,
 * addresses and URLs across each other, so the transitive closure collapses into
 * one giant component: on the ~333k-comment reference corpus a single cluster
 * holds 81% of all comments, which pins 86% of the work onto one worker and makes
 * parallelism useless. Grouping by e-mail alone caps the largest group at a few
 * hundred comments and splits evenly. So when DbSpam is off, prefer `email`.
 *
 * It only needs the source DB (no WordPress), so run it wherever that DB is
 * reachable (e.g. `ddev exec php asb-comparison/build-shards.php` in a project
 * on the shared network).
 */

$host   = getenv( 'ASB_SRC_DB_HOST' ) ?: 'ddev-theme-tests-db';
$port   = (int) ( getenv( 'ASB_SRC_DB_PORT' ) ?: 3306 );
$name   = getenv( 'ASB_SRC_DB_NAME' ) ?: 'db';
$user   = getenv( 'ASB_SRC_DB_USER' ) ?: 'db';
$pass   = getenv( 'ASB_SRC_DB_PASS' ) ?: 'db';
$prefix = getenv( 'ASB_SRC_PREFIX' ) ?: 'wp_2_';
$shards = max( 1, (int) ( getenv( 'ASB_WORKER_COUNT' ) ?: 8 ) );
$mode   = getenv( 'ASB_SHARD_MODE' ) ?: 'identity';

if ( ! in_array( $mode, [ 'identity', 'email' ], true ) ) {
	fwrite( STDERR, "ASB_SHARD_MODE must be 'identity' or 'email', got '$mode'.\n" );
	exit( 2 );
}

// `email` only groups by e-mail address, which is safe for ApprovedEmail but NOT
// for DbSpam — that also matches on IP and URL. Refuse the combination rather
// than silently producing a shard map that lets workers corrupt each other.
if ( 'email' === $mode && '1' !== (string) getenv( 'ASB_DISABLE_DB_SPAM' ) ) {
	fwrite(
		STDERR,
		"ASB_SHARD_MODE=email requires ASB_DISABLE_DB_SPAM=1: DbSpam also matches on\n"
		. "IP and URL, so e-mail-only groups would not keep its inputs on one worker.\n"
	);
	exit( 2 );
}

mysqli_report( MYSQLI_REPORT_OFF );
$db = @mysqli_connect( $host, $user, $pass, $name, $port );
if ( ! $db ) {
	fwrite( STDERR, 'Cannot connect to source DB: ' . mysqli_connect_error() . "\n" );
	exit( 1 );
}
mysqli_set_charset( $db, 'utf8mb4' );

// Separate connection for writes, so we can stream large result sets
// unbuffered on $db while INSERTing on $dbw without "commands out of sync".
$dbw = mysqli_connect( $host, $user, $pass, $name, $port );
mysqli_set_charset( $dbw, 'utf8mb4' );

$comments    = $prefix . 'comments';
$shard_table = $prefix . 'asb_shard';

// --- Union-find over identity keys (i:<ip>, e:<email>, u:<url>) --------------
$parent = [];

/**
 * Find the representative of a key, with path compression.
 *
 * @param string $x Key.
 * @return string Root key.
 */
$find = function ( $x ) use ( &$parent, &$find ) {
	while ( isset( $parent[ $x ] ) && $parent[ $x ] !== $x ) {
		$parent[ $x ] = $parent[ $parent[ $x ] ] ?? $parent[ $x ];
		$x            = $parent[ $x ];
	}
	if ( ! isset( $parent[ $x ] ) ) {
		$parent[ $x ] = $x;
	}
	return $x;
};

/**
 * Union two keys.
 *
 * @param string $a Key.
 * @param string $b Key.
 */
$union = function ( $a, $b ) use ( &$parent, $find ) {
	$ra = $find( $a );
	$rb = $find( $b );
	if ( $ra !== $rb ) {
		$parent[ $ra ] = $rb;
	}
};

/**
 * The cluster key a comment belongs to, per mode.
 *
 * identity: anchored on the IP, with e-mail and URL unioned onto it in pass 1.
 * email:    the e-mail address itself. Comments without one are anchored on
 *           their own id, because ApprovedEmail returns early for an empty
 *           address — they are order-independent and may go to any worker.
 *
 * @param string $mode  Shard mode.
 * @param int    $id    Comment id.
 * @param string $ip    comment_author_IP.
 * @param string $email comment_author_email.
 * @return string Cluster key.
 */
$anchor_of = static function ( string $mode, int $id, string $ip, string $email ): string {
	if ( 'email' === $mode ) {
		$email = strtolower( trim( $email ) );

		return '' !== $email ? 'e:' . $email : 'c:' . $id;
	}

	return 'i:' . strtolower( trim( $ip ) );
};

echo "Pass 1: reading identities and building clusters (mode: $mode)...\n";
$res   = mysqli_query( $db, "SELECT comment_ID, comment_author_IP, comment_author_email, comment_author_url FROM `{$comments}`", MYSQLI_USE_RESULT );
$total = 0;
while ( $row = mysqli_fetch_row( $res ) ) {
	// Normalise keys to match the DB's case-insensitive collation, so identities
	// the rules would treat as equal (e.g. differing only in case/trailing
	// space) end up in the same cluster.
	$anchor = $anchor_of( $mode, (int) $row[0], (string) $row[1], (string) $row[2] );
	$find( $anchor );

	// Only DbSpam needs IP/e-mail/URL to be transitively connected. In `email`
	// mode each address is its own cluster, so there is nothing to union.
	if ( 'identity' === $mode ) {
		if ( $row[2] !== null && trim( $row[2] ) !== '' ) {
			$union( $anchor, 'e:' . strtolower( trim( $row[2] ) ) );
		}
		if ( $row[3] !== null && trim( $row[3] ) !== '' ) {
			$union( $anchor, 'u:' . strtolower( trim( $row[3] ) ) );
		}
	}
	if ( ++$total % 50000 === 0 ) {
		echo "  ...$total\n";
	}
}
mysqli_free_result( $res );
echo "Read $total comments; " . count( $parent ) . " identity keys.\n";

// --- (Re)create the shard table ----------------------------------------------
mysqli_query( $dbw, "DROP TABLE IF EXISTS `{$shard_table}`" );
mysqli_query(
	$dbw,
	"CREATE TABLE `{$shard_table}` (
		comment_id bigint(20) unsigned NOT NULL,
		shard smallint(5) NOT NULL,
		PRIMARY KEY (comment_id),
		KEY shard (shard)
	) DEFAULT CHARSET=utf8mb4"
);

echo "Pass 2: assigning each comment to shard = crc32(cluster_root) % $shards...\n";
$res      = mysqli_query( $db, "SELECT comment_ID, comment_author_IP, comment_author_email FROM `{$comments}`", MYSQLI_USE_RESULT );
$batch    = [];
$written  = 0;
$dist     = array_fill( 0, $shards, 0 );
while ( $row = mysqli_fetch_row( $res ) ) {
	$root  = $find( $anchor_of( $mode, (int) $row[0], (string) $row[1], (string) $row[2] ) );
	$shard = crc32( $root ) % $shards;
	$dist[ $shard ]++;
	$batch[] = '(' . (int) $row[0] . ',' . $shard . ')';
	if ( count( $batch ) >= 5000 ) {
		mysqli_query( $dbw, "INSERT INTO `{$shard_table}` (comment_id, shard) VALUES " . implode( ',', $batch ) );
		$written += count( $batch );
		$batch    = [];
	}
}
mysqli_free_result( $res );
if ( $batch ) {
	mysqli_query( $dbw, "INSERT INTO `{$shard_table}` (comment_id, shard) VALUES " . implode( ',', $batch ) );
	$written += count( $batch );
}
mysqli_close( $db );
mysqli_close( $dbw );

echo "Wrote $written rows to {$shard_table}.\n";
echo "Shard sizes: ";
foreach ( $dist as $i => $c ) {
	echo "$i=$c ";
}
echo "\n";

// Wall time is set by the biggest shard, not the average, so make skew loud:
// a degenerate map turns a parallel run back into a serial one.
if ( $written > 0 && $shards > 1 ) {
	$biggest = max( $dist );
	$share   = $biggest / $written * 100;
	printf( "Biggest shard holds %.1f%% of the corpus (even split would be %.1f%%).\n", $share, 100 / $shards );
	if ( $share > 2 * ( 100 / $shards ) ) {
		printf(
			"  ! Skewed: one worker carries %.1fx its share, so extra workers barely help.\n",
			$share / ( 100 / $shards )
		);
		if ( 'identity' === $mode ) {
			echo "  ! With DbSpam disabled, ASB_SHARD_MODE=email usually splits far more evenly.\n";
		}
	}
}
