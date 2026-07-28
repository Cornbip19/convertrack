<?php
/**
 * Rollback-only checks for the index coverage schema, reason backfill, fix
 * suggestions and the validation lifecycle.
 *
 * Run with the LocalWP PHP binary and site php.ini. All fixtures are created
 * inside a transaction that is rolled back before exit. Google is never called:
 * the fix paths exercised here are the local ones.
 *
 * @package Convertrack
 */

use Convertrack\GSC\Database;
use Convertrack\GSC\Index_Fixes;
use Convertrack\GSC\Index_Reasons;
use Convertrack\GSC\Settings;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}
require_once $wp_load;

if ( ! class_exists( Index_Reasons::class ) ) {
	fwrite( STDERR, "Convertrack GSC index reasons are not loaded.\n" );
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
// Idempotency: a second run must be a no-op, not an error.
$again = Database::install();
cvtrk_check( ! is_wp_error( $again ), 'install() is idempotent' );
cvtrk_check( '1.2.0' === Database::DB_VERSION, 'DB_VERSION is 1.2.0' );
cvtrk_check( Database::DB_VERSION === (string) get_option( Database::DB_VERSION_OPTION ), 'stored watermark matches the code' );

$queue   = Database::queue_table();
$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `$queue`", 0 ) );
foreach ( array( 'index_reason', 'fix_code', 'fix_state', 'fix_payload', 'fix_reason_at_apply', 'fix_attempts', 'fix_applied_at', 'validation_started_at', 'validation_checked_at' ) as $column ) {
	cvtrk_check( in_array( $column, $columns, true ), "column $column exists" );
}
$indexes = array_unique( array_map( 'strval', (array) $wpdb->get_col( "SHOW INDEX FROM `$queue`", 2 ) ) );
cvtrk_check( in_array( 'index_reason', $indexes, true ), 'index_reason index exists' );
cvtrk_check( in_array( 'fix_state', $indexes, true ), 'fix_state index exists' );

$original_settings = get_option( Settings::OPTION, array() );
$prefix            = 'cvtrk-test-' . strtolower( wp_generate_password( 8, false, false ) );

$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
if ( false === $started ) {
	fwrite( STDERR, "Could not start test transaction.\n" );
	exit( 1 );
}

/**
 * Seed one queue row with raw inspection fields.
 *
 * @param string $slug   Path slug.
 * @param array  $fields Inspection fields.
 * @return int Row ID.
 */
function cvtrk_seed_row( $slug, array $fields ) {
	global $wpdb;
	$now = current_time( 'mysql' );
	$url = home_url( '/' . $slug . '/' );

	$data = wp_parse_args(
		$fields,
		array(
			'url_hash'         => md5( strtolower( $url ) ),
			'url'              => $url,
			'post_id'          => 0,
			'post_type'        => '',
			'index_status'     => 'not_indexed',
			'index_reason'     => '',
			'coverage_state'   => '',
			'google_verdict'   => 'FAIL',
			'robots_txt_state' => '',
			'indexing_state'   => '',
			'page_fetch_state' => '',
			'user_canonical'   => '',
			'google_canonical' => '',
			'fix_state'        => 'none',
			'last_checked_at'  => $now,
			'created_at'       => $now,
			'updated_at'       => $now,
		)
	);
	$inserted = $wpdb->insert( Database::queue_table(), $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	cvtrk_fixture( false !== $inserted, 'Could not seed queue fixture ' . $slug );
	return (int) $wpdb->insert_id;
}

try {
	$settings = Settings::all();
	$settings['enabled'] = 1;
	Settings::save( $settings );

	echo "\n=== reason backfill on already-inspected rows ===\n";
	// These arrive with index_reason = '' exactly as historic rows do.
	$cases = array(
		'404'       => array( 'slug' => $prefix . '-gone', 'fields' => array( 'page_fetch_state' => 'NOT_FOUND' ), 'expect' => Index_Reasons::NOT_FOUND_404 ),
		'redirect'  => array( 'slug' => $prefix . '-moved', 'fields' => array( 'page_fetch_state' => 'REDIRECT_ERROR' ), 'expect' => Index_Reasons::PAGE_WITH_REDIRECT ),
		'robots'    => array( 'slug' => $prefix . '-blocked', 'fields' => array( 'robots_txt_state' => 'DISALLOWED' ), 'expect' => Index_Reasons::BLOCKED_ROBOTS_TXT ),
		'noindex'   => array( 'slug' => $prefix . '-hidden', 'fields' => array( 'indexing_state' => 'BLOCKED_BY_META_TAG' ), 'expect' => Index_Reasons::NOINDEX_TAG ),
		'crawled'   => array( 'slug' => $prefix . '-thin', 'fields' => array( 'coverage_state' => 'Crawled - currently not indexed' ), 'expect' => Index_Reasons::CRAWLED_NOT_INDEXED ),
		'forbidden' => array( 'slug' => $prefix . '-locked', 'fields' => array( 'page_fetch_state' => 'ACCESS_FORBIDDEN' ), 'expect' => Index_Reasons::FORBIDDEN_403 ),
		'alternate' => array( 'slug' => $prefix . '-alt', 'fields' => array( 'coverage_state' => 'Alternate page with proper canonical tag' ), 'expect' => Index_Reasons::ALTERNATE_CANONICAL ),
		'canonical' => array(
			'slug'   => $prefix . '-dupe',
			'fields' => array(
				'coverage_state'   => 'Duplicate, Google chose different canonical than user',
				'user_canonical'   => home_url( '/' . $prefix . '-dupe/' ),
				'google_canonical' => home_url( '/' . $prefix . '-canonical/' ),
			),
			'expect' => Index_Reasons::DUPLICATE_GOOGLE_CANONICAL,
		),
	);

	$ids = array();
	foreach ( $cases as $key => $case ) {
		$ids[ $key ] = cvtrk_seed_row( $case['slug'], $case['fields'] );
	}

	$filled = Database::backfill_reasons();
	cvtrk_check( ! is_wp_error( $filled ) && (int) $filled >= count( $cases ), sprintf( 'backfill labelled the seeded rows (%s)', is_wp_error( $filled ) ? $filled->get_error_message() : (int) $filled ) );

	foreach ( $cases as $key => $case ) {
		$row = Database::get_url( $ids[ $key ] );
		cvtrk_check(
			$row && $case['expect'] === $row['index_reason'],
			sprintf( '%-10s -> %s', $key, $row ? $row['index_reason'] : 'missing' )
		);
	}

	echo "\n=== breakdown aggregation ===\n";
	$breakdown = Database::reason_breakdown();
	$by_code   = array();
	foreach ( $breakdown as $entry ) {
		$by_code[ $entry['reason'] ] = $entry;
	}
	cvtrk_check( isset( $by_code[ Index_Reasons::NOT_FOUND_404 ] ), 'breakdown includes not_found_404' );
	cvtrk_check( isset( $by_code[ Index_Reasons::ALTERNATE_CANONICAL ] ), 'breakdown includes alternate_canonical' );
	if ( isset( $by_code[ Index_Reasons::NOT_FOUND_404 ] ) ) {
		$e = $by_code[ Index_Reasons::NOT_FOUND_404 ];
		cvtrk_check( 'site' === $e['owner'], '404 is attributed to the website' );
		cvtrk_check( true === $e['is_error'], '404 is flagged as an error' );
		cvtrk_check( 'not_started' === $e['validation'], '404 validation starts at not_started' );
	}
	if ( isset( $by_code[ Index_Reasons::ALTERNATE_CANONICAL ] ) ) {
		cvtrk_check( false === $by_code[ Index_Reasons::ALTERNATE_CANONICAL ]['is_error'], 'alternate_canonical is NOT flagged as an error' );
	}
	if ( isset( $by_code[ Index_Reasons::CRAWLED_NOT_INDEXED ] ) ) {
		cvtrk_check( 'google' === $by_code[ Index_Reasons::CRAWLED_NOT_INDEXED ]['owner'], 'crawled-not-indexed is attributed to Google systems' );
	}

	echo "\n=== reason filters ===\n";
	$errors_only = Database::list_urls( array( 'reason' => 'any_error', 'per_page' => 100 ) );
	$listed      = wp_list_pluck( $errors_only['rows'], 'index_reason' );
	cvtrk_check( ! in_array( Index_Reasons::ALTERNATE_CANONICAL, $listed, true ), '"any problem" excludes alternate_canonical' );
	cvtrk_check( in_array( Index_Reasons::NOT_FOUND_404, $listed, true ), '"any problem" includes not_found_404' );

	$one = Database::list_urls( array( 'reason' => Index_Reasons::NOINDEX_TAG, 'per_page' => 100 ) );
	cvtrk_check( (int) $one['total'] >= 1, 'single-reason filter returns rows' );
	$bogus = Database::list_urls( array( 'reason' => 'not_a_reason', 'per_page' => 100 ) );
	cvtrk_check( 0 === (int) $bogus['total'], 'unknown reason matches nothing rather than everything' );

	echo "\n=== decorated output carries labels ===\n";
	$decorated = $one['rows'][0];
	cvtrk_check( ! empty( $decorated['reason_label'] ), 'row carries a human reason label: ' . $decorated['reason_label'] );
	cvtrk_check( ! empty( $decorated['reason_summary'] ), 'row carries an explanation of the cause' );
	cvtrk_check( isset( $decorated['reason_owner'] ), 'row carries the owner attribution' );

	echo "\n=== fix suggestions ===\n";
	// 404 -> reuses the Broken URLs matcher. Give it something to find.
	$target = wp_insert_post( array( 'post_title' => 'CVTRK Test Landing', 'post_name' => $prefix . '-landing', 'post_status' => 'publish', 'post_type' => 'post' ) );
	cvtrk_fixture( $target && ! is_wp_error( $target ), 'Could not create redirect target fixture.' );
	$stored = \Convertrack\NotFound\Database::upsert_valid_url(
		get_permalink( $target ),
		array( 'post_id' => (int) $target, 'post_type' => 'post', 'source' => 'post', 'priority' => 100, 'tokens' => \Convertrack\NotFound\Matcher::tokens_string( get_permalink( $target ) ) )
	);
	cvtrk_fixture( ! is_wp_error( $stored ), 'Could not seed a Broken URLs candidate.' );

	$dead    = cvtrk_seed_row( $prefix . '-landing-old', array( 'page_fetch_state' => 'NOT_FOUND', 'index_reason' => Index_Reasons::NOT_FOUND_404 ) );
	$dead_row = Database::get_url( $dead );
	$suggest = Index_Fixes::suggest( $dead_row );
	cvtrk_check( Index_Fixes::CREATE_REDIRECT === $suggest['fix_code'], '404 suggests create_redirect (got ' . $suggest['fix_code'] . ')' );
	cvtrk_check(
		Index_Fixes::STATE_AVAILABLE === $suggest['fix_state'] && ! empty( $suggest['payload']['target'] ),
		'and proposes a concrete target: ' . ( isset( $suggest['payload']['target'] ) ? $suggest['payload']['target'] : '(none)' )
	);
	cvtrk_check(
		isset( $suggest['payload']['confidence'] ) && $suggest['payload']['confidence'] > 0,
		'carrying the matcher confidence through: ' . ( isset( $suggest['payload']['confidence'] ) ? $suggest['payload']['confidence'] . '%' : 'n/a' )
	);

	// Reasons with no safe automated action must say so rather than inventing one.
	foreach ( array( 'forbidden' => Index_Reasons::FORBIDDEN_403, 'alternate' => Index_Reasons::ALTERNATE_CANONICAL ) as $key => $reason ) {
		$row = Database::get_url( $ids[ $key ] );
		$s   = Index_Fixes::suggest( $row );
		cvtrk_check(
			Index_Fixes::STATE_AVAILABLE !== $s['fix_state'],
			sprintf( '%s offers no one-click fix (state=%s)', $reason, $s['fix_state'] )
		);
	}

	echo "\n=== robots.txt fix respects a physical file ===\n";
	$blocked = Database::get_url( $ids['robots'] );
	$s       = Index_Fixes::suggest( $blocked );
	$has_file = file_exists( ABSPATH . 'robots.txt' );
	if ( $has_file ) {
		cvtrk_check( Index_Fixes::STATE_UNAVAILABLE === $s['fix_state'], 'a real robots.txt exists, so the fix refuses' );
		cvtrk_check( ! empty( $s['payload']['manual_line'] ), 'and returns the exact line to add: ' . ( isset( $s['payload']['manual_line'] ) ? $s['payload']['manual_line'] : '' ) );
	} else {
		cvtrk_check( Index_Fixes::STATE_AVAILABLE === $s['fix_state'], 'no physical robots.txt, so an Allow rule is offered' );
		cvtrk_check( ! empty( $s['payload']['rule'] ), 'with the rule to add: ' . ( isset( $s['payload']['rule'] ) ? $s['payload']['rule'] : '' ) );
	}

	echo "\n=== noindex fix never flips the site-wide setting ===\n";
	$blog_public_before = get_option( 'blog_public' );
	$hidden             = Database::get_url( $ids['noindex'] );
	$s                  = Index_Fixes::suggest( $hidden );
	if ( ! $blog_public_before ) {
		cvtrk_check( Index_Fixes::STATE_UNAVAILABLE === $s['fix_state'], 'site-wide discourage-search-engines is reported, not auto-fixed' );
		cvtrk_check( isset( $s['payload']['scope'] ) && 'site' === $s['payload']['scope'], 'and flagged as site-wide scope' );
	} else {
		cvtrk_check( Index_Fixes::STATE_AVAILABLE !== $s['fix_state'] || ! empty( $s['payload']['meta_key'] ), 'a per-page noindex fix names the exact meta key it would clear' );
	}
	cvtrk_check( get_option( 'blog_public' ) === $blog_public_before, 'suggesting a fix never changed blog_public' );

	echo "\n=== storing and applying ===\n";
	$saved = Database::save_fix_suggestion( $dead, $suggest );
	cvtrk_check( ! is_wp_error( $saved ), 'suggestion stored' );
	$reloaded = Database::get_url( $dead );
	cvtrk_check( Index_Fixes::CREATE_REDIRECT === $reloaded['fix_code'], 'stored fix_code round-trips' );
	cvtrk_check( Index_Fixes::STATE_AVAILABLE === $reloaded['fix_state'], 'stored fix_state round-trips' );

	$applied = Index_Fixes::apply( $reloaded );
	cvtrk_check( ! is_wp_error( $applied ), 'apply() created the redirect' . ( is_wp_error( $applied ) ? ': ' . $applied->get_error_message() : '' ) );
	if ( ! is_wp_error( $applied ) ) {
		$rule = \Convertrack\NotFound\Database::find_active_redirect( home_url( '/' . $prefix . '-landing-old/' ) );
		cvtrk_check( is_array( $rule ), 'and a live redirect rule now exists for the dead URL' );
	}

	$marked = Database::mark_fix_applied( $dead, Index_Reasons::NOT_FOUND_404 );
	cvtrk_check( ! is_wp_error( $marked ), 'fix recorded as applied' );
	$after = Database::get_url( $dead );
	cvtrk_check( Index_Fixes::STATE_APPLIED === $after['fix_state'], 'row moved to applied' );
	cvtrk_check( Index_Reasons::NOT_FOUND_404 === $after['fix_reason_at_apply'], 'reason at apply time was recorded for validation' );
	cvtrk_check( ! empty( $after['next_check_at'] ), 'row was queued for re-inspection' );

	echo "\n=== a stored suggestion never overwrites an in-flight validation ===\n";
	$overwrite = Database::save_fix_suggestion( $dead, array( 'fix_code' => Index_Fixes::REQUEST_INDEXING, 'fix_state' => Index_Fixes::STATE_AVAILABLE, 'payload' => array() ) );
	cvtrk_check( ! is_wp_error( $overwrite ), 'a re-suggestion is accepted without error' );
	$still = Database::get_url( $dead );
	cvtrk_check( Index_Fixes::STATE_APPLIED === $still['fix_state'], 'but the applied state is preserved' );
	cvtrk_check( Index_Fixes::CREATE_REDIRECT === $still['fix_code'], 'and the applied fix_code is preserved' );

	echo "\n=== validation transitions persist ===\n";
	$step = Index_Fixes::advance_validation( Index_Fixes::STATE_APPLIED, Index_Reasons::NOT_FOUND_404, Index_Reasons::NOT_FOUND_404, 0 );
	Database::update_validation( $dead, $step['state'], $step['attempts'] );
	$v = Database::get_url( $dead );
	cvtrk_check( Index_Fixes::STATE_VERIFYING === $v['fix_state'] && 1 === (int) $v['fix_attempts'], 'unchanged reason -> verifying, attempt 1' );

	$step = Index_Fixes::advance_validation( Index_Fixes::STATE_VERIFYING, Index_Reasons::NOT_FOUND_404, Index_Reasons::INDEXED, 1 );
	Database::update_validation( $dead, $step['state'], $step['attempts'] );
	$v = Database::get_url( $dead );
	cvtrk_check( Index_Fixes::STATE_PASSED === $v['fix_state'], 'reason cleared -> passed' );

	$breakdown = Database::reason_breakdown();
	foreach ( $breakdown as $entry ) {
		if ( Index_Reasons::NOT_FOUND_404 === $entry['reason'] ) {
			cvtrk_check( $entry['passed'] >= 1, 'breakdown now counts a passed validation for 404' );
		}
	}

	echo "\n=== fix suggestions are backfilled without inspection quota ===\n";
	// Historic rows get a reason from the backfill but no proposal until they are
	// re-inspected, which is capped at 2,000 URLs/day. This pass closes that gap
	// using only stored data.
	$proposed = Database::backfill_fix_suggestions( 100 );
	cvtrk_check( ! is_wp_error( $proposed ) && (int) $proposed > 0, sprintf( 'suggestions proposed for existing rows (%s)', is_wp_error( $proposed ) ? $proposed->get_error_message() : (int) $proposed ) );

	$settled = 0;
	foreach ( $ids as $key => $id ) {
		$row = Database::get_url( $id );
		if ( Index_Fixes::STATE_NONE !== $row['fix_state'] ) {
			$settled++;
		}
	}
	cvtrk_check( $settled === count( $ids ), sprintf( 'every backfilled row settled to a definite fix state (%d/%d)', $settled, count( $ids ) ) );

	// Idempotent: a second pass must find nothing left to do, otherwise cron would
	// churn the same rows forever.
	$again_pass = Database::backfill_fix_suggestions( 100 );
	cvtrk_check( ! is_wp_error( $again_pass ) && 0 === (int) $again_pass, sprintf( 'a second pass is a no-op (%s)', is_wp_error( $again_pass ) ? 'error' : (int) $again_pass ) );

	$robots_row = Database::get_url( $ids['robots'] );
	cvtrk_check(
		in_array( $robots_row['fix_state'], array( Index_Fixes::STATE_AVAILABLE, Index_Fixes::STATE_UNAVAILABLE ), true ),
		'the robots.txt row now carries a definite fix outcome: ' . $robots_row['fix_state']
	);
	$alt_row = Database::get_url( $ids['alternate'] );
	cvtrk_check(
		Index_Fixes::STATE_AVAILABLE !== $alt_row['fix_state'],
		'alternate_canonical is never given a fix button (state=' . $alt_row['fix_state'] . ')'
	);

	echo "\n=== inspected count ===\n";
	cvtrk_check( Database::inspected_count() >= count( $cases ), 'inspected_count reflects checked rows' );

	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	wp_delete_post( (int) $target, true );
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	// GSC\Settings caches in a static that dies with the process, so clearing the
	// options cache is all the teardown that is needed here.
	wp_cache_delete( Settings::OPTION, "options" );

	if ( $cvtrk_failures > 0 ) {
		fwrite( STDERR, sprintf( "\nFAIL: %d check(s) failed. Fixtures were rolled back.\n", $cvtrk_failures ) );
		exit( 1 );
	}
	echo "\nPASS: index coverage schema, reasons, fixes and validation all verified; fixtures rolled back.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	if ( ! empty( $target ) && ! is_wp_error( $target ) ) {
		wp_delete_post( (int) $target, true );
	}
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	// GSC\Settings caches in a static that dies with the process, so clearing the
	// options cache is all the teardown that is needed here.
	wp_cache_delete( Settings::OPTION, "options" );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
