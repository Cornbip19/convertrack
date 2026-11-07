<?php

use Convertrack\Collector;
use Convertrack\Database;
use Convertrack\Geo;
use Convertrack\Ingestion_Guard;
use Convertrack\Settings;
use Convertrack\Trust;

/**
 * 2.17: click location detail and authenticity scoring.
 */
class Convertrack_Click_Trust_Test extends WP_UnitTestCase {

	/** @var array Fake geolocation API body served to wp_remote_get. */
	private $api_body = array();

	/** @var int Number of geolocation API calls made. */
	private $api_calls = 0;

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
		global $wpdb;
		foreach ( array( Database::events_table(), Database::sessions_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM $table" );
		}
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
		$_SERVER['REMOTE_ADDR']     = '73.1.2.3';
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_REGION_CODE'], $_SERVER['HTTP_CF_IPCITY'] );
		Geo::reset();

		$this->api_calls = 0;
		$this->api_body  = array(
			'status'      => 'success',
			'countryCode' => 'US',
			'region'      => 'FL',
			'city'        => 'Miami',
			'isp'         => 'Comcast Cable Communications, LLC',
			'org'         => 'Comcast IP Services',
			'as'          => 'AS7922 Comcast Cable Communications, LLC',
			'mobile'      => false,
			'proxy'       => false,
			'hosting'     => false,
		);
		add_filter( 'pre_http_request', array( $this, 'fake_geo_api' ), 10, 3 );

		Settings::save(
			array(
				'enabled'              => 1,
				'enable_geo'           => 1,
				'geo_detail'           => 1,
				'trust_market'         => 'us-fl',
				'conversion_selectors' => '.call-link',
			)
		);
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_geo_api' ), 10 );
		Geo::reset();
		parent::tear_down();
	}

	public function fake_geo_api( $pre, $args, $url ) {
		if ( false === strpos( $url, 'ip-api.com' ) ) {
			return $pre;
		}
		$this->api_calls++;
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->api_body ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function human_signals( array $overrides = array() ) {
		return array_merge( array( 'pt' => 'touch', 'pd' => 1, 'ms' => 21000, 'ia' => 37, 'hf' => 1, 'wd' => 0 ), $overrides );
	}

	private function call_click( array $tq = null ) {
		$event = array(
			't'    => 'click',
			'url'  => home_url( '/' ),
			'tag'  => 'a',
			'cls'  => 'call-link',
			'sel'  => 'a.call-link',
			'href' => 'tel:+13055550100',
			'txt'  => 'Call now',
			'conv' => 1,
			'goal' => '.call-link',
			'dev'  => 'mobile',
			'eid'  => wp_generate_uuid4(),
		);
		if ( null !== $tq ) {
			$event['tq'] = $tq;
		}
		return $event;
	}

	private function collect( array $events ) {
		Geo::reset();
		return Collector::collect(
			array(
				'vid'    => wp_generate_uuid4(),
				'sid'    => wp_generate_uuid4(),
				'events' => $events,
			)
		);
	}

	private function stored_rows() {
		global $wpdb;
		$events = Database::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT event_type, country, region, city, network, asn, net_type, pointer_type, dwell_ms, trust_score, trust_flags FROM $events ORDER BY id ASC", ARRAY_A );
	}

	public function test_settings_sanitize_market_and_detail() {
		$this->assertSame( 'US-FL', Settings::get( 'trust_market' ) );
		$this->assertSame( 1, (int) Settings::get( 'geo_detail' ) );
		$this->assertSame( 'US,US-FL,CA', Trust::sanitize_market( "us; US-fl , bogus-value, CA, us" ) );
		$this->assertSame( '', Trust::sanitize_market( '<script>' ) );
	}

	public function test_a_local_human_call_click_is_real_and_located() {
		$result = $this->collect( array( $this->call_click( $this->human_signals() ), array( 't' => 'pageview', 'url' => home_url( '/' ), 'eid' => wp_generate_uuid4() ) ) );
		$this->assertSame( 2, (int) $result['stored'] );

		$rows = $this->stored_rows();
		$this->assertSame( 'click', $rows[0]['event_type'] );
		$this->assertSame( 'US', $rows[0]['country'] );
		$this->assertSame( 'FL', $rows[0]['region'] );
		$this->assertSame( 'Miami', $rows[0]['city'] );
		$this->assertSame( 'Comcast Cable Communications, LLC', $rows[0]['network'] );
		$this->assertSame( 'AS7922', $rows[0]['asn'] );
		$this->assertSame( 'isp', $rows[0]['net_type'] );
		$this->assertSame( 'touch', $rows[0]['pointer_type'] );
		$this->assertSame( '21000', $rows[0]['dwell_ms'] );
		$this->assertSame( '100', $rows[0]['trust_score'] );
		$this->assertSame( '', $rows[0]['trust_flags'] );

		// Pageviews carry the location but are never scored.
		$this->assertSame( 'pageview', $rows[1]['event_type'] );
		$this->assertSame( 'Miami', $rows[1]['city'] );
		$this->assertNull( $rows[1]['trust_score'] );

		$recent = Database::recent_events( 1, 10 );
		$click  = null;
		foreach ( $recent as $row ) {
			if ( 'click' === $row['type'] ) {
				$click = $row;
			}
		}
		$this->assertSame( 'real', $click['trust_verdict'] );
		$this->assertSame( 'FL', $click['region'] );
		$this->assertSame( array(), $click['trust_flags'] );

		// One API lookup per IP; the second batch is served from the cache.
		$this->collect( array( $this->call_click( $this->human_signals() ) ) );
		$this->assertSame( 1, $this->api_calls );
	}

	public function test_a_datacenter_bot_outside_the_market_is_suspicious() {
		$this->api_body = array_merge(
			$this->api_body,
			array( 'countryCode' => 'SG', 'region' => '01', 'city' => 'Singapore', 'isp' => 'DigitalOcean, LLC', 'as' => 'AS14061 DigitalOcean, LLC', 'hosting' => true )
		);
		$_SERVER['REMOTE_ADDR'] = '159.89.1.2';
		$this->collect( array( $this->call_click( array( 'pt' => '', 'pd' => 0, 'ms' => 800, 'ia' => 0, 'hf' => 1, 'wd' => 1 ) ) ) );

		$rows  = $this->stored_rows();
		$flags = explode( ',', $rows[0]['trust_flags'] );
		$this->assertSame( 'hosting', $rows[0]['net_type'] );
		$this->assertSame( '0', $rows[0]['trust_score'] );
		foreach ( array( 'automation', 'no_press', 'instant', 'no_activity', 'datacenter', 'outside_area' ) as $flag ) {
			$this->assertContains( $flag, $flags );
		}

		$quality = Database::conversion_quality( 1 );
		$this->assertSame( 1, $quality['total'] );
		$this->assertSame( 1, $quality['verdicts']['suspicious'] );
		$this->assertSame( 'Singapore', $quality['locations'][0]['city'] );
		$this->assertSame( 'hosting', $quality['networks'][0]['net_type'] );
	}

	public function test_out_of_state_mobile_click_is_check_and_keyboard_is_not_penalised() {
		$this->api_body = array_merge( $this->api_body, array( 'region' => 'GA', 'city' => 'Atlanta', 'isp' => 'T-Mobile USA, Inc.', 'mobile' => true ) );
		$this->collect(
			array(
				$this->call_click( $this->human_signals( array( 'pt' => 'keyboard', 'pd' => 0, 'ms' => 2500 ) ) ),
			)
		);
		$rows = $this->stored_rows();
		$this->assertSame( 'mobile', $rows[0]['net_type'] );
		// Keyboard activation carries no press and is not penalised for it.
		$this->assertSame( 'fast,outside_region', $rows[0]['trust_flags'] );
		$this->assertSame( (string) ( 100 - 10 - 15 ), $rows[0]['trust_score'] );
		$this->assertSame( 'real', Trust::verdict( $rows[0]['trust_score'] ) );
		$this->assertSame( 'check', Trust::verdict( 55 ) );
	}

	public function test_old_cached_tracker_without_signals_is_scored_on_network_only() {
		$this->collect( array( $this->call_click( null ) ) );
		$rows = $this->stored_rows();
		$this->assertSame( 'no_signals', $rows[0]['trust_flags'] );
		$this->assertSame( '100', $rows[0]['trust_score'] );
		$this->assertSame( '', $rows[0]['pointer_type'] );
	}

	public function test_detail_off_keeps_country_only_and_never_scores_location() {
		Settings::save(
			array(
				'enabled'              => 1,
				'enable_geo'           => 1,
				'geo_detail'           => 0,
				'conversion_selectors' => '.call-link',
			)
		);
		$this->collect( array( $this->call_click( $this->human_signals() ) ) );
		$rows = $this->stored_rows();
		$this->assertSame( 'US', $rows[0]['country'] );
		$this->assertSame( '', $rows[0]['region'] );
		$this->assertSame( '', $rows[0]['city'] );
		$this->assertSame( '', $rows[0]['network'] );
		$this->assertSame( '', $rows[0]['net_type'] );
		$this->assertSame( '100', $rows[0]['trust_score'] );
	}

	public function test_edge_headers_win_and_hostile_api_values_are_sanitized() {
		$_SERVER['HTTP_CF_IPCOUNTRY']   = 'US';
		$_SERVER['HTTP_CF_REGION_CODE'] = 'FL';
		$_SERVER['HTTP_CF_IPCITY']      = 'Fort%20Lauderdale';
		$this->api_body = array_merge( $this->api_body, array( 'countryCode' => 'CA', 'city' => '<img src=x onerror=alert(1)>Toronto', 'isp' => str_repeat( 'x', 300 ) ) );

		$profile = Geo::profile_for_ip( '73.1.2.3', true );
		$this->assertSame( 'US', $profile['country'] );
		$this->assertSame( 'FL', $profile['region'] );
		$this->assertSame( 'Fort Lauderdale', $profile['city'] );
		$this->assertSame( 100, strlen( $profile['network'] ) );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_REGION_CODE'], $_SERVER['HTTP_CF_IPCITY'] );
		$parsed = Geo::parse_api_body( array( 'countryCode' => 'CA', 'city' => '<img src=x onerror=alert(1)>Toronto', 'as' => 'nonsense' ) );
		$this->assertSame( 'Toronto', $parsed['city'] );
		$this->assertSame( '', $parsed['asn'] );
		$this->assertSame( '', $parsed['net_type'] );
	}
}
