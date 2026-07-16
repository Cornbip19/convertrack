<?php
/**
 * REST endpoints for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register module routes.
	 */
	public function register_routes() {
		$namespace = \Convertrack\Rest_Controller::REST_NAMESPACE;

		foreach ( array(
			'/404/summary'   => 'summary',
			'/404/events'    => 'events',
			'/404/redirects' => 'redirects',
			'/404/logs'      => 'logs',
		) as $route => $method ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}

		foreach ( array( 'approve', 'edit', 'ignore', 'delete', 'bulk', 'process', 'refresh', 'redirect-status', 'redirect-delete' ) as $action ) {
			register_rest_route(
				$namespace,
				'/404/' . $action,
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
		$data['valid_url_count'] = Database::valid_url_count();
		$data['last_sitemap_refresh'] = (string) get_option( 'convertrack_404_last_sitemap_refresh', '' );
		$data['post_types'] = $this->post_type_options();
		$data['taxonomies'] = $this->taxonomy_options();
		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Event list endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function events( $request ) {
		$data = Database::list_events(
			array(
				'page'           => absint( $request->get_param( 'page' ) ),
				'per_page'       => absint( $request->get_param( 'per_page' ) ),
				'status'         => sanitize_key( (string) $request->get_param( 'status' ) ),
				'post_type'      => sanitize_key( (string) $request->get_param( 'post_type' ) ),
				'confidence_min' => sanitize_text_field( (string) $request->get_param( 'confidence_min' ) ),
				'confidence_max' => sanitize_text_field( (string) $request->get_param( 'confidence_max' ) ),
				'detected_from'  => sanitize_text_field( (string) $request->get_param( 'detected_from' ) ),
				'detected_to'    => sanitize_text_field( (string) $request->get_param( 'detected_to' ) ),
				'search'         => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			)
		);
		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Redirect list endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function redirects( $request ) {
		$limit = absint( $request->get_param( 'limit' ) );
		$limit = $limit ? max( 1, min( 200, $limit ) ) : 100;

		$internal = Database::list_redirects( array( 'limit' => $limit ) );
		$external = Compatibility::external_redirects( $limit );
		return $this->no_cache(
			new \WP_REST_Response(
				array(
					'internal' => $internal,
					'external' => $external,
					'rows'     => array_merge( $internal, $external ),
				),
				200
			)
		);
	}

	/**
	 * Logs endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function logs( $request ) {
		$limit = absint( $request->get_param( 'limit' ) );
		$limit = $limit ? max( 1, min( 200, $limit ) ) : 50;
		return $this->no_cache( new \WP_REST_Response( array( 'rows' => Database::recent_logs( $limit ) ), 200 ) );
	}

	/**
	 * Approve a redirect recommendation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		$destination = sanitize_text_field( (string) $this->json_value( $request, 'destination' ) );
		$result      = Redirector::approve_event( $id, $destination );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'redirect_id' => (int) $result ), 200 ) );
	}

	/**
	 * Edit a suggested destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function edit( $request ) {
		$id          = absint( $this->json_value( $request, 'id' ) );
		$destination = trim( (string) $this->json_value( $request, 'destination' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		$event = Database::get_event( $id );
		if ( ! $event ) {
			return $this->bad_id();
		}
		$validation = Redirector::validate_pair( $event['url'], $destination, false );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( empty( Database::normalize_destination( $destination ) ) ) {
			return new \WP_Error( 'convertrack_404_bad_destination', __( 'Enter a valid destination URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}
		$updated = Database::update_suggestion( $id, $destination );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		Logger::info( 'manual', '404 recommendation destination edited.', array( 'id' => $id, 'destination' => $destination ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Ignore an event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ignore( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		$updated = Database::set_event_status( $id, 'ignored' );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		Logger::info( 'manual', '404 event ignored.', array( 'id' => $id ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Soft-delete an event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		$updated = Database::set_event_status( $id, 'deleted' );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		Logger::info( 'manual', '404 event deleted.', array( 'id' => $id ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Bulk actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( $request ) {
		$action = sanitize_key( (string) $this->json_value( $request, 'action' ) );
		$ids    = $this->json_ids( $request );
		$count  = 0;
		$errors = 0;

		if ( ! in_array( $action, array( 'approve', 'approve_high_confidence', 'ignore', 'delete' ), true ) ) {
			return new \WP_Error( 'convertrack_404_bad_bulk_action', __( 'Invalid bulk action.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		if ( 'approve_high_confidence' === $action ) {
			$raw_threshold = absint( $this->json_value( $request, 'threshold' ) );
			$threshold     = $raw_threshold ? max( 50, min( 100, $raw_threshold ) ) : (int) Settings::get( 'auto_min_confidence', 90 );
			$rows = Database::list_events(
				array(
					'status'         => 'recommended',
					'confidence_min' => $threshold,
					'page'           => 1,
					'per_page'       => 100,
				)
			);
			$ids = wp_list_pluck( $rows['rows'], 'id' );
		}

		if ( empty( $ids ) ) {
			return new \WP_Error( 'convertrack_404_no_rows', __( 'Choose at least one 404 row.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		foreach ( $ids as $id ) {
			if ( 'approve' === $action || 'approve_high_confidence' === $action ) {
				$result = Redirector::approve_event( $id );
				if ( is_wp_error( $result ) ) {
					$errors++;
					continue;
				}
				$count++;
			} elseif ( 'ignore' === $action ) {
				$result = Database::set_event_status( $id, 'ignored' );
				is_wp_error( $result ) ? $errors++ : $count++;
			} elseif ( 'delete' === $action ) {
				$result = Database::set_event_status( $id, 'deleted' );
				is_wp_error( $result ) ? $errors++ : $count++;
			}
		}

		Logger::info( 'manual', '404 bulk action completed.', array( 'action' => $action, 'updated' => $count, 'errors' => $errors ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'updated' => $count, 'errors' => $errors ), 200 ) );
	}

	/**
	 * Process recommendation batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function process( $request ) {
		$limit  = min( 100, absint( $this->json_value( $request, 'limit' ) ) );
		$result = Matcher::process_batch( $limit ? $limit : 50 );
		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 ) );
	}

	/**
	 * Refresh valid URL cache.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh() {
		$result = Sitemap_Source::refresh();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['pending'] ) && Settings::recommendations_enabled() ) {
			Cron::kick_processing();
		}
		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 ) );
	}

	/**
	 * Pause/resume/disable an internal redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function redirect_status( $request ) {
		$id     = absint( $this->json_value( $request, 'id' ) );
		$status = sanitize_key( (string) $this->json_value( $request, 'status' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		if ( ! in_array( $status, array( 'active', 'paused', 'disabled' ), true ) ) {
			return new \WP_Error( 'convertrack_404_bad_status', __( 'Invalid redirect status.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}
		if ( 'active' === $status ) {
			$redirect = Database::get_redirect( $id );
			if ( ! $redirect ) {
				return new \WP_Error( 'convertrack_404_redirect_missing', __( 'The redirect rule was not found.', 'convertrack-click-conversion-analytics' ), array( 'status' => 404 ) );
			}
			$validation = Redirector::validate_pair( $redirect['source_url'], $redirect['destination_url'], false );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}
		$updated = Database::set_redirect_status( $id, $status );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		Logger::info( 'redirect', 'Internal redirect status changed.', array( 'id' => $id, 'status' => $status ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Delete an internal redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function redirect_delete( $request ) {
		$id = absint( $this->json_value( $request, 'id' ) );
		if ( ! $id ) {
			return $this->bad_id();
		}
		$deleted = Database::delete_redirect( $id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}
		Logger::info( 'redirect', 'Internal redirect deleted.', array( 'id' => $id ) );
		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Return post type options.
	 *
	 * @return array
	 */
	private function post_type_options() {
		$out = array();
		foreach ( Settings::available_post_types() as $post_type => $object ) {
			$out[] = array(
				'value' => $post_type,
				'label' => $object->labels->name,
			);
		}
		return $out;
	}

	/**
	 * Return taxonomy options.
	 *
	 * @return array
	 */
	private function taxonomy_options() {
		$out = array();
		foreach ( Settings::available_taxonomies() as $taxonomy => $object ) {
			$out[] = array(
				'value' => $taxonomy,
				'label' => $object->labels->name,
			);
		}
		return $out;
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
	 * Read IDs from JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function json_ids( $request ) {
		$ids = $this->json_value( $request, 'ids' );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Standard bad ID error.
	 *
	 * @return \WP_Error
	 */
	private function bad_id() {
		return new \WP_Error( 'convertrack_404_bad_id', __( 'Missing 404 row ID.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
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
