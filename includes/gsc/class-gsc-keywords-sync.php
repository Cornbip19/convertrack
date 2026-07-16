<?php
/**
 * GSC Search Analytics sync engine.
 *
 * Pulls query/page performance rows from the Search Analytics API in
 * paginated steps driven by cron, storing per-range aggregates. State lives
 * in an option so the admin UI can poll live progress.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-owner-lock.php';

class Keywords_Sync {

	const STATE_OPTION      = 'convertrack_gsc_keywords_sync_state';
	const LAST_SYNC_OPTION  = 'convertrack_gsc_keywords_last_sync';
	const LOCK_OPTION       = 'convertrack_gsc_keywords_sync_lock';
	const LAST_ERROR_OPTION = 'convertrack_gsc_keywords_last_error';
	const LOCK_TIMEOUT      = 120;

	const REQUEST_ROW_LIMIT     = 5000;
	const MAX_REQUESTS_PER_RUN  = 5;
	const MAX_REQUESTS_PER_SYNC = 40;

	// Search Analytics data lags ~2-3 days; pinning the end date keeps ranges
	// stable so re-syncs are idempotent. GSC dates are America/Los_Angeles while
	// we compute site-local — at most one boundary day of drift, acceptable.
	const FRESHNESS_LAG_DAYS = 3;

	/**
	 * Queue a sync. Returns the fresh state or a WP_Error.
	 *
	 * @param array  $ranges       Preset range keys; empty = configured sync_ranges.
	 * @param string $custom_start Custom start date (Y-m-d) — overrides $ranges.
	 * @param string $custom_end   Custom end date (Y-m-d).
	 * @param string $trigger      'manual' or 'auto'.
	 * @return array|\WP_Error
	 */
	public static function request_sync( $ranges = array(), $custom_start = '', $custom_end = '', $trigger = 'manual' ) {
		if ( ! Keywords_Settings::ready() ) {
			return new \WP_Error(
				'convertrack_gsc_keywords_not_ready',
				__( 'Keyword Insights is not enabled or Google Search Console is not connected.', 'convertrack-click-conversion-analytics' ),
				array( 'status' => 409 )
			);
		}

		$state = self::raw_state();
		if ( in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return new \WP_Error(
				'convertrack_gsc_keywords_sync_in_progress',
				__( 'A keyword sync is already running.', 'convertrack-click-conversion-analytics' ),
				array( 'status' => 409 )
			);
		}

		$custom = null;
		if ( '' !== $custom_start || '' !== $custom_end ) {
			$custom = self::validate_custom_range( $custom_start, $custom_end );
			if ( is_wp_error( $custom ) ) {
				return $custom;
			}
			$pending = array( 'custom' );
		} else {
			$vocabulary = Keywords_Settings::ranges_vocabulary();
			$pending    = array();
			foreach ( (array) $ranges as $range ) {
				$range = sanitize_key( $range );
				if ( in_array( $range, $vocabulary, true ) && ! in_array( $range, $pending, true ) ) {
					$pending[] = $range;
				}
			}
			if ( empty( $pending ) ) {
				$pending = (array) Keywords_Settings::get( 'sync_ranges', array() );
			}
			if ( empty( $pending ) ) {
				$pending = array( (string) Keywords_Settings::get( 'default_range', '28d' ) );
			}
		}

		$state                   = self::default_state();
		$state['status']         = 'queued';
		$state['trigger']        = 'auto' === $trigger ? 'auto' : 'manual';
		$state['ranges_pending'] = $pending;
		$state['ranges_total']   = count( $pending );
		$state['custom']         = $custom;
		$state['sync_id']        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'kw-', true );
		update_option( self::STATE_OPTION, $state, false );
		Keywords_Database::cleanup_staging();

		Keywords_Cron::kick_sync( 0 );
		Logger::info( 'keywords-sync', 'Keyword sync queued.', array( 'ranges' => $pending, 'trigger' => $state['trigger'] ) );

		return self::state();
	}

	/**
	 * Cancel queued/running work without changing the last complete generation.
	 *
	 * @return array
	 */
	public static function cancel() {
		$state = self::raw_state();
		if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return self::state();
		}
		if ( ! empty( $state['sync_id'] ) ) {
			Keywords_Database::discard_generation( $state['sync_id'] );
		}
		$state['status']      = 'cancelled';
		$state['finished_at'] = current_time( 'mysql' );
		$state['current']     = null;
		update_option( self::STATE_OPTION, $state, false );
		Logger::info( 'keywords-sync', 'Keyword sync cancelled.' );
		return self::state();
	}

	/**
	 * Execute one budgeted sync step. Called only from cron.
	 *
	 * @return array State, with 'busy' set when another step holds the lock.
	 */
	public static function run_step() {
		if ( ! Keywords_Settings::ready() ) {
			$state = self::raw_state();
			if ( in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
				return self::fail( $state, __( 'Google Search Console disconnected while the keyword sync was running. Reconnect Google, then explicitly retry the sync.', 'convertrack-click-conversion-analytics' ), 'auth' );
			}
			return $state;
		}

		$owner = \Convertrack\Owner_Lock::acquire( self::LOCK_OPTION, self::LOCK_TIMEOUT );
		if ( false === $owner ) {
			$state         = self::raw_state();
			$state['busy'] = true;
			return $state;
		}

		try {
			return self::run_step_locked( $owner );
		} finally {
			\Convertrack\Owner_Lock::release( self::LOCK_OPTION, $owner );
		}
	}

	/**
	 * Current state plus derived progress and persisted last-sync/error info.
	 *
	 * @return array
	 */
	public static function state() {
		$state  = self::raw_state();
		$total  = max( 0, (int) $state['ranges_total'] );
		$done   = max( 0, $total - count( (array) $state['ranges_pending'] ) - ( empty( $state['current'] ) ? 0 : 1 ) );
		$cap    = max( 1, (int) Keywords_Settings::get( 'row_cap', 5000 ) );
		$in_run = empty( $state['current'] ) ? 0 : min( 1, $state['current']['stored'] / $cap );

		$state['running']  = in_array( $state['status'], array( 'queued', 'running' ), true ) && ( \Convertrack\Owner_Lock::is_live( self::LOCK_OPTION, self::LOCK_TIMEOUT ) || 'queued' === $state['status'] );
		$state['progress'] = array(
			'ranges_total' => $total,
			'ranges_done'  => $done,
			'current'      => empty( $state['current'] ) ? '' : (string) $state['current']['range_key'],
			'rows_stored'  => (int) $state['rows_stored'] + ( empty( $state['current'] ) ? 0 : (int) $state['current']['stored'] ),
			'percent'      => $total > 0 ? (int) round( 100 * ( $done + $in_run ) / $total ) : 0,
		);

		$last_sync           = get_option( self::LAST_SYNC_OPTION, array() );
		$state['last_sync']  = is_array( $last_sync ) ? $last_sync : array();
		$last_error          = get_option( self::LAST_ERROR_OPTION, array() );
		$state['last_error'] = is_array( $last_error ) && ! empty( $last_error['message'] ) ? $last_error : null;

		return $state;
	}

	/**
	 * Inclusive date bounds for a preset range.
	 *
	 * @param string $range_key Range key.
	 * @return array { start, end } as Y-m-d.
	 */
	public static function range_dates( $range_key ) {
		$spans = array(
			'7d'  => 6,
			'28d' => 27,
			'3m'  => 89,
			'6m'  => 181,
		);
		$days = isset( $spans[ $range_key ] ) ? $spans[ $range_key ] : 27;
		$end  = current_time( 'timestamp' ) - self::FRESHNESS_LAG_DAYS * DAY_IN_SECONDS;

		return array(
			'start' => date( 'Y-m-d', $end - $days * DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			'end'   => date( 'Y-m-d', $end ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		);
	}

	/**
	 * Sync body, called with the lock held.
	 *
	 * @return array
	 */
	private static function run_step_locked( $owner ) {
		$state = self::raw_state();
		if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return $state;
		}

		$state['status'] = 'running';
		if ( ! empty( $state['next_retry_at'] ) && (int) $state['next_retry_at'] > time() ) {
			return $state;
		}
		$state['next_retry_at'] = 0;
		if ( empty( $state['started_at'] ) ) {
			$state['started_at'] = current_time( 'mysql' );
		}

		$row_limit = max( 100, min( 25000, (int) apply_filters( 'convertrack_gsc_keywords_request_row_limit', self::REQUEST_ROW_LIMIT ) ) );
		$row_cap   = max( 100, (int) Keywords_Settings::get( 'row_cap', 5000 ) );

		for ( $i = 0; $i < self::MAX_REQUESTS_PER_RUN; $i++ ) {
			if ( ! \Convertrack\Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, self::LOCK_TIMEOUT ) ) {
				return self::fail( $state, __( 'The keyword sync worker lost ownership of its lease.', 'convertrack-click-conversion-analytics' ), 'lease' );
			}

			if ( empty( $state['current'] ) ) {
				if ( empty( $state['ranges_pending'] ) ) {
					return self::finish( $state );
				}

				$range_key = array_shift( $state['ranges_pending'] );
				if ( 'custom' === $range_key && ! empty( $state['custom'] ) ) {
					$dates = array( 'start' => $state['custom']['start'], 'end' => $state['custom']['end'] );
				} else {
					$dates = self::range_dates( $range_key );
				}

				$state['current'] = array(
					'range_key'       => $range_key,
					'start_date'      => $dates['start'],
					'end_date'        => $dates['end'],
					'start_row'       => 0,
					'stored'          => 0,
					'skipped'         => 0,
					'range_synced_at' => current_time( 'mysql' ),
					'truncated'       => false,
					'cap_hit'         => false,
				);
			}

			if ( (int) $state['requests_made'] >= self::MAX_REQUESTS_PER_SYNC ) {
				$state['current']['truncated'] = true;
				return self::partial( $state, __( 'The request budget ended before the current range completed. The prior complete generation was preserved.', 'convertrack-click-conversion-analytics' ), 'request_budget' );
			}

			$dimensions = array( 'query', 'page' );
			if ( Keywords_Settings::get( 'track_devices' ) ) {
				$dimensions[] = 'device';
			}

			$filters = array();
			$country = (string) Keywords_Settings::get( 'country_filter', '' );
			if ( '' !== $country ) {
				$filters[] = array(
					'filters' => array(
						array(
							'dimension'  => 'country',
							'operator'   => 'equals',
							'expression' => $country,
						),
					),
				);
			}

			$result = API::search_analytics_query(
				$state['current']['start_date'],
				$state['current']['end_date'],
				$dimensions,
				$row_limit,
				(int) $state['current']['start_row'],
				$filters
			);
			$state['requests_made'] = (int) $state['requests_made'] + 1;

			if ( is_wp_error( $result ) ) {
				return self::handle_error( $state, $result );
			}

			$state['retries']       = 0;

			$rows   = $result['rows'];
			$stored = self::store_rows( $rows, $state['current'], $country, $row_cap, $state['sync_id'] );
			if ( is_wp_error( $stored ) ) {
				return self::fail( $state, $stored->get_error_message(), 'database' );
			}

			$budget_spent = $state['requests_made'] >= self::MAX_REQUESTS_PER_SYNC;
			$cap_reached  = ! empty( $state['current']['cap_hit'] );
			$exhausted    = count( $rows ) < $row_limit;

			if ( $cap_reached ) {
				$state['current']['truncated'] = true;
				return self::partial( $state, __( 'The configured keyword row cap truncated this range. The prior complete generation was preserved.', 'convertrack-click-conversion-analytics' ), 'row_cap' );
			}

			if ( $exhausted ) {
				$finalized = self::finalize_range( $state['current'], $state['sync_id'] );
				if ( is_wp_error( $finalized ) ) {
					return self::fail( $state, $finalized->get_error_message(), 'database' );
				}
				$state['rows_stored'] = (int) $state['rows_stored'] + (int) $state['current']['stored'];
				$state['current'] = null;

				if ( $budget_spent && ! empty( $state['ranges_pending'] ) ) {
					// Out of request budget for this sync — drop remaining ranges.
					return self::partial( $state, __( 'The request budget ended before every configured range completed. Completed ranges were kept; unfinished ranges were not published.', 'convertrack-click-conversion-analytics' ), 'request_budget' );
				}
			} else {
				if ( $budget_spent ) {
					$state['current']['truncated'] = true;
					return self::partial( $state, __( 'The request budget ended before the current range completed. The prior complete generation was preserved.', 'convertrack-click-conversion-analytics' ), 'request_budget' );
				}
				$state['current']['start_row'] = (int) $state['current']['start_row'] + count( $rows );
			}

			// Persist every iteration so REST polling sees live progress.
			update_option( self::STATE_OPTION, $state, false );
		}

		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Filter and store one response page of rows.
	 *
	 * @param array  $rows    API rows (keys[0]=query, keys[1]=page[, keys[2]=device]).
	 * @param array  $current Current range state (by reference).
	 * @param string $country Active country filter ('' = worldwide).
	 * @param int    $row_cap Max stored rows for this range.
	 */
	private static function store_rows( array $rows, array &$current, $country, $row_cap, $sync_id ) {
		$min_impressions = (int) Keywords_Settings::get( 'min_impressions', 10 );
		$site_host       = self::normalize_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$range_key       = (string) $current['range_key'];
		$batch           = array();

		foreach ( $rows as $row ) {
			if ( $current['stored'] + count( $batch ) >= $row_cap ) {
				$current['cap_hit'] = true;
				break;
			}
			if ( empty( $row['keys'][0] ) || empty( $row['keys'][1] ) ) {
				continue;
			}

			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
			if ( $impressions < $min_impressions ) {
				$current['skipped']++;
				continue;
			}

			$page_url = Database::normalize_url( (string) $row['keys'][1] );
			if ( '' === $page_url ) {
				$current['skipped']++;
				continue;
			}

			// sc-domain properties report every subdomain; only keep pages this
			// WordPress install can actually analyze.
			$accept = self::normalize_host( (string) wp_parse_url( $page_url, PHP_URL_HOST ) ) === $site_host;
			if ( ! apply_filters( 'convertrack_gsc_keywords_accept_page', $accept, $page_url ) ) {
				$current['skipped']++;
				continue;
			}

			$query  = self::truncate( sanitize_text_field( (string) $row['keys'][0] ), 500 );
			$device = isset( $row['keys'][2] ) ? strtolower( sanitize_key( (string) $row['keys'][2] ) ) : '';
			$device = self::truncate( $device, 10 );

			$batch[] = array(
				'keyword_hash' => md5( strtolower( $query ) . '|' . strtolower( $page_url ) . '|' . $range_key . '|' . $country . '|' . $device ),
				'query'        => $query,
				'page_url'     => $page_url,
				'page_hash'    => md5( strtolower( $page_url ) ),
				'country'      => $country,
				'device'       => $device,
				'clicks'       => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions'  => $impressions,
				'ctr'          => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
				'position'     => isset( $row['position'] ) ? (float) $row['position'] : 0,
			);
		}

		if ( ! empty( $batch ) ) {
			$result = Keywords_Database::stage_keywords( $batch, $range_key, $sync_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$current['stored'] += (int) $result;
		}
		return count( $batch );
	}

	/**
	 * Wrap up a completed range: prune what the sync did not touch, rebuild the
	 * page rollup, resolve posts, and hand off to the analyzer.
	 *
	 * @param array $current Completed range state.
	 */
	private static function finalize_range( array $current, $sync_id ) {
		$range_key = (string) $current['range_key'];

		$published = Keywords_Database::finalize_generation( $sync_id, $range_key, $current['range_synced_at'] );
		if ( is_wp_error( $published ) ) {
			return $published;
		}
		$mapped = Keywords_Database::map_page_posts();
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$last_sync               = get_option( self::LAST_SYNC_OPTION, array() );
		$last_sync               = is_array( $last_sync ) ? $last_sync : array();
		$last_sync[ $range_key ] = array(
			'time'      => current_time( 'mysql' ),
			'rows'      => (int) $current['stored'],
			'truncated' => ! empty( $current['truncated'] ),
		);
		update_option( self::LAST_SYNC_OPTION, $last_sync, false );

		Logger::info( 'keywords-sync', 'Keyword range synced.', array( 'range' => $range_key, 'rows' => (int) $current['stored'], 'skipped' => (int) $current['skipped'], 'truncated' => ! empty( $current['truncated'] ) ) );
		do_action( 'convertrack_gsc_keywords_range_synced', $range_key, $current );
		Keywords_Cron::kick_analyze( MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Complete the sync run.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function finish( array $state ) {
		$state['status']      = 'completed';
		$state['finished_at'] = current_time( 'mysql' );
		$state['current']     = null;
		update_option( self::STATE_OPTION, $state, false );
		delete_option( self::LAST_ERROR_OPTION );
		Keywords_Database::clear_summary_cache();

		Logger::info( 'keywords-sync', 'Keyword sync completed.', array( 'rows' => (int) $state['rows_stored'], 'requests' => (int) $state['requests_made'] ) );
		do_action( 'convertrack_gsc_keywords_sync_completed', $state );

		return $state;
	}

	/**
	 * End a request-budget or row-cap truncated run without publishing its
	 * incomplete current generation.
	 *
	 * @param array  $state   State.
	 * @param string $message Message.
	 * @param string $reason  Partial reason.
	 * @return array
	 */
	private static function partial( array $state, $message, $reason ) {
		$partial_range = empty( $state['current'] ) ? '' : (string) $state['current']['range_key'];
		if ( ! empty( $state['sync_id'] ) ) {
			Keywords_Database::discard_generation( $state['sync_id'] );
		}
		$error = array( 'message' => $message, 'reason' => $reason, 'time' => current_time( 'mysql' ) );
		$state['status']        = 'partial';
		$state['finished_at']   = current_time( 'mysql' );
		$state['partial_range'] = $partial_range;
		$state['current']       = null;
		$state['error']         = $error;
		update_option( self::STATE_OPTION, $state, false );
		update_option( self::LAST_ERROR_OPTION, $error, false );
		Logger::warning( 'keywords-sync', 'Keyword sync ended with partial results.', array( 'reason' => $reason, 'range' => $partial_range ) );
		return $state;
	}

	/**
	 * Classify an API error and either retry or fail the sync.
	 *
	 * @param array     $state State.
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private static function handle_error( array $state, $error ) {
		$message = $error->get_error_message();

		if ( self::is_auth_error( $error ) ) {
			return self::fail( $state, $message, 'auth' );
		}
		if ( API::is_daily_quota_error( $error ) ) {
			return self::fail( $state, $message, 'quota' );
		}
		if ( API::is_rate_limit_error( $error ) ) {
			$delay                  = API::retry_after_seconds( $error, 5 * MINUTE_IN_SECONDS );
			$state['retries']       = (int) $state['retries'] + 1;
			$state['next_retry_at'] = time() + $delay;
			if ( $state['retries'] >= 3 ) {
				return self::fail( $state, $message, 'rate_limit' );
			}
			update_option( self::STATE_OPTION, $state, false );
			Logger::warning( 'keywords-sync', 'Keyword sync was temporarily rate limited.', array( 'retry_after' => $delay ) );
			return $state;
		}
		if ( API::is_quota_error( $error ) ) {
			return self::fail( $state, $message, 'quota' );
		}
		if ( API::is_api_disabled_error( $error ) ) {
			return self::fail( $state, API::api_disabled_hint(), 'permission' );
		}
		if ( API::is_permission_error( $error ) ) {
			return self::fail( $state, $message, 'permission' );
		}

		// Transient (network / 5xx): retry a few times before giving up.
		$state['retries'] = (int) $state['retries'] + 1;
		if ( $state['retries'] < 3 ) {
			update_option( self::STATE_OPTION, $state, false );
			Logger::warning( 'keywords-sync', 'Keyword sync request failed, will retry.', array( 'error' => $message, 'retries' => $state['retries'] ) );
			return $state;
		}

		return self::fail( $state, $message, 'transient' );
	}

	/**
	 * Fail the sync and persist the error for the UI.
	 *
	 * @param array  $state   State.
	 * @param string $message Message.
	 * @param string $reason  auth|quota|permission|transient.
	 * @return array
	 */
	private static function fail( array $state, $message, $reason ) {
		$error = array(
			'message' => $message,
			'reason'  => $reason,
			'time'    => current_time( 'mysql' ),
		);

		$state['status']      = 'failed';
		$state['finished_at'] = current_time( 'mysql' );
		$state['error']       = $error;
		$state['partial_range'] = empty( $state['current'] ) ? '' : (string) $state['current']['range_key'];
		$state['current']       = null;
		if ( 'quota' === $reason ) {
			$state['quota_reached'] = true;
		}
		if ( ! empty( $state['sync_id'] ) ) {
			Keywords_Database::discard_generation( $state['sync_id'] );
		}

		update_option( self::STATE_OPTION, $state, false );
		update_option( self::LAST_ERROR_OPTION, $error, false );
		Logger::error( 'keywords-sync', 'Keyword sync failed.', array( 'reason' => $reason, 'error' => $message ) );

		return $state;
	}

	/**
	 * Whether an error came from the OAuth layer, before any Google HTTP call.
	 *
	 * @param \WP_Error $error Error.
	 * @return bool
	 */
	private static function is_auth_error( $error ) {
		return in_array(
			$error->get_error_code(),
			array(
				'convertrack_gsc_no_client',
				'convertrack_gsc_not_connected',
				'convertrack_gsc_reconnect_required',
				'convertrack_gsc_refresh_failed',
				'convertrack_gsc_token_failed',
			),
			true
		);
	}

	/**
	 * Validate a custom date range.
	 *
	 * @param string $start Start date (Y-m-d).
	 * @param string $end   End date (Y-m-d).
	 * @return array|\WP_Error { start, end }
	 */
	private static function validate_custom_range( $start, $end ) {
		$start = sanitize_text_field( (string) $start );
		$end   = sanitize_text_field( (string) $end );

		$start_dt = \DateTime::createFromFormat( 'Y-m-d', $start );
		$end_dt   = \DateTime::createFromFormat( 'Y-m-d', $end );
		if ( ! $start_dt || $start_dt->format( 'Y-m-d' ) !== $start || ! $end_dt || $end_dt->format( 'Y-m-d' ) !== $end ) {
			return new \WP_Error( 'convertrack_gsc_keywords_bad_dates', __( 'Custom sync dates must use the YYYY-MM-DD format.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$latest = date( 'Y-m-d', current_time( 'timestamp' ) - self::FRESHNESS_LAG_DAYS * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		if ( $end > $latest ) {
			$end = $latest;
		}
		if ( $start > $end ) {
			return new \WP_Error( 'convertrack_gsc_keywords_bad_dates', __( 'The custom start date must be before the end date (Search Console data lags a few days).', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		// Search Analytics keeps ~16 months of data.
		$span = $end_dt->getTimestamp() - $start_dt->getTimestamp();
		if ( $span > 488 * DAY_IN_SECONDS ) {
			return new \WP_Error( 'convertrack_gsc_keywords_bad_dates', __( 'Custom ranges cannot exceed 16 months — Search Console keeps no older data.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		return array( 'start' => $start, 'end' => $end );
	}

	/**
	 * Stored state merged over defaults.
	 *
	 * @return array
	 */
	private static function raw_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return wp_parse_args( $state, self::default_state() );
	}

	/**
	 * Pristine state shape.
	 *
	 * @return array
	 */
	private static function default_state() {
		return array(
			'status'         => 'idle',
			'trigger'        => '',
			'sync_id'        => '',
			'ranges_pending' => array(),
			'ranges_total'   => 0,
			'custom'         => null,
			'current'        => null,
			'requests_made'  => 0,
			'rows_stored'    => 0,
			'retries'        => 0,
			'next_retry_at'  => 0,
			'quota_reached'  => false,
			'partial_range'  => '',
			'started_at'     => null,
			'finished_at'    => null,
			'error'          => null,
		);
	}

	/**
	 * Lowercased host with any www. prefix removed.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( (string) $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
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
