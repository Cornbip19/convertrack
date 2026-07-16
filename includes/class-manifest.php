<?php
/**
 * Shared storage and lifecycle manifest.
 *
 * Keep reset, multisite provisioning and uninstall behavior in one place.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Manifest {

	/** @return array Analytics table suffixes relative to the current blog prefix. */
	public static function analytics_table_suffixes() {
		return array(
			'convertrack_events',
			'convertrack_sessions',
			'convertrack_daily',
			'convertrack_sources',
			'convertrack_geo',
			'convertrack_search_terms',
			'convertrack_rollup_state',
			'convertrack_rollup_stage',
			'convertrack_visitor_days',
			'convertrack_session_days',
		);
	}

	/** @return array Every operational table suffix owned by the plugin. */
	public static function table_suffixes() {
		return array_merge(
			self::analytics_table_suffixes(),
			array(
				'convertrack_ingestion_limits',
				'convertrack_ingestion_metrics',
				'convertrack_gsc_index_queue',
				'convertrack_gsc_logs',
				'convertrack_gsc_keywords',
				'convertrack_gsc_keyword_pages',
				'convertrack_gsc_keywords_stage',
				'convertrack_404_events',
				'convertrack_404_redirects',
				'convertrack_404_valid_urls',
				'convertrack_404_logs',
				'convertrack_404_hit_buckets',
				'convertrack_404_rate_limits',
			)
		);
	}

	/**
	 * Per-blog operational options removed by "Delete operational data".
	 *
	 * Configuration, credentials, schema versions/status and the updater secret
	 * are deliberately absent. Plugin transients are removed separately because
	 * their timeout/value pairs have dynamic names.
	 *
	 * @return array
	 */
	public static function analytics_operational_options() {
		return array(
			'convertrack_privacy_scrub_state',
			'convertrack_rollup_last_error',
			'convertrack_cleanup_last_error',
			'convertrack_last_cleanup',
		);
	}

	/** @return array Every per-blog operational option owned by the plugin. */
	public static function operational_options() {
		return array_merge(
			self::analytics_operational_options(),
			array(
				'convertrack_gsc_quota_usage',
				'convertrack_gsc_last_sync_time',
				'convertrack_gsc_processing_lock',
				'convertrack_gsc_last_batch_error',
				'convertrack_gsc_status_history',
				'convertrack_gsc_sitemap_scan_state',
				'convertrack_gsc_sitemap_scan_lock',
				'convertrack_gsc_sitemap_submissions',
				'convertrack_gsc_last_cleanup',
				'convertrack_gsc_keywords_sync_state',
				'convertrack_gsc_keywords_last_sync',
				'convertrack_gsc_keywords_sync_lock',
				'convertrack_gsc_keywords_last_error',
				'convertrack_gsc_keywords_analysis_lock',
				'convertrack_gsc_keywords_last_analysis_error',
				'convertrack_gsc_keywords_mapping_cursor',
				'convertrack_404_last_sitemap_refresh',
				'convertrack_404_last_spike_notice',
				'convertrack_404_sitemap_scan_state',
				'convertrack_404_sitemap_scan_lock',
			)
		);
	}

	/** @return array Failures while deleting analytics-only worker options. */
	public static function delete_analytics_options() {
		return self::delete_exact_options( self::analytics_operational_options() );
	}

	/** @return array Network options owned by the plugin. */
	public static function network_options() {
		return array(
			'convertrack_network_provision_state',
			'convertrack_network_cleanup_state',
		);
	}

	/** @return array Core analytics hooks safe to pause during an analytics reset. */
	public static function analytics_hooks() {
		return array(
			'convertrack_hourly',
			'convertrack_session_cleanup',
			'convertrack_rollup_continue',
			'convertrack_privacy_scrub_batch',
		);
	}

	/** @return array Every WP-Cron/Action Scheduler hook owned by the plugin. */
	public static function hooks() {
		return array_merge(
			self::analytics_hooks(),
			array(
				'convertrack_multisite_provision',
				'convertrack_gsc_process_queue',
				'convertrack_gsc_process_now',
				'convertrack_gsc_scan_sitemap',
				'convertrack_gsc_scan_sitemap_step',
				'convertrack_gsc_full_audit',
				'convertrack_gsc_keywords_tick',
				'convertrack_gsc_keywords_sync_step',
				'convertrack_gsc_keywords_analyze_now',
				'convertrack_404_refresh_valid_urls',
				'convertrack_404_process_recommendations',
				'convertrack_404_process_now',
				'convertrack_404_cleanup',
				'convertrack_404_spike_check',
				'convertrack_404_destination_health',
				'convertrack_404_redirect_health_check',
				'convertrack_404_refresh_step',
			)
		);
	}

	/**
	 * Cancel all jobs for the current blog.
	 *
	 * @return array Failed hook names.
	 */
	public static function cancel_jobs( $hooks = null ) {
		$hooks  = null === $hooks ? self::hooks() : array_values( array_unique( array_map( 'sanitize_key', (array) $hooks ) ) );
		$failed = array();
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( wp_next_scheduled( $hook ) ) {
				$failed[] = $hook;
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'convertrack' );
				if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( $hook, array(), 'convertrack' ) ) {
					$failed[] = $hook . ' (Action Scheduler)';
				}
			}
		}
		return array_values( array_unique( $failed ) );
	}

	/**
	 * Delete operational state while preserving configuration and schema state.
	 *
	 * @return array Failure descriptions.
	 */
	public static function delete_operational_options() {
		$failures = self::delete_exact_options( self::operational_options() );
		return array_merge( $failures, self::delete_option_prefixes( array( '_transient_convertrack_', '_transient_timeout_convertrack_' ) ) );
	}

	/**
	 * Delete every per-blog option/transient in the plugin namespace.
	 *
	 * @return array Failure descriptions.
	 */
	public static function delete_all_options() {
		return self::delete_option_prefixes( array( 'convertrack_', '_transient_convertrack_', '_transient_timeout_convertrack_' ) );
	}

	/** Delete a fixed set of option names with one failure-aware query. */
	private static function delete_exact_options( array $names ) {
		global $wpdb;
		$names = array_values( array_unique( array_filter( array_map( 'sanitize_key', $names ) ) ) );
		if ( empty( $names ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		// Names and placeholders come from the fixed internal manifest.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $names );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $wpdb->query( $sql ) ) {
			return array( 'options: ' . $wpdb->last_error );
		}
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		return array();
	}

	/** Delete options whose names start with one of the fixed prefixes. */
	private static function delete_option_prefixes( array $prefixes ) {
		global $wpdb;
		$clauses = array();
		$args    = array();
		foreach ( $prefixes as $prefix ) {
			$clauses[] = 'option_name LIKE %s';
			$args[]    = $wpdb->esc_like( (string) $prefix ) . '%';
		}
		if ( empty( $clauses ) ) {
			return array();
		}
		// The clauses are generated locally and values are prepared above.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE " . implode( ' OR ', $clauses ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $wpdb->query( $sql ) ) {
			return array( 'option prefixes: ' . $wpdb->last_error );
		}
		wp_cache_delete( 'alloptions', 'options' );
		return array();
	}
}
