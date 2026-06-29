<?php
/**
 * REST endpoints for Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes under the existing Convertrack namespace.
	 */
	public function register_routes() {
		$namespace = \Convertrack\Rest_Controller::REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/gsc/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gsc/urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'urls' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gsc/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		foreach ( array( 'recheck', 'ignore', 'priority', 'scan-sitemap', 'process' ) as $action ) {
			register_rest_route(
				$namespace,
				'/gsc/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, str_replace( '-', '_', $action ) ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Capability check.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Summary endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function summary() {
		$data = Database::summary();
		$data['quota'] = Processor::quota_state();
		$data['settings_ready'] = Settings::ready();
		$data['credentials'] = Credentials::public_status();
		$data['sitemaps'] = Database::sitemap_options();
		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * URL list endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function urls( $request ) {
		$data = Database::list_urls(
			array(
				'page'         => absint( $request->get_param( 'page' ) ),
				'per_page'     => absint( $request->get_param( 'per_page' ) ),
				'status'       => sanitize_key( (string) $request->get_param( 'status' ) ),
				'post_type'    => sanitize_key( (string) $request->get_param( 'post_type' ) ),
				'priority'     => $request->get_param( 'priority' ),
				'sitemap_hash' => sanitize_text_field( (string) $request->get_param( 'sitemap_hash' ) ),
				'checked_from' => sanitize_text_field( (string) $request->get_param( 'checked_from' ) ),
				'checked_to'   => sanitize_text_field( (string) $request->get_param( 'checked_to' ) ),
			)
		);

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Logs endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function logs( $request ) {
		$limit = max( 1, min( 200, absint( $request->get_param( 'limit' ) ) ) );
		return $this->no_cache( new \WP_REST_Response( array( 'rows' => Database::recent_logs( $limit ) ), 200 ) );
	}

	/**
	 * Recheck action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function recheck( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return new \WP_Error( 'convertrack_gsc_bad_id', __( 'Missing URL row ID.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$this->update_row_action( $id, array( 'index_status' => 'queued', 'next_check_at' => current_time( 'mysql' ) ) );
		Logger::info( 'manual', 'URL queued for manual recheck.', array( 'id' => $id ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Ignore action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ignore( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return new \WP_Error( 'convertrack_gsc_bad_id', __( 'Missing URL row ID.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$this->update_row_action( $id, array( 'index_status' => 'ignored', 'next_check_at' => null ) );
		Logger::info( 'manual', 'URL ignored.', array( 'id' => $id ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Priority action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function priority( $request ) {
		$id       = absint( $this->json_value( $request, 'id' ) );
		$priority = absint( $this->json_value( $request, 'priority' ) );
		if ( ! $id ) {
			return new \WP_Error( 'convertrack_gsc_bad_id', __( 'Missing URL row ID.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$this->update_row_action( $id, array( 'priority' => min( 1, $priority ) ) );
		Logger::info( 'manual', 'URL priority updated.', array( 'id' => $id, 'priority' => $priority ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Trigger sitemap scan.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_sitemap() {
		$result = Sitemap_Scanner::scan();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 ) );
	}

	/**
	 * Trigger processor.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process() {
		$result = Processor::process_batch();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 ) );
	}

	/**
	 * Update row for a manual action.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Data.
	 */
	private function update_row_action( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Database::queue_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		Database::clear_summary_cache();
	}

	/**
	 * Read a JSON value.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Key.
	 * @return mixed
	 */
	private function json_value( $request, $key ) {
		$params = $request->get_json_params();
		return is_array( $params ) && array_key_exists( $key, $params ) ? $params[ $key ] : null;
	}

	/**
	 * Mark response as non-cacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return \WP_REST_Response
	 */
	private function no_cache( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}
}
