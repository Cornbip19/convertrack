<?php
/**
 * REST API: public ingest endpoints + admin-only stats endpoints.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	const REST_NAMESPACE = 'convertrack/v1';

	/**
	 * Register the rest_api_init hook.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 */
	public function register_routes() {
		// Public ingest: event batch.
		register_rest_route(
			self::REST_NAMESPACE,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);

		// Public ingest: presence heartbeat.
		register_rest_route(
			self::REST_NAMESPACE,
			'/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => '__return_true',
			)
		);

		// Admin: live active-visitor count + sessions.
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/active',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_active' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
			)
		);

		// Admin: aggregated summary for the dashboard.
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_summary' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
				'args'                => array(
					'range' => array(
						'default'           => 7,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return (int) $value >= 1 && (int) $value <= 365;
						},
					),
					'post'  => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Capability check for stats endpoints.
	 *
	 * @return bool
	 */
	public function can_view_stats() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /collect
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function collect( $request ) {
		$payload = $this->json_body( $request );
		$result  = Collector::collect( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 ) );
	}

	/**
	 * POST /heartbeat
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function heartbeat( $request ) {
		$payload = $this->json_body( $request );
		$result  = Presence::heartbeat( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->no_cache( new \WP_REST_Response( $result, 200 ) );
	}

	/**
	 * GET /stats/active
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats_active( $request ) {
		$data = array(
			'active'   => Presence::active_count(),
			'window'   => (int) Settings::get( 'active_window' ),
			'sessions' => Presence::active_detail( 50 ),
		);

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * GET /stats/summary
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats_summary( $request ) {
		$range   = (int) $request->get_param( 'range' );
		$post_id = (int) $request->get_param( 'post' );

		// Cache the heavy aggregate build briefly so repeated polling / multiple
		// open tabs do not each run the full query set.
		$cache_key = 'summary_' . $range . '_' . $post_id;
		$data      = wp_cache_get( $cache_key, 'convertrack' );

		if ( false === $data ) {
			$data = array(
				'range'       => $range,
				'totals'      => Database::overview_stats( $range ),
				'top_buttons' => $this->decorate_buttons( Database::top_buttons( $range, 25, $post_id ) ),
				'top_pages'   => $this->decorate_pages( Database::top_pages( $range, 25 ) ),
				'series'      => Database::clicks_timeseries( $range ),
			);
			wp_cache_set( $cache_key, $data, 'convertrack', 15 );
		}

		$data['active'] = Presence::active_count();

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Add human-friendly labels to top-button rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private function decorate_buttons( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$label = trim( (string) $row['element_text'] );
			if ( '' === $label ) {
				$label = (string) $row['element_selector'];
			}
			$out[] = array(
				'label'       => $label,
				'selector'    => (string) $row['element_selector'],
				'clicks'      => (int) $row['clicks'],
				'conversions' => (int) $row['conversions'],
			);
		}
		return $out;
	}

	/**
	 * Resolve post titles/permalinks for top-page rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private function decorate_pages( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$title   = $post_id > 0 ? get_the_title( $post_id ) : __( '(unknown / global)', 'convertrack' );
			$url     = $post_id > 0 ? get_permalink( $post_id ) : '';

			$out[] = array(
				'post_id'     => $post_id,
				'title'       => $title ? $title : ( '#' . $post_id ),
				'url'         => $url ? $url : '',
				'clicks'      => (int) $row['clicks'],
				'pageviews'   => (int) $row['pageviews'],
				'conversions' => (int) $row['conversions'],
			);
		}
		return $out;
	}

	/**
	 * Decode the JSON body of a request, tolerating sendBeacon payloads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function json_body( $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && ! empty( $params ) ) {
			return $params;
		}

		// Fallback: sendBeacon may deliver a raw JSON string body.
		$body = $request->get_body();
		if ( is_string( $body ) && '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Mark a response as non-cacheable.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return \WP_REST_Response
	 */
	private function no_cache( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}
}
