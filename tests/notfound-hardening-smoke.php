<?php
/**
 * Focused, rollback-only 404 Monitor hardening smoke test.
 *
 * Run with the LocalWP PHP binary and site php.ini. The script upgrades the
 * additive module schema, then performs all fixtures in a transaction that is
 * rolled back before exit.
 */

use Convertrack\NotFound\Cron;
use Convertrack\NotFound\Database;
use Convertrack\NotFound\Matcher;
use Convertrack\NotFound\Redirector;
use Convertrack\NotFound\Settings;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}

require_once $wp_load;

if ( ! class_exists( Database::class ) ) {
	fwrite( STDERR, "Convertrack 404 Monitor is not loaded.\n" );
	exit( 1 );
}

function cvtrk_404_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;
$installed = Database::install();
if ( is_wp_error( $installed ) ) {
	fwrite( STDERR, 'Schema upgrade failed: ' . $installed->get_error_message() . "\n" );
	exit( 1 );
}

$original_settings = get_option( Settings::OPTION, array() );
$prefix            = 'cvtrk-smoke-' . strtolower( wp_generate_password( 10, false, false ) );
$events            = Database::events_table();
$redirects         = Database::redirects_table();
$started           = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
if ( false === $started ) {
	fwrite( STDERR, "Could not start test transaction.\n" );
	exit( 1 );
}

try {
	$settings                         = Settings::all();
	$settings['enabled']              = 1;
	$settings['mode']                 = 'recommend';
	$settings['fallback_url']         = '';
	$settings['query_param_allowlist'] = '';
	$settings['capture_ip_per_hour']  = 100000;
	$settings['capture_global_per_hour'] = 1000000;
	$settings['capture_new_paths_per_hour'] = 100000;
	Settings::save( $settings );

	// Isolate the fixture queue without committing changes to existing rows.
	$wpdb->query( "UPDATE $events SET recommendation_state = 'completed' WHERE recommendation_state IN ('pending','processing')" ); // phpcs:ignore WordPress.DB
	Cron::clear_process_continuations();

	$now = current_time( 'mysql' );
	for ( $i = 0; $i < 50; $i++ ) {
		$source = Database::normalize_source( '/' . $prefix . '-low-' . $i . '/?noise=' . $i );
		cvtrk_404_assert( ! empty( $source ), 'Fixture source normalization failed.' );
		$inserted = $wpdb->insert(
			$events,
			array(
				'url_hash'                  => $source['hash'],
				'url'                       => $source['url'],
				'path'                      => $source['path'],
				'query_string'              => $source['query'],
				'first_detected_at'         => $now,
				'last_detected_at'          => $now,
				'hit_count'                 => 4000000000 - $i,
				'status'                    => 'new',
				'recommendation_generation' => Database::RECOMMENDATION_GENERATION,
				'recommendation_state'      => 'pending',
				'created_at'                => $now,
				'updated_at'                => $now,
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		cvtrk_404_assert( false !== $inserted, 'Could not seed recommendation fixture.' );
	}

	$first = Matcher::process_batch( 50 );
	cvtrk_404_assert( 50 === (int) $first['processed'], 'First worker did not process exactly 50 pending rows.' );
	cvtrk_404_assert( 0 === (int) $first['failed'], 'First worker reported recommendation failures.' );
	cvtrk_404_assert( empty( $first['pending'] ), 'Pending work remained after the first worker.' );

	$second = Matcher::process_batch( 50 );
	cvtrk_404_assert( 0 === (int) $second['processed'], 'Second worker reprocessed terminal recommendation rows.' );
	cvtrk_404_assert( ! Database::has_pending_recommendations(), 'Recommendation queue still reports pending work.' );
	Cron::run_process_now();
	cvtrk_404_assert( false === wp_next_scheduled( Cron::PROCESS_NOW ), 'A WP-Cron continuation remained scheduled.' );
	if ( function_exists( 'as_next_scheduled_action' ) ) {
		cvtrk_404_assert( false === as_next_scheduled_action( Cron::PROCESS_NOW, array(), Cron::GROUP ), 'An Action Scheduler continuation remained scheduled.' );
	}

	$terminal = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $events WHERE path LIKE %s AND recommendation_state = 'completed' AND processed_at IS NOT NULL AND recommendation_attempts = 1",
			'/' . $wpdb->esc_like( $prefix ) . '-low-%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	cvtrk_404_assert( 50 === $terminal, 'Not every processed fixture reached one terminal generation.' );

	// A live lease excludes a concurrent owner and only its owner can finalize.
	$concurrent_source = Database::normalize_source( '/' . $prefix . '-concurrent/' );
	$wpdb->insert(
		$events,
		array(
			'url_hash'                  => $concurrent_source['hash'],
			'url'                       => $concurrent_source['url'],
			'path'                      => $concurrent_source['path'],
			'first_detected_at'         => $now,
			'last_detected_at'          => $now,
			'hit_count'                 => 1,
			'status'                    => 'new',
			'recommendation_generation' => Database::RECOMMENDATION_GENERATION,
			'recommendation_state'      => 'pending',
			'created_at'                => $now,
			'updated_at'                => $now,
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$owner_a = 'smoke-owner-a-' . $prefix;
	$owner_b = 'smoke-owner-b-' . $prefix;
	$claim_a = Database::claim_recommendations( 1, $owner_a, 120 );
	$claim_b = Database::claim_recommendations( 1, $owner_b, 120 );
	cvtrk_404_assert( is_array( $claim_a ) && 1 === count( $claim_a ), 'First concurrent worker did not claim the pending row.' );
	cvtrk_404_assert( is_array( $claim_b ) && 0 === count( $claim_b ), 'Second concurrent worker acquired an already leased row.' );
	$claim_result = Database::save_recommendation(
		(int) $claim_a[0]['id'],
		array( 'url' => '', 'confidence' => 0, 'reason' => '', 'post_id' => 0, 'post_type' => '', 'destination_type' => '' ),
		$owner_a,
		(int) $claim_a[0]['recommendation_generation']
	);
	cvtrk_404_assert( true === $claim_result, 'Lease owner could not finalize its row.' );
	$stale_owner = Database::save_recommendation(
		(int) $claim_a[0]['id'],
		array( 'url' => '', 'confidence' => 0 ),
		$owner_b,
		(int) $claim_a[0]['recommendation_generation']
	);
	cvtrk_404_assert( is_wp_error( $stale_owner ), 'A non-owner finalized an already completed recommendation.' );

	// Query strings are excluded by default and sensitive keys are never allowed.
	$plain_a = Database::normalize_source( '/' . $prefix . '/?utm_source=a&anything=one' );
	$plain_b = Database::normalize_source( '/' . $prefix . '/?anything=two' );
	cvtrk_404_assert( $plain_a['hash'] === $plain_b['hash'] && '' === $plain_a['query'], 'Default path-only deduplication failed.' );
	$allow = static function () {
		return array( 'campaign', 'token' );
	};
	add_filter( 'convertrack_404_query_parameter_allowlist', $allow );
	$allowed = Database::normalize_source( '/' . $prefix . '/?campaign=spring&token=secret' );
	remove_filter( 'convertrack_404_query_parameter_allowlist', $allow );
	cvtrk_404_assert( 'campaign=spring' === $allowed['query'], 'Allowlist or mandatory sensitive-key denial failed.' );

	// The event upsert is atomic and reuses the same path identity.
	$one = Database::record_404( '/' . $prefix . '-upsert/?a=1', 'https://example.test/from?secret=1', 'smoke' );
	$two = Database::record_404( '/' . $prefix . '-upsert/?a=2', '', 'smoke' );
	cvtrk_404_assert( ! is_wp_error( $one ) && $one === $two, 'Atomic 404 upsert did not reuse the existing row.' );
	$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM $events WHERE id = %d", $one ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	cvtrk_404_assert( 2 === $hits, 'Atomic 404 upsert did not increment the hit count exactly.' );
	$stored_referrer = (string) $wpdb->get_var( $wpdb->prepare( "SELECT referrer_url FROM $events WHERE id = %d", $one ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	cvtrk_404_assert( false === strpos( $stored_referrer, '?' ), 'Stored 404 referrer retained its query string.' );

	// Multi-hop graph validation must reject a proposed cycle.
	$loop_event = Database::record_404( '/' . $prefix . '-loop-a/', '', 'smoke' );
	cvtrk_404_assert( ! is_wp_error( $loop_event ), 'Loop fixture event could not be stored.' );
	cvtrk_404_assert( null === Database::find_active_redirect( '/' . $prefix . '-loop-b/' ), 'Negative redirect lookup fixture was not empty.' );
	$loop_rule = Database::upsert_redirect( '/' . $prefix . '-loop-b/', home_url( '/' . $prefix . '-loop-a/' ), 0, 'active' );
	cvtrk_404_assert( ! is_wp_error( $loop_rule ), 'Loop fixture redirect could not be stored.' );
	cvtrk_404_assert( is_array( Database::find_active_redirect( '/' . $prefix . '-loop-b/' ) ), 'Creating a rule did not invalidate its negative lookup cache.' );
	$loop = Redirector::validate_pair( '/' . $prefix . '-loop-a/', home_url( '/' . $prefix . '-loop-b/' ), false );
	cvtrk_404_assert( is_wp_error( $loop ) && 'convertrack_404_redirect_chain_loop' === $loop->get_error_code(), 'Full redirect graph loop was not rejected.' );

	// Pausing a rule returns its linked event to the review queue.
	$suggested = Database::update_suggestion( $loop_event, home_url( '/' ) );
	cvtrk_404_assert( ! is_wp_error( $suggested ), 'Could not prepare redirect lifecycle fixture.' );
	$lifecycle_rule = Database::upsert_redirect( '/' . $prefix . '-loop-a/', home_url( '/' ), $loop_event, 'active' );
	cvtrk_404_assert( ! is_wp_error( $lifecycle_rule ), 'Could not create redirect lifecycle fixture.' );
	cvtrk_404_assert( is_array( Database::find_active_redirect( '/' . $prefix . '-loop-a/' ) ), 'Positive redirect lookup fixture was not active.' );
	Database::set_event_status( $loop_event, 'approved' );
	$paused = Database::set_redirect_status( $lifecycle_rule, 'paused' );
	cvtrk_404_assert( ! is_wp_error( $paused ), 'Could not pause redirect lifecycle fixture.' );
	cvtrk_404_assert( null === Database::find_active_redirect( '/' . $prefix . '-loop-a/' ), 'Pausing a rule did not invalidate its positive lookup cache.' );
	$reopened = Database::get_event( $loop_event );
	cvtrk_404_assert( 'recommended' === $reopened['status'], 'Pausing a redirect did not reopen its linked event.' );

	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );
	echo "PASS: 404 hardening smoke checks completed and fixtures were rolled back.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
