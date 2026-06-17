=== Convertrack — Click & Conversion Analytics ===
Contributors: Cornbip19
Tags: analytics, click tracking, conversion, heatmap, real-time
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track clicks on every button and link, measure page conversion, and see how many visitors are on your site right now. Built to scale and to update itself from GitHub.

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

No IP addresses or personal data are stored. A random visitor identifier lives in the browser's local storage only. Do Not Track is supported, and you can exclude logged-in users, specific roles, or URLs.

**Updates straight from GitHub**

Convertrack updates itself from its GitHub repository's Releases. Push a new release and connected sites are notified in the normal WordPress Updates screen. Private repositories are supported with a personal access token.

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

== Changelog ==

= 1.0.0 =
* Initial release: click tracking, pageviews, conversions, live visitor count, daily rollups, GitHub self-updating.
