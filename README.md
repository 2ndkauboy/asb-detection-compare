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
- `compare.sh <old-label> <new-label> <env>` — `env` is `ddev` or `wp-env`
  (whichever backend holds the snapshots).

`compare.sh` prints the verdict counts, the **spam/ham flips** (where the two
versions disagree on the decision — the signal), and a **reason-transition
table** (same decision, different rule — the noise).

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