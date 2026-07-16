<?php
/**
 * Atomic, resumable daily analytics rollups.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

/**
 * Builds daily aggregates in an owner-scoped staging table and swaps them in
 * within one transaction. Raw data is never eligible for deletion until the
 * corresponding state row is complete.
 */
class Rollup_Manager {

	const LEASE_SECONDS = 300;
	const MAX_ATTEMPTS  = 8;
	const OTHER_BUCKET  = '[Other]';

	/**
	 * Rebuild one site-local calendar day.
	 *
	 * @param string $date  Y-m-d.
	 * @param bool   $force Rebuild a previously completed day.
	 * @return array|\WP_Error
	 */
	public static function rollup_day( $date, $force = false ) {
		global $wpdb;

		$date = preg_replace( '/[^0-9\-]/', '', (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date > Database::today() ) {
			return new \WP_Error( 'convertrack_rollup_date', 'Invalid rollup date.' );
		}

		if ( ! Database::schema_is_healthy() ) {
			return new \WP_Error( 'convertrack_schema_unhealthy', 'Analytics schema is not healthy.' );
		}

		$token = wp_generate_uuid4();
		$claim = self::claim( $date, $token, (bool) $force );
		if ( is_wp_error( $claim ) || ! $claim ) {
			return is_wp_error( $claim ) ? $claim : array( 'status' => 'skipped', 'date' => $date );
		}

		$cleaned = self::cleanup_stage( $date, $token, true );
		if ( is_wp_error( $cleaned ) ) {
			return self::fail( $date, $token, $cleaned->get_error_message() );
		}

		$start = $date . ' 00:00:00';
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 day' ) );
		$built = self::build_stage( $date, $start, $end, $token );
		if ( is_wp_error( $built ) ) {
			self::fail( $date, $token, $built->get_error_message() );
			return $built;
		}

		$result = self::replace_day( $date, $token, $built );
		$cleanup = self::cleanup_stage( $date, $token, false );
		if ( is_wp_error( $cleanup ) ) {
			update_option( 'convertrack_rollup_last_error', $date . ': ' . $cleanup->get_error_message(), false );
			if ( is_array( $result ) ) {
				$result['cleanup_error'] = $cleanup->get_error_message();
			}
		}
		return $result;
	}

	/**
	 * Roll up the oldest uncompleted raw-data days within a wall-clock budget.
	 *
	 * @param int $max_days       Maximum days per invocation.
	 * @param int $budget_seconds Wall-clock budget.
	 * @return array|\WP_Error
	 */
	public static function catch_up( $max_days = 10, $budget_seconds = 20 ) {
		global $wpdb;

		$max_days       = max( 1, min( 100, (int) $max_days ) );
		$budget_seconds = max( 2, min( 50, (int) $budget_seconds ) );
		$events         = Database::events_table();
		$state          = Database::rollup_state_table();
		$today          = Database::today();

		// Include missing, failed and expired-running days. Completed days remain
		// immutable unless explicitly forced for late-arriving data.
		$sql = "SELECT DATE(e.created_at) AS stat_date,
			MAX(CASE WHEN s.status='complete' AND e.id > s.source_event_max_id THEN 1 ELSE 0 END) AS force_rebuild
			FROM $events e
			LEFT JOIN $state s ON s.stat_date = DATE(e.created_at)
			WHERE e.created_at < %s
			  AND (s.stat_date IS NULL OR s.status IN ('pending','failed')
			       OR (s.status='running' AND s.lease_expires_at < %s)
			       OR (s.status='complete' AND e.id > s.source_event_max_id))
			GROUP BY DATE(e.created_at)
			ORDER BY stat_date ASC LIMIT %d";
		// Fetch one extra day so continuation scheduling is truthful.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dates = $wpdb->get_results( $wpdb->prepare( $sql, $today . ' 00:00:00', current_time( 'mysql' ), $max_days + 1 ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_rollup_discovery', $wpdb->last_error );
		}

		$has_more  = count( (array) $dates ) > $max_days;
		$dates     = array_slice( (array) $dates, 0, $max_days );
		$started   = microtime( true );
		$processed = array();
		$failed    = array();
		foreach ( (array) $dates as $candidate ) {
			if ( microtime( true ) - $started >= $budget_seconds ) {
				break;
			}
			$date   = (string) $candidate['stat_date'];
			$result = self::rollup_day( $date, ! empty( $candidate['force_rebuild'] ) );
			if ( is_wp_error( $result ) ) {
				$failed[ $date ] = $result->get_error_message();
			} elseif ( isset( $result['status'] ) && 'complete' === $result['status'] ) {
				$processed[] = $date;
			}
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => ( $has_more ? 1 : 0 ) + max( 0, count( (array) $dates ) - count( $processed ) - count( $failed ) ),
		);
	}

	/**
	 * Atomically claim a state row.
	 *
	 * @param string $date  Date.
	 * @param string $token Owner token.
	 * @param bool   $force Force completed rebuild.
	 * @return bool|\WP_Error
	 */
	private static function claim( $date, $token, $force ) {
		global $wpdb;
		$table = Database::rollup_state_table();
		$now   = current_time( 'mysql' );
		$lease = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::LEASE_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (stat_date,status,attempts,updated_at) VALUES (%s,'pending',0,%s)",
				$date,
				$now
			)
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'convertrack_rollup_claim', $wpdb->last_error );
		}

		// A successful day resets attempts below, so routine forced rebuilds do
		// not exhaust the retry budget. Also allow a completed row written by an
		// earlier schema version at MAX_ATTEMPTS to be explicitly rebuilt once;
		// failed/terminal rows still respect the bounded retry policy.
		$attempt_clause = $force ? "(status IN ('complete','terminal') OR attempts < %d)" : "status NOT IN ('complete','terminal') AND attempts < %d";
		$attempt_update = $force ? "attempts=CASE WHEN status IN ('complete','terminal') THEN 1 ELSE attempts+1 END" : 'attempts=attempts+1';
		$sql = "UPDATE $table SET $attempt_update, status='running', owner_token=%s, lease_expires_at=%s,
			started_at=%s, completed_at=NULL, last_error='', updated_at=%s
			WHERE stat_date=%s AND $attempt_clause
			AND (status <> 'running' OR lease_expires_at IS NULL OR lease_expires_at < %s)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $wpdb->prepare( $sql, $token, $lease, $now, $now, $date, self::MAX_ATTEMPTS, $now ) );
		if ( false === $updated ) {
			return new \WP_Error( 'convertrack_rollup_claim', $wpdb->last_error );
		}
		return 1 === (int) $updated;
	}

	/**
	 * Populate owner-scoped staging buckets using bulk INSERT SELECT queries.
	 *
	 * @param string $date  Date.
	 * @param string $start Inclusive datetime.
	 * @param string $end   Exclusive datetime.
	 * @param string $token Owner token.
	 * @return array|\WP_Error
	 */
	private static function build_stage( $date, $start, $end, $token ) {
		global $wpdb;
		$events = Database::events_table();
		$stage  = Database::rollup_stage_table();
		$salt   = wp_salt( 'auth' );
		$source = self::source_snapshot( $start, $end );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$source_max_id = (int) $source['max_id'];

		$page_expr = "CASE WHEN page_key <> '' THEN page_key WHEN post_id > 0 THEN CONCAT('legacy-post:',post_id) ELSE CONCAT('legacy-url:',LEFT(SHA2(page_url,256),40)) END";
		$queries   = array();

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,page_key,post_id,dimension_one,dimension_two,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
			SELECT %s,'daily',MD5(CONCAT(%s,'|daily|',page_identity,'|',selector_bucket)),%s,page_identity,MAX(post_id),selector_bucket,'',
			SUBSTRING_INDEX(MAX(CONCAT(created_at,'|||',CASE WHEN event_type='click' THEN element_text ELSE '' END)),'|||',-1),
			SUM(event_type='click'),COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END),SUM(is_conversion),SUM(event_type='pageview'),COUNT(DISTINCT visitor_id)
			FROM (SELECT *, $page_expr AS page_identity,CASE WHEN event_type='click' THEN element_selector ELSE '' END AS selector_bucket
			FROM $events WHERE created_at >= %s AND created_at < %s AND id <= %d AND event_type IN ('click','pageview')) scoped
			GROUP BY page_identity,selector_bucket",
			$token,
			$date,
			$date,
			$start,
			$end,
			$source_max_id
		);

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,dimension_one,dimension_two,clicks,conversions,conversion_events,pageviews,unique_visitors)
			SELECT %s,'source',MD5(CONCAT(%s,'|source|',source_bucket,'|',campaign_bucket)),%s,source_bucket,campaign_bucket,
			SUM(event_type='click'),COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END),SUM(is_conversion),SUM(event_type='pageview'),COUNT(DISTINCT visitor_id)
			FROM (SELECT *,CASE WHEN source='' THEN 'Direct' ELSE source END source_bucket,utm_campaign campaign_bucket
			FROM $events WHERE created_at >= %s AND created_at < %s AND id <= %d) scoped GROUP BY source_bucket,campaign_bucket",
			$token,
			$date,
			$date,
			$start,
			$end,
			$source_max_id
		);

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,dimension_one,clicks,conversions,conversion_events,pageviews,unique_visitors)
			SELECT %s,'geo',MD5(CONCAT(%s,'|geo|',country)),%s,country,SUM(event_type='click'),
			COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END),SUM(is_conversion),SUM(event_type='pageview'),COUNT(DISTINCT visitor_id)
			FROM $events WHERE country <> '' AND created_at >= %s AND created_at < %s AND id <= %d GROUP BY country",
			$token,
			$date,
			$date,
			$start,
			$end,
			$source_max_id
		);

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,page_key,post_id,dimension_one,dimension_two,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
			SELECT %s,'search',MD5(CONCAT(%s,'|search|',page_identity,'|',keyword_source,'|',keyword,'|',traffic_source)),%s,page_identity,MAX(post_id),keyword,keyword_source,traffic_source,
			SUM(event_type='click'),COUNT(DISTINCT CASE WHEN is_conversion=1 THEN session_id END),SUM(is_conversion),SUM(event_type='pageview'),COUNT(DISTINCT visitor_id)
			FROM (SELECT *,$page_expr AS page_identity,
			CASE WHEN search_keyword<>'' THEN search_keyword WHEN source='Organic search' THEN '(not provided)' ELSE '' END keyword,
			CASE WHEN search_keyword<>'' THEN search_source WHEN source='Organic search' THEN 'organic_not_provided' ELSE '' END keyword_source,
			CASE WHEN source='' THEN 'Direct' ELSE source END traffic_source
			FROM $events WHERE created_at >= %s AND created_at < %s AND id <= %d) scoped
			WHERE keyword<>'' GROUP BY page_identity,keyword,keyword_source,traffic_source",
			$token,
			$date,
			$date,
			$start,
			$end,
			$source_max_id
		);

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,dimension_one,conversions,conversion_events,pageviews,clicks)
			SELECT %s,'visitor',MD5(CONCAT(%s,'|visitor|',visitor_id)),%s,SHA2(CONCAT(visitor_id,%s),256),
			MAX(is_conversion),SUM(is_conversion),SUM(event_type='pageview'),SUM(event_type='click')
			FROM $events WHERE visitor_id<>'' AND created_at >= %s AND created_at < %s AND id <= %d GROUP BY visitor_id",
			$token,
			$date,
			$date,
			$salt,
			$start,
			$end,
			$source_max_id
		);

		$queries[] = $wpdb->prepare(
			"INSERT INTO $stage
			(owner_token,bucket_type,bucket_hash,stat_date,dimension_one,dimension_two,conversions,conversion_events,pageviews,clicks)
			SELECT %s,'session',MD5(CONCAT(%s,'|session|',session_id)),%s,SHA2(CONCAT(session_id,%s),256),SHA2(CONCAT(MIN(visitor_id),%s),256),
			MAX(is_conversion),SUM(is_conversion),SUM(event_type='pageview'),SUM(event_type='click')
			FROM $events WHERE session_id<>'' AND created_at >= %s AND created_at < %s AND id <= %d GROUP BY session_id",
			$token,
			$date,
			$date,
			$salt,
			$salt,
			$start,
			$end,
			$source_max_id
		);

		foreach ( $queries as $query ) {
			$renewed = self::renew_lease( $date, $token );
			if ( is_wp_error( $renewed ) ) {
				return $renewed;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $query ) ) {
				return new \WP_Error( 'convertrack_rollup_stage', $wpdb->last_error );
			}
		}
		$compacted = self::compact_cardinality( $date, $token );
		if ( is_wp_error( $compacted ) ) {
			return $compacted;
		}

		return $source;
	}

	/**
	 * Keep high-value detail buckets and fold the tail into explicit Other rows.
	 * Limits are filterable for large installations without creating unbounded
	 * settings or per-request option writes.
	 *
	 * @param string $date  Rollup date.
	 * @param string $token Owner token.
	 * @return true|\WP_Error
	 */
	private static function compact_cardinality( $date, $token ) {
		global $wpdb;
		$stage = Database::rollup_stage_table();
		$other = self::OTHER_BUCKET;
		$selector_limit = max( 1, min( 50000, (int) apply_filters( 'convertrack_selector_rollup_limit', 5000, $date ) ) );
		$campaign_limit = max( 1, min( 20000, (int) apply_filters( 'convertrack_campaign_rollup_limit', 2000, $date ) ) );
		$keyword_limit  = max( 1, min( 50000, (int) apply_filters( 'convertrack_keyword_rollup_limit', 5000, $date ) ) );

		$queries = array(
			$wpdb->prepare( "UPDATE $stage SET bucket_type='daily_keep' WHERE owner_token=%s AND bucket_type='daily' AND dimension_one<>'' ORDER BY clicks DESC,id ASC LIMIT %d", $token, $selector_limit ),
			$wpdb->prepare(
				"INSERT INTO $stage (owner_token,bucket_type,bucket_hash,stat_date,page_key,post_id,dimension_one,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
				 SELECT %s,'daily_other',MD5(CONCAT(%s,'|daily-overflow|',page_key)),%s,page_key,MAX(post_id),%s,MAX(element_text),SUM(clicks),SUM(conversions),SUM(conversion_events),SUM(pageviews),SUM(unique_visitors)
				 FROM $stage WHERE owner_token=%s AND bucket_type='daily' AND dimension_one<>'' GROUP BY page_key",
				$token, $date, $date, $other, $token
			),
			$wpdb->prepare( "DELETE FROM $stage WHERE owner_token=%s AND bucket_type='daily' AND dimension_one<>''", $token ),
			$wpdb->prepare( "UPDATE $stage SET bucket_type='daily' WHERE owner_token=%s AND bucket_type IN ('daily_keep','daily_other')", $token ),

			$wpdb->prepare( "UPDATE $stage SET bucket_type='source_keep' WHERE owner_token=%s AND bucket_type='source' AND dimension_two<>'' ORDER BY pageviews DESC,clicks DESC,id ASC LIMIT %d", $token, $campaign_limit ),
			$wpdb->prepare(
				"INSERT INTO $stage (owner_token,bucket_type,bucket_hash,stat_date,dimension_one,dimension_two,clicks,conversions,conversion_events,pageviews,unique_visitors)
				 SELECT %s,'source_other',MD5(CONCAT(%s,'|source-overflow|',dimension_one)),%s,dimension_one,%s,SUM(clicks),SUM(conversions),SUM(conversion_events),SUM(pageviews),SUM(unique_visitors)
				 FROM $stage WHERE owner_token=%s AND bucket_type='source' AND dimension_two<>'' GROUP BY dimension_one",
				$token, $date, $date, $other, $token
			),
			$wpdb->prepare( "DELETE FROM $stage WHERE owner_token=%s AND bucket_type='source' AND dimension_two<>''", $token ),
			$wpdb->prepare( "UPDATE $stage SET bucket_type='source' WHERE owner_token=%s AND bucket_type IN ('source_keep','source_other')", $token ),

			$wpdb->prepare( "UPDATE $stage SET bucket_type='search_keep' WHERE owner_token=%s AND bucket_type='search' ORDER BY pageviews DESC,clicks DESC,id ASC LIMIT %d", $token, $keyword_limit ),
			$wpdb->prepare(
				"INSERT INTO $stage (owner_token,bucket_type,bucket_hash,stat_date,page_key,post_id,dimension_one,dimension_two,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
				 SELECT %s,'search_other',MD5(CONCAT(%s,'|search-overflow|',page_key,'|',dimension_two,'|',element_text)),%s,page_key,MAX(post_id),%s,dimension_two,element_text,SUM(clicks),SUM(conversions),SUM(conversion_events),SUM(pageviews),SUM(unique_visitors)
				 FROM $stage WHERE owner_token=%s AND bucket_type='search' GROUP BY page_key,dimension_two,element_text",
				$token, $date, $date, $other, $token
			),
			$wpdb->prepare( "DELETE FROM $stage WHERE owner_token=%s AND bucket_type='search'", $token ),
			$wpdb->prepare( "UPDATE $stage SET bucket_type='search' WHERE owner_token=%s AND bucket_type IN ('search_keep','search_other')", $token ),
		);

		foreach ( $queries as $query ) {
			$renewed = self::renew_lease( $date, $token );
			if ( is_wp_error( $renewed ) ) {
				return $renewed;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $query ) ) {
				return new \WP_Error( 'convertrack_rollup_cardinality', $wpdb->last_error );
			}
		}
		return true;
	}

	/**
	 * Capture a consistent high-water boundary for all stage queries.
	 *
	 * @param string   $start  Inclusive datetime.
	 * @param string   $end    Exclusive datetime.
	 * @param int|null $max_id Optional existing high-water boundary.
	 * @return array|\WP_Error
	 */
	private static function source_snapshot( $start, $end, $max_id = null ) {
		global $wpdb;
		$events = Database::events_table();
		$wpdb->last_error = '';
		$where = 'created_at >= %s AND created_at < %s';
		$params = array( $start, $end );
		if ( null !== $max_id ) {
			$where   .= ' AND id <= %d';
			$params[] = max( 0, (int) $max_id );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) event_count,COALESCE(MAX(id),0) max_id FROM $events WHERE $where", $params ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'convertrack_rollup_source', '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'Could not read the rollup source watermark.' );
		}
		return array(
			'event_count' => isset( $row['event_count'] ) ? (int) $row['event_count'] : 0,
			'max_id'      => isset( $row['max_id'] ) ? (int) $row['max_id'] : 0,
		);
	}

	/**
	 * Replace final buckets atomically from staging.
	 *
	 * @param string $date   Date.
	 * @param string $token  Owner token.
	 * @param array  $source Source watermark.
	 * @return array|\WP_Error
	 */
	private static function replace_day( $date, $token, array $source ) {
		global $wpdb;
		$stage    = Database::rollup_stage_table();
		$daily    = Database::daily_table();
		$sources  = Database::sources_table();
		$geo      = Database::geo_table();
		$search   = Database::search_terms_table();
		$visitors = Database::visitor_days_table();
		$sessions = Database::session_days_table();
		$state    = Database::rollup_state_table();
		$renewed  = self::renew_lease( $date, $token );
		if ( is_wp_error( $renewed ) ) {
			return self::fail( $date, $token, $renewed->get_error_message() );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB
			return self::fail( $date, $token, 'Could not start rollup transaction.' );
		}

		$queries = array(
			$wpdb->prepare( "DELETE FROM $daily WHERE stat_date=%s", $date ),
			$wpdb->prepare( "DELETE FROM $sources WHERE stat_date=%s", $date ),
			$wpdb->prepare( "DELETE FROM $geo WHERE stat_date=%s", $date ),
			$wpdb->prepare( "DELETE FROM $search WHERE stat_date=%s", $date ),
			$wpdb->prepare( "DELETE FROM $visitors WHERE stat_date=%s", $date ),
			$wpdb->prepare( "DELETE FROM $sessions WHERE stat_date=%s", $date ),
			$wpdb->prepare( "INSERT INTO $daily (bucket_hash,stat_date,page_key,post_id,element_selector,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors)
				SELECT bucket_hash,stat_date,page_key,post_id,dimension_one,element_text,clicks,conversions,conversion_events,pageviews,unique_visitors FROM $stage WHERE owner_token=%s AND bucket_type='daily'", $token ),
			$wpdb->prepare( "INSERT INTO $sources (bucket_hash,stat_date,source,campaign,pageviews,clicks,conversions,conversion_events,unique_visitors)
				SELECT bucket_hash,stat_date,dimension_one,dimension_two,pageviews,clicks,conversions,conversion_events,unique_visitors FROM $stage WHERE owner_token=%s AND bucket_type='source'", $token ),
			$wpdb->prepare( "INSERT INTO $geo (bucket_hash,stat_date,country,pageviews,clicks,conversions,conversion_events,unique_visitors)
				SELECT bucket_hash,stat_date,dimension_one,pageviews,clicks,conversions,conversion_events,unique_visitors FROM $stage WHERE owner_token=%s AND bucket_type='geo'", $token ),
			$wpdb->prepare( "INSERT INTO $search (bucket_hash,stat_date,page_key,post_id,search_keyword,search_source,traffic_source,pageviews,clicks,conversions,conversion_events,unique_visitors)
				SELECT bucket_hash,stat_date,page_key,post_id,dimension_one,dimension_two,element_text,pageviews,clicks,conversions,conversion_events,unique_visitors FROM $stage WHERE owner_token=%s AND bucket_type='search'", $token ),
			$wpdb->prepare( "INSERT INTO $visitors (bucket_hash,stat_date,visitor_hash,converted,conversion_events,pageviews,clicks)
				SELECT bucket_hash,stat_date,dimension_one,conversions,conversion_events,pageviews,clicks FROM $stage WHERE owner_token=%s AND bucket_type='visitor'", $token ),
			$wpdb->prepare( "INSERT INTO $sessions (bucket_hash,stat_date,session_hash,visitor_hash,converted,conversion_events,pageviews,clicks)
				SELECT bucket_hash,stat_date,dimension_one,dimension_two,conversions,conversion_events,pageviews,clicks FROM $stage WHERE owner_token=%s AND bucket_type='session'", $token ),
		);

		foreach ( $queries as $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $query ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
				return self::fail( $date, $token, 'Rollup replacement failed: ' . $wpdb->last_error );
			}
		}

		$now = current_time( 'mysql' );
		// Owner-safe completion is part of the same transaction.
		$sql = "UPDATE $state SET status='complete',owner_token='',completed_at=%s,lease_expires_at=NULL,
			attempts=0,source_event_max_id=%d,source_event_count=%d,last_error='',updated_at=%s
			WHERE stat_date=%s AND owner_token=%s AND status='running' AND lease_expires_at >= %s";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $wpdb->prepare( $sql, $now, $source['max_id'], $source['event_count'], $now, $date, $token, $now ) );
		if ( 1 !== (int) $updated || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			return self::fail( $date, $token, 'Could not commit rollup or the lease was lost.' );
		}

		Database::invalidate_report_cache();
		$start   = $date . ' 00:00:00';
		$end     = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 day' ) );
		$current = self::source_snapshot( $start, $end );
		if ( is_wp_error( $current ) ) {
			return new \WP_Error( 'convertrack_rollup_reconcile', $current->get_error_message(), array( 'committed' => true, 'date' => $date ) );
		}
		$status = 'complete';
		if ( (int) $current['max_id'] !== (int) $source['max_id'] || (int) $current['event_count'] !== (int) $source['event_count'] ) {
			// A late insert raced the stage build. Keep the committed snapshot but
			// immediately queue the day for an exact replacement on the next pass.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $state SET status='pending',completed_at=NULL,last_error=%s,updated_at=%s
					 WHERE stat_date=%s AND status='complete'",
					'Late events arrived while this day was being rolled up.',
					current_time( 'mysql' ),
					$date
				)
			);
			if ( false === $pending ) {
				return new \WP_Error( 'convertrack_rollup_reconcile_write', $wpdb->last_error, array( 'committed' => true, 'date' => $date ) );
			}
			$status = 'pending';
		}
		return array(
			'status'      => $status,
			'date'        => $date,
			'event_count' => (int) $source['event_count'],
			'max_id'      => (int) $source['max_id'],
			'late_events_pending' => 'pending' === $status,
		);
	}

	/**
	 * Extend a live owner lease between bounded build phases.
	 *
	 * @param string $date  Rollup date.
	 * @param string $token Owner token.
	 * @return true|\WP_Error
	 */
	private static function renew_lease( $date, $token ) {
		global $wpdb;
		$table = Database::rollup_state_table();
		$now   = current_time( 'mysql' );
		$lease = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::LEASE_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET lease_expires_at=%s,updated_at=%s
				 WHERE stat_date=%s AND owner_token=%s AND status='running' AND lease_expires_at >= %s",
				$lease,
				$now,
				$date,
				$token,
				$now
			)
		);
		if ( false === $updated ) {
			return new \WP_Error( 'convertrack_rollup_lease_write', $wpdb->last_error );
		}
		if ( 1 === (int) $updated ) {
			return true;
		}
		// MySQL reports zero affected rows when the same owner renews more than
		// once within one second and both datetime values are unchanged.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$owned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE stat_date=%s AND owner_token=%s AND status='running' AND lease_expires_at >= %s",
				$date,
				$token,
				$now
			)
		);
		return 1 === $owned ? true : new \WP_Error( 'convertrack_rollup_lease_lost', 'The rollup owner lease expired or was replaced.' );
	}

	/**
	 * Delete bounded owner/stale staging rows and surface every write failure.
	 *
	 * @param string $date       Rollup date.
	 * @param string $token      Current owner.
	 * @param bool   $stale_only Delete prior owners for this date.
	 * @return int|\WP_Error
	 */
	private static function cleanup_stage( $date, $token, $stale_only ) {
		global $wpdb;
		$stage = Database::rollup_stage_table();
		$total = 0;
		for ( $pass = 0; $pass < 20; $pass++ ) {
			if ( $stale_only ) {
				// The current owner has the date lease, so other owner rows for this
				// same date are necessarily abandoned.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $stage WHERE stat_date=%s AND owner_token<>%s LIMIT 10000", $date, $token ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $stage WHERE owner_token=%s LIMIT 10000", $token ) );
			}
			if ( false === $deleted ) {
				return new \WP_Error( 'convertrack_rollup_stage_cleanup', 'Could not clean rollup staging rows: ' . $wpdb->last_error );
			}
			$total += (int) $deleted;
			if ( $deleted < 10000 ) {
				return $total;
			}
		}
		return new \WP_Error( 'convertrack_rollup_stage_backlog', 'Rollup staging cleanup reached its bounded work limit.' );
	}

	/**
	 * Record a terminal/repairable worker failure without releasing another owner.
	 *
	 * @param string $date    Date.
	 * @param string $token   Owner token.
	 * @param string $message Error.
	 * @return \WP_Error
	 */
	private static function fail( $date, $token, $message ) {
		global $wpdb;
		$table = Database::rollup_state_table();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recorded = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status=IF(attempts >= %d,'terminal','failed'),lease_expires_at=NULL,last_error=%s,updated_at=%s WHERE stat_date=%s AND owner_token=%s",
				self::MAX_ATTEMPTS,
				substr( (string) $message, 0, 1000 ),
				$now,
				$date,
				$token
			)
		);
		if ( false === $recorded ) {
			return new \WP_Error(
				'convertrack_rollup_failed',
				$message . ' Failure state could not be saved: ' . $wpdb->last_error,
				array( 'state_write_failed' => true )
			);
		}
		return new \WP_Error( 'convertrack_rollup_failed', $message );
	}
}
