<?php
/**
 * Public collector authentication, privacy checks and abuse protection.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the public analytics endpoints cache-compatible while applying
 * site-bound tokens, origin checks and atomic request/event/byte budgets.
 */
class Ingestion_Guard {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'convertrack_ingestion_db_version';
	const LEGACY_UNTIL_OPTION = 'convertrack_legacy_collector_until';
	const TOKEN_PERIOD      = 21600; // Six hours; several periods are accepted for full-page caches.
	const TOKEN_PERIODS     = 8;     // Current period plus 42 hours of cache compatibility.
	const COLLECT_MAX_BYTES = 65536;
	const HEARTBEAT_MAX_BYTES = 4096;

	/**
	 * Atomic token-bucket table.
	 *
	 * @return string
	 */
	public static function limiter_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_ingestion_limits';
	}

	/**
	 * Bounded daily health metrics table.
	 *
	 * @return string
	 */
	public static function metrics_table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_ingestion_metrics';
	}

	/**
	 * Return dbDelta-compatible schema statements for the core migrator.
	 *
	 * @return array
	 */
	public static function schema_sql() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$limits  = self::limiter_table();
		$metrics = self::metrics_table();

		return array(
			"CREATE TABLE $limits (
				bucket_key char(64) NOT NULL DEFAULT '',
				request_tokens decimal(18,4) NOT NULL DEFAULT 0,
				event_tokens decimal(18,4) NOT NULL DEFAULT 0,
				byte_tokens decimal(18,4) NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				allowed tinyint(1) unsigned NOT NULL DEFAULT 1,
				PRIMARY KEY  (bucket_key),
				KEY expires_at (expires_at)
			) $collate;",
			"CREATE TABLE $metrics (
				stat_date date NOT NULL,
				channel varchar(20) NOT NULL DEFAULT '',
				accepted bigint(20) unsigned NOT NULL DEFAULT 0,
				rejected bigint(20) unsigned NOT NULL DEFAULT 0,
				rate_limited bigint(20) unsigned NOT NULL DEFAULT 0,
				failed bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (stat_date, channel),
				KEY stat_date (stat_date)
			) $collate;",
		);
	}

	/**
	 * Install and verify the isolated ingestion schema.
	 *
	 * This must be called by activation/upgrade code, never from a public REST
	 * request. It is deliberately isolated so the core migration owner can add
	 * it to the existing versioned migration flow.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema_sql() as $sql ) {
			dbDelta( $sql );
			if ( ! empty( $wpdb->last_error ) ) {
				return new \WP_Error( 'convertrack_ingestion_schema_error', $wpdb->last_error );
			}
		}

		$verified = self::verify_schema();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		if ( false === get_option( self::LEGACY_UNTIL_OPTION, false ) ) {
			if ( ! add_option( self::LEGACY_UNTIL_OPTION, time() + ( 90 * DAY_IN_SECONDS ), '', false ) ) {
				return new \WP_Error( 'convertrack_legacy_window_write', __( 'Convertrack could not save the collector compatibility window.', 'convertrack-click-conversion-analytics' ) );
			}
		}
		if ( ! update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false ) && get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			return new \WP_Error( 'convertrack_ingestion_version_write', __( 'Convertrack could not save the ingestion schema version.', 'convertrack-click-conversion-analytics' ) );
		}
		return true;
	}

	/**
	 * Upgrade only from an explicit activation/admin/CLI migration context.
	 * The caller owns locking/backoff; this method never runs from ingestion.
	 *
	 * @return true|\WP_Error
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION || false === get_option( self::LEGACY_UNTIL_OPTION, false ) ) {
			return self::install();
		}
		return self::verify_schema();
	}

	/**
	 * Cheap public-request health gate. The version is written only after full
	 * structural verification; runtime bucket writes still fail closed if a
	 * table is removed after migration.
	 *
	 * @return bool
	 */
	public static function schema_is_healthy() {
		return get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION;
	}

	/**
	 * Verify required columns and indexes before advancing the schema version.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schema() {
		global $wpdb;
		$required = array(
			self::limiter_table() => array(
				'columns' => array( 'bucket_key', 'request_tokens', 'event_tokens', 'byte_tokens', 'updated_at', 'expires_at', 'allowed' ),
				'indexes' => array( 'PRIMARY', 'expires_at' ),
			),
			self::metrics_table() => array(
				'columns' => array( 'stat_date', 'channel', 'accepted', 'rejected', 'rate_limited', 'failed' ),
				'indexes' => array( 'PRIMARY', 'stat_date' ),
			),
		);

		foreach ( $required as $table => $shape ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return new \WP_Error( 'convertrack_ingestion_schema_missing', __( 'Convertrack ingestion protection tables are unavailable.', 'convertrack-click-conversion-analytics' ) );
			}
			// Table names are derived solely from the trusted WordPress prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = (array) $wpdb->get_col( "SHOW INDEX FROM `$table`", 2 );
			$missing_columns = array_diff( $shape['columns'], $columns );
			$missing_indexes = array_diff( $shape['indexes'], array_unique( $indexes ) );
			if ( ! empty( $wpdb->last_error ) || $missing_columns || $missing_indexes ) {
				return new \WP_Error(
					'convertrack_ingestion_schema_incomplete',
					__( 'Convertrack ingestion protection schema is incomplete.', 'convertrack-click-conversion-analytics' ),
					array(
						'missing_columns' => array_values( $missing_columns ),
						'missing_indexes' => array_values( $missing_indexes ),
						'database_error'  => $wpdb->last_error,
					)
				);
			}
		}
		return true;
	}

	/**
	 * Maximum accepted body size for an ingest channel.
	 *
	 * @param string $channel collect|heartbeat.
	 * @return int
	 */
	public static function max_body_bytes( $channel ) {
		$default = 'heartbeat' === $channel ? self::HEARTBEAT_MAX_BYTES : self::COLLECT_MAX_BYTES;
		return max( 1024, (int) apply_filters( 'convertrack_ingestion_max_body_bytes', $default, $channel ) );
	}

	/**
	 * Issue a short-lived stateless token tied to this WordPress site.
	 *
	 * @return string
	 */
	public static function issue_token() {
		$bucket = (int) floor( time() / self::TOKEN_PERIOD );
		return $bucket . '.' . self::token_signature( $bucket );
	}

	/**
	 * Sign the server-resolved identity embedded in one rendered page.
	 *
	 * The event URL/path is part of the signature, so copying taxonomy/archive
	 * object IDs into a request for another path cannot relabel stored data.
	 *
	 * @param array $identity Page_Identity::current() result.
	 * @return string
	 */
	public static function issue_page_identity_token( array $identity ) {
		$bucket = (int) floor( time() / self::TOKEN_PERIOD );
		return $bucket . '.' . self::page_identity_signature( $bucket, $identity );
	}

	/**
	 * Validate a client-returned server identity within the page-cache window.
	 *
	 * @param string $token    Signed identity token.
	 * @param array  $identity Claimed canonical identity and submitted path.
	 * @return bool
	 */
	public static function validate_page_identity_token( $token, array $identity ) {
		if ( ! preg_match( '/^(\d{5,12})\.([A-Za-z0-9_-]{43})$/', (string) $token, $match ) ) {
			return false;
		}
		$bucket  = (int) $match[1];
		$current = (int) floor( time() / self::TOKEN_PERIOD );
		if ( $bucket > $current || $bucket < ( $current - ( self::TOKEN_PERIODS - 1 ) ) ) {
			return false;
		}
		return hash_equals( self::page_identity_signature( $bucket, $identity ), $match[2] );
	}

	/**
	 * Validate privacy signals, request origin, token and atomic quotas.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $channel collect|heartbeat.
	 * @param array            $payload Decoded JSON payload.
	 * @param int              $body_bytes Raw request body size.
	 * @return array|\WP_Error Admission context.
	 */
	public static function admit( $request, $channel, array $payload, $body_bytes ) {
		$channel = 'heartbeat' === $channel ? 'heartbeat' : 'collect';
		if ( ! self::schema_is_healthy() ) {
			return new \WP_Error( 'convertrack_ingestion_unavailable', 'Tracking protection is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( self::privacy_opted_out() ) {
			self::record_metric( $channel, 'rejected', 1 );
			return new \WP_Error( 'convertrack_privacy_opt_out', 'Tracking is disabled for this visitor.', array( 'status' => 403 ) );
		}

		$origin = self::origin_allowed();
		if ( is_wp_error( $origin ) ) {
			self::record_metric( $channel, 'rejected', 1 );
			return $origin;
		}

		$token  = isset( $payload['_ct'] ) && is_string( $payload['_ct'] ) ? trim( $payload['_ct'] ) : '';
		$legacy = '' === $token;
		if ( ! $legacy && ! self::validate_token( $token ) ) {
			self::record_metric( $channel, 'rejected', 1 );
			return new \WP_Error( 'convertrack_bad_collector_token', 'Invalid collector token.', array( 'status' => 403 ) );
		}

		/**
		 * Cached pre-hardening trackers omit the token. Keep them operational under
		 * stricter quotas until site owners disable this documented compatibility
		 * filter. Invalid supplied tokens are never treated as legacy requests.
		 */
		$legacy_until = (int) get_option( self::LEGACY_UNTIL_OPTION, 0 );
		$legacy_open  = 0 === $legacy_until || time() <= $legacy_until;
		if ( $legacy && ! apply_filters( 'convertrack_allow_legacy_collector', $legacy_open, $channel, $request ) ) {
			self::record_metric( $channel, 'rejected', 1 );
			return new \WP_Error( 'convertrack_legacy_collector_expired', 'This collector client is no longer supported.', array( 'status' => 403 ) );
		}

		$visitor = Collector::clean_uuid( isset( $payload['vid'] ) ? $payload['vid'] : '' );
		$events  = 'collect' === $channel && isset( $payload['events'] ) && is_array( $payload['events'] ) ? count( $payload['events'] ) : 0;
		$events  = min( Collector::MAX_EVENTS_PER_BATCH, max( 0, $events ) );
		$quota   = self::consume_quotas( $channel, $visitor, (int) $body_bytes, $events, $legacy );
		if ( is_wp_error( $quota ) ) {
			$metric = 'convertrack_rate_limited' === $quota->get_error_code() ? 'rate_limited' : 'failed';
			self::record_metric( $channel, $metric, max( 1, $events ) );
			return $quota;
		}

		return array(
			'legacy'          => $legacy,
			'heatmap_allowed' => empty( $quota['circuit'] ),
			'circuit'         => isset( $quota['circuit'] ) ? $quota['circuit'] : '',
		);
	}

	/**
	 * Record accepted/rejected/rate-limited/failed event totals atomically.
	 *
	 * @param string $channel Channel.
	 * @param string $metric  Metric column.
	 * @param int    $count   Increment.
	 * @return bool
	 */
	public static function record_metric( $channel, $metric, $count = 1 ) {
		global $wpdb;
		$allowed = array( 'accepted', 'rejected', 'rate_limited', 'failed' );
		if ( ! in_array( $metric, $allowed, true ) || $count <= 0 || ! self::table_exists( self::metrics_table() ) ) {
			return false;
		}
		$channel = 'heartbeat' === $channel ? 'heartbeat' : 'collect';
		$table   = self::metrics_table();
		$date    = current_time( 'Y-m-d' );
		$sql     = "INSERT INTO $table (stat_date, channel, $metric) VALUES (%s, %s, %d)
			ON DUPLICATE KEY UPDATE $metric = $metric + VALUES($metric)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $date, $channel, (int) $count ) );
		if ( false === $result ) {
			self::report_db_failure( 'record_metric' );
		}
		return false !== $result;
	}

	/**
	 * Return bounded health totals for Site Health/admin diagnostics.
	 *
	 * @param int $days Lookback days.
	 * @return array
	 */
	public static function health_metrics( $days = 7 ) {
		global $wpdb;
		$days = max( 1, min( 90, (int) $days ) );
		$out  = array(
			'accepted'     => 0,
			'rejected'     => 0,
			'rate_limited' => 0,
			'failed'       => 0,
			'schema'       => is_wp_error( self::verify_schema() ) ? 'unhealthy' : 'healthy',
		);
		if ( 'healthy' !== $out['schema'] ) {
			return $out;
		}
		$table = self::metrics_table();
		$from  = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(accepted),0) accepted,COALESCE(SUM(rejected),0) rejected,COALESCE(SUM(rate_limited),0) rate_limited,COALESCE(SUM(failed),0) failed FROM $table WHERE stat_date >= %s",
				$from
			),
			ARRAY_A
		);
		foreach ( array( 'accepted', 'rejected', 'rate_limited', 'failed' ) as $metric ) {
			$out[ $metric ] = isset( $row[ $metric ] ) ? (int) $row[ $metric ] : 0;
		}
		return $out;
	}

	/**
	 * Whether the current visitor sent GPC or denied statistics consent.
	 *
	 * @return bool
	 */
	public static function privacy_opted_out() {
		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';
		if ( '1' === $gpc ) {
			return true;
		}
		if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( 'statistics' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check a stateless site token against the current cache window.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function validate_token( $token ) {
		if ( ! preg_match( '/^(\d{5,12})\.([A-Za-z0-9_-]{43})$/', $token, $match ) ) {
			return false;
		}
		$bucket  = (int) $match[1];
		$current = (int) floor( time() / self::TOKEN_PERIOD );
		if ( $bucket > $current || $bucket < ( $current - ( self::TOKEN_PERIODS - 1 ) ) ) {
			return false;
		}
		return hash_equals( self::token_signature( $bucket ), $match[2] );
	}

	/**
	 * Token HMAC.
	 *
	 * @param int $bucket Time bucket.
	 * @return string
	 */
	private static function token_signature( $bucket ) {
		$site = strtolower( untrailingslashit( home_url( '/' ) ) );
		$mac  = hash_hmac( 'sha256', 'convertrack|' . $site . '|' . (int) $bucket, wp_salt( 'auth' ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
	}

	/**
	 * HMAC for a server-owned page identity.
	 *
	 * @param int   $bucket   Time bucket.
	 * @param array $identity Canonical identity.
	 * @return string
	 */
	private static function page_identity_signature( $bucket, array $identity ) {
		$site = strtolower( untrailingslashit( home_url( '/' ) ) );
		$data = array(
			'convertrack-page',
			$site,
			(int) $bucket,
			substr( sanitize_text_field( isset( $identity['page_key'] ) ? (string) $identity['page_key'] : '' ), 0, 191 ),
			substr( sanitize_key( isset( $identity['object_type'] ) ? (string) $identity['object_type'] : '' ), 0, 40 ),
			absint( isset( $identity['object_id'] ) ? $identity['object_id'] : 0 ),
			absint( isset( $identity['post_id'] ) ? $identity['post_id'] : 0 ),
			Page_Identity::normalize_path( isset( $identity['path'] ) ? $identity['path'] : '/' ),
		);
		$mac = hash_hmac( 'sha256', implode( "\n", $data ), wp_salt( 'auth' ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
	}

	/**
	 * Require matching Origin/Referer when a browser supplies either header.
	 *
	 * @return true|\WP_Error
	 */
	private static function origin_allowed() {
		$candidate = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$candidate = (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$candidate = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
		}
		if ( '' === $candidate ) {
			return true;
		}

		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $candidate );
		if ( ! is_array( $actual ) || empty( $actual['host'] ) || empty( $expected['host'] ) ) {
			return new \WP_Error( 'convertrack_bad_origin', 'Collector origin is not allowed.', array( 'status' => 403 ) );
		}
		$expected_scheme = isset( $expected['scheme'] ) ? strtolower( $expected['scheme'] ) : 'https';
		$actual_scheme   = isset( $actual['scheme'] ) ? strtolower( $actual['scheme'] ) : '';
		$expected_port   = isset( $expected['port'] ) ? (int) $expected['port'] : ( 'https' === $expected_scheme ? 443 : 80 );
		$actual_port     = isset( $actual['port'] ) ? (int) $actual['port'] : ( 'https' === $actual_scheme ? 443 : 80 );
		if ( strtolower( $actual['host'] ) !== strtolower( $expected['host'] ) || $actual_scheme !== $expected_scheme || $actual_port !== $expected_port ) {
			return new \WP_Error( 'convertrack_bad_origin', 'Collector origin is not allowed.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Consume per-IP, per-visitor and site-wide token buckets.
	 *
	 * @param string $channel Channel.
	 * @param string $visitor Visitor UUID.
	 * @param int    $bytes Request bytes.
	 * @param int    $events Event count.
	 * @param bool   $legacy Whether token was omitted.
	 * @return array|\WP_Error
	 */
	private static function consume_quotas( $channel, $visitor, $bytes, $events, $legacy ) {
		$quotas = self::quotas( $channel, $legacy );
		$circuit = '';
		$scopes = array(
			'ip'      => self::client_ip_hash(),
			'visitor' => $visitor ? substr( hash_hmac( 'sha256', $visitor, wp_salt( 'nonce' ) ), 0, 24 ) : 'invalid',
			'site'    => substr( hash( 'sha256', strtolower( home_url( '/' ) ) ), 0, 24 ),
		);

		foreach ( $scopes as $scope => $identity ) {
			$key = hash( 'sha256', $channel . '|' . $scope . '|' . $identity );
			$consumed = self::consume_bucket( $key, $quotas[ $scope ], 1, $events, $bytes );
			if ( null === $consumed ) {
				return new \WP_Error( 'convertrack_ingestion_unavailable', 'Tracking protection is temporarily unavailable.', array( 'status' => 503 ) );
			}
			if ( ! $consumed ) {
				return new \WP_Error(
					'convertrack_rate_limited',
					'Too many tracking requests.',
					array( 'status' => 429, 'retry_after' => 60 )
				);
			}
			if ( 'collect' === $channel && 'site' === $scope && self::bucket_is_high_water( $key, $quotas[ $scope ] ) ) {
				$circuit = 'heatmap';
			}
		}

		$circuit = apply_filters( 'convertrack_ingestion_circuit_mode', $circuit, $channel );
		return array( 'circuit' => in_array( $circuit, array( 'heatmap', 'optional' ), true ) ? $circuit : '' );
	}

	/**
	 * Approximate high-water signal after the atomic site-bucket debit. It only
	 * sheds heatmap/scroll events; core pageviews and clicks remain eligible.
	 *
	 * @param string $key Bucket key.
	 * @param array  $caps Capacities.
	 * @return bool
	 */
	private static function bucket_is_high_water( $key, array $caps ) {
		global $wpdb;
		$table = self::limiter_table();
		if ( ! self::table_exists( $table ) || empty( $caps[1] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT event_tokens, byte_tokens FROM $table WHERE bucket_key = %s", $key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return false;
		}
		return (float) $row['event_tokens'] < ( (float) $caps[1] * 0.20 ) || (float) $row['byte_tokens'] < ( (float) $caps[2] * 0.20 );
	}

	/**
	 * Quota capacities refilled over one minute.
	 *
	 * @param string $channel Channel.
	 * @param bool   $legacy Legacy tokenless client.
	 * @return array
	 */
	private static function quotas( $channel, $legacy ) {
		$legacy_factor = $legacy ? 0.5 : 1;
		if ( 'heartbeat' === $channel ) {
			$defaults = array(
				'ip'      => array( 240, 0, 512000 ),
				'visitor' => array( 12, 0, 49152 ),
				'site'    => array( 6000, 0, 12000000 ),
			);
		} else {
			$defaults = array(
				'ip'      => array( min( 120, max( 10, (int) Settings::get( 'rate_limit_per_min' ) ) ), 1200, 1500000 ),
				'visitor' => array( 40, 500, 600000 ),
				// A sustained site-wide ceiling of 3,000 accepted events/minute
				// (180,000/hour) stays below the hourly cleanup drain of 200,000
				// rows. The byte ceiling independently bounds write amplification.
				'site'    => array( 2400, 3000, 8000000 ),
			);
		}

		$defaults = apply_filters( 'convertrack_ingestion_quotas', $defaults, $channel, $legacy );
		foreach ( array( 'ip', 'visitor', 'site' ) as $scope ) {
			if ( ! isset( $defaults[ $scope ] ) || ! is_array( $defaults[ $scope ] ) ) {
				$defaults[ $scope ] = array( 1, 1, 1024 );
			}
			$defaults[ $scope ] = array(
				max( 1, (int) floor( (int) $defaults[ $scope ][0] * $legacy_factor ) ),
				max( 0, (int) floor( (int) $defaults[ $scope ][1] * $legacy_factor ) ),
				max( 1024, (int) floor( (int) $defaults[ $scope ][2] * $legacy_factor ) ),
			);
		}
		return $defaults;
	}

	/**
	 * Consume one atomic SQL token bucket. Falls back to atomic external-cache
	 * counters only while the migration is unavailable; it never creates
	 * per-request options or transients.
	 *
	 * @param string $key Bucket key.
	 * @param array  $caps Request/event/byte capacities per minute.
	 * @param int    $request_cost Request tokens.
	 * @param int    $event_cost Event tokens.
	 * @param int    $byte_cost Byte tokens.
	 * @return bool|null Null when neither the schema nor external cache exists.
	 */
	private static function consume_bucket( $key, array $caps, $request_cost, $event_cost, $byte_cost ) {
		global $wpdb;
		$table = self::limiter_table();
		if ( ! self::table_exists( $table ) ) {
			return wp_using_ext_object_cache() ? self::cache_fallback( $key, $caps, $request_cost, $event_cost, $byte_cost ) : null;
		}

		$now     = time();
		$expires = gmdate( 'Y-m-d H:i:s', $now + 3600 );
		$req_cap = max( 1, (int) $caps[0] );
		$evt_cap = max( 0, (int) $caps[1] );
		$byt_cap = max( 1024, (int) $caps[2] );
		$req_cost = max( 0, (int) $request_cost );
		$evt_cost = max( 0, (int) $event_cost );
		$byt_cost = max( 0, (int) $byte_cost );

		$req_available = "LEAST($req_cap, request_tokens + (GREATEST(0, $now - updated_at) * " . ( $req_cap / 60 ) . '))';
		$evt_available = "LEAST($evt_cap, event_tokens + (GREATEST(0, $now - updated_at) * " . ( $evt_cap / 60 ) . '))';
		$byt_available = "LEAST($byt_cap, byte_tokens + (GREATEST(0, $now - updated_at) * " . ( $byt_cap / 60 ) . '))';
		$condition     = "$req_available >= $req_cost AND $evt_available >= $evt_cost AND $byt_available >= $byt_cost";

		$sql = "INSERT INTO $table
			(bucket_key, request_tokens, event_tokens, byte_tokens, updated_at, expires_at, allowed)
			VALUES (%s, %f, %f, %f, %d, %s, 1)
			ON DUPLICATE KEY UPDATE
				allowed = LAST_INSERT_ID(IF($condition, 1, 0)),
				request_tokens = IF(allowed = 1, $req_available - $req_cost, request_tokens),
				event_tokens = IF(allowed = 1, $evt_available - $evt_cost, event_tokens),
				byte_tokens = IF(allowed = 1, $byt_available - $byt_cost, byte_tokens),
				updated_at = IF(allowed = 1, $now, updated_at),
				expires_at = IF(allowed = 1, VALUES(expires_at), expires_at)";

		// A first request cannot exceed capacity; reject it before inserting.
		if ( $req_cost > $req_cap || $evt_cost > $evt_cap || $byt_cost > $byt_cap ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$key,
				$req_cap - $req_cost,
				$evt_cap - $evt_cost,
				$byt_cap - $byt_cost,
				$now,
				$expires
			)
		);
		if ( false === $result ) {
			return null;
		}
		$accepted = 1 === (int) $result || 1 === (int) $wpdb->insert_id;

		if ( 0 === wp_rand( 0, 99 ) ) {
			// Fixed-size opportunistic maintenance; no full scan or table optimize.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$cleaned = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires_at < %s ORDER BY expires_at ASC LIMIT 100", gmdate( 'Y-m-d H:i:s' ) ) );
			if ( false === $cleaned ) {
				self::report_db_failure( 'limiter_cleanup' );
				return null;
			}
			$metrics = self::metrics_table();
			if ( self::table_exists( $metrics ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$metrics_cleaned = $wpdb->query( $wpdb->prepare( "DELETE FROM $metrics WHERE stat_date < %s ORDER BY stat_date ASC LIMIT 30", gmdate( 'Y-m-d', time() - ( 400 * DAY_IN_SECONDS ) ) ) );
				if ( false === $metrics_cleaned ) {
					self::report_db_failure( 'metrics_cleanup' );
					return null;
				}
			}
		}
		return $accepted;
	}

	/**
	 * Atomic persistent-object-cache fallback used only before schema recovery.
	 *
	 * @param string $key Bucket key.
	 * @param array  $caps Capacities.
	 * @param int    $requests Request cost.
	 * @param int    $events Event cost.
	 * @param int    $bytes Byte cost.
	 * @return bool
	 */
	private static function cache_fallback( $key, array $caps, $requests, $events, $bytes ) {
		$costs = array( (int) $requests, (int) $events, (int) $bytes );
		for ( $i = 0; $i < 3; $i++ ) {
			$cache_key = $key . ':' . $i . ':' . (int) floor( time() / 60 );
			wp_cache_add( $cache_key, 0, 'convertrack_ingestion', 70 );
			$value = wp_cache_incr( $cache_key, $costs[ $i ], 'convertrack_ingestion' );
			if ( false === $value || (int) $value > (int) $caps[ $i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hashed client IP. Forwarded headers are ignored unless REMOTE_ADDR is in
	 * an explicitly configured trusted-proxy CIDR.
	 *
	 * @return string
	 */
	private static function client_ip_hash() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
		$cidrs  = (array) apply_filters( 'convertrack_trusted_proxy_cidrs', array() );
		$trusted = false;
		foreach ( $cidrs as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				$trusted = true;
				break;
			}
		}
		if ( $trusted && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			foreach ( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
	}

	/**
	 * CIDR membership for IPv4 and IPv6.
	 *
	 * @param string $ip IP.
	 * @param string $cidr CIDR or exact IP.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		$cidr = trim( (string) $cidr );
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		list( $network, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$ip_bin  = @inet_pton( $ip );
		$net_bin = @inet_pton( $network );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > ( strlen( $ip_bin ) * 8 ) ) {
			return false;
		}
		$bytes = (int) floor( $bits / 8 );
		$rest  = $bits % 8;
		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Cheap per-request existence cache.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		static $known = array();
		if ( isset( $known[ $table ] ) ) {
			return $known[ $table ];
		}
		global $wpdb;
		$known[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $known[ $table ];
	}

	/**
	 * Surface non-primary ingestion write failures without exposing SQL to the
	 * public response.
	 *
	 * @param string $context Operation context.
	 */
	private static function report_db_failure( $context ) {
		global $wpdb;
		do_action( 'convertrack_ingestion_db_error', $context, $wpdb->last_error );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Convertrack ingestion DB failure (' . $context . '): ' . $wpdb->last_error );
		}
	}
}
