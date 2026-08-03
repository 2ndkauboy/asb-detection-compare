<?php
/**
 * Compare Antispam Bee classification results between the old (2.x) and new
 * (3.x) sites after driver.php has replayed the corpus into each.
 *
 * Each site stores, per replayed comment, its `comment_approved` status and an
 * `antispam_bee_reason` meta, plus an `original_comment_id` meta linking back to
 * the source corpus comment (written by the `save-original-comment-id`
 * mu-plugin). This script connects to both site databases, joins on
 * `original_comment_id`, and reports where the two versions disagree.
 *
 * It needs only DB access (no WordPress), but the DB hostnames
 * (`ddev-asb-2-db`, `ddev-asb-3-db`) only resolve on the shared DDEV network,
 * so run it from inside a running DDEV container, e.g. from the theme-tests
 * project root:
 *
 *     ddev exec php asb-comparison/antispam-plugin-stat-comparer.php
 *
 * Connection details are overridable via ASB_OLD_* / ASB_NEW_* env variables.
 *
 * Either side can instead be read from a snapshot file (ASB_OLD_FILE /
 * ASB_NEW_FILE, see `lib/snapshot-format.php`), which is how CI compares a
 * freshly classified HEAD against a baseline published as a release asset.
 *
 * Point ASB_CORPUS_DB_NAME (plus ASB_CORPUS_DB_HOST/_PORT/_USER/_PASS,
 * ASB_CORPUS_PREFIX and ASB_CORPUS_SALT_FILE) at the corpus to label each flip
 * with how that comment was classified historically. Without it the comparison
 * is unchanged, it just cannot tell a win from a possible regression:
 * `manually` => spam means the new build catches something a human had removed
 * by hand, while `unflagged` => spam means nothing had flagged it before.
 *
 * @package AntispamBee\Comparison
 */

require_once __DIR__ . '/snapshot-format.php';

class AntispamPluginStatComparer {

	/**
	 * Site connection descriptors, keyed by version label.
	 *
	 * @var array<string, array<string, string|int>>
	 */
	private $sites;

	/**
	 * Snapshot manifests of the sides that were read from a file, keyed by
	 * version label. Sides read from a database have no manifest.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $manifests = [];

	/**
	 * Corpus connection descriptor, used to label flips with the historical
	 * classification. Empty `name` means the feature is off.
	 *
	 * @var array<string, mixed>
	 */
	private $corpus = [];

	/**
	 * The label used for a comment nothing flagged at the time.
	 *
	 * Deliberately not "ham": the corpus records what the site's own Antispam Bee
	 * did, not ground truth. `manually` means a human decided it was spam, which
	 * is strong evidence — the absence of any reason only means nothing flagged
	 * the comment, which is not the same as it being legitimate.
	 */
	private const ORIGIN_UNFLAGGED = 'unflagged';

	public function __construct() {
		$this->sites = [
			'old' => [
				'file'   => getenv( 'ASB_OLD_FILE' ) ?: '',
				'host'   => getenv( 'ASB_OLD_DB_HOST' ) ?: 'ddev-asb-2-db',
				'port'   => (int) ( getenv( 'ASB_OLD_DB_PORT' ) ?: 3306 ),
				'name'   => getenv( 'ASB_OLD_DB_NAME' ) ?: 'db',
				'user'   => getenv( 'ASB_OLD_DB_USER' ) ?: 'db',
				'pass'   => getenv( 'ASB_OLD_DB_PASS' ) ?: 'db',
				'prefix' => getenv( 'ASB_OLD_PREFIX' ) ?: 'wp_',
			],
			'new' => [
				'file'   => getenv( 'ASB_NEW_FILE' ) ?: '',
				'host'   => getenv( 'ASB_NEW_DB_HOST' ) ?: 'ddev-asb-3-db',
				'port'   => (int) ( getenv( 'ASB_NEW_DB_PORT' ) ?: 3306 ),
				'name'   => getenv( 'ASB_NEW_DB_NAME' ) ?: 'db',
				'user'   => getenv( 'ASB_NEW_DB_USER' ) ?: 'db',
				'pass'   => getenv( 'ASB_NEW_DB_PASS' ) ?: 'db',
				'prefix' => getenv( 'ASB_NEW_PREFIX' ) ?: 'wp_',
			],
		];

		// Optional: the corpus itself, so a flip can be labelled with how the
		// comment was classified historically. Without it the comparison still
		// works, it just cannot tell the two kinds of flip apart.
		$this->corpus = [
			'name'      => getenv( 'ASB_CORPUS_DB_NAME' ) ?: '',
			'host'      => getenv( 'ASB_CORPUS_DB_HOST' ) ?: '127.0.0.1',
			'port'      => (int) ( getenv( 'ASB_CORPUS_DB_PORT' ) ?: 3306 ),
			'user'      => getenv( 'ASB_CORPUS_DB_USER' ) ?: 'root',
			'pass'      => getenv( 'ASB_CORPUS_DB_PASS' ) ?: '',
			'prefix'    => getenv( 'ASB_CORPUS_PREFIX' ) ?: 'wp_',
			'salt_file' => getenv( 'ASB_CORPUS_SALT_FILE' ) ?: '',
		];
	}

	/**
	 * Load the verdicts for one side, from a snapshot file when one is
	 * configured for it, otherwise from its database.
	 *
	 * The choice is per side, so a baseline restored from a release asset can be
	 * compared against a HEAD that is still sitting in the live tables.
	 *
	 * @param string $which 'old' or 'new'.
	 * @return array<string, array{status: string, reason: ?string}>
	 */
	private function loadSide( string $which ): array {
		$site = $this->sites[ $which ];

		if ( '' !== $site['file'] ) {
			$snapshot                  = asb_snapshot_read( $site['file'] );
			$this->manifests[ $which ] = $snapshot['manifest'];

			return $snapshot['verdicts'];
		}

		return $this->loadVerdicts( $site );
	}

	/**
	 * Fail unless the two sides can meaningfully be compared at all.
	 *
	 * Two snapshots salted differently have disjoint id spaces, so the
	 * intersection is empty and every count comes out zero — which would be
	 * reported as "0 flips" and pass a gating check. The same shape results from
	 * a half-failed classification pass. Both must be loud failures.
	 *
	 * @param array<string, mixed> $old Old side verdicts.
	 * @param array<string, mixed> $new New side verdicts.
	 * @param int                  $compared Size of the intersection.
	 */
	private function assertComparable( array $old, array $new, int $compared ): void {
		$old_check = $this->manifests['old']['salt_check'] ?? null;
		$new_check = $this->manifests['new']['salt_check'] ?? null;
		if ( null !== $old_check && null !== $new_check && $old_check !== $new_check ) {
			fwrite(
				STDERR,
				"The two snapshots were pseudonymised with different salts, so their comment ids\n"
				. "cannot be joined. This usually means they were produced from different corpora,\n"
				. "or with a different snapshot-salt setting. Refusing to report a meaningless\n"
				. "comparison.\n"
			);
			exit( 1 );
		}

		$ratio    = (float) ( getenv( 'ASB_MIN_OVERLAP_RATIO' ) ?: 0.99 );
		$smaller  = min( count( $old ), count( $new ) );
		$required = (int) floor( $ratio * $smaller );
		if ( 0 === $compared || $compared < $required ) {
			fwrite(
				STDERR,
				sprintf(
					"Only %d of %d comments are present on both sides (%.1f%% required).\n"
					. "The two sides do not describe the same corpus, or one classification pass\n"
					. "is incomplete. Refusing to report a comparison over a partial overlap.\n",
					$compared,
					$smaller,
					$ratio * 100
				)
			);
			exit( 1 );
		}
	}

	/**
	 * Load the verdicts for one site from its database, keyed by original
	 * (source) comment id.
	 *
	 * @param array $site Site connection descriptor.
	 * @return array<string, array{status: string, reason: ?string}>
	 */
	private function loadVerdicts( array $site ): array {
		mysqli_report( MYSQLI_REPORT_OFF );
		$db = @mysqli_connect( $site['host'], $site['user'], $site['pass'], $site['name'], $site['port'] );
		if ( ! $db ) {
			fwrite( STDERR, sprintf( "Cannot connect to %s:%d/%s: %s\n", $site['host'], $site['port'], $site['name'], mysqli_connect_error() ) );
			exit( 1 );
		}
		mysqli_set_charset( $db, 'utf8mb4' );

		$comments    = $site['prefix'] . 'comments';
		$commentmeta = $site['prefix'] . 'commentmeta';

		$sql = "SELECT oc.meta_value AS original_comment_id,
					c.comment_approved AS status,
					r.meta_value AS reason
				FROM `{$comments}` AS c
				INNER JOIN `{$commentmeta}` AS oc
					ON c.comment_ID = oc.comment_id AND oc.meta_key = 'original_comment_id'
				LEFT JOIN `{$commentmeta}` AS r
					ON c.comment_ID = r.comment_id AND r.meta_key = 'antispam_bee_reason'";

		$result = mysqli_query( $db, $sql );
		if ( ! $result ) {
			fwrite( STDERR, 'Query failed: ' . mysqli_error( $db ) . "\n" );
			exit( 1 );
		}

		$verdicts = [];
		while ( $row = mysqli_fetch_assoc( $result ) ) {
			$verdicts[ (string) $row['original_comment_id'] ] = [
				'status' => (string) $row['status'],
				'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
			];
		}
		mysqli_free_result( $result );
		mysqli_close( $db );

		return $verdicts;
	}

	/**
	 * Normalise a comment status to a simple spam / not-spam flag.
	 *
	 * @param string $status The `comment_approved` value.
	 */
	private function isSpam( string $status ): bool {
		return 'spam' === $status;
	}

	/**
	 * Map every corpus comment to how it was classified historically.
	 *
	 * This is what makes a flip readable. `manually` => spam means a human had
	 * removed that comment by hand and the new build now catches it automatically
	 * — a win. `unflagged` => spam means nothing had flagged it, which needs a
	 * look. Both render identically without this.
	 *
	 * Keyed to match the verdict keys: snapshots are keyed by pseudonym, so the
	 * corpus ids are pseudonymised with the same salt. A database-to-database
	 * comparison has no salt and uses the raw ids.
	 *
	 * Read from the corpus, never from a snapshot: adding a field to the snapshot
	 * format would change its schema and invalidate every published asset, and it
	 * is unnecessary — the corpus is present in the run that does the comparing.
	 *
	 * @return array<string, string> Verdict key => historical label.
	 */
	private function loadOrigins(): array {
		if ( '' === $this->corpus['name'] ) {
			return [];
		}

		$salt = '';
		if ( '' !== $this->corpus['salt_file'] ) {
			if ( ! is_readable( $this->corpus['salt_file'] ) ) {
				fwrite( STDERR, sprintf( "Cannot read the salt file %s; flips will not be labelled.\n", $this->corpus['salt_file'] ) );

				return [];
			}
			$salt = trim( (string) file_get_contents( $this->corpus['salt_file'] ) );
		}

		mysqli_report( MYSQLI_REPORT_OFF );
		$db = @mysqli_connect(
			$this->corpus['host'],
			$this->corpus['user'],
			$this->corpus['pass'],
			$this->corpus['name'],
			$this->corpus['port']
		);
		if ( ! $db ) {
			fwrite( STDERR, sprintf( "Cannot connect to the corpus DB %s; flips will not be labelled.\n", $this->corpus['name'] ) );

			return [];
		}
		mysqli_set_charset( $db, 'utf8mb4' );

		$comments    = $this->corpus['prefix'] . 'comments';
		$commentmeta = $this->corpus['prefix'] . 'commentmeta';

		$sql = "SELECT c.comment_ID AS id, r.meta_value AS reason
				FROM `{$comments}` AS c
				LEFT JOIN `{$commentmeta}` AS r
					ON c.comment_ID = r.comment_id AND r.meta_key = 'antispam_bee_reason'";

		$result = mysqli_query( $db, $sql );
		if ( ! $result ) {
			fwrite( STDERR, 'Corpus query failed: ' . mysqli_error( $db ) . "\n" );
			mysqli_close( $db );

			return [];
		}

		$origins = [];
		while ( $row = mysqli_fetch_assoc( $result ) ) {
			$id     = (string) $row['id'];
			$reason = $row['reason'] !== null && '' !== $row['reason']
				? (string) $row['reason']
				: self::ORIGIN_UNFLAGGED;

			$origins[ asb_snapshot_pid( $salt, $id ) ] = $reason;
		}
		mysqli_free_result( $result );
		mysqli_close( $db );

		return $origins;
	}

	/**
	 * Compare both sites and collect statistics + differences.
	 *
	 * @return array
	 */
	public function compareResults(): array {
		$old = $this->loadSide( 'old' );
		$new = $this->loadSide( 'new' );

		$common = array_intersect_key( $old, $new );

		$this->assertComparable( $old, $new, count( $common ) );

		// Spam/ham flips are the real signal: the two versions disagree on whether
		// the reaction is spam. Reason-only differences are mostly the v2->v3
		// reason-label vocabulary change, so we aggregate those into a transition
		// table instead of listing every comment.
		$flips              = [];
		$flip_origins       = [];
		$reason_transitions = [];
		$reason_diffs       = 0;
		foreach ( $common as $id => $old_verdict ) {
			$new_verdict = $new[ $id ];

			if ( $this->isSpam( $old_verdict['status'] ) !== $this->isSpam( $new_verdict['status'] ) ) {
				$flips[] = [
					'comment_id' => $id,
					'old'        => $old_verdict,
					'new'        => $new_verdict,
					'origin'     => null,
				];
				continue;
			}

			if ( $old_verdict['reason'] !== $new_verdict['reason'] ) {
				++$reason_diffs;
				$key = ( $old_verdict['reason'] ?? '(none)' ) . "\t" . ( $new_verdict['reason'] ?? '(none)' );

				$reason_transitions[ $key ] = ( $reason_transitions[ $key ] ?? 0 ) + 1;
			}
		}

		// Only now, and only if there is anything to label: on the full corpus the
		// origin map is 300k+ rows and most runs flip nothing at all, so building
		// it unconditionally would be a full corpus scan for no output.
		$origins = $flips ? $this->loadOrigins() : [];
		foreach ( $flips as &$flip ) {
			$flip['origin'] = $origins[ $flip['comment_id'] ] ?? null;
			if ( null === $flip['origin'] ) {
				continue;
			}

			// Grouped by direction, because the same origin means opposite things
			// depending on which way the decision moved.
			$direction            = $this->isSpam( $flip['new']['status'] ) ? '=> spam' : '=> not spam';
			$key                  = $flip['origin'] . "\t" . $direction;
			$flip_origins[ $key ] = ( $flip_origins[ $key ] ?? 0 ) + 1;
		}
		unset( $flip );

		arsort( $reason_transitions );
		arsort( $flip_origins );

	return [
			'old_total'           => count( $old ),
			'new_total'           => count( $new ),
			'compared'            => count( $common ),
			'only_in_old'         => count( array_diff_key( $old, $new ) ),
			'only_in_new'         => count( array_diff_key( $new, $old ) ),
			'spam_flips'          => count( $flips ),
			'reason_diffs'        => $reason_diffs,
			'flips'               => $flips,
			'reason_transitions'  => $reason_transitions,
			'flip_origins'        => $flip_origins,
			'origins_available'   => (bool) $origins,
		];
	}

	/**
	 * Render the comparison detail as Markdown tables, for a job summary or a
	 * pull-request comment.
	 *
	 * Only the comparison itself — the caller knows the run context (which
	 * corpus, which commits, whether a published baseline was reused) and puts
	 * its own summary above this.
	 *
	 * @param array $stats Result of {@see compareResults()}.
	 */
	public function generateMarkdown( array $stats ): string {
		$old = getenv( 'ASB_OLD_LABEL' ) ?: 'old';
		$new = getenv( 'ASB_NEW_LABEL' ) ?: 'new';
		$max = (int) ( getenv( 'ASB_MAX_FLIPS_LISTED' ) ?: 50 );

		$md   = [];
		$md[] = '### Spam/ham flips';
		$md[] = '';
		if ( ! $stats['flips'] ) {
			$md[] = '_None — the two builds agree on every decision._';
		} else {
			$listed  = $max > 0 ? array_slice( $stats['flips'], 0, $max ) : $stats['flips'];
			$origins = ! empty( $stats['origins_available'] );

			if ( $origins ) {
				$md[] = sprintf( '| comment | historically | %s | %s |', $this->mdEscape( $old ), $this->mdEscape( $new ) );
				$md[] = '|---|---|---|---|';
			} else {
				$md[] = sprintf( '| comment | %s | %s |', $this->mdEscape( $old ), $this->mdEscape( $new ) );
				$md[] = '|---|---|---|';
			}
			foreach ( $listed as $flip ) {
				$md[] = sprintf(
					$origins ? '| `%s` | %s | %s (%s) | %s (%s) |' : '| `%s` |%s %s (%s) | %s (%s) |',
					$this->mdEscape( (string) $flip['comment_id'] ),
					$origins ? $this->mdCode( $flip['origin'] ) : '',
					$this->mdEscape( $flip['old']['status'] ),
					$this->mdCode( $flip['old']['reason'] ),
					$this->mdEscape( $flip['new']['status'] ),
					$this->mdCode( $flip['new']['reason'] )
				);
			}
			if ( count( $listed ) < count( $stats['flips'] ) ) {
				$md[] = '';
				$md[] = sprintf( '_… and %d more._', count( $stats['flips'] ) - count( $listed ) );
			}

			if ( $stats['flip_origins'] ) {
				$md[] = '';
				$md[] = '| count | historically | became |';
				$md[] = '|---:|---|---|';
				foreach ( $stats['flip_origins'] as $key => $count ) {
					list( $origin, $direction ) = explode( "\t", $key );
					$md[]                       = sprintf(
						'| %d | %s | %s |',
						$count,
						$this->mdCode( $origin ),
						$this->mdEscape( ltrim( $direction, '=> ' ) )
					);
				}
				$md[] = '';
				$md[] = '<sub>`manually` is a human decision, so `manually` → spam means the new build '
					. 'catches something that previously needed manual moderation. `'
					. self::ORIGIN_UNFLAGGED . '` only means nothing flagged the comment at the time — '
					. 'it is not proof the comment was legitimate, so those want a look rather than being '
					. 'assumed to be regressions.</sub>';
			}
		}

		$md[] = '';
		$md[] = '### Reason changes with the same decision';
		$md[] = '';
		if ( ! $stats['reason_transitions'] ) {
			$md[] = '_None._';
		} else {
			$md[] = sprintf( '| count | %s | %s |', $this->mdEscape( $old ), $this->mdEscape( $new ) );
			$md[] = '|---:|---|---|';
			foreach ( $stats['reason_transitions'] as $key => $count ) {
				list( $old_reason, $new_reason ) = explode( "\t", $key );
				$md[]                            = sprintf(
					'| %d | %s | %s |',
					$count,
					$this->mdCode( '(none)' === $old_reason ? null : $old_reason ),
					$this->mdCode( '(none)' === $new_reason ? null : $new_reason )
				);
			}
		}

		return implode( "\n", $md ) . "\n";
	}

	/**
	 * Neutralise Markdown table syntax in a value.
	 *
	 * @param string $value Raw value.
	 */
	private function mdEscape( string $value ): string {
		return str_replace( [ '|', "\n" ], [ '\|', ' ' ], $value );
	}

	/**
	 * Render a nullable reason as inline code, or a dash when absent.
	 *
	 * @param string|null $value Reason, or null.
	 */
	private function mdCode( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		return '`' . $this->mdEscape( $value ) . '`';
	}

	/**
	 * Render a human-readable report.
	 *
	 * @param array $stats Result of {@see compareResults()}.
	 */
	public function generateReport( array $stats ): string {
		$flip_pct   = $stats['compared'] > 0 ? ( $stats['spam_flips'] / $stats['compared'] ) * 100 : 0;

		// Cosmetic side labels; override with ASB_OLD_LABEL / ASB_NEW_LABEL, e.g.
		// "2.11.12" and "3.0.0-beta.1", or "beta.1" and "beta.2".
		$old = getenv( 'ASB_OLD_LABEL' ) ?: 'old';
		$new = getenv( 'ASB_NEW_LABEL' ) ?: 'new';

		$report   = [];
		$report[] = sprintf( '=== Antispam Bee: %s vs %s ===', $old, $new );
		$report[] = sprintf( '%s verdicts: %d | %s verdicts: %d', $old, $stats['old_total'], $new, $stats['new_total'] );
		$report[] = 'Compared (present in both): ' . $stats['compared'];
		$report[] = sprintf( 'Only in %s / only in %s: %d / %d', $old, $new, $stats['only_in_old'], $stats['only_in_new'] );
		$report[] = sprintf(
			'Spam/ham flips: %d (%.3f%%)   |   reason-only differences: %d',
			$stats['spam_flips'],
			$flip_pct,
			$stats['reason_diffs']
		);
		$report[] = '';

		// The signal: comments the two versions classify differently as spam/ham.
		$report[] = '--- Spam/ham flips (the two versions disagree on the decision) ---';
		if ( ! $stats['flips'] ) {
			$report[] = '  (none)';
		}

		// A real regression over a large corpus can produce tens of thousands of
		// flips, and a GitHub job summary is capped at 1 MiB — an uncapped list
		// would cost us the whole report. The count above is the actionable part.
		$max    = (int) ( getenv( 'ASB_MAX_FLIPS_LISTED' ) ?: 50 );
		$listed = $max > 0 ? array_slice( $stats['flips'], 0, $max ) : $stats['flips'];
		foreach ( $listed as $flip ) {
			$report[] = sprintf(
				'  #%-16s  %s%s=%s (%s)   %s=%s (%s)',
				$flip['comment_id'],
				null !== ( $flip['origin'] ?? null ) ? sprintf( '[was: %s]  ', $flip['origin'] ) : '',
				$old,
				$flip['old']['status'],
				$flip['old']['reason'] ?? '-',
				$new,
				$flip['new']['status'],
				$flip['new']['reason'] ?? '-'
			);
		}
		if ( count( $listed ) < count( $stats['flips'] ) ) {
			$report[] = sprintf( '  … and %d more (raise ASB_MAX_FLIPS_LISTED to see them)', count( $stats['flips'] ) - count( $listed ) );
		}
		$report[] = '';

		// How the flipped comments were classified historically. `manually` is a
		// human decision; the absence of a reason only means nothing flagged the
		// comment, not that it was legitimate.
		if ( ! empty( $stats['flip_origins'] ) ) {
			$report[] = '--- Flips by historical classification ---';
			foreach ( $stats['flip_origins'] as $key => $count ) {
				list( $origin, $direction ) = explode( "\t", $key );
				$report[]                   = sprintf( '  %6d  %-14s %s', $count, $origin, $direction );
			}
			$report[] = '';
		}

		// The noise: same decision, different reason label. Aggregated so a reason
		// vocabulary change does not drown out anything meaningful.
		$report[] = sprintf( '--- Reason changes with SAME decision (%s reason => %s reason) ---', $old, $new );
		if ( ! $stats['reason_transitions'] ) {
			$report[] = '  (none)';
		}
		foreach ( $stats['reason_transitions'] as $key => $count ) {
			list( $old_reason, $new_reason ) = explode( "\t", $key );
			$report[] = sprintf( '  %6d  %s => %s', $count, $old_reason, $new_reason );
		}

		return implode( "\n", $report ) . "\n";
	}
}

// Run when invoked directly on the CLI.
if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$comparer = new AntispamPluginStatComparer();
	$stats    = $comparer->compareResults();
	echo $comparer->generateReport( $stats );

	// Markdown detail tables, for a job summary or a pull-request comment.
	$md_file = getenv( 'ASB_REPORT_MD' ) ?: '';
	if ( '' !== $md_file ) {
		file_put_contents( $md_file, $comparer->generateMarkdown( $stats ) );
	}

	// Machine-readable counts for callers that would otherwise have to scrape the
	// prose above with a regex.
	$stats_file = getenv( 'ASB_STATS_FILE' ) ?: '';
	if ( '' !== $stats_file ) {
		// Reshape the origin aggregate: its in-memory keys are tab-joined pairs,
		// which would be unusable as JSON object keys.
		$origins = [];
		foreach ( $stats['flip_origins'] as $key => $count ) {
			list( $origin, $direction ) = explode( "\t", $key );
			$origins[]                  = [
				'historically' => $origin,
				'became'       => ltrim( $direction, '=> ' ),
				'count'        => $count,
			];
		}
		$stats['flip_origins'] = $origins;

		unset( $stats['flips'], $stats['reason_transitions'] );
		file_put_contents( $stats_file, json_encode( $stats, JSON_UNESCAPED_SLASHES ) . "\n" );
	}
}
