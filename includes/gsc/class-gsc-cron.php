<?php
/**
 * Background scheduling for Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

use Convertrack\Scheduler;

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
	 *
	 * The recurring actions are armed by Scheduler::heal() once Action
	 * Scheduler is ready, not here: during plugins_loaded its API silently
	 * drops every call.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::PROCESS, array( __CLASS__, 'run_process' ) );
		add_action( self::PROCESS_NOW, array( __CLASS__, 'run_process_now' ) );
		add_action( self::SCAN, array( __CLASS__, 'run_scan' ) );
		add_action( self::SCAN_NOW, array( __CLASS__, 'run_scan_now' ) );
		add_action( self::FULL, array( __CLASS__, 'run_full_audit' ) );
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
	 * Schedule recurring actions as soon as the scheduler can take them.
	 */
	public static function schedule() {
		Scheduler::when_ready( array( __CLASS__, 'schedule_recurring' ) );
	}

	/**
	 * Arm the recurring actions. Call schedule() instead unless you know the
	 * scheduler is ready.
	 */
	public static function schedule_recurring() {
		if ( Scheduler::available() ) {
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
		if ( Scheduler::available() ) {
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
		Scheduler::kick( self::PROCESS_NOW, $delay, self::GROUP );
	}

	/**
	 * Schedule a near-immediate resumable sitemap step.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick_scan( $delay = 0 ) {
		Scheduler::kick( self::SCAN_NOW, $delay, self::GROUP );
	}

	/**
	 * Restart a sitemap scan whose step chain was lost: a fatal mid-step, a
	 * cancelled action, or the former duplicate guard that counted the
	 * running step as already queued.
	 *
	 * @return bool Whether a step was queued.
	 */
	public static function resume() {
		if ( ! Settings::get( 'enabled' ) ) {
			return false;
		}
		$state = Sitemap_Scanner::state();
		if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return false;
		}
		if ( Scheduler::is_scheduled( self::SCAN_NOW, self::GROUP ) ) {
			return false;
		}
		self::kick_scan();
		return true;
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

		// Seed the non-sitemap sources first. These are bounded and synchronous,
		// and they are the only way 404 and redirect coverage reasons can appear at
		// all -- those URLs are by definition absent from a sitemap.
		Url_Discovery::run();

		// Label any rows still missing a reason, then propose fixes for rows that
		// have one. Both are derived from stored inspection data, so neither spends
		// inspection quota, and both are bounded and resumable.
		Database::backfill_reasons( 500 );
		Database::backfill_fix_suggestions( 100 );

		$queued = Sitemap_Scanner::request_scan( 'scheduled' );
		if ( ! is_wp_error( $queued ) ) {
			self::kick_scan();
		}
	}

	/**
	 * Execute and continue one sitemap scan step.
	 *
	 * A step only continues a generation that was explicitly requested (by
	 * run_scan(), run_full_audit() or the REST "scan now"); a step left over
	 * after completion must not start a fresh scan of its own.
	 */
	public static function run_scan_now() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}
		$current = Sitemap_Scanner::state();
		if ( ! in_array( $current['status'], array( 'queued', 'running' ), true ) ) {
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
}
