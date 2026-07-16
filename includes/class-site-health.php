<?php
/**
 * WordPress Site Health diagnostics.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Site_Health {

	/** Register diagnostics. */
	public static function register() {
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_status_tests' ) );
	}

	/** Add privacy-safe storage and worker data to Site Health Info. */
	public static function debug_information( $info ) {
		$health = Database::storage_health();
		$ingestion = Ingestion_Guard::health_metrics( 7 );
		$fields = array(
			'schema' => array(
				'label' => __( 'Schema status', 'convertrack-click-conversion-analytics' ),
				'value' => Database::schema_is_healthy() ? 'healthy' : 'unhealthy',
			),
			'rollup_backlog' => array(
				'label' => __( 'Rollup backlog (days)', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $health['rollup_backlog_days'] ) ? (int) $health['rollup_backlog_days'] : 0,
			),
			'last_cleanup' => array(
				'label' => __( 'Last successful cleanup', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $health['last_cleanup']['completed_at'] ) ? $health['last_cleanup']['completed_at'] : __( 'Not recorded', 'convertrack-click-conversion-analytics' ),
			),
			'ingestion_accepted' => array(
				'label' => __( 'Accepted events (7 days)', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $ingestion['accepted'] ) ? (int) $ingestion['accepted'] : 0,
			),
			'ingestion_rejected' => array(
				'label' => __( 'Rejected events (7 days)', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $ingestion['rejected'] ) ? (int) $ingestion['rejected'] : 0,
			),
			'ingestion_rate_limited' => array(
				'label' => __( 'Rate-limited events (7 days)', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $ingestion['rate_limited'] ) ? (int) $ingestion['rate_limited'] : 0,
			),
			'ingestion_failed' => array(
				'label' => __( 'Failed events (7 days)', 'convertrack-click-conversion-analytics' ),
				'value' => isset( $ingestion['failed'] ) ? (int) $ingestion['failed'] : 0,
			),
		);
		foreach ( $health as $name => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['rows_estimate'] ) ) {
				continue;
			}
			$fields[ 'table_' . sanitize_key( $name ) ] = array(
				'label' => sprintf( __( '%s table', 'convertrack-click-conversion-analytics' ), $name ),
				'value' => sprintf( '%d rows estimated; %s data; %s indexes', (int) $row['rows_estimate'], size_format( (int) $row['data_bytes'] ), size_format( (int) $row['index_bytes'] ) ),
			);
		}
		$info['convertrack'] = array(
			'label'  => __( 'Convertrack', 'convertrack-click-conversion-analytics' ),
			'fields' => $fields,
		);
		return $info;
	}

	/** Register a direct health test. */
	public static function site_status_tests( $tests ) {
		$tests['direct']['convertrack_storage'] = array(
			'label' => __( 'Convertrack storage and workers', 'convertrack-click-conversion-analytics' ),
			'test'  => array( __CLASS__, 'test_storage' ),
		);
		return $tests;
	}

	/** Return the Site Health test result. */
	public static function test_storage() {
		$healthy = Database::schema_is_healthy();
		$health  = $healthy ? Database::storage_health() : array();
		$backlog = isset( $health['rollup_backlog_days'] ) ? (int) $health['rollup_backlog_days'] : 0;
		$status  = $healthy && $backlog < 8 ? 'good' : ( $healthy ? 'recommended' : 'critical' );
		$label   = $healthy ? __( 'Convertrack storage is available', 'convertrack-click-conversion-analytics' ) : __( 'Convertrack collection is paused by an incomplete schema', 'convertrack-click-conversion-analytics' );
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => 'Convertrack', 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $healthy ? sprintf( __( 'The rollup backlog is %d day(s).', 'convertrack-click-conversion-analytics' ), $backlog ) : get_option( Database::SCHEMA_ERROR_OPTION, __( 'Retry the database migration from an administrator page.', 'convertrack-click-conversion-analytics' ) ) ) . '</p>',
			'actions'     => '',
			'test'        => 'convertrack_storage',
		);
	}
}
