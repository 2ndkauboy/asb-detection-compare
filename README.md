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
- `scripts/ci-compare.sh` — the runner: resolve baseline → install WP → import
  the corpus → shard it → classify HEAD → reuse or classify the baseline → diff
  → job summary. See [Baseline snapshots](#baseline-snapshots).
- `scripts/resolve-baseline.php` — maps a branch name to the baseline tag.
- `scripts/build-fixture.php` — regenerates `fixtures/corpus.sql` from a real
  corpus, stratified by `antispam_bee_reason` + `comment_type` (≤10 rows each)
  with PII removed (synthetic author/e-mail/IP everywhere, synthetic ham
  content; spam content/URLs kept). See its header for the extraction query.
- `scripts/export-snapshot.php` — writes a pass's verdicts as a pseudonymous
  snapshot; `scripts/verify-snapshot.php` decides whether a published one may be
  reused. `lib/snapshot-format.php` holds the format and its privacy invariant.
- `.github/workflows/self-test.yml` — exercises the action end-to-end.
- `examples/consuming-workflow.yml` — what `pluginkollektiv/antispam-bee` adds.
- `examples/attach-snapshot-on-release.yml` — the other half of the snapshot
  chain: attaches a release's verdict snapshot so the next run can reuse it.

### When it runs (three tiers)

The consuming workflow runs the comparison at three levels:

- **Pull requests** → a fast smoke check against the small **bundled fixture**
  (`fixtures/corpus.sql`), compared to the PR's base branch (`baseline-ref:
  github.base_ref`).
- **`prepare-*` / `chore/prepare-*` pushes** → a thorough check against the
  **full corpus dump**, decrypted in the workflow from an encrypted blob (see
  [Providing the release corpus](#providing-the-release-corpus-encrypted)),
  compared to the resolved baseline release.
- **Manual (`workflow_dispatch`)** → run against **any branch** on demand,
  choosing the baseline (`baseline_ref`, default `v3`), whether to use the full
  corpus (`full_corpus`, default on) or the bundled fixture, and whether to gate
  on flips (`fail_on_flips`). Handy for checking a feature branch against the
  full corpus before it becomes a prepare branch.

This is security-sound: `pull_request` runs (including from forks) never receive
secrets, so they always fall back to the bundled fixture; only trusted events —
`prepare-*` pushes and manual runs — decrypt the private dump. If the corpus is
not configured, those runs gracefully fall back to the fixture too.

> **Note:** GitHub shows the "Run workflow" button only for workflows present on
> the repo's default branch, and the manual run uses the workflow file from the
> branch you pick — so the branch you dispatch on must contain this workflow.

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
`db-*` connection settings, plus the snapshot settings below
(`use-baseline-snapshot`, `snapshot-repo`, `snapshot-salt`, `wp-version`,
`corpus-id`, `corpus-label`, `max-flips-listed`). Outputs: `baseline` (the
resolved tag), `baseline-sha`, `baseline-source`, `flips`, `compared`,
`only-in-baseline`, `only-in-head`, `reason-diffs`, and `snapshot-file` (this
run's own snapshot, for the caller to upload). Results are written to the job
summary as a table plus the full comparison report.

### Baseline snapshots

Classifying the full corpus takes ~20–30 minutes, and half of every run is spent
re-deriving something that never changes: how the *baseline release* classifies
that corpus. So a run publishes its verdicts, and later runs read them back.

The chain sustains itself, with no separate warm-up job:

1. A `prepare-3.0.0-beta.2` push classifies the prepared version and writes its
   verdicts to `snapshot-file`; the workflow uploads that as an artifact.
2. When `3.0.0-beta.2` is released, `attach-snapshot-on-release.yml` attaches
   that artifact to the release.
3. The next prepare run resolves its baseline to `3.0.0-beta.2`, finds the asset
   on that release, and **skips the baseline classification entirely** — one
   pass per run instead of two.

Release assets were chosen over the Actions cache deliberately: they are
immutable, never evicted, and readable from any branch. An Actions cache is
scoped to the branch that wrote it (falling back only to the base and default
branches), so a cache warmed on `v3` is invisible to exactly the `prepare-*`
pushes that need it most.

Only tags are looked up — a branch tip moves, and a snapshot only means anything
for the exact commit it came from. PR runs (baseline = a branch, corpus = the
bundled fixture) therefore just classify both sides; on the fixture that costs
seconds.

**What is in a snapshot.** Exactly the three fields the comparer reads, one row
per comment:

```
0af31c9b2d5e7a41	spam	asb-regexp%2Casb-bbcode
1b7c3f90ab2d4e15	1	\N
```

a pseudonymous id, the `comment_approved` status, and the matched rule slugs.
No content, author, e-mail, IP or date — those are never read in the first
place. A full 332k-comment corpus comes to about 3 MB gzipped. Both the status *and* the reason are needed: Antispam Bee records a
reason whenever a rule matched, including on comments that stay ham, so "has a
reason" is not the same as "is spam".

**Why publishing it is safe.** The pseudonym is `HMAC-SHA256` of the *corpus row
id* — a surrogate key that means nothing outside the dump it came from. Even
with the salt disclosed, a pseudonym cannot be joined against anything an
outsider holds. That is the invariant the design rests on, and
`lib/snapshot-format.php` states it: **never derive the pseudonym from a
personal identifier** (e-mail, IP, URL) — for those, salt secrecy would be the
only protection and a truncated hash over a guessable domain is brute-forceable.
The salt itself is derived from the corpus fingerprint, so two runs over the same
corpus produce joinable ids with no shared secret; `snapshot-salt` can add one
anyway. What a published snapshot does disclose is aggregate composition — the
spam/ham ratio and rule-hit distribution of the corpus.

Two rules the runner enforces so this cannot erode:

- The snapshot output directory is written to by nothing else and is checked for
  strays before the path is handed to the caller. The scratch directory beside it
  holds the decrypted corpus; uploading that would publish the dump.
- A snapshot is marked publishable only when it is both pseudonymous **and**
  cluster-sharded (below). An unsalted local export is refused.

**When a published snapshot is reused.** Its manifest records the conditions it
was produced under, split by whether they can change a verdict:

- *Hard* — plugin commit, corpus fingerprint, option fixture, classification
  harness, shard mode. Folded into one `hard_fp`; any mismatch means the
  snapshot describes a different experiment, and the baseline is re-classified.
- *Soft* — WordPress core version, PHP minor, worker count. Recorded and
  reported as a warning in the job summary, but not disqualifying. Pin
  `wp-version` in the consuming workflow so core releases do not quietly drift;
  bumping it invalidates every snapshot, which is the point.

Corpus identity is computed from the *imported rows* (an order-independent
checksum), not from the dump file: a dump regenerated by `mysqldump` has
different bytes every time, which would make every lookup miss silently — and a
miss only looks slow, never broken.

**Determinism is a precondition.** Some rules read the site database as it fills:
`DbSpam` matches a previous spam entry by IP/e-mail/URL, and `ApprovedEmail`
trusts an address that already has an approved comment. Under plain
`MOD(comment_ID)` sharding those comments land on racing workers, so a verdict
can depend on interleaving. That is invisible when both sides run in the same
job, but a stored baseline compared against a fresh HEAD would turn it into
phantom flips on a gating check. The runner therefore builds the identity-cluster
shard map (`lib/build-shards.php`) and classifies with it: whole clusters stay on
one worker in `comment_ID` order, which makes the result deterministic *and*
independent of the worker count.

Set `use-baseline-snapshot: 'false'` to force a full two-pass run.

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