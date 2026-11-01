<?php
/**
 * Background scheduling for GSC Keyword Insights.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

use Convertrack\Scheduler;

defined( 'ABSPATH' ) || exit;

class Keywords_Cron {

	const TICK        = 'convertrack_gsc_keywords_tick';
	const SYNC_STEP   = 'convertrack_gsc_keywords_sync_step';
	const ANALYZE_NOW = 'convertrack_gsc_keywords_analyze_now';
	const GROUP       = 'convertrack';

	/**
	 * Register hooks.
	 *
	 * The daily tick is armed by Scheduler::heal() once Action Scheduler is
	 * ready, not here: during plugins_loaded its API silently drops the call.
	 */
	public function register() {
		add_action( self::TICK, array( __CLASS__, 'run_tick' ) );
		add_action( self::SYNC_STEP, array( __CLASS__, 'run_sync_step' ) );
		add_action( self::ANALYZE_NOW, array( __CLASS__, 'run_analyze_now' ) );
	}

	/**
	 * Schedule the daily tick as soon as the scheduler can take it.
	 */
	public static function schedule() {
		Scheduler::when_ready( array( __CLASS__, 'schedule_recurring' ) );
	}

	/**
	 * Arm the daily tick. Call schedule() instead unless you know the
	 * scheduler is ready.
	 */
	public static function schedule_recurring() {
		if ( Scheduler::available() ) {
			if ( ! as_next_scheduled_action( self::TICK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 15 * MINUTE_IN_SECONDS, DAY_IN_SECONDS, self::TICK, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', self::TICK );
		}
	}

	/**
	 * Unschedule jobs.
	 */
	public static function unschedule() {
		if ( Scheduler::available() ) {
			as_unschedule_all_actions( self::TICK, array(), self::GROUP );
			as_unschedule_all_actions( self::SYNC_STEP, array(), self::GROUP );
			as_unschedule_all_actions( self::ANALYZE_NOW, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::SYNC_STEP );
		wp_clear_scheduled_hook( self::ANALYZE_NOW );
	}

	/**
	 * Schedule a near-immediate one-shot sync step.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_sync( $delay = 0 ) {
		Scheduler::kick( self::SYNC_STEP, $delay, self::GROUP );
	}

	/**
	 * Schedule a near-immediate one-shot analysis run.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_analyze( $delay = 0 ) {
		Scheduler::kick( self::ANALYZE_NOW, $delay, self::GROUP );
	}

	/**
	 * Restart the sync and analysis chains when work is waiting but no step
	 * is queued or running.
	 *
	 * An analysis batch that stopped on consecutive errors is left alone for
	 * an hour so a broken row cannot be retried every few minutes.
	 *
	 * @return array Chains restarted: any of 'sync', 'analysis'.
	 */
	public static function resume() {
		if ( ! Keywords_Settings::ready() ) {
			return array();
		}
		$resumed = array();

		$sync = Keywords_Sync::state();
		if ( in_array( $sync['status'], array( 'queued', 'running' ), true ) && ! Scheduler::is_scheduled( self::SYNC_STEP, self::GROUP ) ) {
			$delay = ! empty( $sync['next_retry_at'] ) ? max( 0, (int) $sync['next_retry_at'] - time() ) : 0;
			self::kick_sync( $delay );
			$resumed[] = 'sync';
		}

		if ( ! self::analysis_backing_off() && Keywords_Database::pending_analysis_count() > 0 && ! Scheduler::is_scheduled( self::ANALYZE_NOW, self::GROUP ) ) {
			self::kick_analyze();
			$resumed[] = 'analysis';
		}

		return $resumed;
	}

	/**
	 * Daily housekeeping and auto-sync due check.
	 */
	public static function run_tick() {
		if ( ! Keywords_Settings::ready() ) {
			return;
		}

		Keywords_Database::prune_custom_range();
		Keywords_Database::cleanup_staging();

		$auto_sync = (string) Keywords_Settings::get( 'auto_sync', 'weekly' );
		if ( 'manual' === $auto_sync ) {
			return;
		}

		// Slack below the nominal interval so a drifting daily tick can't skip a day.
		$max_age = 'daily' === $auto_sync ? 20 * HOUR_IN_SECONDS : (int) ( 6.5 * DAY_IN_SECONDS );
		if ( self::data_age() < $max_age ) {
			return;
		}

		$result = Keywords_Sync::request_sync( (array) Keywords_Settings::get( 'sync_ranges', array() ), '', '', 'auto' );
		if ( is_wp_error( $result ) && 'convertrack_gsc_keywords_sync_in_progress' !== $result->get_error_code() ) {
			Logger::warning( 'keywords-cron', 'Scheduled keyword sync could not start.', array( 'error' => $result->get_error_message() ) );
		}
	}

	/**
	 * One-shot sync step that re-kicks itself while work remains.
	 *
	 * The chain terminates: every step consumes request budget, finishes, or
	 * fails, and busy steps just reschedule once.
	 */
	public static function run_sync_step() {
		if ( ! Keywords_Settings::get( 'enabled' ) ) {
			return;
		}

		$state = Keywords_Sync::run_step();
		if ( ! is_array( $state ) ) {
			return;
		}

		if ( ! empty( $state['busy'] ) ) {
			self::kick_sync( 2 * MINUTE_IN_SECONDS );
			return;
		}

		if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
			$delay = ! empty( $state['next_retry_at'] ) ? max( MINUTE_IN_SECONDS, (int) $state['next_retry_at'] - time() ) : MINUTE_IN_SECONDS;
			self::kick_sync( $delay );
		}
	}

	/**
	 * One-shot analysis run that re-kicks itself while rows remain.
	 */
	public static function run_analyze_now() {
		if ( ! Keywords_Settings::get( 'enabled' ) ) {
			return;
		}

		$result = Keywords_Analyzer::analyze_batch();
		if ( ! is_array( $result ) ) {
			return;
		}

		if ( ! empty( $result['busy'] ) ) {
			self::kick_analyze( 2 * MINUTE_IN_SECONDS );
			return;
		}

		if ( empty( $result['aborted'] ) && ! empty( $result['remaining'] ) ) {
			self::kick_analyze( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Seconds since the stalest configured range sync (PHP_INT_MAX when any
	 * configured range has never completed).
	 *
	 * @return int
	 */
	private static function data_age() {
		$last_sync = get_option( Keywords_Sync::LAST_SYNC_OPTION, array() );
		if ( ! is_array( $last_sync ) || empty( $last_sync ) ) {
			return PHP_INT_MAX;
		}

		$oldest = PHP_INT_MAX;
		foreach ( (array) Keywords_Settings::get( 'sync_ranges', array() ) as $range ) {
			if ( empty( $last_sync[ $range ]['time'] ) ) {
				return PHP_INT_MAX;
			}
			$time = strtotime( (string) $last_sync[ $range ]['time'] );
			if ( ! $time ) {
				return PHP_INT_MAX;
			}
			$oldest = min( $oldest, $time );
		}

		if ( PHP_INT_MAX === $oldest ) {
			return PHP_INT_MAX;
		}

		return max( 0, (int) ( current_time( 'timestamp' ) - $oldest ) );
	}

	/**
	 * Whether the last analysis batch stopped on consecutive errors less than
	 * an hour ago.
	 *
	 * @return bool
	 */
	private static function analysis_backing_off() {
		$error = get_option( Keywords_Analyzer::LAST_ERROR_OPTION, array() );
		if ( ! is_array( $error ) || empty( $error['time'] ) ) {
			return false;
		}
		$time = strtotime( (string) $error['time'] );
		return $time && ( current_time( 'timestamp' ) - $time ) < HOUR_IN_SECONDS;
	}
}
