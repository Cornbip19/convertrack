<?php

use Convertrack\Collector;
use Convertrack\CSV;
use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Page_Identity;
use Convertrack\Rest_Controller;

class Convertrack_Hardening_Contracts_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// WP_UnitTestCase rewrites CREATE TABLE to CREATE TEMPORARY TABLE, and
		// temporary tables are invisible to the SHOW TABLES contract check in
		// verify_schema(). Remove the rewrite so installs run real DDL exactly
		// as they do on a live site.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
	}

	public function test_public_ingestion_routes_remain_registered_and_admin_routes_are_capability_gated() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/convertrack/v1/collect', $routes );
		$this->assertArrayHasKey( '/convertrack/v1/heartbeat', $routes );

		wp_set_current_user( 0 );
		$this->assertFalse( ( new Rest_Controller() )->can_view_stats() );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertTrue( ( new Rest_Controller() )->can_view_stats() );
	}

	public function test_sensitive_query_data_and_csv_formulas_are_neutralized() {
		add_filter(
			'convertrack_allowed_query_params',
			function () {
				return array( 'utm_source', 'email', 'order_key' );
			}
		);
		$this->assertSame( '/landing?utm_source=google', Collector::sanitize_relative_url( '/landing?utm_source=google&email=a%40example.test&order_key=secret' ) );
		$this->assertSame( "'=2+2", CSV::cell( '=2+2' ) );
		$this->assertSame( "'@SUM(A1:A2)", CSV::cell( '@SUM(A1:A2)' ) );
		$this->assertSame( 'plain', CSV::cell( 'plain' ) );
	}

	public function test_page_identity_never_accepts_a_mismatched_post_id() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$valid   = Page_Identity::from_payload( get_permalink( $post_id ), $post_id );
		$forged  = Page_Identity::from_payload( '/not-the-post/', $post_id );

		$this->assertSame( $post_id, $valid['post_id'] );
		$this->assertSame( 0, $forged['post_id'] );
		$this->assertSame( 'url', $forged['object_type'] );
		$this->assertNotSame( $valid['page_key'], $forged['page_key'] );
	}

	public function test_oversized_public_body_is_rejected_with_413() {
		$request = new WP_REST_Request( 'POST', '/convertrack/v1/collect' );
		$request->set_body( str_repeat( 'x', Ingestion_Guard::max_body_bytes( 'collect' ) + 1 ) );
		$result = ( new Rest_Controller() )->collect( $request );
		$this->assertWPError( $result );
		$this->assertSame( 413, (int) $result->get_error_data()['status'] );
	}
}

