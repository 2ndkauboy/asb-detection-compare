#!/usr/bin/env bash
#
# DDEV variant: activate a cloned Antispam Bee version, classify the corpus
# in-process (driver.php), and snapshot the results under a label so two
# versions can be compared with ../compare.sh.
#
# Usage:
#   ./ddev/run-version.sh <label> [limit]
#
#   label   Which cloned version to run (a directory under ../versions/), also
#           used as the snapshot label. Letters, digits, underscore.
#   limit   Cap comments processed PER WORKER (0 = whole corpus; default 0).
#           Used by the benchmark for a fixed-size slice.
#
# Env:
#   WORKERS   Parallel classifier processes (MOD-sharded, default 1). Use e.g.
#             WORKERS=8 for a full-corpus run; keep 1 for a deterministic bench.
set -euo pipefail

LABEL="${1:?usage: ./ddev/run-version.sh <label> [limit]}"
LIMIT="${2:-0}"
WORKERS="${WORKERS:-1}"

if ! [[ "$LABEL" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "Invalid label '$LABEL' (allowed: letters, digits, underscore)." >&2
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BENCH_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DOCROOT="wp"
VERSION_DIR="$BENCH_DIR/versions/$LABEL"
PLUGIN_DIR="$SCRIPT_DIR/$DOCROOT/wp-content/plugins/antispam-bee"

if [ ! -d "$VERSION_DIR" ]; then
	echo "Version '$LABEL' not found at $VERSION_DIR. Run ../clone-asb.sh first." >&2
	exit 1
fi

cd "$SCRIPT_DIR"

echo "== [$LABEL] install plugin build =="
rm -rf "$PLUGIN_DIR"
mkdir -p "$PLUGIN_DIR"
cp -a "$VERSION_DIR/." "$PLUGIN_DIR/"
rm -rf "$PLUGIN_DIR/.git"
ddev wp plugin activate antispam-bee

echo "== [$LABEL] apply Antispam Bee config (keep spam, Country/Gravatar off) =="
if [ -d "$VERSION_DIR/src" ]; then
	ddev wp option update antispam_bee_options --format=json \
		< "$BENCH_DIR/config/antispam_bee_options.3x.json"
else
	echo "  ! '$LABEL' looks like a 2.x build; no 2.x option fixture is bundled." >&2
	echo "    Add config/antispam_bee.2x.json and extend this script before comparing 2.x." >&2
fi

echo "== [$LABEL] truncate live tables =="
ddev mysql -e "TRUNCATE db.wp_comments; TRUNCATE db.wp_commentmeta; UPDATE db.wp_posts SET comment_count=0 WHERE ID=1;"

echo "== [$LABEL] classify corpus ($WORKERS worker(s), limit/worker=$LIMIT, DbSpam off) =="
cp "$BENCH_DIR/lib/driver.php" "$SCRIPT_DIR/asb-driver.php"
trap 'rm -f "$SCRIPT_DIR/asb-driver.php"' EXIT
# Launch each worker as its own host-backgrounded `ddev exec`. Note: `ddev exec`
# runs the remote command under `set -u`, so a container-side shell loop var
# would trip "unbound variable" — we substitute the worker index on the host
# instead and keep no shell variables inside the remote command.
driver_env="export ASB_SRC_DB_HOST=db ASB_SRC_DB_PORT=3306 ASB_SRC_DB_NAME=corpus ASB_SRC_DB_USER=root ASB_SRC_DB_PASS=root ASB_SRC_PREFIX=wp_ ASB_SHARD_TABLE= ASB_DISABLE_DB_SPAM=1 ASB_LIMIT=$LIMIT"
pids=()
for ((i = 0; i < WORKERS; i++)); do
	ddev exec bash -c "$driver_env; wp --path=/var/www/html/$DOCROOT eval-file /var/www/html/asb-driver.php $i $WORKERS" &
	pids+=("$!")
done
classify_status=0
for p in "${pids[@]}"; do wait "$p" || classify_status=1; done
if [ "$classify_status" -ne 0 ]; then echo "!! a classify worker failed" >&2; exit 1; fi

echo "== [$LABEL] snapshot results -> wp_${LABEL}_comments / wp_${LABEL}_commentmeta =="
ddev mysql -e "
	DROP TABLE IF EXISTS db.wp_${LABEL}_comments;
	DROP TABLE IF EXISTS db.wp_${LABEL}_commentmeta;
	CREATE TABLE db.wp_${LABEL}_comments LIKE db.wp_comments;
	INSERT INTO db.wp_${LABEL}_comments SELECT * FROM db.wp_comments;
	CREATE TABLE db.wp_${LABEL}_commentmeta LIKE db.wp_commentmeta;
	INSERT INTO db.wp_${LABEL}_commentmeta SELECT * FROM db.wp_commentmeta;
"

count="$(ddev mysql -N -e "SELECT COUNT(*) FROM db.wp_${LABEL}_comments" 2>/dev/null | tr -d '[:space:]')"
echo "== [$LABEL] done: ${count:-?} comments snapshotted. Compare with:  ../compare.sh <other> $LABEL ddev =="
