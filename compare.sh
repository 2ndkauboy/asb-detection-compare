#!/usr/bin/env bash
#
# Compare two labelled snapshots (produced by <env>/run-version.sh) that live in
# the same site database, joining on original_comment_id. Prints the spam/ham
# flips and the reason-transition table. Environment-agnostic: runs the
# WordPress-independent comparer inside the chosen environment's container.
#
# Usage:
#   ./compare.sh <old-label> <new-label> <env>
#
#   old-label / new-label   Snapshot labels, e.g. beta1 norm.
#   env                     ddev | wp-env  (which environment holds the snapshots).
set -euo pipefail

OLD="${1:?usage: ./compare.sh <old-label> <new-label> <env>}"
NEW="${2:?usage: ./compare.sh <old-label> <new-label> <env>}"
ENV="${3:?usage: ./compare.sh <old-label> <new-label> <env>}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

common_env="ASB_OLD_PREFIX=wp_${OLD}_ ASB_NEW_PREFIX=wp_${NEW}_ ASB_OLD_LABEL=$OLD ASB_NEW_LABEL=$NEW"

# Covering index for the comparer's per-side load query. Newer snapshots already
# carry it (added by run-version.sh), but ensure it here so snapshots made before
# that change don't stall the diff for minutes on cold random meta_value reads.
cov_cols="(comment_id, meta_key, meta_value(64))"

case "$ENV" in
	ddev)
		db_env="ASB_OLD_DB_HOST=db ASB_OLD_DB_NAME=db ASB_OLD_DB_USER=db ASB_OLD_DB_PASS=db \
ASB_NEW_DB_HOST=db ASB_NEW_DB_NAME=db ASB_NEW_DB_USER=db ASB_NEW_DB_PASS=db"
		( cd "$SCRIPT_DIR/ddev" && ddev mysql -e "
			ALTER TABLE db.wp_${OLD}_commentmeta ADD INDEX IF NOT EXISTS idx_asb_cov $cov_cols;
			ALTER TABLE db.wp_${NEW}_commentmeta ADD INDEX IF NOT EXISTS idx_asb_cov $cov_cols;" )
		cp "$SCRIPT_DIR/lib/antispam-plugin-stat-comparer.php" "$SCRIPT_DIR/ddev/asb-comparer.php"
		trap 'rm -f "$SCRIPT_DIR/ddev/asb-comparer.php"' EXIT
		( cd "$SCRIPT_DIR/ddev" && ddev exec bash -c "export $db_env $common_env; php /var/www/html/asb-comparer.php" )
		;;
	wp-env)
		db_env="ASB_OLD_DB_HOST=mysql ASB_OLD_DB_NAME=wordpress ASB_OLD_DB_USER=root ASB_OLD_DB_PASS=password \
ASB_NEW_DB_HOST=mysql ASB_NEW_DB_NAME=wordpress ASB_NEW_DB_USER=root ASB_NEW_DB_PASS=password"
		( cd "$SCRIPT_DIR/wp-env" && wp-env run cli wp db query "
			ALTER TABLE wp_${OLD}_commentmeta ADD INDEX IF NOT EXISTS idx_asb_cov $cov_cols;
			ALTER TABLE wp_${NEW}_commentmeta ADD INDEX IF NOT EXISTS idx_asb_cov $cov_cols;" )
		( cd "$SCRIPT_DIR/wp-env" && wp-env run cli bash -c "export $db_env $common_env; php wp-content/asb-lib/antispam-plugin-stat-comparer.php" )
		;;
	*)
		echo "Unknown env '$ENV' (expected: ddev | wp-env)." >&2
		exit 1
		;;
esac
