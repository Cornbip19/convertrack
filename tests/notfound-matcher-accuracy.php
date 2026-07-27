<?php
/**
 * Rollback-only accuracy checks for the 404 Monitor suggestion engine and the
 * review-queue ordering/filter behaviour.
 *
 * Run with the LocalWP PHP binary and site php.ini. Every fixture is created
 * inside a transaction that is rolled back before exit.
 */

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

if ( ! class_exists( Matcher::class ) ) {
	fwrite( STDERR, "Convertrack 404 Monitor is not loaded.\n" );
	exit( 1 );
}

$cvtrk_failures = 0;

/**
 * Report one assertion without aborting, so a single miss does not hide the rest.
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
 * Abort on a broken fixture — a bad fixture invalidates everything after it.
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
$installed = Database::install();
if ( is_wp_error( $installed ) ) {
	fwrite( STDERR, 'Schema upgrade failed: ' . $installed->get_error_message() . "\n" );
	exit( 1 );
}

$original_settings = get_option( Settings::OPTION, array() );
$prefix            = 'cvtrk-test-' . strtolower( wp_generate_password( 8, false, false ) );
$events            = Database::events_table();
$valid             = Database::valid_urls_table();

$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
if ( false === $started ) {
	fwrite( STDERR, "Could not start test transaction.\n" );
	exit( 1 );
}

/**
 * Seed a valid_urls candidate directly, bypassing a full sitemap refresh.
 *
 * @param string $path Root-relative path.
 * @param array  $args Candidate metadata.
 * @return void
 */
function cvtrk_seed_candidate( $path, array $args = array() ) {
	$stored = Database::upsert_valid_url(
		home_url( $path ),
		wp_parse_args(
			$args,
			array(
				'source'   => 'post',
				'priority' => 100,
				'tokens'   => Matcher::tokens_string( $path ),
			)
		)
	);
	cvtrk_fixture( ! is_wp_error( $stored ), 'Could not seed candidate ' . $path );
}

/**
 * Score a broken path through the real recommend() path.
 *
 * @param string $path Root-relative broken path.
 * @return array
 */
function cvtrk_recommend( $path ) {
	$source = Database::normalize_source( $path );
	cvtrk_fixture( ! empty( $source ), 'Could not normalize ' . $path );
	return Matcher::recommend( array( 'path' => $source['path'] ) );
}

try {
	$settings                 = Settings::all();
	$settings['enabled']      = 1;
	$settings['mode']         = 'recommend';
	$settings['fallback_url'] = '';
	Settings::save( $settings );

	// Hide pre-existing candidates so scoring is deterministic against fixtures.
	// Everything here is rolled back, so the candidates need no shared marker —
	// and must not have one: a common token prefix would make every candidate
	// score against every probe and no probe would isolate what it claims to.
	$wpdb->query( "UPDATE $valid SET status = 'stale' WHERE status = 'active'" ); // phpcs:ignore WordPress.DB

	cvtrk_seed_candidate( '/zephyr-contact-us/' );
	cvtrk_seed_candidate( '/quasar-products/' );
	cvtrk_seed_candidate( '/news/nimbus-topic/', array( 'source' => 'taxonomy_archive', 'priority' => 55 ) );

	echo "\n--- suggestion accuracy ---\n";

	// D1: a correct-but-longer slug used to score 37 under Jaccard and fall below
	// the recommend floor. D12: it was then replaced outright by the fallback.
	$d1 = cvtrk_recommend( '/zephyr-contact/' );
	cvtrk_check(
		false !== strpos( $d1['url'], '/zephyr-contact-us/' ) && $d1['confidence'] >= Matcher::RECOMMEND_FLOOR,
		sprintf( 'D1 superset slug recommends the real target (url=%s confidence=%d reason=%s)', $d1['url'], $d1['confidence'], $d1['reason'] )
	);

	// D5: a transposition shares no tokens at all and previously scored zero.
	$d5 = cvtrk_recommend( '/quasar-produtcs/' );
	cvtrk_check(
		false !== strpos( $d5['url'], '/quasar-products/' ) && $d5['confidence'] >= Matcher::RECOMMEND_FLOOR,
		sprintf( 'D5 typo slug recommends the real target (url=%s confidence=%d reason=%s)', $d5['url'], $d5['confidence'], $d5['reason'] )
	);

	// D2: an archive was floored to 50-65 for any nonzero token overlap, which
	// outranked genuine content and shipped as "Recommended". The slug differs
	// from the archive's own so this resolves by similarity, where the cap lives.
	$d2 = cvtrk_recommend( '/nimbus-topic-listing/' );
	cvtrk_check(
		'archive_match' === $d2['reason'] && $d2['confidence'] <= Matcher::ARCHIVE_CEILING,
		sprintf( 'D2 archive match is capped and cannot ship as a recommendation (url=%s confidence=%d reason=%s)', $d2['url'], $d2['confidence'], $d2['reason'] )
	);
	cvtrk_check(
		$d2['confidence'] < Matcher::RECOMMEND_FLOOR,
		sprintf( 'D2 archive match lands in manual review, not Recommended (confidence=%d)', $d2['confidence'] )
	);

	// D7: mechanical decoration must resolve to the underlying URL.
	foreach ( array( '/page/2/', '.html', '/amp/', '/feed/', '/comment-page-3/' ) as $suffix ) {
		$hit = cvtrk_recommend( '/quasar-products' . $suffix );
		cvtrk_check(
			false !== strpos( $hit['url'], '/quasar-products/' ) && $hit['confidence'] >= 85,
			sprintf( 'D7 "%s" resolves to the base URL (url=%s confidence=%d reason=%s)', $suffix, $hit['url'], $hit['confidence'], $hit['reason'] )
		);
	}

	// Tier 1 still wins outright.
	$exact = cvtrk_recommend( '/quasar-products/' );
	cvtrk_check( 100 === (int) $exact['confidence'] && 'exact_path' === $exact['reason'], 'Exact path scores 100 via exact_path.' );

	// D13: an unsanitized source slug could never equal a stored sanitize_title slug.
	$d13 = cvtrk_recommend( '/quasar%20products/' );
	cvtrk_check(
		false !== strpos( $d13['url'], '/quasar-products/' ),
		sprintf( 'D13 space in the source slug still matches (url=%s reason=%s)', $d13['url'], $d13['reason'] )
	);

	// D11/D12: with no plausible target and no fallback, stay honestly empty
	// rather than suggesting the homepage.
	$unmatchable = '/qzxwvu-9471-hjklmn-vanished/';
	$none        = cvtrk_recommend( $unmatchable );
	cvtrk_check( '' === $none['url'], sprintf( 'D11 unmatchable path yields no suggestion (url=%s)', $none['url'] ) );

	// D12: a configured fallback must not overwrite a real match.
	$settings['fallback_url'] = home_url( '/' );
	Settings::save( $settings );
	$with_fallback = cvtrk_recommend( '/zephyr-contact/' );
	cvtrk_check(
		'fallback' !== $with_fallback['reason'] && false !== strpos( $with_fallback['url'], '/zephyr-contact-us/' ),
		sprintf( 'D12 fallback does not replace a real match (url=%s reason=%s)', $with_fallback['url'], $with_fallback['reason'] )
	);
	$fallback_only = cvtrk_recommend( $unmatchable );
	cvtrk_check(
		'fallback' === $fallback_only['reason'] && $fallback_only['confidence'] < Matcher::RECOMMEND_FLOOR,
		sprintf( 'Fallback applies only when nothing matched, at low confidence (reason=%s confidence=%d)', $fallback_only['reason'], $fallback_only['confidence'] )
	);
	$settings['fallback_url'] = '';
	Settings::save( $settings );

	// D10: nothing decided by slug or fuzzy similarity may reach the default
	// auto-redirect threshold of 90.
	$threshold = (int) Settings::get( 'auto_min_confidence', 90 );
	cvtrk_check( Matcher::FUZZY_CEILING < $threshold, sprintf( 'D10 fuzzy ceiling %d stays below the auto threshold %d.', Matcher::FUZZY_CEILING, $threshold ) );
	cvtrk_seed_candidate( '/alpha/orion-dup/' );
	cvtrk_seed_candidate( '/beta/orion-dup/' );
	$dup = cvtrk_recommend( '/gamma/orion-dup/' );
	cvtrk_check(
		$dup['confidence'] < $threshold,
		sprintf( 'D10 colliding slug stays below the auto threshold (confidence=%d reason=%s)', $dup['confidence'], $dup['reason'] )
	);

	echo "\n--- ordering and filters ---\n";

	// One event per status, all with equal hits so only the status rank can order them.
	$now      = current_time( 'mysql' );
	$statuses = array( 'ignored', 'auto_redirected', 'approved', 'new', 'manual_review', 'recommended' );
	foreach ( $statuses as $index => $status ) {
		$source = Database::normalize_source( '/' . $prefix . '-order-' . $status . '/' );
		cvtrk_fixture( ! empty( $source ), 'Could not normalize ordering fixture.' );
		$inserted = $wpdb->insert(
			$events,
			array(
				'url_hash'                  => $source['hash'],
				'url'                       => $source['url'],
				'path'                      => $source['path'],
				'first_detected_at'         => $now,
				'last_detected_at'          => $now,
				'hit_count'                 => 100,
				'status'                    => $status,
				'recommendation_generation' => Database::RECOMMENDATION_GENERATION,
				'recommendation_state'      => 'completed',
				'created_at'                => $now,
				'updated_at'                => $now,
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		cvtrk_fixture( false !== $inserted, 'Could not seed ordering fixture for ' . $status );
	}

	$listed = Database::list_events( array( 'status' => 'all', 'search' => $prefix . '-order-', 'per_page' => 50 ) );
	$order  = wp_list_pluck( $listed['rows'], 'status' );
	cvtrk_check(
		array( 'recommended', 'manual_review', 'new', 'approved', 'auto_redirected', 'ignored' ) === $order,
		'Rows awaiting redirection sort to the front and finished rows to the back: ' . implode( ', ', $order )
	);

	$needs = Database::list_events( array( 'status' => 'needs_action', 'search' => $prefix . '-order-', 'per_page' => 50 ) );
	cvtrk_check( 3 === (int) $needs['total'], sprintf( '"Needs action" returns the 3 actionable rows (got %d).', $needs['total'] ) );
	cvtrk_check(
		array( 'recommended', 'manual_review', 'new' ) === wp_list_pluck( $needs['rows'], 'status' ),
		'"Needs action" contains exactly recommended, manual_review and new.'
	);

	$done = Database::list_events( array( 'status' => 'redirected', 'search' => $prefix . '-order-', 'per_page' => 50 ) );
	cvtrk_check( 2 === (int) $done['total'], sprintf( '"Redirected" returns the 2 redirected rows (got %d).', $done['total'] ) );

	$single = Database::list_events( array( 'status' => 'manual_review', 'search' => $prefix . '-order-', 'per_page' => 50 ) );
	cvtrk_check( 1 === (int) $single['total'], sprintf( 'A single-status filter still works (got %d).', $single['total'] ) );

	$bogus = Database::list_events( array( 'status' => 'not_a_status', 'search' => $prefix . '-order-', 'per_page' => 50 ) );
	cvtrk_check( 0 === (int) $bogus['total'], sprintf( 'An unknown status matches nothing rather than everything (got %d).', $bogus['total'] ) );

	// The export duplicates the filter block; prove both callers share it.
	$exported = Database::export_events_cursor( array( 'status' => 'needs_action', 'search' => $prefix . '-order-' ), 0, 500 );
	$exported_statuses = wp_list_pluck( $exported['rows'], 'status' );
	sort( $exported_statuses );
	cvtrk_check(
		array( 'manual_review', 'new', 'recommended' ) === $exported_statuses,
		'The CSV export cursor honours group filters identically: ' . implode( ', ', $exported_statuses )
	);

	echo "\n--- schema ---\n";
	$indexes = (array) $wpdb->get_col( "SHOW INDEX FROM `$valid`", 2 ); // phpcs:ignore WordPress.DB
	cvtrk_check( in_array( 'slug', $indexes, true ), 'valid_urls has a slug index.' );
	cvtrk_check( in_array( 'path', $indexes, true ), 'valid_urls has a path index.' );
	cvtrk_check( '1.3.0' === Database::DB_VERSION, 'Schema version was bumped for the new indexes.' );
	cvtrk_check( Database::schema_is_healthy(), 'verify_schema() accepts the upgraded schema.' );

	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );

	if ( $cvtrk_failures > 0 ) {
		fwrite( STDERR, sprintf( "\nFAIL: %d check(s) failed. Fixtures were rolled back.\n", $cvtrk_failures ) );
		exit( 1 );
	}
	echo "\nPASS: 404 matcher accuracy and review-queue checks completed; fixtures were rolled back.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	Settings::flush_cache();
	wp_cache_delete( Settings::OPTION, 'options' );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
