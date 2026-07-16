<?php
/**
 * Google URL Inspection background processor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-owner-lock.php';

class Processor {

	const QUOTA_OPTION      = 'convertrack_gsc_quota_usage';
	const LOCK_OPTION       = 'convertrack_gsc_processing_lock';
	const LAST_ERROR_OPTION = 'convertrack_gsc_last_batch_error';
	const LOCK_TIMEOUT      = 120;
	const PERMISSION_ABORT_THRESHOLD = 3;
	const SITEMAP_SUBMISSIONS_OPTION = 'convertrack_gsc_sitemap_submissions';

	/**
	 * Process a batch of URLs.
	 *
	 * @param int $limit Optional cap on URLs inspected this call (0 = full batch size).
	 * @return array|\WP_Error
	 */
	public static function process_batch( $limit = 0 ) {
		if ( ! Settings::ready() ) {
			Logger::warning( 'processor', 'Batch skipped because Google Index Monitor is not connected or enabled.' );
			return new \WP_Error( 'convertrack_gsc_not_ready', __( 'Google Index Monitor is not connected or enabled.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		$owner = \Convertrack\Owner_Lock::acquire( self::LOCK_OPTION, self::LOCK_TIMEOUT );
		if ( false === $owner ) {
			return array(
				'processed'     => 0,
				'errors'        => 0,
				'busy'          => true,
				'remaining'     => Database::due_count(),
				'quota_reached' => false,
				'aborted'       => false,
				'abort_reason'  => '',
				'last_error'    => '',
				'quota'         => self::quota_state(),
			);
		}

		try {
			return self::run_batch( (int) $limit, $owner );
		} finally {
			\Convertrack\Owner_Lock::release( self::LOCK_OPTION, $owner );
		}
	}

	/**
	 * Inspect due URLs against the Google Search Console API.
	 *
	 * @param int    $limit Optional cap on URLs inspected this call.
	 * @param string $owner Lock owner token.
	 * @return array
	 */
	private static function run_batch( $limit, $owner ) {
		$daily_limit = (int) Settings::get( 'daily_quota_limit', 2000 );
		$used        = self::quota_used();
		if ( $used >= $daily_limit ) {
			$marked = Database::mark_due_pending_due_to_quota();
			Logger::warning( 'processor', 'Daily URL Inspection quota reached before batch start.', array( 'marked' => $marked ) );
			return array(
				'processed'     => 0,
				'errors'        => 0,
				'remaining'     => Database::due_count(),
				'quota_reached' => true,
				'aborted'       => false,
				'abort_reason'  => '',
				'last_error'    => '',
				'marked'        => $marked,
				'quota'         => self::quota_state(),
			);
		}

		$batch_size = min( (int) Settings::get( 'batch_size', 100 ), $daily_limit - $used );
		if ( $limit > 0 ) {
			$batch_size = max( 1, min( $batch_size, $limit ) );
		}

		$released = Database::release_stale_checking();
		if ( is_wp_error( $released ) ) {
			return $released;
		}

		$rows              = Database::due_batch( $batch_size );
		if ( empty( $rows ) ) {
			return array(
				'processed' => 0, 'errors' => 0, 'remaining' => 0,
				'quota_reached' => false, 'rate_limited' => false, 'budget_reached' => false,
				'aborted' => false, 'abort_reason' => '', 'last_error' => '',
				'quota' => self::quota_state(),
			);
		}
		$processed         = 0;
		$errors            = 0;
		$permission_streak = 0;
		$quota_reached     = false;
		$aborted           = false;
		$abort_reason      = '';
		$last_error        = '';
		$rate_limited      = false;
		$budget_reached    = false;
		$batch_started     = microtime( true );
		$request_budget    = max( 1, min( 100, (int) apply_filters( 'convertrack_gsc_inspection_request_budget', 50 ) ) );
		$wall_budget       = max( 5, min( 60, (int) apply_filters( 'convertrack_gsc_inspection_wall_seconds', 20 ) ) );
		$submitted_sitemaps = array();

		Logger::info( 'processor', 'GSC inspection batch started.', array( 'requested' => $batch_size, 'found' => count( $rows ) ) );

		foreach ( $rows as $row ) {
			if ( ( $processed + $errors ) >= $request_budget || ( microtime( true ) - $batch_started ) >= $wall_budget ) {
				$budget_reached = true;
				break;
			}
			if ( ! \Convertrack\Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, self::LOCK_TIMEOUT ) ) {
				$aborted      = true;
				$abort_reason = __( 'The inspection worker lost ownership of its lease.', 'convertrack-click-conversion-analytics' );
				break;
			}

			if ( self::quota_used() >= $daily_limit ) {
				Database::mark_pending_due_to_quota( (int) $row['id'] );
				Database::mark_due_pending_due_to_quota();
				Logger::warning( 'processor', 'Daily URL Inspection quota reached during batch.' );
				$quota_reached = true;
				break;
			}

			$claimed = Database::mark_checking( (int) $row['id'] );
			if ( is_wp_error( $claimed ) ) {
				$aborted      = true;
				$abort_reason = $claimed->get_error_message();
				break;
			}
			$result = API::inspect_url( $row['url'] );

			if ( is_wp_error( $result ) && self::is_auth_error( $result ) ) {
				// The request never reached Google — return the row to the queue and stop.
				Database::mark_queued( (int) $row['id'] );
				$last_error   = $result->get_error_message();
				$aborted      = true;
				$abort_reason = $last_error;
				self::remember_batch_error( $abort_reason, 'auth' );
				Logger::error( 'processor', 'Inspection batch stopped: Google authorization failed.', array( 'error' => $last_error ) );
				break;
			}

			self::increment_quota();

			if ( is_wp_error( $result ) ) {
				if ( API::is_daily_quota_error( $result ) ) {
					Database::mark_pending_due_to_quota( (int) $row['id'] );
					Database::mark_due_pending_due_to_quota();
					Logger::warning( 'processor', 'Google API quota was reached.', array( 'url' => $row['url'], 'error' => $result->get_error_message() ) );
					$quota_reached = true;
					break;
				}
				if ( API::is_rate_limit_error( $result ) ) {
					$delay = API::retry_after_seconds( $result, 5 * MINUTE_IN_SECONDS );
					Database::mark_rate_limited( (int) $row['id'], $delay );
					Database::mark_due_rate_limited( $delay );
					self::remember_rate_limit( $delay );
					Logger::warning( 'processor', 'Google temporarily rate limited URL inspection.', array( 'retry_after' => $delay, 'error' => $result->get_error_message() ) );
					$rate_limited = true;
					break;
				}

				$errors++;
				$last_error        = $result->get_error_message();
				$permission_streak = API::is_permission_error( $result ) ? $permission_streak + 1 : 0;
				$saved_error = Database::save_error( (int) $row['id'], $last_error, self::error_retry_delay( $row ) );
				if ( is_wp_error( $saved_error ) ) {
					$aborted      = true;
					$abort_reason = $saved_error->get_error_message();
					break;
				}
				Logger::error( 'processor', 'URL inspection failed.', array( 'url' => $row['url'], 'error' => $last_error ) );

				if ( $permission_streak >= self::PERMISSION_ABORT_THRESHOLD ) {
					$aborted = true;
					if ( API::is_api_disabled_error( $result ) ) {
						$abort_reason = sprintf(
							/* translators: 1: consecutive error count, 2: Google Cloud Console API library URL. */
							__( 'Stopped after %1$d consecutive errors: the Google Search Console API is not enabled for your OAuth project. Enable it in the Google Cloud Console (%2$s), wait a few minutes, then run the batch again.', 'convertrack-click-conversion-analytics' ),
							$permission_streak,
							'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com'
						);
					} else {
						$abort_reason = sprintf(
							/* translators: 1: consecutive error count, 2: Google error message, 3: configured Search Console property. */
							__( 'Stopped after %1$d consecutive permission errors from Google: %2$s — check that the Search Console property (%3$s) matches this site\'s URLs.', 'convertrack-click-conversion-analytics' ),
							$permission_streak,
							$last_error,
							Settings::get( 'property_url' )
						);
					}
					self::remember_batch_error( $abort_reason, 'permission' );
					Logger::error( 'processor', 'Inspection batch stopped early to protect quota.', array( 'reason' => $abort_reason ) );
					break;
				}
				continue;
			}

			$permission_streak = 0;
			$result = self::post_process_result( $row, $result, $submitted_sitemaps );
			$saved = Database::save_inspection_result( (int) $row['id'], $result );
			if ( is_wp_error( $saved ) ) {
				$aborted      = true;
				$abort_reason = $saved->get_error_message();
				break;
			}
			$processed++;
		}

		if ( $processed > 0 && ! $aborted ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		$remaining = Database::due_count();

		$sync_time = current_time( 'mysql' );
		$sync_saved = update_option( 'convertrack_gsc_last_sync_time', $sync_time, false );
		if ( ! $sync_saved && get_option( 'convertrack_gsc_last_sync_time' ) !== $sync_time ) {
			$aborted      = true;
			$abort_reason = __( 'The indexing sync watermark could not be saved.', 'convertrack-click-conversion-analytics' );
		}
		Database::clear_summary_cache();
		if ( 0 === $remaining || $aborted || $quota_reached || $rate_limited ) {
			// Snapshot recomputes the full summary — only do it at run boundaries,
			// not on every small chunk of an admin-driven loop.
			$snapshot = Database::record_snapshot();
			if ( is_wp_error( $snapshot ) ) {
				$aborted      = true;
				$abort_reason = $snapshot->get_error_message();
			}
		}
		Logger::info( 'processor', 'GSC inspection batch completed.', array( 'processed' => $processed, 'errors' => $errors, 'budget_reached' => $budget_reached ) );

		return array(
			'processed'     => $processed,
			'errors'        => $errors,
			'remaining'     => $remaining,
			'quota_reached' => $quota_reached,
			'rate_limited'  => $rate_limited,
			'budget_reached' => $budget_reached,
			'aborted'       => $aborted,
			'abort_reason'  => $abort_reason,
			'last_error'    => $last_error,
			'quota'         => self::quota_state(),
		);
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
	 * Persist the last batch-stopping error so the UI can show it after reloads
	 * and for batches that ran via cron.
	 *
	 * @param string $message Human-readable message.
	 * @param string $reason  'permission' or 'auth'.
	 */
	private static function remember_batch_error( $message, $reason ) {
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'message' => $message,
				'reason'  => $reason,
				'time'    => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * Persist a temporary rate-limit window separately from daily usage.
	 *
	 * @param int $delay Retry delay seconds.
	 */
	private static function remember_rate_limit( $delay ) {
		$state                       = self::quota_state();
		$state['state']              = 'rate_limited';
		$state['rate_limited_until'] = time() + max( MINUTE_IN_SECONDS, (int) $delay );
		update_option( self::QUOTA_OPTION, $state, false );
	}

	/**
	 * Reconcile API result with sitemap and Indexing API rules.
	 *
	 * @param array $row    Queue row.
	 * @param array $result Parsed API result.
	 * @return array
	 */
	private static function post_process_result( array $row, array $result, array &$submitted_sitemaps ) {
		$status = isset( $result['index_status'] ) ? $result['index_status'] : 'not_indexed';
		if ( 'indexed' === $status ) {
			return $result;
		}

		if ( Settings::get( 'use_indexing_api' ) && ! empty( $row['post_id'] ) ) {
			$notify = API::indexing_api_notify( $row['url'], (int) $row['post_id'] );
			if ( ! is_wp_error( $notify ) ) {
				$result['index_status']  = 'submitted_via_indexing_api';
				$result['next_check_at'] = Database::mysql_time( DAY_IN_SECONDS );
				Logger::info( 'indexing-api', 'Eligible URL submitted to the Google Indexing API.', array( 'url' => $row['url'] ) );
				return $result;
			}
		}

		if ( self::should_submit_sitemap( $row, $status ) ) {
			$sitemap = ! empty( $row['sitemap_url'] ) ? $row['sitemap_url'] : Settings::get( 'sitemap_url' );
			$key     = md5( strtolower( (string) $sitemap ) );
			if ( isset( $submitted_sitemaps[ $key ] ) ) {
				return $result;
			}
			$submitted_sitemaps[ $key ] = true;
			$submit  = API::submit_sitemap( $sitemap );
			if ( is_wp_error( $submit ) ) {
				Logger::warning( 'sitemap-submit', 'Sitemap resubmission failed.', array( 'sitemap' => $sitemap, 'error' => $submit->get_error_message() ) );
			} else {
				self::remember_sitemap_submission( $sitemap );
				$result['index_status']  = 'pending_from_sitemap';
				$result['next_check_at'] = Database::mysql_time( rand( 24, 72 ) * HOUR_IN_SECONDS );
				$result['submitted']     = true;
				Logger::info( 'sitemap-submit', 'Sitemap resubmitted after non-indexed inspection result.', array( 'sitemap' => $sitemap, 'url' => $row['url'] ) );
			}
		}

		return $result;
	}

	/**
	 * Whether sitemap resubmission is appropriate.
	 *
	 * @param array  $row    Queue row.
	 * @param string $status Status.
	 * @return bool
	 */
	private static function should_submit_sitemap( array $row, $status ) {
		if ( empty( $row['post_id'] ) || empty( $row['in_sitemap'] ) ) {
			return false;
		}

		if ( in_array( $status, array( 'blocked_by_robots', 'noindex_detected', 'duplicate_canonical' ), true ) ) {
			return false;
		}

		$cooldown = max( 1, (int) Settings::get( 'sitemap_submit_cooldown_hours', 24 ) ) * HOUR_IN_SECONDS;
		$history  = get_option( self::SITEMAP_SUBMISSIONS_OPTION, array() );
		$history  = is_array( $history ) ? $history : array();
		$sitemap  = ! empty( $row['sitemap_url'] ) ? $row['sitemap_url'] : Settings::get( 'sitemap_url' );
		$key      = md5( strtolower( (string) $sitemap ) );
		$last     = isset( $history[ $key ] ) ? (int) $history[ $key ] : 0;
		return ! $last || ( time() - $last ) >= $cooldown;
	}

	/**
	 * Record successful sitemap submission once for the whole sitemap.
	 *
	 * @param string $sitemap Sitemap URL.
	 */
	private static function remember_sitemap_submission( $sitemap ) {
		$history = get_option( self::SITEMAP_SUBMISSIONS_OPTION, array() );
		$history = is_array( $history ) ? $history : array();
		$history[ md5( strtolower( (string) $sitemap ) ) ] = time();
		arsort( $history );
		$history = array_slice( $history, 0, 100, true );
		update_option( self::SITEMAP_SUBMISSIONS_OPTION, $history, false );
	}

	/**
	 * Retry delay for errors.
	 *
	 * @param array $row Row.
	 * @return int
	 */
	private static function error_retry_delay( array $row ) {
		$attempt = isset( $row['attempt_count'] ) ? (int) $row['attempt_count'] : 0;
		return min( 72 * HOUR_IN_SECONDS, max( HOUR_IN_SECONDS, (int) pow( 2, min( 6, $attempt ) ) * HOUR_IN_SECONDS ) );
	}

	/**
	 * Current quota state.
	 *
	 * @return array
	 */
	public static function quota_state() {
		$state = get_option( self::QUOTA_OPTION, array() );
		$today = self::quota_date();

		if ( ! is_array( $state ) || empty( $state['date'] ) || $state['date'] !== $today ) {
			$state = array( 'date' => $today, 'used' => 0, 'state' => 'available', 'rate_limited_until' => 0 );
			update_option( self::QUOTA_OPTION, $state, false );
		}
		if ( ! empty( $state['rate_limited_until'] ) && (int) $state['rate_limited_until'] <= time() ) {
			$state['state']              = 'available';
			$state['rate_limited_until'] = 0;
			update_option( self::QUOTA_OPTION, $state, false );
		}

		$state['limit'] = (int) Settings::get( 'daily_quota_limit', 2000 );
		if ( (int) $state['used'] >= (int) $state['limit'] ) {
			$state['state'] = 'daily_exhausted';
		}
		return $state;
	}

	/**
	 * Used quota count.
	 *
	 * @return int
	 */
	private static function quota_used() {
		$state = self::quota_state();
		return isset( $state['used'] ) ? (int) $state['used'] : 0;
	}

	/**
	 * Increment quota usage.
	 */
	private static function increment_quota() {
		$state = self::quota_state();
		$state['used'] = isset( $state['used'] ) ? (int) $state['used'] + 1 : 1;
		update_option( self::QUOTA_OPTION, $state, false );
	}

	/**
	 * Site-local quota date.
	 *
	 * @return string
	 */
	private static function quota_date() {
		return date( 'Y-m-d', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}
}
