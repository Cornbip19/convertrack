<?php
/**
 * Google Search Console Index Monitor data layer.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Database {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'convertrack_gsc_db_version';
	const SUMMARY_CACHE_KEY = 'convertrack_gsc_summary';
	const HISTORY_OPTION    = 'convertrack_gsc_status_history';
	const HISTORY_MAX_DAYS  = 90;

	/**
	 * Queue table.
	 *
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_gsc_index_queue';
	}

	/**
	 * Logs table.
	 *
	 * @return string
	 */
	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_gsc_logs';
	}

	/**
	 * Install or update GSC tables.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$queue           = self::queue_table();
		$logs            = self::logs_table();
		$queue_existed   = self::table_exists( $queue );
		$logs_existed    = self::table_exists( $logs );

		$sql = array();

		$sql[] = "CREATE TABLE $queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL DEFAULT '',
			url varchar(2048) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(100) NOT NULL DEFAULT '',
			sitemap_url varchar(2048) NOT NULL DEFAULT '',
			sitemap_hash char(32) NOT NULL DEFAULT '',
			priority tinyint(3) unsigned NOT NULL DEFAULT 0,
			index_status varchar(40) NOT NULL DEFAULT 'queued',
			coverage_state varchar(255) NOT NULL DEFAULT '',
			google_verdict varchar(50) NOT NULL DEFAULT '',
			robots_txt_state varchar(50) NOT NULL DEFAULT '',
			indexing_state varchar(50) NOT NULL DEFAULT '',
			page_fetch_state varchar(50) NOT NULL DEFAULT '',
			canonical_url varchar(2048) NOT NULL DEFAULT '',
			google_canonical varchar(2048) NOT NULL DEFAULT '',
			user_canonical varchar(2048) NOT NULL DEFAULT '',
			inspection_result_link varchar(2048) NOT NULL DEFAULT '',
			in_sitemap tinyint(1) NOT NULL DEFAULT 0,
			last_checked_at datetime NULL DEFAULT NULL,
			last_submitted_at datetime NULL DEFAULT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_check_at datetime NULL DEFAULT NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY post_id (post_id),
			KEY post_type (post_type),
			KEY index_status (index_status),
			KEY next_check_at (next_check_at),
			KEY priority_next (priority, next_check_at),
			KEY sitemap_hash (sitemap_hash)
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
				self::rollback_install( $queue_existed, $logs_existed );
				return new \WP_Error( 'convertrack_gsc_db_error', $wpdb->last_error );
			}
		}

		if ( ! self::table_exists( $queue ) || ! self::table_exists( $logs ) ) {
			self::rollback_install( $queue_existed, $logs_existed );
			return new \WP_Error( 'convertrack_gsc_db_missing', __( 'Google Search Console tables could not be created.', 'convertrack-click-conversion-analytics' ) );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		Logger::info( 'database', 'GSC database migration completed.', array( 'version' => self::DB_VERSION ) );
		return true;
	}

	/**
	 * Upgrade when needed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$result = self::install();
			if ( is_wp_error( $result ) ) {
				$settings            = Settings::all();
				$settings['enabled'] = 0;
				Settings::save( $settings );
				Logger::error( 'database', 'GSC database migration failed.', array( 'error' => $result->get_error_message() ) );
				set_transient( 'convertrack_gsc_migration_error', $result->get_error_message(), HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Drop GSC tables.
	 */
	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::queue_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::logs_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Add or update a URL in the queue.
	 *
	 * @param string $url  URL.
	 * @param array  $args Metadata.
	 * @return int|false
	 */
	public static function upsert_url( $url, array $args = array() ) {
		global $wpdb;

		$url = self::normalize_url( $url );
		if ( '' === $url ) {
			return false;
		}

		$table = self::queue_table();
		$hash  = md5( strtolower( $url ) );
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,index_status FROM $table WHERE url_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = array(
			'url'          => $url,
			'post_id'      => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'post_type'    => isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '',
			'sitemap_url'  => isset( $args['sitemap_url'] ) ? esc_url_raw( $args['sitemap_url'] ) : '',
			'sitemap_hash' => ! empty( $args['sitemap_url'] ) ? md5( strtolower( esc_url_raw( $args['sitemap_url'] ) ) ) : '',
			'in_sitemap'   => empty( $args['in_sitemap'] ) ? 0 : 1,
			'updated_at'   => $now,
		);

		if ( $existing ) {
			if ( ! in_array( $existing['index_status'], array( 'ignored', 'checking' ), true ) && empty( $args['preserve_status'] ) ) {
				$data['index_status']  = ! empty( $args['index_status'] ) ? sanitize_key( $args['index_status'] ) : 'queued';
				$data['next_check_at'] = isset( $args['next_check_at'] ) ? sanitize_text_field( $args['next_check_at'] ) : $now;
			}

			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::clear_summary_cache();
			return (int) $existing['id'];
		}

		$data['url_hash']      = $hash;
		$data['priority']      = isset( $args['priority'] ) ? absint( $args['priority'] ) : 0;
		$data['index_status']  = ! empty( $args['index_status'] ) ? sanitize_key( $args['index_status'] ) : 'queued';
		$data['next_check_at'] = isset( $args['next_check_at'] ) ? sanitize_text_field( $args['next_check_at'] ) : $now;
		$data['created_at']    = $now;

		$inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get queued rows due for processing.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function due_batch( $limit ) {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );
		$limit = max( 1, min( 500, (int) $limit ) );

		$sql = $wpdb->prepare(
			"SELECT * FROM $table
			WHERE index_status <> 'ignored'
				AND index_status <> 'checking'
				AND (next_check_at IS NULL OR next_check_at <= %s)
			ORDER BY priority DESC, COALESCE(next_check_at, '1970-01-01 00:00:00') ASC, id ASC
			LIMIT %d",
			$now,
			$limit
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count rows currently due for processing (same criteria as due_batch()).
	 *
	 * @return int
	 */
	public static function due_count() {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM $table
			WHERE index_status <> 'ignored'
				AND index_status <> 'checking'
				AND (next_check_at IS NULL OR next_check_at <= %s)",
			$now
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Re-queue rows stranded in 'checking' by an interrupted batch.
	 *
	 * Rows in 'checking' are excluded from due_batch() and protected from
	 * upsert_url(), so a fatal mid-batch would otherwise strand them forever.
	 *
	 * @param int $minutes Age in minutes before a checking row is considered stale.
	 * @return int
	 */
	public static function release_stale_checking( $minutes = 15 ) {
		global $wpdb;
		$table  = self::queue_table();
		$now    = current_time( 'mysql' );
		$cutoff = self::mysql_time( - max( 1, (int) $minutes ) * MINUTE_IN_SECONDS );

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table
				SET index_status = 'queued', next_check_at = %s, updated_at = %s
				WHERE index_status = 'checking' AND updated_at < %s",
				$now,
				$now,
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $result ) {
			self::clear_summary_cache();
		}
		return (int) $result;
	}

	/**
	 * Return a row to the queue without recording an attempt or error.
	 *
	 * @param int $id Row id.
	 */
	public static function mark_queued( $id ) {
		self::update_row(
			$id,
			array(
				'index_status'  => 'queued',
				'next_check_at' => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Fetch a single queue row.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_row( $id ) {
		global $wpdb;
		$table = self::queue_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Mark a row as checking.
	 *
	 * @param int $id Row id.
	 */
	public static function mark_checking( $id ) {
		self::update_row(
			$id,
			array(
				'index_status' => 'checking',
				'updated_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Save inspection result.
	 *
	 * @param int   $id     Row id.
	 * @param array $result Parsed result.
	 */
	public static function save_inspection_result( $id, array $result ) {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );
		$next  = isset( $result['next_check_at'] ) ? $result['next_check_at'] : self::mysql_time( WEEK_IN_SECONDS );

		$data = array(
			'index_status'           => sanitize_key( isset( $result['index_status'] ) ? $result['index_status'] : 'not_indexed' ),
			'coverage_state'         => self::truncate( isset( $result['coverage_state'] ) ? $result['coverage_state'] : '', 255 ),
			'google_verdict'         => self::truncate( isset( $result['google_verdict'] ) ? $result['google_verdict'] : '', 50 ),
			'robots_txt_state'       => self::truncate( isset( $result['robots_txt_state'] ) ? $result['robots_txt_state'] : '', 50 ),
			'indexing_state'         => self::truncate( isset( $result['indexing_state'] ) ? $result['indexing_state'] : '', 50 ),
			'page_fetch_state'       => self::truncate( isset( $result['page_fetch_state'] ) ? $result['page_fetch_state'] : '', 50 ),
			'canonical_url'          => esc_url_raw( isset( $result['canonical_url'] ) ? $result['canonical_url'] : '' ),
			'google_canonical'       => esc_url_raw( isset( $result['google_canonical'] ) ? $result['google_canonical'] : '' ),
			'user_canonical'         => esc_url_raw( isset( $result['user_canonical'] ) ? $result['user_canonical'] : '' ),
			'inspection_result_link' => esc_url_raw( isset( $result['inspection_result_link'] ) ? $result['inspection_result_link'] : '' ),
			'last_checked_at'        => $now,
			'next_check_at'          => $next,
			'error_message'          => '',
			'updated_at'             => $now,
		);
		if ( ! empty( $result['submitted'] ) ) {
			$data['last_submitted_at'] = $now;
		}

		$wpdb->query( $wpdb->prepare( "UPDATE $table SET attempt_count = attempt_count + 1 WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Save row error.
	 *
	 * @param int    $id      Row id.
	 * @param string $message Error message.
	 * @param int    $delay   Retry delay in seconds.
	 */
	public static function save_error( $id, $message, $delay = 3600 ) {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );

		$wpdb->query( $wpdb->prepare( "UPDATE $table SET attempt_count = attempt_count + 1 WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::update_row(
			$id,
			array(
				'index_status'  => 'error',
				'error_message' => self::truncate( $message, 1000 ),
				'next_check_at' => self::mysql_time( $delay ),
				'updated_at'    => $now,
			)
		);
	}

	/**
	 * Mark a single row pending due to quota.
	 *
	 * @param int $id Row id.
	 */
	public static function mark_pending_due_to_quota( $id ) {
		self::update_row(
			$id,
			array(
				'index_status'  => 'pending_due_to_quota',
				'next_check_at' => self::mysql_time( DAY_IN_SECONDS ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Mark all currently due rows pending due to quota.
	 *
	 * @return int
	 */
	public static function mark_due_pending_due_to_quota() {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );
		$next  = self::mysql_time( DAY_IN_SECONDS );

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table
				SET index_status = 'pending_due_to_quota', next_check_at = %s, updated_at = %s
				WHERE index_status <> 'ignored'
					AND index_status <> 'checking'
					AND (next_check_at IS NULL OR next_check_at <= %s)",
				$next,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::clear_summary_cache();
		return (int) $result;
	}

	/**
	 * Update a URL after sitemap submission.
	 *
	 * @param int    $id     Row id.
	 * @param string $status Status.
	 */
	public static function mark_submitted( $id, $status = 'pending_from_sitemap' ) {
		self::update_row(
			$id,
			array(
				'index_status'      => sanitize_key( $status ),
				'last_submitted_at' => current_time( 'mysql' ),
				'next_check_at'     => self::mysql_time( rand( 24, 72 ) * HOUR_IN_SECONDS ),
				'updated_at'        => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Mark all nonignored URLs for a full audit.
	 *
	 * @return int
	 */
	public static function schedule_full_audit() {
		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );

		$result = $wpdb->query( $wpdb->prepare( "UPDATE $table SET next_check_at = %s, updated_at = %s WHERE index_status <> 'ignored'", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::clear_summary_cache();
		return (int) $result;
	}

	/**
	 * List queue rows with filters.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_urls( array $args ) {
		global $wpdb;
		$table    = self::queue_table();
		$where    = array( '1=1' );
		$prepare  = array();
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 25 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]   = 'index_status = %s';
			$prepare[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['post_type'] ) && 'all' !== $args['post_type'] ) {
			$where[]   = 'post_type = %s';
			$prepare[] = sanitize_key( $args['post_type'] );
		}
		if ( isset( $args['priority'] ) && '' !== $args['priority'] ) {
			$where[]   = 'priority = %d';
			$prepare[] = absint( $args['priority'] );
		}
		if ( ! empty( $args['sitemap_hash'] ) ) {
			$where[]   = 'sitemap_hash = %s';
			$prepare[] = sanitize_text_field( $args['sitemap_hash'] );
		}
		if ( ! empty( $args['checked_from'] ) ) {
			$where[]   = 'last_checked_at >= %s';
			$prepare[] = sanitize_text_field( $args['checked_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $args['checked_to'] ) ) {
			$where[]   = 'last_checked_at <= %s';
			$prepare[] = sanitize_text_field( $args['checked_to'] ) . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$rows_sql  = "SELECT * FROM $table WHERE $where_sql ORDER BY priority DESC, COALESCE(next_check_at, '1970-01-01 00:00:00') ASC, id DESC LIMIT %d OFFSET %d";

		$total_prepare = $prepare;
		$rows_prepare  = array_merge( $prepare, array( $per_page, $offset ) );

		$total = ! empty( $total_prepare ) ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $total_prepare ) ) : (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = ! empty( $rows_prepare ) ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_prepare ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'     => array_map( array( __CLASS__, 'decorate_row' ), $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Sitemap filter options.
	 *
	 * @return array
	 */
	public static function sitemap_options() {
		global $wpdb;
		$table = self::queue_table();

		$rows = $wpdb->get_results( "SELECT sitemap_hash, MIN(sitemap_url) AS sitemap_url, COUNT(*) AS total FROM $table WHERE sitemap_hash <> '' GROUP BY sitemap_hash ORDER BY sitemap_url ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'hash' => (string) $row['sitemap_hash'],
				'url'  => (string) $row['sitemap_url'],
				'total' => (int) $row['total'],
			);
		}

		return $out;
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

		$table  = self::queue_table();
		$counts = array(
			'total'                   => 0,
			'indexed'                 => 0,
			'not_indexed'             => 0,
			'pending_due_to_quota'    => 0,
			'pending_from_sitemap'    => 0,
			'crawled_not_indexed'     => 0,
			'discovered_not_indexed'  => 0,
			'duplicate_canonical'     => 0,
			'blocked_by_robots'       => 0,
			'noindex_detected'        => 0,
			'errors'                  => 0,
		);

		$rows = $wpdb->get_results( "SELECT index_status, COUNT(*) AS total FROM $table GROUP BY index_status", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$status = (string) $row['index_status'];
			$total  = (int) $row['total'];
			$counts['total'] += $total;

			if ( 'error' === $status ) {
				$counts['errors'] += $total;
			} elseif ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] += $total;
			} elseif ( in_array( $status, array( 'queued', 'checking', 'pending_due_to_quota', 'pending_from_sitemap', 'sitemap_resubmitted', 'submitted_via_indexing_api' ), true ) ) {
				// Tracked individually only when a summary card exists.
			}
		}

		$counts['last_sync_time'] = (string) get_option( 'convertrack_gsc_last_sync_time', '' );
		$counts['next_scheduled_check'] = self::next_scheduled_check();

		set_transient( self::SUMMARY_CACHE_KEY, $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
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
	 * Insert a log row.
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
	 * Clear cached summary.
	 */
	public static function clear_summary_cache() {
		delete_transient( self::SUMMARY_CACHE_KEY );
	}

	/**
	 * Record one daily snapshot of index-status counts for the trend graph.
	 *
	 * Keyed by site-local date, so repeated calls in the same day just refresh
	 * that day's entry (keeping the latest counts). Capped to the most recent
	 * HISTORY_MAX_DAYS days. Stored in an option — no schema/migration needed.
	 */
	public static function record_snapshot() {
		$today   = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) );
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$counts  = self::summary();
		$pending = (int) $counts['pending_due_to_quota'] + (int) $counts['pending_from_sitemap']
			+ (int) $counts['crawled_not_indexed'] + (int) $counts['discovered_not_indexed'];
		$issues  = (int) $counts['not_indexed'] + (int) $counts['duplicate_canonical']
			+ (int) $counts['blocked_by_robots'] + (int) $counts['noindex_detected'];

		$history[ $today ] = array(
			'total'   => (int) $counts['total'],
			'indexed' => (int) $counts['indexed'],
			'pending' => $pending,
			'issues'  => $issues,
			'errors'  => (int) $counts['errors'],
		);

		if ( count( $history ) > self::HISTORY_MAX_DAYS ) {
			ksort( $history );
			$history = array_slice( $history, -self::HISTORY_MAX_DAYS, null, true );
		}

		update_option( self::HISTORY_OPTION, $history, false );
	}

	/**
	 * Return the daily status snapshots (oldest first) for the trend graph.
	 *
	 * @param int $days Max days to return.
	 * @return array List of { date, total, indexed, pending, issues, errors }.
	 */
	public static function summary_history( $days = 30 ) {
		$days    = max( 1, min( self::HISTORY_MAX_DAYS, (int) $days ) );
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) || empty( $history ) ) {
			return array();
		}

		ksort( $history );
		$sliced = array_slice( $history, -$days, null, true );
		$out    = array();
		foreach ( $sliced as $date => $row ) {
			$out[] = array(
				'date'    => (string) $date,
				'total'   => isset( $row['total'] ) ? (int) $row['total'] : 0,
				'indexed' => isset( $row['indexed'] ) ? (int) $row['indexed'] : 0,
				'pending' => isset( $row['pending'] ) ? (int) $row['pending'] : 0,
				'issues'  => isset( $row['issues'] ) ? (int) $row['issues'] : 0,
				'errors'  => isset( $row['errors'] ) ? (int) $row['errors'] : 0,
			);
		}

		return $out;
	}

	/**
	 * MySQL datetime with site-local offset.
	 *
	 * @param int $offset_seconds Offset from now.
	 * @return string
	 */
	public static function mysql_time( $offset_seconds = 0 ) {
		return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + (int) $offset_seconds ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Normalize URL for storage.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Update row by id.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Data.
	 */
	private static function update_row( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::queue_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Decorate a row for REST/admin output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decorate_row( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		$row['id']       = (int) $row['id'];
		$row['post_id']  = $post_id;
		$row['priority'] = (int) $row['priority'];
		$row['attempt_count'] = (int) $row['attempt_count'];
		$row['in_sitemap'] = ! empty( $row['in_sitemap'] );
		$row['edit_link'] = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
		$row['post_title'] = $post_id > 0 ? get_the_title( $post_id ) : '';

		// Output-only: give never-inspected rows a Search Console inspection
		// deep link too, so "Request Indexing" is always one click away.
		if ( empty( $row['inspection_result_link'] ) && ! empty( $row['url'] ) ) {
			$property = (string) Settings::get( 'property_url' );
			if ( '' !== $property ) {
				$row['inspection_result_link'] = 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&id=' . rawurlencode( $row['url'] );
			}
		}

		return $row;
	}

	/**
	 * Next scheduled due row.
	 *
	 * @return string
	 */
	private static function next_scheduled_check() {
		global $wpdb;
		$table = self::queue_table();
		$value = $wpdb->get_var( "SELECT MIN(next_check_at) FROM $table WHERE index_status <> 'ignored' AND next_check_at IS NOT NULL" ); // phpcs:ignore WordPress.DB
		return $value ? (string) $value : '';
	}

	/**
	 * Rollback failed install.
	 */
	private static function rollback_install( $queue_existed = false, $logs_existed = false ) {
		global $wpdb;
		if ( ! $queue_existed ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::queue_table() ); // phpcs:ignore WordPress.DB
		}
		if ( ! $logs_existed ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::logs_table() ); // phpcs:ignore WordPress.DB
		}
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Check if a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) === strtolower( $table );
	}

	/**
	 * Truncate text safely.
	 *
	 * @param string $value Value.
	 * @param int    $len   Length.
	 * @return string
	 */
	private static function truncate( $value, $len ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $len ) : substr( $value, 0, $len );
	}
}
