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
