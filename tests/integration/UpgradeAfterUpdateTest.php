<?php

use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Plugin;
use Convertrack\Rest_Controller;

class Convertrack_Upgrade_After_Update_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
		delete_transient( Plugin::UPGRADE_ATTEMPT_KEY );
	}

	/**
	 * Make the site look like the plugin files were just replaced: the core
	 * schema watermark is behind and the plugin version has never been seen.
	 */
	private function simulate_fresh_update() {
		delete_option( Plugin::VERSION_OPTION );
		update_option( Database::DB_VERSION_OPTION, '2.0.0', false );
		$this->assertFalse( Database::schema_is_healthy() );
	}

	public function test_first_request_after_an_update_upgrades_every_schema_without_an_administrator() {
		$this->simulate_fresh_update();
		wp_set_current_user( 0 );

		$this->assertSame( 'upgraded', Plugin::instance()->maybe_upgrade_after_update() );
		$this->assertTrue( Database::schema_is_healthy() );
		$this->assertTrue( Ingestion_Guard::schema_is_healthy() );
		$this->assertSame( CONVERTRACK_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertFalse( get_transient( Plugin::UPGRADE_ATTEMPT_KEY ) );

		// Once current, the check is a no-op.
		$this->assertSame( 'current', Plugin::instance()->maybe_upgrade_after_update() );
	}

	public function test_a_failing_attempt_is_not_retried_on_every_request() {
		$this->simulate_fresh_update();
		set_transient( Plugin::UPGRADE_ATTEMPT_KEY, time(), Plugin::UPGRADE_RETRY_SECONDS );

		$this->assertSame( 'throttled', Plugin::instance()->maybe_upgrade_after_update() );
		$this->assertFalse( Database::schema_is_healthy() );

		delete_transient( Plugin::UPGRADE_ATTEMPT_KEY );
		$this->assertSame( 'upgraded', Plugin::instance()->maybe_upgrade_after_update() );
		$this->assertTrue( Database::schema_is_healthy() );
	}

	public function test_ingestion_is_accepted_again_in_the_same_request_that_upgraded() {
		$this->simulate_fresh_update();

		$request = new WP_REST_Request( 'POST', '/convertrack/v1/collect' );
		$request->set_body( '{}' );
		$refused = ( new Rest_Controller() )->collect( $request );
		$this->assertWPError( $refused );
		$this->assertSame( 503, (int) $refused->get_error_data()['status'] );

		$this->assertSame( 'upgraded', Plugin::instance()->maybe_upgrade_after_update() );

		$result = ( new Rest_Controller() )->collect( $request );
		if ( is_wp_error( $result ) ) {
			// Any later guard (origin, token, ids) may still refuse the empty
			// body; what matters is that the schema is no longer the reason.
			$this->assertNotSame( 'convertrack_schema_unavailable', $result->get_error_code() );
			$this->assertNotSame( 503, (int) $result->get_error_data()['status'] );
		} else {
			$this->assertInstanceOf( WP_REST_Response::class, $result );
		}
	}

	public function test_an_administrator_request_records_the_watermark_too() {
		$this->simulate_fresh_update();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Plugin::instance()->maybe_upgrade_schema();

		$this->assertTrue( Database::schema_is_healthy() );
		$this->assertSame( CONVERTRACK_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertSame( 'current', Plugin::instance()->maybe_upgrade_after_update() );
	}
}
