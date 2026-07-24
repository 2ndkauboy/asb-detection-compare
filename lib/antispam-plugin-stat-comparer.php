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
 * @package AntispamBee\Comparison
 */

class AntispamPluginStatComparer {

	/**
	 * Site connection descriptors, keyed by version label.
	 *
	 * @var array<string, array<string, string|int>>
	 */
	private $sites;

	public function __construct() {
		$this->sites = [
			'old' => [
				'host'   => getenv( 'ASB_OLD_DB_HOST' ) ?: 'ddev-asb-2-db',
				'port'   => (int) ( getenv( 'ASB_OLD_DB_PORT' ) ?: 3306 ),
				'name'   => getenv( 'ASB_OLD_DB_NAME' ) ?: 'db',
				'user'   => getenv( 'ASB_OLD_DB_USER' ) ?: 'db',
				'pass'   => getenv( 'ASB_OLD_DB_PASS' ) ?: 'db',
				'prefix' => getenv( 'ASB_OLD_PREFIX' ) ?: 'wp_',
			],
			'new' => [
				'host'   => getenv( 'ASB_NEW_DB_HOST' ) ?: 'ddev-asb-3-db',
				'port'   => (int) ( getenv( 'ASB_NEW_DB_PORT' ) ?: 3306 ),
				'name'   => getenv( 'ASB_NEW_DB_NAME' ) ?: 'db',
				'user'   => getenv( 'ASB_NEW_DB_USER' ) ?: 'db',
				'pass'   => getenv( 'ASB_NEW_DB_PASS' ) ?: 'db',
				'prefix' => getenv( 'ASB_NEW_PREFIX' ) ?: 'wp_',
			],
		];
	}

	/**
	 * Load the verdicts for one site, keyed by original (source) comment id.
	 *
	 * @param array $site Site connection descriptor.
	 * @return array<int, array{status: string, reason: ?string}>
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
			$verdicts[ (int) $row['original_comment_id'] ] = [
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
	 * Compare both sites and collect statistics + differences.
	 *
	 * @return array
	 */
	public function compareResults(): array {
		$old = $this->loadVerdicts( $this->sites['old'] );
		$new = $this->loadVerdicts( $this->sites['new'] );

		$common = array_intersect_key( $old, $new );

		// Spam/ham flips are the real signal: the two versions disagree on whether
		// the reaction is spam. Reason-only differences are mostly the v2->v3
		// reason-label vocabulary change, so we aggregate those into a transition
		// table instead of listing every comment.
		$flips              = [];
		$reason_transitions = [];
		$reason_diffs       = 0;
		foreach ( $common as $id => $old_verdict ) {
			$new_verdict = $new[ $id ];

			if ( $this->isSpam( $old_verdict['status'] ) !== $this->isSpam( $new_verdict['status'] ) ) {
				$flips[] = [
					'comment_id' => $id,
					'old'        => $old_verdict,
					'new'        => $new_verdict,
				];
				continue;
			}

			if ( $old_verdict['reason'] !== $new_verdict['reason'] ) {
				++$reason_diffs;
				$key = ( $old_verdict['reason'] ?? '(none)' ) . "\t" . ( $new_verdict['reason'] ?? '(none)' );

				$reason_transitions[ $key ] = ( $reason_transitions[ $key ] ?? 0 ) + 1;
			}
		}

		arsort( $reason_transitions );

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
		];
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
		foreach ( $stats['flips'] as $flip ) {
			$report[] = sprintf(
				'  #%-10d  %s=%s (%s)   %s=%s (%s)',
				$flip['comment_id'],
				$old,
				$flip['old']['status'],
				$flip['old']['reason'] ?? '-',
				$new,
				$flip['new']['status'],
				$flip['new']['reason'] ?? '-'
			);
		}
		$report[] = '';

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
	echo $comparer->generateReport( $comparer->compareResults() );
}
