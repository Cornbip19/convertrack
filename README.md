# Convertrack — Click & Conversion Analytics

A self-hosted WordPress plugin that tracks **clicks on every button and link**, measures **page conversion**, and shows **how many visitors are on your site right now** — built to scale to large, high-traffic sites and to **update itself from this GitHub repository**.

## What it does

- **Click tracking** — configured button-like clicks store a bounded static label and structural path; heatmap-only clicks store coordinates and a structural path without arbitrary text, href parameters, or form values.
- **Conversion measurement** — mark elements (e.g. `.cvtrk-convert`) or destination URLs (e.g. `/thank-you`) as conversions and watch conversion rate per page.
- **Live visitor count** — a real-time counter of how many distinct visitors are currently on the site, and what they're viewing.
- **Search keywords** — optional reporting for supported UTM terms, WordPress site searches, and visible search-referrer queries.
- **Scales** — events are batched in the browser via `navigator.sendBeacon`, written in bulk, and rolled up into a compact daily-aggregates table by a background job so dashboards stay fast at any size.
- **Privacy-friendly** — form/editable values are never read, URL queries are stripped by default, IPs are not stored, and GPC, WordPress Consent API, Do Not Track, role, and URL exclusions are supported.
- **Google Index Monitor** - optional Search Console OAuth integration that scans sitemaps and checks URL indexing status in background batches.
- **404 Monitor** - captures real frontend 404s, recommends likely destinations, and creates internal 301 redirects only after approval or explicit high-confidence automation.

## Architecture

| Layer | File(s) |
|------|---------|
| Bootstrap | `convertrack.php` |
| Data + schema + rollups | `includes/class-database.php` |
| Settings | `includes/class-settings.php` |
| Ingestion (validation, tokens, atomic quotas) | `includes/class-collector.php`, `includes/class-ingestion-guard.php` |
| Presence / live count | `includes/class-presence.php` |
| REST API | `includes/class-rest-controller.php` |
| Front-end tracker | `public/js/convertrack.js` |
| Background jobs | `includes/class-cron.php` |
| Admin dashboard | `includes/class-admin.php`, `admin/` |
| Google Index Monitor | `includes/gsc/`, `admin/views/gsc-index-monitor.php` |
| 404 Monitor | `includes/404-monitor/`, `admin/views/404-monitor.php` |
| GitHub self-updater | `includes/class-updater.php` |

Custom tables store raw `events`, live `sessions`, and pre-aggregated rollups for pages, sources, countries, and search keywords.
Google Index Monitor uses separate `convertrack_gsc_*` options and tables so analytics data and tracking behavior stay isolated.
404 Monitor uses separate `convertrack_404_*` options and tables for detected 404s, internal redirects, cached valid URLs, and module logs.

## Search & SEO: Indexing

Open **Convertrack -> Search & SEO -> Indexing** to add a Google OAuth Client ID/secret, Search Console property URL, sitemap URL, quota limit, batch size, and selected post types. The feature is disabled until configured and connected. It uses the URL Inspection API for normal URLs, sitemap resubmission for normal WordPress pages, and only calls the Google Indexing API for URLs explicitly marked eligible by code.

## Search & SEO: Keyword Opportunities

Open **Convertrack -> Search & SEO -> Keyword Opportunities** to turn real Search Console query data into on-page SEO recommendations. It reuses the Indexing OAuth connection and property (the `webmasters` scope already grants Search Analytics access, so no reconnect is required) and syncs query/page performance rows in the background on Action Scheduler with a WP-Cron fallback.

Each keyword is matched to the page receiving its impressions, classified by type and intent from offline filterable word lists, and checked against the page content — SEO title, meta description, H1, headings, first paragraph, body, image alt text, internal anchors, and URL slug — with SEO title/description read from Rank Math, Yoast, AIOSEO, or SEOPress when present. Every keyword gets a 0-100 opportunity score and natural, anti-keyword-stuffing recommendations (improve title/meta, add missing keywords, promote body-only keywords into headings, answer question queries in an FAQ, push page-2 rankings with internal links). The dashboard offers summary KPIs, a sortable/filterable keyword table with CSV export, bulk re-analyze, and a per-page detail view. Content is re-analyzed automatically when a post is edited. The feature is disabled until enabled and only reads existing published content — it never writes to posts or third-party SEO tables.

## Broken URLs

Open **Convertrack -> Broken URLs** to capture frontend 404 requests, refresh valid URL candidates from WordPress objects and sitemaps, and review redirect recommendations. The default mode is recommendation/manual approval; automatic internal 301 redirects are disabled unless the administrator opts into high-confidence automation.

The review queue sorts by what needs doing: rows with a suggestion ready to approve first, then manual review, then newly detected URLs, with already-redirected and ignored rows last. Hit count and recency order rows within each group. The status filter offers grouped **Needs action** and **Redirected** options as well as each individual status, and grouped filters carry through to the CSV export.

Suggestions resolve in tiers, strongest evidence first:

1. The exact path already exists as a known-good URL.
2. The slug was renamed, per WordPress's own `_wp_old_slug` / `_wp_old_date` post meta. Note that core records this only for published, non-hierarchical post types, so it never covers pages, and core's own `wp_old_slug_redirect()` already handles the simple case before the request is recorded. This tier mainly serves the existing backlog, custom post types, permalink structure changes, and paths that never resolve to a `name` query var.
3. The path matches once mechanical decoration is stripped: `/page/N/`, `/comment-page-N/`, `/amp/`, `/embed/`, `/trackback/`, `/feed/`, `index.php`, a `.html`/`.php` extension, or a leading date. Hierarchical content is resolved against the page tree.
4. A known-good URL shares the slug. Scored below the automatic-redirect threshold so a slug collision under a different parent always gets a human look.
5. The slug belongs to a draft or trashed post. This yields no destination unless a published ancestor exists, but marks the row as needing review rather than handing it an unrelated guess.
6. Similarity scoring over a bounded candidate set, combining token coverage with character distance so both longer correct targets and slug typos are matched. Capped below the slug tier, so a guess can never outrank a real match, and archives are capped lower still so they surface only as a review hint.

A fallback destination is used only when no tier produces anything at all; it never replaces a real match, and it is empty by default.

The module does not write to Redirection, Rank Math, Yoast, SEOPress, or `.htaccess`. When those tools are detected, Convertrack shows read-only visibility where safely readable and blocks duplicate internal redirects for sources already handled elsewhere.

404 Monitor stores the requested URL/path, referrer URL, a hashed user-agent value, timestamps, hit counts, recommendations, and internal redirect hit counts in this site's database. It does not store IP addresses and does not contact third-party APIs for 404 monitoring.

## Self-updating from GitHub

The plugin checks this repository's **Releases** and offers updates through the normal WordPress Updates screen.

To ship an update:

```bash
# bump the Version header in convertrack.php and the Stable tag in readme.txt, then:
git commit -am "Release 1.0.1"
git tag v1.0.1
git push origin main --tags
```

Pushing a `v*` tag triggers `.github/workflows/release.yml`, which packages a correctly-structured `convertrack.zip` and attaches it to a GitHub Release. Connected sites see the update within a few hours (or immediately via **Dashboard → Updates → Check again**).

Private repositories are supported — add a fine-scoped personal access token in **Convertrack → Settings → GitHub token**.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later
