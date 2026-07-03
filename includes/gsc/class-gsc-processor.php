<?php
/**
 * Google URL Inspection background processor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Processor {

	const QUOTA_OPTION      = 'convertrack_gsc_quota_usage';
	const LOCK_OPTION       = 'convertrack_gsc_processing_lock';
	const LAST_ERROR_OPTION = 'convertrack_gsc_last_batch_error';
	const LOCK_TIMEOUT      = 120;
	const PERMISSION_ABORT_THRESHOLD = 3;

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

		if ( ! self::acquire_lock() ) {
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
			return self::run_batch( (int) $limit );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Inspect due URLs against the Google Search Console API.
	 *
	 * @param int $limit Optional cap on URLs inspected this call.
	 * @return array
	 */
	private static function run_batch( $limit ) {
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

		Database::release_stale_checking();

		$rows              = Database::due_batch( $batch_size );
		$processed         = 0;
		$errors            = 0;
		$permission_streak = 0;
		$quota_reached     = false;
		$aborted           = false;
		$abort_reason      = '';
		$last_error        = '';

		Logger::info( 'processor', 'GSC inspection batch started.', array( 'requested' => $batch_size, 'found' => count( $rows ) ) );

		foreach ( $rows as $row ) {
			// Heartbeat so a live batch can't outlast LOCK_TIMEOUT and be taken over.
			update_option( self::LOCK_OPTION, time(), false );

			if ( self::quota_used() >= $daily_limit ) {
				Database::mark_pending_due_to_quota( (int) $row['id'] );
				Database::mark_due_pending_due_to_quota();
				Logger::warning( 'processor', 'Daily URL Inspection quota reached during batch.' );
				$quota_reached = true;
				break;
			}

			Database::mark_checking( (int) $row['id'] );
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
				if ( API::is_quota_error( $result ) ) {
					Database::mark_pending_due_to_quota( (int) $row['id'] );
					Database::mark_due_pending_due_to_quota();
					Logger::warning( 'processor', 'Google API quota was reached.', array( 'url' => $row['url'], 'error' => $result->get_error_message() ) );
					$quota_reached = true;
					break;
				}

				$errors++;
				$last_error        = $result->get_error_message();
				$permission_streak = API::is_permission_error( $result ) ? $permission_streak + 1 : 0;
				Database::save_error( (int) $row['id'], $last_error, self::error_retry_delay( $row ) );
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
			$result = self::post_process_result( $row, $result );
			Database::save_inspection_result( (int) $row['id'], $result );
			$processed++;
		}

		if ( $processed > 0 && ! $aborted ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		$remaining = Database::due_count();

		update_option( 'convertrack_gsc_last_sync_time', current_time( 'mysql' ), false );
		Database::clear_summary_cache();
		if ( 0 === $remaining || $aborted || $quota_reached ) {
			// Snapshot recomputes the full summary — only do it at run boundaries,
			// not on every small chunk of an admin-driven loop.
			Database::record_snapshot();
		}
		Logger::info( 'processor', 'GSC inspection batch completed.', array( 'processed' => $processed, 'errors' => $errors ) );

		return array(
			'processed'     => $processed,
			'errors'        => $errors,
			'remaining'     => $remaining,
			'quota_reached' => $quota_reached,
			'aborted'       => $aborted,
			'abort_reason'  => $abort_reason,
			'last_error'    => $last_error,
			'quota'         => self::quota_state(),
		);
	}

	/**
	 * Acquire the batch lock so concurrent runs (admin loop + cron) can't overlap.
	 *
	 * add_option() is atomic — it fails when the row already exists. A lock older
	 * than LOCK_TIMEOUT is treated as abandoned and taken over.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION );
		if ( $held && ( time() - $held ) < self::LOCK_TIMEOUT ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );
		return true;
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
	 * Reconcile API result with sitemap and Indexing API rules.
	 *
	 * @param array $row    Queue row.
	 * @param array $result Parsed API result.
	 * @return array
	 */
	private static function post_process_result( array $row, array $result ) {
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
			$submit  = API::submit_sitemap( $sitemap );
			if ( is_wp_error( $submit ) ) {
				Logger::warning( 'sitemap-submit', 'Sitemap resubmission failed.', array( 'sitemap' => $sitemap, 'error' => $submit->get_error_message() ) );
			} else {
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

		if ( empty( $row['last_submitted_at'] ) ) {
			return true;
		}

		$last = strtotime( $row['last_submitted_at'] );
		if ( ! $last ) {
			return true;
		}

		$cooldown = max( 1, (int) Settings::get( 'sitemap_submit_cooldown_hours', 24 ) ) * HOUR_IN_SECONDS;
		return ( time() - $last ) >= $cooldown;
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
			$state = array( 'date' => $today, 'used' => 0 );
			update_option( self::QUOTA_OPTION, $state, false );
		}

		$state['limit'] = (int) Settings::get( 'daily_quota_limit', 2000 );
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
