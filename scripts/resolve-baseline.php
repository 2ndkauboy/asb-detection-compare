<?php
/**
 * Resolve the baseline tag to compare a version-preparation branch against.
 *
 * Given a branch name like `prepare-3.0.0-beta.2` or `chore/prepare-2.11.13`,
 * pick the tag the prepared version should be compared to:
 *
 *   - Prepared version is a PRERELEASE (…-beta.N / …-rc.N): baseline is the
 *     highest existing PRERELEASE tag of the same X.Y.Z core that is lower than
 *     the prepared version (e.g. 3.0.0-beta.2 -> 3.0.0-beta.1). This is the
 *     "compare against the latest 3.0.0 beta/RC" case. If none exists yet (the
 *     first prerelease of that core), it falls back to the highest STABLE tag
 *     below it.
 *   - Prepared version is STABLE (e.g. 2.11.13): baseline is the highest STABLE
 *     tag below it (e.g. 2.11.12) — "compare against the latest released
 *     version".
 *
 * Comparison uses PHP's version_compare(), which orders prereleases correctly
 * (2.11.12 < 3.0.0-beta.1 < 3.0.0-beta.2 < 3.0.0-rc.1 < 3.0.0).
 *
 * Usage:
 *   php resolve-baseline.php <branch-name> [tags-file]
 *
 *   branch-name  The prepare branch, e.g. "chore/prepare-3.0.0-beta.2".
 *   tags-file    Optional newline-separated list of tags (for testing). When
 *                omitted, `git tag` in the current repo is used.
 *
 * Prints the chosen baseline tag (original tag name) to stdout. Exits non-zero
 * with a message on stderr when the branch is not a prepare branch or no
 * suitable baseline exists.
 */

function fail(string $msg, int $code = 1): void {
	fwrite( STDERR, $msg . "\n" );
	exit( $code );
}

$branch = $argv[1] ?? '';
if ( '' === $branch ) {
	fail( 'usage: php resolve-baseline.php <branch-name> [tags-file]', 2 );
}

// Extract the prepared version: strip an optional "chore/" (or any) path
// prefix, then the required "prepare-" segment.
$leaf = preg_replace( '#^.*/#', '', $branch );      // chore/prepare-x -> prepare-x
if ( ! preg_match( '/^prepare-(.+)$/', $leaf, $m ) ) {
	fail( "Branch '$branch' is not a prepare-* branch; nothing to compare.", 3 );
}
$prepared = $m[1];

// Normalise a tag/version to a comparable string (strip a leading "v").
$normalize = static function ( string $v ): string {
	return ltrim( $v, 'vV' );
};
// The X.Y.Z core (everything before the first "-"). A version is a prerelease
// when it carries a "-suffix", i.e. str_contains( $v, '-' ).
$core = static function ( string $v ): string {
	return explode( '-', $v, 2 )[0];
};

$prepared_norm = $normalize( $prepared );
$prepared_pre  = str_contains( $prepared_norm, '-' );
$prepared_core = $core( $prepared_norm );

// Load tags.
if ( isset( $argv[2] ) ) {
	$raw = file_get_contents( $argv[2] );
	if ( false === $raw ) {
		fail( "Cannot read tags file: $argv[2]", 2 );
	}
} else {
	$raw = shell_exec( 'git tag 2>/dev/null' ) ?? '';
}
$tags = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );

$pick_max = static function ( array $candidates ) use ( $normalize ): ?string {
	$best = null;
	foreach ( $candidates as $tag ) {
		if ( null === $best || version_compare( $normalize( $tag ), $normalize( $best ), '>' ) ) {
			$best = $tag;
		}
	}
	return $best;
};

$lower_than_prepared = static function ( string $tag ) use ( $normalize, $prepared_norm ): bool {
	return version_compare( $normalize( $tag ), $prepared_norm, '<' );
};

if ( $prepared_pre ) {
	// Prereleases of the same X.Y.Z core, below the prepared version.
	$same_core_pre = array_filter(
		$tags,
		static function ( $t ) use ( $normalize, $core, $prepared_core, $lower_than_prepared ) {
			$n = $normalize( $t );
			return str_contains( $n, '-' ) && $core( $n ) === $prepared_core && $lower_than_prepared( $t );
		}
	);
	$baseline = $pick_max( $same_core_pre );

	if ( null === $baseline ) {
		// First prerelease of this core: fall back to the latest stable below it.
		$stable = array_filter(
			$tags,
			static function ( $t ) use ( $normalize, $lower_than_prepared ) {
				return ! str_contains( $normalize( $t ), '-' ) && $lower_than_prepared( $t );
			}
		);
		$baseline = $pick_max( $stable );
		if ( null !== $baseline ) {
			fwrite( STDERR, "No prior $prepared_core prerelease; falling back to latest stable: $baseline\n" );
		}
	}
} else {
	// Stable target: latest stable tag below it.
	$stable = array_filter(
		$tags,
		static function ( $t ) use ( $normalize, $lower_than_prepared ) {
			return ! str_contains( $normalize( $t ), '-' ) && $lower_than_prepared( $t );
		}
	);
	$baseline = $pick_max( $stable );
}

if ( null === $baseline ) {
	fail( "No suitable baseline tag found below '$prepared'.", 4 );
}

echo $baseline, "\n";
