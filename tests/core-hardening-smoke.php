<?php
/**
 * Isolated LocalWP smoke test for core migrations, page reports and rollups.
 *
 * The real site schema is upgraded and verified additively. All analytics
 * fixtures use a unique temporary table prefix which is always dropped, so no
 * production analytics rows or rollup days are replaced by this test.
 */

use Convertrack\Collector;
use Convertrack\Database;
use Convertrack\Ingestion_Guard;
use Convertrack\Page_Identity;
use Convertrack\Rest_Controller;
use Convertrack\Rollup_Manager;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}
require_once $wp_load;

if ( ! class_exists( Database::class ) || ! class_exists( Rollup_Manager::class ) ) {
	fwrite( STDERR, "Convertrack core classes are not loaded.\n" );
	exit( 1 );
}

/** Assert a smoke-test condition. */
function cvtrk_core_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Build a minimal trusted database fixture row. */
function cvtrk_core_event( $event_id, $visitor_id, $session_id, $url, $type, $created_at, $conversion = 0, $extra = array() ) {
	return array_merge(
		array(
			'event_id'      => $event_id,
			'visitor_id'    => $visitor_id,
			'session_id'    => $session_id,
			'event_type'    => $type,
			'post_id'       => 0,
			'page_url'      => $url,
			'page_title'    => '',
			'is_conversion' => (int) $conversion,
			'device_type'   => 'desktop',
			'source'        => 'Direct',
			'created_at'    => $created_at,
		),
		$extra
	);
}

global $wpdb;

// Exercise the real additive migration before switching to isolated fixtures.
$migration = Database::install();
if ( is_wp_error( $migration ) ) {
	fwrite( STDERR, 'Core schema migration failed: ' . $migration->get_error_message() . "\n" );
	exit( 1 );
}
$verified = Database::verify_schema();
if ( is_wp_error( $verified ) ) {
	fwrite( STDERR, 'Core schema verification failed: ' . $verified->get_error_message() . "\n" );
	exit( 1 );
}

$real_prefix = $wpdb->prefix;
$token       = strtolower( wp_generate_password( 8, false, false ) );
$test_prefix = $real_prefix . 'cvtrk_core_' . $token . '_';
$wpdb->prefix = $test_prefix;
$one_bucket = static function () { return 1; };

try {
	// Remove debris from an interrupted run with the same improbable prefix,
	// then prove a clean install and idempotent verification.
	Database::drop_tables();
	$installed = Database::install();
	cvtrk_core_assert( ! is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : 'Temporary core schema install failed.' );
	cvtrk_core_assert( true === Database::verify_schema(), 'Temporary core schema did not verify.' );
	update_option( Database::DB_VERSION_OPTION, 'smoke-stale', false );
	$block_version = function ( $value, $old_value, $option ) {
		return 'smoke-stale';
	};
	add_filter( 'pre_update_option_' . Database::DB_VERSION_OPTION, $block_version, 10, 3 );
	$blocked_migration = Database::install();
	remove_filter( 'pre_update_option_' . Database::DB_VERSION_OPTION, $block_version, 10 );
	cvtrk_core_assert( is_wp_error( $blocked_migration ) && 'convertrack_migration_version_write' === $blocked_migration->get_error_code(), 'A denied schema watermark write was reported as success.' );
	$recovered_migration = Database::install();
	cvtrk_core_assert( true === $recovered_migration, 'Migration did not recover after the denied write/lock release.' );
	cvtrk_core_assert( true === Database::maybe_upgrade(), 'A verified schema did not pass an idempotent upgrade check.' );

	$page_a = '/cvtrk-core-' . $token . '-a/';
	$page_b = '/cvtrk-core-' . $token . '-b/';
	$key_a  = Page_Identity::from_payload( $page_a, 0 )['page_key'];
	$key_b  = Page_Identity::from_payload( $page_b, 0 )['page_key'];
	cvtrk_core_assert( $key_a !== $key_b, 'Distinct virtual paths collapsed to one page key.' );
	cvtrk_core_assert( '/b' === Page_Identity::normalize_path( '/a/../b/?email=private@example.test' ), 'Page path normalization retained query data or dot segments.' );

	// A valid server identity survives the REST hop; copying its signature to a
	// different event path falls back to safe URL identity.
	$signed_identity = array(
		'page_key'    => 'term-category:77:' . $page_a,
		'object_type' => 'term-category',
		'object_id'   => 77,
		'post_id'     => 0,
		'path'        => $page_a,
	);
	$identity_token = Ingestion_Guard::issue_page_identity_token( $signed_identity );
	$accepted = Collector::canonicalize_page(
		$page_a,
		0,
		array(
			'page_key'    => $signed_identity['page_key'],
			'object_type' => $signed_identity['object_type'],
			'object_id'   => 77,
			'token'       => $identity_token,
		)
	);
	cvtrk_core_assert( $signed_identity['page_key'] === $accepted['page_key'], 'A valid server page identity was rejected.' );
	$forged = Collector::canonicalize_page(
		$page_b,
		0,
		array(
			'page_key'    => $signed_identity['page_key'],
			'object_type' => $signed_identity['object_type'],
			'object_id'   => 77,
			'token'       => $identity_token,
		)
	);
	cvtrk_core_assert( $key_b === $forged['page_key'] && 'url' === $forged['object_type'], 'A copied page identity token relabeled another path.' );

	$today     = Database::today();
	$yesterday = Database::date_days_ago( 1 );
	$middle    = Database::date_days_ago( 2 );
	$oldest    = Database::date_days_ago( 3 );
	$visitor_a = wp_generate_uuid4();
	$visitor_b = wp_generate_uuid4();
	$session_a1 = wp_generate_uuid4();
	$session_a2 = wp_generate_uuid4();
	$session_b1 = wp_generate_uuid4();

	// The unique event ID is an idempotency key, including when retried inside a
	// later mixed batch.
	$first_id = wp_generate_uuid4();
	$first = cvtrk_core_event( $first_id, $visitor_a, $session_a1, $page_a, 'pageview', $yesterday . ' 09:00:00' );
	cvtrk_core_assert( 1 === Database::insert_events( array( $first ) ), 'Initial event insert did not store one row.' );
	cvtrk_core_assert( 0 === Database::insert_events( array( $first ) ), 'A duplicate event ID was not an idempotent no-op.' );

	$rows = array(
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a1, $page_a, 'click', $yesterday . ' 09:01:00', 1, array( 'element_selector' => '#buy-a', 'element_text' => 'Buy', 'source' => 'Paid search', 'utm_campaign' => 'campaign-a', 'search_keyword' => 'alpha', 'search_source' => 'site_search' ) ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a1, $page_b, 'pageview', $yesterday . ' 09:02:00', 1 ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_b, $session_b1, $page_b, 'pageview', $yesterday . ' 10:00:00' ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_b, $session_b1, $page_b, 'click', $yesterday . ' 10:01:00', 1, array( 'element_selector' => '#buy-b', 'element_text' => 'Buy', 'source' => 'Paid search', 'utm_campaign' => 'campaign-b', 'search_keyword' => 'beta', 'search_source' => 'site_search' ) ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a1, $page_a, 'pageview', $oldest . ' 08:00:00' ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a1, $page_a, 'pageview', $middle . ' 08:00:00' ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a2, $page_b, 'pageview', $today . ' 08:00:00' ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a2, $page_a, 'heatmap_click', $today . ' 08:01:00', 0, array( 'heatmap_selector' => 'main>a:nth-of-type(1)', 'pos_x' => 200, 'pos_y' => 300 ) ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a2, $page_b, 'heatmap_click', $today . ' 08:02:00', 0, array( 'heatmap_selector' => 'main>a:nth-of-type(2)', 'pos_x' => 400, 'pos_y' => 500 ) ),
		cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a2, $page_b, 'heatmap_click', $today . ' 08:03:00', 0, array( 'heatmap_selector' => 'main>a:nth-of-type(2)', 'pos_x' => 410, 'pos_y' => 510 ) ),
	);
	$mixed = array_merge( array( $first ), $rows );
	cvtrk_core_assert( count( $rows ) === Database::insert_events( $mixed ), 'A duplicate in a mixed batch blocked new events.' );
	$event_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::events_table() . ' WHERE event_id=%s', $first_id ) );
	cvtrk_core_assert( 1 === $event_count, 'The event idempotency key stored more than one row.' );
	$session_counts = Database::session_event_counts( $session_a1 );
	cvtrk_core_assert( ! is_wp_error( $session_counts ) && 4 === $session_counts['pageviews'] && 1 === $session_counts['clicks'], 'Exact session event recount was incorrect.' );
	Database::touch_session( $session_a1, $visitor_a, $page_b, 0, $session_counts['pageviews'], $session_counts['clicks'], '', true );
	Database::touch_session( $session_a1, $visitor_a, $page_b, 0, $session_counts['pageviews'], $session_counts['clicks'], '', true );
	$live_counts = $wpdb->get_row( $wpdb->prepare( 'SELECT page_views,click_count FROM ' . Database::sessions_table() . ' WHERE session_id=%s', $session_a1 ), ARRAY_A );
	cvtrk_core_assert( 4 === (int) $live_counts['page_views'] && 1 === (int) $live_counts['click_count'], 'Retry-safe absolute session counters were incremented twice.' );
	// A reused session identifier must remain one session even if a malformed
	// client later pairs it with another valid visitor identifier.
	cvtrk_core_assert( 1 === Database::insert_events( array( cvtrk_core_event( wp_generate_uuid4(), $visitor_b, $session_a1, $page_b, 'click', $yesterday . ' 10:02:00', 0, array( 'element_selector' => '#shared-session' ) ) ) ), 'Cross-visitor session fixture was not stored.' );

	add_filter( 'convertrack_selector_rollup_limit', $one_bucket );
	add_filter( 'convertrack_campaign_rollup_limit', $one_bucket );
	add_filter( 'convertrack_keyword_rollup_limit', $one_bucket );

	$rolled = Rollup_Manager::rollup_day( $yesterday, false );
	cvtrk_core_assert( ! is_wp_error( $rolled ) && 'complete' === $rolled['status'], is_wp_error( $rolled ) ? 'Yesterday rollup failed: ' . $rolled->get_error_code() . ' - ' . $rolled->get_error_message() : 'Yesterday rollup did not complete.' );
	$daily = Database::daily_table();
	$snapshot_one = $wpdb->get_results( $wpdb->prepare( "SELECT bucket_hash,page_key,post_id,element_selector,clicks,conversions,conversion_events,pageviews,unique_visitors FROM $daily WHERE stat_date=%s ORDER BY bucket_hash", $yesterday ), ARRAY_A );
	$rebuilt = Rollup_Manager::rollup_day( $yesterday, true );
	cvtrk_core_assert( ! is_wp_error( $rebuilt ) && 'complete' === $rebuilt['status'], 'Forced rollup rebuild did not complete.' );
	$snapshot_two = $wpdb->get_results( $wpdb->prepare( "SELECT bucket_hash,page_key,post_id,element_selector,clicks,conversions,conversion_events,pageviews,unique_visitors FROM $daily WHERE stat_date=%s ORDER BY bucket_hash", $yesterday ), ARRAY_A );
	cvtrk_core_assert( $snapshot_one === $snapshot_two, 'A rollup rebuild was not an exact idempotent replacement.' );
	$state = $wpdb->get_row( $wpdb->prepare( 'SELECT status,owner_token,attempts,source_event_count FROM ' . Database::rollup_state_table() . ' WHERE stat_date=%s', $yesterday ), ARRAY_A );
	cvtrk_core_assert( 'complete' === $state['status'] && '' === $state['owner_token'] && 0 === (int) $state['attempts'], 'Successful rollup did not release ownership/reset attempts.' );
	$selector_other = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::daily_table() . ' WHERE stat_date=%s AND element_selector=%s', $yesterday, Rollup_Manager::OTHER_BUCKET ) );
	cvtrk_core_assert( $selector_other >= 1 && $selector_other <= 2, 'Selector tail was not compacted into bounded per-page Other buckets.' );
	cvtrk_core_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::sources_table() . ' WHERE stat_date=%s AND campaign=%s', $yesterday, Rollup_Manager::OTHER_BUCKET ) ), 'Campaign tail was not compacted into an Other bucket.' );
	cvtrk_core_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::search_terms_table() . ' WHERE stat_date=%s AND search_keyword=%s', $yesterday, Rollup_Manager::OTHER_BUCKET ) ), 'Keyword tail was not compacted into an Other bucket.' );
	cvtrk_core_assert( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::session_days_table() . ' WHERE stat_date=%s', $yesterday ) ), 'One session ID was split by visitor in the exact session dimension.' );

	// A late event requeues a completed day; retrying the day replaces rather
	// than adds to the prior snapshot.
	$late = cvtrk_core_event( wp_generate_uuid4(), $visitor_a, $session_a1, $page_a, 'pageview', $yesterday . ' 11:00:00' );
	cvtrk_core_assert( 1 === Database::insert_events( array( $late ) ), 'Late historical event was not stored.' );
	$late_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Database::rollup_state_table() . ' WHERE stat_date=%s', $yesterday ) );
	cvtrk_core_assert( 'pending' === $late_status, 'Late historical data did not requeue its completed rollup.' );
	$late_rebuild = Rollup_Manager::rollup_day( $yesterday, false );
	cvtrk_core_assert( ! is_wp_error( $late_rebuild ) && 'complete' === $late_rebuild['status'], 'Late-data rollup replacement did not complete.' );

	// Oldest-first catch-up remains bounded and truthfully reports continuation.
	$catch_one = Rollup_Manager::catch_up( 1, 20 );
	cvtrk_core_assert( ! is_wp_error( $catch_one ) && array( $oldest ) === $catch_one['processed'] && $catch_one['remaining'] > 0, 'First catch-up did not process only the oldest missing day.' );
	$catch_two = Rollup_Manager::catch_up( 10, 20 );
	cvtrk_core_assert( ! is_wp_error( $catch_two ) && in_array( $middle, $catch_two['processed'], true ), 'Catch-up did not recover the next missing day.' );
	// Catch-up also discovers a completed day whose event high-water mark has
	// drifted (for example, a direct importer bypassed the normal write path).
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::rollup_state_table() . ' SET source_event_max_id=0 WHERE stat_date=%s', $yesterday ) );
	$drift_repair = Rollup_Manager::catch_up( 10, 20 );
	cvtrk_core_assert( ! is_wp_error( $drift_repair ) && in_array( $yesterday, $drift_repair['processed'], true ), 'Catch-up did not rebuild a completed day with a stale source watermark.' );

	// A live owner lease excludes overlap; expiry permits one replacement owner.
	$claim = new ReflectionMethod( Rollup_Manager::class, 'claim' );
	$claim->setAccessible( true );
	$claim_date = '2001-01-01';
	$owner_a = wp_generate_uuid4();
	$owner_b = wp_generate_uuid4();
	cvtrk_core_assert( true === $claim->invoke( null, $claim_date, $owner_a, false ), 'First rollup owner could not claim a pending day.' );
	cvtrk_core_assert( false === $claim->invoke( null, $claim_date, $owner_b, false ), 'A second owner acquired a live rollup lease.' );
	$wpdb->query( $wpdb->prepare( "UPDATE " . Database::rollup_state_table() . " SET lease_expires_at=%s WHERE stat_date=%s", '2000-01-01 00:00:00', $claim_date ) );
	cvtrk_core_assert( true === $claim->invoke( null, $claim_date, $owner_b, false ), 'An expired rollup lease could not be recovered.' );
	$renew = new ReflectionMethod( Rollup_Manager::class, 'renew_lease' );
	$renew->setAccessible( true );
	cvtrk_core_assert( is_wp_error( $renew->invoke( null, $claim_date, $owner_a ) ), 'A displaced owner renewed another worker\'s lease.' );
	cvtrk_core_assert( true === $renew->invoke( null, $claim_date, $owner_b ), 'The current owner could not renew its lease.' );

	// Read and cleanup failures must be returned, never reported as a completed
	// rollup. Query filters keep the fault isolated to this temporary prefix.
	$source_snapshot = new ReflectionMethod( Rollup_Manager::class, 'source_snapshot' );
	$source_snapshot->setAccessible( true );
	$source_fault = static function ( $query ) use ( $test_prefix ) {
		if ( false !== strpos( $query, 'SELECT COUNT(*) event_count' ) && false !== strpos( $query, '1999-01-01' ) ) {
			return 'SELECT missing_column FROM ' . $test_prefix . 'missing_source_table';
		}
		return $query;
	};
	add_filter( 'query', $source_fault );
	$source_error = $source_snapshot->invoke( null, '1999-01-01 00:00:00', '1999-01-02 00:00:00' );
	remove_filter( 'query', $source_fault );
	cvtrk_core_assert( is_wp_error( $source_error ) && 'convertrack_rollup_source' === $source_error->get_error_code(), 'A failed source-watermark read was not detected.' );

	$cleanup_stage = new ReflectionMethod( Rollup_Manager::class, 'cleanup_stage' );
	$cleanup_stage->setAccessible( true );
	$cleanup_fault = static function ( $query ) use ( $test_prefix ) {
		if ( 0 === strpos( $query, 'DELETE FROM ' ) && false !== strpos( $query, 'fault-owner' ) ) {
			return 'DELETE FROM ' . $test_prefix . 'missing_cleanup_table';
		}
		return $query;
	};
	add_filter( 'query', $cleanup_fault );
	$cleanup_error = $cleanup_stage->invoke( null, $yesterday, 'fault-owner', false );
	remove_filter( 'query', $cleanup_fault );
	cvtrk_core_assert( is_wp_error( $cleanup_error ) && 'convertrack_rollup_stage_cleanup' === $cleanup_error->get_error_code(), 'A failed staging cleanup was not detected.' );

	$cache_generation = Database::report_cache_generation();
	Database::invalidate_report_cache();
	cvtrk_core_assert( Database::report_cache_generation() > $cache_generation, 'Report cache invalidation did not advance its generation.' );
	foreach ( array( 'convertrack_today_agg', 'convertrack_avgdur_4', 'summary_30_0' ) as $cache_key ) {
		wp_cache_delete( $cache_key, 'convertrack' );
	}
	$overview = Database::overview_stats( 7 );
	cvtrk_core_assert( 2 === $overview['unique_visitors'], 'Exact visitors did not deduplicate one visitor across pages, sessions and days.' );
	cvtrk_core_assert( 3 === $overview['sessions'], 'Exact session count did not deduplicate sessions across pages/days.' );
	cvtrk_core_assert( 2 === $overview['conversions'], 'Primary conversions were not deduplicated to converting sessions.' );
	cvtrk_core_assert( 3 === $overview['conversion_events'], 'Secondary conversion-event total lost the click/destination distinction.' );

	$pages = Database::paged_pages( array( 'range' => 7, 'page' => 1, 'per_page' => 25 ) );
	$reported_keys = wp_list_pluck( $pages['rows'], 'page_key' );
	cvtrk_core_assert( in_array( $key_a, $reported_keys, true ) && in_array( $key_b, $reported_keys, true ), 'Content report collapsed post_id=0 pages.' );
	$search_pages = Database::paged_pages( array( 'range' => 7, 'search' => $token, 'page' => 1, 'per_page' => 25 ) );
	cvtrk_core_assert( 2 === $search_pages['total'], 'Page-key paths were not searchable.' );
	cvtrk_core_assert( 3 === array_sum( wp_list_pluck( $pages['rows'], 'conversions' ) ), 'Page conversion-event totals were multiplied by selector/session buckets.' );
	$buttons = Database::top_buttons( 7, 20 );
	cvtrk_core_assert( 2 === array_sum( wp_list_pluck( $buttons, 'conversions' ) ), 'Button conversion events were not additive across selector buckets.' );
	$sources = Database::top_sources( 7, 20 );
	cvtrk_core_assert( 3 === array_sum( wp_list_pluck( $sources, 'conversions' ) ), 'Source conversion events were multiplied across campaign buckets.' );
	$trend = Database::clicks_timeseries( 7 );
	cvtrk_core_assert( 2 === (int) $trend[ $yesterday ]['conversions'], 'Daily primary conversions were not deduplicated to converting sessions.' );

	$heatmap = Database::heatmap_data( 0, 7, 'all', $key_a );
	cvtrk_core_assert( 2 === $heatmap['heatmap_clicks'] && 1 === count( $heatmap['points'] ), 'Heatmap page_key filtering merged another virtual page.' );

	$controller = new Rest_Controller();
	$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/pages' );
	$request->set_param( 'range', 30 );
	$request->set_param( 'page', 1 );
	$request->set_param( 'per_page', 25 );
	$request->set_param( 'search', '' );
	$request->set_param( 'orderby', 'pageviews' );
	$request->set_param( 'order', 'desc' );
	$page_response = $controller->stats_pages( $request )->get_data();
	cvtrk_core_assert( 30 === $page_response['requested_range'] && 4 === $page_response['effective_range'] && true === $page_response['partial'], 'Content response range metadata was not truthful.' );
	cvtrk_core_assert( isset( $page_response['rows'][0]['page_key'] ), 'Decorated Content rows omitted page_key compatibility data.' );

	$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/heatmap' );
	$request->set_param( 'range', 30 );
	$request->set_param( 'post', 0 );
	$request->set_param( 'page_key', $key_a );
	$request->set_param( 'device', 'all' );
	$heat_response = $controller->stats_heatmap( $request )->get_data();
	cvtrk_core_assert( $key_a === $heat_response['page_key'] && 4 === $heat_response['effective_range'] && true === $heat_response['truncated'], 'Heatmap did not preserve page_key or clamp its raw window.' );

	$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/funnels' );
	$request->set_param( 'range', 30 );
	$funnel_response = $controller->stats_funnels( $request )->get_data();
	cvtrk_core_assert( 4 === $funnel_response['effective_range'] && true === $funnel_response['partial'], 'Journey response did not disclose its retained raw window.' );

	$request = new WP_REST_Request( 'GET', '/convertrack/v1/stats/summary' );
	$request->set_param( 'range', 30 );
	$request->set_param( 'post', 0 );
	$summary_response = $controller->stats_summary( $request )->get_data();
	cvtrk_core_assert( 4 === $summary_response['effective_range'] && true === $summary_response['partial'] && isset( $summary_response['range_sources']['aggregate'], $summary_response['range_sources']['raw'] ), 'Dashboard did not disclose mixed report windows.' );

} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	$failed = true;
} finally {
	remove_filter( 'convertrack_selector_rollup_limit', $one_bucket );
	remove_filter( 'convertrack_campaign_rollup_limit', $one_bucket );
	remove_filter( 'convertrack_keyword_rollup_limit', $one_bucket );
	// The temporary prefix ensures these drops cannot touch installed analytics.
	Database::drop_tables();
	$wpdb->prefix = $real_prefix;
	Database::invalidate_report_cache();
	wp_cache_delete( 'summary_30_0', 'convertrack' );
}

if ( ! empty( $failed ) ) {
	exit( 1 );
}

$leftover = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $test_prefix ) . '%' ) );
if ( $leftover ) {
	fwrite( STDERR, 'FAIL: Temporary analytics table was not removed: ' . $leftover . "\n" );
	exit( 1 );
}

echo "PASS: core migration, identity, rollup, exact metrics and report smoke checks completed; temporary fixtures removed.\n";
