<?php
/**
 * Background scheduling for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Cron {

	const REFRESH     = 'convertrack_404_refresh_valid_urls';
	const PROCESS     = 'convertrack_404_process_recommendations';
	const PROCESS_NOW = 'convertrack_404_process_now';
	const CLEANUP     = 'convertrack_404_cleanup';
	const SPIKE       = 'convertrack_404_spike_check';
	const GROUP       = 'convertrack';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( self::REFRESH, array( __CLASS__, 'run_refresh' ) );
		add_action( self::PROCESS, array( __CLASS__, 'run_process' ) );
		add_action( self::PROCESS_NOW, array( __CLASS__, 'run_process_now' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'run_cleanup' ) );
		add_action( self::SPIKE, array( __CLASS__, 'run_spike_check' ) );

		self::schedule();
	}

	/**
	 * Schedule recurring work.
	 *
	 * @param bool $force Clear existing events first.
	 */
	public static function schedule( $force = false ) {
		if ( $force ) {
			self::unschedule();
		}

		$frequency = Settings::get( 'scan_frequency', 'hourly' );
		$frequency = in_array( $frequency, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $frequency : 'hourly';

		if ( self::action_scheduler_available() ) {
			$intervals = array(
				'hourly'     => HOUR_IN_SECONDS,
				'twicedaily' => 12 * HOUR_IN_SECONDS,
				'daily'      => DAY_IN_SECONDS,
			);
			if ( ! as_next_scheduled_action( self::REFRESH, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, $intervals[ $frequency ], self::REFRESH, array(), self::GROUP );
			}
			if ( ! as_next_scheduled_action( self::PROCESS, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::PROCESS, array(), self::GROUP );
			}
			if ( ! as_next_scheduled_action( self::CLEANUP, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP, array(), self::GROUP );
			}
			if ( ! as_next_scheduled_action( self::SPIKE, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::SPIKE, array(), self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::REFRESH ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, self::REFRESH );
		}
		if ( ! wp_next_scheduled( self::PROCESS ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::PROCESS );
		}
		if ( ! wp_next_scheduled( self::CLEANUP ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP );
		}
		if ( ! wp_next_scheduled( self::SPIKE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SPIKE );
		}
	}

	/**
	 * Clear scheduled jobs.
	 */
	public static function unschedule() {
		if ( self::action_scheduler_available() ) {
			as_unschedule_all_actions( self::REFRESH, array(), self::GROUP );
			as_unschedule_all_actions( self::PROCESS, array(), self::GROUP );
			as_unschedule_all_actions( self::PROCESS_NOW, array(), self::GROUP );
			as_unschedule_all_actions( self::CLEANUP, array(), self::GROUP );
			as_unschedule_all_actions( self::SPIKE, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::REFRESH );
		wp_clear_scheduled_hook( self::PROCESS );
		wp_clear_scheduled_hook( self::PROCESS_NOW );
		wp_clear_scheduled_hook( self::CLEANUP );
		wp_clear_scheduled_hook( self::SPIKE );
	}

	/**
	 * Reschedule recurring events after settings changes.
	 */
	public static function reschedule() {
		self::schedule( true );
	}

	/**
	 * Schedule a near-immediate one-shot recommendation run.
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
	 * Refresh valid URL candidates.
	 */
	public static function run_refresh() {
		if ( ! Settings::get( 'enabled' ) ) {
			return;
		}

		$last_refresh = (string) get_option( 'convertrack_404_last_sitemap_refresh', '' );
		$last_time    = '' !== $last_refresh ? strtotime( $last_refresh ) : false;
		$min_interval = max( 1, (int) Settings::get( 'sitemap_refresh_hours', 24 ) ) * HOUR_IN_SECONDS;
		if ( $last_time && ( time() - $last_time ) < $min_interval ) {
			return;
		}

		$result = Sitemap_Source::refresh();
		if ( ! is_wp_error( $result ) && Settings::recommendations_enabled() ) {
			self::kick_processing();
		}
	}

	/**
	 * Process recommendation batch.
	 */
	public static function run_process() {
		if ( ! Settings::recommendations_enabled() ) {
			return;
		}
		Matcher::process_batch();
	}

	/**
	 * One-shot recommendation run.
	 */
	public static function run_process_now() {
		if ( ! Settings::recommendations_enabled() ) {
			return;
		}

		$limit  = max( 5, min( 100, (int) Settings::get( 'recommendation_batch', 50 ) ) );
		$result = Matcher::process_batch( $limit );
		if ( is_array( $result ) && ! empty( $result['processed'] ) && (int) $result['processed'] >= $limit ) {
			self::kick_processing( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Cleanup retained events/logs.
	 */
	public static function run_cleanup() {
		$deleted = Database::cleanup( (int) Settings::get( 'retention_days', 180 ) );
		Logger::info( 'cleanup', '404 Monitor cleanup completed.', array( 'deleted' => $deleted ) );
	}

	/**
	 * Optional email warning for recent 404 spikes.
	 */
	public static function run_spike_check() {
		if ( ! Settings::get( 'enabled' ) || ! Settings::get( 'email_notifications' ) ) {
			return;
		}

		$summary   = Database::summary();
		$hits      = isset( $summary['spike_hits'] ) ? (int) $summary['spike_hits'] : 0;
		$threshold = isset( $summary['spike_threshold'] ) ? (int) $summary['spike_threshold'] : (int) Settings::get( 'spike_threshold', 50 );
		if ( $hits < $threshold ) {
			return;
		}

		$window       = max( 5, (int) Settings::get( 'spike_window_minutes', 60 ) );
		$last_notice  = (int) get_option( 'convertrack_404_last_spike_notice', 0 );
		$cooldown_end = $last_notice + ( $window * MINUTE_IN_SECONDS );
		if ( $last_notice && time() < $cooldown_end ) {
			return;
		}

		update_option( 'convertrack_404_last_spike_notice', time(), false );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf(
				/* translators: %d: hit count. */
				__( 'Convertrack 404 spike detected: %d hits', 'convertrack-click-conversion-analytics' ),
				$hits
			),
			sprintf(
				/* translators: 1: hit count, 2: minutes, 3: admin URL. */
				__( 'Convertrack detected %1$d 404 hits in the last %2$d minutes. Review the 404 Monitor dashboard: %3$s', 'convertrack-click-conversion-analytics' ),
				$hits,
				$window,
				admin_url( 'admin.php?page=convertrack-404-monitor' )
			)
		);
		Logger::warning( 'spike', '404 spike notification sent.', array( 'hits' => $hits, 'threshold' => $threshold ) );
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
