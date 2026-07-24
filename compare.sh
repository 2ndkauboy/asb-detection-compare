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

case "$ENV" in
	ddev)
		db_env="ASB_OLD_DB_HOST=db ASB_OLD_DB_NAME=db ASB_OLD_DB_USER=db ASB_OLD_DB_PASS=db \
ASB_NEW_DB_HOST=db ASB_NEW_DB_NAME=db ASB_NEW_DB_USER=db ASB_NEW_DB_PASS=db"
		cp "$SCRIPT_DIR/lib/antispam-plugin-stat-comparer.php" "$SCRIPT_DIR/ddev/asb-comparer.php"
		trap 'rm -f "$SCRIPT_DIR/ddev/asb-comparer.php"' EXIT
		( cd "$SCRIPT_DIR/ddev" && ddev exec bash -c "export $db_env $common_env; php /var/www/html/asb-comparer.php" )
		;;
	wp-env)
		db_env="ASB_OLD_DB_HOST=mysql ASB_OLD_DB_NAME=wordpress ASB_OLD_DB_USER=root ASB_OLD_DB_PASS=password \
ASB_NEW_DB_HOST=mysql ASB_NEW_DB_NAME=wordpress ASB_NEW_DB_USER=root ASB_NEW_DB_PASS=password"
		( cd "$SCRIPT_DIR/wp-env" && wp-env run cli bash -c "export $db_env $common_env; php wp-content/asb-lib/antispam-plugin-stat-comparer.php" )
		;;
	*)
		echo "Unknown env '$ENV' (expected: ddev | wp-env)." >&2
		exit 1
		;;
esac
