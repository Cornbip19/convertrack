<?php
/**
 * GSC Keyword Insights data layer.
 *
 * Two tables: one row per (query, page, range[, country, device]) with GSC
 * metrics plus analysis output, and a per-page rollup used by page-centric
 * views. Rows are refreshed in place on every sync of a range.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Database {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'convertrack_gsc_keywords_db_version';
	const SUMMARY_CACHE_KEY = 'convertrack_gsc_keywords_summary';

	/**
	 * Keywords table.
	 *
	 * @return string
	 */
	public static function keywords_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_gsc_keywords';
	}

	/**
	 * Per-page rollup table.
	 *
	 * @return string
	 */
	public static function pages_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_gsc_keyword_pages';
	}

	/**
	 * Install or update tables.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$keywords        = self::keywords_table();
		$pages           = self::pages_table();
		$keywords_existed = self::table_exists( $keywords );
		$pages_existed    = self::table_exists( $pages );

		$sql = array();

		$sql[] = "CREATE TABLE $keywords (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_hash char(32) NOT NULL DEFAULT '',
			query varchar(500) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL,
			page_hash char(32) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			range_key varchar(20) NOT NULL DEFAULT '28d',
			country varchar(3) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			ctr decimal(6,4) NOT NULL DEFAULT 0,
			position decimal(6,2) NOT NULL DEFAULT 0,
			labels longtext NULL,
			presence_status varchar(20) NOT NULL DEFAULT 'unknown',
			opportunity_score decimal(6,2) NOT NULL DEFAULT 0,
			opportunity_level varchar(12) NOT NULL DEFAULT '',
			recommendations longtext NULL,
			analysis_json longtext NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			analysis_state varchar(10) NOT NULL DEFAULT 'pending',
			last_analyzed_at datetime NULL DEFAULT NULL,
			synced_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY keyword_hash (keyword_hash),
			KEY page_range (page_hash, range_key),
			KEY range_impressions (range_key, impressions),
			KEY range_opportunity (range_key, opportunity_score),
			KEY analysis_post (analysis_state, post_id),
			KEY query_prefix (query(191)),
			KEY synced_at (synced_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $pages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_hash char(32) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL,
			range_key varchar(20) NOT NULL DEFAULT '28d',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(100) NOT NULL DEFAULT '',
			keywords int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			avg_ctr decimal(6,4) NOT NULL DEFAULT 0,
			avg_position decimal(6,2) NOT NULL DEFAULT 0,
			best_query varchar(500) NOT NULL DEFAULT '',
			opportunity_count int(10) unsigned NOT NULL DEFAULT 0,
			opportunity_score decimal(6,2) NOT NULL DEFAULT 0,
			recommendations longtext NULL,
			last_analyzed_at datetime NULL DEFAULT NULL,
			synced_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY page_range (page_hash, range_key),
			KEY post_id (post_id),
			KEY post_type_range (post_type, range_key),
			KEY range_impressions (range_key, impressions),
			KEY range_opportunity (range_key, opportunity_score)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
			if ( ! empty( $wpdb->last_error ) ) {
				self::rollback_install( $keywords_existed, $pages_existed );
				return new \WP_Error( 'convertrack_gsc_keywords_db_error', $wpdb->last_error );
			}
		}

		if ( ! self::table_exists( $keywords ) || ! self::table_exists( $pages ) ) {
			self::rollback_install( $keywords_existed, $pages_existed );
			return new \WP_Error( 'convertrack_gsc_keywords_db_missing', __( 'Keyword Insights tables could not be created.', 'convertrack-click-conversion-analytics' ) );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		Logger::info( 'keywords-db', 'Keyword Insights database migration completed.', array( 'version' => self::DB_VERSION ) );
		return true;
	}

	/**
	 * Upgrade when needed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$result = self::install();
			if ( is_wp_error( $result ) ) {
				$settings            = Keywords_Settings::all();
				$settings['enabled'] = 0;
				Keywords_Settings::save( $settings );
				Logger::error( 'keywords-db', 'Keyword Insights database migration failed.', array( 'error' => $result->get_error_message() ) );
				set_transient( 'convertrack_gsc_keywords_migration_error', $result->get_error_message(), HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Drop tables.
	 */
	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::keywords_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::pages_table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Batch-upsert keyword rows for a range.
	 *
	 * Uses multi-row INSERT ... ON DUPLICATE KEY UPDATE (unlike the per-row
	 * wpdb->insert style elsewhere) because a sync writes thousands of rows.
	 * The update clause only ever touches metrics + timestamps so analysis
	 * results survive re-syncs.
	 *
	 * @param array  $rows      Rows: { query, page_url, page_hash, keyword_hash, country, device, clicks, impressions, ctr, position }.
	 * @param string $range_key Range key.
	 * @param string $synced_at MySQL datetime for this sync pass.
	 * @return int Rows written.
	 */
	public static function upsert_keywords( array $rows, $range_key, $synced_at ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$table   = self::keywords_table();
		$now     = current_time( 'mysql' );
		$written = 0;

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%d,%d,%f,%f,%s,%s,%s)';
				$values[]       = $row['keyword_hash'];
				$values[]       = $row['query'];
				$values[]       = $row['page_url'];
				$values[]       = $row['page_hash'];
				$values[]       = $range_key;
				$values[]       = isset( $row['country'] ) ? $row['country'] : '';
				$values[]       = isset( $row['device'] ) ? $row['device'] : '';
				$values[]       = (int) $row['clicks'];
				$values[]       = (int) $row['impressions'];
				$values[]       = (float) $row['ctr'];
				$values[]       = (float) $row['position'];
				$values[]       = $synced_at;
				$values[]       = $now;
				$values[]       = $now;
			}

			$sql = "INSERT INTO $table (keyword_hash, query, page_url, page_hash, range_key, country, device, clicks, impressions, ctr, position, synced_at, created_at, updated_at)
				VALUES " . implode( ',', $placeholders ) . '
				ON DUPLICATE KEY UPDATE
					clicks = VALUES(clicks),
					impressions = VALUES(impressions),
					ctr = VALUES(ctr),
					position = VALUES(position),
					synced_at = VALUES(synced_at),
					updated_at = VALUES(updated_at)';

			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false !== $result ) {
				$written += count( $chunk );
			}
		}

		self::clear_summary_cache();
		return $written;
	}

	/**
	 * Delete rows of a range that were not touched by the given sync pass.
	 *
	 * @param string $range_key Range key.
	 * @param string $synced_at Sync pass datetime.
	 * @return int
	 */
	public static function prune_stale( $range_key, $synced_at ) {
		global $wpdb;
		$keywords = self::keywords_table();
		$pages    = self::pages_table();

		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $keywords WHERE range_key = %s AND synced_at < %s", $range_key, $synced_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $pages WHERE range_key = %s AND synced_at < %s", $range_key, $synced_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::clear_summary_cache();
		return $deleted;
	}

	/**
	 * Delete leftover one-off custom-range rows.
	 *
	 * @param int $max_age_days Age cutoff in days.
	 * @return int
	 */
	public static function prune_custom_range( $max_age_days = 30 ) {
		global $wpdb;
		$cutoff   = Database::mysql_time( - max( 1, (int) $max_age_days ) * DAY_IN_SECONDS );
		$keywords = self::keywords_table();
		$pages    = self::pages_table();

		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $keywords WHERE range_key = 'custom' AND synced_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $pages WHERE range_key = 'custom' AND synced_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $deleted ) {
			self::clear_summary_cache();
		}
		return $deleted;
	}

	/**
	 * Rebuild the per-page rollup for a range from the keyword rows.
	 *
	 * @param string $range_key Range key.
	 * @param string $synced_at Sync pass datetime.
	 */
	public static function rebuild_page_rollup( $range_key, $synced_at ) {
		global $wpdb;
		$keywords = self::keywords_table();
		$pages    = self::pages_table();
		$now      = current_time( 'mysql' );

		// best_query relies on GROUP_CONCAT; bump the session cap so long
		// query lists are not silently truncated mid-value.
		$wpdb->query( 'SET SESSION group_concat_max_len = 65535' ); // phpcs:ignore WordPress.DB

		$sql = "INSERT INTO $pages (page_hash, page_url, range_key, keywords, clicks, impressions, avg_ctr, avg_position, best_query, synced_at, created_at, updated_at)
			SELECT
				page_hash,
				MIN(page_url),
				%s,
				COUNT(*),
				SUM(clicks),
				SUM(impressions),
				IFNULL(SUM(clicks) / NULLIF(SUM(impressions), 0), 0),
				IFNULL(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0),
				SUBSTRING_INDEX(GROUP_CONCAT(query ORDER BY clicks DESC, impressions DESC SEPARATOR 0x1D), 0x1D, 1),
				%s, %s, %s
			FROM $keywords
			WHERE range_key = %s
			GROUP BY page_hash
			ON DUPLICATE KEY UPDATE
				page_url = VALUES(page_url),
				keywords = VALUES(keywords),
				clicks = VALUES(clicks),
				impressions = VALUES(impressions),
				avg_ctr = VALUES(avg_ctr),
				avg_position = VALUES(avg_position),
				best_query = VALUES(best_query),
				synced_at = VALUES(synced_at),
				updated_at = VALUES(updated_at)";

		$wpdb->query( $wpdb->prepare( $sql, $range_key, $synced_at, $now, $now, $range_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Resolve post ids for pages. Reuses the Index Monitor queue mapping via a
	 * set-based hash join first (both stores hash urls as md5(lower(url))),
	 * then falls back to a bounded url_to_postid() loop.
	 *
	 * @param int $fallback_limit Max pages resolved via url_to_postid() per call.
	 * @return int Pages mapped.
	 */
	public static function map_page_posts( $fallback_limit = 200 ) {
		global $wpdb;
		$pages    = self::pages_table();
		$keywords = self::keywords_table();
		$queue    = Database::queue_table();
		$mapped   = 0;

		if ( self::table_exists( $queue ) ) {
			$mapped += (int) $wpdb->query(
				"UPDATE $pages p
				INNER JOIN $queue q ON q.url_hash = p.page_hash
				SET p.post_id = q.post_id, p.post_type = q.post_type
				WHERE p.post_id = 0 AND q.post_id > 0"
			); // phpcs:ignore WordPress.DB
		}

		$unmapped = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT page_hash, page_url FROM $pages WHERE post_id = 0 LIMIT %d",
				max( 1, (int) $fallback_limit )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $unmapped as $row ) {
			$post_id = self::resolve_post_id( (string) $row['page_url'] );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post_type = (string) get_post_type( $post_id );
			$wpdb->update(
				$pages,
				array(
					'post_id'   => $post_id,
					'post_type' => $post_type,
				),
				array( 'page_hash' => $row['page_hash'] )
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$mapped++;
		}

		// Propagate the mapping down to keyword rows for cheap per-post lookups.
		$wpdb->query(
			"UPDATE $keywords k
			INNER JOIN $pages p ON p.page_hash = k.page_hash
			SET k.post_id = p.post_id
			WHERE k.post_id = 0 AND p.post_id > 0"
		); // phpcs:ignore WordPress.DB

		return $mapped;
	}

	/**
	 * Map one URL to a post id.
	 *
	 * @param string $url Page URL.
	 * @return int
	 */
	public static function resolve_post_id( $url ) {
		$bare = strtok( $url, '?' );
		$bare = $bare ? $bare : $url;

		// url_to_postid() cannot resolve the homepage; handle a static front page.
		if ( untrailingslashit( $bare ) === untrailingslashit( home_url( '/' ) ) ) {
			return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		}

		return (int) url_to_postid( $bare );
	}

	/**
	 * List keyword rows with filters.
	 *
	 * @param array $args Query args.
	 * @return array { rows, total, page, per_page, pages }
	 */
	public static function list_keywords( array $args ) {
		global $wpdb;
		$table    = self::keywords_table();
		$where    = array( 'range_key = %s' );
		$prepare  = array( self::sanitize_range( isset( $args['range_key'] ) ? $args['range_key'] : '' ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 25 ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]   = '(query LIKE %s OR page_url LIKE %s)';
			$prepare[] = $like;
			$prepare[] = $like;
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[]   = 'post_id = %d';
			$prepare[] = absint( $args['post_id'] );
		}
		if ( ! empty( $args['page_hash'] ) ) {
			$where[]   = 'page_hash = %s';
			$prepare[] = sanitize_text_field( $args['page_hash'] );
		}
		if ( ! empty( $args['presence'] ) && 'all' !== $args['presence'] ) {
			$where[]   = 'presence_status = %s';
			$prepare[] = sanitize_key( $args['presence'] );
		}
		if ( ! empty( $args['opportunity'] ) && 'all' !== $args['opportunity'] ) {
			$where[]   = 'opportunity_level = %s';
			$prepare[] = sanitize_key( $args['opportunity'] );
		}
		if ( ! empty( $args['label'] ) && 'all' !== $args['label'] ) {
			$where[]   = 'labels LIKE %s';
			$prepare[] = '%' . $wpdb->esc_like( '"' . sanitize_key( $args['label'] ) . '"' ) . '%';
		}
		if ( ! empty( $args['min_impressions'] ) ) {
			$where[]   = 'impressions >= %d';
			$prepare[] = absint( $args['min_impressions'] );
		}

		$orderby_whitelist = array( 'opportunity_score', 'clicks', 'impressions', 'ctr', 'position', 'query', 'last_analyzed_at' );
		$orderby           = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'opportunity_score';
		if ( ! in_array( $orderby, $orderby_whitelist, true ) ) {
			$orderby = 'opportunity_score';
		}
		$order = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
		$rows_sql  = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order, impressions DESC, id ASC LIMIT %d OFFSET %d";

		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $prepare ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $prepare, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'     => array_map( array( __CLASS__, 'decorate_keyword_row' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * List page rollup rows.
	 *
	 * @param array $args Query args: range_key, search, limit, orderby.
	 * @return array
	 */
	public static function list_pages( array $args ) {
		global $wpdb;
		$table   = self::pages_table();
		$where   = array( 'range_key = %s' );
		$prepare = array( self::sanitize_range( isset( $args['range_key'] ) ? $args['range_key'] : '' ) );
		$limit   = max( 1, min( 200, isset( $args['limit'] ) ? (int) $args['limit'] : 100 ) );

		if ( ! empty( $args['search'] ) ) {
			$where[]   = 'page_url LIKE %s';
			$prepare[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}
		if ( ! empty( $args['post_type'] ) && 'all' !== $args['post_type'] ) {
			$where[]   = 'post_type = %s';
			$prepare[] = sanitize_key( $args['post_type'] );
		}

		$orderby = isset( $args['orderby'] ) && 'impressions' === $args['orderby'] ? 'impressions' : 'opportunity_score';

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby DESC, impressions DESC LIMIT %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $prepare, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'decorate_page_row' ), (array) $rows );
	}

	/**
	 * All keyword rows for one page (detail view).
	 *
	 * @param int    $post_id   Post id (0 allowed when $page_hash given).
	 * @param string $page_hash Page hash fallback.
	 * @param string $range_key Range key.
	 * @return array
	 */
	public static function page_keywords( $post_id, $page_hash, $range_key ) {
		global $wpdb;
		$table = self::keywords_table();
		$range = self::sanitize_range( $range_key );

		if ( $post_id > 0 ) {
			$sql = $wpdb->prepare( "SELECT * FROM $table WHERE range_key = %s AND post_id = %d ORDER BY impressions DESC LIMIT 500", $range, (int) $post_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM $table WHERE range_key = %s AND page_hash = %s ORDER BY impressions DESC LIMIT 500", $range, sanitize_text_field( $page_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'decorate_keyword_row' ), (array) $rows );
	}

	/**
	 * Dashboard summary for a range.
	 *
	 * @param string $range_key Range key.
	 * @return array
	 */
	public static function summary( $range_key ) {
		global $wpdb;
		$range  = self::sanitize_range( $range_key );
		$cached = get_transient( self::SUMMARY_CACHE_KEY . '_' . $range );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table = self::keywords_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_keywords,
					COUNT(DISTINCT page_hash) AS total_pages,
					SUM(CASE WHEN opportunity_level = 'high' THEN 1 ELSE 0 END) AS high_opportunity,
					COUNT(DISTINCT CASE WHEN presence_status = 'missing' THEN page_hash END) AS pages_missing,
					SUM(CASE WHEN position >= 11 AND position <= 20 THEN 1 ELSE 0 END) AS page_two,
					SUM(CASE WHEN recommendations LIKE %s THEN 1 ELSE 0 END) AS low_ctr,
					SUM(CASE WHEN labels LIKE %s THEN 1 ELSE 0 END) AS branded,
					SUM(CASE WHEN labels LIKE %s THEN 1 ELSE 0 END) AS non_branded,
					SUM(CASE WHEN analysis_state IN ('pending','stale') THEN 1 ELSE 0 END) AS pending_analysis,
					MAX(synced_at) AS last_synced_at,
					MAX(last_analyzed_at) AS last_analyzed_at
				FROM $table WHERE range_key = %s",
				'%' . $wpdb->esc_like( '"improve_title_meta"' ) . '%',
				'%' . $wpdb->esc_like( '"branded"' ) . '%',
				'%' . $wpdb->esc_like( '"non_branded"' ) . '%',
				$range
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$summary = array(
			'range'            => $range,
			'total_keywords'   => isset( $row['total_keywords'] ) ? (int) $row['total_keywords'] : 0,
			'total_pages'      => isset( $row['total_pages'] ) ? (int) $row['total_pages'] : 0,
			'high_opportunity' => isset( $row['high_opportunity'] ) ? (int) $row['high_opportunity'] : 0,
			'pages_missing'    => isset( $row['pages_missing'] ) ? (int) $row['pages_missing'] : 0,
			'page_two'         => isset( $row['page_two'] ) ? (int) $row['page_two'] : 0,
			'low_ctr'          => isset( $row['low_ctr'] ) ? (int) $row['low_ctr'] : 0,
			'branded'          => isset( $row['branded'] ) ? (int) $row['branded'] : 0,
			'non_branded'      => isset( $row['non_branded'] ) ? (int) $row['non_branded'] : 0,
			'pending_analysis' => isset( $row['pending_analysis'] ) ? (int) $row['pending_analysis'] : 0,
			'last_synced_at'   => isset( $row['last_synced_at'] ) ? (string) $row['last_synced_at'] : '',
			'last_analyzed_at' => isset( $row['last_analyzed_at'] ) ? (string) $row['last_analyzed_at'] : '',
			'top_pages'        => self::list_pages( array( 'range_key' => $range, 'limit' => 5 ) ),
		);

		set_transient( self::SUMMARY_CACHE_KEY . '_' . $range, $summary, 5 * MINUTE_IN_SECONDS );
		return $summary;
	}

	/**
	 * Next batch of rows awaiting analysis, grouped by post so the content
	 * fingerprint cache pays off.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	public static function analysis_batch( $limit ) {
		global $wpdb;
		$table = self::keywords_table();
		$limit = max( 1, min( 500, (int) $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE analysis_state IN ('pending','stale') ORDER BY post_id ASC, impressions DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count rows awaiting analysis.
	 *
	 * @return int
	 */
	public static function pending_analysis_count() {
		global $wpdb;
		$table = self::keywords_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE analysis_state IN ('pending','stale')" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Persist analysis output for one keyword row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields { labels, presence_status, opportunity_score, opportunity_level, recommendations, analysis_json, content_hash, analysis_state }.
	 */
	public static function save_analysis( $id, array $fields ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$data = array(
			'labels'            => wp_json_encode( isset( $fields['labels'] ) ? array_values( (array) $fields['labels'] ) : array() ),
			'presence_status'   => sanitize_key( isset( $fields['presence_status'] ) ? $fields['presence_status'] : 'unknown' ),
			'opportunity_score' => isset( $fields['opportunity_score'] ) ? (float) $fields['opportunity_score'] : 0,
			'opportunity_level' => sanitize_key( isset( $fields['opportunity_level'] ) ? $fields['opportunity_level'] : '' ),
			'recommendations'   => wp_json_encode( isset( $fields['recommendations'] ) ? array_values( (array) $fields['recommendations'] ) : array() ),
			'analysis_json'     => wp_json_encode( isset( $fields['analysis_json'] ) ? $fields['analysis_json'] : array() ),
			'content_hash'      => isset( $fields['content_hash'] ) ? substr( (string) $fields['content_hash'], 0, 32 ) : '',
			'analysis_state'    => sanitize_key( isset( $fields['analysis_state'] ) ? $fields['analysis_state'] : 'done' ),
			'last_analyzed_at'  => $now,
			'updated_at'        => $now,
		);

		$wpdb->update( self::keywords_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Mark one row failed without wiping prior analysis.
	 *
	 * @param int $id Row id.
	 */
	public static function mark_analysis_failed( $id ) {
		global $wpdb;
		$wpdb->update(
			self::keywords_table(),
			array(
				'analysis_state' => 'failed',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Mark one post's keyword rows stale.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function mark_post_stale( $post_id ) {
		global $wpdb;
		$table = self::keywords_table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET analysis_state = 'stale' WHERE post_id = %d AND analysis_state IN ('done','failed')",
				absint( $post_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mark selected rows stale.
	 *
	 * @param array $ids Row ids.
	 * @return int
	 */
	public static function mark_rows_stale( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::keywords_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE $table SET analysis_state = 'stale' WHERE id IN ($placeholders) AND analysis_state <> 'pending'", $ids )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mark every analyzed row stale (settings changed / manual re-analyze).
	 *
	 * @return int
	 */
	public static function mark_all_stale() {
		global $wpdb;
		$table = self::keywords_table();
		return (int) $wpdb->query( "UPDATE $table SET analysis_state = 'stale' WHERE analysis_state IN ('done','failed')" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Refresh page-level analysis aggregates from analyzed keyword rows.
	 */
	public static function refresh_page_analysis() {
		global $wpdb;
		$keywords = self::keywords_table();
		$pages    = self::pages_table();
		$now      = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $pages p
				INNER JOIN (
					SELECT page_hash, range_key,
						SUM(CASE WHEN opportunity_level IN ('high','medium') THEN 1 ELSE 0 END) AS opp_count,
						MAX(opportunity_score) AS opp_score,
						MAX(last_analyzed_at) AS analyzed_at
					FROM $keywords
					WHERE analysis_state = 'done'
					GROUP BY page_hash, range_key
				) k ON k.page_hash = p.page_hash AND k.range_key = p.range_key
				SET p.opportunity_count = k.opp_count,
					p.opportunity_score = k.opp_score,
					p.last_analyzed_at = k.analyzed_at,
					p.updated_at = %s",
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::clear_summary_cache();
	}

	/**
	 * Clear cached summaries for all ranges.
	 */
	public static function clear_summary_cache() {
		$ranges   = Keywords_Settings::ranges_vocabulary();
		$ranges[] = 'custom';
		foreach ( $ranges as $range ) {
			delete_transient( self::SUMMARY_CACHE_KEY . '_' . $range );
		}
	}

	/**
	 * Whether any keyword data exists at all.
	 *
	 * @return bool
	 */
	public static function has_data() {
		global $wpdb;
		$table = self::keywords_table();
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( "SELECT id FROM $table LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Validate a range key for queries.
	 *
	 * @param string $range_key Raw range key.
	 * @return string
	 */
	public static function sanitize_range( $range_key ) {
		$range_key = sanitize_key( (string) $range_key );
		$allowed   = Keywords_Settings::ranges_vocabulary();
		$allowed[] = 'custom';
		if ( in_array( $range_key, $allowed, true ) ) {
			return $range_key;
		}
		return (string) Keywords_Settings::get( 'default_range', '28d' );
	}

	/**
	 * Decorate a keyword row for REST output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decorate_keyword_row( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		$row['id']                = (int) $row['id'];
		$row['post_id']           = $post_id;
		$row['clicks']            = (int) $row['clicks'];
		$row['impressions']       = (int) $row['impressions'];
		$row['ctr']               = (float) $row['ctr'];
		$row['position']          = (float) $row['position'];
		$row['opportunity_score'] = (float) $row['opportunity_score'];
		$row['labels']            = self::decode_json_list( isset( $row['labels'] ) ? $row['labels'] : '' );
		$row['recommendations']   = self::decode_json_list( isset( $row['recommendations'] ) ? $row['recommendations'] : '' );
		$row['analysis']          = json_decode( isset( $row['analysis_json'] ) ? (string) $row['analysis_json'] : '', true );
		$row['analysis']          = is_array( $row['analysis'] ) ? $row['analysis'] : array();
		$row['post_title']        = $post_id > 0 ? get_the_title( $post_id ) : '';
		$row['edit_link']         = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
		unset( $row['analysis_json'] );

		return $row;
	}

	/**
	 * Decorate a page rollup row for REST output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decorate_page_row( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		$row['id']                = (int) $row['id'];
		$row['post_id']           = $post_id;
		$row['keywords']          = (int) $row['keywords'];
		$row['clicks']            = (int) $row['clicks'];
		$row['impressions']       = (int) $row['impressions'];
		$row['avg_ctr']           = (float) $row['avg_ctr'];
		$row['avg_position']      = (float) $row['avg_position'];
		$row['opportunity_count'] = (int) $row['opportunity_count'];
		$row['opportunity_score'] = (float) $row['opportunity_score'];
		$row['post_title']        = $post_id > 0 ? get_the_title( $post_id ) : '';
		$row['edit_link']         = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';

		return $row;
	}

	/**
	 * Decode a JSON array column defensively.
	 *
	 * @param string $json Raw JSON.
	 * @return array
	 */
	private static function decode_json_list( $json ) {
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Rollback failed install.
	 *
	 * @param bool $keywords_existed Keywords table pre-existed.
	 * @param bool $pages_existed    Pages table pre-existed.
	 */
	private static function rollback_install( $keywords_existed = false, $pages_existed = false ) {
		global $wpdb;
		if ( ! $keywords_existed ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::keywords_table() ); // phpcs:ignore WordPress.DB
		}
		if ( ! $pages_existed ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::pages_table() ); // phpcs:ignore WordPress.DB
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
}
