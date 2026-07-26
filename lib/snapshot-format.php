<?php
/**
 * Shared format helpers for pseudonymous verdict snapshots.
 *
 * A snapshot is the minimal record of one classification pass: for every
 * replayed comment, the three fields the comparer actually reads — the source
 * comment id, the resulting `comment_approved` status, and the
 * `antispam_bee_reason` meta. Nothing else. No content, no author, no e-mail,
 * no IP, no dates.
 *
 * Snapshots are published as assets on public GitHub releases so a later run can
 * use one as its baseline instead of re-classifying the whole corpus. That makes
 * the privacy properties load-bearing:
 *
 *   THE PSEUDONYM IS DERIVED ONLY FROM THE CORPUS-LOCAL ROW ID. Never from an
 *   e-mail address, IP, URL, or any other personal identifier. A row id is a
 *   surrogate key that means nothing outside the dump it came from, so a
 *   pseudonym stays meaningless even if the salt leaks — there is no identifier
 *   an outsider holds that could be joined against it. Pseudonymising a personal
 *   identifier instead would make salt secrecy the only protection, and a
 *   truncated hash over a guessable domain is brute-forceable. Do not do it.
 *
 * The salt is derived from the corpus fingerprint so that two runs over the same
 * corpus produce joinable pseudonyms without any shared secret, while two
 * different corpora can never be compared by accident.
 *
 * File layout (gzipped, LF-separated):
 *
 *     #asb-snapshot	1
 *     #meta	{"schema":1,"rows":332168,"salt_check":"…",…}
 *     0af31c9b2d5e7a41	spam	asb-regexp%2Casb-bbcode
 *     1b7c3f90ab2d4e15	1	\N
 *
 * Rows are sorted by pseudonym, so the body bytes are deterministic and can be
 * hashed to assert that two passes produced an identical classification.
 *
 * @package AntispamBee\Comparison
 */

/**
 * Snapshot format version. Bump when the layout or any derivation changes;
 * a snapshot whose schema differs is never used as a baseline.
 */
const ASB_SNAPSHOT_SCHEMA = 1;

/**
 * Pseudonymise a corpus-local comment id.
 *
 * An empty salt disables pseudonymisation and passes the id through unchanged —
 * that is the local-harness mode, where readable ids are the whole point.
 * Snapshots produced that way are marked unpublishable.
 *
 * @param string $salt Salt from {@see asb_snapshot_salt()}, or '' for raw ids.
 * @param string $id   Source comment id.
 * @return string Pseudonym (16 hex chars), or the id unchanged.
 */
function asb_snapshot_pid( string $salt, string $id ): string {
	if ( '' === $salt ) {
		return $id;
	}

	return substr( hash_hmac( 'sha256', 'id:' . $id, $salt ), 0, 16 );
}

/**
 * Derive the pseudonymisation salt for a corpus.
 *
 * @param string $corpus_fp Corpus fingerprint (see the runner's corpus_fp query).
 * @param string $extra     Optional extra secret, mixed in when configured.
 * @return string Salt.
 */
function asb_snapshot_salt( string $corpus_fp, string $extra = '' ): string {
	return hash_hmac( 'sha256', 'asb-snapshot-pseudonym-v1', $corpus_fp . "\0" . $extra );
}

/**
 * A public token proving two snapshots were salted identically.
 *
 * Published in the manifest. Without it, a salt mismatch would make the two id
 * spaces disjoint, and the comparer would cheerfully report "0 flips" from an
 * empty intersection instead of failing.
 *
 * @param string $salt Salt, or '' for an unsalted snapshot.
 * @return string Check token, or 'none' when unsalted.
 */
function asb_snapshot_salt_check( string $salt ): string {
	if ( '' === $salt ) {
		return 'none';
	}

	return substr( hash_hmac( 'sha256', 'salt-check-v1', $salt ), 0, 16 );
}

/**
 * The corpus token that appears in the public asset filename.
 *
 * Deliberately a different derivation from {@see asb_snapshot_salt()}: the
 * filename is public, so it must not be usable to re-derive the salt.
 *
 * @param string $corpus_fp Corpus fingerprint.
 * @return string Eight hex chars.
 */
function asb_snapshot_corpus_token( string $corpus_fp ): string {
	return substr( hash_hmac( 'sha256', 'asb-asset-name-v1', $corpus_fp ), 0, 8 );
}

/**
 * Write a snapshot file.
 *
 * @param string                                        $path     Output path (gzipped).
 * @param array<string, array{status: string, reason: ?string}> $verdicts Keyed by pseudonym.
 * @param array<string, mixed>                          $meta     Caller-supplied manifest fields.
 * @return array<string, mixed> The complete manifest as written.
 */
function asb_snapshot_write( string $path, array $verdicts, array $meta ): array {
	ksort( $verdicts, SORT_STRING );

	$body = '';
	foreach ( $verdicts as $pid => $verdict ) {
		$body .= $pid . "\t" . rawurlencode( $verdict['status'] ) . "\t"
			. ( null === $verdict['reason'] ? '\N' : rawurlencode( $verdict['reason'] ) ) . "\n";
	}

	// A snapshot may only become someone else's baseline when it is both
	// pseudonymous and reproducible. Cluster sharding is what makes the
	// order-dependent rules (DbSpam, ApprovedEmail) deterministic; a MOD-sharded
	// run can differ between passes and must never be published.
	$salt_check  = $meta['salt_check'] ?? 'none';
	$publishable = 'none' !== $salt_check && 'cluster' === ( $meta['shard_mode'] ?? '' );

	$manifest = array_merge(
		$meta,
		[
			'schema'      => ASB_SNAPSHOT_SCHEMA,
			'rows'        => count( $verdicts ),
			'body_sha256' => hash( 'sha256', $body ),
			'publishable' => $publishable,
			'created_at'  => gmdate( 'c' ),
		]
	);

	$json = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
	if ( false === $json || str_contains( $json, "\n" ) ) {
		fwrite( STDERR, "Refusing to write a snapshot with an unencodable manifest.\n" );
		exit( 1 );
	}

	$gz = gzopen( $path, 'wb9' );
	if ( ! $gz ) {
		fwrite( STDERR, "Cannot open snapshot for writing: $path\n" );
		exit( 1 );
	}
	gzwrite( $gz, "#asb-snapshot\t" . ASB_SNAPSHOT_SCHEMA . "\n" );
	gzwrite( $gz, "#meta\t" . $json . "\n" );
	gzwrite( $gz, $body );
	gzclose( $gz );

	return $manifest;
}

/**
 * Read only a snapshot's manifest.
 *
 * Cheap enough to run before deciding whether a downloaded asset is usable at
 * all, without parsing a few hundred thousand rows first.
 *
 * @param string $path Snapshot path.
 * @return array<string, mixed>|null Manifest, or null when the file is not a
 *                                   readable snapshot.
 */
function asb_snapshot_manifest( string $path ): ?array {
	$gz = gzopen( $path, 'rb' );
	if ( ! $gz ) {
		return null;
	}

	$magic     = rtrim( (string) gzgets( $gz ), "\n" );
	$meta_line = rtrim( (string) gzgets( $gz ), "\n" );
	gzclose( $gz );

	if ( ! str_starts_with( $magic, "#asb-snapshot\t" ) || ! str_starts_with( $meta_line, "#meta\t" ) ) {
		return null;
	}

	$manifest = json_decode( substr( $meta_line, strlen( "#meta\t" ) ), true );

	return is_array( $manifest ) ? $manifest : null;
}

/**
 * Read a snapshot file.
 *
 * Verifies the magic line, the schema, the recorded row count and the body
 * hash — a truncated or edited asset must fail loudly rather than silently
 * shrink the comparison.
 *
 * @param string $path Snapshot path (gzipped, or plain for debugging).
 * @return array{manifest: array<string, mixed>, verdicts: array<string, array{status: string, reason: ?string}>}
 */
function asb_snapshot_read( string $path ): array {
	$gz = gzopen( $path, 'rb' );
	if ( ! $gz ) {
		fwrite( STDERR, "Cannot read snapshot: $path\n" );
		exit( 1 );
	}

	$magic = rtrim( (string) gzgets( $gz ), "\n" );
	if ( ! str_starts_with( $magic, "#asb-snapshot\t" ) ) {
		fwrite( STDERR, "Not a snapshot file (bad magic line): $path\n" );
		exit( 1 );
	}
	$schema = (int) substr( $magic, strlen( "#asb-snapshot\t" ) );
	if ( ASB_SNAPSHOT_SCHEMA !== $schema ) {
		fwrite( STDERR, sprintf( "Snapshot schema %d is not supported (expected %d): %s\n", $schema, ASB_SNAPSHOT_SCHEMA, $path ) );
		exit( 1 );
	}

	$meta_line = rtrim( (string) gzgets( $gz ), "\n" );
	if ( ! str_starts_with( $meta_line, "#meta\t" ) ) {
		fwrite( STDERR, "Snapshot is missing its manifest line: $path\n" );
		exit( 1 );
	}
	$manifest = json_decode( substr( $meta_line, strlen( "#meta\t" ) ), true );
	if ( ! is_array( $manifest ) ) {
		fwrite( STDERR, "Snapshot manifest is not valid JSON: $path\n" );
		exit( 1 );
	}

	$verdicts = [];
	$body     = '';
	while ( false !== ( $line = gzgets( $gz ) ) ) {
		if ( '' === rtrim( $line, "\n" ) ) {
			continue;
		}
		$body .= $line;
		$col = explode( "\t", rtrim( $line, "\n" ), 3 );
		if ( 3 !== count( $col ) ) {
			fwrite( STDERR, "Malformed snapshot row in $path: " . rtrim( $line, "\n" ) . "\n" );
			exit( 1 );
		}
		$verdicts[ $col[0] ] = [
			'status' => rawurldecode( $col[1] ),
			'reason' => '\N' === $col[2] ? null : rawurldecode( $col[2] ),
		];
	}
	gzclose( $gz );

	if ( isset( $manifest['rows'] ) && (int) $manifest['rows'] !== count( $verdicts ) ) {
		fwrite(
			STDERR,
			sprintf(
				"Snapshot %s is truncated: manifest says %d rows, file has %d.\n",
				$path,
				(int) $manifest['rows'],
				count( $verdicts )
			)
		);
		exit( 1 );
	}
	if ( isset( $manifest['body_sha256'] ) && hash( 'sha256', $body ) !== $manifest['body_sha256'] ) {
		fwrite( STDERR, "Snapshot $path failed its body checksum.\n" );
		exit( 1 );
	}

	return [
		'manifest' => $manifest,
		'verdicts' => $verdicts,
	];
}
