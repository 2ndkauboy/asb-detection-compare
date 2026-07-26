#!/usr/bin/env bash
#
# CI runner for the asb-detection-compare GitHub Action.
#
# Classifies a bundled comment corpus with two Antispam Bee builds — the current
# checkout (HEAD, the prepared version) and a resolved baseline tag — and diffs
# the spam/ham verdicts. Unlike the local harness it uses NO DDEV/wp-env: just a
# reachable MySQL server, PHP, Composer, WP-CLI and git. Reuses the repo's own
# lib/driver.php, lib/antispam-plugin-stat-comparer.php, mu-plugins/ and the 3.x
# option fixture — no duplicated logic.
#
# Configuration via environment (the composite action fills these from inputs):
#   ASB_ACTION_DIR   Path to this repo (holds lib/, mu-plugins/, config/, fixtures/).
#   ASB_PLUGIN_DIR   Antispam Bee checkout = HEAD/prepared version (default: $GITHUB_WORKSPACE).
#   ASB_BRANCH       Branch name for baseline resolution (default: $GITHUB_REF_NAME).
#   ASB_BASELINE_REF Explicit baseline tag; skips resolution when set.
#   DB_HOST/DB_PORT/DB_ROOT_USER/DB_ROOT_PASS   MySQL connection (root).
#   SITE_DB/CORPUS_DB    Database names (defaults asbcmp_site / asbcmp_corpus).
#   CORPUS_FILE      Corpus dump (default: $ASB_ACTION_DIR/fixtures/corpus.sql).
#   WORKERS          Parallel classifier processes (default 2).
#   FAIL_ON_FLIPS    "true" -> exit 1 when spam/ham flips > 0 (default true).
set -euo pipefail

ASB_ACTION_DIR="${ASB_ACTION_DIR:?ASB_ACTION_DIR is required}"
ASB_PLUGIN_DIR="${ASB_PLUGIN_DIR:-${GITHUB_WORKSPACE:?}}"
ASB_BRANCH="${ASB_BRANCH:-${GITHUB_REF_NAME:-}}"
ASB_BASELINE_REF="${ASB_BASELINE_REF:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_ROOT_USER="${DB_ROOT_USER:-root}"
DB_ROOT_PASS="${DB_ROOT_PASS:-root}"
SITE_DB="${SITE_DB:-asbcmp_site}"
CORPUS_DB="${CORPUS_DB:-asbcmp_corpus}"
CORPUS_FILE="${CORPUS_FILE:-$ASB_ACTION_DIR/fixtures/corpus.sql}"
WORKERS="${WORKERS:-2}"
FAIL_ON_FLIPS="${FAIL_ON_FLIPS:-true}"

log()     { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
summary() { [ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n' "$*" >> "$GITHUB_STEP_SUMMARY" || true; }

WORK="$(mktemp -d)"
WP_DIR="$WORK/wp"
BASE_DIR="$WORK/baseline"
trap 'git -C "$ASB_PLUGIN_DIR" worktree remove --force "$BASE_DIR" 2>/dev/null || true; rm -rf "$WORK"' EXIT

# WP-CLI wrapper (path-scoped, non-interactive, allow running as root in CI).
wp() { command wp --path="$WP_DIR" --allow-root "$@"; }

# ---------------------------------------------------------------------------
# 1. Resolve the baseline tag.
# ---------------------------------------------------------------------------
git config --global --add safe.directory "$ASB_PLUGIN_DIR" 2>/dev/null || true
git -C "$ASB_PLUGIN_DIR" fetch --tags --force --quiet 2>/dev/null || true

if [ -z "$ASB_BASELINE_REF" ]; then
	log "Resolving baseline for branch '$ASB_BRANCH'"
	ASB_BASELINE_REF="$( cd "$ASB_PLUGIN_DIR" && php "$ASB_ACTION_DIR/scripts/resolve-baseline.php" "$ASB_BRANCH" )"
fi
echo "Baseline: $ASB_BASELINE_REF"

# Sanitised labels for snapshot table names (letters/digits/underscore).
sanitize() { echo "$1" | tr -c 'A-Za-z0-9' '_' | sed 's/_\+/_/g; s/^_//; s/_$//'; }
BASE_LABEL="base_$(sanitize "$ASB_BASELINE_REF")"
HEAD_LABEL="head"

# ---------------------------------------------------------------------------
# 2. Materialise both plugin builds + autoloaders.
# ---------------------------------------------------------------------------
log "Checking out baseline '$ASB_BASELINE_REF' into a worktree"
# The baseline may be a tag (resolved from a prepare-* branch) or a branch name
# (e.g. a PR base ref like `v3`). Make sure the commit-ish exists locally, then
# resolve tag > local branch > remote branch.
git -C "$ASB_PLUGIN_DIR" fetch --force --quiet origin \
	"refs/tags/$ASB_BASELINE_REF:refs/tags/$ASB_BASELINE_REF" 2>/dev/null || true
git -C "$ASB_PLUGIN_DIR" fetch --force --quiet origin "$ASB_BASELINE_REF" 2>/dev/null || true
baseline_ish="$ASB_BASELINE_REF"
if ! git -C "$ASB_PLUGIN_DIR" rev-parse --verify --quiet "${baseline_ish}^{commit}" >/dev/null; then
	baseline_ish="origin/$ASB_BASELINE_REF"
fi
git -C "$ASB_PLUGIN_DIR" worktree add --detach --force "$BASE_DIR" "$baseline_ish"

build_autoloader() {
	local dir="$1"
	if [ -f "$dir/composer.json" ] && [ ! -f "$dir/vendor/autoload.php" ]; then
		log "composer install ($dir)"
		( cd "$dir" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
	fi
}
build_autoloader "$ASB_PLUGIN_DIR"
build_autoloader "$BASE_DIR"

# ---------------------------------------------------------------------------
# 3. Wait for MySQL, create databases, import the corpus (all via WP-CLI later;
#    DB creation needs a first connection, so ping with PHP mysqli).
# ---------------------------------------------------------------------------
log "Waiting for MySQL at $DB_HOST:$DB_PORT"
for _ in $(seq 1 60); do
	if php -r 'exit(@mysqli_connect($argv[1],$argv[2],$argv[3],"",(int)$argv[4])?0:1);' \
		"$DB_HOST" "$DB_ROOT_USER" "$DB_ROOT_PASS" "$DB_PORT" 2>/dev/null; then
		break
	fi
	sleep 2
done

# ---------------------------------------------------------------------------
# 4. Install WordPress once (site DB), then create + load the corpus DB.
# ---------------------------------------------------------------------------
log "Installing WordPress"
mkdir -p "$WP_DIR"
wp core download --force
wp config create --force --skip-check \
	--dbname="$SITE_DB" --dbuser="$DB_ROOT_USER" --dbpass="$DB_ROOT_PASS" --dbhost="$DB_HOST:$DB_PORT"
wp db reset --yes 2>/dev/null || wp db create 2>/dev/null || true
wp core install --url="http://localhost" --title="ASB detection compare" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email

log "Installing support mu-plugins"
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cp "$ASB_ACTION_DIR"/mu-plugins/*.php "$WP_DIR/wp-content/mu-plugins/"

# Resolve the raw corpus source — a download from CORPUS_URL, or CORPUS_FILE
# (the bundled fixture by default, or a file the workflow prepared, e.g. a
# decrypted dump). Then normalise to plain SQL, accepting gzip-compressed dumps
# from either source (so a decrypted `corpus.sql.gz` works via corpus-file too).
if [ -n "${CORPUS_URL:-}" ]; then
	log "Downloading corpus from CORPUS_URL"
	raw="$WORK/corpus.download"
	curl -fSL --retry 3 ${CORPUS_AUTH:+-H "Authorization: $CORPUS_AUTH"} -o "$raw" "$CORPUS_URL"
else
	raw="$CORPUS_FILE"
fi
if [ ! -f "$raw" ]; then
	echo "Corpus file not found: $raw" >&2
	exit 1
fi
if gzip -t "$raw" 2>/dev/null; then
	gunzip -c "$raw" > "$WORK/corpus.sql"
	CORPUS_FILE="$WORK/corpus.sql"
else
	CORPUS_FILE="$raw"
fi

log "Creating corpus DB '$CORPUS_DB' + importing $(basename "$CORPUS_FILE")"
wp db query "CREATE DATABASE IF NOT EXISTS \`$CORPUS_DB\`"
{ echo "USE \`$CORPUS_DB\`;"; cat "$CORPUS_FILE"; } | wp db query
corpus_count="$(wp db query "SELECT COUNT(*) FROM \`$CORPUS_DB\`.wp_comments" --skip-column-names)"
echo "Corpus rows: $corpus_count"

# ---------------------------------------------------------------------------
# 5. Classify the corpus with a given plugin build, snapshot under a label.
# ---------------------------------------------------------------------------
PLUGIN_SLOT="$WP_DIR/wp-content/plugins/antispam-bee"
classify() {
	local label="$1" src="$2"
	log "[$label] activate build + classify ($WORKERS workers)"
	rm -rf "$PLUGIN_SLOT"
	mkdir -p "$PLUGIN_SLOT"
	cp -a "$src/." "$PLUGIN_SLOT/"
	rm -rf "$PLUGIN_SLOT/.git"
	wp plugin activate antispam-bee

	if [ -d "$src/src" ]; then
		wp option update antispam_bee_options --format=json < "$ASB_ACTION_DIR/config/antispam_bee_options.3x.json" >/dev/null
	else
		echo "  ! '$label' is not a 3.x build (no src/); no option fixture applied." >&2
	fi

	wp db query "TRUNCATE wp_comments; TRUNCATE wp_commentmeta; UPDATE wp_posts SET comment_count=0 WHERE ID=1;"

	local i pids=()
	for (( i = 0; i < WORKERS; i++ )); do
		( cd "$WP_DIR" && \
			ASB_SRC_DB_HOST="$DB_HOST" ASB_SRC_DB_PORT="$DB_PORT" ASB_SRC_DB_NAME="$CORPUS_DB" \
			ASB_SRC_DB_USER="$DB_ROOT_USER" ASB_SRC_DB_PASS="$DB_ROOT_PASS" ASB_SRC_PREFIX=wp_ \
			ASB_SHARD_TABLE= ASB_DISABLE_DB_SPAM=1 \
			command wp --path="$WP_DIR" --allow-root eval-file "$ASB_ACTION_DIR/lib/driver.php" "$i" "$WORKERS" ) &
		pids+=("$!")
	done
	local status=0 p
	for p in "${pids[@]}"; do wait "$p" || status=1; done
	[ "$status" -eq 0 ] || { echo "!! a classify worker failed for '$label'" >&2; return 1; }

	wp db query "
		DROP TABLE IF EXISTS wp_${label}_comments;
		DROP TABLE IF EXISTS wp_${label}_commentmeta;
		CREATE TABLE wp_${label}_comments LIKE wp_comments;
		INSERT INTO wp_${label}_comments SELECT * FROM wp_comments;
		CREATE TABLE wp_${label}_commentmeta LIKE wp_commentmeta;
		INSERT INTO wp_${label}_commentmeta SELECT * FROM wp_commentmeta;
	"
}

classify "$BASE_LABEL" "$BASE_DIR"
classify "$HEAD_LABEL" "$ASB_PLUGIN_DIR"

# ---------------------------------------------------------------------------
# 6. Compare the two snapshots and report.
# ---------------------------------------------------------------------------
log "Comparing $BASE_LABEL (baseline $ASB_BASELINE_REF) vs $HEAD_LABEL (HEAD)"
report="$(
	ASB_OLD_DB_HOST="$DB_HOST" ASB_OLD_DB_PORT="$DB_PORT" ASB_OLD_DB_NAME="$SITE_DB" \
	ASB_OLD_DB_USER="$DB_ROOT_USER" ASB_OLD_DB_PASS="$DB_ROOT_PASS" ASB_OLD_PREFIX="wp_${BASE_LABEL}_" \
	ASB_NEW_DB_HOST="$DB_HOST" ASB_NEW_DB_PORT="$DB_PORT" ASB_NEW_DB_NAME="$SITE_DB" \
	ASB_NEW_DB_USER="$DB_ROOT_USER" ASB_NEW_DB_PASS="$DB_ROOT_PASS" ASB_NEW_PREFIX="wp_${HEAD_LABEL}_" \
	ASB_OLD_LABEL="$ASB_BASELINE_REF" ASB_NEW_LABEL="HEAD" \
	php "$ASB_ACTION_DIR/lib/antispam-plugin-stat-comparer.php"
)"
echo "$report"

flips="$(printf '%s\n' "$report" | sed -nE 's/.*Spam\/ham flips: ([0-9]+).*/\1/p' | head -1)"
flips="${flips:-0}"

# GitHub outputs + job summary.
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{ echo "baseline=$ASB_BASELINE_REF"; echo "flips=$flips"; } >> "$GITHUB_OUTPUT"
fi
summary "## Antispam Bee spam-detection comparison"
summary ""
summary "- **HEAD (prepared):** \`$ASB_BRANCH\`"
summary "- **Baseline:** \`$ASB_BASELINE_REF\`"
summary "- **Corpus rows:** $corpus_count"
summary "- **Spam/ham flips:** **$flips**"
summary ""
summary '```'
summary "$report"
summary '```'

log "Result: $flips spam/ham flip(s)"
if [ "$flips" -gt 0 ] && [ "$FAIL_ON_FLIPS" = "true" ]; then
	echo "Failing: $flips spam/ham flip(s) detected between $ASB_BASELINE_REF and HEAD." >&2
	exit 1
fi
