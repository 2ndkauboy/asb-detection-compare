#!/usr/bin/env bash
#
# Clone Antispam Bee from GitHub in two versions, checking out an arbitrary ref
# (branch, tag, or commit SHA) for each. The two checkouts are what the bench
# environments activate (one at a time) to compare how each version classifies
# the corpus.
#
# Usage:
#   ./clone-asb.sh <labelA> <refA> <labelB> <refB> [repo-url]
#
#   labelA / labelB   Short names for the two versions (letters/digits/underscore),
#                     e.g. beta1, norm. Used as the checkout dir name and, later,
#                     as the snapshot label.
#   refA / refB       Git ref to check out for each: a branch, tag, or commit.
#   repo-url          Git remote to clone (default: the canonical repo).
#
# Re-runnable: an existing checkout is fetched and re-checked-out rather than
# re-cloned. Output goes to versions/<label>/ (gitignored).
set -euo pipefail

LABEL_A="${1:?usage: ./clone-asb.sh <labelA> <refA> <labelB> <refB> [repo-url]}"
REF_A="${2:?missing refA}"
LABEL_B="${3:?missing labelB}"
REF_B="${4:?missing refB}"
REPO_URL="${5:-https://github.com/pluginkollektiv/antispam-bee.git}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSIONS_DIR="$SCRIPT_DIR/versions"
mkdir -p "$VERSIONS_DIR"

# Clone (or update) $REPO_URL into versions/<label>/ and check out <ref>.
clone_one() {
	local label="$1" ref="$2" dest="$VERSIONS_DIR/$1"

	if ! [[ "$label" =~ ^[A-Za-z0-9_]+$ ]]; then
		echo "Invalid label '$label' (allowed: letters, digits, underscore)." >&2
		exit 1
	fi

	if [ -d "$dest/.git" ]; then
		echo "== [$label] updating existing checkout ($dest) =="
		git -C "$dest" fetch --tags --force origin
	else
		echo "== [$label] cloning $REPO_URL -> $dest =="
		rm -rf "$dest"
		git clone "$REPO_URL" "$dest"
	fi

	echo "== [$label] checking out '$ref' =="
	# Detach onto the ref so branches, tags and commit SHAs all work uniformly.
	git -C "$dest" checkout --detach --force "$ref" 2>/dev/null \
		|| git -C "$dest" checkout --detach --force "origin/$ref"

	if [ ! -f "$dest/antispam_bee.php" ]; then
		echo "WARNING: $dest/antispam_bee.php not found after checkout of '$ref'." >&2
		echo "         Is '$ref' a valid Antispam Bee ref?" >&2
		exit 1
	fi

	# Antispam Bee 3.x loads its classes through a Composer PSR-4 autoloader
	# (AntispamBee\ -> src/), which a bare git checkout does not include. There
	# are no runtime dependencies, so `composer install --no-dev` just generates
	# vendor/autoload.php. 2.x has no composer.json and is skipped.
	if [ -f "$dest/composer.json" ] && [ ! -f "$dest/vendor/autoload.php" ]; then
		if command -v composer >/dev/null 2>&1; then
			echo "== [$label] composer install (generate autoloader) =="
			( cd "$dest" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
		else
			echo "WARNING: composer not found; $dest/vendor/autoload.php was not generated." >&2
			echo "         Antispam Bee 3.x will not load its classes without it." >&2
			exit 1
		fi
	fi

	local sha
	sha="$(git -C "$dest" rev-parse --short HEAD)"
	echo "== [$label] ready at $ref ($sha) =="
}

clone_one "$LABEL_A" "$REF_A"
clone_one "$LABEL_B" "$REF_B"

cat <<EOF

== two versions ready ==
  $LABEL_A -> $REF_A   ($VERSIONS_DIR/$LABEL_A)
  $LABEL_B -> $REF_B   ($VERSIONS_DIR/$LABEL_B)

Next: stand up an environment and classify each version, e.g.
  ./ddev/setup.sh    &&  ./ddev/run-version.sh $LABEL_A
  ./wp-env/setup.sh  &&  ./wp-env/run-version.sh $LABEL_A
EOF
