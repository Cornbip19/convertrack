<?php
/**
 * Explicit, failure-aware data management operations.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Data_Management {

	/**
	 * Delete analytics events/presence/aggregates only.
	 *
	 * Preserves settings, GSC data, Keyword data, 404 rules/data, credentials,
	 * updater secrets and schema versions.
	 *
	 * @return true|\WP_Error
	 */
	public static function reset_analytics() {
		$jobs = Manifest::cancel_jobs( Manifest::analytics_hooks() );
		if ( ! empty( $jobs ) ) {
			return new \WP_Error( 'convertrack_reset_jobs_failed', 'Analytics workers could not be paused: ' . implode( ', ', $jobs ) );
		}

		$result = Database::reset_all();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$option_failures = Manifest::delete_analytics_options();
		if ( ! empty( $option_failures ) ) {
			return new \WP_Error( 'convertrack_reset_options_failed', implode( '; ', $option_failures ) );
		}
		Database::invalidate_report_cache();
		Cron::schedule();
		return true;
	}

	/**
	 * Delete every operational row while preserving configuration/secrets/schema.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_operational_data() {
		global $wpdb;
		$failures = Manifest::cancel_jobs();
		if ( ! empty( $failures ) ) {
			return new \WP_Error( 'convertrack_operational_jobs_failed', 'Background workers could not be paused: ' . implode( ', ', $failures ) );
		}

		foreach ( Manifest::table_suffixes() as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// Fixed manifest plus WordPress-owned prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "TRUNCATE TABLE `$table`" ) ) {
				$failures[] = $table . ': ' . $wpdb->last_error;
			}
		}

		$failures = array_merge( $failures, Manifest::delete_operational_options() );
		Database::invalidate_report_cache();
		\Convertrack\GSC\Database::clear_summary_cache();
		\Convertrack\GSC\Keywords_Database::clear_summary_cache();
		\Convertrack\NotFound\Database::clear_summary_cache();
		\Convertrack\NotFound\Database::invalidate_redirect_cache( '' );
		if ( ! empty( $failures ) ) {
			return new \WP_Error( 'convertrack_operational_delete_failed', implode( '; ', $failures ) );
		}

		// Configuration was preserved, so restore normal recurring workers.
		Cron::schedule();
		\Convertrack\GSC\Cron::schedule();
		\Convertrack\GSC\Keywords_Cron::schedule();
		\Convertrack\NotFound\Cron::schedule();
		return true;
	}
}
