<?php
/**
 * 404 Monitor data layer.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Database {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'convertrack_404_db_version';
	const SUMMARY_CACHE_KEY = 'convertrack_404_summary';

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
	 * Create or update tables.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$redirects       = self::redirects_table();
		$valid           = self::valid_urls_table();
		$logs            = self::logs_table();
		$existed         = array(
			$events    => self::table_exists( $events ),
			$redirects => self::table_exists( $redirects ),
			$valid     => self::table_exists( $valid ),
			$logs      => self::table_exists( $logs ),
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
			KEY suggested_post_type (suggested_post_type)
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
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY status (status),
			KEY last_hit_at (last_hit_at),
			KEY event_id (event_id)
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

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		return true;
	}

	/**
	 * Upgrade when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$result = self::install();
			if ( is_wp_error( $result ) ) {
				$settings            = Settings::all();
				$settings['enabled'] = 0;
				Settings::save( $settings );
				set_transient( 'convertrack_404_migration_error', $result->get_error_message(), HOUR_IN_SECONDS );
			}
		}
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
	}

	/**
	 * Normalize a request URL into a local path/query structure.
	 *
	 * @param string $url Raw URL or path.
	 * @return array
	 */
	public static function normalize_source( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array();
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			$parts['path'] = '/';
		}

		if ( ! empty( $parts['host'] ) ) {
			$home = wp_parse_url( home_url( '/' ) );
			$home_host = isset( $home['host'] ) ? strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) : '';
			$test_host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
			if ( $home_host && $test_host !== $home_host ) {
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
		$url = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return array();
		}

		$path = isset( $parts['path'] ) ? '/' . ltrim( rawurldecode( $parts['path'] ), '/' ) : '/';
		$path = preg_replace( '#/+#', '/', $path );
		$path = '/' === $path ? '/' : untrailingslashit( $path ) . '/';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		$full  = trailingslashit( $parts['scheme'] . '://' . $parts['host'] ) . ltrim( $path, '/' );
		if ( '/' === $path ) {
			$full = $parts['scheme'] . '://' . $parts['host'] . '/';
		}
		$full .= $query;

		return array(
			'url'  => self::truncate( esc_url_raw( $full ), 2048 ),
			'path' => self::truncate( strtolower( $path ), 512 ),
			'hash' => md5( strtolower( esc_url_raw( $full ) ) ),
		);
	}

	/**
	 * Record or update a detected 404 URL.
	 *
	 * @param string $url      URL/path.
	 * @param string $referrer Referrer URL.
	 * @param string $ua       User agent.
	 * @return int|false
	 */
	public static function record_404( $url, $referrer = '', $ua = '' ) {
		global $wpdb;

		$source = self::normalize_source( $url );
		if ( empty( $source ) ) {
			return false;
		}

		$table = self::events_table();
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id,status FROM $table WHERE url_hash = %s", $source['hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $row ) {
			$data = array(
				'last_detected_at' => $now,
				'updated_at'       => $now,
			);
			if ( '' !== $referrer ) {
				$data['referrer_url'] = self::truncate( esc_url_raw( $referrer ), 2048 );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET hit_count = hit_count + 1 WHERE id = %d", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::clear_summary_cache();
			return (int) $row['id'];
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'url_hash'          => $source['hash'],
				'url'               => $source['url'],
				'path'              => $source['path'],
				'query_string'      => $source['query'],
				'referrer_url'      => self::truncate( esc_url_raw( $referrer ), 2048 ),
				'user_agent_hash'   => '' !== $ua ? md5( $ua ) : '',
				'first_detected_at' => $now,
				'last_detected_at'  => $now,
				'hit_count'         => 1,
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		self::clear_summary_cache();
		return $inserted ? (int) $wpdb->insert_id : false;
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
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table
				WHERE status IN ('new','recommended','manual_review')
				ORDER BY hit_count DESC, last_detected_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Save recommendation fields for one event.
	 *
	 * @param int   $id     Event ID.
	 * @param array $result Match result.
	 */
	public static function save_recommendation( $id, array $result ) {
		$status = ! empty( $result['url'] ) ? 'recommended' : 'manual_review';
		if ( isset( $result['confidence'] ) && (int) $result['confidence'] < 50 ) {
			$status = 'manual_review';
		}
		self::update_event(
			$id,
			array(
				'status'              => $status,
				'suggested_url'       => isset( $result['url'] ) ? esc_url_raw( $result['url'] ) : '',
				'suggested_post_id'   => isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0,
				'suggested_post_type' => isset( $result['post_type'] ) ? sanitize_key( $result['post_type'] ) : '',
				'confidence'          => isset( $result['confidence'] ) ? min( 100, absint( $result['confidence'] ) ) : 0,
				'match_reason'        => isset( $result['reason'] ) ? self::truncate( sanitize_text_field( $result['reason'] ), 255 ) : '',
				'destination_type'    => isset( $result['destination_type'] ) ? sanitize_key( $result['destination_type'] ) : '',
				'error_message'       => '',
			)
		);
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
		$counts['spike_hits'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hit_count),0) FROM $events WHERE last_detected_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 */
	public static function set_event_status( $id, $status ) {
		self::update_event( $id, array( 'status' => sanitize_key( $status ) ) );
	}

	/**
	 * Update suggested destination without creating a redirect.
	 *
	 * @param int    $id          Event ID.
	 * @param string $destination Destination URL.
	 */
	public static function update_suggestion( $id, $destination ) {
		self::update_event(
			$id,
			array(
				'suggested_url' => esc_url_raw( $destination ),
				'status'        => 'recommended',
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
	 * @return int|false
	 */
	public static function upsert_redirect( $source, $destination, $event_id = 0, $status = 'active' ) {
		global $wpdb;
		$src = self::normalize_source( $source );
		$dst = self::normalize_destination( $destination );
		if ( empty( $src ) || empty( $dst ) ) {
			return false;
		}

		$table = self::redirects_table();
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE source_hash = %s", $src['hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data  = array(
			'source_url'      => $src['url'],
			'source_path'     => $src['path'],
			'destination_url' => $dst['url'],
			'redirect_type'   => 301,
			'status'          => sanitize_key( $status ),
			'source'          => 'internal',
			'event_id'        => absint( $event_id ),
			'created_by'      => get_current_user_id(),
			'updated_at'      => $now,
		);

		if ( $row ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::clear_summary_cache();
			return (int) $row['id'];
		}

		$data['source_hash'] = $src['hash'];
		$data['created_at']  = $now;
		$inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
		return $inserted ? (int) $wpdb->insert_id : false;
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
		$table = self::redirects_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE source_hash = %s AND status = 'active'", $src['hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decorate_redirect( $row ) : null;
	}

	/**
	 * Count redirect hit.
	 *
	 * @param int $id Redirect ID.
	 */
	public static function record_redirect_hit( $id ) {
		global $wpdb;
		$table = self::redirects_table();
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET hit_count = hit_count + 1, last_hit_at = %s, updated_at = %s WHERE id = %d", current_time( 'mysql' ), current_time( 'mysql' ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::clear_summary_cache();
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
	 */
	public static function set_redirect_status( $id, $status ) {
		global $wpdb;
		$wpdb->update(
			self::redirects_table(),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Delete redirect.
	 *
	 * @param int $id Redirect ID.
	 */
	public static function delete_redirect( $id ) {
		global $wpdb;
		$wpdb->delete( self::redirects_table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Add/update a valid URL candidate.
	 *
	 * @param string $url  URL.
	 * @param array  $args Metadata.
	 * @return int|false
	 */
	public static function upsert_valid_url( $url, array $args = array() ) {
		global $wpdb;
		$dst = self::normalize_destination( $url );
		if ( empty( $dst ) ) {
			return false;
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
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $row['id'];
		}

		$data['url_hash']   = $dst['hash'];
		$data['created_at'] = $now;
		$inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Mark old valid URL candidates inactive.
	 *
	 * @param string $before MySQL datetime.
	 */
	public static function mark_valid_urls_stale( $before ) {
		global $wpdb;
		$table = self::valid_urls_table();
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'stale', updated_at = %s WHERE last_seen_at < %s", current_time( 'mysql' ), $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @return int Deleted event rows.
	 */
	public static function cleanup( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$cutoff = self::mysql_time( - $days * DAY_IN_SECONDS );
		$events = self::events_table();
		$logs   = self::logs_table();
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $events WHERE last_detected_at < %s AND status NOT IN ('approved','auto_redirected') LIMIT 10000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $logs WHERE created_at < %s LIMIT 10000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::clear_summary_cache();
		return false === $deleted ? 0 : (int) $deleted;
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
		foreach ( array_keys( $params ) as $key ) {
			if ( in_array( strtolower( (string) $key ), $ignored, true ) ) {
				unset( $params[ $key ] );
			}
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
	 */
	private static function update_event( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::events_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
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

