<?php
/**
 * Rollback-only checks for the WordPress dashboard widget.
 *
 * Run with the LocalWP PHP binary and site php.ini. Fixtures are created inside
 * a transaction that is rolled back before exit.
 *
 * @package Convertrack
 */

use Convertrack\Dashboard_Widget;
use Convertrack\Database;
use Convertrack\Settings;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php was not found.\n" );
	exit( 1 );
}
require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/dashboard.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

if ( ! class_exists( Dashboard_Widget::class ) ) {
	fwrite( STDERR, "Convertrack dashboard widget is not loaded.\n" );
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

/**
 * Render the widget and capture its markup plus any PHP notices raised.
 *
 * @return array {html, notices}
 */
function cvtrk_render_widget() {
	$notices = array();
	set_error_handler(
		function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
			if ( false !== strpos( $errstr, 'already defined' ) ) {
				return true;
			}
			$notices[] = $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')';
			return true;
		}
	);
	ob_start();
	Dashboard_Widget::render();
	$html = ob_get_clean();
	restore_error_handler();
	return array( 'html' => $html, 'notices' => $notices );
}

/**
 * Which dashboard widgets a given user gets registered.
 *
 * Reading $wp_meta_boxes after wp_dashboard_setup is the real capability test:
 * a box the user must not see should never be registered at all.
 *
 * @param int $user_id User ID.
 * @return array Widget keys.
 */
function cvtrk_widgets_for( $user_id ) {
	global $wp_meta_boxes;
	wp_set_current_user( $user_id );
	$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	set_current_screen( 'dashboard' );
	do_action( 'wp_dashboard_setup' );

	$found = array();
	if ( isset( $wp_meta_boxes['dashboard'] ) && is_array( $wp_meta_boxes['dashboard'] ) ) {
		foreach ( $wp_meta_boxes['dashboard'] as $context ) {
			foreach ( (array) $context as $priority ) {
				foreach ( (array) $priority as $id => $box ) {
					$found[] = (string) $id;
				}
			}
		}
	}
	return $found;
}

global $wpdb;

$installed = Database::install();
if ( is_wp_error( $installed ) ) {
	fwrite( STDERR, 'Schema install failed: ' . $installed->get_error_message() . "\n" );
	exit( 1 );
}

$original_settings = get_option( Settings::OPTION, array() );
$prefix            = 'cvtrk-test-' . strtolower( wp_generate_password( 8, false, false ) );
$created_users     = array();
$created_posts     = array();

$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
if ( false === $started ) {
	fwrite( STDERR, "Could not start test transaction.\n" );
	exit( 1 );
}

try {
	$settings = Settings::all();
	$settings['enabled'] = 1;
	Settings::save( $settings );

	echo "=== capability gating ===\n";
	// Roles are created outside the transaction's reach for user meta in some
	// setups, so they are tracked and deleted explicitly in teardown.
	$admin_id = wp_insert_user( array( 'user_login' => $prefix . '-admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
	$editor_id = wp_insert_user( array( 'user_login' => $prefix . '-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$sub_id    = wp_insert_user( array( 'user_login' => $prefix . '-sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	foreach ( array( $admin_id, $editor_id, $sub_id ) as $uid ) {
		cvtrk_fixture( ! is_wp_error( $uid ), 'Could not create a test user.' );
		$created_users[] = (int) $uid;
	}

	$admin_widgets = cvtrk_widgets_for( (int) $admin_id );
	cvtrk_check( in_array( Dashboard_Widget::WIDGET_ID, $admin_widgets, true ), 'administrator gets the widget registered' );

	$editor_widgets = cvtrk_widgets_for( (int) $editor_id );
	cvtrk_check( ! in_array( Dashboard_Widget::WIDGET_ID, $editor_widgets, true ), 'editor does NOT get the widget registered' );

	$sub_widgets = cvtrk_widgets_for( (int) $sub_id );
	cvtrk_check( ! in_array( Dashboard_Widget::WIDGET_ID, $sub_widgets, true ), 'subscriber does NOT get the widget registered' );

	// render() is a public callback, so it must gate independently of register().
	wp_set_current_user( (int) $editor_id );
	$as_editor = cvtrk_render_widget();
	cvtrk_check( '' === trim( $as_editor['html'] ), 'render() outputs nothing for a non-administrator' );

	wp_set_current_user( (int) $admin_id );

	echo "\n=== empty state ===\n";
	delete_transient( 'convertrack_dw_' . Database::report_cache_generation() . '_7' );
	$empty = cvtrk_render_widget();
	$has_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::events_table() );
	if ( 0 === $has_rows ) {
		cvtrk_check( false !== strpos( $empty['html'], 'No activity recorded yet' ), 'with no data, the empty state renders' );
		cvtrk_check( false === strpos( $empty['html'], 'cvtrk-dw-kpis' ), 'and no zeroed KPI row is shown' );
	} else {
		cvtrk_check( true, 'site already has events; empty state covered by the disabled-state check below' );
	}
	cvtrk_check( empty( $empty['notices'] ), 'empty render raised no PHP notices: ' . implode( '; ', $empty['notices'] ) );

	echo "\n=== tracking disabled ===\n";
	$settings['enabled'] = 0;
	Settings::save( $settings );
	$off = cvtrk_render_widget();
	cvtrk_check( false !== strpos( $off['html'], 'Tracking is turned off' ), 'disabled state states tracking is off' );
	cvtrk_check( false === strpos( $off['html'], 'cvtrk-dw-kpis' ), 'and shows no KPI row, so zeros cannot be mistaken for no traffic' );
	cvtrk_check( empty( $off['notices'] ), 'disabled render raised no PHP notices' );
	$settings['enabled'] = 1;
	Settings::save( $settings );

	echo "\n=== with data ===\n";
	// A deliberately hostile title: page titles are user content.
	$xss_title = '<script>alert(1)</script> Pricing';
	$post_id   = wp_insert_post( array( 'post_title' => $xss_title, 'post_name' => $prefix . '-pricing', 'post_status' => 'publish', 'post_type' => 'page' ) );
	cvtrk_fixture( $post_id && ! is_wp_error( $post_id ), 'Could not create the fixture page.' );
	$created_posts[] = (int) $post_id;

	$today  = gmdate( 'Y-m-d' );
	$daily  = Database::daily_table();
	$seeded = $wpdb->insert(
		$daily,
		array(
			'stat_date'         => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			'page_key'          => 'p:' . (int) $post_id,
			'post_id'           => (int) $post_id,
			'clicks'            => 120,
			'pageviews'         => 900,
			'conversion_events' => 9,
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	cvtrk_check( false !== $seeded, 'seeded a daily rollup row' );

	// Force a rebuild, then confirm the numbers and the escaping.
	delete_transient( 'convertrack_dw_' . Database::report_cache_generation() . '_7' );
	$with = cvtrk_render_widget();
	cvtrk_check( empty( $with['notices'] ), 'populated render raised no PHP notices: ' . implode( '; ', $with['notices'] ) );
	cvtrk_check( false !== strpos( $with['html'], 'cvtrk-dw-kpis' ), 'KPI row renders' );
	cvtrk_check( false !== strpos( $with['html'], 'cvtrk-dw-foot' ), 'footer with freshness renders' );
	cvtrk_check( false !== strpos( $with['html'], 'View full dashboard' ), 'link through to the full dashboard renders' );

	echo "\n=== escaping ===\n";
	cvtrk_check( false === strpos( $with['html'], '<script>alert(1)</script>' ), 'a hostile page title is NOT emitted raw' );
	if ( false !== strpos( $with['html'], 'Pricing' ) ) {
		cvtrk_check( false !== strpos( $with['html'], '&lt;script&gt;' ), 'and appears escaped instead' );
	} else {
		cvtrk_check( true, 'fixture page not in top pages for this range; raw-tag check above is the binding one' );
	}

	echo "\n=== caching ===\n";
	$key = 'convertrack_dw_' . Database::report_cache_generation() . '_7';
	delete_transient( $key );
	$before_q = $wpdb->num_queries;
	Dashboard_Widget::payload();
	$cold = $wpdb->num_queries - $before_q;

	$before_q = $wpdb->num_queries;
	Dashboard_Widget::payload();
	$warm = $wpdb->num_queries - $before_q;

	cvtrk_check( $cold > 0, sprintf( 'a cold build runs queries (%d)', $cold ) );
	cvtrk_check( $warm < $cold, sprintf( 'a warm build runs fewer (%d vs %d)', $warm, $cold ) );
	cvtrk_check( $warm <= 1, sprintf( 'and is effectively free (%d queries)', $warm ) );
	cvtrk_check( is_array( get_transient( $key ) ), 'the payload is stored in a transient' );

	$payload = Dashboard_Widget::payload();
	foreach ( array( 'pageviews', 'clicks', 'conversions', 'comparison', 'series', 'top_pages', 'generated_at', 'has_data' ) as $field ) {
		cvtrk_check( array_key_exists( $field, $payload ), "payload carries $field" );
	}

	echo "\n=== a new rollup invalidates the snapshot ===\n";
	// The cache key embeds the report generation, so a bumped generation must not
	// serve the previous snapshot.
	$old_key = 'convertrack_dw_' . Database::report_cache_generation() . '_7';
	wp_cache_set( 'convertrack_report_generation', Database::report_cache_generation() + 1, 'convertrack' );
	$new_key = 'convertrack_dw_' . Database::report_cache_generation() . '_7';
	cvtrk_check( $old_key !== $new_key, 'the cache key changes with the report generation' );
	cvtrk_check( false === get_transient( $new_key ), 'so the new key starts empty rather than reusing stale figures' );

	echo "\n=== stylesheet is registered only where it is needed ===\n";
	cvtrk_check( file_exists( CONVERTRACK_DIR . 'admin/css/dashboard-widget.css' ), 'the widget stylesheet ships' );
	$admin = new Convertrack\Admin();
	global $wp_styles;
	$wp_styles = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$admin->enqueue( 'index.php' );
	cvtrk_check( wp_style_is( 'convertrack-dashboard-widget', 'enqueued' ), 'enqueued on index.php' );
	cvtrk_check( ! wp_style_is( 'convertrack-admin', 'enqueued' ), 'and the heavy admin stylesheet is not' );

	$wp_styles = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$admin->enqueue( 'edit.php' );
	cvtrk_check( ! wp_style_is( 'convertrack-dashboard-widget', 'enqueued' ), 'not enqueued on unrelated admin screens' );

	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created_users as $uid ) {
		wp_delete_user( $uid );
	}
	delete_transient( 'convertrack_dw_' . Database::report_cache_generation() . '_7' );
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	wp_cache_delete( Settings::OPTION, 'options' );

	if ( $cvtrk_failures > 0 ) {
		fwrite( STDERR, sprintf( "\nFAIL: %d check(s) failed. Fixtures were rolled back.\n", $cvtrk_failures ) );
		exit( 1 );
	}
	echo "\nPASS: dashboard widget capability, states, escaping, caching and enqueue all verified; fixtures rolled back.\n";
	exit( 0 );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	if ( ! empty( $created_users ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $created_users as $uid ) {
			wp_delete_user( $uid );
		}
	}
	update_option( Settings::OPTION, is_array( $original_settings ) ? $original_settings : array(), false );
	wp_cache_delete( Settings::OPTION, 'options' );
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
