<?php

use Convertrack\Dashboard_Widget;
use Convertrack\Database;
use Convertrack\Ingestion_Guard;

/**
 * The WordPress dashboard widget: consistent figures, one bar per day with
 * today marked as partial, readable top pages with conversions.
 */
class Convertrack_Dashboard_Widget_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
		global $wpdb;
		foreach ( array( Database::events_table(), Database::daily_table(), Database::daily_kinds_table(), Database::rollup_state_table(), Database::rollup_stage_table(), Database::sessions_table(), Database::session_days_table(), Database::visitor_days_table(), Database::sources_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM $table" );
		}
		// The widget caches its payload; a stale one from another test would
		// mask what this test inserts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_convertrack_dw_%' OR option_name LIKE '_transient_timeout_convertrack_dw_%'" );
	}

	private function event( $visitor, $session, $post_id, $url, $type, $created_at, array $extra = array() ) {
		return array_merge(
			array(
				'event_id'      => wp_generate_uuid4(),
				'visitor_id'    => $visitor,
				'session_id'    => $session,
				'event_type'    => $type,
				'post_id'       => $post_id,
				'page_url'      => $url,
				'page_title'    => '',
				'element_tag'   => 'click' === $type ? 'a' : '',
				'element_text'  => 'click' === $type ? 'Book' : '',
				'element_selector' => 'click' === $type ? 'a#book' : '',
				'is_conversion' => 0,
				'device_type'   => 'mobile',
				'source'        => 'Direct',
				'created_at'    => $created_at,
			),
			$extra
		);
	}

	public function test_payload_and_markup_show_todays_figures_bars_and_top_pages() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Booking page' ) );
		$url     = get_permalink( $post_id );
		$today   = Database::today();
		$a       = wp_generate_uuid4();
		$b       = wp_generate_uuid4();
		$sa      = wp_generate_uuid4();
		$sb      = wp_generate_uuid4();
		$rows    = array(
			$this->event( $a, $sa, $post_id, $url, 'pageview', $today . ' 08:00:00' ),
			$this->event( $a, $sa, $post_id, $url, 'click', $today . ' 08:01:00', array( 'is_conversion' => 1, 'conversion_kind' => 'call', 'conversion_goal' => 'tel:' ) ),
			$this->event( $b, $sb, $post_id, $url, 'pageview', $today . ' 09:00:00' ),
			$this->event( $b, $sb, 0, '/about/', 'pageview', $today . ' 09:05:00' ),
			$this->event( $b, $sb, 0, '/about/', 'click', $today . ' 09:06:00' ),
		);
		$this->assertSame( count( $rows ), Database::insert_events( $rows ) );

		$data = Dashboard_Widget::payload();
		$this->assertTrue( $data['has_data'] );
		$this->assertSame( 3, $data['pageviews'] );
		$this->assertSame( 2, $data['clicks'] );
		$this->assertSame( 1, $data['conversions'], 'Conversions are conversion events, as on every plugin screen.' );
		$this->assertSame( 2, $data['unique_visitors'] );
		$this->assertSame( $today, $data['today'] );
		$this->assertCount( Dashboard_Widget::RANGE_DAYS, $data['series'] );
		$last = $data['series'][ count( $data['series'] ) - 1 ];
		$this->assertSame( $today, $last['date'] );
		$this->assertSame( 3, $last['pageviews'] );
		$this->assertSame( 2, $last['visitors'] );
		$this->assertSame( 1, $last['conversions'] );
		$this->assertNotEmpty( $data['top_pages'] );
		$booking = null;
		foreach ( $data['top_pages'] as $row ) {
			if ( 'Booking page' === $row['title'] ) {
				$booking = $row;
			}
		}
		$this->assertNotNull( $booking, 'The post is listed under its real title.' );
		$this->assertSame( 2, $booking['pageviews'] );
		$this->assertSame( 1, $booking['conversions'] );
		$this->assertStringStartsWith( '/', $booking['path'] );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		ob_start();
		Dashboard_Widget::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'cvtrk-dw-context', $html );
		$this->assertStringContainsString( 'online now', $html );
		$this->assertStringContainsString( 'cvtrk-dw-kpi-sub', $html );
		$this->assertStringContainsString( 'cvtrk-dw-bars', $html );
		$this->assertSame( Dashboard_Widget::RANGE_DAYS, substr_count( $html, 'cvtrk-dw-bar-col' ), 'One bar per day.' );
		$this->assertStringContainsString( 'is-today', $html, 'Today is drawn as the partial day.' );
		$this->assertStringContainsString( 'is-peak', $html );
		$this->assertStringContainsString( 'Booking page', $html );
		$this->assertStringContainsString( 'cvtrk-dw-conv is-some', $html, 'A page with conversions gets the green count.' );
		$this->assertStringContainsString( 'View full dashboard', $html );
		$this->assertStringNotContainsString( 'cvtrk-dw-spark', $html, 'The old sparkline is gone.' );
	}

	public function test_empty_site_shows_the_empty_state() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$data = Dashboard_Widget::payload();
		$this->assertFalse( $data['has_data'] );
		ob_start();
		Dashboard_Widget::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'No activity recorded yet', $html );
		$this->assertStringNotContainsString( 'cvtrk-dw-bars', $html );
	}
}
