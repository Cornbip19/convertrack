# Convertrack — Click & Conversion Analytics

A self-hosted WordPress plugin that tracks **clicks on every button and link**, measures **page conversion**, and shows **how many visitors are on your site right now** — built to scale to large, high-traffic sites and to **update itself from this GitHub repository**.

## What it does

- **Click tracking** — every click on a link, button, input, block button, or any element you configure is recorded with its label, CSS path, page, and target.
- **Conversion measurement** — mark elements (e.g. `.cvtrk-convert`) or destination URLs (e.g. `/thank-you`) as conversions and watch conversion rate per page.
- **Live visitor count** — a real-time counter of how many distinct visitors are currently on the site, and what they're viewing.
- **Search keywords** — optional reporting for supported UTM terms, WordPress site searches, and visible search-referrer queries.
- **Scales** — events are batched in the browser via `navigator.sendBeacon`, written in bulk, and rolled up into a compact daily-aggregates table by a background job so dashboards stay fast at any size.
- **Privacy-friendly** — no IP addresses or personal data stored; Do Not Track supported; logged-in users / roles / URLs can be excluded.

## Architecture

| Layer | File(s) |
|------|---------|
| Bootstrap | `convertrack.php` |
| Data + schema + rollups | `includes/class-database.php` |
| Settings | `includes/class-settings.php` |
| Ingestion (validation, rate limit) | `includes/class-collector.php` |
| Presence / live count | `includes/class-presence.php` |
| REST API | `includes/class-rest-controller.php` |
| Front-end tracker | `public/js/convertrack.js` |
| Background jobs | `includes/class-cron.php` |
| Admin dashboard | `includes/class-admin.php`, `admin/` |
| GitHub self-updater | `includes/class-updater.php` |

Custom tables store raw `events`, live `sessions`, and pre-aggregated rollups for pages, sources, countries, and search keywords.

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
