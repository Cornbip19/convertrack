<?php

use Convertrack\Scheduler;

// The integration fixture ships no Action Scheduler, so the contracts these
// tests protect (readiness gating and pending-only duplicate guards) run
// against an in-memory stand-in with the same procedural signatures and the
// same semantics that bit production: nothing is stored before the data
// store is initialised, and as_next_scheduled_action() answers true for an
// action that is running.
if ( ! class_exists( 'ActionScheduler', false ) ) {
	class ActionScheduler {
		public static $initialized = false;

		public static function is_initialized( $function_name = null ) {
			return self::$initialized;
		}
	}
}

if ( ! class_exists( 'ActionScheduler_Store', false ) ) {
	class ActionScheduler_Store {
		const STATUS_PENDING = 'pending';
		const STATUS_RUNNING = 'in-progress';
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	$GLOBALS['convertrack_test_actions'] = array();

	function convertrack_test_add_action( $hook, $status, $timestamp, $group = 'convertrack', $recurring = false ) {
		static $next_id = 1;
		$id                                      = $next_id++;
		$GLOBALS['convertrack_test_actions'][ $id ] = compact( 'hook', 'status', 'timestamp', 'group', 'recurring' );
		return $id;
	}

	function convertrack_test_actions( $hook, $status = null, $group = 'convertrack' ) {
		$found = array();
		foreach ( $GLOBALS['convertrack_test_actions'] as $id => $action ) {
			if ( $action['hook'] === $hook && $action['group'] === $group && ( null === $status || $action['status'] === $status ) ) {
				$found[ $id ] = $action;
			}
		}
		return $found;
	}

	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		if ( ! ActionScheduler::is_initialized() ) {
			return 0;
		}
		return convertrack_test_add_action( $hook, 'pending', (int) $timestamp, $group );
	}

	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		if ( ! ActionScheduler::is_initialized() ) {
			return 0;
		}
		return convertrack_test_add_action( $hook, 'pending', (int) $timestamp, $group, true );
	}

	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		if ( ! ActionScheduler::is_initialized() ) {
			return false;
		}
		if ( convertrack_test_actions( $hook, 'in-progress', $group ) ) {
			return true;
		}
		foreach ( convertrack_test_actions( $hook, 'pending', $group ) as $action ) {
			return (int) $action['timestamp'];
		}
		return false;
	}

	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		if ( ! ActionScheduler::is_initialized() ) {
			return;
		}
		foreach ( convertrack_test_actions( $hook, 'pending', $group ) as $id => $action ) {
			unset( $GLOBALS['convertrack_test_actions'][ $id ] );
		}
	}

	function as_get_scheduled_actions( $args = array(), $return_format = OBJECT ) {
		if ( ! ActionScheduler::is_initialized() ) {
			return array();
		}
		$status = isset( $args['status'] ) ? $args['status'] : null;
		$group  = isset( $args['group'] ) ? $args['group'] : '';
		$ids    = array_keys( convertrack_test_actions( $args['hook'], $status, $group ) );
		if ( ! empty( $args['per_page'] ) ) {
			$ids = array_slice( $ids, 0, (int) $args['per_page'] );
		}
		return $ids;
	}
}

class Convertrack_Scheduler_Contracts_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$GLOBALS['convertrack_test_actions'] = array();
		ActionScheduler::$initialized        = true;
		delete_option( Scheduler::HEAL_OPTION );
	}

	public function tear_down() {
		ActionScheduler::$initialized = true;
		parent::tear_down();
	}

	public function test_scheduling_before_the_data_store_opens_is_deferred_not_dropped() {
		ActionScheduler::$initialized = false;
		$this->assertFalse( Scheduler::ready() );

		$ran = 0;
		$this->assertFalse(
			Scheduler::when_ready(
				function () use ( &$ran ) {
					$ran++;
				}
			)
		);
		\Convertrack\GSC\Keywords_Cron::schedule();
		\Convertrack\GSC\Keywords_Cron::kick_analyze( 0 );
		$this->assertSame( 0, $ran );
		$this->assertCount( 0, convertrack_test_actions( \Convertrack\GSC\Keywords_Cron::TICK ) );

		ActionScheduler::$initialized = true;
		do_action( 'action_scheduler_init' );

		$this->assertSame( 1, $ran );
		$this->assertCount( 1, convertrack_test_actions( \Convertrack\GSC\Keywords_Cron::TICK, 'pending' ) );
		$this->assertCount( 1, convertrack_test_actions( \Convertrack\GSC\Keywords_Cron::ANALYZE_NOW, 'pending' ) );

		// A second announcement must not replay the queue.
		Scheduler::flush_deferred();
		$this->assertSame( 1, $ran );

		// Once ready, work runs immediately.
		$this->assertTrue(
			Scheduler::when_ready(
				function () use ( &$ran ) {
					$ran++;
				}
			)
		);
		$this->assertSame( 2, $ran );
	}

	public function test_a_running_step_can_queue_its_successor_but_a_pending_one_is_never_duplicated() {
		$hook = \Convertrack\GSC\Cron::SCAN_NOW;
		convertrack_test_add_action( $hook, 'in-progress', time() );

		// The legacy guard saw the running step as "already scheduled".
		$this->assertTrue( as_next_scheduled_action( $hook, array(), 'convertrack' ) );
		$this->assertFalse( Scheduler::has_pending( $hook ) );
		$this->assertTrue( Scheduler::is_scheduled( $hook ) );

		\Convertrack\GSC\Cron::kick_scan( 60 );
		$this->assertCount( 1, convertrack_test_actions( $hook, 'pending' ) );

		\Convertrack\GSC\Cron::kick_scan( 60 );
		\Convertrack\GSC\Cron::kick_scan( 0 );
		$this->assertCount( 1, convertrack_test_actions( $hook, 'pending' ) );
		$this->assertTrue( Scheduler::has_pending( $hook ) );
	}

	public function test_heal_rearms_missing_recurring_jobs_and_is_throttled() {
		$this->assertNotFalse( Scheduler::heal() );

		$expected = array(
			\Convertrack\GSC\Cron::PROCESS,
			\Convertrack\GSC\Cron::SCAN,
			\Convertrack\GSC\Cron::FULL,
			\Convertrack\GSC\Keywords_Cron::TICK,
			\Convertrack\NotFound\Cron::REFRESH,
			\Convertrack\NotFound\Cron::PROCESS,
			\Convertrack\NotFound\Cron::CLEANUP,
			\Convertrack\NotFound\Cron::SPIKE,
			\Convertrack\NotFound\Cron::HEALTH,
		);
		foreach ( $expected as $hook ) {
			$this->assertCount( 1, convertrack_test_actions( $hook, 'pending' ), $hook );
		}
		$this->assertNotFalse( wp_next_scheduled( \Convertrack\Cron::HOURLY ) );

		// A second pass never duplicates.
		$this->assertNotFalse( Scheduler::heal( true ) );
		foreach ( $expected as $hook ) {
			$this->assertCount( 1, convertrack_test_actions( $hook, 'pending' ), $hook );
		}

		// Lose the hourly inspection job (what a fatal inside it does).
		as_unschedule_all_actions( \Convertrack\GSC\Cron::PROCESS, array(), 'convertrack' );
		$this->assertFalse( Scheduler::heal() );
		$this->assertCount( 0, convertrack_test_actions( \Convertrack\GSC\Cron::PROCESS ) );

		update_option( Scheduler::HEAL_OPTION, time() - Scheduler::HEAL_INTERVAL - 1, true );
		$this->assertNotFalse( Scheduler::heal() );
		$this->assertCount( 1, convertrack_test_actions( \Convertrack\GSC\Cron::PROCESS, 'pending' ) );
	}

	public function test_a_stalled_sitemap_scan_is_resumed_and_a_leftover_step_starts_nothing() {
		\Convertrack\GSC\Settings::save(
			array(
				'enabled'     => 1,
				'sitemap_url' => home_url( '/wp-sitemap.xml' ),
			)
		);

		// Idle: a leftover step must not start a generation.
		\Convertrack\GSC\Cron::run_scan_now();
		$this->assertSame( 'idle', \Convertrack\GSC\Sitemap_Scanner::state()['status'] );
		$this->assertFalse( \Convertrack\GSC\Cron::resume() );

		// Running with no step queued (the stall seen in production).
		update_option(
			\Convertrack\GSC\Sitemap_Scanner::STATE_OPTION,
			array(
				'status'     => 'running',
				'phase'      => 'posts',
				'generation' => 'test',
				'updated_at' => time() - 2 * HOUR_IN_SECONDS,
			),
			false
		);
		$this->assertTrue( \Convertrack\GSC\Cron::resume() );
		$this->assertTrue( Scheduler::has_pending( \Convertrack\GSC\Cron::SCAN_NOW ) );
		$this->assertFalse( \Convertrack\GSC\Cron::resume() );

		$resumed = Scheduler::heal( true );
		$this->assertIsArray( $resumed );
		$this->assertNotContains( 'gsc_sitemap_scan', $resumed );
		$this->assertCount( 1, convertrack_test_actions( \Convertrack\GSC\Cron::SCAN_NOW, 'pending' ) );
	}

	public function test_without_action_scheduler_ready_the_wp_cron_fallback_keeps_working() {
		// Action Scheduler functions exist in this process, so simulate the
		// "loaded but never initialised" state: nothing may be scheduled and
		// nothing may be lost.
		ActionScheduler::$initialized = false;
		$this->assertFalse( Scheduler::heal( true ) );
		$this->assertCount( 0, $GLOBALS['convertrack_test_actions'] );

		ActionScheduler::$initialized = true;
		do_action( 'action_scheduler_init' );
		$this->assertCount( 1, convertrack_test_actions( \Convertrack\GSC\Cron::PROCESS, 'pending' ) );
	}
}
