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
	const ROLLUP_CONTINUE = 'convertrack_rollup_continue';

	/**
	 * Register schedules and job handlers.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOURLY, array( __CLASS__, 'run_hourly' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'run_session_cleanup' ) );
		add_action( self::ROLLUP_CONTINUE, array( __CLASS__, 'run_rollup_continuation' ) );

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
		wp_clear_scheduled_hook( self::ROLLUP_CONTINUE );
	}

	/**
	 * Hourly job: roll up recent days and purge expired raw events.
	 */
	public static function run_hourly() {
		// Recover missed days oldest-first before refreshing recent buckets.
		$catch_up = Rollup_Manager::catch_up( 10, 20 );
		if ( is_wp_error( $catch_up ) ) {
			update_option( 'convertrack_rollup_last_error', $catch_up->get_error_message(), false );
		} else {
			delete_option( 'convertrack_rollup_last_error' );
			if ( ! empty( $catch_up['remaining'] ) ) {
				self::schedule_rollup_continuation();
			}
		}

		// Re-roll today and yesterday for late-arriving events. Staging and owner
		// leases make overlapping cron invocations safe.
		$recent_results = array(
			Database::date_days_ago( 1 ) => Rollup_Manager::rollup_day( Database::date_days_ago( 1 ), true ),
			Database::today()            => Rollup_Manager::rollup_day( Database::today(), true ),
		);
		foreach ( $recent_results as $date => $result ) {
			if ( is_wp_error( $result ) && 'convertrack_rollup_failed' === $result->get_error_code() ) {
				update_option( 'convertrack_rollup_last_error', $date . ': ' . $result->get_error_message(), false );
			}
		}

		// Purge in bounded batches so a large backlog never stalls the request.
		// Up to 20 x 10k = 200k rows/run keeps pace with very high write rates.
		$retention = (int) Settings::get( 'retention_days' );
		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = Database::purge_old_events( $retention );
			if ( is_wp_error( $deleted ) ) {
				update_option( 'convertrack_cleanup_last_error', $deleted->get_error_message(), false );
				break;
			}
			if ( $deleted < 10000 ) {
				break;
			}
		}

		$aggregate_retention = (int) Settings::get( 'aggregate_retention_days', 400 );
		$aggregate_deleted   = Database::purge_old_aggregates( $aggregate_retention );
		if ( is_wp_error( $aggregate_deleted ) ) {
			update_option( 'convertrack_cleanup_last_error', $aggregate_deleted->get_error_message(), false );
		} else {
			update_option(
				'convertrack_last_cleanup',
				array(
					'completed_at'          => current_time( 'mysql', true ),
					'aggregate_rows_deleted'=> (int) $aggregate_deleted,
				),
				false
			);
			delete_option( 'convertrack_cleanup_last_error' );
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
			if ( is_wp_error( $deleted ) ) {
				update_option( 'convertrack_cleanup_last_error', $deleted->get_error_message(), false );
				break;
			}
			if ( $deleted < 5000 ) {
				break;
			}
		}
	}

	/**
	 * Continue a large catch-up without waiting for the next hourly event.
	 */
	public static function run_rollup_continuation() {
		$result = Rollup_Manager::catch_up( 10, 20 );
		if ( is_wp_error( $result ) ) {
			update_option( 'convertrack_rollup_last_error', $result->get_error_message(), false );
			return;
		}
		if ( ! empty( $result['remaining'] ) ) {
			self::schedule_rollup_continuation();
		}
	}

	/**
	 * Schedule one bounded continuation, never a duplicate queue.
	 */
	private static function schedule_rollup_continuation() {
		if ( ! wp_next_scheduled( self::ROLLUP_CONTINUE ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ROLLUP_CONTINUE );
		}
	}
}
