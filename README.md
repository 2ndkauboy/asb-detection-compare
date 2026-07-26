# asb-detection-compare — compare Antispam Bee spam detection between two versions

Replay a large corpus of real comments through **two builds of Antispam Bee**
(any branch, tag, or commit) and diff how each one classifies them — spam vs.
ham, and by which rule. This tells you whether a change in the plugin flips any
spam-detection decisions, and where.

Everything runs on **one** WordPress installation. You can stand that install up
with either of two backends — **DDEV** or **`@wordpress/env`** — and a bundled
benchmark lets you pick whichever is faster on your machine.

## How it works

The classifier (`lib/driver.php`) bootstraps WordPress once via WP-CLI and drives
WordPress' own comment pipeline in-process (`wp_handle_comment_submission()` /
`wp_new_comment()`), so a full corpus runs in minutes with results identical to
real HTTP submissions. Each replayed comment is linked back to its source row via
an `original_comment_id` comment meta — the join key for the comparison.

Because the driver classifies with whatever Antispam Bee version is **currently
active**, one site can compare any number of versions: classify with each build
in turn, snapshot the results under a label (`wp_<label>_comments` /
`wp_<label>_commentmeta`), then diff two labels with the WordPress-independent
comparer (`lib/antispam-plugin-stat-comparer.php`).

The corpus is imported into its **own `corpus` database** (created with the root
DB user), separate from the site's `wp_` tables, so there is no collision.

## Requirements

- Docker, plus **either** [DDEV](https://ddev.readthedocs.io/) **or**
  [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
  (`wp-env`) on your `PATH`.
- `git`, `bash`, and `composer` (used to generate the plugin's autoloader).
- A corpus dump at `dumps/comments.sql.gz` (see [Corpus dump](#corpus-dump)).

## Files

| Path | Purpose |
|------|---------|
| `clone-asb.sh` | Clone Antispam Bee twice and check out two refs into `versions/` (runs `composer install` to generate the PSR-4 autoloader). |
| `ddev/setup.sh`, `wp-env/setup.sh` | Build the single WordPress install and import the corpus into the `corpus` database. |
| `ddev/run-version.sh`, `wp-env/run-version.sh` | Activate a version, classify the corpus, snapshot the results under a label. |
| `benchmark.sh` | Classify the same slice through both backends and print a speed table (see below). |
| `compare.sh` | Diff two snapshots (spam/ham flips + reason transitions). |
| `lib/` | `driver.php` (classifier), `build-shards.php` (DbSpam-safe sharding), `antispam-plugin-stat-comparer.php` (report engine). |
| `mu-plugins/` | Support mu-plugins the driver needs: writes the join key and disables comment-flood throttling, duplicate-comment rejection, and notification mail. |
| `config/antispam_bee_options.3x.json` | Bundled known-good Antispam Bee 3.x option set (RegExp / Honeypot / BBCode / ApprovedEmail / save-reason on; Country and Gravatar off) so both versions run with an identical rule set. |
| `dumps/` | The corpus dump (gitignored). |
| `versions/` | Clone output (gitignored). |

## Usage

```bash
cd asb-detection-compare

# 1. Clone the two versions to compare (branch, tag, or commit for each).
./clone-asb.sh beta1 3.0.0-beta.1 norm refactor/normalized-rule-payload

# 2. Stand up an environment (each imports the corpus into the `corpus` DB).
./ddev/setup.sh          # or: ./wp-env/setup.sh

# 3. Classify each version over the whole corpus (8 parallel workers).
WORKERS=8 ./ddev/run-version.sh beta1    # -> wp_beta1_*
WORKERS=8 ./ddev/run-version.sh norm     # -> wp_norm_*

# 4. Diff the two versions.
./compare.sh beta1 norm ddev             # flips + reason-transition table
```

- `run-version.sh <label> [limit]` — `limit` caps the comments processed **per
  worker** (`0` = whole corpus, the default).
- `WORKERS` (env, default `1`) sets the number of parallel MOD-sharded
  classifier processes; use e.g. `WORKERS=8` for a full-corpus run.
- `ASB_REFRESH` (env, default `0`) — see [Snapshot caching](#snapshot-caching).
- `compare.sh <old-label> <new-label> <env>` — `env` is `ddev` or `wp-env`
  (whichever backend holds the snapshots).

`compare.sh` prints the verdict counts, the **spam/ham flips** (where the two
versions disagree on the decision — the signal), and a **reason-transition
table** (same decision, different rule — the noise).

## Snapshot caching

Classifying the full corpus takes ~20–30 min, so re-running the same baseline
(e.g. `v3` or `3.0.0-beta.1`) for every new PR comparison is wasted work.
`run-version.sh` therefore **reuses an existing snapshot by default**: a full run
records the version's git SHA and row count in a `wp_asb_snapshot_meta` table,
and a later run for the same label **skips classification** when

- the label's snapshot tables exist and the row count is intact, **and**
- the recorded git SHA matches the current `versions/<label>` checkout.

The SHA check means a moved branch is re-classified instead of served stale,
while immutable tags reuse forever. Only full runs (`limit = 0`) are cached or
reused — partial/benchmark runs always classify.

Force a fresh run with `ASB_REFRESH=1 ./ddev/run-version.sh <label>` (e.g. after
re-cloning a branch to a new commit). Typical PR-vs-baseline flow:

```bash
./clone-asb.sh mypr feat/my-branch v3 v3
WORKERS=8 ./ddev/run-version.sh mypr   # classifies the PR
WORKERS=8 ./ddev/run-version.sh v3     # instant if v3 is already cached
./compare.sh mypr v3 ddev
```

## Benchmarking DDEV vs. wp-env

Both backends run the identical workflow, but their local stacks differ in speed.
`benchmark.sh` exists so you can **measure which one is faster on your own
machine** before committing to a full run:

```bash
./benchmark.sh beta1 10000     # classify 10k comments on each backend
```

It classifies the same fixed-size slice (default 10,000 comments, single worker
for a clean, deterministic comparison) through **both** DDEV and wp-env, then
prints a table:

```
env        |   classify (s) |      rate (/min) |     wall (s)
-----------+----------------+------------------+-------------
ddev       |          632.5 |              948 |        635.8
wp-env     |         1041.4 |              575 |       1047.6
```

- **classify** — the driver's own in-process classification time.
- **rate** — comments per minute.
- **wall** — end-to-end time of the whole run (includes each backend's
  per-command container overhead).

Both `setup.sh` scripts must have run first (so the corpus is imported into both
backends). Pick the faster backend for your real full-corpus runs.

## Notes / limitations

- **DbSpam** is disabled during classification (`ASB_DISABLE_DB_SPAM=1`): it is
  order-dependent and effectively unparallelisable on densely linked corpora, so
  turning it off gives a fast, deterministic, apples-to-apples run. To keep it,
  export `ASB_DISABLE_DB_SPAM=0` and build a shard map with `build-shards.php`.
- The bundled option fixture is **3.x only**. A 2.x build is detected (no `src/`
  directory) and the run warns; add `config/antispam_bee.2x.json` and extend the
  two `run-version.sh` scripts to compare a 2.x version.
- Country and Gravatar rules are kept **off** — they depend on external lookups
  that are neither reproducible nor deterministic for a bulk historical replay.

## Corpus dump

Place a comment dump at `dumps/comments.sql.gz`. It must contain a
`<prefix>comments` table and its matching `<prefix>commentmeta` (prefix `wp_`).
The dump is gitignored (it is large binary test data). Override the path with
`ASB_DUMP=/path/to/dump.sql.gz ./ddev/setup.sh`.

## GitHub Action (CI)

The same comparison also runs in CI as a reusable action, so version-preparation
branches are checked automatically. It reuses this repo's `lib/`, `mu-plugins/`
and `config/` — there is no separate copy — but uses a **lean stack** (a MySQL
service + `wp-cli`, no DDEV/wp-env) and a small **bundled corpus**
(`fixtures/corpus.sql`) instead of the large local dump.

- `action.yml` — composite action (sets up PHP + WP-CLI, runs the CI runner).
- `scripts/ci-compare.sh` — the runner: resolve baseline → build both plugin
  builds → install WP → import the corpus → classify each → diff → job summary.
- `scripts/resolve-baseline.php` — maps a branch name to the baseline tag.
- `.github/workflows/self-test.yml` — exercises the action end-to-end.
- `examples/consuming-workflow.yml` — what `pluginkollektiv/antispam-bee` adds.

### When it runs (two tiers)

The consuming workflow runs the comparison at two levels:

- **Pull requests** → a fast smoke check against the small **bundled fixture**
  (`fixtures/corpus.sql`), compared to the PR's base branch (`baseline-ref:
  github.base_ref`).
- **`prepare-*` / `chore/prepare-*` pushes** → a thorough check against the
  **full corpus dump**, decrypted in the workflow from an encrypted blob (see
  [Providing the release corpus](#providing-the-release-corpus-encrypted)),
  compared to the resolved baseline release.

This is security-sound: `pull_request` runs (including from forks) never receive
secrets, so they always fall back to the bundled fixture; only trusted pushes to
prepare branches decrypt the private dump. If the corpus is not configured,
prepare pushes gracefully fall back to the fixture too.

### Baseline selection

The prepared version is parsed from the branch name (`chore/prepare-3.0.0-beta.2`
→ `3.0.0-beta.2`) and the baseline tag is chosen semver-aware:

- **Prerelease target** (`-beta`/`-rc`) → the latest earlier prerelease of the
  same `X.Y.Z` (e.g. `3.0.0-beta.2` → `3.0.0-beta.1`). This is the "while
  developing v3, compare against the latest 3.0.0 beta/RC" case. If there is no
  earlier prerelease yet, it falls back to the latest stable release.
- **Stable target** (e.g. `2.11.13`) → the latest earlier **stable** release
  (`2.11.12`). This is the "compare against the latest released version" case.

Override with the `baseline-ref` input when needed (e.g. `prepare-3.0.0` stable,
where you may want the last RC rather than the last 2.x stable).

### Inputs / outputs

Key inputs: `fail-on-flips` (default `true` — spam/ham flips fail the check;
reason-only differences stay informational), `php-version`, `workers`,
`baseline-ref`, `corpus-file` (a `.sql` or `.sql.gz` dump; the release tier
points this at the decrypted corpus), and `corpus-url` / `corpus-auth` (an
alternative: download the corpus from a URL, `.sql` or `.sql.gz`), plus the
`db-*` connection settings. Outputs: `baseline` (the resolved tag) and `flips`
(the flip count). Results are written to the job summary as a table plus the
full comparison report.

### Providing the release corpus (encrypted)

The full corpus is real comments (likely PII), so it must not be public — but a
GitHub secret caps at 48 KB, far smaller than the dump. The solution: **encrypt
the dump once; only the passphrase is a secret.** The ciphertext is useless
without the key, so it can be hosted at a public URL; the workflow downloads and
decrypts it, then passes the plain dump to the action via `corpus-file`.

1. **Encrypt** the dump (symmetric AES256 with a strong passphrase):

   ```bash
   gpg --batch --symmetric --cipher-algo AES256 \
     --passphrase "$ASB_CORPUS_KEY" -o corpus.sql.gz.gpg corpus.sql.gz
   ```

2. **Host** `corpus.sql.gz.gpg` at a URL the runner can reach — e.g. a **public**
   GitHub release asset. Public is fine: it is encrypted.

3. **Configure** the consuming repo (`antispam-bee`):
   - repository **variable** `ASB_CORPUS_ENC_URL` = the ciphertext URL (not
     sensitive);
   - repository **secret** `ASB_CORPUS_KEY` = the passphrase.

4. The workflow's release-tier step (see `examples/consuming-workflow.yml`)
   downloads and decrypts it:

   ```yaml
   - name: Fetch & decrypt the release corpus
     if: github.event_name == 'push' && vars.ASB_CORPUS_ENC_URL != ''
     env:
       ASB_CORPUS_ENC_URL: ${{ vars.ASB_CORPUS_ENC_URL }}
       ASB_CORPUS_KEY: ${{ secrets.ASB_CORPUS_KEY }}
     run: |
       curl -fSL --retry 3 -o corpus.sql.gz.gpg "$ASB_CORPUS_ENC_URL"
       gpg --batch --quiet --yes --passphrase "$ASB_CORPUS_KEY" \
         -o corpus.sql.gz -d corpus.sql.gz.gpg
   ```

   Then the action step sets `corpus-file` to the decrypted `corpus.sql.gz` on
   the release tier, and leaves it empty on PRs (bundled fixture).

Because the ciphertext is public, the **passphrase must be strong** — it is the
only thing protecting the data. Rotate it by re-encrypting and updating the
secret. `gpg` is preinstalled on GitHub-hosted runners.