=== Convertrack — Click & Conversion Analytics ===
Contributors: Cornbip19
Tags: analytics, click tracking, conversion, heatmap, real-time
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
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

**Updating**

Installed from WordPress.org, Convertrack updates through your dashboard like any other plugin. The separately distributed self-hosted build can additionally update itself from its GitHub Releases.

== Privacy ==

Convertrack is a first-party analytics tool: every event (pageview, click, traffic source, presence heartbeat) is stored only in this site's own database. It does not collect or store IP addresses, names, or email addresses. A random, non-identifying visitor ID is stored in the browser's local storage to distinguish repeat visits.

By default no visitor data is transmitted to any external or third-party service. The optional **Visitor location** feature (disabled by default) is the single exception: when enabled, a visitor's IP address is sent to a geolocation service (ip-api.com) only to determine their country. The IP address is not stored — only the resulting two-letter country code. If you enable it, disclose this in your privacy policy and gate it behind consent where required.

By default, visitors whose browser sends a "Do Not Track" signal are not tracked. To require explicit cookie/consent before any tracking, return `true` from the `convertrack_skip_tracking` filter until your consent banner is accepted. A suggested privacy-policy paragraph is added to **Settings → Privacy** for inclusion in your site's policy.

The optional **Search keyword tracking** setting stores supported search terms locally in this site's database. It does not contact search providers or Google Search Console.

The optional **Google Index Monitor** feature contacts Google Search Console APIs only after an administrator connects it. It sends site URLs for indexing inspection and sitemap submission, and it does not run on frontend page loads.

== Installation ==

1. Upload the `convertrack` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.
3. Open **Convertrack → Settings** to configure tracked elements, conversions and retention.
4. Watch live data on **Convertrack → Overview**.

For high-traffic sites: (1) disable WP-Cron and trigger `wp-cron.php` from a real system cron so rollups and cleanup run on schedule, and (2) run a persistent object cache (Redis or Memcached) — Convertrack then keeps its rate-limit counters and short stat caches in memory instead of the database.

Optional: open **Convertrack -> Google Index Monitor** to configure Google OAuth, connect Search Console, scan the sitemap, and run indexing checks.

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

= How do I require visitor consent before tracking? =
Return `true` from the `convertrack_skip_tracking` filter while consent has not been granted (most consent-management plugins expose a state you can check), then allow tracking once the visitor accepts.

== Changelog ==

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
