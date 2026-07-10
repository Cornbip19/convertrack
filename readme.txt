=== Convertrack — Click & Conversion Analytics ===
Contributors: Cornbip19
Tags: analytics, click tracking, conversion, heatmap, real-time
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track clicks on every button and link, measure page conversion, and see how many visitors are on your site right now — privacy-friendly and built to scale.

== Description ==

Convertrack answers three questions about your site:

1. **Are visitors clicking?** Every click on a button, link, input or block button is recorded with its label, location and target.
2. **Is the page converting?** Mark elements or destination URLs as conversions and watch the conversion rate per page.
3. **Who's here now?** A live counter shows how many visitors are currently on the site, and what they're viewing.
4. **Where do they look?** Per-page heatmaps show a click map and a scroll-depth breakdown — how far down each page visitors actually browse.
5. **Which searches bring them in?** Optional search keyword tracking shows supported UTM terms, site searches, and visible search-referrer queries.

6. **Are important URLs indexed?** The optional Google Index Monitor connects to Search Console, scans sitemaps, and checks URL indexing status in background batches.
7. **Which 404s need redirects?** The optional 404 Monitor captures missing frontend URLs, recommends likely destinations, and creates internal 301 redirects only after approval or explicit high-confidence automation.

**Built for large sites**

* Clicks are batched in the browser and delivered with `navigator.sendBeacon`, so tracking never blocks navigation.
* Events are written in bulk and rolled up into a compact daily-aggregates table by a background job, so dashboards stay fast no matter how many pages or events you have.
* Configurable raw-data retention keeps the database lean; long-term trends live in the aggregates table.
* Per-IP rate limiting, a bot filter, optional visitor sampling and full-page-cache compatibility keep ingestion cheap under heavy traffic.

**Privacy-friendly**

All analytics are stored in your site's own database. No IP addresses, names, or email addresses are collected; only a random identifier kept in the visitor's browser storage. By default no data is sent to any third-party service. "Do Not Track" is honored by default, you can exclude logged-in users, roles, or URLs, and consent-management plugins can gate tracking via the `convertrack_skip_tracking` filter.

The only optional exception is **Visitor location** (off by default): when you turn it on, a visitor's IP address is sent to a geolocation service to look up their country. The IP is never stored — only the 2-letter country code — and a CDN country header (e.g. Cloudflare) is used first when available to avoid the external call.

**Search keyword tracking** is also optional and off by default. When enabled, Convertrack stores supported UTM term values, this site's search query parameter, and search-engine referrer query strings when browsers provide them. Modern search engines often hide organic queries, so those visits may appear as not provided.

**Google Index Monitor** is optional and off until an administrator configures OAuth credentials and connects Google Search Console. When enabled, it sends configured site URLs and sitemap URLs to Google Search Console APIs for indexing inspection and sitemap submission. OAuth tokens are stored encrypted in this site's database, and queue/log data stays in separate `convertrack_gsc_*` tables.

**404 Monitor** is optional and stores its data locally in separate `convertrack_404_*` tables. It records the requested 404 URL/path, referrer URL, a hashed user-agent value, timestamps, hit counts, recommendations, and internal redirect hit counts. It does not store IP addresses and does not contact third-party APIs for 404 monitoring. It detects common redirect plugins for visibility/conflict warnings but does not write to their tables or to `.htaccess`.

**Updating**

Installed from WordPress.org, Convertrack updates through your dashboard like any other plugin. The separately distributed self-hosted build can additionally update itself from its GitHub Releases.

== Privacy ==

Convertrack is a first-party analytics tool: every event (pageview, click, traffic source, presence heartbeat) is stored only in this site's own database. It does not collect or store IP addresses, names, or email addresses. A random, non-identifying visitor ID is stored in the browser's local storage to distinguish repeat visits.

By default no visitor data is transmitted to any external or third-party service. The optional **Visitor location** feature (disabled by default) is the single exception: when enabled, a visitor's IP address is sent to a geolocation service (ip-api.com) only to determine their country. The IP address is not stored — only the resulting two-letter country code. If you enable it, disclose this in your privacy policy and gate it behind consent where required.

By default, visitors whose browser sends a "Do Not Track" signal are not tracked. To require explicit cookie/consent before any tracking, return `true` from the `convertrack_skip_tracking` filter until your consent banner is accepted. A suggested privacy-policy paragraph is added to **Settings → Privacy** for inclusion in your site's policy.

The optional **Search keyword tracking** setting stores supported search terms locally in this site's database. It does not contact search providers or Google Search Console.

The optional **Google Index Monitor** feature contacts Google Search Console APIs only after an administrator connects it. It sends site URLs for indexing inspection and sitemap submission, and it does not run on frontend page loads.

The optional **404 Monitor** feature runs locally. It stores requested missing URLs, referrers, hashed user-agent values, recommendation data, and internal redirect hit counts in this site's database. It does not store IP addresses and does not send 404 monitoring data to third-party services.

== Installation ==

1. Upload the `convertrack` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.
3. Open **Convertrack → Settings** to configure tracked elements, conversions and retention.
4. Watch live data on **Convertrack → Overview**.

For high-traffic sites: (1) disable WP-Cron and trigger `wp-cron.php` from a real system cron so rollups and cleanup run on schedule, and (2) run a persistent object cache (Redis or Memcached) — Convertrack then keeps its rate-limit counters and short stat caches in memory instead of the database.

Optional: open **Convertrack -> Google Index Monitor** to configure Google OAuth, connect Search Console, scan the sitemap, and run indexing checks.

Optional: open **Convertrack -> 404 Monitor** to capture frontend 404s, refresh valid URL candidates, review recommendations, approve internal 301 redirects, export CSVs, and configure retention or spike alerts.

== Frequently Asked Questions ==

= Does it work with caching plugins? =
Yes. The tracker loads as a static script and the ingestion endpoints are public and cache-exempt, so a full-page cache does not break tracking.

= Where is data stored? =
In this site's own database: raw events, live sessions, and daily aggregate tables for pages, sources, countries, and search keywords. No third-party service is contacted for analytics.

= How do conversions work? =
A conversion is only counted once you define a goal in **Settings → Tracking**. There are two kinds: a **page reached** (add a path such as `/thank-you` or `/order-received` under "Conversion goal: pages reached" — a visit landing there counts), and a **button clicked** (add a CSS selector, or simply put the attribute `data-cvtrk-convert` on the button). Until at least one goal is set, your conversion count stays at zero even with plenty of traffic.

= Does the plugin collect personal data? =
No. It stores no IP addresses, names, or email addresses — only a random visitor identifier in the browser's local storage, plus anonymous interaction counts. All of it stays in your own database. Do Not Track is honored by default.

= Does it contact any external services? =
Not by default. The one optional exception is the **Visitor location** setting (off by default): when enabled, it sends each visitor's IP address to a geolocation service (ip-api.com) to resolve the country only — the IP is not stored. Leave it off and no analytics data leaves your site. (Separately, the optional self-hosted build distributed via GitHub queries the GitHub Releases API to check for plugin updates; the WordPress.org version does not.)

The optional **Google Index Monitor** also contacts Google Search Console APIs, but only after an administrator configures and connects it. It sends site URLs for indexing inspection and sitemap submission; it does not run on frontend page loads.

The optional **404 Monitor** does not contact external services for monitoring. It detects common redirect plugins and readable redirect tables for conflict visibility, but it does not modify third-party redirect rules or `.htaccess`.

= How do I require visitor consent before tracking? =
Return `true` from the `convertrack_skip_tracking` filter while consent has not been granted (most consent-management plugins expose a state you can check), then allow tracking once the visitor accepts.

== Changelog ==

= 2.2.2 =
* Restructured the Keyword Insights and 404 Monitor dashboards: page header with primary actions and last sync/scan date, summary cards, aligned filter grids, and a separated bulk-action bar (404 settings moved below the data).
* Fixed table overflow: long URLs now truncate with tooltips and text cells wrap instead of forcing horizontal scrolling.
* Added expandable "Details" rows to the 404 table (referrer, first/last detected, match reason, confidence, post type, errors) and clearer redirect-recommendation callouts on keyword page details.
* Fixed the Recommended Placements section of the keyword page detail never rendering, the keyword table/CSV export ignoring the minimum-impressions filter, 404 confidence missing its % sign, and a stuck sync poll disabling the Sync button indefinitely.
* Improved accessibility: labelled row checkboxes with select-all, keyboard-sortable column headers with aria-sort, visible focus rings everywhere, WCAG AA muted-text contrast, and status badges with a color-independent dot.
* Distinct loading, empty, and error states (with Retry) so failed requests are no longer shown as "no data", plus softer badges and calmer, more consistent styling across the admin.

= 2.2.1 =
* Upgraded heatmaps to capture all page clicks for click-map analysis, while keeping conversion/dashboard analytics limited to configured tracked clicks.
* Added separate heatmap and tracked-click totals plus confidence labels so low-sample heatmaps are easier to interpret.
* Improved heatmap empty/loading/error states and page selection handling so stale dots or skeletons are not left behind.
* Refined the admin interface with lighter typography, softer cards/tables/badges, more consistent spacing, and polished modal/table states.

= 2.2.0 =
* Added GSC Keyword Insights & Content Optimization: pulls real Search Console query/page data through the existing Google connection (no reconnect needed) and turns it into on-page SEO recommendations.
* Classifies each keyword by type and intent (branded, service, location, commercial, informational, transactional, question, long-tail, competitor, and more) using offline, filterable word lists.
* Analyzes the matched page's content — SEO title, meta description, H1, headings, first paragraph, body, image alt text, internal anchors, and URL slug — and reports whether each keyword is present, partially matched, missing, needs improvement, or overused. Reads SEO title/description from Rank Math, Yoast, AIOSEO, and SEOPress where available.
* Scores every keyword opportunity 0-100 (High / Medium / Low / Already optimized) from impressions, CTR gap vs. expected CTR for its position, ranking sweet spot, presence gaps, and intent.
* Generates natural, anti-keyword-stuffing recommendations: improve title/meta on high-impression low-CTR queries, add missing keywords to content, promote body-only keywords into headings, answer question queries in an FAQ, and push page-2 rankings with supporting content and internal links.
* New "Keyword Insights" dashboard with summary KPIs, branded vs non-branded split, top pages by opportunity, a sortable/filterable keyword table (type, intent, page, opportunity, presence, search, date range), CSV export, bulk re-analyze, and a per-page detail view with suggested placements, FAQ questions, and anchor texts.
* Background sync (daily/weekly/manual) and content analysis run on Action Scheduler with a WP-Cron fallback, using cached data, batching, row caps, and proper indexing so the site is never slowed down. Content is re-analyzed automatically when a post is edited.

= 2.1.0 =
* Added 404 Monitor as an isolated module with separate settings, tables, REST/admin-post handlers, cron jobs, dashboard view, CSV export, and uninstall cleanup.
* Captures real frontend 404 requests, normalizes ignored query parameters/patterns, and recommends destinations from WordPress content, public archives, taxonomies, and sitemap URLs.
* Adds conservative redirect handling: internal 301 redirects are created only after administrator approval or explicit high-confidence automation, with loop, duplicate, same-site, and destination validation.
* Shows read-only visibility/conflict warnings for common redirect tools such as Redirection, Rank Math, Yoast, SEOPress, and possible `.htaccess` rules without writing to third-party tables.
* Adds retention cleanup, valid URL cache refresh, recommendation processing, redirect hit tracking, and optional 404 spike email notifications.

= 2.0.3 =
* New "Needs Indexing (all stuck pages)" status filter: one combined view of every page Google knows about but has not indexed (Not Indexed, Crawled and Discovered but not indexed). Also available in the CSV export.
* New "Open in GSC" toolbar button: opens up to 10 of the currently listed URLs in Google Search Console tabs, each one click away from Request Indexing. Priority-flagged URLs sort to the top of the list.

= 2.0.2 =
* New "Notify Google" button on non-indexed URLs in the queue: sends an official Google Indexing API notification for that URL. Requires the Indexing API option in settings, the Web Search Indexing API enabled in your Google Cloud project, and a one-time reconnect to grant the permission. Note: Google officially supports this API for job-posting and livestream pages only; notifications for other content may be ignored (200 requests/day quota).
* Notified URLs move to a "Submitted via Indexing API" status with an automatic next-day recheck, and can be filtered in the queue.
* Row actions (Recheck, Ignore, Priority, Notify Google) now surface their error message instead of failing silently.
* Self-updater now selects the release package by name, so additional release assets can never break future updates.

= 2.0.1 =
Google Index Monitor reliability release: inspections now actually run after a scan, and every failure is visible and actionable.
* URL inspection now starts automatically after a sitemap scan, processed in small chunks with a live progress line; a background task continues the queue if you leave the page.
* The first scheduled background inspection runs within minutes instead of an hour after setup.
* Google's real error messages are now visible everywhere: a persistent status banner, per-URL details in the queue table, and full error details in the Activity Log.
* Inspection batches stop after repeated Google permission errors to protect your daily quota, with a clear explanation — including a direct enable link when the Search Console API is not turned on in your Google Cloud project.
* Reconnecting Google now auto-selects the Search Console property that matches your site's domain (domain properties preferred) when the setting is still the default or invalid.
* The property picker shows loading and error states with a Retry option instead of failing silently, and saving a property your account doesn't own warns immediately.
* Every URL row has an Inspect link that opens Google Search Console's inspection screen, where Request Indexing is one click away.
* Overlapping inspection runs are prevented by a lock, rows stranded by an interrupted batch recover automatically, and uninstall cleans up all monitor data.
Major release: a direct Google Search Console connection, a redesigned Google Index Monitor, richer chart controls, and a round of UI polish and hardening.
* Google Index Monitor now connects directly to Google with your own Google Cloud OAuth client (Client ID + Secret) instead of a hosted broker — enter your credentials, click Connect, and sign in to Google.
* Added a guided setup with the exact Authorized redirect URI to copy into Google Cloud.
* After connecting, pick your Search Console property from a dropdown of your verified properties.
* Tokens are refreshed and revoked directly with Google; the client secret and refresh token are stored encrypted.
* Redesigned the Google Index Monitor summary into a professional coverage panel: an index-coverage donut, an indexing-progress trend line (collected daily), and clickable status cards that drill into the matching URLs in the queue below.
* Overview activity chart: click a legend key to show/hide Pageviews, Clicks, or Conversions (the chart rescales to the visible series), and refined the line charts with thinner, cleaner lines.
* Refined the click heatmap rendering: smaller, tighter warm blobs with a cleaner falloff (no more oversized glowing circles) and small, subtle click dots.
* Replaced the dashboard header logo with a lightweight vector — admin screens load faster and stay crisp on high-DPI displays.
* Truncated dashboard labels and URLs now reveal their full text on hover.
* Fixed the active dashboard tab being clipped at the bottom.
* Heatmaps show clear guidance when a site has no page activity yet, instead of a perpetual loading state.
* Nudged the smallest chart axis and bar labels up for readability.
* Database errors are now logged when WP_DEBUG is enabled, so a failed schema migration is diagnosable instead of silently dropping events.
* Added a WordPress-version safety check so older cores fail gracefully with an admin notice.

= 1.6.0 =
* Added Google Index Monitor as an optional, isolated Search Console module with OAuth, sitemap scanning, URL Inspection batches, queue reporting, CSV export, and activity logs.

= 1.5.0 =
* Optional search keyword tracking: stores supported UTM terms, WordPress site-search terms, and visible search-engine referrer query strings when enabled.
* Added Search keywords reporting to Overview, Heatmaps, and CSV exports.
* Redesigned Heatmaps into a full-width viewer with clicked-element details and keyword details.
* Fixed heatmap overlay alignment while scrolling by keeping the page snapshot, heat layer, and click markers in the same scrollable coordinate space.

= 1.4.0 =
* Heatmaps now use an anonymous, script-disabled page snapshot instead of loading the live page as the logged-in admin.
* Click heatmaps now record element-relative coordinates and can render in element-anchored mode, with page-position fallback for older data.
* Heatmaps can be filtered by device type.
* Added a Funnels screen showing converting sessions, common paths before conversion, drop-off pages, converting sources, and pre-conversion button clicks.

= 1.3.0 =
* Conversions now work end to end: a fix ensures "page reached" goals (e.g. landing on /thank-you) are counted in the dashboard totals — previously only button-click goals were counted. The Settings screen now separates the two goal types ("pages reached" and "buttons clicked") with clear examples, and the Overview shows a hint when traffic exists but no conversion goal is set up.
* Visitor location (optional, off by default): records each visitor's country and adds a "Top countries" card and CSV export. When enabled, the IP is sent to a geolocation service (ip-api.com) to look up the country only and is never stored; a CDN country header is used first when present. Disclose in your privacy policy before enabling.
* Time on site: the live "who's on the site now" view now shows each visitor's location and how long they've been browsing, and the Overview has a new "Avg. time on site" stat.
* URL-based conversion clicks to internal pages are no longer double-counted (the destination pageview counts them); external conversion links still count on click.

= 1.2.0 =
* Page heatmaps: a new Heatmaps screen shows a per-page click map (where visitors click) and a scroll-depth breakdown (how far down the page they browse). The tracker now records click position and maximum scroll depth, stored anonymously as percentages of the page.

= 1.1.0 =
* Privacy & directory compliance: "Do Not Track" is now honored by default, a suggested privacy-policy paragraph is registered, and a documented `convertrack_skip_tracking` filter lets consent plugins gate tracking.
* The build now ships a WordPress.org-ready package that contains no self-update code (the directory handles updates); the GitHub self-updater remains in the self-hosted build only.

= 1.0.3 =
* Redesigned all admin views with a neutral, full-width, report-style interface: grayscale palette with a single charcoal accent, 1px hairline borders, square corners, and no shadows. Removed colored KPI tiles, the pulsing live dot, and rounded "pills" for a cleaner, professional look.

= 1.0.2 =
* Traffic sources: visits are now classified (Direct, Organic search, Social, Referral, Paid search, Newsletter) from referrer + UTM parameters, with a "Traffic sources" card on the dashboard.
* Period-over-period comparison: KPI cards show the % change vs. the previous equal-length window.
* CSV export of buttons, pages, traffic sources, and daily activity for the selected range.

= 1.0.1 =
* Redesigned admin dashboard: tabbed navigation, KPI cards, activity chart, and sortable buttons/pages tables.
* Buttons clicked are now shown in a dedicated, prominent table on the Pages & Buttons screen.
* Added Tools: insert sample data to preview the dashboard, and reset all data.
* Caching and batched cleanup improvements for high-traffic sites.

= 1.0.0 =
* Initial release: click tracking, pageviews, conversions, live visitor count, daily rollups, GitHub self-updating.
