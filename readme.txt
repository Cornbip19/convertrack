=== Convertrack — Click & Conversion Analytics ===
Contributors: Cornbip19
Tags: analytics, click tracking, conversion, real-time
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track clicks on every button and link, measure page conversion, and see how many visitors are on your site right now — privacy-friendly and built to scale.

== Description ==

Convertrack answers three questions about your site:

1. **Are visitors clicking?** Every click on a button, link, input or block button is recorded with its label, location and target.
2. **Is the page converting?** Mark elements or destination URLs as conversions and watch the conversion rate per page.
3. **Who's here now?** A live counter shows how many visitors are currently on the site, and what they're viewing.

**Built for large sites**

* Clicks are batched in the browser and delivered with `navigator.sendBeacon`, so tracking never blocks navigation.
* Events are written in bulk and rolled up into a compact daily-aggregates table by a background job, so dashboards stay fast no matter how many pages or events you have.
* Configurable raw-data retention keeps the database lean; long-term trends live in the aggregates table.
* Per-IP rate limiting, a bot filter, optional visitor sampling and full-page-cache compatibility keep ingestion cheap under heavy traffic.

**Privacy-friendly**

All analytics are stored in your site's own database — no data is ever sent to a third-party service. No IP addresses, names, or email addresses are collected; only a random identifier kept in the visitor's browser storage. "Do Not Track" is honored by default, you can exclude logged-in users, roles, or URLs, and consent-management plugins can gate tracking via the `convertrack_skip_tracking` filter.

**Updating**

Installed from WordPress.org, Convertrack updates through your dashboard like any other plugin. The separately distributed self-hosted build can additionally update itself from its GitHub Releases.

== Privacy ==

Convertrack is a first-party analytics tool: every event (pageview, click, traffic source, presence heartbeat) is stored only in this site's own database and is never transmitted to any external or third-party service. It does not collect IP addresses, names, or email addresses. A random, non-identifying visitor ID is stored in the browser's local storage to distinguish repeat visits.

By default, visitors whose browser sends a "Do Not Track" signal are not tracked. To require explicit cookie/consent before any tracking, return `true` from the `convertrack_skip_tracking` filter until your consent banner is accepted. A suggested privacy-policy paragraph is added to **Settings → Privacy** for inclusion in your site's policy.

== Installation ==

1. Upload the `convertrack` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.
3. Open **Convertrack → Settings** to configure tracked elements, conversions and retention.
4. Watch live data on **Convertrack → Overview**.

For high-traffic sites: (1) disable WP-Cron and trigger `wp-cron.php` from a real system cron so rollups and cleanup run on schedule, and (2) run a persistent object cache (Redis or Memcached) — Convertrack then keeps its rate-limit counters and short stat caches in memory instead of the database.

== Frequently Asked Questions ==

= Does it work with caching plugins? =
Yes. The tracker loads as a static script and the ingestion endpoints are public and cache-exempt, so a full-page cache does not break tracking.

= Where is data stored? =
In three custom tables: raw events, live sessions, and daily aggregates. No third-party service is contacted for analytics.

= How do conversions work? =
Add CSS selectors (e.g. `.cvtrk-convert`) or destination paths (e.g. `/thank-you`) in Settings. Matching clicks and pageviews are flagged as conversions.

= Does the plugin collect personal data? =
No. It stores no IP addresses, names, or email addresses — only a random visitor identifier in the browser's local storage, plus anonymous interaction counts. All of it stays in your own database. Do Not Track is honored by default.

= Does it contact any external services? =
No. The plugin does not send any data to external or third-party services. (The optional self-hosted build distributed via GitHub additionally queries the GitHub Releases API to check for plugin updates; the WordPress.org version does not.)

= How do I require visitor consent before tracking? =
Return `true` from the `convertrack_skip_tracking` filter while consent has not been granted (most consent-management plugins expose a state you can check), then allow tracking once the visitor accepts.

== Changelog ==

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
