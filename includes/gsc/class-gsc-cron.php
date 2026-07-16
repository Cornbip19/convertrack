<?php
/**
 * Background scheduling for Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Cron {

	const PROCESS     = 'convertrack_gsc_process_queue';
	const PROCESS_NOW = 'convertrack_gsc_process_now';
	const SCAN        = 'convertrack_gsc_scan_sitemap';
	const SCAN_NOW    = 'convertrack_gsc_scan_sitemap_step';
	const FULL        = 'convertrack_gsc_full_audit';
	const GROUP       = 'convertrack';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::PROCESS, array( __CLASS__, 'run_process' ) );
		add_action( self::PROCESS_NOW, array( __CLASS__, 'run_process_now' ) );
		add_action( self::SCAN, array( __CLASS__, 'run_scan' ) );
		add_action( self::SCAN_NOW, array( __CLASS__, 'run_scan_now' ) );
		add_action( self::FULL, array( __CLASS__, 'run_full_audit' ) );

		self::schedule();
	}

	/**
	 * Add weekly schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		if ( ! isset( $schedules['convertrack_gsc_weekly'] ) ) {
			$schedules['convertrack_gsc_weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Weekly (Convertrack Google Index Monitor)', 'convertrack-click-conversion-analytics' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule recurring actions.
	 */
	public static function schedule() {
		if ( self::action_scheduler_available() ) {
			if ( ! as_next_scheduled_action( self::PROCESS, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::PROCESS, array(), self::GROUP );
			}
			if ( ! as_next_scheduled_action( self::SCAN, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::SCAN, array(), self::GROUP );
			}
			if ( ! as_next_scheduled_action( self::FULL, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + WEEK_IN_SECONDS, WEEK_IN_SECONDS, self::FULL, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::PROCESS ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::PROCESS );
		}
		if ( ! wp_next_scheduled( self::SCAN ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::SCAN );
		}
		if ( ! wp_next_scheduled( self::FULL ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'convertrack_gsc_weekly', self::FULL );
		}
	}

	/**
	 * Unschedule jobs.
	 */
	public static function unschedule() {
		if ( self::action_scheduler_available() ) {
			as_unschedule_all_actions( self::PROCESS, array(), self::GROUP );
			as_unschedule_all_actions( self::PROCESS_NOW, array(), self::GROUP );
			as_unschedule_all_actions( self::SCAN, array(), self::GROUP );
			as_unschedule_all_actions( self::SCAN_NOW, array(), self::GROUP );
			as_unschedule_all_actions( self::FULL, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::PROCESS );
		wp_clear_scheduled_hook( self::PROCESS_NOW );
		wp_clear_scheduled_hook( self::SCAN );
		wp_clear_scheduled_hook( self::SCAN_NOW );
		wp_clear_scheduled_hook( self::FULL );
	}

	/**
	 * Schedule a near-immediate one-shot processing run.
	 *
	 * Uses a dedicated hook so WP-Cron's 10-minute duplicate-event rule can't
	 * collide with the recurring PROCESS event.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_processing( $delay = 0 ) {
		if ( self::action_scheduler_available() && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( self::PROCESS_NOW, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + max( 0, (int) $delay ), self::PROCESS_NOW, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::PROCESS_NOW ) ) {
			wp_schedule_single_event( time() + max( 30, (int) $delay ), self::PROCESS_NOW );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Schedule a near-immediate resumable sitemap step.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_scan( $delay = 0 ) {
		if ( self::action_scheduler_available() && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( self::SCAN_NOW, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + max( 0, (int) $delay ), self::SCAN_NOW, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::SCAN_NOW ) ) {
			wp_schedule_single_event( time() + max( 30, (int) $delay ), self::SCAN_NOW );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Run queue processor.
	 */
	public static function run_process() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		Processor::process_batch();
	}

	/**
	 * One-shot processing run that re-kicks itself while work remains.
	 *
	 * Chain terminates: every inspected/errored row gets a future next_check_at,
	 * and abort/quota states stop the re-kick.
	 */
	public static function run_process_now() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		$result = Processor::process_batch( 25 );
		if ( ! is_array( $result ) ) {
			return;
		}

		if ( ! empty( $result['busy'] ) ) {
			self::kick_processing( 2 * MINUTE_IN_SECONDS );
			return;
		}

		if ( empty( $result['aborted'] ) && empty( $result['quota_reached'] ) && empty( $result['rate_limited'] ) && ! empty( $result['remaining'] ) ) {
			self::kick_processing( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Run sitemap scan.
	 */
	public static function run_scan() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		$queued = Sitemap_Scanner::request_scan( 'scheduled' );
		if ( ! is_wp_error( $queued ) ) {
			self::kick_scan();
		}
	}

	/**
	 * Execute and continue one sitemap scan step.
	 */
	public static function run_scan_now() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		$scan = Sitemap_Scanner::scan();
		if ( is_wp_error( $scan ) ) {
			return;
		}
		if ( ! empty( $scan['busy'] ) || in_array( $scan['status'], array( 'queued', 'running' ), true ) ) {
			self::kick_scan( ! empty( $scan['busy'] ) ? 2 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
			return;
		}
		if ( in_array( $scan['status'], array( 'completed', 'partial' ), true ) ) {
			self::kick_processing();
		}
	}

	/**
	 * Run weekly full audit.
	 */
	public static function run_full_audit() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		$queued = Sitemap_Scanner::request_scan( 'full_audit' );
		if ( ! is_wp_error( $queued ) ) {
			self::kick_scan();
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
