<?php
/**
 * Google URL Inspection background processor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Processor {

	const QUOTA_OPTION = 'convertrack_gsc_quota_usage';

	/**
	 * Process a batch of URLs.
	 *
	 * @return array|\WP_Error
	 */
	public static function process_batch() {
		if ( ! Settings::ready() ) {
			Logger::warning( 'processor', 'Batch skipped because Google Index Monitor is not connected or enabled.' );
			return new \WP_Error( 'convertrack_gsc_not_ready', __( 'Google Index Monitor is not connected or enabled.', 'convertrack-click-conversion-analytics' ) );
		}

		$limit = (int) Settings::get( 'daily_quota_limit', 2000 );
		$used  = self::quota_used();
		if ( $used >= $limit ) {
			$marked = Database::mark_due_pending_due_to_quota();
			Logger::warning( 'processor', 'Daily URL Inspection quota reached before batch start.', array( 'marked' => $marked ) );
			return array( 'processed' => 0, 'quota_reached' => true, 'marked' => $marked );
		}

		$batch_size = min( (int) Settings::get( 'batch_size', 100 ), $limit - $used );
		$rows       = Database::due_batch( $batch_size );
		$processed  = 0;
		$errors     = 0;

		Logger::info( 'processor', 'GSC inspection batch started.', array( 'requested' => $batch_size, 'found' => count( $rows ) ) );

		foreach ( $rows as $row ) {
			if ( self::quota_used() >= $limit ) {
				Database::mark_pending_due_to_quota( (int) $row['id'] );
				Database::mark_due_pending_due_to_quota();
				Logger::warning( 'processor', 'Daily URL Inspection quota reached during batch.' );
				break;
			}

			Database::mark_checking( (int) $row['id'] );
			$result = API::inspect_url( $row['url'] );
			self::increment_quota();

			if ( is_wp_error( $result ) ) {
				if ( API::is_quota_error( $result ) ) {
					Database::mark_pending_due_to_quota( (int) $row['id'] );
					Database::mark_due_pending_due_to_quota();
					Logger::warning( 'processor', 'Google API quota was reached.', array( 'url' => $row['url'], 'error' => $result->get_error_message() ) );
					break;
				}

				$errors++;
				Database::save_error( (int) $row['id'], $result->get_error_message(), self::error_retry_delay( $row ) );
				Logger::error( 'processor', 'URL inspection failed.', array( 'url' => $row['url'], 'error' => $result->get_error_message() ) );
				continue;
			}

			$result = self::post_process_result( $row, $result );
			Database::save_inspection_result( (int) $row['id'], $result );
			$processed++;
		}

		update_option( 'convertrack_gsc_last_sync_time', current_time( 'mysql' ), false );
		Database::clear_summary_cache();
		Database::record_snapshot();
		Logger::info( 'processor', 'GSC inspection batch completed.', array( 'processed' => $processed, 'errors' => $errors ) );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'quota'     => self::quota_state(),
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
