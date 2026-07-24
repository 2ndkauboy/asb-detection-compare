#!/usr/bin/env bash
#
# wp-env variant: activate a cloned Antispam Bee version, classify the corpus
# in-process (driver.php), and snapshot the results under a label so two
# versions can be compared with ../compare.sh.
#
# Usage:
#   ./wp-env/run-version.sh <label> [limit]
#
#   label   Which cloned version to run (a directory under ../versions/), also
#           used as the snapshot label. Letters, digits, underscore.
#   limit   Cap comments processed PER WORKER (0 = whole corpus; default 0).
#
# Env:
#   WORKERS   Parallel classifier processes (MOD-sharded, default 1). Use e.g.
#             WORKERS=8 for a full-corpus run; keep 1 for a deterministic bench.
set -euo pipefail

LABEL="${1:?usage: ./wp-env/run-version.sh <label> [limit]}"
LIMIT="${2:-0}"
WORKERS="${WORKERS:-1}"

if ! [[ "$LABEL" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "Invalid label '$LABEL' (allowed: letters, digits, underscore)." >&2
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BENCH_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION_DIR="$BENCH_DIR/versions/$LABEL"

if [ ! -d "$VERSION_DIR" ]; then
	echo "Version '$LABEL' not found at $VERSION_DIR. Run ../clone-asb.sh first." >&2
	exit 1
fi

cd "$SCRIPT_DIR"

echo "== [$LABEL] relax MariaDB durability for the run (matches DDEV) =="
# wp-env ships stock MariaDB (flush_log_at_trx_commit=1 -> fsync per commit);
# DDEV pre-tunes this to 2. Online GLOBAL (root); resets on `wp-env stop`.
# (Buffer-pool resizing is intentionally NOT done here: the online resize holds
# internal latches and stalled the insert-heavy replay more than it helped.)
wp-env run cli wp db query "SET GLOBAL innodb_flush_log_at_trx_commit=2;" || true

echo "== [$LABEL] install plugin build (into ./active-plugin) =="
# Clear the CONTENTS only — active-plugin is a wp-env bind-mount target, so
# removing the directory itself would break the mount inside the container.
mkdir -p active-plugin
find active-plugin -mindepth 1 -delete
cp -a "$VERSION_DIR/." active-plugin/
rm -rf active-plugin/.git
wp-env run cli wp plugin activate antispam-bee

echo "== [$LABEL] apply Antispam Bee config (keep spam, Country/Gravatar off) =="
if [ -d "$VERSION_DIR/src" ]; then
	wp-env run cli bash -c "wp option update antispam_bee_options --format=json < wp-content/asb-config/antispam_bee_options.3x.json"
else
	echo "  ! '$LABEL' looks like a 2.x build; no 2.x option fixture is bundled." >&2
	echo "    Add config/antispam_bee.2x.json and extend this script before comparing 2.x." >&2
fi

echo "== [$LABEL] truncate live tables =="
wp-env run cli wp db query "TRUNCATE wp_comments; TRUNCATE wp_commentmeta; UPDATE wp_posts SET comment_count=0 WHERE ID=1;"

echo "== [$LABEL] classify corpus ($WORKERS worker(s), limit/worker=$LIMIT, DbSpam off) =="
wp-env run cli bash -c "export ASB_SRC_DB_HOST=mysql ASB_SRC_DB_PORT=3306 ASB_SRC_DB_NAME=corpus ASB_SRC_DB_USER=root ASB_SRC_DB_PASS=password ASB_SRC_PREFIX=wp_ ASB_SHARD_TABLE= ASB_DISABLE_DB_SPAM=1 ASB_LIMIT=$LIMIT; for i in \$(seq 0 $((WORKERS-1))); do wp eval-file wp-content/asb-lib/driver.php \$i $WORKERS & done; wait"

echo "== [$LABEL] snapshot results -> wp_${LABEL}_comments / wp_${LABEL}_commentmeta =="
wp-env run cli wp db query "
	DROP TABLE IF EXISTS wp_${LABEL}_comments;
	DROP TABLE IF EXISTS wp_${LABEL}_commentmeta;
	CREATE TABLE wp_${LABEL}_comments LIKE wp_comments;
	INSERT INTO wp_${LABEL}_comments SELECT * FROM wp_comments;
	CREATE TABLE wp_${LABEL}_commentmeta LIKE wp_commentmeta;
	INSERT INTO wp_${LABEL}_commentmeta SELECT * FROM wp_commentmeta;
"

count="$(wp-env run cli wp db query "SELECT COUNT(*) FROM wp_${LABEL}_comments" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
echo "== [$LABEL] done: ${count:-?} comments snapshotted. Compare with:  ../compare.sh <other> $LABEL wp-env =="
