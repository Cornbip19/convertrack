<?php
/**
 * Background scheduling for Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Cron {

	const PROCESS = 'convertrack_gsc_process_queue';
	const SCAN    = 'convertrack_gsc_scan_sitemap';
	const FULL    = 'convertrack_gsc_full_audit';
	const GROUP   = 'convertrack';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::PROCESS, array( __CLASS__, 'run_process' ) );
		add_action( self::SCAN, array( __CLASS__, 'run_scan' ) );
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
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::PROCESS, array(), self::GROUP );
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
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::PROCESS );
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
			as_unschedule_all_actions( self::SCAN, array(), self::GROUP );
			as_unschedule_all_actions( self::FULL, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::PROCESS );
		wp_clear_scheduled_hook( self::SCAN );
		wp_clear_scheduled_hook( self::FULL );
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
	 * Run sitemap scan.
	 */
	public static function run_scan() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		Sitemap_Scanner::scan();
		Database::record_snapshot();
	}

	/**
	 * Run weekly full audit.
	 */
	public static function run_full_audit() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		$scan = Sitemap_Scanner::scan();
		if ( ! is_wp_error( $scan ) ) {
			$count = Database::schedule_full_audit();
			Logger::info( 'full-audit', 'Weekly full audit scheduled.', array( 'urls' => $count ) );
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
