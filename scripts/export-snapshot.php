<?php
/**
 * Export one classification pass as a pseudonymous verdict snapshot.
 *
 * Reads the same three fields the comparer reads — `original_comment_id`,
 * `comment_approved` and the `antispam_bee_reason` meta — from a labelled set of
 * WordPress tables, and writes them as a gzipped, pseudonymised TSV. See
 * `lib/snapshot-format.php` for the file layout and the privacy invariant.
 *
 * Usage:
 *
 *     php scripts/export-snapshot.php \
 *       --db=asbcmp_site --prefix=wp_ \
 *       [--host=127.0.0.1] [--port=3306] [--user=root] [--pass-env=DB_PASS] \
 *       [--salt-file=/path/to/salt] \
 *       --meta='{"baseline_ref":"3.0.0-beta.1","hard_fp":"…","shard_mode":"cluster"}' \
 *       --out=/path/to/asb-snapshot-private-1a2b3c4d.tsv.gz
 *
 * The salt is passed as a *file*, never on the command line: argv is visible to
 * every process on the machine via `ps`. Omitting `--salt-file` exports raw ids,
 * which is convenient locally but marks the snapshot unpublishable.
 *
 * The complete manifest is echoed to STDOUT as JSON so the caller can read back
 * the row count, the body hash and the publishable flag.
 *
 * @package AntispamBee\Comparison
 */

require_once __DIR__ . '/../lib/snapshot-format.php';

$opts = [
	'host'     => '127.0.0.1',
	'port'     => '3306',
	'user'     => 'root',
	'pass-env' => 'DB_PASS',
	'db'       => '',
	'prefix'   => 'wp_',
	'salt-file' => '',
	'meta'     => '{}',
	'out'      => '',
];

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( ! preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) || ! array_key_exists( $m[1], $opts ) ) {
		fwrite( STDERR, "Unknown or malformed argument: $arg\n" );
		exit( 2 );
	}
	$opts[ $m[1] ] = $m[2];
}

if ( '' === $opts['db'] || '' === $opts['out'] ) {
	fwrite( STDERR, "--db and --out are required.\n" );
	exit( 2 );
}

$meta = json_decode( $opts['meta'], true );
if ( ! is_array( $meta ) ) {
	fwrite( STDERR, "--meta must be a JSON object.\n" );
	exit( 2 );
}

$salt = '';
if ( '' !== $opts['salt-file'] ) {
	$raw = file_get_contents( $opts['salt-file'] );
	if ( false === $raw ) {
		fwrite( STDERR, "Cannot read salt file: {$opts['salt-file']}\n" );
		exit( 2 );
	}
	$salt = trim( $raw );
}
$meta['salt_check'] = asb_snapshot_salt_check( $salt );

mysqli_report( MYSQLI_REPORT_OFF );
$db = @mysqli_connect(
	$opts['host'],
	$opts['user'],
	(string) getenv( $opts['pass-env'] ),
	$opts['db'],
	(int) $opts['port']
);
if ( ! $db ) {
	fwrite( STDERR, 'Cannot connect to the site DB: ' . mysqli_connect_error() . "\n" );
	exit( 1 );
}
mysqli_set_charset( $db, 'utf8mb4' );

$comments    = $opts['prefix'] . 'comments';
$commentmeta = $opts['prefix'] . 'commentmeta';

$sql = "SELECT oc.meta_value AS original_comment_id,
			c.comment_approved AS status,
			r.meta_value AS reason
		FROM `{$comments}` AS c
		INNER JOIN `{$commentmeta}` AS oc
			ON c.comment_ID = oc.comment_id AND oc.meta_key = 'original_comment_id'
		LEFT JOIN `{$commentmeta}` AS r
			ON c.comment_ID = r.comment_id AND r.meta_key = 'antispam_bee_reason'";

// Unbuffered: a full-corpus pass is a few hundred thousand rows.
$result = mysqli_query( $db, $sql, MYSQLI_USE_RESULT );
if ( ! $result ) {
	fwrite( STDERR, 'Query failed: ' . mysqli_error( $db ) . "\n" );
	exit( 1 );
}

$verdicts = [];
while ( $row = mysqli_fetch_assoc( $result ) ) {
	$pid = asb_snapshot_pid( $salt, (string) $row['original_comment_id'] );

	// A pseudonym collision would silently drop a row and skew the "only in one
	// side" counts, so treat it as fatal rather than losing a comparison row.
	if ( isset( $verdicts[ $pid ] ) ) {
		fwrite( STDERR, "Pseudonym collision on '$pid' — refusing to write a lossy snapshot.\n" );
		exit( 1 );
	}

	$verdicts[ $pid ] = [
		'status' => (string) $row['status'],
		'reason' => null !== $row['reason'] ? (string) $row['reason'] : null,
	];
}
mysqli_free_result( $result );
mysqli_close( $db );

if ( ! $verdicts ) {
	fwrite( STDERR, "No classified comments found in `{$opts['db']}`.`{$opts['prefix']}comments`.\n" );
	exit( 1 );
}

$manifest = asb_snapshot_write( $opts['out'], $verdicts, $meta );

fwrite(
	STDERR,
	sprintf(
		"Wrote %s: %d rows, %s%s.\n",
		$opts['out'],
		$manifest['rows'],
		'none' === $manifest['salt_check'] ? 'raw ids' : 'pseudonymous',
		$manifest['publishable'] ? '' : ', NOT publishable'
	)
);

echo json_encode( $manifest, JSON_UNESCAPED_SLASHES ) . "\n";
