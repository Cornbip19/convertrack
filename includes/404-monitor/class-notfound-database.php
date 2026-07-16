<?php
/**
 * 404 Monitor data layer.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Database {

	const DB_VERSION        = '1.2.0';
	const DB_VERSION_OPTION = 'convertrack_404_db_version';
	const SUMMARY_CACHE_KEY = 'convertrack_404_summary';
	const RECOMMENDATION_GENERATION = 1;
	const MAX_RECOMMENDATION_ATTEMPTS = 3;
	const RECOMMENDATION_LEASE_SECONDS = 300;
	const REDIRECT_CACHE_GROUP = 'convertrack_404_redirects';
	const REDIRECT_CACHE_GENERATION_OPTION = 'convertrack_404_redirect_cache_generation';

	/**
	 * Request-local redirect cache, including negative lookups.
	 *
	 * @var array
	 */
	private static $redirect_cache = array();

	/**
	 * Request-local capture-budget schema check.
	 *
	 * @var bool|null
	 */
	private static $budget_table_ready = null;

	/**
	 * Detected 404 table.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_events';
	}

	/**
	 * Internal redirects table.
	 *
	 * @return string
	 */
	public static function redirects_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_redirects';
	}

	/**
	 * Cached valid URL candidates table.
	 *
	 * @return string
	 */
	public static function valid_urls_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_valid_urls';
	}

	/**
	 * Logs table.
	 *
	 * @return string
	 */
	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_logs';
	}

	/**
	 * Short-retention 404 hit buckets table.
	 *
	 * @return string
	 */
	public static function hit_buckets_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_hit_buckets';
	}

	/**
	 * Atomic capture budget counters table.
	 *
	 * @return string
	 */
	public static function rate_limits_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_404_rate_limits';
	}

	/**
	 * Create or update tables.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		$lock_option = 'convertrack_404_schema_lock';
		$lock_owner  = \Convertrack\Owner_Lock::acquire( $lock_option, 300 );
		if ( false === $lock_owner ) {
			return new \WP_Error( 'convertrack_404_migration_locked', __( 'Another Broken URLs schema migration is already running.', 'convertrack-click-conversion-analytics' ) );
		}

		try {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$redirects       = self::redirects_table();
		$valid           = self::valid_urls_table();
		$logs            = self::logs_table();
		$hit_buckets     = self::hit_buckets_table();
		$rate_limits     = self::rate_limits_table();
		$existed         = array(
			$events    => self::table_exists( $events ),
			$redirects => self::table_exists( $redirects ),
			$valid     => self::table_exists( $valid ),
			$logs      => self::table_exists( $logs ),
			$hit_buckets => self::table_exists( $hit_buckets ),
			$rate_limits => self::table_exists( $rate_limits ),
		);

		$sql = array();

		$sql[] = "CREATE TABLE $events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			query_string varchar(512) NOT NULL DEFAULT '',
			referrer_url varchar(2048) NOT NULL DEFAULT '',
			user_agent_hash char(32) NOT NULL DEFAULT '',
			first_detected_at datetime NOT NULL,
			last_detected_at datetime NOT NULL,
			hit_count int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(40) NOT NULL DEFAULT 'new',
			recommendation_generation bigint(20) unsigned NOT NULL DEFAULT 1,
			processed_generation bigint(20) unsigned NOT NULL DEFAULT 0,
			recommendation_state varchar(20) NOT NULL DEFAULT 'pending',
			recommendation_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			claim_owner varchar(64) NOT NULL DEFAULT '',
			claim_expires_at datetime NULL DEFAULT NULL,
			last_attempt_at datetime NULL DEFAULT NULL,
			processed_at datetime NULL DEFAULT NULL,
			suggested_url varchar(2048) NOT NULL DEFAULT '',
			suggested_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			suggested_post_type varchar(100) NOT NULL DEFAULT '',
			confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			match_reason varchar(255) NOT NULL DEFAULT '',
			destination_type varchar(50) NOT NULL DEFAULT '',
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status),
			KEY confidence (confidence),
			KEY last_detected_at (last_detected_at),
			KEY hit_count (hit_count),
			KEY suggested_post_type (suggested_post_type),
			KEY recommendation_queue (recommendation_state, recommendation_attempts, claim_expires_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $redirects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash char(32) NOT NULL DEFAULT '',
			source_url varchar(2048) NOT NULL,
			source_path varchar(512) NOT NULL DEFAULT '',
			destination_url varchar(2048) NOT NULL,
			redirect_type smallint(3) unsigned NOT NULL DEFAULT 301,
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(60) NOT NULL DEFAULT 'internal',
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			last_hit_at datetime NULL DEFAULT NULL,
			hit_count int(10) unsigned NOT NULL DEFAULT 0,
			health_status varchar(20) NOT NULL DEFAULT 'unknown',
			health_code smallint(3) unsigned NOT NULL DEFAULT 0,
			health_error varchar(500) NOT NULL DEFAULT '',
			last_checked_at datetime NULL DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY status (status),
			KEY last_hit_at (last_hit_at),
			KEY event_id (event_id),
			KEY health_check (status, last_checked_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $valid (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			tokens text NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(100) NOT NULL DEFAULT '',
			taxonomy varchar(100) NOT NULL DEFAULT '',
			term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(60) NOT NULL DEFAULT '',
			priority int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_seen_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY post_type (post_type),
			KEY source (source),
			KEY last_seen_at (last_seen_at),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			source varchar(60) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level_created (level, created_at),
			KEY source_created (source, created_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $hit_buckets (
			bucket_start datetime NOT NULL,
			url_hash char(32) NOT NULL DEFAULT '',
			hit_count int(10) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (bucket_start, url_hash),
			KEY bucket_start (bucket_start)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $rate_limits (
			bucket_key char(64) NOT NULL DEFAULT '',
			bucket_start datetime NOT NULL,
			hit_count int(10) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (bucket_key),
			KEY expires_at (expires_at)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
			if ( ! empty( $wpdb->last_error ) ) {
				self::rollback_install( $existed );
				return new \WP_Error( 'convertrack_404_db_error', $wpdb->last_error );
			}
		}

		foreach ( array_keys( $existed ) as $table ) {
			if ( ! self::table_exists( $table ) ) {
				self::rollback_install( $existed );
				return new \WP_Error( 'convertrack_404_db_missing', __( '404 Monitor tables could not be created.', 'convertrack-click-conversion-analytics' ) );
			}
		}

		$migrated = self::migrate_recommendation_state();
		if ( is_wp_error( $migrated ) ) {
			self::rollback_install( $existed );
			return $migrated;
		}
		$verified = self::verify_schema();
		if ( is_wp_error( $verified ) ) {
			self::rollback_install( $existed );
			return $verified;
		}

		if ( ! update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false ) && self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			return new \WP_Error( 'convertrack_404_db_version_write', __( 'The Broken URLs schema version could not be saved.', 'convertrack-click-conversion-analytics' ) );
		}
		return true;
		} finally {
			\Convertrack\Owner_Lock::release( $lock_option, $lock_owner );
		}
	}

	/**
	 * Upgrade when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return true;
		}
		$result = self::install();
		if ( is_wp_error( $result ) ) {
			if ( 'convertrack_404_migration_locked' !== $result->get_error_code() ) {
				$settings            = Settings::all();
				$settings['enabled'] = 0;
				Settings::save( $settings );
			}
			set_transient( 'convertrack_404_migration_error', $result->get_error_message(), HOUR_IN_SECONDS );
			return $result;
		}
		delete_transient( 'convertrack_404_migration_error' );
		return true;
	}

	/** Whether the verified schema watermark and current structure agree. */
	public static function schema_is_healthy() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) && ! is_wp_error( self::verify_schema() );
	}

	/**
	 * Drop module tables.
	 */
	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::events_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::redirects_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::valid_urls_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::logs_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::hit_buckets_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::rate_limits_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Normalize a request URL into a local path/query structure.
	 *
	 * @param string $url Raw URL or path.
	 * @return array
	 */
	public static function normalize_source( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return array();
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array();
		}
		if ( empty( $parts['path'] ) ) {
			$parts['path'] = '/';
		}

		if ( ! empty( $parts['host'] ) ) {
			if ( ! self::is_home_origin( $parts ) || ! self::path_is_in_home( (string) $parts['path'] ) ) {
				return array();
			}
		}

		$path = '/' . ltrim( rawurldecode( (string) $parts['path'] ), '/' );
		$path = preg_replace( '#/+#', '/', $path );
		$path = '/' === $path ? '/' : untrailingslashit( $path ) . '/';
		$path = strtolower( preg_replace( '/[\x00-\x1F\x7F]/', '', $path ) );

		$query = isset( $parts['query'] ) ? self::normalize_query( (string) $parts['query'] ) : '';
		$value = $path . ( '' !== $query ? '?' . $query : '' );

		return array(
			'url'   => self::truncate( $value, 2048 ),
			'path'  => self::truncate( $path, 512 ),
			'query' => self::truncate( $query, 512 ),
			'hash'  => md5( $value ),
		);
	}

	/**
	 * Normalize a valid destination/candidate URL.
	 *
	 * @param string $url Raw URL.
	 * @return array
	 */
	public static function normalize_destination( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array();
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return array();
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( rtrim( $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$path = isset( $parts['path'] ) ? '/' . ltrim( rawurldecode( $parts['path'] ), '/' ) : '/';
		$path = preg_replace( '#/+#', '/', $path );
		$path = '/' === $path ? '/' : untrailingslashit( $path ) . '/';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . ltrim( (string) $parts['query'], '?' ) : '';
		$default_port = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
		$origin = $scheme . '://' . $host . ( $default_port ? '' : ':' . $port );
		$full  = trailingslashit( $origin ) . ltrim( $path, '/' );
		if ( '/' === $path ) {
			$full = $origin . '/';
		}
		$full .= $query;
		$full = esc_url_raw( $full, array( 'http', 'https' ) );

		return array(
			'url'  => self::truncate( $full, 2048 ),
			'path' => self::truncate( strtolower( $path ), 512 ),
			'hash' => md5( strtolower( $full ) ),
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
		);
	}

	/**
	 * Record or update a detected 404 URL.
	 *
	 * @param string $url      URL/path.
	 * @param string $referrer Referrer URL.
	 * @param string $ua       User agent.
	 * @return int|\WP_Error
	 */
	public static function record_404( $url, $referrer = '', $ua = '' ) {
		global $wpdb;

		$source = self::normalize_source( $url );
		if ( empty( $source ) ) {
			return new \WP_Error( 'convertrack_404_invalid_source', __( 'The 404 URL could not be normalized.', 'convertrack-click-conversion-analytics' ) );
		}

		$table = self::events_table();
		$now   = current_time( 'mysql' );
		$referrer = self::sanitize_referrer( $referrer );
		$ua_hash  = '' !== $ua ? md5( self::truncate( $ua, 1000 ) ) : '';
		$sql      = $wpdb->prepare(
			"INSERT INTO $table
			(url_hash,url,path,query_string,referrer_url,user_agent_hash,first_detected_at,last_detected_at,hit_count,status,recommendation_generation,processed_generation,recommendation_state,recommendation_attempts,claim_owner,created_at,updated_at)
			VALUES (%s,%s,%s,%s,%s,%s,%s,%s,1,'new',%d,0,'pending',0,'',%s,%s)
			ON DUPLICATE KEY UPDATE
			id = LAST_INSERT_ID(id),
			last_detected_at = VALUES(last_detected_at),
			hit_count = hit_count + 1,
			referrer_url = IF(VALUES(referrer_url) <> '', VALUES(referrer_url), referrer_url),
			user_agent_hash = IF(VALUES(user_agent_hash) <> '', VALUES(user_agent_hash), user_agent_hash),
			recommendation_state = IF(status IN ('ignored','deleted','archived','suppressed'), 'pending', recommendation_state),
			recommendation_attempts = IF(status IN ('ignored','deleted','archived','suppressed'), 0, recommendation_attempts),
			processed_generation = IF(status IN ('ignored','deleted','archived','suppressed'), 0, processed_generation),
			processed_at = IF(status IN ('ignored','deleted','archived','suppressed'), NULL, processed_at),
			claim_owner = IF(status IN ('ignored','deleted','archived','suppressed'), '', claim_owner),
			claim_expires_at = IF(status IN ('ignored','deleted','archived','suppressed'), NULL, claim_expires_at),
			status = IF(status IN ('ignored','deleted','archived','suppressed'), 'new', status),
			updated_at = VALUES(updated_at)",
			$source['hash'],
			$source['url'],
			$source['path'],
			$source['query'],
			$referrer,
			$ua_hash,
			$now,
			$now,
			self::RECOMMENDATION_GENERATION,
			$now,
			$now
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_event_write_failed', __( 'The 404 event could not be stored.', 'convertrack-click-conversion-analytics' ) );
		}

		$event_id = (int) $wpdb->insert_id;
		$bucket   = self::record_hit_bucket( $source['hash'] );
		self::clear_summary_cache();
		if ( is_wp_error( $bucket ) ) {
			$bucket->add_data( array( 'event_id' => $event_id ) );
			return $bucket;
		}

		return $event_id;
	}

	/**
	 * Fetch events needing recommendations.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recommendation_batch( $limit ) {
		global $wpdb;
		$table = self::events_table();
		$limit = max( 1, min( 500, (int) $limit ) );
		$now   = current_time( 'mysql' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table
				WHERE status = 'new' AND recommendation_state = 'pending'
				AND recommendation_attempts < %d
				AND (claim_owner = '' OR claim_expires_at IS NULL OR claim_expires_at <= %s)
				ORDER BY hit_count DESC, last_detected_at DESC
				LIMIT %d",
				self::MAX_RECOMMENDATION_ATTEMPTS,
				$now,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Atomically claim a bounded recommendation batch.
	 *
	 * Expired leases may be reclaimed, but attempts are capped. Completed and
	 * manual-review rows never return to this queue unless explicitly reopened.
	 *
	 * @param int    $limit         Batch size.
	 * @param string $owner         Unique worker owner token.
	 * @param int    $lease_seconds Lease lifetime.
	 * @return array|\WP_Error
	 */
	public static function claim_recommendations( $limit, $owner, $lease_seconds = self::RECOMMENDATION_LEASE_SECONDS ) {
		global $wpdb;
		$table = self::events_table();
		$limit = max( 1, min( 500, (int) $limit ) );
		$owner = self::truncate( sanitize_text_field( $owner ), 64 );
		if ( '' === $owner ) {
			return new \WP_Error( 'convertrack_404_claim_owner_missing', __( 'The recommendation worker owner token is missing.', 'convertrack-click-conversion-analytics' ) );
		}

		$now     = current_time( 'mysql' );
		$expires = self::mysql_time( max( 30, min( 900, (int) $lease_seconds ) ) );
		$terminalized = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET recommendation_state = 'failed', status = 'manual_review', processed_at = %s,
				error_message = %s, claim_owner = '', claim_expires_at = NULL, updated_at = %s
				WHERE status = 'new' AND recommendation_state IN ('pending','processing') AND recommendation_attempts >= %d
				AND (claim_owner = '' OR claim_expires_at IS NULL OR claim_expires_at <= %s)",
				$now,
				__( 'Recommendation processing stopped after the maximum retry count.', 'convertrack-click-conversion-analytics' ),
				$now,
				self::MAX_RECOMMENDATION_ATTEMPTS,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $terminalized ) {
			return self::write_error( 'convertrack_404_claim_cleanup_failed', __( 'Expired recommendation claims could not be finalized.', 'convertrack-click-conversion-analytics' ) );
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET recommendation_state = 'processing', claim_owner = %s, claim_expires_at = %s,
				recommendation_attempts = recommendation_attempts + 1, last_attempt_at = %s, updated_at = %s
				WHERE status = 'new' AND recommendation_attempts < %d
				AND ((recommendation_state = 'pending' AND (claim_owner = '' OR claim_expires_at IS NULL OR claim_expires_at <= %s))
				OR (recommendation_state = 'processing' AND claim_expires_at IS NOT NULL AND claim_expires_at <= %s))
				ORDER BY hit_count DESC, last_detected_at DESC, id ASC LIMIT %d",
				$owner,
				$expires,
				$now,
				$now,
				self::MAX_RECOMMENDATION_ATTEMPTS,
				$now,
				$now,
				$limit
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $claimed ) {
			return self::write_error( 'convertrack_404_claim_failed', __( '404 recommendations could not be claimed.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( 0 === (int) $claimed ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE recommendation_state = 'processing' AND claim_owner = %s ORDER BY hit_count DESC, last_detected_at DESC, id ASC",
				$owner
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $rows && ! empty( $wpdb->last_error ) ) {
			return self::write_error( 'convertrack_404_claim_read_failed', __( 'Claimed 404 recommendations could not be read.', 'convertrack-click-conversion-analytics' ) );
		}

		return (array) $rows;
	}

	/**
	 * Save recommendation fields for one event.
	 *
	 * @param int   $id     Event ID.
	 * @param array  $result     Match result.
	 * @param string $owner      Claim owner token.
	 * @param int    $generation Immutable row generation.
	 * @return true|\WP_Error
	 */
	public static function save_recommendation( $id, array $result, $owner = '', $generation = 0 ) {
		global $wpdb;
		$status = ! empty( $result['url'] ) ? 'recommended' : 'manual_review';
		if ( isset( $result['confidence'] ) && (int) $result['confidence'] < 50 ) {
			$status = 'manual_review';
		}
		$owner      = self::truncate( sanitize_text_field( $owner ), 64 );
		$generation = absint( $generation );
		if ( '' === $owner || 0 === $generation ) {
			return new \WP_Error( 'convertrack_404_invalid_claim', __( 'The recommendation claim is invalid.', 'convertrack-click-conversion-analytics' ) );
		}

		$table  = self::events_table();
		$now    = current_time( 'mysql' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = %s, suggested_url = %s, suggested_post_id = %d,
				suggested_post_type = %s, confidence = %d, match_reason = %s, destination_type = %s,
				error_message = '', recommendation_state = 'completed', processed_generation = %d,
				processed_at = %s, claim_owner = '', claim_expires_at = NULL, updated_at = %s
				WHERE id = %d AND recommendation_state = 'processing' AND claim_owner = %s
				AND recommendation_generation = %d",
				$status,
				isset( $result['url'] ) ? esc_url_raw( $result['url'] ) : '',
				isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0,
				isset( $result['post_type'] ) ? sanitize_key( $result['post_type'] ) : '',
				isset( $result['confidence'] ) ? min( 100, absint( $result['confidence'] ) ) : 0,
				isset( $result['reason'] ) ? self::truncate( sanitize_text_field( $result['reason'] ), 255 ) : '',
				isset( $result['destination_type'] ) ? sanitize_key( $result['destination_type'] ) : '',
				$generation,
				$now,
				$now,
				(int) $id,
				$owner,
				$generation
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_recommendation_write_failed', __( 'The 404 recommendation could not be stored.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( 0 === (int) $result ) {
			return new \WP_Error( 'convertrack_404_claim_lost', __( 'The recommendation claim expired or was taken over before it could be saved.', 'convertrack-click-conversion-analytics' ) );
		}

		self::clear_summary_cache();
		return true;
	}

	/**
	 * Release or terminalize a failed recommendation claim.
	 *
	 * @param int    $id      Event ID.
	 * @param string $owner   Claim owner.
	 * @param string $message Failure detail.
	 * @return true|\WP_Error
	 */
	public static function fail_recommendation( $id, $owner, $message ) {
		global $wpdb;
		$table   = self::events_table();
		$owner   = self::truncate( sanitize_text_field( $owner ), 64 );
		$message = self::truncate( sanitize_text_field( $message ), 1000 );
		$now     = current_time( 'mysql' );
		$result  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET
				recommendation_state = IF(recommendation_attempts >= %d, 'failed', 'pending'),
				status = IF(recommendation_attempts >= %d, 'manual_review', status),
				processed_at = IF(recommendation_attempts >= %d, %s, NULL), error_message = %s,
				claim_owner = '', claim_expires_at = NULL, updated_at = %s
				WHERE id = %d AND recommendation_state = 'processing' AND claim_owner = %s",
				self::MAX_RECOMMENDATION_ATTEMPTS,
				self::MAX_RECOMMENDATION_ATTEMPTS,
				self::MAX_RECOMMENDATION_ATTEMPTS,
				$now,
				$message,
				$now,
				(int) $id,
				$owner
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_claim_release_failed', __( 'The failed recommendation claim could not be released.', 'convertrack-click-conversion-analytics' ) );
		}
		return true;
	}

	/**
	 * Whether any unclaimed or reclaimable recommendation work remains.
	 *
	 * @return bool
	 */
	public static function has_pending_recommendations() {
		global $wpdb;
		$table = self::events_table();
		$now   = current_time( 'mysql' );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE status = 'new' AND recommendation_attempts < %d AND
				((recommendation_state = 'pending' AND (claim_owner = '' OR claim_expires_at IS NULL OR claim_expires_at <= %s))
				OR (recommendation_state = 'processing' AND claim_expires_at IS NOT NULL AND claim_expires_at <= %s))",
				self::MAX_RECOMMENDATION_ATTEMPTS,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $count > 0;
	}

	/**
	 * List events with filters.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_events( array $args ) {
		global $wpdb;
		$table    = self::events_table();
		$where    = array( "status <> 'deleted'" );
		$prepare  = array();
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 25 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]   = 'status = %s';
			$prepare[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['post_type'] ) && 'all' !== $args['post_type'] ) {
			$where[]   = 'suggested_post_type = %s';
			$prepare[] = sanitize_key( $args['post_type'] );
		}
		if ( isset( $args['confidence_min'] ) && '' !== $args['confidence_min'] ) {
			$where[]   = 'confidence >= %d';
			$prepare[] = absint( $args['confidence_min'] );
		}
		if ( isset( $args['confidence_max'] ) && '' !== $args['confidence_max'] ) {
			$where[]   = 'confidence <= %d';
			$prepare[] = absint( $args['confidence_max'] );
		}
		if ( ! empty( $args['detected_from'] ) ) {
			$where[]   = 'last_detected_at >= %s';
			$prepare[] = sanitize_text_field( $args['detected_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $args['detected_to'] ) ) {
			$where[]   = 'last_detected_at <= %s';
			$prepare[] = sanitize_text_field( $args['detected_to'] ) . ' 23:59:59';
		}
		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]   = '(url LIKE %s OR referrer_url LIKE %s OR suggested_url LIKE %s)';
			$prepare[] = $like;
			$prepare[] = $like;
			$prepare[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$rows_sql  = "SELECT * FROM $table WHERE $where_sql ORDER BY hit_count DESC, last_detected_at DESC, id DESC LIMIT %d OFFSET %d";

		$total = ! empty( $prepare ) ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $prepare ) ) : (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows_prepare = array_merge( $prepare, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_prepare ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'     => array_map( array( __CLASS__, 'decorate_event' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Stream an export page using an ID cursor instead of OFFSET.
	 *
	 * @param array $args     Event filters.
	 * @param int   $after_id Last exported ID.
	 * @param int   $limit    Batch size.
	 * @return array {rows,cursor,done}
	 */
	public static function export_events_cursor( array $args, $after_id = 0, $limit = 500 ) {
		global $wpdb;
		$table   = self::events_table();
		$where   = array( "status <> 'deleted'", 'id > %d' );
		$prepare = array( max( 0, (int) $after_id ) );
		$limit   = max( 1, min( 1000, (int) $limit ) );

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]   = 'status = %s';
			$prepare[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['post_type'] ) && 'all' !== $args['post_type'] ) {
			$where[]   = 'suggested_post_type = %s';
			$prepare[] = sanitize_key( $args['post_type'] );
		}
		if ( isset( $args['confidence_min'] ) && '' !== $args['confidence_min'] ) {
			$where[]   = 'confidence >= %d';
			$prepare[] = absint( $args['confidence_min'] );
		}
		if ( isset( $args['confidence_max'] ) && '' !== $args['confidence_max'] ) {
			$where[]   = 'confidence <= %d';
			$prepare[] = absint( $args['confidence_max'] );
		}
		if ( ! empty( $args['detected_from'] ) ) {
			$where[]   = 'last_detected_at >= %s';
			$prepare[] = sanitize_text_field( $args['detected_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $args['detected_to'] ) ) {
			$where[]   = 'last_detected_at <= %s';
			$prepare[] = sanitize_text_field( $args['detected_to'] ) . ' 23:59:59';
		}
		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]   = '(url LIKE %s OR referrer_url LIKE %s OR suggested_url LIKE %s)';
			$prepare[] = $like;
			$prepare[] = $like;
			$prepare[] = $like;
		}

		$prepare[] = $limit;
		$sql       = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( $sql, $prepare ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$decorated = array_map( array( __CLASS__, 'decorate_event' ), $rows );
		$cursor    = empty( $rows ) ? (int) $after_id : (int) $rows[ count( $rows ) - 1 ]['id'];
		return array(
			'rows'   => $decorated,
			'cursor' => $cursor,
			'done'   => count( $rows ) < $limit,
		);
	}

	/**
	 * Summary counts.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		$cached = get_transient( self::SUMMARY_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$events    = self::events_table();
		$redirects = self::redirects_table();
		$counts    = array(
			'total'       => 0,
			'unresolved'  => 0,
			'recommended' => 0,
			'redirected'  => 0,
			'ignored'     => 0,
			'manual'      => 0,
			'redirect_hits' => 0,
			'spike_hits'  => 0,
		);

		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) total FROM $events WHERE status <> 'deleted' GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$status = (string) $row['status'];
			$total  = (int) $row['total'];
			$counts['total'] += $total;
			if ( 'new' === $status ) {
				$counts['unresolved'] += $total;
			} elseif ( 'recommended' === $status ) {
				$counts['recommended'] += $total;
			} elseif ( in_array( $status, array( 'approved', 'auto_redirected' ), true ) ) {
				$counts['redirected'] += $total;
			} elseif ( 'ignored' === $status ) {
				$counts['ignored'] += $total;
			} elseif ( 'manual_review' === $status ) {
				$counts['manual'] += $total;
			}
		}

		$counts['redirect_hits'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(hit_count),0) FROM $redirects" ); // phpcs:ignore WordPress.DB

		$window = max( 5, (int) Settings::get( 'spike_window_minutes', 60 ) );
		$since  = self::mysql_time( - $window * MINUTE_IN_SECONDS );
		$buckets = self::hit_buckets_table();
		$counts['spike_hits'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hit_count),0) FROM $buckets WHERE bucket_start >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['spike_threshold'] = (int) Settings::get( 'spike_threshold', 50 );
		$counts['settings'] = Settings::all();
		$counts['compatibility'] = Compatibility::status();

		set_transient( self::SUMMARY_CACHE_KEY, $counts, 2 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Get one event.
	 *
	 * @param int $id Event ID.
	 * @return array|null
	 */
	public static function get_event( $id ) {
		global $wpdb;
		$table = self::events_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decorate_event( $row ) : null;
	}

	/**
	 * Update event status.
	 *
	 * @param int    $id     Event ID.
	 * @param string $status Status.
	 * @return true|\WP_Error
	 */
	public static function set_event_status( $id, $status ) {
		$status = sanitize_key( $status );
		$data   = array( 'status' => $status );
		if ( in_array( $status, array( 'ignored', 'deleted', 'archived', 'suppressed' ), true ) ) {
			$data['recommendation_state'] = 'completed';
			$data['processed_at']         = current_time( 'mysql' );
			$data['claim_owner']          = '';
			$data['claim_expires_at']     = null;
		}
		return self::update_event( $id, $data );
	}

	/**
	 * Update suggested destination without creating a redirect.
	 *
	 * @param int    $id          Event ID.
	 * @param string $destination Destination URL.
	 * @return true|\WP_Error
	 */
	public static function update_suggestion( $id, $destination ) {
		return self::update_event(
			$id,
			array(
				'suggested_url' => esc_url_raw( $destination ),
				'status'        => 'recommended',
				'recommendation_state' => 'completed',
				'processed_generation' => self::RECOMMENDATION_GENERATION,
				'processed_at' => current_time( 'mysql' ),
				'claim_owner' => '',
				'claim_expires_at' => null,
			)
		);
	}

	/**
	 * Create or update an internal redirect.
	 *
	 * @param string $source      Source path/url.
	 * @param string $destination Destination URL.
	 * @param int    $event_id    Linked event.
	 * @param string $status      Redirect status.
	 * @return int|\WP_Error
	 */
	public static function upsert_redirect( $source, $destination, $event_id = 0, $status = 'active' ) {
		global $wpdb;
		$src = self::normalize_source( $source );
		$dst = self::normalize_destination( $destination );
		if ( empty( $src ) || empty( $dst ) ) {
			return new \WP_Error( 'convertrack_404_bad_redirect_url', __( 'Source or destination URL is invalid.', 'convertrack-click-conversion-analytics' ) );
		}

		$table = self::redirects_table();
		$now   = current_time( 'mysql' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table
				(source_hash,source_url,source_path,destination_url,redirect_type,status,source,event_id,created_by,created_at,health_status,health_code,health_error,last_checked_at,updated_at)
				VALUES (%s,%s,%s,%s,301,%s,'internal',%d,%d,%s,'healthy',200,'',%s,%s)
				ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), source_url = VALUES(source_url),
				source_path = VALUES(source_path), destination_url = VALUES(destination_url), redirect_type = 301,
				status = VALUES(status), source = 'internal', event_id = VALUES(event_id), created_by = VALUES(created_by),
				health_status = 'healthy', health_code = 200, health_error = '', last_checked_at = VALUES(last_checked_at), updated_at = VALUES(updated_at)",
				$src['hash'],
				$src['url'],
				$src['path'],
				$dst['url'],
				sanitize_key( $status ),
				absint( $event_id ),
				get_current_user_id(),
				$now,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_redirect_write_failed', __( 'The redirect rule could not be stored.', 'convertrack-click-conversion-analytics' ) );
		}
		$id = (int) $wpdb->insert_id;
		self::invalidate_redirect_cache( $src['url'] );
		self::clear_summary_cache();
		return $id;
	}

	/**
	 * Find an active internal redirect by source URL/path.
	 *
	 * @param string $source Source URL/path.
	 * @return array|null
	 */
	public static function find_active_redirect( $source ) {
		global $wpdb;
		$src = self::normalize_source( $source );
		if ( empty( $src ) ) {
			return null;
		}
		$key = self::redirect_cache_key( $src['hash'] );
		if ( array_key_exists( $key, self::$redirect_cache ) ) {
			return self::$redirect_cache[ $key ];
		}
		$found  = false;
		$cached = wp_cache_get( $key, self::REDIRECT_CACHE_GROUP, false, $found );
		if ( $found ) {
			self::$redirect_cache[ $key ] = is_array( $cached ) && empty( $cached['_not_found'] ) ? $cached : null;
			return self::$redirect_cache[ $key ];
		}

		$table = self::redirects_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE source_hash = %s AND status = 'active'", $src['hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $row ? self::decorate_redirect( $row ) : null;
		self::$redirect_cache[ $key ] = $value;
		wp_cache_set( $key, null === $value ? array( '_not_found' => 1 ) : $value, self::REDIRECT_CACHE_GROUP, null === $value ? 60 : 300 );
		return $value;
	}

	/**
	 * Get one internal redirect.
	 *
	 * @param int $id Redirect ID.
	 * @return array|null
	 */
	public static function get_redirect( $id ) {
		global $wpdb;
		$table = self::redirects_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decorate_redirect( $row ) : null;
	}

	/**
	 * Count redirect hit.
	 *
	 * @param int $id Redirect ID.
	 * @return true|\WP_Error
	 */
	public static function record_redirect_hit( $id ) {
		global $wpdb;
		$table = self::redirects_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE $table SET hit_count = hit_count + 1, last_hit_at = %s, updated_at = %s WHERE id = %d", current_time( 'mysql' ), current_time( 'mysql' ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_redirect_hit_failed', __( 'The redirect hit could not be recorded.', 'convertrack-click-conversion-analytics' ) );
		}
		self::clear_summary_cache();
		return true;
	}

	/**
	 * List internal redirects.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_redirects( array $args = array() ) {
		global $wpdb;
		$table = self::redirects_table();
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 100;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY updated_at DESC, id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decorate_redirect' ), (array) $rows );
	}

	/**
	 * Update redirect status.
	 *
	 * @param int    $id     Redirect ID.
	 * @param string $status Status.
	 * @return true|\WP_Error
	 */
	public static function set_redirect_status( $id, $status ) {
		global $wpdb;
		$redirect = self::get_redirect( $id );
		if ( ! $redirect ) {
			return new \WP_Error( 'convertrack_404_redirect_missing', __( 'The redirect rule was not found.', 'convertrack-click-conversion-analytics' ) );
		}
		$result = $wpdb->update(
			self::redirects_table(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_redirect_status_failed', __( 'The redirect status could not be changed.', 'convertrack-click-conversion-analytics' ) );
		}
		self::invalidate_redirect_cache( $redirect['source_url'] );
		if ( 'active' !== sanitize_key( $status ) ) {
			$reopened = self::reopen_linked_event( $redirect );
			if ( is_wp_error( $reopened ) ) {
				return $reopened;
			}
		}
		self::clear_summary_cache();
		return true;
	}

	/**
	 * Delete redirect.
	 *
	 * @param int $id Redirect ID.
	 * @return true|\WP_Error
	 */
	public static function delete_redirect( $id ) {
		global $wpdb;
		$redirect = self::get_redirect( $id );
		if ( ! $redirect ) {
			return new \WP_Error( 'convertrack_404_redirect_missing', __( 'The redirect rule was not found.', 'convertrack-click-conversion-analytics' ) );
		}
		$result = $wpdb->delete( self::redirects_table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result || 0 === (int) $result ) {
			return self::write_error( 'convertrack_404_redirect_delete_failed', __( 'The redirect rule could not be deleted.', 'convertrack-click-conversion-analytics' ) );
		}
		self::invalidate_redirect_cache( $redirect['source_url'] );
		$reopened = self::reopen_linked_event( $redirect );
		if ( is_wp_error( $reopened ) ) {
			return $reopened;
		}
		self::clear_summary_cache();
		return true;
	}

	/**
	 * Active redirect destinations due for background validation.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	public static function redirects_for_health_check( $limit = 25 ) {
		global $wpdb;
		$table  = self::redirects_table();
		$limit  = max( 1, min( 100, (int) $limit ) );
		$before = self::mysql_time( - DAY_IN_SECONDS );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status = 'active' AND (last_checked_at IS NULL OR last_checked_at < %s) ORDER BY COALESCE(last_checked_at,'1970-01-01 00:00:00') ASC, id ASC LIMIT %d",
				$before,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Store a background destination-health result.
	 *
	 * @param int             $id     Redirect ID.
	 * @param array|\WP_Error $health Health result.
	 * @return true|\WP_Error
	 */
	public static function set_redirect_health( $id, $health ) {
		global $wpdb;
		$data = array(
			'health_status'   => is_wp_error( $health ) ? 'unhealthy' : 'healthy',
			'health_code'     => is_wp_error( $health ) ? 0 : ( isset( $health['code'] ) ? absint( $health['code'] ) : 200 ),
			'health_error'    => is_wp_error( $health ) ? self::truncate( sanitize_text_field( $health->get_error_message() ), 500 ) : '',
			'last_checked_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);
		$result = $wpdb->update( self::redirects_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_redirect_health_failed', __( 'Redirect destination health could not be stored.', 'convertrack-click-conversion-analytics' ) );
		}
		return true;
	}

	/**
	 * Add/update a valid URL candidate.
	 *
	 * @param string $url  URL.
	 * @param array  $args Metadata.
	 * @return int|\WP_Error
	 */
	public static function upsert_valid_url( $url, array $args = array() ) {
		global $wpdb;
		$dst = self::normalize_destination( $url );
		if ( empty( $dst ) ) {
			return new \WP_Error( 'convertrack_404_invalid_candidate', __( 'The valid URL candidate is invalid.', 'convertrack-click-conversion-analytics' ) );
		}

		$table = self::valid_urls_table();
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE url_hash = %s", $dst['hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slug  = basename( untrailingslashit( $dst['path'] ) );

		$data = array(
			'url'          => $dst['url'],
			'path'         => $dst['path'],
			'slug'         => self::truncate( sanitize_title( $slug ), 191 ),
			'tokens'       => isset( $args['tokens'] ) ? self::truncate( sanitize_text_field( $args['tokens'] ), 1000 ) : '',
			'post_id'      => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'post_type'    => isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '',
			'taxonomy'     => isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '',
			'term_id'      => isset( $args['term_id'] ) ? absint( $args['term_id'] ) : 0,
			'source'       => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'wordpress',
			'priority'     => isset( $args['priority'] ) ? absint( $args['priority'] ) : 0,
			'status'       => 'active',
			'last_seen_at' => $now,
			'updated_at'   => $now,
		);

		if ( $row ) {
			$updated = $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $updated ) {
				return self::write_error( 'convertrack_404_candidate_write_failed', __( 'The valid URL candidate could not be updated.', 'convertrack-click-conversion-analytics' ) );
			}
			return (int) $row['id'];
		}

		$data['url_hash']   = $dst['hash'];
		$data['created_at'] = $now;
		$inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $inserted ? (int) $wpdb->insert_id : self::write_error( 'convertrack_404_candidate_write_failed', __( 'The valid URL candidate could not be stored.', 'convertrack-click-conversion-analytics' ) );
	}

	/**
	 * Mark old valid URL candidates inactive.
	 *
	 * @param string $before MySQL datetime.
	 * @return true|\WP_Error
	 */
	public static function mark_valid_urls_stale( $before ) {
		global $wpdb;
		$table = self::valid_urls_table();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'stale', updated_at = %s WHERE last_seen_at < %s", current_time( 'mysql' ), $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? self::write_error( 'convertrack_404_candidate_stale_failed', __( 'Stale valid URL candidates could not be marked.', 'convertrack-click-conversion-analytics' ) ) : true;
	}

	/**
	 * Valid URL candidates for matching.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function valid_candidates( $limit = 5000 ) {
		global $wpdb;
		$table = self::valid_urls_table();
		$limit = max( 1, min( 10000, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = 'active' ORDER BY priority DESC, id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count active valid URL candidates.
	 *
	 * @return int
	 */
	public static function valid_url_count() {
		global $wpdb;
		$table = self::valid_urls_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Recent logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent_logs( $limit = 50 ) {
		global $wpdb;
		$table = self::logs_table();
		$limit = max( 1, min( 200, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert log row.
	 *
	 * @param string $level   Level.
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function insert_log( $level, $source, $message, array $context = array() ) {
		global $wpdb;
		$table = self::logs_table();
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'level'      => sanitize_key( $level ),
				'source'     => sanitize_key( $source ),
				'message'    => self::truncate( sanitize_text_field( $message ), 1000 ),
				'context'    => empty( $context ) ? '' : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cleanup old events/logs.
	 *
	 * @param int $days Retention days.
	 * @return array|\WP_Error Deleted row counts or a detectable write failure.
	 */
	public static function cleanup( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$cutoff = self::mysql_time( - $days * DAY_IN_SECONDS );
		$events = self::events_table();
		$logs   = self::logs_table();
		$buckets = self::hit_buckets_table();
		$limits  = self::rate_limits_table();
		$results = array();
		$results['events'] = $wpdb->query( $wpdb->prepare( "DELETE FROM $events WHERE last_detected_at < %s AND status NOT IN ('approved','auto_redirected') LIMIT 10000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results['logs'] = $wpdb->query( $wpdb->prepare( "DELETE FROM $logs WHERE created_at < %s LIMIT 10000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bucket_cutoff = self::mysql_time( - 2 * DAY_IN_SECONDS );
		$results['hit_buckets'] = $wpdb->query( $wpdb->prepare( "DELETE FROM $buckets WHERE bucket_start < %s LIMIT 10000", $bucket_cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results['rate_limits'] = $wpdb->query( $wpdb->prepare( "DELETE FROM $limits WHERE expires_at < %s LIMIT 10000", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $results as $surface => $deleted ) {
			if ( false === $deleted ) {
				return new \WP_Error( 'convertrack_404_cleanup_failed', $wpdb->last_error, array( 'surface' => $surface ) );
			}
			$results[ $surface ] = (int) $deleted;
		}
		self::clear_summary_cache();
		return $results;
	}

	/**
	 * MySQL datetime with site-local offset.
	 *
	 * @param int $offset_seconds Offset from now.
	 * @return string
	 */
	public static function mysql_time( $offset_seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + (int) $offset_seconds );
	}

	/**
	 * Clear cached summary.
	 */
	public static function clear_summary_cache() {
		delete_transient( self::SUMMARY_CACHE_KEY );
	}

	/**
	 * Apply per-IP, global and new-path capture budgets with atomic counters.
	 *
	 * Only REMOTE_ADDR is accepted by the caller; forwarded headers are never
	 * trusted here. The address is salted and hashed before it reaches storage.
	 *
	 * @param array  $source Normalized source.
	 * @param string $ip     Direct peer IP.
	 * @return true|\WP_Error
	 */
	public static function consume_capture_budget( array $source, $ip ) {
		if ( empty( $source['hash'] ) ) {
			return new \WP_Error( 'convertrack_404_invalid_source', __( 'The 404 URL could not be normalized.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( null === self::$budget_table_ready ) {
			self::$budget_table_ready = self::table_exists( self::rate_limits_table() );
		}
		if ( ! self::$budget_table_ready ) {
			return new \WP_Error( 'convertrack_404_budget_schema_missing', __( 'The 404 capture budget table is unavailable.', 'convertrack-click-conversion-analytics' ) );
		}

		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? (string) $ip : 'unknown';
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : home_url( '/' );
		$ip_key = hash_hmac( 'sha256', $ip, $salt );
		$ip_limit = (int) apply_filters( 'convertrack_404_capture_ip_per_hour', Settings::get( 'capture_ip_per_hour', 300 ) );
		$global_limit = (int) apply_filters( 'convertrack_404_capture_global_per_hour', Settings::get( 'capture_global_per_hour', 10000 ) );
		$new_path_limit = (int) apply_filters( 'convertrack_404_capture_new_paths_per_hour', Settings::get( 'capture_new_paths_per_hour', 1000 ) );

		$allowed = self::consume_rate_counter( 'global', max( 100, $global_limit ), HOUR_IN_SECONDS, 'convertrack_404_global_rate_limited' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$allowed = self::consume_rate_counter( 'ip|' . $ip_key, max( 10, $ip_limit ), HOUR_IN_SECONDS, 'convertrack_404_ip_rate_limited' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$table  = self::events_table();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE url_hash = %s LIMIT 1", $source['hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $exists ) {
			$allowed = self::consume_cardinality_budget( $source['hash'], max( 10, $new_path_limit ), HOUR_IN_SECONDS );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		return true;
	}

	/**
	 * Invalidate one positive or negative redirect lookup.
	 *
	 * @param string $source Source URL/path.
	 */
	public static function invalidate_redirect_cache( $source ) {
		$src = self::normalize_source( $source );
		if ( empty( $src ) ) {
			self::$redirect_cache = array();
			$generation = (int) get_option( self::REDIRECT_CACHE_GENERATION_OPTION, 0 );
			update_option( self::REDIRECT_CACHE_GENERATION_OPTION, $generation + 1, false );
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( self::REDIRECT_CACHE_GROUP );
			}
			return;
		}
		$key = self::redirect_cache_key( $src['hash'] );
		unset( self::$redirect_cache[ $key ] );
		wp_cache_delete( $key, self::REDIRECT_CACHE_GROUP );
	}

	/** Build a generation-scoped redirect cache key. */
	private static function redirect_cache_key( $source_hash ) {
		$generation = max( 0, (int) get_option( self::REDIRECT_CACHE_GENERATION_OPTION, 0 ) );
		return 'g' . $generation . '_source_' . (string) $source_hash;
	}

	/**
	 * Reopen the linked review item when its redirect stops protecting the URL.
	 *
	 * @param array $redirect Redirect row.
	 * @return true|\WP_Error
	 */
	private static function reopen_linked_event( array $redirect ) {
		$event_id = isset( $redirect['event_id'] ) ? (int) $redirect['event_id'] : 0;
		if ( ! $event_id ) {
			return true;
		}
		$event = self::get_event( $event_id );
		if ( ! $event ) {
			return true;
		}
		$has_suggestion = ! empty( $event['suggested_url'] );
		$data = array(
			'status'               => $has_suggestion ? 'recommended' : 'new',
			'recommendation_state' => $has_suggestion ? 'completed' : 'pending',
			'claim_owner'          => '',
			'claim_expires_at'     => null,
		);
		if ( ! $has_suggestion ) {
			$data['recommendation_attempts'] = 0;
			$data['processed_generation']    = 0;
			$data['processed_at']            = null;
		}
		return self::update_event( $event_id, $data );
	}

	/**
	 * Atomically increment a minute-level hit bucket.
	 *
	 * @param string $url_hash Source hash.
	 * @return true|\WP_Error
	 */
	private static function record_hit_bucket( $url_hash ) {
		global $wpdb;
		$table  = self::hit_buckets_table();
		$now_ts = current_time( 'timestamp' );
		$bucket = gmdate( 'Y-m-d H:i:00', $now_ts );
		$now    = current_time( 'mysql' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (bucket_start,url_hash,hit_count,updated_at) VALUES (%s,%s,1,%s)
				ON DUPLICATE KEY UPDATE hit_count = LEAST(hit_count + 1, 4294967295), updated_at = VALUES(updated_at)",
				$bucket,
				$url_hash,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? self::write_error( 'convertrack_404_hit_bucket_failed', __( 'The 404 hit bucket could not be updated.', 'convertrack-click-conversion-analytics' ) ) : true;
	}

	/**
	 * Consume an atomic fixed-window counter.
	 *
	 * @param string $scope      Counter scope.
	 * @param int    $limit      Allowed count.
	 * @param int    $window     Window seconds.
	 * @param string $error_code Limit error code.
	 * @return true|\WP_Error
	 */
	private static function consume_rate_counter( $scope, $limit, $window, $error_code ) {
		global $wpdb;
		$table       = self::rate_limits_table();
		$now_ts      = current_time( 'timestamp' );
		$window      = max( 60, (int) $window );
		$bucket_ts   = (int) floor( $now_ts / $window ) * $window;
		$bucket      = gmdate( 'Y-m-d H:i:s', $bucket_ts );
		$expires     = gmdate( 'Y-m-d H:i:s', $bucket_ts + $window + HOUR_IN_SECONDS );
		$now         = current_time( 'mysql' );
		$bucket_key  = hash( 'sha256', $scope . '|' . $bucket_ts );
		$result      = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (bucket_key,bucket_start,hit_count,expires_at,updated_at) VALUES (%s,%s,1,%s,%s)
				ON DUPLICATE KEY UPDATE hit_count = LEAST(hit_count + 1, 4294967295), updated_at = VALUES(updated_at)",
				$bucket_key,
				$bucket,
				$expires,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_budget_write_failed', __( 'The 404 capture budget could not be updated.', 'convertrack-click-conversion-analytics' ) );
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM $table WHERE bucket_key = %s", $bucket_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > max( 1, (int) $limit ) ) {
			return new \WP_Error( $error_code, __( 'The 404 capture budget has been reached. Try again later.', 'convertrack-click-conversion-analytics' ), array( 'status' => 429, 'retry_after' => max( 1, $bucket_ts + $window - $now_ts ) ) );
		}
		return true;
	}

	/**
	 * Count each new path once per fixed window before allowing new cardinality.
	 *
	 * @param string $url_hash Source hash.
	 * @param int    $limit    New-path limit.
	 * @param int    $window   Window seconds.
	 * @return true|\WP_Error
	 */
	private static function consume_cardinality_budget( $url_hash, $limit, $window ) {
		global $wpdb;
		$table      = self::rate_limits_table();
		$now_ts     = current_time( 'timestamp' );
		$window     = max( 60, (int) $window );
		$bucket_ts  = (int) floor( $now_ts / $window ) * $window;
		$bucket     = gmdate( 'Y-m-d H:i:s', $bucket_ts );
		$expires    = gmdate( 'Y-m-d H:i:s', $bucket_ts + $window + HOUR_IN_SECONDS );
		$now        = current_time( 'mysql' );
		$marker_key = hash( 'sha256', 'new-path-marker|' . $bucket_ts . '|' . $url_hash );
		$inserted   = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (bucket_key,bucket_start,hit_count,expires_at,updated_at) VALUES (%s,%s,0,%s,%s)",
				$marker_key,
				$bucket,
				$expires,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $inserted ) {
			return self::write_error( 'convertrack_404_budget_write_failed', __( 'The 404 path cardinality budget could not be updated.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( 0 === (int) $inserted ) {
			return true;
		}

		$allowed = self::consume_rate_counter( 'new-paths', $limit, $window, 'convertrack_404_cardinality_limited' );
		if ( is_wp_error( $allowed ) ) {
			$wpdb->delete( $table, array( 'bucket_key' => $marker_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$counter_key = hash( 'sha256', 'new-paths|' . $bucket_ts );
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET hit_count = GREATEST(hit_count - 1, 0) WHERE bucket_key = %s", $counter_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $allowed;
		}
		return true;
	}

	/**
	 * Normalize query string by dropping ignored params.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private static function normalize_query( $query ) {
		if ( '' === $query ) {
			return '';
		}
		parse_str( $query, $params );
		if ( ! is_array( $params ) ) {
			return '';
		}
		$ignored = array_map( 'strtolower', Settings::lines_to_array( Settings::get( 'ignore_query_params' ) ) );
		$allowed = array_map( 'strtolower', Settings::lines_to_array( Settings::get( 'query_param_allowlist', '' ) ) );
		$allowed = (array) apply_filters( 'convertrack_404_query_parameter_allowlist', $allowed );
		$allowed = array_values( array_unique( array_filter( array_map( 'sanitize_key', $allowed ) ) ) );
		if ( empty( $allowed ) ) {
			return '';
		}
		foreach ( array_keys( $params ) as $key ) {
			$normalized_key = strtolower( sanitize_key( (string) $key ) );
			if ( '' === $normalized_key || self::is_sensitive_query_key( $normalized_key ) || ! in_array( $normalized_key, $allowed, true ) || in_array( $normalized_key, $ignored, true ) || ! is_scalar( $params[ $key ] ) ) {
				unset( $params[ $key ] );
				continue;
			}
			$value = self::truncate( sanitize_text_field( (string) $params[ $key ] ), 191 );
			unset( $params[ $key ] );
			$params[ $normalized_key ] = $value;
		}
		if ( empty( $params ) ) {
			return '';
		}
		ksort( $params );
		return http_build_query( $params, '', '&' );
	}

	/**
	 * Update event row.
	 *
	 * @param int   $id   Event ID.
	 * @param array $data Data.
	 * @return true|\WP_Error
	 */
	private static function update_event( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->update( self::events_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			return self::write_error( 'convertrack_404_event_update_failed', __( 'The 404 event could not be updated.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( 0 === (int) $result && ! self::get_event( $id ) ) {
			return new \WP_Error( 'convertrack_404_event_missing', __( 'The 404 event was not found.', 'convertrack-click-conversion-analytics' ) );
		}
		self::clear_summary_cache();
		return true;
	}

	/**
	 * Decorate event for REST/admin output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decorate_event( array $row ) {
		$row['id']                = (int) $row['id'];
		$row['hit_count']         = (int) $row['hit_count'];
		$row['suggested_post_id'] = (int) $row['suggested_post_id'];
		$row['confidence']        = (int) $row['confidence'];
		$row['source_full_url']   = home_url( $row['url'] );
		return $row;
	}

	/**
	 * Decorate redirect for REST/admin output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decorate_redirect( array $row ) {
		$row['id']            = (int) $row['id'];
		$row['redirect_type'] = (int) $row['redirect_type'];
		$row['event_id']      = (int) $row['event_id'];
		$row['created_by']    = (int) $row['created_by'];
		$row['hit_count']     = (int) $row['hit_count'];
		$row['provider']      = isset( $row['source'] ) ? (string) $row['source'] : 'internal';
		return $row;
	}

	/**
	 * Initialize terminal state for recommendations produced before leased jobs.
	 *
	 * @return true|\WP_Error
	 */
	private static function migrate_recommendation_state() {
		global $wpdb;
		$events    = self::events_table();
		$redirects = self::redirects_table();
		$event_columns = self::table_columns( $events );
		$redirect_columns = self::table_columns( $redirects );
		$required_events = array( 'recommendation_generation', 'processed_generation', 'recommendation_state', 'recommendation_attempts', 'claim_owner', 'claim_expires_at', 'last_attempt_at', 'processed_at' );
		$required_redirects = array( 'health_status', 'health_code', 'health_error', 'last_checked_at' );
		if ( array_diff( $required_events, $event_columns ) || array_diff( $required_redirects, $redirect_columns ) ) {
			return new \WP_Error( 'convertrack_404_schema_incomplete', __( 'The 404 Monitor schema upgrade is incomplete.', 'convertrack-click-conversion-analytics' ) );
		}

		// Existing terminal statuses are excluded by the queue's status = 'new'
		// invariant. Avoid a blocking backfill of large production event tables;
		// newly processed rows receive the explicit state and timestamps lazily.
		return true;
	}

	/**
	 * Verify every table surface required by detection, redirect lookup and the
	 * bounded workers before advancing (or trusting) the schema watermark.
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_schema() {
		global $wpdb;
		$required = array(
			self::events_table() => array(
				'columns' => array( 'id', 'url_hash', 'url', 'path', 'hit_count', 'status', 'recommendation_generation', 'processed_generation', 'recommendation_state', 'recommendation_attempts', 'claim_owner', 'claim_expires_at', 'processed_at' ),
				'indexes' => array( 'PRIMARY', 'url_hash', 'last_detected_at', 'recommendation_queue' ),
			),
			self::redirects_table() => array(
				'columns' => array( 'id', 'source_hash', 'source_url', 'source_path', 'destination_url', 'status', 'event_id', 'health_status', 'last_checked_at' ),
				'indexes' => array( 'PRIMARY', 'source_hash', 'status', 'health_check' ),
			),
			self::valid_urls_table() => array(
				'columns' => array( 'id', 'url_hash', 'url', 'path', 'status', 'last_seen_at' ),
				'indexes' => array( 'PRIMARY', 'url_hash', 'last_seen_at', 'status' ),
			),
			self::logs_table() => array(
				'columns' => array( 'id', 'level', 'source', 'message', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'level_created', 'source_created' ),
			),
			self::hit_buckets_table() => array(
				'columns' => array( 'bucket_start', 'url_hash', 'hit_count', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'bucket_start' ),
			),
			self::rate_limits_table() => array(
				'columns' => array( 'bucket_key', 'bucket_start', 'hit_count', 'expires_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'expires_at' ),
			),
		);

		foreach ( $required as $table => $expect ) {
			if ( ! self::table_exists( $table ) ) {
				return new \WP_Error( 'convertrack_404_schema_missing', sprintf( /* translators: %s: table. */ __( 'Broken URLs schema is missing table %s.', 'convertrack-click-conversion-analytics' ), $table ) );
			}
			$wpdb->last_error = '';
			$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = array_unique( array_map( 'strval', (array) $wpdb->get_col( "SHOW INDEX FROM `$table`", 2 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing_columns = array_diff( $expect['columns'], $columns );
			$missing_indexes = array_diff( $expect['indexes'], $indexes );
			if ( '' !== (string) $wpdb->last_error || ! empty( $missing_columns ) || ! empty( $missing_indexes ) ) {
				return new \WP_Error(
					'convertrack_404_schema_incomplete',
					sprintf(
						/* translators: 1: table, 2: columns, 3: indexes. */
						__( 'Broken URLs schema verification failed for %1$s. Missing columns: %2$s. Missing indexes: %3$s.', 'convertrack-click-conversion-analytics' ),
						$table,
						implode( ', ', $missing_columns ),
						implode( ', ', $missing_indexes )
					),
					array( 'database_error' => (string) $wpdb->last_error )
				);
			}
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || empty( $status['Engine'] ) || 'innodb' !== strtolower( (string) $status['Engine'] ) ) {
				return new \WP_Error( 'convertrack_404_schema_engine', sprintf( /* translators: %s: table. */ __( 'Broken URLs table %s must use InnoDB.', 'convertrack-click-conversion-analytics' ), $table ) );
			}
		}

		return true;
	}

	/**
	 * Column names for a module table.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	private static function table_columns( $table ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'DESCRIBE ' . $table, ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return isset( $row['Field'] ) ? (string) $row['Field'] : '';
					},
					(array) $rows
				)
			)
		);
	}

	/**
	 * Whether URL parts match the configured home origin exactly.
	 *
	 * @param array $parts Parsed URL parts.
	 * @return bool
	 */
	private static function is_home_origin( array $parts ) {
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$home_scheme = strtolower( $home['scheme'] );
		$test_scheme = strtolower( $parts['scheme'] );
		$home_host   = strtolower( rtrim( $home['host'], '.' ) );
		$test_host   = strtolower( rtrim( $parts['host'], '.' ) );
		$home_port   = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home_scheme ? 443 : 80 );
		$test_port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $test_scheme ? 443 : 80 );
		return $home_scheme === $test_scheme && $home_host === $test_host && $home_port === $test_port;
	}

	/**
	 * Keep absolute source URLs inside a subdirectory WordPress installation.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private static function path_is_in_home( $path ) {
		$home      = wp_parse_url( home_url( '/' ) );
		$home_path = isset( $home['path'] ) ? '/' . trim( rawurldecode( $home['path'] ), '/' ) : '/';
		$home_path = '/' === $home_path ? '/' : untrailingslashit( $home_path );
		$path      = '/' . ltrim( rawurldecode( (string) $path ), '/' );
		if ( '/' === $home_path ) {
			return true;
		}
		return $path === $home_path || 0 === strpos( $path, trailingslashit( $home_path ) );
	}

	/**
	 * Strip queries, fragments and credentials from stored referrers.
	 *
	 * @param string $url Referrer URL.
	 * @return string
	 */
	private static function sanitize_referrer( $url ) {
		$url   = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( rtrim( $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
		return self::truncate( esc_url_raw( $scheme . '://' . $host . $port . $path, array( 'http', 'https' ) ), 2048 );
	}

	/**
	 * Sensitive query names are denied even when a developer allowlists them.
	 *
	 * @param string $key Normalized query key.
	 * @return bool
	 */
	private static function is_sensitive_query_key( $key ) {
		$key = strtolower( (string) $key );
		return (bool) preg_match( '/(?:^|[_-])(?:token|key|nonce|pass|password|email|auth|authorization|session|code|secret|signature|order[_-]?key|reset[_-]?key)(?:[_-]|$)/', $key );
	}

	/**
	 * Standard database write error with the underlying diagnostic retained.
	 *
	 * @param string $code    Error code.
	 * @param string $message Public message.
	 * @return \WP_Error
	 */
	private static function write_error( $code, $message ) {
		global $wpdb;
		return new \WP_Error(
			$code,
			$message,
			array( 'database_error' => self::truncate( sanitize_text_field( (string) $wpdb->last_error ), 500 ) )
		);
	}

	/**
	 * Rollback failed install without touching pre-existing tables.
	 *
	 * @param array $existed Table => existed before install.
	 */
	private static function rollback_install( array $existed ) {
		global $wpdb;
		foreach ( $existed as $table => $did_exist ) {
			if ( ! $did_exist ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB
			}
		}
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Check table existence.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		return strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) === strtolower( $table );
	}

	/**
	 * Truncate text safely.
	 *
	 * @param string $value Value.
	 * @param int    $len   Max chars.
	 * @return string
	 */
	public static function truncate( $value, $len ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $len ) : substr( $value, 0, $len );
	}
}
