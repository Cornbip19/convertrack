<?php
/**
 * Convertrack uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes all tables,
 * options, transients and scheduled jobs. Self-contained: plugin classes are
 * not loaded during uninstall.
 *
 * @package Convertrack
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'convertrack_events',
	$wpdb->prefix . 'convertrack_sessions',
	$wpdb->prefix . 'convertrack_daily',
	$wpdb->prefix . 'convertrack_sources',
	$wpdb->prefix . 'convertrack_geo',
	$wpdb->prefix . 'convertrack_search_terms',
	$wpdb->prefix . 'convertrack_gsc_index_queue',
	$wpdb->prefix . 'convertrack_gsc_logs',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
}

// Remove options.
delete_option( 'convertrack_settings' );
delete_option( 'convertrack_db_version' );
delete_option( 'convertrack_gsc_settings' );
delete_option( 'convertrack_gsc_credentials' );
delete_option( 'convertrack_gsc_db_version' );
delete_option( 'convertrack_gsc_quota_usage' );
delete_option( 'convertrack_gsc_last_sync_time' );

// Remove transients (including any per-IP rate-limit keys).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_convertrack\_%' OR option_name LIKE '\_transient\_timeout\_convertrack\_%' OR option_name LIKE '\_transient\_timeout\_convertrack\_gsc\_%' OR option_name LIKE '\_transient\_convertrack\_gsc\_%'" ); // phpcs:ignore WordPress.DB

// Clear scheduled jobs.
wp_clear_scheduled_hook( 'convertrack_hourly' );
wp_clear_scheduled_hook( 'convertrack_session_cleanup' );
wp_clear_scheduled_hook( 'convertrack_gsc_process_queue' );
wp_clear_scheduled_hook( 'convertrack_gsc_scan_sitemap' );
wp_clear_scheduled_hook( 'convertrack_gsc_full_audit' );

// Multisite: best-effort cleanup across sites.
if ( is_multisite() ) {
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB
	foreach ( (array) $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );

		$mt = array(
			$wpdb->prefix . 'convertrack_events',
			$wpdb->prefix . 'convertrack_sessions',
			$wpdb->prefix . 'convertrack_daily',
			$wpdb->prefix . 'convertrack_sources',
			$wpdb->prefix . 'convertrack_geo',
			$wpdb->prefix . 'convertrack_search_terms',
			$wpdb->prefix . 'convertrack_gsc_index_queue',
			$wpdb->prefix . 'convertrack_gsc_logs',
		);
		foreach ( $mt as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'convertrack_settings' );
		delete_option( 'convertrack_db_version' );
		delete_option( 'convertrack_gsc_settings' );
		delete_option( 'convertrack_gsc_credentials' );
		delete_option( 'convertrack_gsc_db_version' );
		delete_option( 'convertrack_gsc_quota_usage' );
		delete_option( 'convertrack_gsc_last_sync_time' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_convertrack\_%' OR option_name LIKE '\_transient\_timeout\_convertrack\_%' OR option_name LIKE '\_transient\_timeout\_convertrack\_gsc\_%' OR option_name LIKE '\_transient\_convertrack\_gsc\_%'" ); // phpcs:ignore WordPress.DB
		wp_clear_scheduled_hook( 'convertrack_hourly' );
		wp_clear_scheduled_hook( 'convertrack_session_cleanup' );
		wp_clear_scheduled_hook( 'convertrack_gsc_process_queue' );
		wp_clear_scheduled_hook( 'convertrack_gsc_scan_sitemap' );
		wp_clear_scheduled_hook( 'convertrack_gsc_full_audit' );

		restore_current_blog();
	}
}
