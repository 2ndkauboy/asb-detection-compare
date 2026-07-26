<?php
/**
 * Decide whether a downloaded baseline snapshot may be used for this run.
 *
 * A snapshot published months ago describes a classification produced under a
 * particular set of conditions. Some of those conditions change the verdicts and
 * some do not, so they are checked differently:
 *
 *   HARD — the plugin commit, the corpus, the option fixture, the classification
 *          harness and the shard mode. All folded into one `hard_fp`. A mismatch
 *          means the snapshot describes a different experiment; it is discarded
 *          and the baseline is re-classified.
 *   SOFT — WordPress core version, PHP minor, worker count. These *should* not
 *          change a verdict (cluster sharding makes the result worker-count
 *          independent), but they are recorded so a drift can be reported rather
 *          than silently assumed away.
 *
 * Exit codes: 0 = usable, 3 = unusable (caller should re-classify), 2 = usage.
 * Soft-mismatch warnings are printed to STDOUT so the caller can put them in the
 * job summary.
 *
 * Usage:
 *
 *     php scripts/verify-snapshot.php --file=snap.tsv.gz --hard-fp=… \
 *       --salt-check=… --soft='{"wp_version":"6.8.1","php_minor":"8.2","workers":"2"}'
 *
 * @package AntispamBee\Comparison
 */

require_once __DIR__ . '/../lib/snapshot-format.php';

$opts = [
	'file'       => '',
	'hard-fp'    => '',
	'salt-check' => '',
	'soft'       => '{}',
];

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( ! preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) || ! array_key_exists( $m[1], $opts ) ) {
		fwrite( STDERR, "Unknown or malformed argument: $arg\n" );
		exit( 2 );
	}
	$opts[ $m[1] ] = $m[2];
}

if ( '' === $opts['file'] || '' === $opts['hard-fp'] ) {
	fwrite( STDERR, "--file and --hard-fp are required.\n" );
	exit( 2 );
}

/**
 * Report why the snapshot cannot be used and exit with the "re-classify" code.
 *
 * @param string $reason Human-readable reason.
 */
function unusable( string $reason ): void {
	echo "Baseline snapshot not usable: $reason\n";
	exit( 3 );
}

$manifest = asb_snapshot_manifest( $opts['file'] );
if ( null === $manifest ) {
	unusable( 'the file is not a readable snapshot.' );
}

if ( (int) ( $manifest['schema'] ?? 0 ) !== ASB_SNAPSHOT_SCHEMA ) {
	unusable( sprintf( 'schema %s, this runner speaks %d.', $manifest['schema'] ?? '?', ASB_SNAPSHOT_SCHEMA ) );
}

if ( ( $manifest['hard_fp'] ?? '' ) !== $opts['hard-fp'] ) {
	unusable(
		sprintf(
			"it was produced under different conditions (fingerprint %s, this run needs %s).\n"
			. '  The plugin commit, corpus, option fixture, harness or shard mode differ.',
			substr( (string) ( $manifest['hard_fp'] ?? '?' ), 0, 12 ),
			substr( $opts['hard-fp'], 0, 12 )
		)
	);
}

if ( '' !== $opts['salt-check'] && ( $manifest['salt_check'] ?? '' ) !== $opts['salt-check'] ) {
	unusable( 'it was pseudonymised with a different salt, so its ids cannot be joined.' );
}

// Soft components: usable, but say so out loud.
$soft     = json_decode( $opts['soft'], true );
$recorded = $manifest['soft'] ?? [];
if ( is_array( $soft ) ) {
	foreach ( $soft as $key => $want ) {
		$have = $recorded[ $key ] ?? null;
		if ( null !== $have && (string) $have !== (string) $want ) {
			printf(
				"Baseline snapshot drift: %s was %s when the snapshot was made, this run uses %s.\n",
				$key,
				(string) $have,
				(string) $want
			);
		}
	}
}

printf(
	"Baseline snapshot accepted: %d rows, built %s from %s.\n",
	(int) ( $manifest['rows'] ?? 0 ),
	(string) ( $manifest['created_at'] ?? 'unknown date' ),
	substr( (string) ( $manifest['commit_sha'] ?? 'unknown commit' ), 0, 12 )
);
exit( 0 );
