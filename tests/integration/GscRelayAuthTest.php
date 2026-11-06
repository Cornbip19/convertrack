<?php

use Convertrack\GSC\Admin as GSC_Admin;
use Convertrack\GSC\Credentials;
use Convertrack\GSC\Keywords_Settings;
use Convertrack\GSC\OAuth;
use Convertrack\GSC\Relay;
use Convertrack\GSC\Settings;

/**
 * Thrown from the wp_redirect filter so admin-post handlers can be exercised
 * without exiting the test process.
 */
class Convertrack_Gsc_Test_Redirect extends Exception {}

/**
 * "Sign in with Google" (relay) mode next to the pre-existing direct OAuth.
 *
 * Every outbound HTTP call is intercepted: nothing here talks to the relay or
 * to Google. The contracts under test are the ones that would hurt in
 * production if they slipped: which mode a stored option reads as, where the
 * admin is sent, what the relay receives, and above all that a relay outage
 * can never be mistaken for a revoked Google grant.
 */
class Convertrack_Gsc_Relay_Auth_Test extends WP_UnitTestCase {

	const RELAY = 'https://relay.test';
	const SITE_ID = 'abcdef0123456789abcdef0123456789';

	/** @var array Every intercepted request: { url, args }. */
	private $requests = array();

	/** @var array Responders tried in order: { method, prefix, response|callable }. */
	private $responders = array();

	public function set_up() {
		parent::set_up();
		$this->reset_state();
		add_filter( 'convertrack_gsc_relay_url', array( $this, 'relay_url' ) );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'convertrack_gsc_relay_url', array( $this, 'relay_url' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		remove_all_filters( 'wp_redirect' );
		$this->reset_state();
		parent::tear_down();
	}

	private function reset_state() {
		delete_option( Credentials::OPTION );
		delete_option( Settings::OPTION );
		delete_transient( Relay::CHALLENGE_TRANSIENT );
		delete_transient( 'convertrack_gsc_reconnect_required' );
		$this->requests   = array();
		$this->responders = array();
		$_GET             = array();
		$_POST            = array();
		$_REQUEST         = array();

		// Settings caches per request; tests are many requests.
		$cache = new ReflectionProperty( Settings::class, 'cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	public function relay_url() {
		return self::RELAY;
	}

	public function intercept_http( $preempt, $args, $url ) {
		$method           = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		foreach ( $this->responders as $responder ) {
			if ( $responder['method'] !== $method || 0 !== strpos( $url, $responder['prefix'] ) ) {
				continue;
			}
			$response = $responder['response'];
			$result   = is_callable( $response ) ? call_user_func( $response, $url, $args ) : $response;
			if ( null !== $result ) {
				return $result;
			}
		}
		$this->fail( 'Unexpected HTTP request: ' . $method . ' ' . $url );
	}

	/* ------------------------------------------------------------ helpers */

	private function respond_to( $method, $prefix, $response ) {
		$this->responders[] = array( 'method' => $method, 'prefix' => $prefix, 'response' => $response );
	}

	private static function http( $code, $body, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function relay_error( $code, $error, $description = 'relay says no' ) {
		return self::http( $code, array( 'error' => $error, 'error_description' => $description ), array( 'x-convertrack-relay' => 'error' ) );
	}

	private static function google_through_relay( $code, array $body ) {
		return self::http( $code, $body, array( 'x-convertrack-relay' => 'passthrough' ) );
	}

	private function requests_to( $prefix ) {
		$found = array();
		foreach ( $this->requests as $request ) {
			if ( 0 === strpos( $request['url'], $prefix ) ) {
				$found[] = $request;
			}
		}
		return $found;
	}

	private static function expected_site_url() {
		return preg_replace( '#/wp-admin/admin-post\.php\?action=convertrack_gsc_oauth_callback$#', '', GSC_Admin::callback_url() );
	}

	/** The relay registration round-trip, asserting what the site publishes and sends. */
	private function expect_registration( $site_secret = 'site-secret-1' ) {
		$test = $this;
		$this->respond_to(
			'POST',
			self::RELAY . '/v1/sites',
			function ( $url, $args ) use ( $test, $site_secret ) {
				$body = json_decode( $args['body'], true );
				$test->assertSame( self::expected_site_url(), $body['site_url'] );
				$test->assertSame( rest_url( 'convertrack/v1/gsc/relay-challenge' ), $body['challenge_url'] );
				$test->assertMatchesRegularExpression( '/^[A-Za-z0-9]{48}$/', $body['challenge'] );

				// While the relay is on the line, the site publishes exactly that challenge.
				$published = ( new GSC_Admin() )->relay_challenge();
				$test->assertInstanceOf( 'WP_REST_Response', $published );
				$test->assertSame( $body['challenge'], $published->get_data()['challenge'] );

				return self::http(
					200,
					array(
						'site_id'        => self::SITE_ID,
						'site_secret'    => $site_secret,
						'site_url'       => $body['site_url'],
						'relay_url'      => self::RELAY,
						'relay_callback' => self::RELAY . '/v1/callback',
					)
				);
			}
		);
	}

	private function connect_in_relay_mode( $refresh_token = '1//refresh' ) {
		Credentials::set_auth_mode( Credentials::MODE_RELAY );
		Credentials::store_site_registration( self::SITE_ID, 'site-secret-1', self::RELAY );
		Credentials::store_connection( 'ya29.old', 3600, 'https://www.googleapis.com/auth/webmasters', $refresh_token, 'owner@example.com' );
	}

	private function as_admin() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		return $user;
	}

	/** Run an admin-post handler and return the URL it redirected to. */
	private function capture_redirect( $callback ) {
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new Convertrack_Gsc_Test_Redirect( $location );
			}
		);
		try {
			call_user_func( $callback );
		} catch ( Convertrack_Gsc_Test_Redirect $redirect ) {
			remove_all_filters( 'wp_redirect' );
			return $redirect->getMessage();
		}
		remove_all_filters( 'wp_redirect' );
		$this->fail( 'The handler did not redirect.' );
	}

	private static function notice_of( $location ) {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		return isset( $query['cvtrk_gsc_notice'] ) ? $query['cvtrk_gsc_notice'] : '';
	}

	private static function fake_id_token( $email ) {
		$payload = rtrim( strtr( base64_encode( wp_json_encode( array( 'email' => $email ) ) ), '+/', '-_' ), '=' );
		return 'header.' . $payload . '.signature';
	}

	/* ------------------------------------------------------- mode detection */

	public function test_a_fresh_install_defaults_to_sign_in_with_google() {
		$this->assertSame( 'relay', Credentials::auth_mode() );
		$this->assertTrue( Credentials::is_relay_mode() );
		$this->assertFalse( Credentials::has_site_registration() );
		$this->assertFalse( Credentials::is_connected() );

		$status = Credentials::public_status();
		$this->assertSame( 'relay', $status['auth_mode'] );
		$this->assertFalse( $status['has_site_registration'] );
		$this->assertFalse( $status['connected'] );
	}

	public function test_a_site_that_connected_with_its_own_client_stays_on_direct_oauth() {
		// What a pre-relay install holds: an owner client and a Google grant,
		// and nothing else.
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::store_connection( 'ya29.direct', 3600, 'https://www.googleapis.com/auth/webmasters', '1//direct', 'owner@example.com' );

		$this->assertSame( 'direct', Credentials::auth_mode() );
		$this->assertFalse( Credentials::is_relay_mode() );
		$this->assertTrue( Credentials::has_client() );
		$this->assertTrue( Credentials::is_connected() );

		$status = Credentials::public_status();
		$this->assertSame( 'direct', $status['auth_mode'] );
		$this->assertTrue( $status['has_client'] );
		$this->assertTrue( $status['connected'] );
		$this->assertSame( 'owner-client-id', $status['client_id'] );
	}

	public function test_an_explicit_mode_wins_and_the_other_credentials_survive() {
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		$this->assertSame( 'direct', Credentials::auth_mode() );

		$this->assertTrue( Credentials::set_auth_mode( Credentials::MODE_RELAY ) );
		$this->assertSame( 'relay', Credentials::auth_mode() );
		$this->assertSame( 'owner-client-id', Credentials::client_id(), 'Switching modes keeps the owner client.' );
		$this->assertSame( 'owner-client-secret', Credentials::client_secret() );

		Credentials::store_site_registration( self::SITE_ID, 'site-secret-1', self::RELAY );
		$this->assertTrue( Credentials::set_auth_mode( Credentials::MODE_DIRECT ) );
		$this->assertSame( 'direct', Credentials::auth_mode() );
		$this->assertTrue( Credentials::has_site_registration(), 'Switching back keeps the relay registration.' );

		$this->assertWPError( Credentials::set_auth_mode( 'bogus' ) );
		$this->assertSame( 'direct', Credentials::auth_mode() );
	}

	/* ----------------------------------------------------- registration */

	public function test_registration_publishes_a_challenge_for_the_relay_and_stores_the_site_credentials() {
		$this->expect_registration( 'site-secret-1' );

		$this->assertTrue( Relay::register_site() );
		$this->assertCount( 1, $this->requests );

		$this->assertTrue( Credentials::has_site_registration() );
		$this->assertSame( self::SITE_ID, Credentials::site_id() );
		$this->assertSame( 'site-secret-1', Credentials::site_secret() );
		$this->assertSame( self::RELAY, Credentials::relay_url() );
		$this->assertTrue( Relay::is_registered() );

		// The challenge lives only for the registration round-trip.
		$this->assertSame( '', Relay::pending_challenge() );
		$gone = ( new GSC_Admin() )->relay_challenge();
		$this->assertWPError( $gone );
		$this->assertSame( 404, $gone->get_error_data()['status'] );
	}

	public function test_a_refused_registration_is_an_error_without_site_credentials() {
		$this->respond_to( 'POST', self::RELAY . '/v1/sites', self::relay_error( 403, 'relay_challenge_mismatch', 'The site published a different challenge.' ) );

		$result = Relay::register_site();
		$this->assertWPError( $result );
		$this->assertSame( 'convertrack_gsc_relay_registration_failed', $result->get_error_code() );
		$this->assertFalse( Credentials::has_site_registration() );
	}

	/* ---------------------------------------------------------- connect_url */

	public function test_connect_url_targets_google_directly_or_the_relay_depending_on_the_mode() {
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::set_auth_mode( Credentials::MODE_DIRECT );

		$direct = OAuth::connect_url( OAuth::redirect_uri(), 'state-direct-0123456789', 'challenge-direct' );
		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $direct );
		$query = array();
		wp_parse_str( wp_parse_url( $direct, PHP_URL_QUERY ), $query );
		$this->assertSame( 'owner-client-id', $query['client_id'] );
		$this->assertSame( OAuth::redirect_uri(), $query['redirect_uri'] );
		$this->assertSame( 'state-direct-0123456789', $query['state'] );
		$this->assertSame( 'challenge-direct', $query['code_challenge'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );

		Credentials::set_auth_mode( Credentials::MODE_RELAY );
		$unregistered = OAuth::connect_url( OAuth::redirect_uri(), 'state-relay-0123456789', 'challenge-relay' );
		$this->assertWPError( $unregistered );
		$this->assertSame( 'convertrack_gsc_no_site_registration', $unregistered->get_error_code() );

		Credentials::store_site_registration( self::SITE_ID, 'site-secret-1', self::RELAY );
		$relay = OAuth::connect_url( OAuth::redirect_uri(), 'state-relay-0123456789', 'challenge-relay' );
		$this->assertStringStartsWith( self::RELAY . '/v1/authorize?', $relay );
		$query = array();
		wp_parse_str( wp_parse_url( $relay, PHP_URL_QUERY ), $query );
		$this->assertSame( self::SITE_ID, $query['site_id'] );
		$this->assertSame( self::expected_site_url(), $query['site_url'] );
		$this->assertSame( 'state-relay-0123456789', $query['state'] );
		$this->assertSame( 'challenge-relay', $query['code_challenge'] );
		$this->assertStringContainsString( 'https://www.googleapis.com/auth/webmasters', $query['scopes'] );
		$this->assertArrayNotHasKey( 'client_id', $query );
		$this->assertStringNotContainsString( 'owner-client-secret', $relay );

		$this->assertCount( 0, $this->requests, 'Building a connect URL never calls out.' );
	}

	/* ------------------------------------------------- refresh: relay vs Google */

	public function test_a_relay_outage_keeps_the_tokens_and_never_demands_a_reconnect() {
		$this->connect_in_relay_mode( '1//keep-me' );

		$cases = array(
			'transport failure' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			'relay 503'         => self::relay_error( 503, 'relay_not_configured' ),
			'relay 502'         => self::relay_error( 502, 'relay_upstream_unavailable' ),
			'non-JSON 502'      => self::http( 502, '<html>Bad gateway</html>' ),
		);

		foreach ( $cases as $label => $response ) {
			$this->responders = array();
			$this->respond_to( 'POST', self::RELAY . '/v1/token', $response );

			$result = OAuth::refresh_token();
			$this->assertWPError( $result, $label );
			$this->assertNotSame( 'convertrack_gsc_reconnect_required', $result->get_error_code(), $label );
			$this->assertContains( $result->get_error_code(), array( 'convertrack_gsc_relay_unreachable', 'convertrack_gsc_relay_error' ), $label );
			$this->assertSame( '1//keep-me', Credentials::refresh_token(), $label . ': refresh token survives' );
			$this->assertTrue( Credentials::is_connected(), $label );
			$this->assertFalse( get_transient( 'convertrack_gsc_reconnect_required' ), $label );
		}
	}

	public function test_a_google_invalid_grant_through_the_relay_clears_the_tokens() {
		$this->connect_in_relay_mode( '1//dead' );
		$test = $this;
		$this->respond_to(
			'POST',
			self::RELAY . '/v1/token',
			function ( $url, $args ) use ( $test ) {
				$test->assertSame( 'refresh_token', $args['body']['grant_type'] );
				$test->assertSame( '1//dead', $args['body']['refresh_token'] );
				$test->assertSame( self::SITE_ID, $args['body']['site_id'] );
				$test->assertSame( 'site-secret-1', $args['body']['site_secret'] );
				$test->assertArrayNotHasKey( 'client_secret', $args['body'], 'The relay adds the client secret, never the site.' );
				return self::google_through_relay( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
			}
		);

		$result = OAuth::refresh_token();
		$this->assertWPError( $result );
		$this->assertSame( 'convertrack_gsc_reconnect_required', $result->get_error_code() );
		$this->assertSame( '', Credentials::refresh_token() );
		$this->assertFalse( Credentials::is_connected() );
		$this->assertNotFalse( get_transient( 'convertrack_gsc_reconnect_required' ) );
		$this->assertTrue( Credentials::has_site_registration(), 'Only the Google tokens go; the registration stays.' );
	}

	public function test_rejected_site_credentials_trigger_one_fresh_registration_and_a_retry() {
		$this->connect_in_relay_mode( '1//refresh' );
		$this->expect_registration( 'site-secret-2' );

		$calls = 0;
		$test  = $this;
		$this->respond_to(
			'POST',
			self::RELAY . '/v1/token',
			function ( $url, $args ) use ( &$calls, $test ) {
				$calls++;
				if ( 1 === $calls ) {
					$test->assertSame( 'site-secret-1', $args['body']['site_secret'] );
					return self::relay_error( 401, 'relay_invalid_site_credentials' );
				}
				$test->assertSame( 'site-secret-2', $args['body']['site_secret'], 'The retry carries the fresh secret.' );
				return self::google_through_relay( 200, array( 'access_token' => 'ya29.fresh', 'expires_in' => 3599, 'token_type' => 'Bearer' ) );
			}
		);

		$this->assertSame( 'ya29.fresh', OAuth::refresh_token() );
		$this->assertSame( 2, $calls );
		$this->assertCount( 1, $this->requests_to( self::RELAY . '/v1/sites' ) );
		$this->assertSame( 'site-secret-2', Credentials::site_secret() );
		$this->assertSame( 'ya29.fresh', Credentials::access_token() );
		$this->assertSame( '1//refresh', Credentials::refresh_token() );
	}

	/* ------------------------------------------------------------ callback */

	public function test_the_callback_consumes_the_bounced_code_and_state_and_turns_the_monitor_on() {
		$user = $this->as_admin();
		Credentials::set_auth_mode( Credentials::MODE_RELAY );
		Credentials::store_site_registration( self::SITE_ID, 'site-secret-1', self::RELAY );
		set_transient( 'convertrack_gsc_oauth_state_' . $user, array( 'state' => 'siteStateSiteState0123456789', 'verifier' => 'verifierVerifierVerifierVerifierVerifier0123456789' ), 600 );
		$this->assertSame( 0, (int) Settings::get( 'enabled' ) );

		$_GET = array( 'code' => '4/0AX4XfWbouncedCode', 'state' => 'siteStateSiteState0123456789' );

		$test = $this;
		$this->respond_to(
			'POST',
			self::RELAY . '/v1/token',
			function ( $url, $args ) use ( $test ) {
				$body = $args['body'];
				$test->assertSame( 'authorization_code', $body['grant_type'] );
				$test->assertSame( '4/0AX4XfWbouncedCode', $body['code'] );
				$test->assertSame( 'verifierVerifierVerifierVerifierVerifier0123456789', $body['code_verifier'] );
				$test->assertSame( self::RELAY . '/v1/callback', $body['redirect_uri'], 'The exchange names the relay callback Google saw.' );
				$test->assertSame( self::SITE_ID, $body['site_id'] );
				$test->assertSame( 'site-secret-1', $body['site_secret'] );
				$test->assertArrayNotHasKey( 'client_secret', $body );
				return self::google_through_relay(
					200,
					array(
						'access_token'  => 'ya29.bounced',
						'expires_in'    => 3599,
						'refresh_token' => '1//bounced',
						'scope'         => 'https://www.googleapis.com/auth/webmasters openid email',
						'token_type'    => 'Bearer',
						'id_token'      => self::fake_id_token( 'owner@example.com' ),
					)
				);
			}
		);
		$this->respond_to(
			'GET',
			'https://www.googleapis.com/webmasters/v3/sites',
			self::http( 200, array( 'siteEntry' => array( array( 'siteUrl' => trailingslashit( home_url( '/' ) ), 'permissionLevel' => 'siteOwner' ) ) ) )
		);

		$location = $this->capture_redirect( array( new GSC_Admin(), 'oauth_callback' ) );

		$this->assertSame( 'oauth-connected', self::notice_of( $location ) );
		$this->assertTrue( Credentials::is_connected() );
		$this->assertSame( '1//bounced', Credentials::refresh_token() );
		$this->assertSame( 'ya29.bounced', Credentials::access_token() );
		$this->assertSame( 'owner@example.com', Credentials::public_status()['google_account'] );
		$this->assertFalse( get_transient( 'convertrack_gsc_oauth_state_' . $user ), 'The state is single-use.' );
		$this->assertSame( 1, (int) Settings::get( 'enabled' ), 'Connecting turns the monitor on.' );
		$this->assertCount( 1, $this->requests_to( self::RELAY . '/v1/token' ) );
		$this->assertCount( 0, $this->requests_to( 'https://oauth2.googleapis.com' ), 'Relay mode never posts to Google\'s token endpoint itself.' );
	}

	public function test_the_callback_rejects_a_state_it_did_not_mint() {
		$user = $this->as_admin();
		Credentials::set_auth_mode( Credentials::MODE_RELAY );
		Credentials::store_site_registration( self::SITE_ID, 'site-secret-1', self::RELAY );
		set_transient( 'convertrack_gsc_oauth_state_' . $user, array( 'state' => 'expected-state-0123456789', 'verifier' => 'verifierVerifierVerifierVerifierVerifier0123456789' ), 600 );
		$_GET = array( 'code' => '4/0AX4XfWbouncedCode', 'state' => 'someone-elses-state-0123456789' );

		$location = $this->capture_redirect( array( new GSC_Admin(), 'oauth_callback' ) );

		$this->assertSame( 'oauth-error', self::notice_of( $location ) );
		$this->assertFalse( Credentials::is_connected() );
		$this->assertCount( 0, $this->requests, 'A bad state never reaches the relay.' );
	}

	/* ------------------------------------------------------- direct mode */

	public function test_direct_mode_still_exchanges_the_code_with_google_using_the_owner_client() {
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::set_auth_mode( Credentials::MODE_DIRECT );

		$test = $this;
		$this->respond_to(
			'POST',
			'https://oauth2.googleapis.com/token',
			function ( $url, $args ) use ( $test ) {
				$body = $args['body'];
				$test->assertSame( 'owner-client-id', $body['client_id'] );
				$test->assertSame( 'owner-client-secret', $body['client_secret'] );
				$test->assertSame( OAuth::redirect_uri(), $body['redirect_uri'] );
				$test->assertSame( 'direct-verifier', $body['code_verifier'] );
				$test->assertArrayNotHasKey( 'site_secret', $body );
				return self::http( 200, array( 'access_token' => 'ya29.direct', 'expires_in' => 3599, 'refresh_token' => '1//direct', 'scope' => 'https://www.googleapis.com/auth/webmasters', 'token_type' => 'Bearer' ) );
			}
		);

		$this->assertTrue( OAuth::exchange_code( 'direct-code', 'direct-verifier', OAuth::redirect_uri() ) );
		$this->assertTrue( Credentials::is_connected() );
		$this->assertSame( '1//direct', Credentials::refresh_token() );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 0, $this->requests_to( self::RELAY ) );
	}

	public function test_direct_mode_invalid_grant_still_clears_tokens_and_a_5xx_does_not() {
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::store_connection( 'ya29.direct', 3600, 'scope', '1//direct' );
		$this->assertSame( 'direct', Credentials::auth_mode() );

		$this->respond_to( 'POST', 'https://oauth2.googleapis.com/token', self::http( 503, array( 'error' => 'internal_failure' ) ) );
		$result = OAuth::refresh_token();
		$this->assertWPError( $result );
		$this->assertSame( 'convertrack_gsc_refresh_failed', $result->get_error_code() );
		$this->assertSame( '1//direct', Credentials::refresh_token() );

		$this->responders = array();
		$this->respond_to( 'POST', 'https://oauth2.googleapis.com/token', self::http( 400, array( 'error' => 'invalid_grant' ) ) );
		$result = OAuth::refresh_token();
		$this->assertSame( 'convertrack_gsc_reconnect_required', $result->get_error_code() );
		$this->assertSame( '', Credentials::refresh_token() );
	}

	/* ------------------------------------------------------- mode switching */

	public function test_switching_modes_revokes_the_old_grant_and_keeps_both_sets_of_credentials() {
		$this->as_admin();
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::store_connection( 'ya29.direct', 3600, 'scope', '1//direct', 'owner@example.com' );
		$this->assertSame( 'direct', Credentials::auth_mode() );

		$this->respond_to( 'POST', 'https://oauth2.googleapis.com/revoke', self::http( 200, array() ) );
		$this->expect_registration( 'site-secret-1' );

		$_POST['convertrack_gsc_mode'] = 'relay';
		$_REQUEST['_wpnonce']          = wp_create_nonce( 'convertrack_gsc_switch_mode' );
		$location                      = $this->capture_redirect( array( new GSC_Admin(), 'switch_mode' ) );

		$this->assertStringStartsWith( self::RELAY . '/v1/authorize?', $location, 'Switching to Sign in with Google goes straight into the sign-in.' );
		$this->assertSame( 'relay', Credentials::auth_mode() );
		$this->assertFalse( Credentials::is_connected(), 'Tokens of the old client are gone.' );
		$this->assertCount( 1, $this->requests_to( 'https://oauth2.googleapis.com/revoke' ) );
		$this->assertTrue( Credentials::has_site_registration() );
		$this->assertSame( 'owner-client-id', Credentials::client_id(), 'The owner client is kept for a one-click revert.' );
		$this->assertSame( 'owner-client-secret', Credentials::client_secret() );

		$_POST['convertrack_gsc_mode'] = 'direct';
		$_REQUEST['_wpnonce']          = wp_create_nonce( 'convertrack_gsc_switch_mode' );
		$location                      = $this->capture_redirect( array( new GSC_Admin(), 'switch_mode' ) );

		$this->assertSame( 'mode-direct', self::notice_of( $location ) );
		$this->assertSame( 'direct', Credentials::auth_mode() );
		$this->assertTrue( Credentials::has_client() );
		$this->assertTrue( Credentials::has_site_registration(), 'The relay registration is kept too.' );
	}

	public function test_enable_after_connect_turns_the_monitor_on_and_leaves_keyword_insights_alone() {
		$keywords_before = Keywords_Settings::get( 'enabled' );
		$this->assertSame( 0, (int) Settings::get( 'enabled' ) );

		$admin = new GSC_Admin();
		$this->assertTrue( $admin->enable_after_connect() );
		$this->assertSame( 1, (int) Settings::get( 'enabled' ) );
		$this->assertFalse( $admin->enable_after_connect(), 'Already on: nothing to do.' );
		$this->assertSame( $keywords_before, Keywords_Settings::get( 'enabled' ) );
	}
	/* ---------------------------------------------------------- the screen */

	private function render_screen() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		include CONVERTRACK_DIR . 'admin/views/gsc-index-monitor.php';
		return ob_get_clean();
	}

	public function test_the_screen_leads_with_sign_in_with_google_on_a_fresh_install() {
		$this->as_admin();
		$html = $this->render_screen();

		$this->assertStringContainsString( 'id="convertrack-gsc-setup"', $html );
		$this->assertSame( 1, substr_count( $html, 'class="is-current"' ), 'One setup step in relay mode.' );
		$this->assertStringNotContainsString( 'Create OAuth client', $html );
		$this->assertStringContainsString( 'cvtrk-google-signin', $html );
		$this->assertMatchesRegularExpression( '#cvtrk-google-signin" href="[^"]*action=convertrack_gsc_oauth_start[^"]*">.*?Sign in with Google\s*</a>#s', $html );
		$this->assertStringContainsString( 'action=convertrack_gsc_oauth_start', $html );
		$this->assertStringContainsString( 'Advanced: use your own Google Cloud credentials', $html );
		$this->assertStringContainsString( 'name="convertrack_gsc_mode" value="direct"', $html );
		$this->assertStringNotContainsString( 'id="cvtrk-gsc-client-id"', $html, 'The credentials form is not shown while relay mode is active.' );
		$this->assertStringNotContainsString( 'id="convertrack-gsc-monitor-off"', $html );
	}

	public function test_the_screen_shows_a_direct_mode_site_its_credentials_a_switch_and_the_monitoring_off_state() {
		$this->as_admin();
		Credentials::store_client( 'owner-client-id', 'owner-client-secret' );
		Credentials::store_connection( 'ya29.direct', 3600, 'scope', '1//direct', 'owner@example.com' );
		$html = $this->render_screen();

		$this->assertStringContainsString( 'Connected as owner@example.com', $html );
		$this->assertStringContainsString( 'Monitoring off', $html );
		$this->assertStringContainsString( 'id="convertrack-gsc-monitor-off"', $html );
		$this->assertStringContainsString( 'value="convertrack_gsc_enable_monitor"', $html );
		$this->assertStringContainsString( 'name="convertrack_gsc_mode" value="relay"', $html );
		$this->assertStringContainsString( 'Switch to Sign in with Google', $html );
		$this->assertStringContainsString( 'id="cvtrk-gsc-client-id"', $html );
		$this->assertStringContainsString( 'value="owner-client-id"', $html );
		$this->assertStringContainsString( 'id="convertrack-gsc-advanced" open', $html, 'Advanced is open when it is the active mode.' );
		$this->assertStringContainsString( 'with your own Google Cloud credentials', $html );
		$this->assertStringContainsString( 'id="convertrack-gsc-settings"', $html );

		( new GSC_Admin() )->enable_after_connect();
		$html = $this->render_screen();
		$this->assertStringNotContainsString( 'id="convertrack-gsc-monitor-off"', $html );
		$this->assertStringNotContainsString( 'Monitoring off', $html );
	}

	public function test_a_build_without_a_relay_offers_only_own_credentials() {
		$this->as_admin();
		remove_filter( 'convertrack_gsc_relay_url', array( $this, 'relay_url' ) );
		$this->assertFalse( Relay::is_configured(), 'The shipped placeholder is not a relay.' );
		$this->assertSame( 'direct', Credentials::auth_mode(), 'Nothing to relay through: the fresh install reads as direct.' );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'Create OAuth client', $html );
		$this->assertStringContainsString( 'id="cvtrk-gsc-client-id"', $html );
		$this->assertStringContainsString( 'id="convertrack-gsc-advanced" open', $html );
		$this->assertStringNotContainsString( 'name="convertrack_gsc_mode" value="relay"', $html, 'No switch to a relay that does not exist.' );
		$this->assertStringNotContainsString( 'cvtrk-google-signin', $html );

		$start = Relay::register_site();
		$this->assertWPError( $start );
		$this->assertSame( 'convertrack_gsc_relay_not_configured', $start->get_error_code() );

		// An explicit choice still wins, and the screen then says why the button is dead.
		Credentials::set_auth_mode( Credentials::MODE_RELAY );
		$this->assertSame( 'relay', Credentials::auth_mode() );
		$html = $this->render_screen();
		$this->assertStringContainsString( 'no sign-in service is configured', $html );
		$this->assertStringContainsString( 'name="convertrack_gsc_mode" value="direct"', $html );
	}
}
