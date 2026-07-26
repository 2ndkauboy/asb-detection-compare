<?php
/**
 * Build the bundled comparison fixture (fixtures/corpus.sql) from a real corpus,
 * stratified by original antispam_bee_reason + comment_type, with PII removed.
 *
 * The fixture is committed to a PUBLIC repo, so it must contain no personal
 * data: author name, e-mail and IP are replaced with synthetic values for every
 * row, and the content of *ham* rows (real people's comments) is replaced with
 * synthetic ham. Spam content and URLs are kept — that is bot text the rules key
 * on, and it's what makes the fixture worth having. The original
 * antispam_bee_reason is preserved as comment meta because the driver uses the
 * `css` reason to replay a comment through the visible decoy field.
 *
 * This script is the transform half; it reads a base64-TSV of sampled rows on
 * STDIN and writes the fixture SQL to STDOUT. Produce the input with (up to 10
 * rows per reason+type; MariaDB/MySQL 8+, window functions required):
 *
 *   Q="WITH ranked AS (
 *        SELECT c.comment_ID, c.comment_type, c.comment_agent,
 *               c.comment_content, c.comment_author_url,
 *               COALESCE(NULLIF(cm.meta_value,''),'') AS reason,
 *               ROW_NUMBER() OVER (
 *                 PARTITION BY COALESCE(NULLIF(cm.meta_value,''),'(ham)'), c.comment_type
 *                 ORDER BY c.comment_ID) AS rn
 *        FROM wp_comments c
 *        LEFT JOIN wp_commentmeta cm
 *          ON cm.comment_id=c.comment_ID AND cm.meta_key='antispam_bee_reason')
 *      SELECT comment_ID,
 *             CASE WHEN reason='' THEN 1 ELSE 0 END AS is_ham,
 *             comment_type, reason,
 *             REPLACE(REPLACE(TO_BASE64(COALESCE(comment_content,'')),'\n',''),'\r','') AS c64,
 *             REPLACE(REPLACE(TO_BASE64(COALESCE(comment_author_url,'')),'\n',''),'\r','') AS u64,
 *             REPLACE(REPLACE(TO_BASE64(COALESCE(comment_agent,'')),'\n',''),'\r','')   AS a64
 *      FROM ranked WHERE rn <= 10 ORDER BY reason, comment_type, comment_ID;"
 *   mariadb --batch --skip-column-names <db> -e "$Q" | php scripts/build-fixture.php > fixtures/corpus.sql
 *
 * Columns per input line (tab-separated): comment_ID, is_ham, comment_type,
 * reason, base64(content), base64(author_url), base64(agent).
 */

// Synthetic ham bodies (rotated) used to replace real ham content.
$ham = [
	'Thanks, this walkthrough finally made the setup click for me.',
	'Does this also work on a multisite install? Great write-up either way.',
	'I hit the same issue last week; clearing the object cache fixed it.',
	'Bookmarked — looking forward to the follow-up on caching.',
	'Small typo in step three, but otherwise perfect. Cheers!',
	'We rolled this out to staging today and it just works. Thank you.',
	'Could you add a short section on backups? Otherwise very clear.',
	'The diagram really helped me understand the flow.',
	'Exactly what I needed, thanks for taking the time to write it up.',
	'Works on PHP 8.3 for me as well, no changes required.',
];

/** Escape a string for a single-quoted MySQL/MariaDB literal. */
function sql_str( string $s ): string {
	$s = str_replace( "\0", '', $s );
	$s = str_replace( '\\', '\\\\', $s );
	$s = str_replace( "'", "\\'", $s );
	return "'" . $s . "'";
}

// Human-readable description per source reason (why the group is here).
$descriptions = [
	'(ham)'         => 'legitimate comments (content synthesized; envelope anonymized)',
	'css'           => 'honeypot / CSS decoy catch (content replayed via the visible field)',
	'empty'         => 'empty comment body',
	'localdb'       => 'local-DB spam (IP / e-mail / URL seen before)',
	'manually'      => 'manually marked as spam',
	'regexp'        => 'matched a spam regex pattern',
	'title_is_name' => 'linkback title equals the blog name',
];

// Accumulate rows grouped by reason (ham bucketed together), preserving order.
$groups  = []; // key => [ 'rows' => [sql...], 'types' => [type => n] ].
$order   = [];
$meta    = [];
$ham_i   = 0;
$next_id = 1;  // Renumber comment IDs 1..N; the original source IDs are discarded.

while ( ( $line = fgets( STDIN ) ) !== false ) {
	$line = rtrim( $line, "\r\n" );
	if ( '' === $line ) {
		continue;
	}
	$col = explode( "\t", $line );
	if ( count( $col ) < 7 ) {
		continue;
	}
	[ $orig_id, $is_ham, $type, $reason, $c64, $u64, $a64 ] = $col;
	$is_ham = ( '1' === $is_ham );
	$id     = $next_id++; // Renumbered; the original comment_ID is intentionally dropped.

	$content = base64_decode( $c64, true ) ?: '';
	$url     = base64_decode( $u64, true ) ?: '';
	$agent   = base64_decode( $a64, true ) ?: '';

	if ( $is_ham ) {
		$content = $ham[ $ham_i % count( $ham ) ];
		++$ham_i;
		$url = ''; // Ham URLs could be personal sites; drop them.
	}

	// Synthetic, non-personal envelope for every row.
	$author = 'Commenter ' . $id;
	$email  = 'user' . $id . '@example.com';
	$ip     = ( 0 === $id % 2 ? '203.0.113.' : '198.51.100.' ) . ( ( $id % 253 ) + 1 );

	$key = $is_ham ? '(ham)' : $reason;
	if ( ! isset( $groups[ $key ] ) ) {
		$groups[ $key ] = [ 'rows' => [], 'types' => [] ];
		$order[]        = $key;
	}
	$groups[ $key ]['rows'][] = sprintf(
		"\t(%d, %s, %s, %s, %s, %s, %s, %s)",
		$id,
		sql_str( $content ),
		sql_str( $author ),
		sql_str( $email ),
		sql_str( $url ),
		sql_str( $ip ),
		sql_str( $agent ),
		sql_str( $type )
	);
	$groups[ $key ]['types'][ $type ] = ( $groups[ $key ]['types'][ $type ] ?? 0 ) + 1;

	if ( '' !== $reason ) {
		$meta[] = sprintf( "\t(%d, 'antispam_bee_reason', %s)", $id, sql_str( $reason ) );
	}
}

// "comment ×10, trackback ×9" for a group's type counts.
$fmt_types = static function ( array $types ): string {
	$parts = [];
	foreach ( $types as $type => $n ) {
		$parts[] = "$type \xC3\x97$n";
	}
	return implode( ', ', $parts );
};

$generated = 0;
foreach ( $order as $key ) {
	$generated += count( $groups[ $key ]['rows'] );
}
echo "-- Bundled comparison fixture for the asb-detection-compare GitHub Action.\n";
echo "--\n";
echo "-- Auto-generated by scripts/build-fixture.php from a real corpus, stratified\n";
echo "-- by antispam_bee_reason + comment_type (<=10 rows each). PII removed: author,\n";
echo "-- e-mail and IP are synthetic for every row, comment IDs are renumbered from 1\n";
echo "-- (original IDs discarded), and ham content is synthetic; spam content/URLs are\n";
echo "-- kept because the rules act on them. Do not edit by hand.\n";
echo "-- Rows: {$generated}.\n";
echo "--\n";
echo "-- Groups (source antispam_bee_reason; up to 10 rows each):\n";
foreach ( $order as $key ) {
	echo sprintf(
		"--   %-14s %3d  %-22s %s\n",
		$key,
		count( $groups[ $key ]['rows'] ),
		$fmt_types( $groups[ $key ]['types'] ),
		$descriptions[ $key ] ?? ''
	);
}
echo "\n";

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

$cols = "\t(comment_ID, comment_content, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_agent, comment_type)";
foreach ( $order as $key ) {
	$g = $groups[ $key ];
	echo sprintf(
		"-- %s — %d row(s) [%s] — %s\n",
		$key,
		count( $g['rows'] ),
		$fmt_types( $g['types'] ),
		$descriptions[ $key ] ?? ''
	);
	echo "INSERT INTO wp_comments\n$cols\nVALUES\n";
	echo implode( ",\n", $g['rows'] ) . ";\n\n";
}

if ( $meta ) {
	echo "-- Original antispam_bee_reason per comment (drives the driver's css decoy replay).\n";
	echo "INSERT INTO wp_commentmeta (comment_id, meta_key, meta_value)\n";
	echo "VALUES\n";
	echo implode( ",\n", $meta ) . ";\n";
}

fwrite( STDERR, "Generated fixture with {$generated} comments, " . count( $meta ) . " reason meta rows.\n" );
