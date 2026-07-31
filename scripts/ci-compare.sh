#!/usr/bin/env bash
#
# CI runner for the asb-detection-compare GitHub Action.
#
# Classifies a comment corpus with two Antispam Bee builds — the current
# checkout (HEAD, the prepared version) and a resolved baseline tag — and diffs
# the spam/ham verdicts. Unlike the local harness it uses NO DDEV/wp-env: just a
# reachable MySQL server, PHP, Composer, WP-CLI and git. Reuses the repo's own
# lib/driver.php, lib/antispam-plugin-stat-comparer.php, mu-plugins/ and the 3.x
# option fixture — no duplicated logic.
#
# The baseline half is usually not classified at all: when the baseline resolves
# to a tag whose GitHub release carries a matching verdict snapshot, that
# snapshot is downloaded and used instead. See "Baseline snapshots" in the
# README, and lib/snapshot-format.php for what such a snapshot contains.
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
#   ASB_USE_BASELINE_SNAPSHOT  "false" disables the release-asset lookup.
#   ASB_SNAPSHOT_REPO          Repo whose releases carry the snapshots.
#   ASB_SNAPSHOT_SALT          Optional extra secret mixed into the pseudonym salt.
#   ASB_CORPUS_ID              Override the computed corpus fingerprint.
#   ASB_CORPUS_LABEL           Cosmetic asset-name segment (fixture/private).
#   ASB_WP_VERSION             WordPress core version to install (default latest).
#   ASB_MAX_FLIPS_LISTED       Cap on flips listed in the report (default 50).
#   ASB_SHARD_MODE             Shard grouping: "email" (default) or "identity".
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
ASB_USE_BASELINE_SNAPSHOT="${ASB_USE_BASELINE_SNAPSHOT:-true}"
ASB_SNAPSHOT_REPO="${ASB_SNAPSHOT_REPO:-${GITHUB_REPOSITORY:-}}"
ASB_WP_VERSION="${ASB_WP_VERSION:-latest}"
ASB_MAX_FLIPS_LISTED="${ASB_MAX_FLIPS_LISTED:-50}"
export ASB_SNAPSHOT_SALT="${ASB_SNAPSHOT_SALT:-}"

# Cosmetic only: which corpus this run used, for the asset filename. Decided
# before CORPUS_FILE is rewritten to point at the normalised dump.
if [ -n "${ASB_CORPUS_LABEL:-}" ]; then
	CORPUS_LABEL="$ASB_CORPUS_LABEL"
elif [ -n "${CORPUS_URL:-}" ] || [ "$CORPUS_FILE" != "$ASB_ACTION_DIR/fixtures/corpus.sql" ]; then
	CORPUS_LABEL="private"
else
	CORPUS_LABEL="fixture"
fi

log()     { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
summary() { [ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n' "$*" >> "$GITHUB_STEP_SUMMARY" || true; }

# The scratch dir holds the corpus and is deleted on exit. The snapshot output
# dir must survive the step so a later step can upload it — so it lives outside,
# and NOTHING but the snapshot may ever be written into it.
WORK="${RUNNER_TEMP:-$(mktemp -d)}/asb-compare"
SNAP_OUT="${RUNNER_TEMP:-$(dirname "$WORK")}/asb-snapshot-out"
rm -rf "$WORK" "$SNAP_OUT"
mkdir -p "$WORK" "$SNAP_OUT"
WP_DIR="$WORK/wp"
BASE_DIR="$WORK/baseline"
trap 'git -C "$ASB_PLUGIN_DIR" worktree remove --force "$BASE_DIR" 2>/dev/null || true; rm -rf "$WORK"' EXIT

# WP-CLI wrapper (path-scoped, non-interactive, allow running as root in CI).
wp() { command wp --path="$WP_DIR" --allow-root "$@"; }

# ---------------------------------------------------------------------------
# 1. Resolve the baseline tag and the commit it points at.
# ---------------------------------------------------------------------------
git config --global --add safe.directory "$ASB_PLUGIN_DIR" 2>/dev/null || true
git -C "$ASB_PLUGIN_DIR" fetch --tags --force --quiet 2>/dev/null || true

if [ -z "$ASB_BASELINE_REF" ]; then
	log "Resolving baseline for branch '$ASB_BRANCH'"
	ASB_BASELINE_REF="$( cd "$ASB_PLUGIN_DIR" && php "$ASB_ACTION_DIR/scripts/resolve-baseline.php" "$ASB_BRANCH" )"
fi
echo "Baseline: $ASB_BASELINE_REF"

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
baseline_sha="$(git -C "$ASB_PLUGIN_DIR" rev-parse "${baseline_ish}^{commit}")"
echo "Baseline commit: $baseline_sha"

# Only a tag can carry a published snapshot: a branch tip moves, and a snapshot
# is only meaningful for the exact commit it was produced from.
baseline_is_tag=false
if git -C "$ASB_PLUGIN_DIR" rev-parse --verify --quiet "refs/tags/$ASB_BASELINE_REF" >/dev/null; then
	baseline_is_tag=true
fi

build_autoloader() {
	local dir="$1"
	if [ -f "$dir/composer.json" ] && [ ! -f "$dir/vendor/autoload.php" ]; then
		log "composer install ($dir)"
		( cd "$dir" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
	fi
}
build_autoloader "$ASB_PLUGIN_DIR"

# ---------------------------------------------------------------------------
# 2. Wait for MySQL, create databases, import the corpus (all via WP-CLI later;
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
# 3. Install WordPress once (site DB), then create + load the corpus DB.
# ---------------------------------------------------------------------------
log "Installing WordPress"
mkdir -p "$WP_DIR"
if [ -n "$ASB_WP_VERSION" ] && [ "$ASB_WP_VERSION" != "latest" ]; then
	wp core download --force --version="$ASB_WP_VERSION"
else
	wp core download --force
fi
wp config create --force --skip-check \
	--dbname="$SITE_DB" --dbuser="$DB_ROOT_USER" --dbpass="$DB_ROOT_PASS" --dbhost="$DB_HOST:$DB_PORT"
wp db reset --yes 2>/dev/null || wp db create 2>/dev/null || true
wp core install --url="http://localhost" --title="ASB detection compare" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
wp_version="$(wp core version)"
echo "WordPress: $wp_version"

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
# SET NAMES utf8mb4 so 4-byte characters (emoji in real comment content) import.
{ echo "SET NAMES utf8mb4; USE \`$CORPUS_DB\`;"; cat "$CORPUS_FILE"; } | wp db query
corpus_count="$(wp db query "SELECT COUNT(*) FROM \`$CORPUS_DB\`.wp_comments" --skip-column-names)"
echo "Corpus rows: $corpus_count"

# ---------------------------------------------------------------------------
# 4. Build the identity-cluster shard map.
#
# Antispam Bee has rules whose verdict depends on what is already in the site
# DB: DbSpam matches a previous spam entry by IP/e-mail/URL, and ApprovedEmail
# trusts an address that already has an approved comment. Under plain
# MOD(comment_ID) sharding those comments land on racing workers, so a verdict
# can depend on interleaving — which is invisible when both sides run in the
# same job, but turns into phantom flips the moment one side comes from a stored
# snapshot. build-shards.php keeps whole identity clusters on one worker, in
# comment_ID order, which makes the outcome deterministic and independent of the
# worker count.
# ---------------------------------------------------------------------------
# DbSpam is disabled here, so the only order-dependent rule left is
# ApprovedEmail, which keys on the e-mail address alone. Grouping by e-mail is
# therefore sufficient — and far better balanced than the transitive
# IP/e-mail/URL closure, which on real corpora collapses most of the comments
# into one component and starves every worker but one.
SHARD_MODE="${ASB_SHARD_MODE:-email}"
log "Building the shard map (mode: $SHARD_MODE)"
ASB_SRC_DB_HOST="$DB_HOST" ASB_SRC_DB_PORT="$DB_PORT" ASB_SRC_DB_NAME="$CORPUS_DB" \
	ASB_SRC_DB_USER="$DB_ROOT_USER" ASB_SRC_DB_PASS="$DB_ROOT_PASS" ASB_SRC_PREFIX=wp_ \
	ASB_WORKER_COUNT="$WORKERS" ASB_SHARD_MODE="$SHARD_MODE" ASB_DISABLE_DB_SPAM=1 \
	php "$ASB_ACTION_DIR/lib/build-shards.php"

# ---------------------------------------------------------------------------
# 5. Fingerprint the run: what identifies this corpus, and what conditions the
#    classification depends on.
# ---------------------------------------------------------------------------
# Corpus identity is computed from the imported rows, not from the dump file: a
# dump regenerated by mysqldump has different bytes every time, which would make
# every snapshot lookup miss without anyone noticing (a miss only looks slow).
# COUNT + SUM + BIT_XOR over a per-row CRC is order-independent and
# content-sensitive.
crc_expr="CRC32(CONCAT_WS(0x1f, comment_ID, comment_content, comment_author,
	comment_author_email, comment_author_url, comment_author_IP, comment_agent, comment_type))"
if [ -n "${ASB_CORPUS_ID:-}" ]; then
	corpus_fp="$ASB_CORPUS_ID"
else
	corpus_fp="$(wp db query "SELECT MD5(CONCAT_WS(':', COUNT(*),
		IFNULL(SUM($crc_expr), 0), IFNULL(BIT_XOR($crc_expr), 0)))
		FROM \`$CORPUS_DB\`.wp_comments" --skip-column-names | tr -d '[:space:]')"
fi

# Everything that can change a verdict, hashed into one token. The comparer is
# deliberately NOT in here: it reads snapshots, it does not produce them, so a
# change to it must not invalidate every published asset.
harness_fp="$(cat \
	"$ASB_ACTION_DIR/lib/driver.php" \
	"$ASB_ACTION_DIR/lib/build-shards.php" \
	"$ASB_ACTION_DIR/lib/snapshot-format.php" \
	"$ASB_ACTION_DIR/scripts/export-snapshot.php" \
	"$ASB_ACTION_DIR/config/antispam_bee_options.3x.json" \
	"$ASB_ACTION_DIR"/mu-plugins/*.php | sha256sum | cut -d' ' -f1)"

# The fingerprint is per commit: it answers "was this classification produced
# from *this* plugin build under *these* conditions". The baseline's fingerprint
# is what a published snapshot must match; HEAD's is what this run's own
# snapshot records, so that it validates when it becomes someone's baseline.
hard_fp_for() {
	printf 'schema=1\ncommit=%s\ncorpus=%s\nharness=%s\nshard=%s\ndbspam=off\n' \
		"$1" "$corpus_fp" "$harness_fp" "$SHARD_MODE" | sha256sum | cut -d' ' -f1
}
hard_fp="$(hard_fp_for "$baseline_sha")"

php_minor="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
soft_json="$(ASB_WPV="$wp_version" ASB_PHPM="$php_minor" ASB_W="$WORKERS" php -r \
	'echo json_encode(["wp_version"=>getenv("ASB_WPV"),"php_minor"=>getenv("ASB_PHPM"),"workers"=>getenv("ASB_W")]);')"

# The pseudonymisation salt is derived from the corpus fingerprint (plus an
# optional secret), so two runs over the same corpus produce joinable ids with no
# shared secret. It goes into a file because argv is world-readable via `ps`.
export ASB_LIB="$ASB_ACTION_DIR/lib/snapshot-format.php"
ASB_CORPUS_FP="$corpus_fp" php -r \
	'require getenv("ASB_LIB"); echo asb_snapshot_salt(getenv("ASB_CORPUS_FP"), (string) getenv("ASB_SNAPSHOT_SALT"));' \
	> "$WORK/salt"
chmod 600 "$WORK/salt"
salt_check="$(php -r 'require getenv("ASB_LIB"); echo asb_snapshot_salt_check(trim(file_get_contents($argv[1])));' "$WORK/salt")"
corpus_token="$(ASB_CORPUS_FP="$corpus_fp" php -r \
	'require getenv("ASB_LIB"); echo asb_snapshot_corpus_token(getenv("ASB_CORPUS_FP"));')"

echo "Corpus: $CORPUS_LABEL/$corpus_token  |  conditions: ${hard_fp:0:12}"

# ---------------------------------------------------------------------------
# 6. Try to use a published baseline snapshot instead of classifying twice.
# ---------------------------------------------------------------------------
BASE_SNAPSHOT=""
baseline_source="classified"
drift_notes=""
candidate=""

# A snapshot handed to us directly (local debugging, a self-hosted setup, or the
# self-test) short-circuits the release lookup but goes through the same checks.
if [ -n "${ASB_BASELINE_SNAPSHOT:-}" ]; then
	log "Using the baseline snapshot supplied via ASB_BASELINE_SNAPSHOT"
	if [ ! -f "$ASB_BASELINE_SNAPSHOT" ]; then
		# Most likely cause: it was left in the snapshot output dir, which this
		# run cleared on startup. Copy it aside before re-running.
		echo "ASB_BASELINE_SNAPSHOT does not exist: $ASB_BASELINE_SNAPSHOT" >&2
		exit 1
	fi
	candidate="$ASB_BASELINE_SNAPSHOT"
elif [ "$ASB_USE_BASELINE_SNAPSHOT" = "true" ] && [ "$baseline_is_tag" = "true" ] \
	&& [ -n "$ASB_SNAPSHOT_REPO" ] && command -v gh >/dev/null; then
	log "Looking for a published baseline snapshot on release '$ASB_BASELINE_REF'"
	dl_dir="$WORK/baseline-snapshot"
	mkdir -p "$dl_dir"
	if gh release download "$ASB_BASELINE_REF" --repo "$ASB_SNAPSHOT_REPO" \
		--pattern "asb-snapshot-*-${corpus_token}.tsv.gz" --dir "$dl_dir" 2>&1; then
		candidate="$(find "$dl_dir" -name '*.tsv.gz' -type f | head -1)"
	else
		echo "No matching snapshot asset on that release."
	fi
elif [ "$ASB_USE_BASELINE_SNAPSHOT" = "true" ] && [ "$baseline_is_tag" != "true" ]; then
	echo "Baseline '$ASB_BASELINE_REF' is not a tag — snapshots are only published for releases."
elif [ "$ASB_USE_BASELINE_SNAPSHOT" = "true" ] && ! command -v gh >/dev/null; then
	echo "The gh CLI is not available — cannot look for a published baseline snapshot."
fi

# However the candidate arrived, it faces the same checks: right experiment,
# right salt, and a note about anything that drifted but does not invalidate it.
if [ -n "$candidate" ]; then
	if php "$ASB_ACTION_DIR/scripts/verify-snapshot.php" \
		--file="$candidate" --hard-fp="$hard_fp" --salt-check="$salt_check" \
		--soft="$soft_json" > "$WORK/verify.txt"; then
		BASE_SNAPSHOT="$candidate"
		baseline_source="release-asset"
		drift_notes="$(grep '^Baseline snapshot drift:' "$WORK/verify.txt" || true)"
	fi
	cat "$WORK/verify.txt"
fi

# ---------------------------------------------------------------------------
# 7. Classify a build and export its verdicts as a snapshot.
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
			ASB_SHARD_TABLE=wp_asb_shard ASB_DISABLE_DB_SPAM=1 \
			command wp --path="$WP_DIR" --allow-root eval-file "$ASB_ACTION_DIR/lib/driver.php" "$i" "$WORKERS" ) &
		pids+=("$!")
	done
	local status=0 p
	for p in "${pids[@]}"; do wait "$p" || status=1; done
	[ "$status" -eq 0 ] || { echo "!! a classify worker failed for '$label'" >&2; return 1; }
}

# Export whatever is currently in the live tables as a snapshot. Must run right
# after the matching classify(), before the next pass truncates them.
export_snapshot() {
	local out="$1" ref="$2" sha="$3"
	local meta
	meta="$(ASB_M_REF="$ref" ASB_M_SHA="$sha" ASB_M_HARD="$(hard_fp_for "$sha")" ASB_M_TOKEN="$corpus_token" \
		ASB_M_SHARD="$SHARD_MODE" ASB_M_SOFT="$soft_json" php -r \
		'echo json_encode([
			"ref"           => getenv("ASB_M_REF"),
			"commit_sha"    => getenv("ASB_M_SHA"),
			"hard_fp"       => getenv("ASB_M_HARD"),
			"corpus_token"  => getenv("ASB_M_TOKEN"),
			"shard_mode"    => getenv("ASB_M_SHARD"),
			"soft"          => json_decode(getenv("ASB_M_SOFT"), true),
		]);')"
	DB_PASS="$DB_ROOT_PASS" php "$ASB_ACTION_DIR/scripts/export-snapshot.php" \
		--host="$DB_HOST" --port="$DB_PORT" --user="$DB_ROOT_USER" --pass-env=DB_PASS \
		--db="$SITE_DB" --prefix=wp_ --salt-file="$WORK/salt" \
		--meta="$meta" --out="$out" > /dev/null
}

# HEAD first: its snapshot is the one this run publishes.
head_sha="$(git -C "$ASB_PLUGIN_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
HEAD_SNAPSHOT="$SNAP_OUT/asb-snapshot-${CORPUS_LABEL}-${corpus_token}.tsv.gz"
classify head "$ASB_PLUGIN_DIR"
export_snapshot "$HEAD_SNAPSHOT" "$ASB_BRANCH" "$head_sha"

if [ -z "$BASE_SNAPSHOT" ]; then
	log "Checking out baseline '$ASB_BASELINE_REF' into a worktree"
	git -C "$ASB_PLUGIN_DIR" worktree add --detach --force "$BASE_DIR" "$baseline_ish"
	build_autoloader "$BASE_DIR"
	BASE_SNAPSHOT="$WORK/baseline.tsv.gz"
	classify "baseline" "$BASE_DIR"
	export_snapshot "$BASE_SNAPSHOT" "$ASB_BASELINE_REF" "$baseline_sha"
else
	log "Skipping the baseline classification — using the published snapshot"
fi

# ---------------------------------------------------------------------------
# 8. Compare the two snapshots and report.
# ---------------------------------------------------------------------------
log "Comparing $ASB_BASELINE_REF vs HEAD"
stats_file="$WORK/stats.json"
report="$(
	ASB_OLD_FILE="$BASE_SNAPSHOT" ASB_NEW_FILE="$HEAD_SNAPSHOT" \
	ASB_OLD_LABEL="$ASB_BASELINE_REF" ASB_NEW_LABEL="HEAD" \
	ASB_MAX_FLIPS_LISTED="$ASB_MAX_FLIPS_LISTED" ASB_STATS_FILE="$stats_file" \
	php "$ASB_ACTION_DIR/lib/antispam-plugin-stat-comparer.php"
)"
echo "$report"

stat_of() { php -r '$s=json_decode(file_get_contents($argv[1]),true); echo $s[$argv[2]] ?? 0;' "$stats_file" "$1"; }
flips="$(stat_of spam_flips)"
compared="$(stat_of compared)"
only_in_baseline="$(stat_of only_in_old)"
only_in_head="$(stat_of only_in_new)"
reason_diffs="$(stat_of reason_diffs)"

# ---------------------------------------------------------------------------
# 9. Publish the HEAD snapshot for the caller, but only if it is safe to.
#
# This directory gets uploaded as a workflow artifact and can end up as a public
# release asset, so it must hold the snapshot and nothing else — the scratch dir
# next to it contains the corpus itself.
# ---------------------------------------------------------------------------
snapshot_out=""
publishable="$(php -r \
	'require getenv("ASB_LIB"); $m = asb_snapshot_manifest($argv[1]); echo ! empty($m["publishable"]) ? "yes" : "no";' \
	"$HEAD_SNAPSHOT")"
stray="$(find "$SNAP_OUT" -mindepth 1 ! -name "$(basename "$HEAD_SNAPSHOT")" | head -1)"
if [ -n "$stray" ]; then
	echo "!! Refusing to publish: unexpected file in the snapshot output dir: $stray" >&2
elif [ "$publishable" != "yes" ]; then
	echo "!! Not publishing the snapshot: it is not pseudonymous or not reproducible." >&2
else
	snapshot_out="$HEAD_SNAPSHOT"
fi

# ---------------------------------------------------------------------------
# 10. GitHub outputs + job summary.
# ---------------------------------------------------------------------------
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "baseline=$ASB_BASELINE_REF"
		echo "baseline-sha=$baseline_sha"
		echo "baseline-source=$baseline_source"
		echo "flips=$flips"
		echo "compared=$compared"
		echo "only-in-baseline=$only_in_baseline"
		echo "only-in-head=$only_in_head"
		echo "reason-diffs=$reason_diffs"
		echo "snapshot-file=$snapshot_out"
	} >> "$GITHUB_OUTPUT"
fi

if [ "$baseline_source" = "release-asset" ]; then
	baseline_note="reused the snapshot published on that release (no re-classification)"
else
	baseline_note="classified in this run"
fi

summary "## Antispam Bee spam-detection comparison"
summary ""
summary "- **HEAD (prepared):** \`$ASB_BRANCH\`"
summary "- **Baseline:** \`$ASB_BASELINE_REF\` (\`${baseline_sha:0:12}\`) — $baseline_note"
summary "- **Corpus rows:** $corpus_count (\`$CORPUS_LABEL/$corpus_token\`)"
summary "- **Compared:** $compared"
summary "- **Spam/ham flips:** **$flips**"
summary "- **Reason-only differences:** $reason_diffs"
if [ -n "$drift_notes" ]; then
	summary ""
	summary "> [!WARNING]"
	while IFS= read -r line; do
		summary "> $line"
	done <<< "$drift_notes"
fi
summary ""
summary '```'
summary "$report"
summary '```'

log "Result: $flips spam/ham flip(s)"
if [ "$flips" -gt 0 ] && [ "$FAIL_ON_FLIPS" = "true" ]; then
	echo "Failing: $flips spam/ham flip(s) detected between $ASB_BASELINE_REF and HEAD." >&2
	exit 1
fi
