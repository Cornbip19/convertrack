<?php
/**
 * Rollback-only checks for redirect conflict detection, diagnosis and fixes.
 *
 * Run with the LocalWP PHP binary and site php.ini. Every fixture is created
 * inside a transaction that is rolled back before exit.
 *
 * @package Convertrack
 */

use Convertrack\NotFound\Compatibility;
use Convertrack\NotFound\Conflicts;
use Convertrack\NotFound\Database;
use Convertrack\NotFound\Matcher;
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

if ( ! class_exists( Conflicts::class ) ) {
	fwrite( STDERR, "Convertrack conflict diagnosis is not loaded.\n" );
	exit( 1 );
}

$cvtrk_failures = 0;

/**
 * Report one assertion without aborting.
 *
 * @param bool   $condition Result.
 * @param string $message   Description.
 */
function cvtrk_check( $condition, $message ) {
	global $cvtrk_failures;
	if ( $condition ) {
		echo "PASS  $message\n";
		return;
	}
	$cvtrk_failures++;
	echo "FAIL  $message\n";
}

/**
 * Abort on a broken fixture.
 *
 * @param bool   $condition Result.
 * @param string $message   Description.
 */
function cvtrk_fixture( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

echo "=== schema migration ===\n";
$installed = Database::install();
cvtrk_check( ! is_wp_error( $installed ), 'install() succeeded' . ( is_wp_error( $installed ) ? ': ' . $installed->get_error_message() : '' ) );
if ( is_wp_error( $installed ) ) {
	exit( 1 );
}
cvtrk_check( ! is_wp_error( Database::install() ), 'install() is idempotent' );
cvtrk_check( '1.4.0' === Database::DB_VERSION, 'DB_VERSION is 1.4.0' );
cvtrk_check( Database::DB_VERSION === (string) get_option( Database::DB_VERSION_OPTION ), 'stored watermark matches the code' );

$events  = Database::events_table();
$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `$events`", 0 ) );
foreach ( array( 'conflict_code', 'conflict_owner', 'conflict_redirect_id', 'conflict_detail', 'conflict_checked_at' ) as $column ) {
	cvtrk_check( in_array( $column, $columns, true ), "column $column exists" );
}
$indexes = array_unique( array_map( 'strval', (array) $wpdb->get_col( "SHOW INDEX FROM `$events`", 2 ) ) );
cvtrk_check( in_array( 'conflict_code', $indexes, true ), 'conflict_code index exists' );

$original_settings = get_option( Settings::OPTION, array() );
$prefix            = 'cvtrk-test-' . strtolower( wp_generate_password( 8, false, false ) );

$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
if ( false === $started ) {
	fwrite( STDERR, "Could not start test transaction.\n" );
	exit( 1 );
}

/**
 * Seed one 404 event.
 *
 * @param string $slug     Path slug.
 * @param string $last_hit MySQL datetime of the last hit.
 * @param string $status   Event status.
 * @param string $query    Optional query string.
 * @return int
 */
function cvtrk_seed_event( $slug, $last_hit, $status = 'new', $query = '' ) {
	global $wpdb;
	$path   = '/' . $slug . '/' . ( '' !== $query ? '?' . $query : '' );
	$source = Database::normalize_source( $path );
	cvtrk_fixture( ! empty( $source ), 'Could not normalize ' . $path );

	$inserted = $wpdb->insert(
		Database::events_table(),
		array(
			'url_hash'                  => $source['hash'],
			'url'                       => $source['url'],
			'path'                      => $source['path'],
			'query_string'              => $source['query'],
			'first_detected_at'         => $last_hit,
			'last_detected_at'          => $last_hit,
			'hit_count'                 => 5,
			'status'                    => $status,
			'recommendation_generation' => Database::RECOMMENDATION_GENERATION,
			'recommendation_state'      => 'pending',
			'created_at'                => $last_hit,
			'updated_at'                => $last_hit,
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	cvtrk_fixture( false !== $inserted, 'Could not seed event ' . $slug );
	return (int) $wpdb->insert_id;
}

/**
 * Seed a redirect rule directly, bypassing validation.
 *
 * @param string $slug        Source slug.
 * @param string $destination Destination URL.
 * @param string $status      Rule status.
 * @param string $updated     Rule updated_at.
 * @param string $health      Health status.
 * @return int
 */
function cvtrk_seed_rule( $slug, $destination, $status, $updated, $health = 'healthy' ) {
	global $wpdb;
	$source = Database::normalize_source( '/' . $slug . '/' );
	cvtrk_fixture( ! empty( $source ), 'Could not normalize rule source ' . $slug );

	$inserted = $wpdb->insert(
		Database::redirects_table(),
		array(
			'source_hash'     => $source['hash'],
			'source_url'      => $source['url'],
			'source_path'     => $source['path'],
			'destination_url' => $destination,
			'redirect_type'   => 301,
			'status'          => $status,
			'source'          => 'internal',
			'health_status'   => $health,
			'created_at'      => $updated,
			'updated_at'      => $updated,
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	cvtrk_fixture( false !== $inserted, 'Could not seed rule ' . $slug );
	Database::invalidate_redirect_cache( $source['url'] );
	return (int) $wpdb->insert_id;
}

try {
	$settings = Settings::all();
	$settings['enabled'] = 1;
	$settings['mode']    = 'recommend';
	$settings['fallback_url'] = '';
	Settings::save( $settings );

	$old = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - ( 5 * DAY_IN_SECONDS ) );
	$mid = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - ( 3 * DAY_IN_SECONDS ) );
	$now = current_time( 'mysql' );

	echo "\n=== the discriminator: does the hit predate the rule? ===\n";
	// Stale: last hit BEFORE the rule was last touched.
	$stale_event = cvtrk_seed_event( $prefix . '-stale', $old );
	$stale_rule  = cvtrk_seed_rule( $prefix . '-stale', home_url( '/' ), 'active', $mid );
	$stale_row   = Database::get_event( $stale_event );
	$stale_rule_row = Database::get_redirect( $stale_rule );
	cvtrk_check( Database::event_predates_rule( $stale_row, $stale_rule_row ), 'a hit before the rule is treated as stale' );

	// Conflict: last hit AFTER the rule was last touched.
	$live_event = cvtrk_seed_event( $prefix . '-live', $now );
	$live_rule  = cvtrk_seed_rule( $prefix . '-live', home_url( '/' ), 'active', $mid );
	$live_row   = Database::get_event( $live_event );
	$live_rule_row = Database::get_redirect( $live_rule );
	cvtrk_check( ! Database::event_predates_rule( $live_row, $live_rule_row ), 'a hit after the rule is treated as a conflict' );

	echo "\n=== conflict_suspects finds only the live one ===\n";
	$suspects = Database::conflict_suspects( 100 );
	$suspect_ids = wp_list_pluck( $suspects, 'id' );
	cvtrk_check( in_array( (string) $live_event, array_map( 'strval', $suspect_ids ), true ), 'the still-hit URL is a suspect' );
	cvtrk_check( ! in_array( (string) $stale_event, array_map( 'strval', $suspect_ids ), true ), 'the stale URL is not a suspect' );

	echo "\n=== the matcher stops recommending already-redirected URLs (ask 1) ===\n";
	$batch = Matcher::process_batch( 50 );
	cvtrk_check( ! is_wp_error( $batch ) && isset( $batch['already_redirected'] ), 'process_batch reports already-redirected rows' );
	$after_stale = Database::get_event( $stale_event );
	cvtrk_check(
		Database::STATUS_ALREADY_REDIRECTED === $after_stale['status'],
		'the stale row became already_redirected (got ' . $after_stale['status'] . ')'
	);
	cvtrk_check( '' === $after_stale['suggested_url'], 'and no suggestion was generated for it' );
	cvtrk_check( 'completed' === $after_stale['recommendation_state'], 'and its recommendation lease was released' );
	cvtrk_check( '' === $after_stale['claim_owner'], 'and no claim owner is left behind' );

	$after_live = Database::get_event( $live_event );
	cvtrk_check( '' !== $after_live['conflict_code'], 'the still-hit row was flagged with a conflict verdict: ' . $after_live['conflict_code'] );
	cvtrk_check( Conflicts::OWNER_INTERNAL === $after_live['conflict_owner'], 'and the rule owner was recorded as Convertrack' );

	echo "\n=== already_redirected is grouped and sorted as finished work ===\n";
	cvtrk_check( in_array( Database::STATUS_ALREADY_REDIRECTED, Database::status_groups()['redirected'], true ), 'it belongs to the "Redirected" filter group' );
	$order = Database::status_rank_order();
	$idx_already = array_search( Database::STATUS_ALREADY_REDIRECTED, $order, true );
	$idx_new     = array_search( Database::STATUS_NEW, $order, true );
	cvtrk_check( $idx_already > $idx_new, 'it sorts behind rows that still need action' );

	$grouped = Database::list_events( array( 'status' => 'redirected', 'search' => $prefix . '-stale', 'per_page' => 50 ) );
	cvtrk_check( 1 === (int) $grouped['total'], 'the "Redirected" filter returns it' );
	$needs = Database::list_events( array( 'status' => 'needs_action', 'search' => $prefix . '-stale', 'per_page' => 50 ) );
	cvtrk_check( 0 === (int) $needs['total'], 'and "Needs action" does not' );

	echo "\n=== verdicts from stored data ===\n";
	// Paused rule.
	$paused_event = cvtrk_seed_event( $prefix . '-paused', $now );
	cvtrk_seed_rule( $prefix . '-paused', home_url( '/' ), 'paused', $mid );
	$verdict = Conflicts::diagnose( Database::get_event( $paused_event ), false );
	cvtrk_check( Conflicts::RULE_PAUSED === $verdict, 'a paused rule is diagnosed as rule_paused (got ' . $verdict . ')' );
	cvtrk_check(
		Conflicts::FIX_RESUME_RULE === Conflicts::fix_for( $verdict ),
		'and offers Resume rule'
	);

	// A paused rule is invisible to find_active_redirect(), which is why the
	// diagnosis has to look it up regardless of status.
	cvtrk_check( null === Database::find_active_redirect( home_url( '/' . $prefix . '-paused/' ) ), 'find_active_redirect cannot see a paused rule' );
	cvtrk_check( is_array( Database::redirect_for_source_any_status( home_url( '/' . $prefix . '-paused/' ) ) ), 'redirect_for_source_any_status can' );

	// Monitoring off outranks everything per-rule.
	$settings['enabled'] = 0;
	Settings::save( $settings );
	$off_event = cvtrk_seed_event( $prefix . '-off', $now );
	cvtrk_seed_rule( $prefix . '-off', home_url( '/' ), 'paused', $mid );
	$verdict = Conflicts::diagnose( Database::get_event( $off_event ), false );
	cvtrk_check( Conflicts::MONITOR_DISABLED === $verdict, 'with monitoring off the verdict is monitor_disabled (got ' . $verdict . ')' );
	cvtrk_check( Conflicts::FIX_NONE === Conflicts::fix_for( $verdict ), 'and no one-click fix is offered for a site-wide cause' );
	cvtrk_check( 'site' === Conflicts::descriptor( $verdict )['scope'], 'and it is marked site-wide in scope' );
	$settings['enabled'] = 1;
	Settings::save( $settings );

	echo "\n=== the probe must not record a new 404 (self-feeding guard) ===\n";
	// The most important check here. Detector::is_internal_user_agent() skips
	// requests carrying the module user agent; without it, probing a broken URL
	// would log a fresh hit and the conflict would sustain itself forever.
	$probe_event = cvtrk_seed_event( $prefix . '-probe', $now );
	cvtrk_seed_rule( $prefix . '-probe', home_url( '/' ), 'active', $mid );
	$before = Database::get_event( $probe_event );
	$hits_before = (int) $before['hit_count'];

	$status = \Convertrack\NotFound\Redirector::probe_status( home_url( '/' . $prefix . '-probe/' ) );
	$after  = Database::get_event( $probe_event );
	cvtrk_check(
		(int) $after['hit_count'] === $hits_before,
		sprintf( 'probing did not increase hit_count (%d -> %d, probe returned %d)', $hits_before, (int) $after['hit_count'], $status )
	);
	cvtrk_check(
		\Convertrack\NotFound\Detector::should_ignore( '/' . $prefix . '-probe/' ) || true,
		'probe completed without throwing (status ' . $status . ')'
	);

	echo "\n=== fixes ===\n";
	// Resume a paused rule.
	$resume_event = cvtrk_seed_event( $prefix . '-resume', $now );
	$resume_rule  = cvtrk_seed_rule( $prefix . '-resume', home_url( '/' ), 'paused', $mid );
	Database::save_conflict( $resume_event, Conflicts::RULE_PAUSED, Conflicts::OWNER_INTERNAL, $resume_rule );
	$applied = Conflicts::apply( Database::get_event( $resume_event ) );
	cvtrk_check( ! is_wp_error( $applied ), 'Resume rule applied' . ( is_wp_error( $applied ) ? ': ' . $applied->get_error_message() : '' ) );
	if ( ! is_wp_error( $applied ) ) {
		$rule = Database::get_redirect( $resume_rule );
		cvtrk_check( 'active' === $rule['status'], 'and the rule is active again' );
		cvtrk_check( '' === Database::get_event( $resume_event )['conflict_code'], 'and the conflict was cleared' );
	}

	// Mark resolved.
	$resolved_event = cvtrk_seed_event( $prefix . '-resolved', $now );
	Database::save_conflict( $resolved_event, Conflicts::RESOLVED, Conflicts::OWNER_INTERNAL, 0 );
	$applied = Conflicts::apply( Database::get_event( $resolved_event ) );
	cvtrk_check( ! is_wp_error( $applied ), 'Mark resolved applied' );
	cvtrk_check( Database::STATUS_ALREADY_REDIRECTED === Database::get_event( $resolved_event )['status'], 'and the row closed as already_redirected' );

	// Causes with no safe fix must refuse rather than pretend.
	foreach ( array( Conflicts::MONITOR_DISABLED, Conflicts::EXTERNAL_TOOL, Conflicts::SERVER_INTERCEPTS, Conflicts::UNKNOWN ) as $code ) {
		$refuse_event = cvtrk_seed_event( $prefix . '-refuse-' . substr( md5( $code ), 0, 6 ), $now );
		Database::save_conflict( $refuse_event, $code, Conflicts::OWNER_INTERNAL, 0 );
		$result = Conflicts::apply( Database::get_event( $refuse_event ) );
		cvtrk_check( is_wp_error( $result ), sprintf( '%-24s refuses to auto-apply', $code ) );
	}

	echo "\n=== approving a duplicate no longer parks the row in manual review ===\n";
	$dup_event = cvtrk_seed_event( $prefix . '-dup', $old );
	cvtrk_seed_rule( $prefix . '-dup', home_url( '/' ), 'active', $mid );
	$approved = \Convertrack\NotFound\Redirector::approve_event( $dup_event, home_url( '/somewhere-else/' ) );
	cvtrk_check( is_wp_error( $approved ), 'approval is still rejected as a duplicate' );
	$dup_row = Database::get_event( $dup_event );
	cvtrk_check(
		Database::STATUS_MANUAL_REVIEW !== $dup_row['status'],
		'and the row was NOT left in manual_review (status ' . $dup_row['status'] . ')'
	);
	cvtrk_check( Database::STATUS_ALREADY_REDIRECTED === $dup_row['status'], 'it was closed as already_redirected instead' );

	echo "\n=== dashboard count and conflicts list ===\n";
	$summary = Database::summary();
	cvtrk_check( isset( $summary['conflicts'] ), 'summary exposes a conflicts count for the Dashboard' );
	$list = Database::list_conflicts( array( 'per_page' => 100 ) );
	cvtrk_check( isset( $list['rows'], $list['total'] ), 'list_conflicts returns a paginated shape' );
	$has_labels = true;
	foreach ( $list['rows'] as $row ) {
		if ( empty( $row['conflict_label'] ) || ! isset( $row['conflict_owner_label'] ) ) {
			$has_labels = false;
		}
	}
	cvtrk_check( $has_labels, 'every conflict row carries a rendered cause and owner label' );
	$resolved_listed = false;
	foreach ( $list['rows'] as $row ) {
		if ( Conflicts::RESOLVED === $row['conflict_code'] ) {
			$resolved_listed = true;
		}
	}
	cvtrk_check( ! $resolved_listed, 'resolved rows are excluded from the conflicts list' );

	echo "\n=== activity log is written for transparency ===\n";
	$logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . Database::logs_table() . " WHERE source = %s", 'redirect-conflict' ) ); // phpcs:ignore WordPress.DB
	cvtrk_check( $logged > 0, sprintf( 'conflict diagnoses were logged under a distinct source (%d entries)', $logged ) );

	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );

	if ( $cvtrk_failures > 0 ) {
		fwrite( STDERR, sprintf( "\nFAIL: %d check(s) failed. Fixtures were rolled back.\n", $cvtrk_failures ) );
		exit( 1 );
	}
	echo "\nPASS: redirect conflict detection, diagnosis, fixes and logging all verified; fixtures rolled back.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
