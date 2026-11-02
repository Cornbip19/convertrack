<?php

use Convertrack\Collector;
use Convertrack\Conversion_Kinds;
use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Rollup_Manager;
use Convertrack\Settings;

class Convertrack_Conversion_Kinds_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Real DDL, as in the hardening contracts test: temporary tables are
		// invisible to the SHOW TABLES contract check.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
		// The rollup commits its own transaction, which also commits the test
		// transaction around it, so rows from an earlier run or test can
		// survive. Start every test from empty analytics tables.
		global $wpdb;
		foreach ( array( Database::events_table(), Database::daily_table(), Database::daily_kinds_table(), Database::rollup_state_table(), Database::rollup_stage_table(), Database::sessions_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM $table" );
		}
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
		Settings::save(
			array(
				'enabled'              => 1,
				'conversion_selectors' => ".book-now\n.call-link",
				'conversion_urls'      => 'prefix:/thank-you',
			)
		);
	}

	public function test_classification_prefers_the_contact_scheme_over_the_matched_rule() {
		$this->assertSame( array( 'call', 'tel:' ), Conversion_Kinds::classify_click( 'tel:+15551234567', '.call-link' ) );
		$this->assertSame( array( 'email', 'mailto:' ), Conversion_Kinds::classify_click( 'MailTo:hello@example.test', '.call-link' ) );
		$this->assertSame( array( 'sms', 'sms:' ), Conversion_Kinds::classify_click( 'sms:+1555', '' ) );
		$this->assertSame( array( 'outbound', '@url' ), Conversion_Kinds::classify_click( 'https://partner.example/book', '@url' ) );
		$this->assertSame( array( 'rule', '.book-now' ), Conversion_Kinds::classify_click( '', '.book-now' ) );
		$this->assertSame( array( 'page', 'prefix:/thank-you' ), Conversion_Kinds::classify_pageview( 'prefix:/thank-you' ) );
		$this->assertSame( 'other', Conversion_Kinds::normalize_kind( '' ) );
		$this->assertSame( 'other', Conversion_Kinds::normalize_kind( 'made-up' ) );
	}

	public function test_labels_follow_settings_then_defaults_then_button_text() {
		$this->assertSame( 'Call', Conversion_Kinds::label( 'call', 'tel:' ) );
		$this->assertSame( 'Book now', Conversion_Kinds::label( 'rule', '.book-now', 'Book now' ) );
		$this->assertSame( '.book-now', Conversion_Kinds::label( 'rule', '.book-now', '' ) );
		$this->assertSame( 'Reached /thank-you', Conversion_Kinds::label( 'page', 'prefix:/thank-you' ) );
		$this->assertSame( 'Other conversion', Conversion_Kinds::label( '', '' ) );

		$this->assertSame(
			"tel: | Call us\n.book-now | Book online",
			Conversion_Kinds::sanitize_labels( "tel: | Call us\n<b>.book-now</b> | Book <i>online</i>\nno-separator\n | empty goal\n.BOOK-NOW | duplicate" )
		);

		Settings::save(
			array(
				'enabled'                => 1,
				'conversion_selectors'   => ".book-now\n.call-link",
				'conversion_kind_labels' => "tel: | Call us\n.book-now | Book online",
			)
		);
		$this->assertSame( 'Call us', Conversion_Kinds::label( 'call', 'tel:' ) );
		$this->assertSame( 'Book online', Conversion_Kinds::label( 'rule', '.book-now', 'Book now' ) );
		$this->assertSame( 'Book online', Conversion_Kinds::label( 'rule', '.BOOK-NOW', 'Book now' ) );
	}

	public function test_collector_stores_the_kind_and_matched_rule_for_every_conversion() {
		global $wpdb;

		$visitor = wp_generate_uuid4();
		$session = wp_generate_uuid4();
		$click   = function ( $overrides ) {
			return array_merge(
				array(
					't'    => 'click',
					'url'  => home_url( '/' ),
					'tag'  => 'a',
					'cls'  => '',
					'sel'  => '',
					'href' => '',
					'txt'  => '',
					'conv' => 0,
					'goal' => '',
					'eid'  => wp_generate_uuid4(),
				),
				$overrides
			);
		};
		$payload = array(
			'vid'    => $visitor,
			'sid'    => $session,
			'events' => array(
				$click( array( 'cls' => 'call-link', 'sel' => 'a.call-link', 'href' => 'tel:+15551234567', 'txt' => 'Call now', 'conv' => 1, 'goal' => '.call-link' ) ),
				$click( array( 'cls' => 'call-link', 'sel' => 'a.call-link', 'href' => 'mailto:hello@example.test', 'txt' => 'Email us', 'conv' => 1, 'goal' => '.call-link' ) ),
				$click( array( 'tag' => 'button', 'cls' => 'book-now', 'sel' => 'button.book-now', 'txt' => 'Book now', 'conv' => 1, 'goal' => '.book-now' ) ),
				$click( array( 'cls' => 'learn', 'sel' => 'a.learn', 'href' => '/about', 'txt' => 'Learn more' ) ),
				array( 't' => 'pageview', 'url' => home_url( '/thank-you/' ), 'eid' => wp_generate_uuid4() ),
			),
		);

		$result = Collector::collect( $payload );
		$this->assertIsArray( $result );
		$this->assertSame( 5, (int) $result['stored'] );

		$events = Database::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT event_type, is_conversion, conversion_kind, conversion_goal FROM $events ORDER BY id ASC", ARRAY_A );
		$this->assertSame(
			array(
				array( 'event_type' => 'click', 'is_conversion' => '1', 'conversion_kind' => 'call', 'conversion_goal' => 'tel:' ),
				array( 'event_type' => 'click', 'is_conversion' => '1', 'conversion_kind' => 'email', 'conversion_goal' => 'mailto:' ),
				array( 'event_type' => 'click', 'is_conversion' => '1', 'conversion_kind' => 'rule', 'conversion_goal' => '.book-now' ),
				array( 'event_type' => 'click', 'is_conversion' => '0', 'conversion_kind' => '', 'conversion_goal' => '' ),
				array( 'event_type' => 'pageview', 'is_conversion' => '1', 'conversion_kind' => 'page', 'conversion_goal' => 'prefix:/thank-you' ),
			),
			$rows
		);

		$recent = Database::recent_events( 1, 10 );
		$this->assertCount( 5, $recent );
		$labels = array_values( array_filter( array_map( function ( $row ) { return $row['kind_label']; }, $recent ) ) );
		sort( $labels );
		$this->assertSame( array( 'Book now', 'Call', 'Email', 'Reached /thank-you' ), $labels );
	}

	public function test_kinds_report_merges_rolled_up_days_with_today_and_flags_legacy_rows() {
		global $wpdb;

		$today     = Database::today();
		$yesterday = Database::date_days_ago( 1 );
		$visitor_a = wp_generate_uuid4();
		$visitor_b = wp_generate_uuid4();
		$event     = function ( $visitor, $session, $time, $kind, $goal, $text ) {
			return array(
				'event_id'        => wp_generate_uuid4(),
				'visitor_id'      => $visitor,
				'session_id'      => $session,
				'event_type'      => 'click',
				'post_id'         => 0,
				'page_url'        => '/',
				'page_title'      => 'Home',
				'element_tag'     => 'a',
				'element_text'    => $text,
				'element_selector'=> 'a.cta',
				'is_conversion'   => 1,
				'conversion_kind' => $kind,
				'conversion_goal' => $goal,
				'device_type'     => 'desktop',
				'source'          => 'Direct',
				'created_at'      => $time,
			);
		};
		$session_a1 = wp_generate_uuid4();
		$session_b1 = wp_generate_uuid4();
		$this->assertSame(
			3,
			Database::insert_events(
				array(
					$event( $visitor_a, $session_a1, $yesterday . ' 09:00:00', 'call', 'tel:', 'Call now' ),
					$event( $visitor_b, $session_b1, $yesterday . ' 10:00:00', 'call', 'tel:', 'Call now' ),
					$event( $visitor_b, $session_b1, $yesterday . ' 10:05:00', 'rule', '.book-now', 'Book now' ),
				)
			)
		);

		$rolled = Rollup_Manager::rollup_day( $yesterday, true );
		$this->assertIsArray( $rolled );
		$this->assertSame( 'complete', $rolled['status'] );

		$kinds_table = Database::daily_kinds_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT conversion_kind, conversion_goal, conversions, conversion_events FROM $kinds_table WHERE stat_date = %s ORDER BY conversion_kind", $yesterday ), ARRAY_A );
		$this->assertSame(
			array(
				array( 'conversion_kind' => 'call', 'conversion_goal' => 'tel:', 'conversions' => '2', 'conversion_events' => '2' ),
				array( 'conversion_kind' => 'rule', 'conversion_goal' => '.book-now', 'conversions' => '1', 'conversion_events' => '1' ),
			),
			$daily
		);

		// Today: one more call plus a row stored before kinds existed.
		$this->assertSame(
			2,
			Database::insert_events(
				array(
					$event( $visitor_a, wp_generate_uuid4(), $today . ' 00:01:00', 'call', 'tel:', 'Call now' ),
					$event( $visitor_a, wp_generate_uuid4(), $today . ' 00:02:00', '', '', 'Old button' ),
				)
			)
		);

		$report = Database::conversion_kinds( 7, 5 );
		$this->assertSame( 5, $report['total_events'] );
		$this->assertSame( 5, $report['total_sessions'] );
		$this->assertCount( 3, $report['kinds'] );

		$call = $report['kinds'][0];
		$this->assertSame( 'call', $call['kind'] );
		$this->assertSame( 'Call', $call['label'] );
		$this->assertSame( 'phone', $call['icon'] );
		$this->assertSame( 3, $call['conversion_events'] );
		$this->assertSame( 3, $call['conversions'] );
		$this->assertSame( 60.0, (float) $call['share'] );
		$this->assertCount( 3, $call['latest'] );
		$this->assertFalse( $call['latest_truncated'] );
		$this->assertSame( 'Call', $call['latest'][0]['kind_label'] );

		$by_kind = array();
		foreach ( $report['kinds'] as $kind ) {
			$by_kind[ $kind['kind'] ] = $kind;
		}
		$this->assertSame( 'Book now', $by_kind['rule']['label'] );
		$this->assertSame( '.book-now', $by_kind['rule']['goal'] );
		$this->assertSame( 'Other conversion', $by_kind['other']['label'] );
		$this->assertSame( 1, $by_kind['other']['conversion_events'] );

		// A latest-events cap marks the group as truncated.
		$capped = Database::conversion_kinds( 7, 1 );
		$this->assertCount( 1, $capped['kinds'][0]['latest'] );
		$this->assertTrue( $capped['kinds'][0]['latest_truncated'] );

		// Labels configured in settings reach the report.
		Settings::save(
			array(
				'enabled'                => 1,
				'conversion_selectors'   => ".book-now\n.call-link",
				'conversion_kind_labels' => '.book-now | Book online',
			)
		);
		$relabelled = Database::conversion_kinds( 7, 0 );
		$labels     = array();
		foreach ( $relabelled['kinds'] as $kind ) {
			$labels[ $kind['kind'] ] = $kind['label'];
		}
		$this->assertSame( 'Book online', $labels['rule'] );
		$this->assertSame( array(), $relabelled['kinds'][0]['latest'] );
	}
}
