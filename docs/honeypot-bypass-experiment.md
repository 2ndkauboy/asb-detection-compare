# Experiment: detection rate when the honeypot is bypassed

**Status:** paused — results from a 1-in-10 sample are in, a full-corpus run is
blocked on a performance problem that is not yet diagnosed.

## The question

Antispam Bee's honeypot catches spam by *how it was submitted*, not by what it
says. On the reference corpus it does almost all of the work:

| 2.x reason (as recorded live)      | count       | share       |
|------------------------------------|-------------|-------------|
| **`css`** (honeypot / decoy field) | **324,948** | **97.63 %** |
| (ham — nothing flagged it)         | 6,755       | 2.03 %      |
| `empty`                            | 634         | 0.19 %      |
| `localdb`                          | 215         | 0.06 %      |
| `manually`                         | 210         | 0.06 %      |
| `title_is_name`                    | 62          | 0.02 %      |
| `regexp`                           | 10          | 0.00 %      |

Those `css` comments map 1:1 onto `asb-invalid-request` in 3.x — the same
mechanism under a new name.

So the corpus tells us very little about the **content** rules. The question this
experiment answers:

> If that spam had been submitted *normally* — content in the real comment field,
> honeypot untouched — how much of it would the content rules still catch?

The point is not to score the honeypot. It is to find **new detection patterns**
in the 80 % that currently slips past everything else, while checking those
patterns against the ham set so they do not introduce false positives.

## Method

`lib/driver.php` normally replays a `css` comment through the *visible* decoy
field, faithfully reproducing how it arrived:

```php
if ( 'css' === $reason ) {
    $_POST['comment'] = wp_slash( $content );      // visible decoy
} else {
    $_POST['comment']      = '';
    $_POST[ $secret_name ] = wp_slash( $content ); // real field
}
```

The experiment patches that so **every** comment takes the second branch. The
honeypot then passes and the content rules have to decide. Run against both
`master` (2.x) and `origin/v3` (3.x), then cross-tabulate against each comment's
original 2.x reason.

Constraints worth remembering:

- **DbSpam stays off**, as in the harness. With it on, the first comment from an
  address is judged on content and every later one is caught as a repeat — that
  measures the corpus's redundancy, not content coverage. Results are therefore a
  **lower bound**; production would additionally catch repeats via `localdb`.
- 2.x needs its own option fixture: flat keys (`regexp_check`, `bbcode_check`,
  `spam_ip`, `already_commented`, `reasons_enable`) under option `antispam_bee`,
  not the 3.x nested set. **`reasons_enable` defaults to `0`** — without setting
  it, no reasons are recorded at all.

## Results so far (1-in-10 sample, 32,506 `css` comments)

| | v2 (`master`) | v3 (`origin/v3`) |
|---|---|---|
| caught as spam | 5,443 (**16.7 %**) | 6,199 (**19.1 %**) |
| **would slip through** | 27,063 (**83.3 %**) | 26,307 (**80.9 %**) |
| `bbcode` | 3,711 (11.4 %) | 3,711 (11.4 %) |
| `regexp` | 1,732 (5.3 %) | 2,561 (7.9 %) |

Two things stand out:

1. **~81 % of real spam would be missed** by the content rules alone. That is the
   pool to mine for new patterns.
2. **v3's regexp set is materially better** — 2,561 vs 1,732 catches, +48 %,
   worth +2.4 points overall. `bbcode` is identical, so the whole improvement is
   in the patterns.

Sampling error at this size is roughly ±0.5 %, so the full run would sharpen the
figures but is unlikely to change the conclusion.

## Why it was paused — and why that reason is gone

The configuration ran **15–20× slower** than a normal comparison: ~582
comments/min aggregate against ~9,245, i.e. ~14 hours per arm on the full corpus.

**This has been fixed.** The cause was a blocking reverse-DNS lookup in
WordPress core, not anything about the bypass configuration.

`wp_notify_moderator()` and `wp_notify_postauthor()`
(`wp-includes/pluggable.php`) call `gethostbyaddr()` on the commenter's IP to put
a hostname in the mail body. Spam IPs rarely have a PTR record and often have no
responding authority at all, so the resolver blocks for its entire retry budget —
**measured at 63 s for a single comment**, at 8 ms of CPU. `pre_wp_mail` stopped
the mail being *sent*, but not being *built*, so the lookup still happened.

Critically, notifications only run for comments that are **not** classified as
spam. In the normal configuration ~98 % of comments are spam and never reach the
notification path; under the bypass most do. That is the entire asymmetry.

Per-comment timing over the small corpus, before the fix:

- median ham comment 10.8 ms, but **mean 244 ms**
- **195 of 9,750 comments took ≥ 1 s**, accounting for **1,323 s of 1,499 s** of
  classification time — while burning **1 s of CPU between them**
- half of all time went to the slowest **81 comments (0.8 % of the corpus)**
- cost was independent of content length: a 60-byte comment took 62 s

`scripts/ci-compare.sh` now sets `comments_notify` and `moderation_notify` to
`0`. Both are checked before the `gethostbyaddr()` call, so the lookup is skipped
entirely. A small-corpus comparison went from **16 min 37 s to 45 s** with
byte-identical verdicts (9,084 compared, 0 flips, the same 2 reason-only
differences).

Ruled out along the way, and still worth recording:

- **The database** — statement logging over a steady-state window put total DB
  time at **~5.9 s of 360 worker-seconds (1.6 %)**. The two heaviest scans
  (`comment_date_gmt` flood check at 717,793 rows examined, and the
  `comment_approved` count at 893,078) cost 2.2 s combined.
- **`wp_update_comment_count_now()`** — its `COUNT(*)` *is* a full table scan
  (`EXPLAIN` gives `type=ALL`, `key=NULL`, because `comment_approved='1'` stops
  being selective once most comments are ham), but `wp_defer_comment_counting(
  true )` changed throughput not at all: 391/min with it, 582/min without.
- **`ApprovedEmail`** — 0.14 ms per lookup, uses `comment_author_email`.
- **Outbound HTTP** — 6 requests in an entire pass. `LangSpam`, `CountrySpam`
  and `ValidGravatar` are not enabled in the options fixture.
- **The rules themselves** — timing every rule inside `Rules::apply()` for a
  63 s comment totalled **under 1 ms**. The stall was outside the plugin.
- **Contention between workers** — a re-run of ten known-slow comments with a
  *single* worker still took ~63 s each.

Two earlier hypotheses recorded here were wrong and are retracted: that six
workers achieving what one does indicated a *shared, serialised bottleneck*
(single-worker re-runs of the same comments were just as slow), and that the
`comment_previously_approved` lookup in `wp_allow_comment()` / `check_comment()`
was the next suspect (it is 0.25 ms, examining 11 rows).

Aside, for the plugin itself: this is core behaviour, not an Antispam Bee bug.
But it does mean a real site with comment notifications enabled can block for up
to a minute inside a comment submission whenever the commenter's IP has no
resolvable PTR record.

## To resume

1. Confirm or rule out the moderation path: `EXPLAIN` that query at ~100k rows,
   then re-measure with `comment_previously_approved` / `comment_moderation`
   forced off via `pre_option_*` filters. Safe for this harness — the snapshot
   only records `spam` vs not-spam, so a non-spam comment's approved/pending
   status is irrelevant.
2. With throughput restored, run the full corpus for both versions.
3. Mine the ~81 % that slips through for candidate patterns, and validate each
   against the 6,755 ham comments — that set is the false-positive check, and is
   the reason to keep all of it in any reduced corpus.

## Related

The full-corpus comparison already shows that 3.x flags **1,946 comments that 2.x
let through** (1,554 via `bbcode`, 315 via `regexp`), which is the same question
seen from the other side and deserves its own look.
