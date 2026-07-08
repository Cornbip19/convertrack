<?php
/**
 * Background scheduling for GSC Keyword Insights.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Cron {

	const TICK        = 'convertrack_gsc_keywords_tick';
	const SYNC_STEP   = 'convertrack_gsc_keywords_sync_step';
	const ANALYZE_NOW = 'convertrack_gsc_keywords_analyze_now';
	const GROUP       = 'convertrack';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( self::TICK, array( __CLASS__, 'run_tick' ) );
		add_action( self::SYNC_STEP, array( __CLASS__, 'run_sync_step' ) );
		add_action( self::ANALYZE_NOW, array( __CLASS__, 'run_analyze_now' ) );

		self::schedule();
	}

	/**
	 * Schedule the daily tick.
	 */
	public static function schedule() {
		if ( self::action_scheduler_available() ) {
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
		if ( self::action_scheduler_available() ) {
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
		self::kick( self::SYNC_STEP, $delay );
	}

	/**
	 * Schedule a near-immediate one-shot analysis run.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_analyze( $delay = 0 ) {
		self::kick( self::ANALYZE_NOW, $delay );
	}

	/**
	 * Daily housekeeping and auto-sync due check.
	 */
	public static function run_tick() {
		if ( ! Keywords_Settings::ready() ) {
			return;
		}

		Keywords_Database::prune_custom_range();

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
			self::kick_sync( MINUTE_IN_SECONDS );
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
	 * Seconds since the newest completed range sync (PHP_INT_MAX when never synced).
	 *
	 * @return int
	 */
	private static function data_age() {
		$last_sync = get_option( Keywords_Sync::LAST_SYNC_OPTION, array() );
		if ( ! is_array( $last_sync ) || empty( $last_sync ) ) {
			return PHP_INT_MAX;
		}

		$newest = 0;
		foreach ( $last_sync as $entry ) {
			$time = isset( $entry['time'] ) ? strtotime( (string) $entry['time'] ) : 0;
			if ( $time > $newest ) {
				$newest = $time;
			}
		}

		if ( ! $newest ) {
			return PHP_INT_MAX;
		}

		return max( 0, (int) ( current_time( 'timestamp' ) - $newest ) );
	}

	/**
	 * Dupe-guarded one-shot scheduling with a WP-Cron fallback.
	 *
	 * @param string $hook  Hook name.
	 * @param int    $delay Seconds from now.
	 */
	private static function kick( $hook, $delay = 0 ) {
		if ( self::action_scheduler_available() && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + max( 30, (int) $delay ), $hook );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Whether Action Scheduler functions are available.
	 *
	 * @return bool
	 */
	private static function action_scheduler_available() {
		return function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}
}
