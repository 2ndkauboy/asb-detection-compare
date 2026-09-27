<?php
/**
 * Pick the snapshot a release was originally judged by.
 *
 * `verify-snapshot.php` decides whether a snapshot may stand in for classifying
 * the baseline again, and only an exact match of every hard condition may. That
 * is the right test for reuse, but the wrong one for evidence: a snapshot taken
 * with an earlier harness still records how that release classified the corpus
 * when the harness of its time modelled it. Comparing HEAD against that original
 * answers whether any decision changed compared to how the release was judged,
 * and comparing it against the current-harness baseline shows whether a harness
 * change moved verdicts on its own.
 *
 * So this accepts any snapshot for the right commit, corpus, salt, shard mode and
 * option fixture, whatever harness produced it, and prints the oldest one: the
 * release's original judgement. Prints nothing when there is none. The option
 * fixture decides which rules run, so a snapshot taken with another one is a
 * different experiment, not an earlier measurement of the same one. Snapshots
 * from before `options_fp` was recorded carry no such field and are accepted.
 *
 * Usage:
 *
 *     php scripts/pick-released-snapshot.php --commit=<sha> --salt-check=<check> \
 *       --token=<corpus token> --shard-mode=<mode> --options-fp=<fp> \
 *       ( --dir=<download dir> | --file=<snapshot> )
 *
 * Exit codes: 0 = done (a path on STDOUT, or nothing), 2 = usage.
 *
 * @package AntispamBee\Comparison
 */

require_once __DIR__ . '/../lib/snapshot-format.php';

$opts = [
	'dir'        => '',
	'file'       => '',
	'commit'     => '',
	'salt-check' => '',
	'token'      => '',
	'shard-mode' => '',
	'options-fp' => '',
];

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( ! preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) || ! array_key_exists( $m[1], $opts ) ) {
		fwrite( STDERR, "Unknown or malformed argument: $arg\n" );
		exit( 2 );
	}
	$opts[ $m[1] ] = $m[2];
}

if ( ( '' === $opts['dir'] ) === ( '' === $opts['file'] ) || '' === $opts['commit'] || '' === $opts['salt-check'] ) {
	fwrite( STDERR, "Exactly one of --dir and --file, plus --commit and --salt-check, are required.\n" );
	exit( 2 );
}

$files = '' !== $opts['file'] ? [ $opts['file'] ] : ( glob( rtrim( $opts['dir'], '/' ) . '/*.tsv.gz' ) ?: [] );

$picked      = null;
$picked_date = null;
foreach ( $files as $file ) {
	$manifest = asb_snapshot_manifest( $file );
	$name     = basename( $file );

	if ( null === $manifest || (int) ( $manifest['schema'] ?? 0 ) !== ASB_SNAPSHOT_SCHEMA ) {
		fwrite( STDERR, "As-released candidate $name: not a readable snapshot of this schema.\n" );
		continue;
	}
	if ( ( $manifest['commit_sha'] ?? '' ) !== $opts['commit'] ) {
		fwrite( STDERR, "As-released candidate $name: describes another commit.\n" );
		continue;
	}
	if ( ( $manifest['salt_check'] ?? '' ) !== $opts['salt-check'] ) {
		fwrite( STDERR, "As-released candidate $name: different salt, its ids cannot be joined.\n" );
		continue;
	}
	if ( '' !== $opts['token'] && ( $manifest['corpus_token'] ?? '' ) !== $opts['token'] ) {
		fwrite( STDERR, "As-released candidate $name: another corpus.\n" );
		continue;
	}
	if ( '' !== $opts['shard-mode'] && ( $manifest['shard_mode'] ?? '' ) !== $opts['shard-mode'] ) {
		fwrite( STDERR, "As-released candidate $name: another shard mode.\n" );
		continue;
	}
	if ( '' !== $opts['options-fp'] && isset( $manifest['options_fp'] ) && $manifest['options_fp'] !== $opts['options-fp'] ) {
		fwrite( STDERR, "As-released candidate $name: another option fixture.\n" );
		continue;
	}

	$date = (string) ( $manifest['created_at'] ?? '' );
	if ( null === $picked || strcmp( $date, (string) $picked_date ) < 0 ) {
		$picked      = $file;
		$picked_date = $date;
	}
}

if ( null !== $picked ) {
	echo $picked;
}
exit( 0 );
