# Convertrack — Comprehensive QA & Usability Testing Prompt

> Paste everything below this line into a fresh Claude Code session opened at the plugin root
> (`wp-content/plugins/convertrack`), or hand it to a human QA engineer as a test charter.

---

## Role and mission

You are a senior QA engineer and usability specialist performing a full-depth quality pass on
**Convertrack**, a WordPress click/conversion analytics plugin with five admin destinations
(Dashboard, Analytics, Search & SEO, Broken URLs, Settings). Your mission is to verify that every
feature **produces correct, trustworthy output** — not merely that screens render. Numbers must
reconcile across layers, recommendations must be right, and destructive actions must be safe.

Two areas carry the highest priority and the strictest quality bar:

1. **Broken URLs (404 Monitor)** — capture accuracy, recommendation quality, redirect correctness.
2. **Indexing (Google Index Monitor + Keyword Insights)** — sitemap scanning, indexing status
   accuracy, keyword recommendation quality.

Work feature by feature. For each, execute the functional checks, then the output-quality checks,
then the usability review. Record findings as you go using the report format at the end. Do not
fix anything unless explicitly asked — this engagement produces a findings report.

## Ground rules

- **Never test against a production site.** Use the local dev site only.
- Prefix every row you insert directly into the database with the marker `cvtrk-test-` (in a text
  column such as element label, URL path, or keyword) so cleanup is one DELETE per table.
- Verify at the **lowest layer first** (DB row / REST JSON), then the UI. When the UI disagrees
  with the data layer, that is a finding; when both agree but are wrong, that is a worse finding.
- Reproduce every finding twice before recording it. Include exact steps, expected vs actual, and
  the layer where truth diverged.
- Time-box exploratory tangents to 15 minutes; log a `FOLLOW-UP` item instead of going deeper.

## Environment setup

- Local by Flywheel site; there is no `php` on PATH. Bundled CLI:
  `C:\Users\Admin\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe`
  (needs `-d extension_dir=...\ext -d extension=mysqli -d extension=mbstring -d extension=openssl`
  to bootstrap WordPress; MySQL listens on `127.0.0.1:10035`, user/pass `root`/`root`, DB `local`).
  Confirm the site is running in the Local app before starting (check `Get-Process mysqld`).
- Full-WordPress harness: replicate `wp-config.php` constants in a CLI script, `require
  wp-settings.php`, then call REST handlers directly with `new WP_REST_Request(...)`. This is the
  fastest way to exercise every endpoint deterministically.
- UI verification without a browser session: build a static HTML repro that loads the real
  `admin/js/admin.js` with a stubbed `window.fetch` and `window.ConvertrackAdmin` config, then run
  headless Edge (`msedge --headless=new --disable-gpu --virtual-time-budget=8000 --dump-dom
  "file:///C:/..."`) and inspect the DOM dump. Console errors must be captured via a
  `console.error` hook written into the page.
- Seed baseline data with the built-in demo seeder: **Settings → Tools → Seed demo data**
  (admin-post action `convertrack_seed_demo`), or generate organic data by driving the public
  REST endpoints (`POST /wp-json/convertrack/v1/collect`, `/heartbeat`) with realistic payloads.
- Google APIs must be **mocked, never called live**: hook `pre_http_request` and return canned
  Search Console / indexing responses. Build both happy-path and failure fixtures (quota exceeded,
  expired token, malformed JSON, 5xx).
- Run the automated suites first and record their status as your baseline:
  `npm test` (tracker + admin client tests) and the PHPUnit integration suite
  (`tests/bootstrap.php`; needs `WP_TESTS_DIR` and the PHPUnit phar — see repo CI for the recipe).

---

## Part 1 — Data pipeline integrity (foundation for everything else)

The single most important invariant: **the same number must appear at every layer.**

1. Generate a known, exact quantity of traffic: e.g. 100 pageviews, 40 clicks (10 on a marked
   button), 5 conversions, across 3 pages, 2 sources, 2 countries, from N distinct visitor IDs.
2. Verify counts in raw tables (`wp_convertrack_events`, `_sessions`).
3. Trigger the rollup cron (`convertrack_hourly`) and verify the daily aggregate tables
   (`_daily`, `_sources`, `_geo`, `_search_terms`, `_visitor_days`, `_session_days`) sum to the
   same totals — no double counting, no drops, unique-visitor math correct.
4. Verify `/stats/summary?range=7` returns those exact totals; verify each dashboard region and
   each CSV export (buttons, pages, sources, keywords, countries, daily) agrees.
5. Re-run the rollup twice more — totals must not change (idempotency).
6. Ingestion guard: confirm per-IP/visitor/site budgets actually reject over-quota traffic with
   429 + `Retry-After`, that rejected events do NOT appear in any table, and that a tokenless
   "legacy" payload is accepted only inside the compatibility window.
7. Edge cases: event with 10KB label text, URL with 2,000-char query string, emoji/RTL/CJK in
   labels and keywords, visitor ID collision, clock skew (event dated tomorrow), payload with
   unknown extra fields, malformed JSON body (must 4xx, never 500).

## Part 2 — Click, conversion, presence, heatmap tracking (frontend)

- **Click capture:** buttons, links, `input[type=submit]`, block buttons, elements inside
  shadow-DOM-free nested markup. Verify selector + label recorded match the clicked element.
  Rapid double-click = configurable/deduped? Middle-click and ctrl+click behavior documented?
- **Conversions:** element-goal and destination-URL goal both fire exactly once per session rule;
  conversion rate per page = conversions ÷ pageviews within tolerance.
- **Presence:** live counter reflects N concurrently open sessions within one heartbeat interval;
  closing a tab drops the count after the timeout; heartbeat traffic is cache-exempt.
- **Heatmaps:** click coordinates land on the correct page-relative position at 3 viewport widths
  (mobile/tablet/desktop buckets if supported); scroll-depth distribution sums to 100%; a page
  with <10 events shows an honest low-data state rather than a misleading map.
- **Consent & privacy (fail-closed is the requirement):** DNT header, GPC signal, denied WP
  Consent API `statistics` purpose, and `convertrack_skip_tracking` filter — each must fully stop
  collection (zero rows written, not just UI hiding). Form values must never be read. Query
  strings stripped by default; allowed-param whitelist works; credential/email/order params are
  stripped even when whitelisted.
- **Delivery:** batching + `sendBeacon` on pagehide — kill the tab mid-session and verify the
  final batch arrives; offline → online transition retries once (per tracker tests) without
  duplicating events.

## Part 3 — Dashboard & Analytics UI

- Every region (KPIs, trend, hourly, engagement, top buttons/pages/sources/search terms/countries,
  event timeline, needs-attention health, live sessions) in four states: loading, populated,
  empty, and error (kill the REST route to force it). One broken region must show a scoped,
  retryable error and never blank unrelated regions.
- Range selector changes must update every region consistently (no region stuck on the old range)
  and survive URL sharing (range in query param).
- Content & CTAs paginated view: search, sort, page-size changes preserve filters; totals row
  matches the sum of all pages, not the visible page.
- Journeys/funnels: paths, dropoffs, sources, buttons reconcile with raw events for a scripted
  session sequence you control end to end.
- Timezone traps: events at 23:50 local appear on the correct local day in charts and CSVs.

## Part 4 — 🔴 Broken URLs / 404 Monitor (priority)

**Capture accuracy**
- Hit 404s as: plain missing page, missing page with query string, with referrer, with encoded
  UTF-8 path, with 2,000-char path, trailing-slash variant, uppercase variant. Each recorded once
  with correct path/referrer/hit-count; repeat hits increment, not duplicate.
- Must NOT capture: admin 404s, REST 404s, feed 404s, static asset 404s (confirm intended scope),
  bot floods beyond the rate-limit budget (verify `_404_rate_limits` engages).

**Recommendation quality — measured, not eyeballed.** Build a fixture site of ~20 real pages,
then request ~30 broken variants (typos, old slugs, moved hierarchy, plural/singular, hyphen/
underscore swaps, and 5 garbage paths with no sensible match). Score the matcher:
- Report top-1 accuracy for matchable paths. Below ~70% is a quality finding; document every miss
  with the expected vs suggested destination.
- The 5 garbage paths must yield NO confident recommendation (false-positive check). A confident
  wrong suggestion is worse than none — treat any confident garbage match as a bug.
- Confidence scores must be monotonic: obviously-better matches score higher than weak ones.

**Redirect correctness**
- Approving a recommendation creates an internal 301 that actually fires on the frontend (curl
  the source path: correct status, correct Location, exactly one hop).
- Loop and chain protection: attempt A→B then B→A (must be rejected); build a chain at the
  validation limit and one past it. HTTPS destination downgrade to HTTP must be rejected.
- External destinations must be rejected ("limited to this site").
- Auto-redirect mode: only fires above the high-confidence threshold; verify a mid-confidence
  match stays in the manual review queue.
- Redirect hit counts increment; destination health check flags a destination that starts
  returning 404 after the rule was created.
- Deleting/deactivating a rule stops the redirect immediately (cache check).

**Operational**
- Sitemap-sourced valid-URL refresh: correct candidate set after adding/removing pages; spike
  alert fires on a simulated 404 storm and not on normal traffic; retention pruning deletes only
  out-of-window rows; CSV export columns complete and injection-safe (`=`, `@`, `+`, tab-prefixed
  values must arrive neutralized); compatibility warning appears when a known redirect plugin is
  active and writes nothing to its tables.
- REST permission audit: every 404-monitor route requires `manage_options` except none — confirm.

**Usability (404 workflows)**
- Persona task: "A shop owner sees 404 spikes after a redesign. Starting from the Dashboard,
  find the worst broken URL, understand where visitors come from, and fix it." Count clicks,
  dead ends, and moments where the next action is unclear. The approve-redirect flow must make
  the destination obvious BEFORE approving and confirm success AFTER.
- Empty state must teach (what is this, why is it empty, what to do); bulk actions must announce
  results; a wrong approval must be visibly reversible.

## Part 5 — 🔴 Indexing / Google Index Monitor + Keyword Insights (priority)

All Google traffic mocked via `pre_http_request` fixtures.

**Connection & credentials**
- OAuth setup with invalid client ID/secret fails with a human-readable message, not a stack
  trace. Token refresh on expiry is transparent; a revoked token surfaces a re-connect prompt,
  and background jobs stop erroring loudly (bounded retries, clear status). Stored tokens are
  encrypted at rest (inspect the option value — must not be plaintext JSON).

**Sitemap scanning correctness** — fixtures to serve locally: plain sitemap, sitemap-index with
3 children, nested index, gzipped sitemap, sitemap with 50k URLs (performance + memory), XML with
BOM, malformed XML, sitemap with external-host URLs (must be ignored), redirecting sitemap URL
(SSRF rules: private/loopback destinations must be refused). After each scan: URL count in
`_gsc_index_queue` exactly matches fixture; re-scan is idempotent; removed URLs are handled per
design (verify what the design is and that the UI explains it).

**Indexing checks & quota**
- Batch processor: with a quota of N, exactly N inspections happen per window; the queue resumes
  where it stopped; a wedged job recovers via the owner-lock lease expiry (kill it mid-batch and
  confirm another run takes over after TTL).
- Status mapping: for each mocked verdict (indexed, not indexed, blocked by robots, noindex
  detected, error), the row status, dashboard tiles, and Needs Attention panel all agree. The
  "% indexed" figure must equal indexed ÷ total from the DB, not from a cache gone stale.
- Failure honesty: on quota-exceeded or 5xx fixtures, the UI must show "check pending/failed",
  never silently display stale status as fresh (check the last-checked timestamp is honest).

**Keyword Insights output quality**
- Sync fixture: 200 keyword rows with impressions/clicks/position. Verify stored rows match the
  fixture exactly (no rounding drift on CTR/position).
- Scoring: hand-construct cases where keyword A objectively beats B (higher impressions, worse
  position, page already ranks 8–15) and assert A scores higher; opportunities must not surface
  keywords the page already ranks #1 for.
- Recommendation grounding: for 10 sampled recommendations, open the target page and confirm the
  claim is true (e.g. "keyword missing from H1" — actually missing?). Any recommendation that
  references content the page does not have is a correctness finding.
- Filters (min impressions), sort, pagination, CSV export must all operate on the same filtered
  set (export must respect the active filter — regression-prone).

**Usability (indexing workflows)**
- Persona task: "An SEO freelancer connects a client's Search Console and wants to know: which
  important pages aren't indexed, and what should I fix first?" Time-to-first-insight, clarity of
  setup steps (credentials flow is the hardest part of the plugin — note every step where a
  non-developer would stall), and whether error states say what to DO next.

## Part 6 — Settings, data management, lifecycle

- Every setting: change → save → confirm persisted → confirm behavioral effect (not just stored).
  Especially: master enable toggle (must stop all collection), retention windows (prune exactly
  out-of-window rows), search-keyword and geo opt-ins (default OFF; enabling starts collection,
  disabling stops it and the privacy-policy suggestion text updates).
- Danger zone: Reset analytics, Delete operational data, Privacy scrub — each behind
  nonce + `manage_options`, each deletes exactly what its label says (diff table row counts and
  option lists before/after against `includes/class-manifest.php` as the source of truth), and
  each is impossible to trigger via GET or cross-site request.
- Seed demo: rows clearly marked as demo; reset removes all of them.
- Deactivate → reactivate: no data loss, crons rescheduled once (no duplicate schedules).
- Uninstall (on a disposable copy): every `convertrack_*` table/option/transient/cron gone,
  nothing else touched.
- Updater surface: with an invalid GitHub token, update checks fail closed with a clear message;
  Site Health section reports schema + updater status truthfully.

## Part 7 — Cross-cutting sweeps

- **Caching:** full-page cache simulation — tracker works on cached pages; ingestion endpoints
  send no-store headers; admin data is never cached stale after a range change.
- **Performance:** with 1M synthetic event rows, dashboard summary must return in a defensible
  time (measure; >2s is a finding) and the frontend tracker must add no long tasks >50ms.
- **Accessibility (admin):** keyboard-only pass through all five destinations — every action
  reachable, visible focus, tables sortable via keyboard, dialogs trap focus, live regions
  announce async results; automated axe scan on each screen; color-independent status badges.
- **i18n:** with a pseudo-locale, scan all five screens for hardcoded English (strings not in the
  localize array or missing translator functions); dates respect the WP locale/timezone.
- **Compatibility matrix:** PHP 7.4 and 8.3; WP 5.8 and latest; single-site and multisite
  (network activation provisions all sites; a new subsite gets provisioned automatically).
- **Security spot-checks:** every admin REST route rejects a subscriber; nonces on every
  admin-post handler; no raw `$_GET`/`$_POST` echoed anywhere in `admin/views/`; CSV injection
  neutralized in every exporter; SQL placeholders in every new query you sample.

## Severity taxonomy

- **S1 Blocker** — data loss/corruption, security hole, wrong redirect fired, silent tracking
  failure, fatal error.
- **S2 Major** — wrong numbers anywhere users see them, wrong recommendation confidently
  presented, feature unusable for its persona task.
- **S3 Minor** — degraded UX with a workaround, inconsistent state handling, unclear copy.
- **S4 Polish** — cosmetic, wording, alignment.
- **UX-n** — usability findings from persona tasks, rated by task-blocking severity.

## Report format

Produce `tests/QA-REPORT-<date>.md` containing:

1. **Executive summary** — ship/no-ship recommendation, counts by severity, the three most
   important findings in plain language.
2. **Data-integrity reconciliation table** — one row per metric: generated → raw tables → rollups
   → REST → UI → CSV, with ✓/✗ per layer.
3. **404 recommendation scorecard** — top-1 accuracy, false-positive count on garbage paths, and
   the full miss list.
4. **Indexing accuracy scorecard** — status-mapping table (fixture verdict vs UI display) and
   keyword recommendation grounding results (n sampled / n correct).
5. **Findings register** — ID, severity, feature, title, reproduction steps, expected vs actual,
   layer of divergence, evidence (query/response/screenshot path).
6. **Usability narrative** — per persona task: completion, time, click count, stall points, quotes
   of confusing copy, and one concrete improvement per stall.
7. **Coverage map** — every section above marked tested / partially tested / blocked, with reasons.
8. **Cleanup confirmation** — the `cvtrk-test-` purge queries you ran and row counts removed.

## Exit criteria

The pass is complete when: every Part 1–7 section is tested or explicitly blocked-with-reason;
both priority scorecards (404 + indexing) are filled with measured numbers; zero S1 findings
remain unreported; and the test data cleanup is confirmed. Do not mark complete on partial
coverage — an honest "blocked" is worth more than silent omission.
