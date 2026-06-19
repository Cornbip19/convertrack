<?php
/**
 * Background jobs: daily rollups, raw-event retention and presence cleanup.
 *
 * Keeping the dashboard fast on large sites depends on these running. They are
 * scheduled with WP-Cron; on high-traffic sites a real system cron hitting
 * wp-cron.php is recommended.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Cron {

	const HOURLY  = 'convertrack_hourly';
	const CLEANUP = 'convertrack_session_cleanup';

	/**
	 * Register schedules and job handlers.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOURLY, array( __CLASS__, 'run_hourly' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'run_session_cleanup' ) );

		// Self-heal scheduling if an event was lost.
		if ( ! wp_next_scheduled( self::HOURLY ) || ! wp_next_scheduled( self::CLEANUP ) ) {
			self::schedule();
		}
	}

	/**
	 * Add a 5-minute schedule for presence cleanup.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['convertrack_5min'] ) ) {
			$schedules['convertrack_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Convertrack)', 'convertrack-click-conversion-analytics' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule jobs (called on activation and self-heal).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::CLEANUP ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'convertrack_5min', self::CLEANUP );
		}
	}

	/**
	 * Remove scheduled jobs (called on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::CLEANUP );
	}

	/**
	 * Hourly job: roll up recent days and purge expired raw events.
	 */
	public static function run_hourly() {
		// Re-roll today and yesterday so late-arriving events are captured.
		Database::rollup_day( Database::today() );
		Database::rollup_day( Database::date_days_ago( 1 ) );

		// Purge in bounded batches so a large backlog never stalls the request.
		// Up to 20 x 10k = 200k rows/run keeps pace with very high write rates.
		$retention = (int) Settings::get( 'retention_days' );
		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = Database::purge_old_events( $retention );
			if ( $deleted < 10000 ) {
				break;
			}
		}

		do_action( 'convertrack_hourly_complete' );
	}

	/**
	 * Frequent job: drop stale presence rows.
	 */
	public static function run_session_cleanup() {
		$window = (int) Settings::get( 'active_window' );
		// Keep sessions a while past the active window so counts stay stable.
		$retention = max( HOUR_IN_SECONDS, $window * 4 );

		// Drain in bounded batches so a backlog never holds a long table lock.
		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = Database::cleanup_sessions( $retention );
			if ( $deleted < 5000 ) {
				break;
			}
		}
	}
}
