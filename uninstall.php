<?php
/**
 * Convertrack uninstall routine.
 *
 * Uses the same manifest as reset/deactivation and keyset-paginates multisite
 * cleanup so a large network is never loaded into memory at once.
 *
 * @package Convertrack
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-manifest.php';

global $wpdb;
$convertrack_uninstall_failures = array();

/**
 * Remove all Convertrack state for the current blog.
 *
 * @return array Failures.
 */
$convertrack_cleanup_blog = static function () use ( $wpdb ) {
	$failures = array();
	foreach ( \Convertrack\Manifest::table_suffixes() as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// Suffixes are a fixed internal manifest and prefix is WordPress-owned.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS `$table`" ) ) {
			$failures[] = $table . ': ' . $wpdb->last_error;
		}
	}

	$jobs = \Convertrack\Manifest::cancel_jobs();
	if ( ! empty( $jobs ) ) {
		$failures[] = 'scheduled jobs: ' . implode( ', ', $jobs );
	}

	// Options, migration errors, secrets, cursors, locks and transients all use
	// the plugin-owned prefix. This also removes keys introduced by future
	// modules, preventing reset/uninstall manifest drift.
	$failures = array_merge( $failures, \Convertrack\Manifest::delete_all_options() );
	return $failures;
};

if ( is_multisite() ) {
	$cursor = 0;
	do {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blog_ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id>%d ORDER BY blog_id ASC LIMIT 100", $cursor ) );
		if ( ! empty( $wpdb->last_error ) ) {
			$convertrack_uninstall_failures['network'] = array( 'site query: ' . $wpdb->last_error );
			break;
		}
		foreach ( (array) $blog_ids as $blog_id ) {
			$cursor = (int) $blog_id;
			switch_to_blog( $cursor );
			try {
				$failures = $convertrack_cleanup_blog();
				if ( ! empty( $failures ) ) {
					$convertrack_uninstall_failures[ $cursor ] = $failures;
				}
			} finally {
				restore_current_blog();
			}
		}
	} while ( count( (array) $blog_ids ) === 100 );
	foreach ( \Convertrack\Manifest::network_options() as $option ) {
		delete_site_option( $option );
	}
} else {
	$convertrack_uninstall_failures = $convertrack_cleanup_blog();
}

if ( ! empty( $convertrack_uninstall_failures ) ) {
	// Uninstall has no reliable UI response channel. Preserve a detectable audit
	// trail in the PHP error log instead of silently claiming success.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'Convertrack uninstall incomplete: ' . wp_json_encode( $convertrack_uninstall_failures ) );
}
