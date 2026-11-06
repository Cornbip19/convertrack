<?php

use Convertrack\GSC\API;
use Convertrack\GSC\Credentials;

/**
 * Contracts for the Search Console HTTP helper (API::request()).
 *
 * Google's front end answers "411 Length Required" to a body-less PUT that
 * carries no Content-Length header, which is exactly what sitemaps.submit is
 * (a bare PUT). WordPress's HTTP API never adds that header itself and the
 * Requests cURL transport skips the body entirely when it is empty, so the
 * plugin has to declare the empty body explicitly. All Google traffic is
 * intercepted with pre_http_request; nothing is sent.
 */
class Convertrack_GSC_API_Request_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->assertTrue( true === Credentials::store_client( 'test-client-id.apps.googleusercontent.com', 'test-client-secret' ) );
		$this->assertTrue( true === Credentials::store_connection( 'test-access-token', 3600, 'https://www.googleapis.com/auth/webmasters', 'test-refresh-token' ) );
	}

	public function tear_down() {
		Credentials::delete_all();
		parent::tear_down();
	}

	/**
	 * Run a callback with Google traffic intercepted; return what WordPress was
	 * about to send plus the callback's return value.
	 *
	 * @param callable $call          Code that performs one API call.
	 * @param string   $response_body Canned JSON body Google "returns".
	 * @return array { url: string, args: array, result: mixed }
	 */
	private function capture( callable $call, $response_body = '{}' ) {
		$captured = array( 'url' => '', 'args' => array() );
		$mock     = function ( $pre, $args, $url ) use ( &$captured, $response_body ) {
			$captured['url']  = $url;
			$captured['args'] = $args;
			return array(
				'headers'  => array(),
				'body'     => $response_body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		$result = $call();
		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertNotSame( '', $captured['url'], 'The API call never reached the HTTP layer.' );
		$captured['result'] = $result;
		return $captured;
	}

	public function test_sitemap_submit_is_a_bodyless_put_that_declares_content_length_zero() {
		$c = $this->capture(
			function () {
				return API::submit_sitemap( 'https://example.com/sitemap.xml' );
			}
		);

		$this->assertTrue( $c['result'] );
		$this->assertSame( 'PUT', $c['args']['method'] );
		$this->assertStringEndsWith( '/sitemaps/https%3A%2F%2Fexample.com%2Fsitemap.xml', $c['url'] );
		$this->assertSame( 'Bearer test-access-token', $c['args']['headers']['Authorization'] );

		// The regression: an empty body must be declared, not omitted.
		$this->assertSame( '', $c['args']['body'] );
		$this->assertArrayHasKey( 'Content-Length', $c['args']['headers'] );
		$this->assertSame( '0', $c['args']['headers']['Content-Length'] );
		$this->assertArrayNotHasKey( 'Content-Type', $c['args']['headers'], 'A body-less request must not claim a JSON body.' );
	}

	public function test_json_post_keeps_its_body_and_never_sets_a_manual_content_length() {
		$c = $this->capture(
			function () {
				return API::inspect_url( 'https://example.com/page/' );
			}
		);

		$this->assertIsArray( $c['result'] );
		$this->assertSame( 'POST', $c['args']['method'] );
		$this->assertSame( 'application/json', $c['args']['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Content-Length', $c['args']['headers'], 'A manual length beside a real body would corrupt the request.' );

		$body = json_decode( $c['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'https://example.com/page/', $body['inspectionUrl'] );
	}

	public function test_get_requests_send_no_body_and_no_length() {
		$c = $this->capture(
			function () {
				return API::list_sites();
			},
			'{"siteEntry":[]}'
		);

		$this->assertSame( array(), $c['result'] );
		$this->assertSame( 'GET', $c['args']['method'] );
		$this->assertNull( $c['args']['body'] );
		$this->assertArrayNotHasKey( 'Content-Length', $c['args']['headers'] );
		$this->assertArrayNotHasKey( 'Content-Type', $c['args']['headers'] );
	}
}
