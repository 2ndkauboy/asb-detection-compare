#!/usr/bin/env bash
#
# Stand up the DDEV variant of the bench: a single fresh WordPress install with
# the corpus imported into a dedicated `corpus` database (created via the root
# DB user) and the support mu-plugins in place. Idempotent — safe to re-run.
#
# Usage:
#   ./ddev/setup.sh
#
# Env overrides:
#   ASB_DUMP   Path to the corpus dump (default: the asb-comparison dumps file).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # .../asb-detection-compare/ddev
BENCH_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"                    # .../asb-detection-compare
REPO_ROOT="$(cd "$BENCH_DIR/.." && pwd)"                     # .../antispam-bee-tests

PROJECT="asb-detection-compare-ddev"
DOCROOT="wp"
URL="https://${PROJECT}.ddev.site"
DUMP="${ASB_DUMP:-$BENCH_DIR/dumps/comments.sql.gz}"

if [ ! -f "$DUMP" ]; then echo "Corpus dump not found: $DUMP" >&2; exit 1; fi

cd "$SCRIPT_DIR"
mkdir -p "$DOCROOT"

echo "== configure DDEV project '$PROJECT' (docroot=$DOCROOT) =="
ddev config \
	--project-name="$PROJECT" \
	--project-type=wordpress \
	--docroot="$DOCROOT" \
	--php-version=8.2 \
	--database=mariadb:10.4

echo "== start =="
ddev start

if ! ddev wp core is-installed 2>/dev/null; then
	echo "== download WordPress core =="
	ddev wp core download --force
	echo "== create wp-config (DDEV db service) =="
	ddev wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db --force
	echo "== install WordPress =="
	ddev wp core install \
		--url="$URL" \
		--title="ASB Bench (DDEV)" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
else
	echo "== WordPress already installed =="
fi

echo "== install support mu-plugins =="
mkdir -p "$DOCROOT/wp-content/mu-plugins"
cp "$BENCH_DIR"/mu-plugins/*.php "$DOCROOT/wp-content/mu-plugins/"

echo "== create 'corpus' database with the root user + import dump =="
ddev mysql -uroot -proot <<'SQL'
CREATE DATABASE IF NOT EXISTS corpus;
GRANT ALL PRIVILEGES ON corpus.* TO 'db'@'%';
FLUSH PRIVILEGES;
SQL
ddev import-db --database=corpus --file="$DUMP"

corpus_count="$(ddev mysql -uroot -proot -N -e 'SELECT COUNT(*) FROM corpus.wp_comments' 2>/dev/null | tr -d '[:space:]')"

cat <<EOF

== DDEV bench ready ==
  project : $PROJECT ($URL)
  corpus  : corpus.wp_comments = ${corpus_count:-?} rows (host 'db')

Classify a cloned version with:  ./ddev/run-version.sh <label> [limit]
EOF
