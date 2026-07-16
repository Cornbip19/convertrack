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
	const DB_VERSION = '2.0.0';

	const DB_VERSION_OPTION = 'convertrack_db_version';
	const SCHEMA_STATUS_OPTION = 'convertrack_schema_status';
	const SCHEMA_ERROR_OPTION  = 'convertrack_schema_error';

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
	 * Daily traffic-source rollups (channel + campaign).
	 *
	 * @return string
	 */
	public static function sources_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_sources';
	}

	/**
	 * Daily visitor-country rollups (geolocation).
	 *
	 * @return string
	 */
	public static function geo_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_geo';
	}

	/**
	 * Daily search-keyword rollups.
	 *
	 * @return string
	 */
	public static function search_terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_search_terms';
	}

	/** @return string Durable per-day rollup state table. */
	public static function rollup_state_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_rollup_state';
	}

	/** @return string Owner-scoped rollup staging table. */
	public static function rollup_stage_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_rollup_stage';
	}

	/** @return string Privacy-safe exact visitor-day dimension. */
	public static function visitor_days_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_visitor_days';
	}

	/** @return string Privacy-safe exact session-day dimension. */
	public static function session_days_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_session_days';
	}

	/**
	 * Create or update the database schema.
	 *
	 * The version is advanced only after every required table, column, index and
	 * transactional engine has been verified.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;

		$lock_name = 'convertrack_schema_' . md5( $wpdb->prefix . get_current_blog_id() );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
		if ( 1 !== $locked ) {
			return new \WP_Error( 'convertrack_migration_locked', 'Another Convertrack database migration is already running.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$sessions        = self::sessions_table();
		$daily           = self::daily_table();
		$sources         = self::sources_table();
		$geo             = self::geo_table();
		$search_terms    = self::search_terms_table();
		$rollup_state    = self::rollup_state_table();
		$rollup_stage    = self::rollup_stage_table();
		$visitor_days    = self::visitor_days_table();
		$session_days    = self::session_days_table();

		$sql = array();

		$sql[] = "CREATE TABLE $events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) DEFAULT NULL,
			visitor_id char(36) NOT NULL DEFAULT '',
			session_id char(36) NOT NULL DEFAULT '',
			event_type varchar(20) NOT NULL DEFAULT 'click',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_key varchar(191) NOT NULL DEFAULT '',
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
			country char(2) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			referrer_host varchar(191) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(150) NOT NULL DEFAULT '',
			utm_term varchar(150) NOT NULL DEFAULT '',
			search_keyword varchar(191) NOT NULL DEFAULT '',
			search_source varchar(50) NOT NULL DEFAULT '',
			heatmap_selector varchar(255) NOT NULL DEFAULT '',
			pos_x smallint(5) unsigned NOT NULL DEFAULT 0,
			pos_y smallint(5) unsigned NOT NULL DEFAULT 0,
			rel_x smallint(5) unsigned NOT NULL DEFAULT 0,
			rel_y smallint(5) unsigned NOT NULL DEFAULT 0,
			viewport_w int(10) unsigned NOT NULL DEFAULT 0,
			viewport_h int(10) unsigned NOT NULL DEFAULT 0,
			document_w int(10) unsigned NOT NULL DEFAULT 0,
			document_h int(10) unsigned NOT NULL DEFAULT 0,
			scroll_x int(10) unsigned NOT NULL DEFAULT 0,
			scroll_y int(10) unsigned NOT NULL DEFAULT 0,
			scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,
			occurred_at_utc datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY created_at (created_at),
			KEY page_key_created (page_key, created_at),
			KEY post_created (post_id, created_at),
			KEY type_created (event_type, created_at),
			KEY heatmap_device (post_id, event_type, device_type, created_at),
			KEY search_keyword (search_keyword),
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
			current_page_key varchar(191) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
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
			page_key varchar(191) NOT NULL DEFAULT '',
			element_selector varchar(255) NOT NULL DEFAULT '',
			element_text varchar(255) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY stat_date (stat_date),
			KEY post_date (post_id, stat_date),
			KEY page_date (page_key, stat_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $sources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL DEFAULT '',
			stat_date date NOT NULL,
			source varchar(100) NOT NULL DEFAULT '',
			campaign varchar(150) NOT NULL DEFAULT '',
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY stat_date (stat_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $geo (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL DEFAULT '',
			stat_date date NOT NULL,
			country char(2) NOT NULL DEFAULT '',
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY stat_date (stat_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $search_terms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL DEFAULT '',
			stat_date date NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_key varchar(191) NOT NULL DEFAULT '',
			search_keyword varchar(191) NOT NULL DEFAULT '',
			search_source varchar(50) NOT NULL DEFAULT '',
			traffic_source varchar(100) NOT NULL DEFAULT '',
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			unique_visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY stat_date (stat_date),
			KEY keyword_date (search_keyword, stat_date),
			KEY post_date (post_id, stat_date),
			KEY page_date (page_key, stat_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $rollup_state (
			stat_date date NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			owner_token char(36) NOT NULL DEFAULT '',
			lease_expires_at datetime DEFAULT NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			source_event_max_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_event_count bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			last_error text NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (stat_date),
			KEY status_lease (status, lease_expires_at)
		) ENGINE=InnoDB $charset_collate;";

		$sql[] = "CREATE TABLE $rollup_stage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_token char(36) NOT NULL,
			bucket_type varchar(20) NOT NULL,
			bucket_hash char(32) NOT NULL,
			stat_date date NOT NULL,
			page_key varchar(191) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dimension_one varchar(191) NOT NULL DEFAULT '',
			dimension_two varchar(191) NOT NULL DEFAULT '',
			element_text varchar(255) NOT NULL DEFAULT '',
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			conversions bigint(20) unsigned NOT NULL DEFAULT 0,
			conversion_events bigint(20) unsigned NOT NULL DEFAULT 0,
			pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
			unique_visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY owner_bucket (owner_token, bucket_type, bucket_hash),
			KEY owner_type (owner_token, bucket_type),
			KEY stat_date (stat_date)
		) ENGINE=InnoDB $charset_collate;";

		$sql[] = "CREATE TABLE $visitor_days (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL,
			stat_date date NOT NULL,
			visitor_hash char(64) NOT NULL,
			converted tinyint(1) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY date_visitor (stat_date, visitor_hash),
			KEY visitor_hash (visitor_hash)
		) ENGINE=InnoDB $charset_collate;";

		$sql[] = "CREATE TABLE $session_days (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bucket_hash char(32) NOT NULL,
			stat_date date NOT NULL,
			session_hash char(64) NOT NULL,
			visitor_hash char(64) NOT NULL DEFAULT '',
			converted tinyint(1) unsigned NOT NULL DEFAULT 0,
			conversion_events int(10) unsigned NOT NULL DEFAULT 0,
			pageviews int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_hash (bucket_hash),
			KEY date_session (stat_date, session_hash),
			KEY session_hash (session_hash),
			KEY visitor_hash (visitor_hash)
		) ENGINE=InnoDB $charset_collate;";

		$errors = array();
		foreach ( $sql as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( '' !== (string) $wpdb->last_error ) {
				$errors[] = $wpdb->last_error;
			}
		}

		$verified = empty( $errors ) ? self::verify_schema() : new \WP_Error( 'convertrack_dbdelta_failed', implode( '; ', array_unique( $errors ) ) );
		if ( is_wp_error( $verified ) ) {
			update_option( self::SCHEMA_STATUS_OPTION, 'unhealthy', false );
			update_option( self::SCHEMA_ERROR_OPTION, $verified->get_error_message(), false );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return $verified;
		}

		// Persist health first and the version watermark last. update_option()
		// returns false both for failure and unchanged values, so verify the
		// stored contract explicitly before claiming migration success.
		update_option( self::SCHEMA_STATUS_OPTION, 'healthy', false );
		if ( 'healthy' !== get_option( self::SCHEMA_STATUS_OPTION ) ) {
			$error = new \WP_Error( 'convertrack_migration_status_write', 'The verified analytics schema status could not be saved.' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return $error;
		}
		delete_option( self::SCHEMA_ERROR_OPTION );
		if ( null !== get_option( self::SCHEMA_ERROR_OPTION, null ) ) {
			update_option( self::SCHEMA_STATUS_OPTION, 'unhealthy', false );
			$error = new \WP_Error( 'convertrack_migration_error_clear', 'The previous analytics schema error could not be cleared.' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return $error;
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			update_option( self::SCHEMA_STATUS_OPTION, 'unhealthy', false );
			update_option( self::SCHEMA_ERROR_OPTION, 'The analytics schema version watermark could not be saved.', false );
			$error = new \WP_Error( 'convertrack_migration_version_write', 'The analytics schema version watermark could not be saved.' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			return $error;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		return true;
	}

	/**
	 * Re-run installation if the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			return self::install();
		}
		return self::schema_is_healthy() ? true : self::verify_schema();
	}

	/**
	 * Verify the structural contract required by collection and atomic rollups.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schema() {
		global $wpdb;

		$manifest = array(
			self::events_table() => array(
				'columns' => array( 'id', 'event_id', 'visitor_id', 'session_id', 'event_type', 'post_id', 'page_key', 'object_type', 'object_id', 'page_url', 'is_conversion', 'occurred_at_utc', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'event_id', 'created_at', 'page_key_created' ),
			),
			self::sessions_table() => array(
				'columns' => array( 'session_id', 'visitor_id', 'last_seen', 'current_url', 'current_post_id', 'current_page_key' ),
				'indexes' => array( 'PRIMARY', 'last_seen', 'visitor_id' ),
			),
			self::daily_table() => array(
				'columns' => array( 'bucket_hash', 'stat_date', 'post_id', 'page_key', 'conversions', 'conversion_events', 'unique_visitors' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'stat_date', 'page_date' ),
			),
			self::sources_table() => array(
				'columns' => array( 'bucket_hash', 'stat_date', 'source', 'campaign', 'conversions', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'stat_date' ),
			),
			self::geo_table() => array(
				'columns' => array( 'bucket_hash', 'stat_date', 'country', 'conversions', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'stat_date' ),
			),
			self::search_terms_table() => array(
				'columns' => array( 'bucket_hash', 'stat_date', 'post_id', 'page_key', 'search_keyword', 'conversions', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'stat_date', 'page_date' ),
			),
			self::rollup_state_table() => array(
				'columns' => array( 'stat_date', 'status', 'owner_token', 'lease_expires_at', 'attempts', 'source_event_max_id', 'completed_at' ),
				'indexes' => array( 'PRIMARY', 'status_lease' ),
			),
			self::rollup_stage_table() => array(
				'columns' => array( 'owner_token', 'bucket_type', 'bucket_hash', 'stat_date', 'page_key', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'owner_bucket', 'owner_type' ),
			),
			self::visitor_days_table() => array(
				'columns' => array( 'stat_date', 'visitor_hash', 'converted', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'date_visitor' ),
			),
			self::session_days_table() => array(
				'columns' => array( 'stat_date', 'session_hash', 'visitor_hash', 'converted', 'conversion_events' ),
				'indexes' => array( 'PRIMARY', 'bucket_hash', 'date_session' ),
			),
		);

		$problems = array();
		foreach ( $manifest as $table => $requirements ) {
			// Table names are internal values made from the trusted prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $exists ) {
				$problems[] = 'Missing table ' . $table;
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
			foreach ( $requirements['columns'] as $column ) {
				if ( ! in_array( $column, (array) $columns, true ) ) {
					$problems[] = 'Missing column ' . $table . '.' . $column;
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = $wpdb->get_col( "SHOW INDEX FROM `$table`", 2 );
			foreach ( $requirements['indexes'] as $index ) {
				if ( ! in_array( $index, (array) $indexes, true ) ) {
					$problems[] = 'Missing index ' . $table . '.' . $index;
				}
			}

			// Atomic replacement depends on transactional tables.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== (string) $engine ) {
				$problems[] = 'Table is not InnoDB: ' . $table;
			}
		}

		if ( ! empty( $problems ) ) {
			$error = new \WP_Error( 'convertrack_schema_verification', implode( '; ', $problems ) );
			update_option( self::SCHEMA_STATUS_OPTION, 'unhealthy', false );
			update_option( self::SCHEMA_ERROR_OPTION, $error->get_error_message(), false );
			return $error;
		}

		update_option( self::SCHEMA_STATUS_OPTION, 'healthy', false );
		delete_option( self::SCHEMA_ERROR_OPTION );
		return true;
	}

	/**
	 * Whether collection-safe schema is fully installed.
	 *
	 * @return bool
	 */
	public static function schema_is_healthy() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) && 'healthy' === get_option( self::SCHEMA_STATUS_OPTION );
	}

	/**
	 * Clear the bounded set of report cache keys after writes/rollups.
	 */
	public static function invalidate_report_cache() {
		wp_cache_delete( 'convertrack_today_agg', 'convertrack' );
		wp_cache_delete( 'convertrack_range_availability', 'convertrack' );
		// Incrementing a generation avoids attempting to enumerate every possible
		// range/post cache key. Not every cache backend creates a missing counter
		// during incr(), so initialize it atomically and retain a safe fallback.
		$current = self::report_cache_generation();
		$next    = wp_cache_incr( 'convertrack_report_generation', 1, 'convertrack' );
		if ( false === $next ) {
			wp_cache_set( 'convertrack_report_generation', $current + 1, 'convertrack' );
		}
	}

	/**
	 * Current generation for bounded aggregate cache keys.
	 *
	 * @return int
	 */
	public static function report_cache_generation() {
		$key        = 'convertrack_report_generation';
		$generation = wp_cache_get( $key, 'convertrack' );
		if ( false === $generation ) {
			if ( ! wp_cache_add( $key, 1, 'convertrack' ) ) {
				$generation = wp_cache_get( $key, 'convertrack' );
			}
			if ( false === $generation ) {
				$generation = 1;
				wp_cache_set( $key, $generation, 'convertrack' );
			}
		}
		return max( 1, (int) $generation );
	}

	/**
	 * Describe the truthful data window for a report.
	 *
	 * @param int $requested_days Requested range.
	 * @param string $source      raw for event-backed reports; aggregate for
	 *                            daily-rollup reports (including today's raw data).
	 * @return array
	 */
	public static function range_metadata( $requested_days, $source = 'raw' ) {
		global $wpdb;
		$requested_days = max( 1, (int) $requested_days );
		$source         = 'aggregate' === $source ? 'aggregate' : 'raw';
		$events         = self::events_table();

		if ( 'aggregate' === $source ) {
			$daily = self::daily_table();
			// A report can be populated by a completed historical rollup, today's
			// raw rows, or both. Use the earliest real source rather than implying
			// that a configured range is available when retention has removed it.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$oldest = $wpdb->get_var(
				"SELECT MIN(available_from) FROM (
					SELECT MIN(stat_date) available_from FROM $daily
					UNION ALL
					SELECT DATE(MIN(created_at)) available_from FROM $events
				) report_availability"
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$oldest = $wpdb->get_var( "SELECT MIN(created_at) FROM $events" );
		}
		$from   = $oldest ? substr( (string) $oldest, 0, 10 ) : null;
		$today  = self::today();
		$available_days = $from && $from <= $today ? max( 1, (int) floor( ( strtotime( $today ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 ) : 0;
		$effective      = $available_days > 0 ? min( $requested_days, $available_days ) : 0;

		return array(
			'requested_range'   => $requested_days,
			'effective_range'   => $effective,
			'data_available_from' => $from,
			'truncated'         => $effective < $requested_days,
			'partial'           => $effective < $requested_days,
		);
	}

	/**
	 * Log the last database error when debugging is on, so silent schema or
	 * insert failures (e.g. a column missing after a failed migration on an
	 * unusual MySQL config) are diagnosable instead of only surfacing as
	 * "stored: 0" to the tracker.
	 *
	 * @param string $context Where the failure occurred.
	 */
	private static function log_db_error( $context ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Convertrack DB error (' . $context . '): ' . $wpdb->last_error );
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
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::sources_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::geo_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::search_terms_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::rollup_state_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::rollup_stage_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::visitor_days_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::session_days_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete all tracked data but keep the tables (Tools → Reset).
	 */
	public static function reset_all() {
		global $wpdb;
		$tables = array_map(
			static function ( $suffix ) use ( $wpdb ) {
				return $wpdb->prefix . $suffix;
			},
			Manifest::analytics_table_suffixes()
		);
		$failures = array();
		foreach ( $tables as $table ) {
			// Internal table name from the trusted WordPress prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( 'TRUNCATE TABLE ' . $table ) ) {
				$failures[] = $table . ': ' . $wpdb->last_error;
			}
		}
		self::invalidate_report_cache();
		return empty( $failures ) ? true : new \WP_Error( 'convertrack_reset_failed', implode( '; ', $failures ) );
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

		$source_pool = array(
			array( 'source' => 'Organic search', 'rh' => 'google.com',           'us' => '',           'um' => '',       'uc' => '',              'ut' => '',              'kw' => 'analytics plugin',   'ks' => 'referrer_query' ),
			array( 'source' => 'Organic search', 'rh' => 'bing.com',             'us' => '',           'um' => '',       'uc' => '',              'ut' => '',              'kw' => '',                   'ks' => '' ),
			array( 'source' => 'Direct',         'rh' => '',                      'us' => '',           'um' => '',       'uc' => '',              'ut' => '',              'kw' => '',                   'ks' => '' ),
			array( 'source' => 'Social',         'rh' => 'facebook.com',          'us' => 'facebook',   'um' => 'social', 'uc' => 'spring-launch', 'ut' => '',              'kw' => '',                   'ks' => '' ),
			array( 'source' => 'Referral',       'rh' => 'news.ycombinator.com',  'us' => '',           'um' => '',       'uc' => '',              'ut' => '',              'kw' => '',                   'ks' => '' ),
			array( 'source' => 'Newsletter',     'rh' => '',                      'us' => 'newsletter', 'um' => 'email',  'uc' => 'june-digest',    'ut' => 'conversion tips', 'kw' => 'conversion tips',    'ks' => 'utm_term' ),
			array( 'source' => 'Paid search',    'rh' => '',                      'us' => 'google',     'um' => 'cpc',    'uc' => 'brand-search',   'ut' => 'click heatmaps',  'kw' => 'click heatmaps',     'ks' => 'utm_term' ),
			array( 'source' => 'Direct',         'rh' => '',                      'us' => '',           'um' => '',       'uc' => '',              'ut' => '',              'kw' => 'pricing',            'ks' => 'site_search' ),
		);

		$country_pool = array( 'US', 'GB', 'CA', 'DE', 'IN', 'AU', 'FR', 'BR', 'NL', 'ES' );

		$now_ts = current_time( 'timestamp' );
		$rows   = array();

		for ( $d = 6; $d >= 0; $d-- ) {
			for ( $v = 0; $v < 18; $v++ ) {
				$vid     = self::uuid();
				$sid     = self::uuid();
				$page    = $page_map[ array_rand( $page_map ) ];
				$src     = $source_pool[ array_rand( $source_pool ) ];
				$country = $country_pool[ array_rand( $country_pool ) ];
				$ts      = $now_ts - ( $d * DAY_IN_SECONDS ) - wp_rand( 60, 80000 );

				$rows[] = array(
					'visitor_id' => $vid, 'session_id' => $sid, 'event_type' => 'pageview',
					'post_id' => $page['id'], 'page_url' => $page['url'], 'page_title' => $page['title'],
					'element_tag' => '', 'element_id' => '', 'element_classes' => '', 'element_text' => '',
					'element_selector' => '', 'element_href' => '', 'is_conversion' => 0,
					'device_type' => ( wp_rand( 0, 2 ) ? 'desktop' : 'mobile' ), 'country' => $country,
					'source' => $src['source'], 'referrer_host' => $src['rh'], 'utm_source' => $src['us'], 'utm_medium' => $src['um'], 'utm_campaign' => $src['uc'], 'utm_term' => $src['ut'], 'search_keyword' => $src['kw'], 'search_source' => $src['ks'],
					'created_at' => gmdate( 'Y-m-d H:i:s', $ts ),
				);

				$clicks = wp_rand( 0, 3 );
				for ( $c = 0; $c < $clicks; $c++ ) {
					$pick   = $buttons[ array_rand( $buttons ) ];
					$rows[] = array(
						'visitor_id' => $vid, 'session_id' => $sid, 'event_type' => 'click',
						'post_id' => $page['id'], 'page_url' => $page['url'], 'page_title' => $page['title'],
						'element_tag' => $pick['tag'], 'element_id' => '', 'element_classes' => $pick['cls'],
						'element_text' => $pick['txt'], 'element_selector' => $pick['sel'], 'element_href' => $pick['href'],
						'is_conversion' => ( $pick['conv'] && wp_rand( 0, 1 ) ) ? 1 : 0, 'device_type' => 'desktop', 'country' => $country,
						'source' => $src['source'], 'referrer_host' => $src['rh'], 'utm_source' => $src['us'], 'utm_medium' => $src['um'], 'utm_campaign' => $src['uc'], 'utm_term' => $src['ut'], 'search_keyword' => $src['kw'], 'search_source' => $src['ks'], 'heatmap_selector' => $pick['sel'],
						'pos_x' => wp_rand( 120, 880 ), 'pos_y' => wp_rand( 60, 820 ),
						'rel_x' => wp_rand( 80, 920 ), 'rel_y' => wp_rand( 80, 920 ),
						'viewport_w' => 1440, 'viewport_h' => 900, 'document_w' => 1440, 'document_h' => 2200,
						'scroll_x' => 0, 'scroll_y' => wp_rand( 0, 1400 ),
						'created_at' => gmdate( 'Y-m-d H:i:s', $ts + wp_rand( 5, 600 ) ),
					);
				}

				$rows[] = array(
					'visitor_id' => $vid, 'session_id' => $sid, 'event_type' => 'scroll',
					'post_id' => $page['id'], 'page_url' => $page['url'], 'page_title' => $page['title'],
					'element_tag' => '', 'element_id' => '', 'element_classes' => '', 'element_text' => '',
					'element_selector' => '', 'element_href' => '', 'is_conversion' => 0, 'device_type' => 'desktop', 'country' => $country,
					'source' => $src['source'], 'referrer_host' => $src['rh'], 'utm_source' => $src['us'], 'utm_medium' => $src['um'], 'utm_campaign' => $src['uc'], 'utm_term' => $src['ut'], 'search_keyword' => $src['kw'], 'search_source' => $src['ks'],
					'scroll_depth' => min( 100, wp_rand( 20, 100 ) ),
					'created_at' => gmdate( 'Y-m-d H:i:s', $ts + wp_rand( 60, 800 ) ),
				);
			}
		}

		$inserted = 0;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$result = self::insert_events( $chunk );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$inserted += (int) $result;
		}

		for ( $d = 6; $d >= 0; $d-- ) {
			self::rollup_day( self::date_days_ago( $d ) );
		}

		// A few visitors "on the site now".
		for ( $i = 0; $i < 4; $i++ ) {
			$pg = $page_map[ array_rand( $page_map ) ];
			self::touch_session( self::uuid(), self::uuid(), $pg['url'], $pg['id'], 1, wp_rand( 0, 3 ), $country_pool[ array_rand( $country_pool ) ] );
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
	 * @return int|\WP_Error Number of rows inserted or a detectable write error.
	 */
	public static function insert_events( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$columns = array(
			'event_id',
			'visitor_id',
			'session_id',
			'event_type',
			'post_id',
			'page_key',
			'object_type',
			'object_id',
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
			'country',
			'source',
			'referrer_host',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'search_keyword',
			'search_source',
			'heatmap_selector',
			'pos_x',
			'pos_y',
			'rel_x',
			'rel_y',
			'viewport_w',
			'viewport_h',
			'document_w',
			'document_h',
			'scroll_x',
			'scroll_y',
			'scroll_depth',
			'occurred_at_utc',
			'created_at',
		);

		$formats = array(
			'%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d',
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s',
		);
		$row_placeholder = '(' . implode( ',', $formats ) . ')';
		$placeholders    = array();
		$values          = array();
		$historical_dates = array();

		foreach ( $rows as $row ) {
			$identity = Page_Identity::from_payload(
				isset( $row['page_url'] ) ? $row['page_url'] : '/',
				isset( $row['post_id'] ) ? $row['post_id'] : 0
			);
			if ( empty( $row['event_id'] ) || ! preg_match( '/^[a-f0-9-]{36}$/i', (string) $row['event_id'] ) ) {
				$row['event_id'] = wp_generate_uuid4();
			}
			$has_server_identity = ! empty( $row['page_key'] ) && ! empty( $row['object_type'] );
			$row['page_key']   = ! empty( $row['page_key'] ) ? substr( sanitize_text_field( $row['page_key'] ), 0, 191 ) : $identity['page_key'];
			$row['object_type']= ! empty( $row['object_type'] ) ? substr( sanitize_key( $row['object_type'] ), 0, 40 ) : $identity['object_type'];
			$row['object_id']  = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : $identity['object_id'];
			$row['post_id']    = $has_server_identity && isset( $row['post_id'] ) ? absint( $row['post_id'] ) : $identity['post_id'];
			if ( empty( $row['occurred_at_utc'] ) ) {
				$row['occurred_at_utc'] = ! empty( $row['created_at'] ) ? get_gmt_from_date( $row['created_at'] ) : current_time( 'mysql', true );
			}
			$event_date = ! empty( $row['created_at'] ) ? substr( (string) $row['created_at'], 0, 10 ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event_date ) && $event_date < self::today() ) {
				$historical_dates[ $event_date ] = true;
			}
			$placeholders[] = $row_placeholder;
			foreach ( $columns as $col ) {
				$values[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
			}
		}

		$table = self::events_table();
		$sql   = "INSERT INTO $table (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders ) .
			' ON DUPLICATE KEY UPDATE event_id=VALUES(event_id)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		if ( false === $result ) {
			self::log_db_error( 'insert_events' );
			return new \WP_Error( 'convertrack_event_write_failed', 'Analytics events could not be stored.', array( 'database_error' => $wpdb->last_error ) );
		}
		if ( $result > 0 && ! empty( $historical_dates ) ) {
			$pending = self::mark_rollups_pending( array_keys( $historical_dates ) );
			if ( is_wp_error( $pending ) ) {
				// The events are durable and idempotent. Surface the state-write
				// failure; catch-up also compares event IDs with the saved watermark,
				// so a retry cannot leave the late rows permanently unaggregated.
				return $pending;
			}
		}

		return (int) $result;
	}

	/**
	 * Requeue completed historical days after a late idempotent event insert.
	 *
	 * @param array $dates Site-local Y-m-d values.
	 * @return true|\WP_Error
	 */
	private static function mark_rollups_pending( array $dates ) {
		global $wpdb;
		$dates = array_values( array_unique( array_filter( $dates ) ) );
		if ( empty( $dates ) ) {
			return true;
		}
		$table        = self::rollup_state_table();
		$placeholders = implode( ',', array_fill( 0, count( $dates ), '%s' ) );
		$params       = array_merge(
			array( 'Late events arrived after the completed rollup.', current_time( 'mysql' ) ),
			$dates
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending',owner_token='',lease_expires_at=NULL,completed_at=NULL,last_error=%s,updated_at=%s
				 WHERE status='complete' AND stat_date IN ($placeholders)",
				$params
			)
		);
		return false === $updated ? new \WP_Error( 'convertrack_rollup_requeue_failed', $wpdb->last_error ) : true;
	}

	/**
	 * Recount live pageview/click totals from idempotent event storage.
	 *
	 * @param string $session_id Session UUID.
	 * @return array|\WP_Error
	 */
	public static function session_event_counts( $session_id ) {
		global $wpdb;
		$table = self::events_table();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(event_type='pageview') pageviews,SUM(event_type='click') clicks FROM $table WHERE session_id=%s",
				(string) $session_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'convertrack_session_count_failed', 'Analytics session totals could not be calculated.', array( 'database_error' => $wpdb->last_error ) );
		}
		return array(
			'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			'clicks'    => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
		);
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
	 * @param string $country        Two-letter country code ('' if unknown).
	 * @param bool   $absolute_counts Treat page/click values as exact session
	 *                                totals rather than increments.
	 * @param string $page_key       Validated canonical page key, when available.
	 * @return true|\WP_Error
	 */
	public static function touch_session( $session_id, $visitor_id, $url, $post_id, $pageview_inc, $click_inc, $country = '', $absolute_counts = false, $page_key = '' ) {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$table    = self::sessions_table();
		$identity = Page_Identity::from_payload( $url, $post_id );
		$has_page_key = '' !== (string) $page_key;
		$page_key     = $has_page_key ? substr( sanitize_text_field( (string) $page_key ), 0, 191 ) : $identity['page_key'];
		$current_post_id = $has_page_key ? absint( $post_id ) : $identity['post_id'];

		// Keep an already-known country if a later ping cannot resolve one.
		$pageview_update = $absolute_counts ? 'page_views = VALUES(page_views)' : 'page_views = page_views + VALUES(page_views)';
		$click_update    = $absolute_counts ? 'click_count = VALUES(click_count)' : 'click_count = click_count + VALUES(click_count)';
		$sql = "INSERT INTO $table
			(session_id, visitor_id, last_seen, started_at, current_url, current_post_id, current_page_key, country, page_views, click_count)
			VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %d, %d)
			ON DUPLICATE KEY UPDATE
				last_seen = VALUES(last_seen),
				current_url = VALUES(current_url),
				current_post_id = VALUES(current_post_id),
				current_page_key = VALUES(current_page_key),
				country = IF(VALUES(country) <> '', VALUES(country), country),
				$pageview_update,
				$click_update";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$session_id,
				$visitor_id,
				$now,
				$now,
				$url,
				$current_post_id,
				$page_key,
				$country,
				$pageview_inc,
				$click_inc
			)
		);
		if ( false === $result ) {
			return new \WP_Error( 'convertrack_session_write_failed', 'The analytics session could not be stored.', array( 'database_error' => $wpdb->last_error ) );
		}
		return true;
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
				"SELECT current_url, current_post_id, last_seen, started_at, country, page_views, click_count
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
				"SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(conversion_events),0) conversion_events,
				        COALESCE(SUM(pageviews),0) pageviews
				 FROM $daily WHERE stat_date >= %s AND stat_date < %s",
				$start_date,
				$today
			),
			ARRAY_A
		);
		$hist = is_array( $hist ) ? $hist : array();
		// Today, live from raw events (cached briefly to absorb dashboard polling).
		$today_row = self::today_aggregate();
		$dimensions = self::exact_range_dimensions( $start_date, true );

		$clicks      = (int) ( isset( $hist['clicks'] ) ? $hist['clicks'] : 0 ) + (int) $today_row['clicks'];
		$conversions = (int) $dimensions['converting_sessions'];
		$conversion_events = (int) ( isset( $hist['conversion_events'] ) ? $hist['conversion_events'] : 0 ) + (int) $today_row['conversion_events'];
		$pageviews   = (int) ( isset( $hist['pageviews'] ) ? $hist['pageviews'] : 0 ) + (int) $today_row['pageviews'];
		$uniques     = (int) $dimensions['unique_visitors'];
		$total_sessions = (int) $dimensions['sessions'];

		$conversion_rate = $total_sessions > 0 ? round( ( $conversions / $total_sessions ) * 100, 2 ) : 0.0;
		$ctr             = $pageviews > 0 ? round( ( $clicks / $pageviews ) * 100, 2 ) : 0.0;

		$current = array(
			'clicks'          => $clicks,
			'conversions'     => $conversions,
			'conversion_events'=> $conversion_events,
			'pageviews'       => $pageviews,
			'unique_visitors' => $uniques,
			'sessions'        => $total_sessions,
			'conversion_rate' => $conversion_rate,
			'click_through'   => $ctr,
		);

		// Previous equal-length window (fully historical) for comparison.
		$prev_start = self::date_days_ago( ( 2 * $days ) - 1 );
		$prev       = self::historical_window_totals( $prev_start, $start_date );

		$comparison = array();
		foreach ( array_keys( $current ) as $metric ) {
			$comparison[ $metric ] = self::pct_change( $prev[ $metric ], $current[ $metric ] );
		}

		return array_merge( $current, array( 'comparison' => $comparison ) );
	}

	/**
	 * Sum finished-day rollups over an exclusive [start, end) date window.
	 *
	 * @param string $start_date    Y-m-d inclusive.
	 * @param string $end_exclusive Y-m-d exclusive.
	 * @return array Totals incl. derived rates.
	 */
	private static function historical_window_totals( $start_date, $end_exclusive ) {
		global $wpdb;

		$daily    = self::daily_table();
		$visitors = self::visitor_days_table();
		$sessions = self::session_days_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(clicks),0) clicks,COALESCE(SUM(conversion_events),0) conversion_events,
				        COALESCE(SUM(pageviews),0) pageviews
				 FROM $daily WHERE stat_date >= %s AND stat_date < %s",
				$start_date,
				$end_exclusive
			),
			ARRAY_A
		);

		$row       = is_array( $row ) ? $row : array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$uniques = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM $visitors WHERE stat_date >= %s AND stat_date < %s", $start_date, $end_exclusive ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$session_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(DISTINCT session_hash) sessions,COUNT(DISTINCT CASE WHEN converted=1 THEN session_hash END) converting_sessions,COALESCE(SUM(conversion_events),0) conversion_events FROM $sessions WHERE stat_date >= %s AND stat_date < %s", $start_date, $end_exclusive ),
			ARRAY_A
		);
		$session_row = is_array( $session_row ) ? $session_row : array();
		$clicks    = (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 );
		$convs     = (int) ( isset( $session_row['converting_sessions'] ) ? $session_row['converting_sessions'] : 0 );
		$pageviews = (int) ( isset( $row['pageviews'] ) ? $row['pageviews'] : 0 );
		$total_sessions = (int) ( isset( $session_row['sessions'] ) ? $session_row['sessions'] : 0 );

		return array(
			'clicks'          => $clicks,
			'conversions'     => $convs,
			'conversion_events'=> (int) ( isset( $session_row['conversion_events'] ) ? $session_row['conversion_events'] : 0 ),
			'pageviews'       => $pageviews,
			'unique_visitors' => $uniques,
			'sessions'        => $total_sessions,
			'conversion_rate' => $total_sessions > 0 ? round( $convs / $total_sessions * 100, 2 ) : 0.0,
			'click_through'   => $pageviews > 0 ? round( $clicks / $pageviews * 100, 2 ) : 0.0,
		);
	}

	/**
	 * Exact range-level visitor and session dimensions.
	 *
	 * Finished days come from privacy-safe hash dimensions. The current day is
	 * unioned from raw rows so a visitor/session spanning midnight is counted
	 * once rather than once per day or selector bucket.
	 *
	 * @param string $start_date       Inclusive Y-m-d.
	 * @param bool   $include_live_day Include current raw day.
	 * @return array
	 */
	private static function exact_range_dimensions( $start_date, $include_live_day ) {
		global $wpdb;
		$visitors = self::visitor_days_table();
		$sessions = self::session_days_table();
		$events   = self::events_table();
		$today    = self::today();
		$salt     = wp_salt( 'auth' );

		if ( $include_live_day ) {
			$raw_start = max( $start_date, $today ) . ' 00:00:00';
			$visitor_sql = "SELECT COUNT(DISTINCT visitor_hash) FROM (
				SELECT visitor_hash FROM $visitors WHERE stat_date >= %s AND stat_date < %s
				UNION ALL
				SELECT SHA2(CONCAT(visitor_id,%s),256) visitor_hash FROM $events WHERE visitor_id<>'' AND created_at >= %s
			) visitor_union";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$unique_visitors = (int) $wpdb->get_var( $wpdb->prepare( $visitor_sql, $start_date, $today, $salt, $raw_start ) );

			$session_sql = "SELECT COUNT(*) sessions,COALESCE(SUM(converted),0) converting_sessions FROM (
				SELECT session_hash,MAX(converted) converted FROM (
					SELECT session_hash,converted FROM $sessions WHERE stat_date >= %s AND stat_date < %s
					UNION ALL
					SELECT SHA2(CONCAT(session_id,%s),256) session_hash,MAX(is_conversion) converted FROM $events
					WHERE session_id<>'' AND created_at >= %s GROUP BY session_id
				) session_union GROUP BY session_hash
			) exact_sessions";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$session_row = $wpdb->get_row( $wpdb->prepare( $session_sql, $start_date, $today, $salt, $raw_start ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$unique_visitors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM $visitors WHERE stat_date >= %s AND stat_date < %s", $start_date, $today ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$session_row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(DISTINCT session_hash) sessions,COUNT(DISTINCT CASE WHEN converted=1 THEN session_hash END) converting_sessions FROM $sessions WHERE stat_date >= %s AND stat_date < %s", $start_date, $today ), ARRAY_A );
		}

		$session_row = is_array( $session_row ) ? $session_row : array();
		return array(
			'unique_visitors'   => $unique_visitors,
			'sessions'          => isset( $session_row['sessions'] ) ? (int) $session_row['sessions'] : 0,
			'converting_sessions'=> isset( $session_row['converting_sessions'] ) ? (int) $session_row['converting_sessions'] : 0,
		);
	}

	/**
	 * Percentage change from previous to current. Null when there is no baseline.
	 *
	 * @param float $prev Previous value.
	 * @param float $cur  Current value.
	 * @return float|null
	 */
	private static function pct_change( $prev, $cur ) {
		$prev = (float) $prev;
		$cur  = (float) $cur;
		if ( $prev <= 0 ) {
			return null;
		}
		return round( ( ( $cur - $prev ) / $prev ) * 100, 1 );
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

		$today  = self::today();
		$start  = self::date_days_ago( max( 1, (int) $days ) - 1 );
		$limit  = max( 1, min( 200, (int) $limit ) );
		$daily  = self::daily_table();
		$events = self::events_table();
		$where  = 'stat_date >= %s AND stat_date < %s AND element_selector <> %s';
		$params = array( $start, $today, '' );

		if ( $post_id > 0 ) {
			$where   .= ' AND post_id = %d';
			$params[] = (int) $post_id;
		}

		$sql = "SELECT element_selector, MAX(element_text) AS element_text,
		               SUM(clicks) AS clicks, SUM(conversion_events) AS conversions
		        FROM $daily WHERE $where
		        GROUP BY element_selector ORDER BY clicks DESC LIMIT %d";
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$raw_where  = "event_type='click' AND element_selector <> '' AND created_at >= %s";
		$raw_params = array( $today . ' 00:00:00' );
		if ( $post_id > 0 ) {
			$raw_where   .= ' AND post_id = %d';
			$raw_params[] = (int) $post_id;
		}
		$raw_params[] = $limit * 4;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT element_selector,
				        SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',element_text)),'|||',-1) AS element_text,
				        COUNT(*) AS clicks,
				        SUM(is_conversion) AS conversions
				 FROM $events
				 WHERE $raw_where
				 GROUP BY element_selector ORDER BY clicks DESC LIMIT %d",
				$raw_params
			),
			ARRAY_A
		);

		$map = array();
		foreach ( array( $hist, $today_rows ) as $set ) {
			foreach ( $set as $row ) {
				$selector = (string) $row['element_selector'];
				if ( '' === $selector ) {
					continue;
				}
				if ( ! isset( $map[ $selector ] ) ) {
					$map[ $selector ] = array(
						'element_selector' => $selector,
						'element_text'     => (string) $row['element_text'],
						'clicks'           => 0,
						'conversions'      => 0,
					);
				}
				if ( '' !== (string) $row['element_text'] ) {
					$map[ $selector ]['element_text'] = (string) $row['element_text'];
				}
				$map[ $selector ]['clicks']      += (int) $row['clicks'];
				$map[ $selector ]['conversions'] += (int) $row['conversions'];
			}
		}

		$list = array_values( $map );
		usort(
			$list,
			function ( $a, $b ) {
				return (int) $b['clicks'] - (int) $a['clicks'];
			}
		);

		return array_slice( $list, 0, $limit );
	}

	/**
	 * Top pages by clicks and pageviews over a date range.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function top_pages( $days, $limit = 20 ) {
		$result = self::paged_pages(
			array(
				'range'    => max( 1, (int) $days ),
				'page'     => 1,
				'per_page' => max( 1, min( 100, (int) $limit ) ),
				'orderby'  => 'clicks',
				'order'    => 'desc',
			)
		);

		return $result['rows'];
	}

	/**
	 * Searchable, sortable and paginated page statistics over a date range.
	 *
	 * Finished days come from the daily rollup while the current day comes from
	 * raw events, matching top_pages() without loading the full result set into
	 * PHP. Current WordPress post data and retained event URLs/titles provide the
	 * searchable page identity without changing the analytics schema.
	 *
	 * @param array $args Query args: range, page, per_page, search, orderby, order.
	 * @return array Rows plus page, per_page, total and total_pages.
	 */
	public static function paged_pages( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'range'    => 7,
				'page'     => 1,
				'per_page' => 25,
				'search'   => '',
				'orderby'  => 'pageviews',
				'order'    => 'desc',
			)
		);

		$days     = max( 1, min( 365, (int) $args['range'] ) );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$search   = trim( sanitize_text_field( (string) $args['search'] ) );
		$orderby  = sanitize_key( (string) $args['orderby'] );
		$order    = 'asc' === sanitize_key( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$allowed_orderby = array( 'pageviews', 'clicks', 'conversions', 'title' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'pageviews';
		}

		$today  = self::today();
		$start  = self::date_days_ago( $days - 1 );
		$daily  = self::daily_table();
		$events = self::events_table();
		$posts  = $wpdb->posts;

		$daily_page_key = "CASE
			WHEN page_key <> '' THEN page_key
			WHEN post_id > 0 THEN CONCAT('legacy-post:',post_id)
			ELSE 'legacy-global:0'
		END";
		$event_page_key = "CASE
			WHEN page_key <> '' THEN page_key
			WHEN post_id > 0 THEN CONCAT('legacy-post:',post_id)
			WHEN page_url <> '' THEN CONCAT('legacy-url:',LEFT(SHA2(page_url,256),40))
			ELSE 'legacy-global:0'
		END";

		$metrics_sql = "SELECT combined.page_key,
		                       MAX(combined.post_id) AS post_id,
		                       MAX(combined.page_url) AS page_url,
		                       MAX(combined.page_title) AS page_title,
		                       SUM(combined.clicks) AS clicks,
		                       SUM(combined.pageviews) AS pageviews,
		                       SUM(combined.conversions) AS conversions
		                FROM (
		                    SELECT $daily_page_key AS page_key, MAX(post_id) AS post_id,
		                           '' AS page_url, '' AS page_title, SUM(clicks) AS clicks,
		                           SUM(pageviews) AS pageviews,
		                           SUM(conversion_events) AS conversions
		                    FROM $daily
		                    WHERE stat_date >= %s AND stat_date < %s
		                    GROUP BY $daily_page_key
		                    UNION ALL
		                    SELECT $event_page_key AS page_key, MAX(post_id) AS post_id,
		                           SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',page_url)),'|||',-1) AS page_url,
		                           SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',page_title)),'|||',-1) AS page_title,
		                           SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicks,
		                           SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) AS pageviews,
		                           SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) AS conversions
		                    FROM $events
		                    WHERE created_at >= %s
		                    GROUP BY $event_page_key
		                ) combined
		                GROUP BY combined.page_key";
		$metrics_params = array( $start, $today, $today . ' 00:00:00' );

		$title_sql = "CASE
		                  WHEN COALESCE(posts.post_title, '') <> '' THEN posts.post_title
		                  WHEN COALESCE(metrics.page_title, '') <> '' THEN metrics.page_title
		                  WHEN metrics.page_key = 'legacy-global:0' THEN '(unknown / global)'
		                  ELSE metrics.page_key
		              END";
		$where_sql    = '';
		$where_params = array();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_sql = "WHERE (
				$title_sql LIKE %s
				OR posts.post_name LIKE %s
				OR posts.guid LIKE %s
				OR metrics.page_key LIKE %s
				OR metrics.page_url LIKE %s
				OR EXISTS (
					SELECT 1 FROM $events known_page
					WHERE known_page.page_key = metrics.page_key
					  AND (known_page.page_title LIKE %s OR known_page.page_url LIKE %s)
					LIMIT 1
				)
			)";
			$where_params = array( $like, $like, $like, $like, $like, $like, $like );
		}

		$count_sql    = "SELECT COUNT(*)
		                 FROM ($metrics_sql) metrics
		                 LEFT JOIN $posts posts ON posts.ID = metrics.post_id
		                 $where_sql";
		$count_params = array_merge( $metrics_params, $where_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );

		$sort_sql = 'title' === $orderby ? 'sort_title' : 'metrics.' . $orderby;
		$offset   = ( $page - 1 ) * $per_page;
		$rows_sql = "SELECT metrics.page_key, metrics.post_id, metrics.page_url, metrics.page_title,
		                    metrics.clicks, metrics.pageviews, metrics.conversions,
		                    $title_sql AS sort_title
		             FROM ($metrics_sql) metrics
		             LEFT JOIN $posts posts ON posts.ID = metrics.post_id
		             $where_sql
		             ORDER BY $sort_sql $order, sort_title ASC, metrics.page_key ASC
		             LIMIT %d OFFSET %d";
		$rows_params = array_merge( $metrics_params, $where_params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

		foreach ( $rows as &$row ) {
			unset( $row['sort_title'] );
			$row['page_key']    = (string) $row['page_key'];
			$row['post_id']     = (int) $row['post_id'];
			$row['page_url']    = (string) $row['page_url'];
			$row['page_title']  = (string) $row['page_title'];
			$row['clicks']      = (int) $row['clicks'];
			$row['pageviews']   = (int) $row['pageviews'];
			$row['conversions'] = (int) $row['conversions'];
		}
		unset( $row );

		return array(
			'rows'        => $rows,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Resolve the latest retained public label and URL for page identities.
	 *
	 * This is deliberately one bounded indexed query for the whole response,
	 * avoiding an event lookup per row in the REST decorator. WordPress-backed
	 * posts are still resolved from current post data by the caller.
	 *
	 * @param array $page_keys Canonical page keys.
	 * @return array Map keyed by page_key.
	 */
	public static function page_identity_details( $page_keys ) {
		global $wpdb;

		$keys = array();
		foreach ( array_slice( array_unique( (array) $page_keys ), 0, 100 ) as $page_key ) {
			$page_key = substr( sanitize_text_field( (string) $page_key ), 0, 191 );
			if ( '' !== $page_key ) {
				$keys[] = $page_key;
			}
		}
		if ( empty( $keys ) ) {
			return array();
		}

		$events       = self::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT event.page_key,event.page_url,event.page_title
			FROM $events event
			INNER JOIN (
				SELECT page_key,MAX(id) latest_id FROM $events
				WHERE page_key IN ($placeholders) GROUP BY page_key
			) latest ON latest.latest_id=event.id";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $keys ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['page_key'] ] = array(
				'page_url'   => (string) $row['page_url'],
				'page_title' => (string) $row['page_title'],
			);
		}
		return $out;
	}

	/**
	 * Traffic by source/channel over a range (rollups + today live), merged.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max sources.
	 * @return array
	 */
	public static function top_sources( $days, $limit = 12 ) {
		global $wpdb;

		$days    = max( 1, (int) $days );
		$today   = self::today();
		$start   = self::date_days_ago( $days - 1 );
		$sources = self::sources_table();
		$events  = self::events_table();

		// Finished days from the source rollup.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, SUM(pageviews) pageviews, SUM(clicks) clicks,
				        SUM(conversion_events) conversions, SUM(unique_visitors) visitors
				 FROM $sources WHERE stat_date >= %s AND stat_date < %s GROUP BY source",
				$start,
				$today
			),
			ARRAY_A
		);

		// Today, live from raw events.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversions,
				        COUNT(DISTINCT visitor_id) visitors
				 FROM $events WHERE created_at >= %s GROUP BY source",
				$today . ' 00:00:00'
			),
			ARRAY_A
		);

		$map = array();
		foreach ( array( $hist, $today_rows ) as $set ) {
			foreach ( $set as $r ) {
				$s = '' === (string) $r['source'] ? 'Direct' : (string) $r['source'];
				if ( ! isset( $map[ $s ] ) ) {
					$map[ $s ] = array( 'source' => $s, 'pageviews' => 0, 'clicks' => 0, 'conversions' => 0, 'visitors' => 0 );
				}
				$map[ $s ]['pageviews']   += (int) $r['pageviews'];
				$map[ $s ]['clicks']      += (int) $r['clicks'];
				$map[ $s ]['conversions'] += (int) $r['conversions'];
				$map[ $s ]['visitors']    += (int) $r['visitors'];
			}
		}

		$list = array_values( $map );
		usort(
			$list,
			function ( $a, $b ) {
				return $b['pageviews'] - $a['pageviews'];
			}
		);

		return array_slice( $list, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Top search keywords over a range. Uses daily rollups for finished days
	 * and raw events for today so the report stays both fast and current.
	 *
	 * @param int    $days    Days back.
	 * @param int    $limit   Max terms.
	 * @param int    $post_id Optional post filter (0 = all).
	 * @param string $device  Device filter: all|desktop|tablet|mobile.
	 * @param string $page_key Optional canonical page key.
	 * @return array
	 */
	public static function top_search_terms( $days, $limit = 12, $post_id = 0, $device = 'all', $page_key = '' ) {
		global $wpdb;

		$days    = max( 1, (int) $days );
		$limit   = max( 1, min( 200, (int) $limit ) );
		$post_id = max( 0, (int) $post_id );
		$device  = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'all';
		$page_key = substr( sanitize_text_field( (string) $page_key ), 0, 191 );
		$today   = self::today();
		$start   = self::date_days_ago( $days - 1 );

		if ( 'all' !== $device ) {
			return self::merge_search_term_rows( array( self::search_terms_from_raw( $start . ' 00:00:00', $limit, $post_id, $device, $page_key ) ), $limit );
		}

		$search_terms = self::search_terms_table();
		$where        = 'stat_date >= %s AND stat_date < %s';
		$params       = array( $start, $today );

		if ( '' !== $page_key ) {
			$where   .= ' AND (page_key = %s';
			$params[] = $page_key;
			if ( $post_id > 0 ) {
				$where   .= " OR (page_key = '' AND post_id = %d)";
				$params[] = $post_id;
			}
			$where .= ')';
		} elseif ( $post_id > 0 ) {
			$where   .= ' AND post_id = %d';
			$params[] = $post_id;
		}

		$params[] = $limit * 4;

		// Finished days from the keyword rollup.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT search_keyword keyword, search_source keyword_source, traffic_source,
				        SUM(pageviews) pageviews, SUM(clicks) clicks,
				        SUM(conversion_events) conversions, SUM(unique_visitors) visitors
				 FROM $search_terms
				 WHERE $where
				 GROUP BY search_keyword, search_source, traffic_source
				 ORDER BY pageviews DESC, clicks DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);

		$today_rows = self::search_terms_from_raw( $today . ' 00:00:00', $limit * 4, $post_id, 'all', $page_key );

		return self::merge_search_term_rows( array( $hist, $today_rows ), $limit );
	}

	/**
	 * Read keyword rows directly from raw events.
	 *
	 * @param string $start   Start datetime.
	 * @param int    $limit   Max rows.
	 * @param int    $post_id Optional post filter.
	 * @param string $device  Device filter.
	 * @param string $page_key Optional canonical page key.
	 * @return array
	 */
	private static function search_terms_from_raw( $start, $limit, $post_id = 0, $device = 'all', $page_key = '' ) {
		global $wpdb;

		$events  = self::events_table();
		$where   = 'created_at >= %s';
		$params  = array( $start );
		$post_id = max( 0, (int) $post_id );
		$device  = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'all';
		$page_key = substr( sanitize_text_field( (string) $page_key ), 0, 191 );

		if ( '' !== $page_key ) {
			$where   .= ' AND (page_key = %s';
			$params[] = $page_key;
			if ( $post_id > 0 ) {
				$where   .= " OR (page_key = '' AND post_id = %d)";
				$params[] = $post_id;
			}
			$where .= ')';
		} elseif ( $post_id > 0 ) {
			$where   .= ' AND post_id = %d';
			$params[] = $post_id;
		}
		if ( 'all' !== $device ) {
			$where   .= ' AND device_type = %s';
			$params[] = $device;
		}

		$params[] = max( 1, min( 800, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				        CASE
							WHEN search_keyword <> '' THEN search_keyword
							WHEN source='Organic search' THEN '(not provided)'
							ELSE ''
						END AS keyword,
				        CASE
							WHEN search_keyword <> '' THEN search_source
							WHEN source='Organic search' THEN 'organic_not_provided'
							ELSE ''
						END AS keyword_source,
				        source AS traffic_source,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversions,
				        COUNT(DISTINCT visitor_id) visitors
				 FROM $events
				 WHERE $where
				 GROUP BY keyword, keyword_source, traffic_source
				 HAVING keyword <> ''
				 ORDER BY pageviews DESC, clicks DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Merge keyword rows from rollups and raw events.
	 *
	 * @param array $sets  Sets of rows.
	 * @param int   $limit Max rows.
	 * @return array
	 */
	private static function merge_search_term_rows( array $sets, $limit ) {
		$map = array();
		foreach ( $sets as $rows ) {
			foreach ( (array) $rows as $r ) {
				$keyword = trim( (string) $r['keyword'] );
				if ( '' === $keyword ) {
					continue;
				}
				$keyword_source = '' === (string) $r['keyword_source'] ? 'unknown' : (string) $r['keyword_source'];
				$traffic_source = '' === (string) $r['traffic_source'] ? 'Direct' : (string) $r['traffic_source'];
				$key            = $keyword_source . '|' . $keyword . '|' . $traffic_source;
				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array(
						'keyword'        => $keyword,
						'keyword_source' => $keyword_source,
						'traffic_source' => $traffic_source,
						'pageviews'      => 0,
						'clicks'         => 0,
						'conversions'    => 0,
						'visitors'       => 0,
					);
				}
				$map[ $key ]['pageviews']   += (int) $r['pageviews'];
				$map[ $key ]['clicks']      += (int) $r['clicks'];
				$map[ $key ]['conversions'] += (int) $r['conversions'];
				$map[ $key ]['visitors']    += (int) $r['visitors'];
			}
		}

		$list = array_values( $map );
		usort(
			$list,
			function ( $a, $b ) {
				if ( (int) $a['pageviews'] === (int) $b['pageviews'] ) {
					return (int) $b['clicks'] - (int) $a['clicks'];
				}
				return (int) $b['pageviews'] - (int) $a['pageviews'];
			}
		);

		return array_slice( $list, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Visitors by country over a range (rollups + today live), merged.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max countries.
	 * @return array
	 */
	public static function top_countries( $days, $limit = 12 ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$today  = self::today();
		$start  = self::date_days_ago( $days - 1 );
		$geo    = self::geo_table();
		$events = self::events_table();

		// Finished days from the country rollup.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hist = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, SUM(pageviews) pageviews, SUM(clicks) clicks,
				        SUM(conversion_events) conversions, SUM(unique_visitors) visitors
				 FROM $geo WHERE stat_date >= %s AND stat_date < %s GROUP BY country",
				$start,
				$today
			),
			ARRAY_A
		);

		// Today, live from raw events.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversions,
				        COUNT(DISTINCT visitor_id) visitors
				 FROM $events WHERE country <> '' AND created_at >= %s GROUP BY country",
				$today . ' 00:00:00'
			),
			ARRAY_A
		);

		$map = array();
		foreach ( array( $hist, $today_rows ) as $set ) {
			foreach ( $set as $r ) {
				$c = strtoupper( (string) $r['country'] );
				if ( '' === $c ) {
					continue;
				}
				if ( ! isset( $map[ $c ] ) ) {
					$map[ $c ] = array( 'country' => $c, 'pageviews' => 0, 'clicks' => 0, 'conversions' => 0, 'visitors' => 0 );
				}
				$map[ $c ]['pageviews']   += (int) $r['pageviews'];
				$map[ $c ]['clicks']      += (int) $r['clicks'];
				$map[ $c ]['conversions'] += (int) $r['conversions'];
				$map[ $c ]['visitors']    += (int) $r['visitors'];
			}
		}

		$list = array_values( $map );
		usort(
			$list,
			function ( $a, $b ) {
				return $b['pageviews'] - $a['pageviews'];
			}
		);

		return array_slice( $list, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Average session duration (seconds) over a date range, derived from the
	 * spread of each session's events. Cached briefly to absorb dashboard polling.
	 *
	 * @param int $days Days back.
	 * @return int Average seconds per session (0 when there is no data).
	 */
	public static function avg_session_seconds( $days ) {
		$days      = max( 1, (int) $days );
		$cache_key = 'convertrack_avgdur_' . $days;
		$cached    = wp_cache_get( $cache_key, 'convertrack' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$events = self::events_table();
		$start  = self::date_days_ago( $days - 1 ) . ' 00:00:00';

		// Per session: last event minus first event. Averaged across sessions.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(dur) FROM (
					SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) dur
					FROM $events WHERE created_at >= %s GROUP BY session_id
				) t",
				$start
			)
		);

		$avg = (int) round( (float) $avg );
		wp_cache_set( $cache_key, $avg, 'convertrack', 60 );
		return $avg;
	}

	/**
	 * Activity volume by hour of day for the selected range.
	 *
	 * @param int $days Days back.
	 * @return array Hourly rows, 00:00 through 23:00.
	 */
	public static function activity_by_hour( $days ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$start  = self::date_days_ago( $days - 1 ) . ' 00:00:00';
		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR(created_at) hour,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversions
				 FROM $events
				 WHERE created_at >= %s
				 GROUP BY HOUR(created_at)",
				$start
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['hour'] ] = $row;
		}

		$out = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$row     = isset( $map[ $h ] ) ? $map[ $h ] : array();
			$out[] = array(
				'hour'        => sprintf( '%02d:00', $h ),
				'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'conversions' => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Event-type mix for the engagement visualization.
	 *
	 * @param int $days Days back.
	 * @return array
	 */
	public static function engagement_breakdown( $days ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$start  = self::date_days_ago( $days - 1 ) . ' 00:00:00';
		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
					SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
					SUM(CASE WHEN event_type='scroll' THEN 1 ELSE 0 END) scrolls,
					SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversions
				 FROM $events WHERE created_at >= %s",
				$start
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();
		return array(
			'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
			'scrolls'     => isset( $row['scrolls'] ) ? (int) $row['scrolls'] : 0,
			'conversions' => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
		);
	}

	/**
	 * Recent raw events for the activity timeline.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent_events( $days, $limit = 80 ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$limit  = max( 1, min( 200, (int) $limit ) );
		$start  = self::date_days_ago( $days - 1 ) . ' 00:00:00';
		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, visitor_id, session_id, event_type, post_id, page_url, page_title,
				        element_text, element_selector, element_href, is_conversion,
				        device_type, country, source, created_at
				 FROM $events
				 WHERE created_at >= %s
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d",
				$start,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$visitor_id = (string) $row['visitor_id'];
			$session_id = (string) $row['session_id'];
			$out[]      = array(
				'id'               => (int) $row['id'],
				'time'             => (string) $row['created_at'],
				'visitor'          => '' !== $visitor_id ? substr( $visitor_id, 0, 8 ) : '',
				'session'          => '' !== $session_id ? substr( $session_id, 0, 8 ) : '',
				'type'             => (string) $row['event_type'],
				'post_id'          => (int) $row['post_id'],
				'page_url'         => (string) $row['page_url'],
				'page_title'       => (string) $row['page_title'],
				'element_text'     => (string) $row['element_text'],
				'element_selector' => (string) $row['element_selector'],
				'element_href'     => (string) $row['element_href'],
				'is_conversion'    => (int) $row['is_conversion'],
				'device'           => (string) $row['device_type'],
				'country'          => (string) $row['country'],
				'source'           => '' === (string) $row['source'] ? 'Direct' : (string) $row['source'],
			);
		}

		return $out;
	}

	/**
	 * Heatmap data for one page: click density grid + scroll-depth distribution.
	 *
	 * Click positions are stored as tenths of a percent of the page (0-1000) and
	 * aggregated here into a 0-100 x 0-100 grid so the payload stays bounded.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $days    Days back.
	 * @param string $device  Device filter: all|desktop|tablet|mobile.
	 * @param string $page_key Optional canonical page key.
	 * @return array
	 */
	public static function heatmap_data( $post_id, $days, $device = 'all', $page_key = '' ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$days    = max( 1, (int) $days );
		$start   = self::date_days_ago( $days - 1 ) . ' 00:00:00';
		$events  = self::events_table();
		$device  = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'all';
		$page_key = substr( sanitize_text_field( (string) $page_key ), 0, 191 );
		if ( '' !== $page_key ) {
			$page_where  = '(page_key=%s';
			$page_params = array( $page_key );
			if ( $post_id > 0 ) {
				// Include pre-page_key rows for the same public post during the
				// compatibility window without merging unrelated virtual pages.
				$page_where   .= " OR (page_key='' AND post_id=%d)";
				$page_params[] = $post_id;
			}
			$page_where .= ')';
		} else {
			$page_where  = 'post_id=%d';
			$page_params = array( $post_id );
		}

		$click_where  = "event_type IN ('click','heatmap_click') AND $page_where AND created_at >= %s AND (pos_x > 0 OR pos_y > 0)";
		$click_params = array_merge( $page_params, array( $start ) );
		if ( 'all' !== $device ) {
			$click_where   .= ' AND device_type=%s';
			$click_params[] = $device;
		}
		$click_params[] = 1500;

		// Click density, aggregated to a 0-100 grid. New rows also include
		// selector-relative coordinates so the admin view can anchor dots to the
		// matching element in the anonymous snapshot. Old rows still draw via gx/gy.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clicks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(heatmap_selector,''), element_selector) sel,
				        ROUND(pos_x/10) gx,
				        ROUND(pos_y/10) gy,
				        ROUND(rel_x/10) erx,
				        ROUND(rel_y/10) ery,
				        AVG(pos_x) px,
				        AVG(pos_y) py,
				        AVG(viewport_w) vw,
				        AVG(viewport_h) vh,
				        AVG(document_w) dw,
				        AVG(document_h) dh,
				        AVG(scroll_x) sx,
				        AVG(scroll_y) sy,
				        MAX(CASE WHEN COALESCE(NULLIF(heatmap_selector,''), element_selector) <> '' AND (rel_x > 0 OR rel_y > 0) THEN 1 ELSE 0 END) has_rel,
				        MAX(CASE WHEN viewport_w > 0 AND viewport_h > 0 AND document_w > 0 AND document_h > 0 THEN 1 ELSE 0 END) has_viewport,
				        COUNT(*) w
				 FROM $events
				 WHERE $click_where
				 GROUP BY sel, gx, gy, erx, ery ORDER BY w DESC LIMIT %d",
				$click_params
			),
			ARRAY_A
		);

		$points = array();
		$max_w  = 0;
		foreach ( $clicks as $r ) {
			$w        = (int) $r['w'];
			$points[] = array(
				'selector' => (string) $r['sel'],
				'x'        => (int) $r['gx'],
				'y'        => (int) $r['gy'],
				'rx'       => (int) $r['erx'],
				'ry'       => (int) $r['ery'],
				'px'       => (int) round( (float) $r['px'] ),
				'py'       => (int) round( (float) $r['py'] ),
				'vw'       => (int) round( (float) $r['vw'] ),
				'vh'       => (int) round( (float) $r['vh'] ),
				'dw'       => (int) round( (float) $r['dw'] ),
				'dh'       => (int) round( (float) $r['dh'] ),
				'sx'       => (int) round( (float) $r['sx'] ),
				'sy'       => (int) round( (float) $r['sy'] ),
				'has_rel'  => ! empty( $r['has_rel'] ) ? 1 : 0,
				'has_viewport' => ! empty( $r['has_viewport'] ) ? 1 : 0,
				'w'        => $w,
			);
			if ( $w > $max_w ) {
				$max_w = $w;
			}
		}

		$scroll_where  = "event_type='scroll' AND $page_where AND created_at >= %s";
		$scroll_params = array_merge( $page_params, array( $start ) );
		if ( 'all' !== $device ) {
			$scroll_where   .= ' AND device_type=%s';
			$scroll_params[] = $device;
		}

		// Scroll-depth samples grouped by reached depth.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scroll_depth d, COUNT(*) c
				 FROM $events
				 WHERE $scroll_where
				 GROUP BY scroll_depth",
				$scroll_params
			),
			ARRAY_A
		);

		$total_scroll = 0;
		$by_depth     = array();
		foreach ( $rows as $r ) {
			$by_depth[ (int) $r['d'] ] = (int) $r['c'];
			$total_scroll             += (int) $r['c'];
		}

		// Cumulative: % of samples that reached at least each 10% band.
		$scroll = array();
		for ( $band = 10; $band <= 100; $band += 10 ) {
			$reached = 0;
			foreach ( $by_depth as $depth => $count ) {
				if ( $depth >= $band ) {
					$reached += $count;
				}
			}
			$scroll[] = array(
				'depth' => $band,
				'pct'   => $total_scroll > 0 ? round( $reached / $total_scroll * 100, 1 ) : 0,
			);
		}

		$metric_where  = "$page_where AND created_at >= %s";
		$metric_params = array_merge( $page_params, array( $start ) );
		if ( 'all' !== $device ) {
			$metric_where   .= ' AND device_type=%s';
			$metric_params[] = $device;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pageviews = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events WHERE event_type='pageview' AND $metric_where", $metric_params ) );

		$tracked_click_params = $metric_params;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tracked_click_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events WHERE event_type='click' AND $metric_where", $tracked_click_params ) );

		$heatmap_click_params = $metric_params;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$heatmap_click_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events WHERE event_type IN ('click','heatmap_click') AND $metric_where", $heatmap_click_params ) );

		$element_params = array_merge( $page_params, array( $start ) );
		$element_where  = "event_type IN ('click','heatmap_click') AND $page_where AND created_at >= %s";
		if ( 'all' !== $device ) {
			$element_where   .= ' AND device_type=%s';
			$element_params[] = $device;
		}
		$element_params[] = 20;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$elements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(heatmap_selector,''), element_selector) element_selector,
				        SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',element_text)),'|||',-1) element_text,
				        SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',element_href)),'|||',-1) element_href,
				        COUNT(*) clicks,
				        SUM(CASE WHEN event_type='click' THEN is_conversion ELSE 0 END) conversions
				 FROM $events
				 WHERE $element_where
				 GROUP BY COALESCE(NULLIF(heatmap_selector,''), element_selector)
				 ORDER BY clicks DESC LIMIT %d",
				$element_params
			),
			ARRAY_A
		);

		$element_rows = array();
		foreach ( $elements as $row ) {
			$label = trim( (string) $row['element_text'] );
			if ( '' === $label ) {
				$label = (string) $row['element_selector'];
			}
			$element_rows[] = array(
				'label'       => $label,
				'selector'    => (string) $row['element_selector'],
				'href'        => (string) $row['element_href'],
				'clicks'      => (int) $row['clicks'],
				'conversions' => (int) $row['conversions'],
			);
		}

		return array(
			'points'         => $points,
			'max_weight'     => $max_w,
			'scroll'         => $scroll,
			'elements'       => $element_rows,
			'search_terms'   => self::top_search_terms( $days, 10, $post_id, $device, $page_key ),
			'pageviews'      => $pageviews,
			'clicks'         => $heatmap_click_total,
			'heatmap_clicks' => $heatmap_click_total,
			'tracked_clicks' => $tracked_click_total,
			'scroll_samples' => $total_scroll,
			'device'         => $device,
		);
	}

	/**
	 * Funnel / journey data derived from raw session events.
	 *
	 * @param int $days  Days back.
	 * @param int $limit Max rows per breakdown.
	 * @return array
	 */
	public static function funnel_data( $days, $limit = 10 ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$limit  = max( 1, min( 50, (int) $limit ) );
		$start  = self::date_days_ago( $days - 1 ) . ' 00:00:00';
		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_sessions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM $events WHERE created_at >= %s", $start ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$converting_sessions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM $events WHERE created_at >= %s AND is_conversion=1", $start ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_conversions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events WHERE created_at >= %s AND is_conversion=1", $start ) );

		return array(
			'total_sessions'      => $total_sessions,
			'converting_sessions' => $converting_sessions,
			'total_conversions'   => $total_conversions,
			'conversion_rate'     => $total_sessions > 0 ? round( ( $converting_sessions / $total_sessions ) * 100, 2 ) : 0,
			'paths'               => self::funnel_paths( $start, 1000, $limit ),
			'dropoffs'            => self::funnel_dropoffs( $start, $limit ),
			'sources'             => self::funnel_sources( $start, $limit ),
			'buttons'             => self::funnel_buttons_before_conversion( $start, $limit ),
		);
	}

	/**
	 * Common pageview paths before the first conversion in a session.
	 *
	 * @param string $start         Start datetime.
	 * @param int    $session_limit Max converting sessions to inspect.
	 * @param int    $limit         Max path rows.
	 * @return array
	 */
	private static function funnel_paths( $start, $session_limit, $limit ) {
		global $wpdb;

		$events        = self::events_table();
		$session_limit = max( 1, min( 5000, (int) $session_limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, MIN(created_at) converted_at
				 FROM $events
				 WHERE created_at >= %s AND is_conversion=1
				 GROUP BY session_id ORDER BY converted_at DESC LIMIT %d",
				$start,
				$session_limit
			),
			ARRAY_A
		);

		if ( empty( $sessions ) ) {
			return array();
		}

		$cutoffs = array();
		$ids     = array();
		foreach ( $sessions as $row ) {
			$sid = (string) $row['session_id'];
			if ( '' === $sid ) {
				continue;
			}
			$ids[]           = $sid;
			$cutoffs[ $sid ] = (string) $row['converted_at'];
		}

		if ( empty( $ids ) ) {
			return array();
		}

		$in     = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$params = array_merge( array( $start ), $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, page_url, page_title, created_at
				 FROM $events
				 WHERE created_at >= %s AND event_type='pageview' AND session_id IN ($in)
				 ORDER BY session_id ASC, created_at ASC",
				$params
			),
			ARRAY_A
		);

		$paths = array();
		foreach ( $events_rows as $row ) {
			$sid = (string) $row['session_id'];
			if ( ! isset( $cutoffs[ $sid ] ) || (string) $row['created_at'] > $cutoffs[ $sid ] ) {
				continue;
			}
			$url = '' !== (string) $row['page_url'] ? (string) $row['page_url'] : '/';
			if ( ! isset( $paths[ $sid ] ) ) {
				$paths[ $sid ] = array();
			}
			if ( end( $paths[ $sid ] ) !== $url ) {
				$paths[ $sid ][] = $url;
			}
		}

		$counted = array();
		foreach ( $paths as $path ) {
			if ( empty( $path ) ) {
				continue;
			}
			$path = array_slice( $path, -6 );
			$key  = implode( ' > ', $path );
			if ( ! isset( $counted[ $key ] ) ) {
				$counted[ $key ] = array( 'path' => $key, 'sessions' => 0 );
			}
			$counted[ $key ]['sessions']++;
		}

		$out = array_values( $counted );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['sessions'] - $a['sessions'];
			}
		);

		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Top final pages among sessions that did not convert.
	 *
	 * @param string $start Start datetime.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	private static function funnel_dropoffs( $start, $limit ) {
		global $wpdb;

		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT last.page_url url, MAX(last.page_title) title, COUNT(DISTINCT last.session_id) sessions
				 FROM (
					SELECT e.session_id, e.page_url, e.page_title
					FROM $events e
					INNER JOIN (
						SELECT session_id, MAX(created_at) last_seen
						FROM $events
						WHERE created_at >= %s AND page_url <> ''
						GROUP BY session_id
					) m ON e.session_id=m.session_id AND e.created_at=m.last_seen
					LEFT JOIN (
						SELECT DISTINCT session_id
						FROM $events
						WHERE created_at >= %s AND is_conversion=1
					) c ON c.session_id=e.session_id
					WHERE c.session_id IS NULL AND e.page_url <> ''
				 ) last
				 GROUP BY last.page_url ORDER BY sessions DESC LIMIT %d",
				$start,
				$start,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Converting sessions grouped by source/campaign.
	 *
	 * @param string $start Start datetime.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	private static function funnel_sources( $start, $limit ) {
		global $wpdb;

		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, utm_campaign campaign, COUNT(DISTINCT session_id) sessions, COUNT(*) conversions
				 FROM $events
				 WHERE created_at >= %s AND is_conversion=1
				 GROUP BY source, utm_campaign
				 ORDER BY sessions DESC, conversions DESC LIMIT %d",
				$start,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Buttons clicked before the first conversion in converting sessions.
	 *
	 * @param string $start Start datetime.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	private static function funnel_buttons_before_conversion( $start, $limit ) {
		global $wpdb;

		$events = self::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.element_selector,
				        SUBSTRING_INDEX(MAX(CONCAT(e.created_at,'|||',e.element_text)),'|||',-1) element_text,
				        COUNT(*) clicks,
				        COUNT(DISTINCT e.session_id) sessions
				 FROM $events e
				 INNER JOIN (
					SELECT session_id, MIN(created_at) converted_at
					FROM $events
					WHERE created_at >= %s AND is_conversion=1
					GROUP BY session_id
				 ) c ON e.session_id=c.session_id AND e.created_at <= c.converted_at
				 WHERE e.created_at >= %s AND e.event_type='click' AND e.element_selector <> ''
				 GROUP BY e.element_selector ORDER BY clicks DESC LIMIT %d",
				$start,
				$start,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
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
		$sessions = self::session_days_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, SUM(clicks) clicks, SUM(pageviews) pageviews
				 FROM $daily WHERE stat_date >= %s GROUP BY stat_date",
				$start
			),
			ARRAY_A
		);
		// Primary conversions are converting sessions. Selector/page buckets are
		// intentionally not summed because one conversion event can be represented
		// in more than one dimensional view.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conversion_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date,COUNT(DISTINCT session_hash) conversions
				 FROM $sessions WHERE stat_date >= %s AND converted=1 GROUP BY stat_date",
				$start
			),
			ARRAY_A
		);
		$conversions_by_date = array();
		foreach ( (array) $conversion_rows as $conversion_row ) {
			$conversions_by_date[ (string) $conversion_row['stat_date'] ] = (int) $conversion_row['conversions'];
		}

		$map = array();
		foreach ( $rows as $row ) {
			$row['conversions'] = isset( $conversions_by_date[ $row['stat_date'] ] ) ? $conversions_by_date[ $row['stat_date'] ] : 0;
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
					SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) conversion_events,
					SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) pageviews,
					COUNT(DISTINCT visitor_id) uniques,
					COUNT(DISTINCT session_id) sessions,
					COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END) converting_sessions
				 FROM $events WHERE created_at >= %s",
				self::today() . ' 00:00:00'
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();
		$agg = array(
			'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
			'conversions' => isset( $row['converting_sessions'] ) ? (int) $row['converting_sessions'] : 0,
			'conversion_events' => isset( $row['conversion_events'] ) ? (int) $row['conversion_events'] : 0,
			'pageviews'   => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			'uniques'     => isset( $row['uniques'] ) ? (int) $row['uniques'] : 0,
			'sessions'    => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
			'converting_sessions' => isset( $row['converting_sessions'] ) ? (int) $row['converting_sessions'] : 0,
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
		// Public compatibility wrapper. Scheduled catch-up calls Rollup_Manager
		// directly with force=false; explicit legacy callers expect a rebuild.
		return Rollup_Manager::rollup_day( $date, true );

		/* Legacy implementation retained for one compatibility release only. The
		 * early return above prevents its destructive delete-before-build path.
		 * It will be removed after downstream extension authors have migrated. */
		global $wpdb;

		$date = preg_replace( '/[^0-9\-]/', '', (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}

		$events  = self::events_table();
		$daily   = self::daily_table();
		$sources = self::sources_table();
		$geo     = self::geo_table();
		$search_terms = self::search_terms_table();
		$start   = $date . ' 00:00:00';
		$end     = $date . ' 23:59:59';

		// Clear existing buckets for the day so re-runs do not double count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $daily WHERE stat_date = %s", $date ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $sources WHERE stat_date = %s", $date ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $geo WHERE stat_date = %s", $date ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $search_terms WHERE stat_date = %s", $date ) );

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

		// Pageview buckets grouped by page (selector left empty). Pageview-level
		// conversions (a visit reaching a configured conversion URL) are counted
		// here so URL goals show up in the dashboard totals.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pv_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS pageviews, SUM(is_conversion) AS conversions,
				        COUNT(DISTINCT visitor_id) AS uniques
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
					'conversions'     => (int) $row['conversions'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}

		// Traffic-source buckets grouped by channel + campaign.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$src_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, utm_campaign AS campaign,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) AS pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) AS conversions,
				        COUNT(DISTINCT visitor_id) AS uniques
				 FROM $events
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY source, utm_campaign",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $src_rows as $row ) {
			$source = '' === (string) $row['source'] ? 'Direct' : (string) $row['source'];
			self::upsert_source_bucket(
				$date,
				$source,
				(string) $row['campaign'],
				array(
					'pageviews'       => (int) $row['pageviews'],
					'clicks'          => (int) $row['clicks'],
					'conversions'     => (int) $row['conversions'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}

		// Visitor-country buckets (rows with a resolved country only).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$geo_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) AS pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) AS conversions,
				        COUNT(DISTINCT visitor_id) AS uniques
				 FROM $events
				 WHERE country <> '' AND created_at BETWEEN %s AND %s
				 GROUP BY country",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $geo_rows as $row ) {
			self::upsert_geo_bucket(
				$date,
				(string) $row['country'],
				array(
					'pageviews'       => (int) $row['pageviews'],
					'clicks'          => (int) $row['clicks'],
					'conversions'     => (int) $row['conversions'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}

		// Search-keyword buckets grouped by page, keyword and traffic source.
		// Organic visits with no visible query are reported as not provided.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$term_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id,
				        CASE
							WHEN search_keyword <> '' THEN search_keyword
							WHEN source='Organic search' THEN '(not provided)'
							ELSE ''
						END AS keyword,
				        CASE
							WHEN search_keyword <> '' THEN search_source
							WHEN source='Organic search' THEN 'organic_not_provided'
							ELSE ''
						END AS keyword_source,
				        source AS traffic_source,
				        SUM(CASE WHEN event_type='pageview' THEN 1 ELSE 0 END) AS pageviews,
				        SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicks,
				        SUM(CASE WHEN is_conversion=1 THEN 1 ELSE 0 END) AS conversions,
				        COUNT(DISTINCT visitor_id) AS uniques
				 FROM $events
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY post_id, keyword, keyword_source, traffic_source
				 HAVING keyword <> ''",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $term_rows as $row ) {
			self::upsert_search_term_bucket(
				$date,
				(int) $row['post_id'],
				(string) $row['keyword'],
				(string) $row['keyword_source'],
				'' === (string) $row['traffic_source'] ? 'Direct' : (string) $row['traffic_source'],
				array(
					'pageviews'       => (int) $row['pageviews'],
					'clicks'          => (int) $row['clicks'],
					'conversions'     => (int) $row['conversions'],
					'unique_visitors' => (int) $row['uniques'],
				)
			);
		}
	}

	/**
	 * Upsert a single visitor-country bucket for a day.
	 *
	 * @param string $date    Y-m-d.
	 * @param string $country Two-letter country code.
	 * @param array  $metrics pageviews/clicks/conversions/unique_visitors.
	 */
	private static function upsert_geo_bucket( $date, $country, array $metrics ) {
		global $wpdb;

		$hash = md5( $date . '|geo|' . $country );
		$geo  = self::geo_table();

		$sql = "INSERT INTO $geo
			(bucket_hash, stat_date, country, pageviews, clicks, conversions, unique_visitors)
			VALUES (%s, %s, %s, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				pageviews = pageviews + VALUES(pageviews),
				clicks = clicks + VALUES(clicks),
				conversions = conversions + VALUES(conversions),
				unique_visitors = unique_visitors + VALUES(unique_visitors)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$hash,
				$date,
				$country,
				(int) $metrics['pageviews'],
				(int) $metrics['clicks'],
				(int) $metrics['conversions'],
				(int) $metrics['unique_visitors']
			)
		);
	}

	/**
	 * Upsert a single search-keyword bucket for a day.
	 *
	 * @param string $date           Y-m-d.
	 * @param int    $post_id        Post ID.
	 * @param string $keyword        Search keyword, or "(not provided)".
	 * @param string $keyword_source Keyword source label.
	 * @param string $traffic_source Traffic channel label.
	 * @param array  $metrics        pageviews/clicks/conversions/unique_visitors.
	 */
	private static function upsert_search_term_bucket( $date, $post_id, $keyword, $keyword_source, $traffic_source, array $metrics ) {
		global $wpdb;

		$hash         = md5( $date . '|search|' . (int) $post_id . '|' . $keyword_source . '|' . $keyword . '|' . $traffic_source );
		$search_terms = self::search_terms_table();

		$sql = "INSERT INTO $search_terms
			(bucket_hash, stat_date, post_id, search_keyword, search_source, traffic_source, pageviews, clicks, conversions, unique_visitors)
			VALUES (%s, %s, %d, %s, %s, %s, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				pageviews = pageviews + VALUES(pageviews),
				clicks = clicks + VALUES(clicks),
				conversions = conversions + VALUES(conversions),
				unique_visitors = unique_visitors + VALUES(unique_visitors)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$hash,
				$date,
				(int) $post_id,
				self::truncate( $keyword, 191 ),
				self::truncate( $keyword_source, 50 ),
				self::truncate( $traffic_source, 100 ),
				(int) $metrics['pageviews'],
				(int) $metrics['clicks'],
				(int) $metrics['conversions'],
				(int) $metrics['unique_visitors']
			)
		);
	}

	/**
	 * Cap a string to a maximum number of characters (multibyte-safe).
	 *
	 * @param string $value String.
	 * @param int    $len   Max characters.
	 * @return string
	 */
	private static function truncate( $value, $len ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $len );
		}
		return substr( $value, 0, $len );
	}

	/**
	 * Upsert a single traffic-source bucket for a day.
	 *
	 * @param string $date     Y-m-d.
	 * @param string $source   Channel label.
	 * @param string $campaign UTM campaign ('' if none).
	 * @param array  $metrics  pageviews/clicks/conversions/unique_visitors.
	 */
	private static function upsert_source_bucket( $date, $source, $campaign, array $metrics ) {
		global $wpdb;

		$hash    = md5( $date . '|' . $source . '|' . $campaign );
		$sources = self::sources_table();

		$sql = "INSERT INTO $sources
			(bucket_hash, stat_date, source, campaign, pageviews, clicks, conversions, unique_visitors)
			VALUES (%s, %s, %s, %s, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				pageviews = pageviews + VALUES(pageviews),
				clicks = clicks + VALUES(clicks),
				conversions = conversions + VALUES(conversions),
				unique_visitors = unique_visitors + VALUES(unique_visitors)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$hash,
				$date,
				$source,
				$campaign,
				(int) $metrics['pageviews'],
				(int) $metrics['clicks'],
				(int) $metrics['conversions'],
				(int) $metrics['unique_visitors']
			)
		);
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

		// Real upsert: a page-level pageview bucket (selector '') can share a hash
		// with a selector-less click bucket for the same page/day, so merge metrics
		// rather than letting the second INSERT fail silently.
		$sql = "INSERT INTO $daily
			(bucket_hash, stat_date, post_id, element_selector, element_text, clicks, conversions, pageviews, unique_visitors)
			VALUES (%s, %s, %d, %s, %s, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				element_text = IF(VALUES(element_text) <> '', VALUES(element_text), element_text),
				clicks = clicks + VALUES(clicks),
				conversions = conversions + VALUES(conversions),
				pageviews = pageviews + VALUES(pageviews),
				unique_visitors = unique_visitors + VALUES(unique_visitors)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$hash,
				$date,
				$post_id,
				$selector,
				$text,
				(int) $data['clicks'],
				(int) $data['conversions'],
				(int) $data['pageviews'],
				(int) $data['unique_visitors']
			)
		);
	}

	/**
	 * Delete raw events older than the retention window. Rollups are preserved.
	 *
	 * @param int $days Retention in days.
	 * @return int|\WP_Error Rows deleted in this batch or a write error.
	 */
	public static function purge_old_events( $days ) {
		global $wpdb;

		$days      = max( 1, (int) $days );
		$threshold = self::date_days_ago( $days ) . ' 00:00:00';
		$events    = self::events_table();
		$state     = self::rollup_state_table();

		// Bounded batch. A raw day is never deleted until its exact rollup has an
		// explicit completed watermark.
		$sql = "DELETE FROM $events WHERE id IN (
			SELECT id FROM (
				SELECT e.id FROM $events e INNER JOIN $state s ON s.stat_date=DATE(e.created_at)
				WHERE e.created_at < %s AND s.status='complete' ORDER BY e.id ASC LIMIT 10000
			) convertrack_purge_ids
		)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare( $sql, $threshold )
		);

		return false === $deleted ? new \WP_Error( 'convertrack_event_cleanup_failed', $wpdb->last_error ) : (int) $deleted;
	}

	/**
	 * Purge detailed aggregate dimensions after their supported report window.
	 *
	 * @param int $days Retention in days.
	 * @return int|\WP_Error Total rows deleted.
	 */
	public static function purge_old_aggregates( $days ) {
		global $wpdb;
		$days      = max( 32, (int) $days );
		$threshold = self::date_days_ago( $days );
		$tables    = array( self::daily_table(), self::sources_table(), self::geo_table(), self::search_terms_table(), self::visitor_days_table(), self::session_days_table() );
		$total     = 0;
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE stat_date < %s LIMIT 5000", $threshold ) );
			if ( false === $deleted ) {
				return new \WP_Error( 'convertrack_aggregate_cleanup_failed', $wpdb->last_error, array( 'table' => $table ) );
			}
			$total += (int) $deleted;
		}
		$state  = self::rollup_state_table();
		$events = self::events_table();
		$sql    = "DELETE FROM $state WHERE stat_date < %s AND NOT EXISTS (
			SELECT 1 FROM $events e WHERE e.created_at >= CONCAT($state.stat_date,' 00:00:00')
			AND e.created_at < DATE_ADD($state.stat_date,INTERVAL 1 DAY) LIMIT 1
		) LIMIT 5000";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( $sql, $threshold ) );
		if ( false === $deleted ) {
			return new \WP_Error( 'convertrack_rollup_state_cleanup_failed', $wpdb->last_error );
		}
		$total += (int) $deleted;
		return $total;
	}

	/**
	 * Storage/backlog diagnostics for Site Health and Settings.
	 *
	 * @return array
	 */
	public static function storage_health() {
		global $wpdb;
		$tables = array(
			'events'       => self::events_table(),
			'sessions'     => self::sessions_table(),
			'daily'        => self::daily_table(),
			'sources'      => self::sources_table(),
			'geo'          => self::geo_table(),
			'search_terms' => self::search_terms_table(),
			'visitor_days' => self::visitor_days_table(),
			'session_days' => self::session_days_table(),
		);
		$out = array();
		foreach ( $tables as $key => $table ) {
			// Metadata is intentionally read from information_schema; no table scan.
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ),
				ARRAY_A
			);
			$out[ $key ] = array(
				'rows_estimate' => isset( $row['TABLE_ROWS'] ) ? (int) $row['TABLE_ROWS'] : 0,
				'data_bytes'    => isset( $row['DATA_LENGTH'] ) ? (int) $row['DATA_LENGTH'] : 0,
				'index_bytes'   => isset( $row['INDEX_LENGTH'] ) ? (int) $row['INDEX_LENGTH'] : 0,
			);
		}
		$events = self::events_table();
		$state  = self::rollup_state_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['events']['oldest_record'] = $wpdb->get_var( "SELECT MIN(created_at) FROM $events" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['rollup_backlog_days'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $state WHERE status<>'complete'" );
		$out['last_cleanup'] = get_option( 'convertrack_last_cleanup', array() );
		return $out;
	}

	/**
	 * Delete stale presence rows (older than the retention window).
	 *
	 * @param int $seconds Inactivity threshold in seconds.
	 * @return int|\WP_Error Rows deleted in this batch.
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

		return false === $deleted ? new \WP_Error( 'convertrack_session_cleanup_failed', $wpdb->last_error ) : (int) $deleted;
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
