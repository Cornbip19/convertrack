<?php
/**
 * Focused, rollback-only GSC and Keyword hardening smoke test.
 *
 * Run with the LocalWP PHP binary and site php.ini. Additive schema upgrades
 * are retained; all fixture rows are created inside a rolled-back transaction.
 */

use Convertrack\GSC\Database;
use Convertrack\GSC\Keywords_Database;
use Convertrack\Owner_Lock;
use Convertrack\Safe_Sitemap_Fetcher;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}

require_once $wp_load;

function cvtrk_gsc_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

try {
	$installed = Database::install();
	cvtrk_gsc_assert( ! is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : 'GSC schema migration failed.' );
	$installed = Keywords_Database::install();
	cvtrk_gsc_assert( ! is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : 'Keyword schema migration failed.' );

	$queue_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Database::queue_table(), 0 ); // phpcs:ignore WordPress.DB
	$page_columns  = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Keywords_Database::pages_table(), 0 ); // phpcs:ignore WordPress.DB
	cvtrk_gsc_assert( empty( array_diff( array( 'scan_generation', 'last_seen_at', 'retired_at' ), $queue_columns ) ), 'GSC generation columns are incomplete.' );
	cvtrk_gsc_assert( empty( array_diff( array( 'last_mapping_attempt_at', 'mapping_attempts' ), $page_columns ) ), 'Keyword mapping cursor columns are incomplete.' );
	cvtrk_gsc_assert(
		Keywords_Database::staging_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Keywords_Database::staging_table() ) ),
		'Keyword staging table is missing.'
	);

	$lock_option = 'cvtrk_gsc_smoke_' . strtolower( wp_generate_password( 8, false, false ) );
	$owner       = Owner_Lock::acquire( $lock_option, 30 );
	cvtrk_gsc_assert( is_string( $owner ) && '' !== $owner, 'Initial owner lock acquisition failed.' );
	cvtrk_gsc_assert( false === Owner_Lock::acquire( $lock_option, 30 ), 'A concurrent owner acquired a live lock.' );
	cvtrk_gsc_assert( false === Owner_Lock::release( $lock_option, 'not-the-owner' ), 'A non-owner released the lock.' );
	cvtrk_gsc_assert( Owner_Lock::heartbeat( $lock_option, $owner, 30 ), 'The owner could not heartbeat its lock.' );
	cvtrk_gsc_assert( Owner_Lock::release( $lock_option, $owner ), 'The owner could not release its lock.' );

	foreach ( array( 'http://127.0.0.1/sitemap.xml', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/sitemap.xml' ) as $private_url ) {
		$fetch_state = Safe_Sitemap_Fetcher::start( array( $private_url ) );
		cvtrk_gsc_assert( ! is_wp_error( $fetch_state ), 'Could not create the private-host test state.' );
		$valid = Safe_Sitemap_Fetcher::validate_url( $private_url, $fetch_state['allowed_origins'], 'smoke' );
		cvtrk_gsc_assert( is_wp_error( $valid ), 'A private sitemap endpoint passed outbound validation.' );
	}

	$reference_allowed = new ReflectionMethod( Safe_Sitemap_Fetcher::class, 'reference_allowed' );
	$reference_allowed->setAccessible( true );
	$allowed_origin = array( 'https://public.example:443' );
	cvtrk_gsc_assert( true === $reference_allowed->invoke( null, 'https://public.example/page/', $allowed_origin ), 'A same-origin sitemap page was rejected.' );
	cvtrk_gsc_assert( false === $reference_allowed->invoke( null, 'https://external.example/page/', $allowed_origin ), 'An external sitemap page entered the candidate stream.' );
	cvtrk_gsc_assert( false === $reference_allowed->invoke( null, 'https://user:secret@public.example/page/', $allowed_origin ), 'A credential-bearing sitemap page entered the candidate stream.' );

	cvtrk_gsc_assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start the fixture transaction.' ); // phpcs:ignore WordPress.DB
	try {
		$token       = strtolower( wp_generate_password( 12, false, false ) );
		$url         = add_query_arg( 'cvtrk-gsc-smoke', $token, home_url( '/' ) );
		$sitemap_url = home_url( '/cvtrk-gsc-smoke-sitemap.xml' );
		$seen        = current_time( 'mysql' );
		$id          = Database::upsert_url(
			$url,
			array(
				'sitemap_url'     => $sitemap_url,
				'in_sitemap'      => 1,
				'scan_generation' => 'smoke-a',
				'last_seen_at'    => $seen,
				'index_status'    => 'pending_from_sitemap',
			)
		);
		cvtrk_gsc_assert( ! is_wp_error( $id ), 'Initial GSC queue upsert failed.' );

		$patched = Database::upsert_url(
			$url,
			array(
				'post_id'         => 1,
				'post_type'       => 'post',
				'scan_generation' => 'smoke-b',
				'last_seen_at'    => $seen,
				'index_status'    => 'queued',
				'preserve_status' => 1,
			)
		);
		cvtrk_gsc_assert( $id === $patched, 'Patch upsert changed queue row identity.' );
		$row = Database::get_row( $id );
		cvtrk_gsc_assert(
			$sitemap_url === $row['sitemap_url'] && 1 === (int) $row['in_sitemap'] && md5( strtolower( $sitemap_url ) ) === $row['sitemap_hash'],
			'Patch upsert erased sitemap metadata.'
		);

		$live_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Keywords_Database::keywords_table() ); // phpcs:ignore WordPress.DB
		$sync_id     = 'smoke-' . wp_generate_uuid4();
		$staged      = Keywords_Database::stage_keywords(
			array(
				array(
					'keyword_hash' => md5( $sync_id ),
					'query'        => 'convertrack smoke keyword',
					'page_url'     => $url,
					'page_hash'    => md5( strtolower( $url ) ),
					'country'      => '',
					'device'       => '',
					'clicks'       => 1,
					'impressions'  => 10,
					'ctr'          => 0.1,
					'position'     => 8,
				),
			),
			'28d',
			$sync_id
		);
		cvtrk_gsc_assert( 1 === $staged, 'Keyword staging write failed.' );
		cvtrk_gsc_assert( $live_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Keywords_Database::keywords_table() ), 'Staging mutated live keyword data.' ); // phpcs:ignore WordPress.DB
		cvtrk_gsc_assert( ! is_wp_error( Keywords_Database::discard_generation( $sync_id ) ), 'Keyword staging discard failed.' );

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
		throw $error;
	}

	echo "PASS: GSC and Keyword hardening smoke checks completed.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	if ( isset( $lock_option, $owner ) && is_string( $owner ) ) {
		Owner_Lock::release( $lock_option, $owner );
	}
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
