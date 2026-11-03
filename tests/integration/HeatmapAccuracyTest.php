<?php

use Convertrack\Collector;
use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Rest_Controller;

/**
 * Heatmap figures only count clicks a person made, report visitors next to
 * clicks, and can open on the device that holds the most data.
 */
class Convertrack_Heatmap_Accuracy_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
	}

	private function event( $visitor, $session, $post_id, $url, $type, $device, array $extra = array() ) {
		return array_merge(
			array(
				'event_id'         => wp_generate_uuid4(),
				'visitor_id'       => $visitor,
				'session_id'       => $session,
				'event_type'       => $type,
				'post_id'          => $post_id,
				'page_key'         => '',
				'object_type'      => '',
				'object_id'        => 0,
				'page_url'         => $url,
				'page_title'       => 'Sample',
				'element_tag'      => '',
				'element_id'       => '',
				'element_classes'  => '',
				'element_text'     => '',
				'element_selector' => '',
				'element_href'     => '',
				'is_conversion'    => 0,
				'conversion_kind'  => '',
				'conversion_goal'  => '',
				'device_type'      => $device,
				'country'          => '',
				'source'           => 'Direct',
				'referrer_host'    => '',
				'utm_source'       => '',
				'utm_medium'       => '',
				'utm_campaign'     => '',
				'utm_term'         => '',
				'search_keyword'   => '',
				'search_source'    => '',
				'heatmap_selector' => '',
				'pos_x'            => 0,
				'pos_y'            => 0,
				'rel_x'            => 0,
				'rel_y'            => 0,
				'viewport_w'       => 390,
				'viewport_h'       => 800,
				'document_w'       => 390,
				'document_h'       => 2000,
				'scroll_x'         => 0,
				'scroll_y'         => 0,
				'scroll_depth'     => 0,
				'created_at'       => current_time( 'mysql' ),
			),
			$extra
		);
	}

	private function element_row( array $elements, $selector ) {
		foreach ( $elements as $row ) {
			if ( $row['selector'] === $selector ) {
				return $row;
			}
		}
		return null;
	}

	public function test_heatmap_counts_only_person_made_clicks_and_reports_visitors() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url     = get_permalink( $post_id );
		$a       = wp_generate_uuid4();
		$b       = wp_generate_uuid4();
		$c       = wp_generate_uuid4();
		$sa      = wp_generate_uuid4();
		$sb      = wp_generate_uuid4();
		$sc      = wp_generate_uuid4();

		$book = array( 'element_tag' => 'a', 'element_text' => 'Book now', 'element_selector' => 'a#book', 'heatmap_selector' => 'main>a:nth-of-type(1)' );
		$rows = array(
			$this->event( $a, $sa, $post_id, $url, 'pageview', 'mobile' ),
			// A real tap on the booking link, with page and element coordinates.
			$this->event( $a, $sa, $post_id, $url, 'click', 'mobile', $book + array( 'pos_x' => 300, 'pos_y' => 400, 'rel_x' => 500, 'rel_y' => 500 ) ),
			// A real tap on plain text.
			$this->event( $a, $sa, $post_id, $url, 'heatmap_click', 'mobile', array( 'element_tag' => 'p', 'heatmap_selector' => 'main>p', 'pos_x' => 120, 'pos_y' => 900 ) ),
			// A script-generated click on a chat widget: no position.
			$this->event( $a, $sa, $post_id, $url, 'heatmap_click', 'mobile', array( 'element_tag' => 'chatwidget', 'heatmap_selector' => 'body>chat-widget' ) ),
			$this->event( $b, $sb, $post_id, $url, 'pageview', 'desktop' ),
			// Keyboard activation of the booking link: a click without a position.
			$this->event( $b, $sb, $post_id, $url, 'click', 'desktop', $book ),
			$this->event( $c, $sc, $post_id, $url, 'pageview', 'mobile' ),
			$this->event( $c, $sc, $post_id, $url, 'heatmap_click', 'mobile', array( 'element_tag' => 'chatwidget', 'heatmap_selector' => 'body>chat-widget' ) ),
		);
		$this->assertSame( count( $rows ), Database::insert_events( $rows ) );

		$auto = Database::heatmap_data( $post_id, 7, 'auto' );
		$this->assertSame( 'mobile', $auto['device'], 'Auto should open the device with the most person-made clicks.' );
		$this->assertSame( array( 'desktop' => 1, 'tablet' => 0, 'mobile' => 2 ), $auto['devices'] );
		$this->assertSame( 2, $auto['clicks'] );
		$this->assertSame( 2, $auto['plotted'] );
		$this->assertSame( 0, $auto['unplotted'] );
		$this->assertSame( 1, $auto['visitors'] );
		$this->assertSame( 1, $auto['tracked_clicks'] );
		$this->assertSame( 2, $auto['pageviews'] );
		$this->assertSame( 2, $auto['pageview_visitors'] );
		$this->assertCount( 2, $auto['points'] );
		foreach ( $auto['points'] as $point ) {
			$this->assertSame( 1, $point['v'] );
			$this->assertNotSame( 'body>chat-widget', $point['selector'] );
		}
		$this->assertCount( 2, $auto['elements'] );
		$this->assertNull( $this->element_row( $auto['elements'], 'body>chat-widget' ), 'Script-generated clicks must not appear as a clicked element.' );
		$booking = $this->element_row( $auto['elements'], 'main>a:nth-of-type(1)' );
		$this->assertSame( 'Book now', $booking['label'] );
		$this->assertSame( 1, $booking['clicks'] );
		$this->assertSame( 1, $booking['visitors'] );

		$all = Database::heatmap_data( $post_id, 7, 'all' );
		$this->assertSame( 'all', $all['device'] );
		$this->assertSame( 3, $all['clicks'] );
		$this->assertSame( 2, $all['plotted'] );
		$this->assertSame( 1, $all['unplotted'] );
		$this->assertSame( 2, $all['visitors'] );
		$this->assertSame( 3, $all['pageview_visitors'] );
		$this->assertSame( 2, $all['tracked_clicks'] );
		$booking_all = $this->element_row( $all['elements'], 'main>a:nth-of-type(1)' );
		$this->assertSame( 2, $booking_all['clicks'] );
		$this->assertSame( 2, $booking_all['visitors'] );

		$desktop = Database::heatmap_data( $post_id, 7, 'desktop' );
		$this->assertSame( 1, $desktop['clicks'], 'A keyboard click on a real control still counts.' );
		$this->assertSame( 0, $desktop['plotted'] );
		$this->assertSame( array(), $desktop['points'] );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/heatmap' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'range', 7 );
		$request->set_param( 'device', 'auto' );
		$data = ( new Rest_Controller() )->stats_heatmap( $request )->get_data();
		$this->assertSame( 'mobile', $data['device'] );
		$this->assertSame( 2, $data['devices']['mobile'] );
		$this->assertSame( 1, $data['visitors'] );
	}

	public function test_heatmap_insights_cover_fold_scroll_dead_and_repeated_clicks() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url     = get_permalink( $post_id );
		$base    = current_time( 'timestamp' ) - 300;
		$at      = function ( $offset ) use ( $base ) {
			return gmdate( 'Y-m-d H:i:s', $base + $offset );
		};
		$book = array( 'element_tag' => 'a', 'element_text' => 'Book now', 'element_selector' => 'a#book', 'heatmap_selector' => 'main>a:nth-of-type(1)', 'rel_x' => 500, 'rel_y' => 500 );
		$rows = array();
		// Two visitors each click the booking link four times within five seconds,
		// above the fold (page Y 300/1000 of a 2000px page = 600px, viewport 800).
		foreach ( array( array( 'a', 'sa' ), array( 'b', 'sb' ) ) as $pair ) {
			$visitor = wp_generate_uuid4();
			$session = wp_generate_uuid4();
			$rows[]  = $this->event( $visitor, $session, $post_id, $url, 'pageview', 'mobile', array( 'created_at' => $at( 0 ) ) );
			for ( $i = 0; $i < 4; $i++ ) {
				$rows[] = $this->event( $visitor, $session, $post_id, $url, 'click', 'mobile', $book + array( 'pos_x' => 300, 'pos_y' => 300, 'created_at' => $at( 10 + $i ) ) );
			}
			$rows[] = $this->event( $visitor, $session, $post_id, $url, 'scroll', 'mobile', array( 'scroll_depth' => 60, 'created_at' => $at( 20 ) ) );
		}
		// Two more visitors click a hero image that is not clickable, below the fold.
		foreach ( array( 1, 2 ) as $n ) {
			$visitor = wp_generate_uuid4();
			$session = wp_generate_uuid4();
			$rows[]  = $this->event( $visitor, $session, $post_id, $url, 'pageview', 'mobile', array( 'created_at' => $at( 0 ) ) );
			$rows[]  = $this->event( $visitor, $session, $post_id, $url, 'heatmap_click', 'mobile', array( 'element_tag' => 'img', 'heatmap_selector' => 'main>section:nth-of-type(2)>img', 'pos_x' => 500, 'pos_y' => 700, 'created_at' => $at( 30 ) ) );
			if ( 1 === $n ) {
				$rows[] = $this->event( $visitor, $session, $post_id, $url, 'heatmap_click', 'mobile', array( 'element_tag' => 'img', 'heatmap_selector' => 'main>section:nth-of-type(2)>img', 'pos_x' => 505, 'pos_y' => 705, 'created_at' => $at( 40 ) ) );
			}
			$rows[] = $this->event( $visitor, $session, $post_id, $url, 'scroll', 'mobile', array( 'scroll_depth' => 1 === $n ? 100 : 30, 'created_at' => $at( 50 ) ) );
		}
		// Two scroll-only visitors: one reads everything, one leaves early.
		foreach ( array( 100, 20 ) as $depth ) {
			$rows[] = $this->event( wp_generate_uuid4(), wp_generate_uuid4(), $post_id, $url, 'scroll', 'mobile', array( 'scroll_depth' => $depth, 'created_at' => $at( 60 ) ) );
		}
		$this->assertSame( count( $rows ), Database::insert_events( $rows ) );

		$d = Database::heatmap_data( $post_id, 7, 'mobile' );
		$this->assertSame( 11, $d['clicks'] );
		$this->assertSame( 8, $d['fold']['above'], 'Eight booking clicks sit within the first screen.' );
		$this->assertSame( 11, $d['fold']['known'] );
		$this->assertSame( 73, (int) $d['fold']['share'] );
		// Scroll samples: 60, 60, 100, 30, 100, 20 -> at least half reach 60%.
		$this->assertSame( 6, $d['scroll_samples'] );
		$this->assertSame( 60, $d['scroll_half'] );

		$this->assertCount( 1, $d['dead_clicks'] );
		$this->assertSame( 'main>section:nth-of-type(2)>img', $d['dead_clicks'][0]['selector'] );
		$this->assertSame( 'img', $d['dead_clicks'][0]['tag'] );
		$this->assertSame( 3, $d['dead_clicks'][0]['clicks'] );
		$this->assertSame( 2, $d['dead_clicks'][0]['visitors'] );

		$this->assertCount( 1, $d['rage_clicks'] );
		$this->assertSame( 'main>a:nth-of-type(1)', $d['rage_clicks'][0]['selector'] );
		$this->assertSame( 'Book now', $d['rage_clicks'][0]['label'] );
		$this->assertSame( 2, $d['rage_clicks'][0]['sessions'] );

		$booking = $this->element_row( $d['elements'], 'main>a:nth-of-type(1)' );
		$this->assertSame( 'control', $booking['kind'] );
		$this->assertSame( 50.0, (float) $booking['share'], 'Two of four viewing visitors clicked the booking link.' );
		$image = $this->element_row( $d['elements'], 'main>section:nth-of-type(2)>img' );
		$this->assertSame( 'content', $image['kind'] );

		$kinds = wp_list_pluck( $d['insights'], 'kind' );
		$this->assertSame( array( 'top', 'fold', 'scroll', 'dead', 'rage' ), $kinds, 'Five findings in a fixed order; the device mix is only added when nothing else fills the list.' );
		$this->assertSame( 'Book now', $d['insights'][0]['label'] );
		$this->assertSame( 60, $d['insights'][2]['band'] );

		$quiet = Database::heatmap_data( $post_id, 7, 'desktop' );
		$this->assertCount( 1, $quiet['insights'], 'With no desktop clicks the only finding is where the clicks are.' );
		$this->assertSame( 'device', $quiet['insights'][0]['kind'] );
		$this->assertSame( 'mobile', $quiet['insights'][0]['device'] );
		$this->assertSame( 100, $quiet['insights'][0]['share'] );
		$this->assertSame( 0, $quiet['scroll_half'] );
		$this->assertNull( $quiet['fold']['share'] );
	}

	public function test_snapshot_is_cached_per_device_and_refreshable() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$fetches = 0;
		$mock    = function () use ( &$fetches ) {
			$fetches++;
			return array(
				'headers'  => array(),
				'body'     => '<html><head><title>Snap</title></head><body><script>alert(1)</script><p>Hello</p></body></html>',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $mock );

		$controller = new Rest_Controller();
		$call       = function ( $device, $refresh = 0 ) use ( $controller, $post_id ) {
			$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/heatmap-snapshot' );
			$request->set_param( 'post', $post_id );
			$request->set_param( 'device', $device );
			$request->set_param( 'refresh', $refresh );
			return $controller->stats_heatmap_snapshot( $request )->get_data();
		};

		$first = $call( 'mobile' );
		$this->assertFalse( $first['cached'] );
		$this->assertStringNotContainsString( '<script', $first['html'], 'Snapshots are script-free.' );
		$this->assertStringContainsString( '<p>Hello</p>', $first['html'] );
		$this->assertSame( 1, $fetches );

		$second = $call( 'mobile' );
		$this->assertTrue( $second['cached'] );
		$this->assertSame( $first['html'], $second['html'] );
		$this->assertSame( 1, $fetches, 'The second request for the same device is served from the transient.' );

		$desktop = $call( 'desktop' );
		$this->assertFalse( $desktop['cached'] );
		$this->assertSame( 2, $fetches, 'Each device keeps its own snapshot.' );

		$refreshed = $call( 'mobile', 1 );
		$this->assertFalse( $refreshed['cached'] );
		$this->assertSame( 3, $fetches );

		remove_filter( 'pre_http_request', $mock );
	}

	public function test_collector_drops_positionless_heatmap_clicks_and_keeps_odd_tag_clicks_structurally() {
		$method = new ReflectionMethod( Collector::class, 'sanitize_event' );
		$method->setAccessible( true );
		$vid  = '00000000-0000-4000-8000-000000000001';
		$sid  = '00000000-0000-4000-8000-000000000002';
		$now  = current_time( 'mysql' );
		$base = array( 'url' => '/sample-page/', 'tag' => 'span', 'hsel' => 'main>section:nth-of-type(1)>span', 'dev' => 'mobile' );

		$ghost = $method->invokeArgs( null, array( $base + array( 't' => 'heatmap_click', 'cx' => 0, 'cy' => 0 ), $vid, $sid, $now ) );
		$this->assertNull( $ghost, 'A heatmap-only click without a position is not a visitor click.' );

		$real = $method->invokeArgs( null, array( $base + array( 't' => 'heatmap_click', 'cx' => 180, 'cy' => 640 ), $vid, $sid, $now ) );
		$this->assertSame( 'heatmap_click', $real['event_type'] );
		$this->assertSame( 180, $real['pos_x'] );
		$this->assertSame( 640, $real['pos_y'] );

		$odd = $method->invokeArgs(
			null,
			array(
				array(
					't' => 'click', 'url' => '/sample-page/', 'tag' => 'li', 'txt' => 'Menu item', 'sel' => 'ul>li:nth-of-type(2)',
					'hsel' => 'nav>ul>li:nth-of-type(2)', 'cx' => 30, 'cy' => 40, 'conv' => 1, 'goal' => '.cvtrk-convert', 'dev' => 'desktop',
				),
				$vid,
				$sid,
				$now,
			)
		);
		$this->assertSame( 'heatmap_click', $odd['event_type'], 'A tracked click on an unexpected tag is kept as a heatmap-only click.' );
		$this->assertSame( '', $odd['element_text'] );
		$this->assertSame( '', $odd['element_selector'] );
		$this->assertSame( 'nav>ul>li:nth-of-type(2)', $odd['heatmap_selector'] );
		$this->assertSame( 0, $odd['is_conversion'] );
		$this->assertSame( 30, $odd['pos_x'] );

		$keyboard = $method->invokeArgs( null, array( array( 't' => 'click', 'url' => '/sample-page/', 'tag' => 'a', 'txt' => 'Book', 'sel' => 'a#book', 'hsel' => 'main>a', 'cx' => 0, 'cy' => 0, 'dev' => 'desktop' ), $vid, $sid, $now ) );
		$this->assertSame( 'click', $keyboard['event_type'], 'Keyboard activation of a real control stays a click.' );
		$this->assertSame( 0, $keyboard['pos_x'] );
	}
}
