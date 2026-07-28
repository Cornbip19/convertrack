<?php
/**
 * Extra URL discovery for the index queue.
 *
 * The sitemap alone cannot surface "Not found (404)" or "Page with redirect",
 * because by definition those URLs are not listed in a sitemap. Two further
 * sources close that gap:
 *
 *  - Search Analytics pages, which is the closest the API gets to the set of
 *    URLs Google actually knows about.
 *  - The Broken URLs module's own 404 log, which is the set of URLs confirmed to
 *    404 on this site.
 *
 * Both seed at lower priority than sitemap URLs, so a large 404 backlog cannot
 * starve inspection of the pages that matter -- inspection is capped at 2,000
 * URLs/day per property.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Url_Discovery {

	/**
	 * Max URLs seeded per source per run.
	 */
	const MAX_PER_RUN = 500;

	/**
	 * How far back to ask Search Analytics for pages.
	 */
	const LOOKBACK_DAYS = 90;

	/**
	 * Run every enabled discovery pass.
	 *
	 * @return array {search_console, broken_urls} stored counts.
	 */
	public static function run() {
		$out = array( 'search_console' => 0, 'broken_urls' => 0, 'errors' => 0 );

		if ( Settings::get( 'discover_from_search_console' ) ) {
			$result = self::from_search_console();
			if ( is_wp_error( $result ) ) {
				$out['errors']++;
				Logger::warning( 'discovery', 'Search Console page discovery failed.', array( 'error' => $result->get_error_message() ) );
			} else {
				$out['search_console'] = (int) $result;
			}
		}

		if ( Settings::get( 'discover_from_broken_urls' ) ) {
			$result = self::from_broken_urls();
			if ( is_wp_error( $result ) ) {
				$out['errors']++;
				Logger::warning( 'discovery', 'Broken URL discovery failed.', array( 'error' => $result->get_error_message() ) );
			} else {
				$out['broken_urls'] = (int) $result;
			}
		}

		if ( $out['search_console'] > 0 || $out['broken_urls'] > 0 ) {
			Logger::info( 'discovery', 'Seeded additional URLs for index inspection.', $out );
		}

		return $out;
	}

	/**
	 * Seed the pages Search Console has search data for.
	 *
	 * @return int|\WP_Error Stored count.
	 */
	public static function from_search_console() {
		if ( ! Credentials::is_connected() ) {
			return 0;
		}

		$end   = gmdate( 'Y-m-d', current_time( 'timestamp', true ) - DAY_IN_SECONDS );
		$start = gmdate( 'Y-m-d', current_time( 'timestamp', true ) - ( self::LOOKBACK_DAYS * DAY_IN_SECONDS ) );

		$rows = API::search_analytics_query( $start, $end, array( 'page' ), self::MAX_PER_RUN );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$urls = array();
		foreach ( (array) $rows as $row ) {
			$url = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return self::seed( $urls, 'pending_from_search_console' );
	}

	/**
	 * Seed URLs the Broken URLs module has recorded as 404ing.
	 *
	 * @return int|\WP_Error Stored count.
	 */
	public static function from_broken_urls() {
		global $wpdb;

		if ( ! class_exists( '\\Convertrack\\NotFound\\Database' ) ) {
			return 0;
		}
		$events = \Convertrack\NotFound\Database::events_table();
		if ( ! \Convertrack\NotFound\Database::table_exists( $events ) ) {
			return 0;
		}

		// Most-hit first: those are the URLs costing the most real traffic, so they
		// are the ones worth spending inspection quota on.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url FROM $events
				WHERE status NOT IN ('deleted','ignored')
				ORDER BY hit_count DESC, last_detected_at DESC LIMIT %d",
				self::MAX_PER_RUN
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$urls = array();
		foreach ( (array) $rows as $path ) {
			$path = (string) $path;
			if ( '' === $path ) {
				continue;
			}
			// The 404 log stores root-relative paths; the index queue stores
			// absolute URLs.
			$urls[] = 0 === strpos( $path, 'http' ) ? $path : home_url( $path );
		}

		return self::seed( $urls, 'pending_from_broken_urls' );
	}

	/**
	 * Upsert a batch of discovered URLs into the queue.
	 *
	 * preserve_status keeps any inspection result a URL already has, so
	 * rediscovery never resets a row that has been checked.
	 *
	 * @param array  $urls   Absolute URLs.
	 * @param string $status Seed status for genuinely new rows.
	 * @return int Stored count.
	 */
	private static function seed( array $urls, $status ) {
		$stored = 0;
		$seen   = array();
		$now    = current_time( 'mysql' );

		foreach ( $urls as $raw ) {
			$url = Database::normalize_url( $raw );
			if ( '' === $url || isset( $seen[ $url ] ) || ! Sitemap_Scanner::is_site_url( $url ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$match  = Sitemap_Scanner::match_post( $url );
			$result = Database::upsert_url(
				$url,
				array(
					'post_id'         => isset( $match['post_id'] ) ? (int) $match['post_id'] : 0,
					'post_type'       => isset( $match['post_type'] ) ? (string) $match['post_type'] : '',
					'index_status'    => $status,
					'preserve_status' => 1,
					// Explicitly not in_sitemap: these came from elsewhere, and
					// claiming otherwise would corrupt the sitemap filter.
					'priority'        => 0,
					'last_seen_at'    => $now,
				)
			);
			if ( ! is_wp_error( $result ) && $result ) {
				$stored++;
			}
		}

		return $stored;
	}
}
