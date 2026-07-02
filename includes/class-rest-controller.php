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

		// Admin: per-page heatmap (click density + scroll depth).
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/heatmap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_heatmap' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
				'args'                => array(
					'range' => array( 'default' => 7, 'sanitize_callback' => 'absint' ),
					'post'  => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'device' => array( 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		// Admin: anonymous, script-disabled HTML snapshot for heatmap previews.
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/heatmap-snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_heatmap_snapshot' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
				'args'                => array(
					'post'   => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'device' => array( 'default' => 'desktop', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		// Admin: conversion funnel / visitor journey report.
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/funnels',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_funnels' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
				'args'                => array(
					'range' => array( 'default' => 7, 'sanitize_callback' => 'absint' ),
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
				'range'                => $range,
				'totals'               => Database::overview_stats( $range ),
				'top_buttons'          => $this->decorate_buttons( Database::top_buttons( $range, 25, $post_id ) ),
				'top_pages'            => $this->decorate_pages( Database::top_pages( $range, 25 ) ),
				'top_sources'          => Database::top_sources( $range, 8 ),
				'top_search_terms'     => Settings::get( 'track_search_keywords' ) ? Database::top_search_terms( $range, 12, $post_id ) : array(),
				'search_keywords_enabled' => (bool) Settings::get( 'track_search_keywords' ),
				'top_countries'        => Database::top_countries( $range, 10 ),
				'geo_enabled'          => (bool) Settings::get( 'enable_geo' ),
				'avg_session_seconds'  => Database::avg_session_seconds( $range ),
				'series'               => Database::clicks_timeseries( $range ),
				'activity_hours'       => Database::activity_by_hour( $range ),
				'engagement'           => Database::engagement_breakdown( $range ),
			);
			wp_cache_set( $cache_key, $data, 'convertrack', 15 );
		}

		$data['active']        = Presence::active_count();
		$data['recent_events'] = Database::recent_events( $range, 100 );

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * GET /stats/heatmap — click density + scroll depth for one page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats_heatmap( $request ) {
		$range   = max( 1, min( 365, (int) $request->get_param( 'range' ) ) );
		$post_id = (int) $request->get_param( 'post' );
		$device  = sanitize_key( (string) $request->get_param( 'device' ) );

		$data            = Database::heatmap_data( $post_id, $range, $device );
		if ( ! Settings::get( 'track_search_keywords' ) ) {
			$data['search_terms'] = array();
		}
		$data['search_keywords_enabled'] = (bool) Settings::get( 'track_search_keywords' );
		$data['post_id'] = $post_id;
		$data['title']   = $post_id > 0 ? get_the_title( $post_id ) : __( '(unknown / global)', 'convertrack-click-conversion-analytics' );
		$data['url']     = $post_id > 0 ? get_permalink( $post_id ) : '';

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * GET /stats/heatmap-snapshot — anonymous page HTML for the heatmap iframe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stats_heatmap_snapshot( $request ) {
		$post_id = (int) $request->get_param( 'post' );
		$device  = sanitize_key( (string) $request->get_param( 'device' ) );
		$device  = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'desktop';
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'convertrack_bad_post', 'Invalid page.', array( 'status' => 400 ) );
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return new \WP_Error( 'convertrack_bad_post', 'Invalid page URL.', array( 'status' => 400 ) );
		}

		$url      = add_query_arg( 'convertrack_no_track', '1', $permalink );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => $this->snapshot_user_agent( $device ),
				'cookies'     => array(),
				'headers'     => array(
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'convertrack_snapshot_failed', 'Could not load page snapshot.', array( 'status' => 502 ) );
		}

		$html = (string) wp_remote_retrieve_body( $response );
		$html = $this->prepare_snapshot_html( $html, $permalink );

		return $this->no_cache(
			new \WP_REST_Response(
				array(
					'post_id' => $post_id,
					'url'     => $permalink,
					'device'  => $device,
					'html'    => $html,
				),
				200
			)
		);
	}

	/**
	 * User-agent string for responsive heatmap snapshots.
	 *
	 * @param string $device desktop|tablet|mobile.
	 * @return string
	 */
	private function snapshot_user_agent( $device ) {
		if ( 'mobile' === $device ) {
			return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 Convertrack/' . CONVERTRACK_VERSION;
		}
		if ( 'tablet' === $device ) {
			return 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 Convertrack/' . CONVERTRACK_VERSION;
		}
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 Convertrack/' . CONVERTRACK_VERSION;
	}

	/**
	 * GET /stats/funnels — conversion journey report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats_funnels( $request ) {
		$range = max( 1, min( 365, (int) $request->get_param( 'range' ) ) );
		$data  = Database::funnel_data( $range, 10 );
		$data['range'] = $range;

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Remove scripts and inject base/styles so the snapshot is inert but styled.
	 *
	 * @param string $html     Raw page HTML.
	 * @param string $base_url Page URL for resolving relative assets.
	 * @return string
	 */
	private function prepare_snapshot_html( $html, $base_url ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html = preg_replace( '#<script\b[^>]*/>#is', '', $html );

		$viewport = preg_match( '#<meta\b[^>]*name=["\']viewport["\'][^>]*>#i', $html ) ? '' : '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$inject = $viewport .
			'<base href="' . esc_url( $base_url ) . '">' .
			'<style id="convertrack-heatmap-snapshot-css">' .
			'html{scroll-behavior:auto!important;margin-top:0!important;}' .
			'*,*:before,*:after{animation:none!important;transition:none!important;}' .
			'a,button,input,select,textarea{pointer-events:none!important;}' .
			'#wpadminbar{display:none!important;}' .
			'</style>';

		if ( preg_match( '/<head\b[^>]*>/i', $html ) ) {
			return preg_replace( '/<head\b[^>]*>/i', '$0' . $inject, $html, 1 );
		}

		return '<!doctype html><html><head>' . $inject . '</head><body>' . $html . '</body></html>';
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
			$title   = $post_id > 0 ? get_the_title( $post_id ) : __( '(unknown / global)', 'convertrack-click-conversion-analytics' );
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
