<?php
/**
 * Data layer: schema, installation and all read/write queries.
 *
 * Stores timestamps in site-local time (current_time('mysql')) so that
 * day-bucketing for the dashboard matches the site's configured timezone.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Database {

	/**
	 * Bump when the schema changes so maybe_upgrade() re-runs dbDelta.
	 */
	const DB_VERSION = '1.0.0';

	const DB_VERSION_OPTION = 'convertrack_db_version';

	/**
	 * Raw click / pageview events table.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_events';
	}

	/**
	 * Live session / presence table.
	 *
	 * @return string
	 */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_sessions';
	}

	/**
	 * Pre-aggregated daily rollups table (keeps dashboards fast on large sites).
	 *
	 * @return string
	 */
	public static function daily_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_daily';
	}

	/**
	 * Create or update the database schema.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$sessions        = self::sessions_table();
		$daily           = self::daily_table();

		$sql = array();

		$sql[] = "CREATE TABLE $events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id char(36) NOT NULL DEFAULT '',
			session_id char(36) NOT NULL DEFAULT '',
			event_type varchar(20) NOT NULL DEFAULT 'click',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_url varchar(255) NOT NULL DEFAULT '',
			page_title varchar(255) NOT NULL DEFAULT '',
			element_tag varchar(20) NOT NULL DEFAULT '',
			element_id varchar(191) NOT NULL DEFAULT '',
			element_classes varchar(255) NOT NULL DEFAULT '',
			element_text varchar(255) NOT NULL DEFAULT '',
			element_selector varchar(255) NOT NULL DEFAULT '',
			element_href varchar(255) NOT NULL DEFAULT '',
			is_conversion tinyint(1) NOT NULL DEFAULT 0,
			device_type varchar(10) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY post_created (post_id, created_at),
			KEY type_created (event_type, created_at),
			KEY visitor_id (visitor_id),
			KEY session_id (session_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $sessions (
			session_id char(36) NOT NULL DEFAULT '',
			visitor_id char(36) NOT NULL DEFAULT '',
			last_seen datetime NOT NULL,
			started_at datetime NOT NULL,
			current_url varchar(255) NOT NULL DEFAULT '',
			current_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_views int(10) unsigned NOT NULL DEFAULT 0,
			click_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (session_id),
			KEY last_seen (last_seen),
			KEY visitor_id (visitor_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $daily (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL DEFAULT '',
			stat_date date NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			element_selector varchar(255) NOT NULL DEFAULT '',
			element_text varchar(255) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY stat_date (stat_date),
			KEY post_date (post_id, stat_date)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Re-run installation if the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Remove all plugin tables (used on uninstall).
	 */
	public static function drop_tables() {
		global $wpdb;
		// Table names are built from the trusted $wpdb->prefix; safe to interpolate.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::events_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::sessions_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::daily_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete all tracked data but keep the tables (Tools → Reset).
	 */
	public static function reset_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::events_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'TRUNCATE TABLE ' . self::sessions_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'TRUNCATE TABLE ' . self::daily_table() ); // phpcs:ignore WordPress.DB
		wp_cache_delete( 'convertrack_today_agg', 'convertrack' );
	}

	/**
	 * Insert a week of realistic sample data so the dashboard can be evaluated
	 * before any live traffic exists (Tools → Insert sample data).
	 *
	 * @return int Number of sample events inserted.
	 */
	public static function seed_demo() {
		$pages    = get_posts( array( 'post_type' => array( 'post', 'page' ), 'numberposts' => 4, 'post_status' => 'publish' ) );
		$page_map = array();
		foreach ( $pages as $p ) {
			$page_map[] = array(
				'id'    => (int) $p->ID,
				'url'   => wp_make_link_relative( get_permalink( $p->ID ) ),
				'title' => $p->post_title,
			);
		}
		if ( empty( $page_map ) ) {
			$page_map[] = array( 'id' => 0, 'url' => '/', 'title' => 'Home' );
		}

		$buttons = array(
			array( 'txt' => 'Buy Now',     'sel' => 'a#buy-now',          'tag' => 'a',      'cls' => 'btn btn-primary', 'href' => '/checkout', 'conv' => 1 ),
			array( 'txt' => 'Add to Cart', 'sel' => 'button.add-to-cart', 'tag' => 'button', 'cls' => 'add-to-cart',     'href' => '',          'conv' => 0 ),
			array( 'txt' => 'Subscribe',   'sel' => 'button#subscribe',   'tag' => 'button', 'cls' => 'btn',             'href' => '',          'conv' => 1 ),
			array( 'txt' => 'Contact Us',  'sel' => 'a.contact-link',     'tag' => 'a',      'cls' => 'contact-link',    'href' => '/contact', 'conv' => 0 ),
			array( 'txt' => 'Learn More',  'sel' => 'a.learn-more',       'tag' => 'a',      'cls' => 'learn-more',      'href' => '/about',   'conv' => 0 ),
		);

		$now_ts = current_time( 'timestamp' );
		$rows   = array();

		for ( $d = 6; $d >= 0; $d-- ) {
			for ( $v = 0; $v < 18; $v++ ) {
				$vid  = self::uuid();
				$sid  = self::uuid();
				$page = $page_map[ array_rand( $page_map ) ];
				$ts   = $now_ts - ( $d * DAY_IN_SECONDS ) - wp_rand( 60, 80000 );

				$rows[] = array(
					'visitor_id' => $vid, 'session_id' => $sid, 'event_type' => 'pageview',
					'post_id' => $page['id'], 'page_url' => $page['url'], 'page_title' => $page['title'],
					'element_tag' => '', 'element_id' => '', 'element_classes' => '', 'element_text' => '',
					'element_selector' => '', 'element_href' => '', 'is_conversion' => 0,
					'device_type' => ( wp_rand( 0, 2 ) ? 'desktop' : 'mobile' ), 'created_at' => gmdate( 'Y-m-d H:i:s', $ts ),
				);

				$clicks = wp_rand( 0, 3 );
				for ( $c = 0; $c < $clicks; $c++ ) {
					$pick   = $buttons[ array_rand( $buttons ) ];
					$rows[] = array(
						'visitor_id' => $vid, 'session_id' => $sid, 'event_type' => 'click',
						'post_id' => $page['id'], 'page_url' => $page['url'], 'page_title' => $page['title'],
						'element_tag' => $pick['tag'], 'element_id' => '', 'element_classes' => $pick['cls'],
						'element_text' => $pick['txt'], 'element_selector' => $pick['sel'], 'element_href' => $pick['href'],
						'is_conversion' => ( $pick['conv'] && wp_rand( 0, 1 ) ) ? 1 : 0, 'device_type' => 'desktop',
						'created_at' => gmdate( 'Y-m-d H:i:s', $ts + wp_rand( 5, 600 ) ),
					);
				}
			}
		}

		$inserted = 0;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$inserted += self::insert_events( $chunk );
		}

		for ( $d = 6; $d >= 0; $d-- ) {
			self::rollup_day( self::date_days_ago( $d ) );
		}

		// A few visitors "on the site now".
		for ( $i = 0; $i < 4; $i++ ) {
			$pg = $page_map[ array_rand( $page_map ) ];
			self::touch_session( self::uuid(), self::uuid(), $pg['url'], $pg['id'], 1, wp_rand( 0, 3 ) );
		}

		wp_cache_delete( 'convertrack_today_agg', 'convertrack' );
		return $inserted;
	}

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string
	 */
	private static function uuid() {
		$d    = random_bytes( 16 );
		$d[6] = chr( ( ord( $d[6] ) & 0x0f ) | 0x40 );
		$d[8] = chr( ( ord( $d[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $d ), 4 ) );
	}

	/**
	 * Bulk-insert validated event rows in a single query.
	 *
	 * @param array $rows Each row is an ordered associative array already sanitized by Collector.
	 * @return int Number of rows inserted.
	 */
	public static function insert_events( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$columns = array(
			'visitor_id',
			'session_id',
			'event_type',
			'post_id',
			'page_url',
			'page_title',
			'element_tag',
			'element_id',
			'element_classes',
			'element_text',
			'element_selector',
			'element_href',
			'is_conversion',
			'device_type',
			'created_at',
		);

		$row_placeholder = '(%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s)';
		$placeholders    = array();
		$values          = array();

		foreach ( $rows as $row ) {
			$placeholders[] = $row_placeholder;
			foreach ( $columns as $col ) {
				$values[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
			}
		}

		$table = self::events_table();
		$sql   = "INSERT INTO $table (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Insert or update the live session row for presence tracking.
	 *
	 * @param string $session_id     Session UUID.
	 * @param string $visitor_id     Visitor UUID.
	 * @param string $url            Current page URL (already sanitized).
	 * @param int    $post_id        Current post ID.
	 * @param int    $pageview_inc   Page views to add (0 or 1).
	 * @param int    $click_inc      Clicks to add.
	 */
	public static function touch_session( $session_id, $visitor_id, $url, $post_id, $pageview_inc, $click_inc ) {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$table = self::sessions_table();

		$sql = "INSERT INTO $table
			(session_id, visitor_id, last_seen, started_at, current_url, current_post_id, page_views, click_count)
			VALUES (%s, %s, %s, %s, %s, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				last_seen = VALUES(last_seen),
				current_url = VALUES(current_url),
				current_post_id = VALUES(current_post_id),
				page_views = page_views + VALUES(page_views),
				click_count = click_count + VALUES(click_count)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$session_id,
				$visitor_id,
				$now,
				$now,
				$url,
				$post_id,
				$pageview_inc,
				$click_inc
			)
		);
	}

	/**
	 * Count distinct visitors seen within the active window.
	 *
	 * @param int $window_seconds Active window.
	 * @return int
	 */
	public static function active_visitor_count( $window_seconds ) {
		global $wpdb;

		$cache_key = 'convertrack_active_' . (int) $window_seconds;
		$cached    = wp_cache_get( $cache_key, 'convertrack' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$threshold = self::ago( $window_seconds );
		$table     = self::sessions_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM $table WHERE last_seen >= %s", $threshold )
		);

		// Short TTL: enough to absorb dashboard polling without going stale.
		wp_cache_set( $cache_key, $count, 'convertrack', 5 );

		return $count;
	}

	/**
	 * Active sessions detail for the live view (capped).
	 *
	 * @param int $window_seconds Active window.
	 * @param int $limit          Max rows.
	 * @return array
	 */
	public static function active_sessions( $window_seconds, $limit = 50 ) {
		global $wpdb;

		$threshold = self::ago( $window_seconds );
		$table     = self::sessions_table();
		$limit     = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT current_url, current_post_id, last_seen, page_views, click_count
				 FROM $table WHERE last_seen >= %s ORDER BY last_seen DESC LIMIT %d",
				$threshold,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Overview totals for a date range. Reads finished days from the rollup
	 * table and today from raw events so the dashboard is both fast and live.
	 *
	 * @param int $days Number of days back (including today).
	 * @return array
	 */
	public static function overview_stats( $days ) {
		global $wpdb;

		$days       = max( 1, (int) $days );
		$today      = self::today();
		$start_date = self::date_days_ago( $days - 1 );
		$daily      = self::daily_table();
		$events     = self::events_table();

		// Finished days from rollups.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(conversions),0) conversions,
				        COALESCE(SUM(pageviews),0) pageviews, COALESCE(SUM(unique_visitors),0) uniques
				 FROM $daily WHERE stat_date >= %s AND stat_date < %s",
				$start_date,
				$today
			),
			ARRAY_A
		);
		$hist = is_array( $hist ) ? $hist : array();

		// Today, live from raw events (cached briefly to absorb dashboard polling).
		$today_row = self::today_aggregate();

		$clicks      = (int) ( isset( $hist['clicks'] ) ? $hist['clicks'] : 0 ) + (int) $today_row['clicks'];
		$conversions = (int) ( isset( $hist['conversions'] ) ? $hist['conversions'] : 0 ) + (int) $today_row['conversions'];
		$pageviews   = (int) ( isset( $hist['pageviews'] ) ? $hist['pageviews'] : 0 ) + (int) $today_row['pageviews'];
		$uniques     = (int) ( isset( $hist['uniques'] ) ? $hist['uniques'] : 0 ) + (int) $today_row['uniques'];

		$conversion_rate = $pageviews > 0 ? round( ( $conversions / $pageviews ) * 100, 2 ) : 0.0;
		$ctr             = $pageviews > 0 ? round( ( $clicks / $pageviews ) * 100, 2 ) : 0.0;

		return array(
			'clicks'          => $clicks,
			'conversions'     => $conversions,
			'pageviews'       => $pageviews,
			'unique_visitors' => $uniques,
			'conversion_rate' => $conversion_rate,
			'click_through'   => $ctr,
		);
	}

	/**
	 * Top clicked buttons/links over a date range.
	 *
	 * @param int $days    Days back.
	 * @param int $limit   Max rows.
	 * @param int $post_id Optional post filter (0 = all).
	 * @return array
	 */
	public static function top_buttons( $days, $limit = 20, $post_id = 0 ) {
		global $wpdb;

		$start  = self::date_days_ago( max( 1, (int) $days ) - 1 );
		$limit  = max( 1, min( 200, (int) $limit ) );
		$daily  = self::daily_table();
		$where  = 'stat_date >= %s AND element_selector <> %s';
		$params = array( $start, '' );

		if ( $post_id > 0 ) {
			$where   .= ' AND post_id = %d';
			$params[] = (int) $post_id;
		}

		$sql = "SELECT element_selector, MAX(element_text) AS element_text,
		               SUM(clicks) AS clicks, SUM(conversions) AS conversions
		        FROM $daily WHERE $where
		        GROUP BY element_selector ORDER BY clicks DESC LIMIT %d";
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Top pages by clicks and pageviews over a date range.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function top_pages( $days, $limit = 20 ) {
		global $wpdb;

		$start = self::date_days_ago( max( 1, (int) $days ) - 1 );
		$limit = max( 1, min( 200, (int) $limit ) );
		$daily = self::daily_table();

		$sql = "SELECT post_id, SUM(clicks) AS clicks, SUM(pageviews) AS pageviews,
		               SUM(conversions) AS conversions
		        FROM $daily WHERE stat_date >= %s
		        GROUP BY post_id ORDER BY clicks DESC, pageviews DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $start, $limit ), ARRAY_A );
	}

	/**
	 * Daily click time-series for charts.
	 *
	 * @param int $days Days back.
	 * @return array Map of date => clicks (oldest first).
	 */
	public static function clicks_timeseries( $days ) {
		global $wpdb;

		$days  = max( 1, (int) $days );
		$start = self::date_days_ago( $days - 1 );
		$daily = self::daily_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, SUM(clicks) clicks, SUM(pageviews) pageviews, SUM(conversions) conversions
				 FROM $daily WHERE stat_date >= %s GROUP BY stat_date",
				$start
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['stat_date'] ] = $row;
		}

		// Fill gaps and append a live "today" bucket from raw events.
		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = self::date_days_ago( $i );
			if ( $date === self::today() ) {
				$series[ $date ] = self::today_counts();
			} elseif ( isset( $map[ $date ] ) ) {
				$series[ $date ] = array(
					'clicks'      => (int) $map[ $date ]['clicks'],
					'pageviews'   => (int) $map[ $date ]['pageviews'],
					'conversions' => (int) $map[ $date ]['conversions'],
				);
			} else {
				$series[ $date ] = array(
					'clicks'      => 0,
					'pageviews'   => 0,
					'conversions' => 0,
				);
			}
		}

		return $series;
	}

	/**
	 * Aggregate counts for the current day, straight from raw events.
	 *
	 * COUNT(DISTINCT visitor_id) over the day's rows is the most expensive read
	 * in the dashboard, so the result is cached for 30s. That is plenty fresh for
	 * an admin view and removes the query from repeated polling / multiple tabs.
	 *
	 * @return array clicks, conversions, pageviews, uniques.
	 */
	public static function today_aggregate() {
		$cached = wp_cache_get( 'convertrack_today_agg', 'convertrack' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
					SUM(CASE WHEN event_type='click' AND is_conversion=1 THEN 1 ELSE 0 END) conversions,
					SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
					COUNT(DISTINCT visitor_id) uniques
				 FROM $events WHERE created_at >= %s",
				self::today() . ' 00:00:00'
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();
		$agg = array(
			'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
			'conversions' => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
			'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			'uniques'     => isset( $row['uniques'] ) ? (int) $row['uniques'] : 0,
		);

		wp_cache_set( 'convertrack_today_agg', $agg, 'convertrack', 30 );
		return $agg;
	}

	/**
	 * Live click/pageview/conversion counts for the current day.
	 *
	 * @return array
	 */
	public static function today_counts() {
		$agg = self::today_aggregate();
		return array(
			'clicks'      => (int) $agg['clicks'],
			'pageviews'   => (int) $agg['pageviews'],
			'conversions' => (int) $agg['conversions'],
		);
	}

	/**
	 * Aggregate one day of raw events into the daily rollup table.
	 * Idempotent: clears that day's buckets first, then rebuilds them.
	 *
	 * @param string $date Y-m-d (site-local).
	 */
	public static function rollup_day( $date ) {
		global $wpdb;

		$date = preg_replace( '/[^0-9\-]/', '', (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}

		$events = self::events_table();
		$daily  = self::daily_table();
		$start  = $date . ' 00:00:00';
		$end    = $date . ' 23:59:59';

		// Clear existing buckets for the day so re-runs do not double count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $daily WHERE stat_date = %s", $date ) );

		// Click buckets grouped by selector.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$click_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, element_selector,
				        SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',element_text)),'|||',-1) AS element_text,
				        COUNT(*) AS clicks,
				        SUM(is_conversion) AS conversions,
				        COUNT(DISTINCT visitor_id) AS uniques
				 FROM $events
				 WHERE event_type='click' AND created_at BETWEEN %s AND %s
				 GROUP BY post_id, element_selector",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $click_rows as $row ) {
			self::upsert_daily_bucket(
				$date,
				(int) $row['post_id'],
				(string) $row['element_selector'],
				(string) $row['element_text'],
				array(
					'clicks'          => (int) $row['clicks'],
					'conversions'     => (int) $row['conversions'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}

		// Pageview buckets grouped by page (selector left empty).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pv_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS uniques
				 FROM $events
				 WHERE event_type='pageview' AND created_at BETWEEN %s AND %s
				 GROUP BY post_id",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $pv_rows as $row ) {
			self::upsert_daily_bucket(
				$date,
				(int) $row['post_id'],
				'',
				'',
				array(
					'pageviews'       => (int) $row['pageviews'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}
	}

	/**
	 * Upsert a single daily bucket identified by (date, post, selector).
	 *
	 * @param string $date     Y-m-d.
	 * @param int    $post_id  Post ID.
	 * @param string $selector Element selector ('' for page-level pageview bucket).
	 * @param string $text     Element text.
	 * @param array  $metrics  Subset of clicks/conversions/pageviews/unique_visitors.
	 */
	private static function upsert_daily_bucket( $date, $post_id, $selector, $text, array $metrics ) {
		global $wpdb;

		$daily = self::daily_table();
		$hash  = md5( $date . '|' . $post_id . '|' . $selector );

		$data = wp_parse_args(
			$metrics,
			array(
				'clicks'          => 0,
				'conversions'     => 0,
				'pageviews'       => 0,
				'unique_visitors' => 0,
			)
		);

		$wpdb->insert(
			$daily,
			array(
				'bucket_hash'     => $hash,
				'stat_date'       => $date,
				'post_id'         => $post_id,
				'element_selector' => $selector,
				'element_text'    => $text,
				'clicks'          => (int) $data['clicks'],
				'conversions'     => (int) $data['conversions'],
				'pageviews'       => (int) $data['pageviews'],
				'unique_visitors' => (int) $data['unique_visitors'],
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * Delete raw events older than the retention window. Rollups are preserved.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted in this batch.
	 */
	public static function purge_old_events( $days ) {
		global $wpdb;

		$days      = max( 1, (int) $days );
		$threshold = self::date_days_ago( $days ) . ' 00:00:00';
		$events    = self::events_table();

		// Bounded batch so a huge backlog never blocks cron.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM $events WHERE created_at < %s LIMIT 10000", $threshold )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Delete stale presence rows (older than the retention window).
	 *
	 * @param int $seconds Inactivity threshold in seconds.
	 * @return int Rows deleted in this batch.
	 */
	public static function cleanup_sessions( $seconds ) {
		global $wpdb;

		$threshold = self::ago( max( 60, (int) $seconds ) );
		$table     = self::sessions_table();

		// Bounded batch keeps the lock short on busy sites; cron loops as needed.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE last_seen < %s LIMIT 5000", $threshold )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/* --------------------------------------------------------------------- *
	 * Date helpers (site-local time).
	 *
	 * NOTE: current_time('timestamp') already has the site's GMT offset baked
	 * in, so formatting it with gmdate() (which applies NO further offset)
	 * yields the site-local wall-clock string that matches current_time('mysql')
	 * — the value stored in created_at/last_seen. Do NOT switch these to date():
	 * it is a no-op when WordPress forces PHP's timezone to UTC and a
	 * double-offset bug if anything changes the default timezone.
	 * --------------------------------------------------------------------- */

	/**
	 * Current site-local date (Y-m-d).
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Site-local date N days ago (Y-m-d).
	 *
	 * @param int $n Days back.
	 * @return string
	 */
	public static function date_days_ago( $n ) {
		$ts = current_time( 'timestamp' ) - ( (int) $n * DAY_IN_SECONDS );
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Site-local datetime N seconds ago (mysql format).
	 *
	 * @param int $seconds Seconds back.
	 * @return string
	 */
	public static function ago( $seconds ) {
		$ts = current_time( 'timestamp' ) - (int) $seconds;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
