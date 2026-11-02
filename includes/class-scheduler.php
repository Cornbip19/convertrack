<?php
/**
 * Background job scheduling: Action Scheduler readiness, duplicate guards and
 * self-healing for every Convertrack worker.
 *
 * Action Scheduler loads its procedural API on plugins_loaded but only opens
 * its data store on init (priority 1). Every as_*() call before that returns
 * false or 0 without scheduling anything, so a plugin that arms its jobs while
 * booting arms nothing. as_next_scheduled_action() also answers true for an
 * action that is running right now, so a step that re-kicks itself from inside
 * its own run can never queue its successor. Together those left Convertrack's
 * recurring jobs missing and its step chains stalled after a single step.
 *
 * This class owns the three answers:
 *  - when_ready() runs scheduling work once the data store is open;
 *  - kick() queues a one-shot step unless one is already *pending*;
 *  - heal() re-arms missing recurring jobs and restarts stalled chains, at
 *    most every HEAL_INTERVAL from init and unconditionally from the hourly
 *    WP-Cron rollup.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const GROUP         = 'convertrack';
	const HEAL_OPTION   = 'convertrack_jobs_healed_at';
	const HEAL_INTERVAL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Callbacks waiting for Action Scheduler to open its data store.
	 *
	 * @var array
	 */
	private static $deferred = array();

	/**
	 * Hook the throttled self-heal and the WP-CLI commands.
	 */
	public static function register() {
		if ( did_action( 'init' ) ) {
			self::maybe_heal();
		} else {
			// Action Scheduler initialises on init priority 1, so by priority 20
			// it is ready whenever it is loaded at all.
			add_action( 'init', array( __CLASS__, 'maybe_heal' ), 20 );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'convertrack jobs status', array( __CLASS__, 'cli_status' ) );
			\WP_CLI::add_command( 'convertrack jobs heal', array( __CLASS__, 'cli_heal' ) );
		}
	}

	/**
	 * Whether the Action Scheduler procedural API is loaded.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Whether Action Scheduler can accept work right now.
	 *
	 * @return bool
	 */
	public static function ready() {
		if ( ! self::available() ) {
			return false;
		}
		if ( class_exists( '\ActionScheduler', false ) && method_exists( '\ActionScheduler', 'is_initialized' ) ) {
			return (bool) \ActionScheduler::is_initialized();
		}
		// Releases without the readiness flag (pre 3.1.6) open their store on
		// init priority 1 and never announce it.
		return did_action( 'action_scheduler_init' ) > 0 || did_action( 'init' ) > 0;
	}

	/**
	 * Run $callback as soon as background work can be scheduled: immediately
	 * when Action Scheduler is ready or absent (WP-Cron fallback), otherwise
	 * once it announces its data store.
	 *
	 * @param callable $callback Callback taking no arguments.
	 * @return bool True when it ran now, false when it was deferred.
	 */
	public static function when_ready( $callback ) {
		if ( ! self::available() || self::ready() ) {
			call_user_func( $callback );
			return true;
		}

		if ( empty( self::$deferred ) ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'flush_deferred' ), 1 );
			// Releases without the readiness action: the store is open by init 2.
			add_action( 'init', array( __CLASS__, 'flush_deferred' ), 2 );
		}
		if ( ! in_array( $callback, self::$deferred, true ) ) {
			self::$deferred[] = $callback;
		}
		return false;
	}

	/**
	 * Run the deferred callbacks once the data store is open.
	 */
	public static function flush_deferred() {
		if ( empty( self::$deferred ) || ! self::ready() ) {
			return;
		}
		$queue          = self::$deferred;
		self::$deferred = array();
		foreach ( $queue as $callback ) {
			call_user_func( $callback );
		}
	}

	/**
	 * Whether a pending (not yet started) action exists for $hook.
	 *
	 * Unlike as_next_scheduled_action(), a running action does not count: the
	 * step that is executing right now is allowed to queue its successor.
	 *
	 * @param string $hook  Hook name.
	 * @param string $group Action Scheduler group.
	 * @return bool
	 */
	public static function has_pending( $hook, $group = self::GROUP ) {
		if ( ! self::ready() ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $group,
				'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);
		return is_array( $ids ) && ! empty( $ids );
	}

	/**
	 * Whether $hook is pending or running under the active backend.
	 *
	 * @param string $hook  Hook name.
	 * @param string $group Action Scheduler group.
	 * @return bool
	 */
	public static function is_scheduled( $hook, $group = self::GROUP ) {
		if ( self::available() ) {
			return self::ready() && false !== as_next_scheduled_action( $hook, array(), $group );
		}
		return false !== wp_next_scheduled( $hook );
	}

	/**
	 * Queue a one-shot action unless one is already pending.
	 *
	 * Before the data store opens the kick is deferred rather than dropped.
	 * Without Action Scheduler a WP-Cron single event is used instead.
	 *
	 * @param string $hook  Hook name.
	 * @param int    $delay Seconds from now.
	 * @param string $group Action Scheduler group.
	 */
	public static function kick( $hook, $delay = 0, $group = self::GROUP ) {
		$delay = max( 0, (int) $delay );

		if ( ! self::available() ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_single_event( time() + max( 30, $delay ), $hook );
				if ( function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}
			}
			return;
		}

		if ( ! self::ready() ) {
			self::when_ready(
				function () use ( $hook, $delay, $group ) {
					self::kick( $hook, $delay, $group );
				}
			);
			return;
		}

		if ( ! self::has_pending( $hook, $group ) ) {
			as_schedule_single_action( time() + $delay, $hook, array(), $group );
		}
	}

	/**
	 * Throttled self-heal, hooked to init.
	 */
	public static function maybe_heal() {
		self::heal( false );
	}

	/**
	 * Re-arm missing recurring jobs and restart stalled step chains.
	 *
	 * @param bool $force Ignore the throttle.
	 * @return array|false Names of the chains that were restarted, or false
	 *                     when the check did not run (throttled or deferred).
	 */
	public static function heal( $force = false ) {
		if ( ! $force && ! self::heal_due() ) {
			return false;
		}
		if ( self::available() && ! self::ready() ) {
			// The deferred run re-checks the throttle, so a burst of early
			// requests cannot stack repairs.
			self::when_ready( array( __CLASS__, 'maybe_heal' ) );
			return false;
		}

		update_option( self::HEAL_OPTION, time(), true );

		Cron::schedule();
		\Convertrack\GSC\Cron::schedule();
		\Convertrack\GSC\Keywords_Cron::schedule();
		\Convertrack\GSC\Keywords_Autoapply::maybe_schedule();
		\Convertrack\NotFound\Cron::schedule();

		$resumed = array();
		if ( \Convertrack\GSC\Cron::resume() ) {
			$resumed[] = 'gsc_sitemap_scan';
		}
		foreach ( \Convertrack\GSC\Keywords_Cron::resume() as $chain ) {
			$resumed[] = 'keywords_' . $chain;
		}
		if ( \Convertrack\NotFound\Cron::resume() ) {
			$resumed[] = '404_sitemap_refresh';
		}

		if ( ! empty( $resumed ) ) {
			do_action( 'convertrack_jobs_resumed', $resumed );
		}
		return $resumed;
	}

	/**
	 * Whether the throttle allows a heal now.
	 *
	 * @return bool
	 */
	private static function heal_due() {
		$age = time() - (int) get_option( self::HEAL_OPTION, 0 );
		return $age >= self::HEAL_INTERVAL || $age < -self::HEAL_INTERVAL;
	}

	/**
	 * Recurring jobs and the backend each one lives on.
	 *
	 * @return array hook => 'wp-cron' | 'scheduler'
	 */
	public static function recurring_jobs() {
		$jobs = array(
			Cron::HOURLY                         => 'wp-cron',
			Cron::CLEANUP                        => 'wp-cron',
			\Convertrack\GSC\Cron::PROCESS       => 'scheduler',
			\Convertrack\GSC\Cron::SCAN          => 'scheduler',
			\Convertrack\GSC\Cron::FULL          => 'scheduler',
			\Convertrack\GSC\Keywords_Cron::TICK => 'scheduler',
			\Convertrack\NotFound\Cron::REFRESH  => 'scheduler',
			\Convertrack\NotFound\Cron::PROCESS  => 'scheduler',
			\Convertrack\NotFound\Cron::CLEANUP  => 'scheduler',
			\Convertrack\NotFound\Cron::SPIKE    => 'scheduler',
			\Convertrack\NotFound\Cron::HEALTH   => 'scheduler',
		);
		$autoapply = \Convertrack\GSC\Keywords_Autoapply::settings();
		if ( ! empty( $autoapply['enabled'] ) ) {
			$jobs[ \Convertrack\GSC\Keywords_Autoapply::HOOK ] = 'scheduler';
		}
		return $jobs;
	}

	/**
	 * Human-readable next run for a recurring job.
	 *
	 * @param string $hook    Hook name.
	 * @param string $backend 'wp-cron' or 'scheduler'.
	 * @return string
	 */
	public static function describe_next_run( $hook, $backend ) {
		if ( 'scheduler' === $backend && self::available() ) {
			if ( ! self::ready() ) {
				return 'unknown (Action Scheduler not initialised)';
			}
			$next = as_next_scheduled_action( $hook, array(), self::GROUP );
			if ( true === $next ) {
				return 'running now';
			}
			return $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'MISSING';
		}
		$next = wp_next_scheduled( $hook );
		return $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'MISSING';
	}

	/**
	 * `wp convertrack jobs status`: recurring jobs, their next run and the
	 * state of every resumable chain.
	 */
	public static function cli_status() {
		$backend = 'WP-Cron';
		if ( self::available() ) {
			$backend = 'Action Scheduler';
			if ( class_exists( '\ActionScheduler_Versions' ) && method_exists( '\ActionScheduler_Versions', 'instance' ) ) {
				$backend .= ' ' . \ActionScheduler_Versions::instance()->latest_version();
			}
			$backend .= self::ready() ? ' (ready)' : ' (not initialised)';
		}
		$last = (int) get_option( self::HEAL_OPTION, 0 );
		\WP_CLI::log( 'Backend: ' . $backend );
		\WP_CLI::log( 'Last self-heal: ' . ( $last ? gmdate( 'Y-m-d H:i:s', $last ) . ' UTC' : 'never' ) );

		$rows = array();
		foreach ( self::recurring_jobs() as $hook => $job_backend ) {
			$rows[] = array(
				'job'      => $hook,
				'backend'  => $job_backend,
				'next_run' => self::describe_next_run( $hook, $job_backend ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'job', 'backend', 'next_run' ) );

		$chains   = array();
		$scan     = \Convertrack\GSC\Sitemap_Scanner::state();
		$chains[] = array(
			'chain'  => 'gsc sitemap scan',
			'state'  => (string) $scan['status'],
			'queued' => self::is_scheduled( \Convertrack\GSC\Cron::SCAN_NOW ) ? 'yes' : 'no',
		);
		$chains[] = array(
			'chain'  => 'gsc inspection run',
			'state'  => \Convertrack\GSC\Database::due_count() . ' due',
			'queued' => self::is_scheduled( \Convertrack\GSC\Cron::PROCESS_NOW ) ? 'yes' : 'no',
		);
		if ( \Convertrack\GSC\Keywords_Settings::ready() ) {
			$sync     = \Convertrack\GSC\Keywords_Sync::state();
			$chains[] = array(
				'chain'  => 'keyword sync',
				'state'  => (string) $sync['status'],
				'queued' => self::is_scheduled( \Convertrack\GSC\Keywords_Cron::SYNC_STEP ) ? 'yes' : 'no',
			);
			$chains[] = array(
				'chain'  => 'keyword analysis',
				'state'  => \Convertrack\GSC\Keywords_Database::pending_analysis_count() . ' pending',
				'queued' => self::is_scheduled( \Convertrack\GSC\Keywords_Cron::ANALYZE_NOW ) ? 'yes' : 'no',
			);
		}
		$refresh  = get_option( \Convertrack\NotFound\Sitemap_Source::STATE_OPTION, array() );
		$chains[] = array(
			'chain'  => '404 sitemap refresh',
			'state'  => is_array( $refresh ) && ! empty( $refresh['status'] ) ? (string) $refresh['status'] : 'idle',
			'queued' => self::is_scheduled( \Convertrack\NotFound\Cron::REFRESH_NOW ) ? 'yes' : 'no',
		);
		\WP_CLI\Utils\format_items( 'table', $chains, array( 'chain', 'state', 'queued' ) );
	}

	/**
	 * `wp convertrack jobs heal`: re-arm and resume now, ignoring the throttle.
	 */
	public static function cli_heal() {
		$resumed = self::heal( true );
		if ( false === $resumed ) {
			\WP_CLI::error( 'Action Scheduler is loaded but its data store is not initialised in this context.' );
		}
		if ( empty( $resumed ) ) {
			\WP_CLI::success( 'Recurring jobs verified; no stalled chain needed a restart.' );
		} else {
			\WP_CLI::success( 'Recurring jobs verified; restarted: ' . implode( ', ', $resumed ) . '.' );
		}
	}
}
