#!/usr/bin/env bash
#
# Stand up the wp-env variant of the bench: a single WordPress install (Docker,
# via @wordpress/env) with the corpus imported into a dedicated `corpus`
# database created with the root DB user, and the support mu-plugins mapped in.
# Idempotent — safe to re-run.
#
# Usage:
#   ./wp-env/setup.sh
#
# Notes:
#   * .wp-env.json maps the harness into the container:
#       wp-content/asb-lib     -> ../lib      (driver.php, comparer)
#       wp-content/asb-config  -> ../config   (option fixture)
#       wp-content/asb-dumps   -> the corpus dump directory
#       wp-content/plugins/antispam-bee -> ./active-plugin (swapped per version)
#   * wp-env's DB service is host `mysql`; the site user is `root`/`password`.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # .../asb-detection-compare/wp-env

cd "$SCRIPT_DIR"

# The active-plugin mapping source must exist before wp-env validates mappings.
mkdir -p active-plugin
touch active-plugin/.gitkeep

echo "== wp-env start =="
wp-env start

echo "== create 'corpus' database with the root user =="
wp-env run cli wp db query "CREATE DATABASE IF NOT EXISTS corpus"

echo "== import corpus dump into 'corpus' (inside the container) =="
# wp db query has no --dbname flag; select the target DB with a USE prefix.
wp-env run cli bash -c "{ echo 'USE corpus;'; zcat wp-content/asb-dumps/comments.sql.gz; } | wp db query"

corpus_count="$(wp-env run cli wp db query "USE corpus; SELECT COUNT(*) FROM wp_comments" --skip-column-names 2>/dev/null | tr -d '[:space:]')"

cat <<EOF

== wp-env bench ready ==
  corpus : corpus.wp_comments = ${corpus_count:-?} rows (host 'mysql')

Classify a cloned version with:  ./wp-env/run-version.sh <label> [limit]
EOF
