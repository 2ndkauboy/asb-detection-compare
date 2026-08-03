<?php
/**
 * Build a small, stratified corpus from a full one.
 *
 * The full corpus is ~97.6% honeypot-caught spam. Since Antispam Bee 3.x wired
 * up `is_final()`, those comments short-circuit in `Rules::apply()` before
 * BBCode, RegexpSpam, LangSpam or DbSpam are ever evaluated — final rules are
 * sorted first and return immediately. So the content rules only ever run on
 * about 2% of the corpus, while the other 98% costs a full classification pass.
 *
 * This keeps every comment where a non-final rule can still run, plus a reserve
 * of honeypot-caught spam, and drops the rest. The result is ~17x smaller and
 * classifies in minutes rather than half an hour.
 *
 * Strata combine four dimensions:
 *   - the original 2.x reason, carried in the corpus as comment meta
 *   - the 3.x reason combination, read from a published verdict snapshot
 *   - comment type (comment / trackback / pingback)
 *   - whether the text uses a script without word delimiters (LangSpam material)
 *
 * The 3.x combination must come from a snapshot produced BEFORE `is_final()`
 * landed (3.0.0-beta.1). Such a snapshot records every rule that matched, so it
 * still tells us which comments BBCode or RegexpSpam *would* catch — something a
 * current build can no longer reveal, because it stops at the honeypot. That is
 * what makes the retained sample useful rather than filler.
 *
 * Selection is deterministic: within each stratum, rows are ordered by
 * comment_ID and taken at an even stride. Corpus identity feeds the comparison's
 * fingerprint, so regenerating must produce a byte-identical corpus or every
 * published baseline snapshot stops validating.
 *
 * Usage:
 *
 *     php scripts/build-corpus.php \
 *       --db=corpus [--host=127.0.0.1] [--port=3306] [--user=root] [--pass-env=DB_PASS] \
 *       [--prefix=wp_] \
 *       --snapshot=/path/to/asb-snapshot-private-<token>.tsv.gz \
 *       [--css-cap=300] \
 *       > small-corpus.sql
 *
 * Then encrypt it the same way as the full dump before publishing:
 *
 *     gzip -9 < small-corpus.sql > small-corpus.sql.gz
 *     gpg --batch --symmetric --cipher-algo AES256 --passphrase "$KEY" \
 *       -o small-corpus.sql.gz.gpg small-corpus.sql.gz
 *
 * @package AntispamBee\Comparison
 */

require_once __DIR__ . '/../lib/snapshot-format.php';

$opts = [
	'host'         => '127.0.0.1',
	'port'         => '3306',
	'user'         => 'root',
	'pass-env'     => 'DB_PASS',
	'db'           => '',
	'prefix'       => 'wp_',
	'snapshot'     => '',
	'css-cap'      => '300',
];

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( ! preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) || ! array_key_exists( $m[1], $opts ) ) {
		fwrite( STDERR, "Unknown or malformed argument: $arg\n" );
		exit( 2 );
	}
	$opts[ $m[1] ] = $m[2];
}

if ( '' === $opts['db'] || '' === $opts['snapshot'] ) {
	fwrite( STDERR, "--db and --snapshot are required.\n" );
	exit( 2 );
}
if ( ! is_readable( $opts['snapshot'] ) ) {
	fwrite( STDERR, "Cannot read snapshot: {$opts['snapshot']}\n" );
	exit( 2 );
}

$css_cap = max( 1, (int) $opts['css-cap'] );

mysqli_report( MYSQLI_REPORT_OFF );
$db = @mysqli_connect(
	$opts['host'],
	$opts['user'],
	(string) getenv( $opts['pass-env'] ),
	$opts['db'],
	(int) $opts['port']
);
if ( ! $db ) {
	fwrite( STDERR, 'Cannot connect: ' . mysqli_connect_error() . "\n" );
	exit( 1 );
}
mysqli_set_charset( $db, 'utf8mb4' );

$comments    = $opts['prefix'] . 'comments';
$commentmeta = $opts['prefix'] . 'commentmeta';

/**
 * Escape a string for a single-quoted MySQL literal.
 *
 * @param string $s Raw value.
 */
function sql_str( string $s ): string {
	$s = str_replace( "\0", '', $s );
	$s = str_replace( '\\', '\\\\', $s );
	$s = str_replace( "'", "\\'", $s );

	return "'" . $s . "'";
}

// ---------------------------------------------------------------------------
// The corpus fingerprint, so the salt matches the one that produced the
// snapshot and the pseudonyms can be joined back to comment ids.
// ---------------------------------------------------------------------------
$crc = "CRC32(CONCAT_WS(0x1f, comment_ID, comment_content, comment_author,
	comment_author_email, comment_author_url, comment_author_IP, comment_agent, comment_type))";
$row = mysqli_fetch_row(
	mysqli_query(
		$db,
		"SELECT MD5(CONCAT_WS(':', COUNT(*), IFNULL(SUM($crc), 0), IFNULL(BIT_XOR($crc), 0)))
		FROM `$comments`"
	)
);
$corpus_fp = (string) $row[0];
$salt      = asb_snapshot_salt( $corpus_fp, '' );

fwrite( STDERR, sprintf( "source corpus token: %s\n", asb_snapshot_corpus_token( $corpus_fp ) ) );

// 3.x reason per pseudonym.
$by_pid = [];
$gz     = gzopen( $opts['snapshot'], 'rb' );
while ( ( $line = gzgets( $gz ) ) !== false ) {
	if ( '#' === $line[0] ) {
		continue;
	}
	$col = explode( "\t", rtrim( $line, "\n" ), 3 );
	if ( 3 !== count( $col ) ) {
		continue;
	}
	$by_pid[ $col[0] ] = '\N' === $col[2] ? '(none)' : rawurldecode( $col[2] );
}
gzclose( $gz );
fwrite( STDERR, sprintf( "snapshot verdicts: %d\n", count( $by_pid ) ) );

// ---------------------------------------------------------------------------
// Pass 1 — assign every comment to a stratum. The script test runs in SQL so
// the content itself never has to be held in memory.
// ---------------------------------------------------------------------------
$spaceless_sql = "comment_content REGEXP '[\\\\x{4E00}-\\\\x{9FFF}\\\\x{3040}-\\\\x{30FF}\\\\x{AC00}-\\\\x{D7AF}\\\\x{0E00}-\\\\x{0E7F}\\\\x{0E80}-\\\\x{0EFF}\\\\x{1780}-\\\\x{17FF}\\\\x{1000}-\\\\x{109F}\\\\x{0F00}-\\\\x{0FFF}]'";

$res = mysqli_query(
	$db,
	"SELECT c.comment_ID,
			IF( c.comment_type = '', 'comment', c.comment_type ) AS ctype,
			COALESCE( NULLIF( m.meta_value, '' ), 'ham' ) AS v2,
			IF( $spaceless_sql, 'spaceless', 'latin' ) AS script
		FROM `$comments` AS c
		LEFT JOIN `$commentmeta` AS m
			ON m.comment_id = c.comment_ID AND m.meta_key = 'antispam_bee_reason'
		ORDER BY c.comment_ID",
	MYSQLI_USE_RESULT
);
if ( ! $res ) {
	fwrite( STDERR, 'Pass 1 failed: ' . mysqli_error( $db ) . "\n" );
	exit( 1 );
}

$strata = [];
$total  = 0;
while ( $r = mysqli_fetch_row( $res ) ) {
	list( $id, $ctype, $v2, $script ) = $r;
	++$total;
	$v3  = $by_pid[ asb_snapshot_pid( $salt, (string) $id ) ] ?? '(unclassified)';
	$key = "$v2 | $v3 | $ctype | $script";

	$strata[ $key ][] = (int) $id;
}
mysqli_free_result( $res );
fwrite( STDERR, sprintf( "%d comments in %d strata\n", $total, count( $strata ) ) );

// ---------------------------------------------------------------------------
// Pass 2 — decide how much of each stratum to keep.
//
// Anything that is not honeypot-caught stays whole: that is the entire surface
// on which the content rules still run, and the ham set is the false-positive
// check.
//
// Honeypot-caught strata are capped hard, and all of them equally. On a current
// build every one of those comments produces the same single verdict — the extra
// reasons the snapshot records for some of them are historical, from before
// `is_final()` short-circuited the rules that found them. What is left is enough
// to catch a regression in the honeypot itself and to keep the corpus's shape
// (scripts, comment types); investigating the content rules against this spam
// belongs on the full corpus.
// ---------------------------------------------------------------------------
$chosen = [];
$report = [];
ksort( $strata );
foreach ( $strata as $key => $ids ) {
	$size   = count( $ids );
	$is_css = str_starts_with( $key, 'css |' );

	if ( $is_css ) {
		$quota = min( $size, $css_cap );
		$why   = 'honeypot-caught, capped';
	} else {
		$quota = $size;
		$why   = 'kept whole (content rules still run here)';
	}

	// Even stride, so the sample spans the whole corpus instead of its oldest
	// comments, and is reproducible.
	$take = [];
	if ( $quota >= $size ) {
		$take = $ids;
	} else {
		$step = $size / $quota;
		for ( $i = 0; $i < $quota; $i++ ) {
			$take[] = $ids[ (int) floor( $i * $step ) ];
		}
		$take = array_values( array_unique( $take ) );
	}

	foreach ( $take as $id ) {
		$chosen[ $id ] = true;
	}
	$report[] = [ $key, $size, count( $take ), $why ];
}

$kept = count( $chosen );
fwrite( STDERR, sprintf( "\nkeeping %d of %d comments (%.1fx smaller)\n\n", $kept, $total, $total / max( $kept, 1 ) ) );
fwrite( STDERR, sprintf( "  %-64s %8s %8s  %s\n", 'stratum', 'size', 'kept', 'why' ) );
foreach ( $report as list( $key, $size, $n, $why ) ) {
	fwrite( STDERR, sprintf( "  %-64s %8d %8d  %s\n", $key, $size, $n, $why ) );
}

// ---------------------------------------------------------------------------
// Emit the dump. Same shape as the source: wp_comments + wp_commentmeta, with
// the original ids and the antispam_bee_reason meta, which the driver keys its
// decoy replay off.
// ---------------------------------------------------------------------------
$ids_csv = implode( ',', array_map( 'intval', array_keys( $chosen ) ) );

echo "-- Small stratified corpus for asb-detection-compare.\n";
echo "--\n";
echo "-- Generated by scripts/build-corpus.php from a full corpus. Keeps every\n";
echo "-- comment where a non-final rule can still run (the honeypot short-circuits\n";
echo "-- the rest), plus a capped reserve of honeypot-caught spam.\n";
echo "--\n";
printf( "-- Source corpus: %d comments, token %s\n", $total, asb_snapshot_corpus_token( $corpus_fp ) );
printf( "-- This corpus:   %d comments (%.1fx smaller)\n", $kept, $total / max( $kept, 1 ) );
echo "--\n";
echo "-- Contains real comment content, including real ham. Keep it encrypted;\n";
echo "-- do not commit it. Do not edit by hand.\n\n";

// The tables are utf8mb4, but that is not enough on its own: if the loading
// client's connection charset is only 3-byte utf8, a 4-byte character (emoji -
// this corpus has 32 of them) raises "Incorrect string value" and, with the
// mysql CLI's default error handling, aborts the rest of the import. That fails
// loudly at the comment it chokes on but silently leaves every later table
// short, so declare the connection charset in the dump. Matches the full
// corpus dump.
echo "SET NAMES utf8mb4;\n\n";

echo "DROP TABLE IF EXISTS wp_comments;\n";
echo "CREATE TABLE wp_comments (\n";
echo "\tcomment_ID           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n";
echo "\tcomment_content      TEXT,\n";
echo "\tcomment_author       TINYTEXT,\n";
echo "\tcomment_author_email VARCHAR(100) DEFAULT '',\n";
echo "\tcomment_author_url   VARCHAR(200) DEFAULT '',\n";
echo "\tcomment_author_IP    VARCHAR(100) DEFAULT '',\n";
echo "\tcomment_agent        VARCHAR(255) DEFAULT '',\n";
echo "\tcomment_type         VARCHAR(20)  DEFAULT 'comment',\n";
echo "\tPRIMARY KEY (comment_ID)\n";
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

echo "DROP TABLE IF EXISTS wp_commentmeta;\n";
echo "CREATE TABLE wp_commentmeta (\n";
echo "\tmeta_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n";
echo "\tcomment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n";
echo "\tmeta_key   VARCHAR(255) DEFAULT NULL,\n";
echo "\tmeta_value LONGTEXT,\n";
echo "\tPRIMARY KEY (meta_id),\n";
echo "\tKEY comment_id (comment_id)\n";
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

$cols = '(comment_ID, comment_content, comment_author, comment_author_email, '
	. 'comment_author_url, comment_author_IP, comment_agent, comment_type)';

$res = mysqli_query(
	$db,
	"SELECT comment_ID, comment_content, comment_author, comment_author_email,
			comment_author_url, comment_author_IP, comment_agent,
			IF( comment_type = '', 'comment', comment_type )
		FROM `$comments` WHERE comment_ID IN ($ids_csv) ORDER BY comment_ID",
	MYSQLI_USE_RESULT
);

$batch   = [];
$written = 0;
$flush   = function () use ( &$batch, $cols ) {
	if ( ! $batch ) {
		return;
	}
	echo "INSERT INTO wp_comments\n\t$cols\nVALUES\n" . implode( ",\n", $batch ) . ";\n\n";
	$batch = [];
};
while ( $r = mysqli_fetch_row( $res ) ) {
	$batch[] = sprintf(
		"\t(%d, %s, %s, %s, %s, %s, %s, %s)",
		(int) $r[0],
		sql_str( (string) $r[1] ),
		sql_str( (string) $r[2] ),
		sql_str( (string) $r[3] ),
		sql_str( (string) $r[4] ),
		sql_str( (string) $r[5] ),
		sql_str( (string) $r[6] ),
		sql_str( (string) $r[7] )
	);
	++$written;
	if ( count( $batch ) >= 500 ) {
		$flush();
	}
}
$flush();
mysqli_free_result( $res );

// The reason meta must come along: lib/driver.php replays a comment whose reason
// is `css` through the visible decoy field, which is what reproduces the
// honeypot catch. Without it the corpus classifies differently.
$res  = mysqli_query(
	$db,
	"SELECT comment_id, meta_value FROM `$commentmeta`
		WHERE meta_key = 'antispam_bee_reason' AND comment_id IN ($ids_csv)
		ORDER BY comment_id",
	MYSQLI_USE_RESULT
);
$meta = [];
while ( $r = mysqli_fetch_row( $res ) ) {
	$meta[] = sprintf( "\t(%d, 'antispam_bee_reason', %s)", (int) $r[0], sql_str( (string) $r[1] ) );
}
mysqli_free_result( $res );
mysqli_close( $db );

if ( $meta ) {
	echo "-- Original 2.x spam reason per comment; the driver uses the `css` reason\n";
	echo "-- to decide which comments to replay through the visible decoy field.\n";
	foreach ( array_chunk( $meta, 500 ) as $chunk ) {
		echo "INSERT INTO wp_commentmeta (comment_id, meta_key, meta_value)\nVALUES\n"
			. implode( ",\n", $chunk ) . ";\n\n";
	}
}

fwrite( STDERR, sprintf( "\nwrote %d comments and %d reason rows\n", $written, count( $meta ) ) );
