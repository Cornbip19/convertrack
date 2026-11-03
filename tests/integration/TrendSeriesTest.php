<?php

use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Rest_Controller;
use Convertrack\Rollup_Manager;
use Convertrack\Settings;

/**
 * The dashboard trend reads completed rollups, falls back to raw events for
 * days that were not rolled up, can return the previous window, and carries
 * dated notes about settings and plugin changes.
 */
class Convertrack_Trend_Series_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( true === Database::install() );
		$this->assertTrue( true === Ingestion_Guard::install() );
		delete_option( Database::NOTES_OPTION );
		// The rollup commits its own transaction, which also commits the test
		// transaction around it, so rows from an earlier test can survive.
		// Start from empty analytics tables.
		global $wpdb;
		foreach ( array( Database::events_table(), Database::daily_table(), Database::daily_kinds_table(), Database::rollup_state_table(), Database::rollup_stage_table(), Database::sessions_table(), Database::session_days_table(), Database::visitor_days_table(), Database::sources_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM $table" );
		}
	}

	private function event( $visitor, $session, $type, $created_at, array $extra = array() ) {
		return array_merge(
			array(
				'event_id'      => wp_generate_uuid4(),
				'visitor_id'    => $visitor,
				'session_id'    => $session,
				'event_type'    => $type,
				'post_id'       => 0,
				'page_url'      => '/trend-sample/',
				'page_title'    => 'Trend sample',
				'element_tag'   => 'click' === $type ? 'a' : '',
				'element_text'  => 'click' === $type ? 'Book' : '',
				'is_conversion' => 0,
				'device_type'   => 'mobile',
				'source'        => 'Direct',
				'created_at'    => $created_at,
			),
			$extra
		);
	}

	public function test_series_windows_and_raw_fallback_for_days_without_a_rollup() {
		$yesterday = Database::date_days_ago( 1 );
		$visitor_a = wp_generate_uuid4();
		$visitor_b = wp_generate_uuid4();
		$session_a = wp_generate_uuid4();
		$session_b = wp_generate_uuid4();
		$rows      = array(
			$this->event( $visitor_a, $session_a, 'pageview', $yesterday . ' 09:00:00' ),
			$this->event( $visitor_a, $session_a, 'click', $yesterday . ' 09:01:00', array( 'is_conversion' => 1, 'conversion_kind' => 'call', 'conversion_goal' => 'tel:' ) ),
			$this->event( $visitor_b, $session_b, 'pageview', $yesterday . ' 10:00:00' ),
		);
		$this->assertSame( 3, Database::insert_events( $rows ) );

		// No rollup has run for yesterday, so the series must read the raw rows
		// instead of reporting a zero day.
		$series = Database::clicks_timeseries( 3 );
		$this->assertSame( array( Database::date_days_ago( 2 ), $yesterday, Database::today() ), array_keys( $series ) );
		$this->assertSame( 2, $series[ $yesterday ]['pageviews'] );
		$this->assertSame( 1, $series[ $yesterday ]['clicks'] );
		$this->assertSame( 1, $series[ $yesterday ]['conversion_events'] );
		$this->assertSame( 1, $series[ $yesterday ]['conversions'] );
		$this->assertSame( 2, $series[ $yesterday ]['visitors'] );
		$this->assertSame( 0, $series[ Database::date_days_ago( 2 ) ]['pageviews'] );

		// The previous window ends the day before the current one starts.
		$previous = Database::clicks_timeseries( 3, 3 );
		$this->assertSame( array( Database::date_days_ago( 5 ), Database::date_days_ago( 4 ), Database::date_days_ago( 3 ) ), array_keys( $previous ) );
		foreach ( $previous as $row ) {
			$this->assertSame( 0, $row['pageviews'] );
		}

		// Once the day is rolled up, the completed rollup is the source and agrees.
		$rolled_up = Rollup_Manager::rollup_day( $yesterday, true );
		$this->assertFalse( is_wp_error( $rolled_up ), is_wp_error( $rolled_up ) ? $rolled_up->get_error_message() : '' );
		$rolled = Database::clicks_timeseries( 3 );
		$this->assertSame( 2, $rolled[ $yesterday ]['pageviews'] );
		$this->assertSame( 1, $rolled[ $yesterday ]['clicks'] );
		$this->assertSame( 1, $rolled[ $yesterday ]['conversion_events'] );
	}

	public function test_notes_are_recorded_for_setting_changes_and_updates_and_listed_by_date() {
		$this->assertTrue( Database::add_note( 'Hello' ) );
		$this->assertFalse( Database::add_note( 'Hello' ), 'The same note on the same day is stored once.' );
		$this->assertTrue( Database::add_note( 'Older', 'manual', Database::date_days_ago( 10 ) ) );

		$today = Database::today();
		$notes = Database::notes_between( $today, $today );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Hello', $notes[0]['text'] );
		$all = Database::notes_between( Database::date_days_ago( 30 ), $today );
		$this->assertSame( array( 'Older', 'Hello' ), wp_list_pluck( $all, 'text' ), 'Notes come back oldest first.' );

		$current = Settings::all();
		$changed = $current;
		$changed['ignore_selectors'] = '#cookie-notice';
		Settings::sanitize_registered( $changed );
		$after = Database::notes_between( $today, $today );
		$this->assertCount( 2, $after );
		$this->assertStringContainsString( 'Ignored elements', $after[1]['text'] );
		$this->assertSame( 'settings', $after[1]['kind'] );

		Settings::sanitize_registered( $current );
		// Saving the same values again (the sanitize runs against the stored
		// option, which still holds the old values) records a second change
		// but never a duplicate text on the same day.
		$again = Database::notes_between( $today, $today );
		$this->assertLessThanOrEqual( 3, count( $again ) );

		update_option( 'convertrack_version', '0.0.1', true );
		delete_transient( 'convertrack_upgrade_attempt' );
		$plugin = \Convertrack\Plugin::instance();
		$this->assertSame( 'upgraded', $plugin->maybe_upgrade_after_update() );
		$latest = Database::notes_between( $today, $today );
		$last   = end( $latest );
		$this->assertSame( 'update', $last['kind'] );
		$this->assertStringContainsString( CONVERTRACK_VERSION, $last['text'] );
		$this->assertStringContainsString( '0.0.1', $last['text'] );
	}

	public function test_summary_endpoint_ships_the_previous_window_notes_and_today() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		Database::add_note( 'Launch', 'manual' );
		$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/summary' );
		$request->set_param( 'range', 7 );
		$request->set_param( 'post', 0 );
		$data = ( new Rest_Controller() )->stats_summary( $request )->get_data();
		$this->assertCount( 7, $data['series'] );
		$this->assertCount( 7, $data['series_previous'] );
		$this->assertSame( Database::date_days_ago( 13 ), array_keys( $data['series_previous'] )[0] );
		$this->assertSame( Database::date_days_ago( 7 ), array_keys( $data['series_previous'] )[6] );
		$this->assertSame( Database::today(), $data['today'] );
		$this->assertSame( 'Launch', $data['notes'][0]['text'] );
	}
}
