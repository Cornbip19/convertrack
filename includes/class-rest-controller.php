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
		add_filter( 'rest_post_dispatch', array( $this, 'ingestion_response_headers' ), 10, 3 );
	}

	/**
	 * Add standards-based retry guidance to public quota responses.
	 *
	 * @param \WP_HTTP_Response $response REST response.
	 * @param \WP_REST_Server   $server REST server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_HTTP_Response
	 */
	public function ingestion_response_headers( $response, $server, $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		if ( 0 === strpos( $route, '/' . self::REST_NAMESPACE . '/' ) && is_object( $response ) && method_exists( $response, 'get_status' ) && 429 === (int) $response->get_status() ) {
			$response->header( 'Retry-After', '60' );
		}
		return $response;
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

		// Admin: searchable, sortable and paginated per-page statistics.
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats_pages' ),
				'permission_callback' => array( $this, 'can_view_stats' ),
				'args'                => array(
					'range'    => array(
						'default'           => 7,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return (int) $value >= 1 && (int) $value <= 365;
						},
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return (int) $value >= 1;
						},
					),
					'per_page' => array(
						'default'           => 25,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return (int) $value >= 1 && (int) $value <= 100;
						},
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value );
						},
					),
					'orderby'  => array(
						'default'           => 'pageviews',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( sanitize_key( (string) $value ), array( 'pageviews', 'clicks', 'conversions', 'title' ), true );
						},
					),
					'order'    => array(
						'default'           => 'desc',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( sanitize_key( (string) $value ), array( 'asc', 'desc' ), true );
						},
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
					'page_key' => array(
						'default'           => '',
						'sanitize_callback' => function ( $value ) {
							return substr( sanitize_text_field( (string) $value ), 0, 191 );
						},
					),
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
		$schema_error = $this->ingestion_schema_error();
		if ( is_wp_error( $schema_error ) ) {
			return $schema_error;
		}
		$size_error = $this->validate_body_size( $request, 'collect' );
		if ( is_wp_error( $size_error ) ) {
			Ingestion_Guard::record_metric( 'collect', 'rejected', 1 );
			return $size_error;
		}
		$payload = $this->json_body( $request );
		$context = Ingestion_Guard::admit( $request, 'collect', $payload, strlen( (string) $request->get_body() ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$result  = Collector::collect( $payload, $context );

		if ( is_wp_error( $result ) ) {
			Ingestion_Guard::record_metric( 'collect', 'failed', 1 );
			return $result;
		}
		$accepted = isset( $result['accepted'] ) ? (int) $result['accepted'] : ( isset( $result['stored'] ) ? (int) $result['stored'] : 0 );
		Ingestion_Guard::record_metric( 'collect', 'accepted', $accepted );

		return $this->no_cache( new \WP_REST_Response( array_merge( array( 'ok' => true ), $result, array( 'legacy' => ! empty( $context['legacy'] ) ) ), 200 ) );
	}

	/**
	 * POST /heartbeat
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function heartbeat( $request ) {
		$schema_error = $this->ingestion_schema_error();
		if ( is_wp_error( $schema_error ) ) {
			return $schema_error;
		}
		$size_error = $this->validate_body_size( $request, 'heartbeat' );
		if ( is_wp_error( $size_error ) ) {
			Ingestion_Guard::record_metric( 'heartbeat', 'rejected', 1 );
			return $size_error;
		}
		$payload = $this->json_body( $request );
		$context = Ingestion_Guard::admit( $request, 'heartbeat', $payload, strlen( (string) $request->get_body() ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$result  = Presence::heartbeat( $payload, $context );

		if ( is_wp_error( $result ) ) {
			Ingestion_Guard::record_metric( 'heartbeat', 'failed', 1 );
			return $result;
		}
		Ingestion_Guard::record_metric( 'heartbeat', 'accepted', 1 );
		$result['legacy'] = ! empty( $context['legacy'] );

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
		$aggregate_range = Database::range_metadata( $range, 'aggregate' );
		$raw_range       = Database::range_metadata( $range, 'raw' );
		$raw_days        = max( 1, (int) $raw_range['effective_range'] );

		// Cache the heavy aggregate build briefly so repeated polling / multiple
		// open tabs do not each run the full query set.
		$cache_key = 'summary_' . Database::report_cache_generation() . '_' . $range . '_' . $post_id;
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
				'avg_session_seconds'  => Database::avg_session_seconds( $raw_days ),
				'series'               => Database::clicks_timeseries( $range ),
				'activity_hours'       => Database::activity_by_hour( $raw_days ),
				'engagement'           => Database::engagement_breakdown( $raw_days ),
			);
			wp_cache_set( $cache_key, $data, 'convertrack', 15 );
		}

		$data['active']        = Presence::active_count();
		$data['recent_events'] = Database::recent_events( $raw_days, 100 );
		$data                  = array_merge( $data, $this->mixed_range_metadata( $range, $aggregate_range, $raw_range ) );

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * GET /stats/pages
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats_pages( $request ) {
		$range  = (int) $request->get_param( 'range' );
		$result = Database::paged_pages(
			array(
				'range'    => $range,
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'search'   => (string) $request->get_param( 'search' ),
				'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
			)
		);

		$data = array(
			'rows'        => $this->decorate_pages( $result['rows'] ),
			'page'        => (int) $result['page'],
			'per_page'    => (int) $result['per_page'],
			'total'       => (int) $result['total'],
			'total_pages' => (int) $result['total_pages'],
		);
		$data = array_merge( $data, Database::range_metadata( $range, 'aggregate' ) );

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
		$page_key = substr( sanitize_text_field( (string) $request->get_param( 'page_key' ) ), 0, 191 );
		$device  = sanitize_key( (string) $request->get_param( 'device' ) );
		$metadata = Database::range_metadata( $range, 'raw' );
		$query_range = max( 1, (int) $metadata['effective_range'] );

		$data            = Database::heatmap_data( $post_id, $query_range, $device, $page_key );
		if ( ! Settings::get( 'track_search_keywords' ) ) {
			$data['search_terms'] = array();
		}
		$data['search_keywords_enabled'] = (bool) Settings::get( 'track_search_keywords' );
		$page = $this->decorate_pages(
			array(
				array(
					'page_key'   => $page_key,
					'post_id'    => $post_id,
					'clicks'     => 0,
					'pageviews'  => 0,
					'conversions'=> 0,
				)
			)
		);
		$page             = reset( $page );
		$data['page_key'] = $page_key;
		$data['post_id']  = $post_id;
		$data['title']    = $page ? $page['title'] : __( '(unknown / global)', 'convertrack-click-conversion-analytics' );
		$data['url']      = $page ? $page['url'] : '';
		$data             = array_merge( $data, $metadata );

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
		$metadata = Database::range_metadata( $range, 'raw' );
		$data  = Database::funnel_data( max( 1, (int) $metadata['effective_range'] ), 10 );
		$data['range'] = $range;
		$data = array_merge( $data, $metadata );

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
		$page_keys = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['page_key'] ) ) {
				$page_keys[] = (string) $row['page_key'];
			}
		}
		$details = Database::page_identity_details( $page_keys );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$page_key = isset( $row['page_key'] ) ? (string) $row['page_key'] : '';
			$title    = $post_id > 0 ? get_the_title( $post_id ) : '';
			$url      = $post_id > 0 ? get_permalink( $post_id ) : '';

			if ( ! $title && ! empty( $row['page_title'] ) ) {
				$title = (string) $row['page_title'];
			}
			if ( ! $url && ! empty( $row['page_url'] ) ) {
				$url = (string) $row['page_url'];
			}
			if ( isset( $details[ $page_key ] ) ) {
				if ( ! $title ) {
					$title = $details[ $page_key ]['page_title'];
				}
				if ( ! $url ) {
					$url = $details[ $page_key ]['page_url'];
				}
			}

			// Canonical keys end in the normalized site path. This remains useful
			// after raw detail retention has expired, without inventing labels for
			// one-way legacy URL hashes.
			$key_parts = explode( ':', $page_key, 3 );
			$key_path  = 3 === count( $key_parts ) && 0 === strpos( $key_parts[2], '/' ) ? $key_parts[2] : '';
			if ( ! $url && '' !== $key_path ) {
				$url = home_url( $key_path );
			}
			if ( ! $title && '' !== $key_path ) {
				$title = '/' === $key_path ? get_bloginfo( 'name' ) : $key_path;
			}
			if ( ! $title ) {
				$title = $post_id > 0 ? ( '#' . $post_id ) : __( '(unknown / global)', 'convertrack-click-conversion-analytics' );
			}

			$out[] = array(
				'page_key'   => $page_key,
				'post_id'     => $post_id,
				'title'       => $title,
				'url'         => $url ? $url : '',
				'clicks'      => (int) $row['clicks'],
				'pageviews'   => (int) $row['pageviews'],
				'conversions' => (int) $row['conversions'],
			);
		}
		return $out;
	}

	/**
	 * Mark a mixed aggregate/raw response with its shortest truthful window.
	 *
	 * @param int   $requested Requested days.
	 * @param array $aggregate Aggregate-source metadata.
	 * @param array $raw       Raw-source metadata.
	 * @return array
	 */
	private function mixed_range_metadata( $requested, array $aggregate, array $raw ) {
		$from_values = array_filter(
			array( $aggregate['data_available_from'], $raw['data_available_from'] ),
			'is_string'
		);
		return array(
			'requested_range'    => max( 1, (int) $requested ),
			'effective_range'    => min( (int) $aggregate['effective_range'], (int) $raw['effective_range'] ),
			'data_available_from'=> empty( $from_values ) ? null : max( $from_values ),
			'truncated'          => ! empty( $aggregate['truncated'] ) || ! empty( $raw['truncated'] ),
			'partial'            => ! empty( $aggregate['partial'] ) || ! empty( $raw['partial'] ),
			'range_sources'      => array(
				'aggregate' => $aggregate,
				'raw'       => $raw,
			),
		);
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
	 * Reject oversized public payloads before decoding or persistence.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $channel collect|heartbeat.
	 * @return true|\WP_Error
	 */
	private function validate_body_size( $request, $channel ) {
		$maximum = Ingestion_Guard::max_body_bytes( $channel );
		$body    = (string) $request->get_body();
		$declared = $request->get_header( 'content-length' );
		if ( ( is_numeric( $declared ) && (int) $declared > $maximum ) || strlen( $body ) > $maximum ) {
			return new \WP_Error(
				'convertrack_payload_too_large',
				'Tracking payload is too large.',
				array( 'status' => 413, 'max_bytes' => $maximum )
			);
		}
		return true;
	}

	/**
	 * Fail closed for direct/cached clients while either schema is unhealthy.
	 *
	 * @return true|\WP_Error
	 */
	private function ingestion_schema_error() {
		if ( Database::schema_is_healthy() && Ingestion_Guard::schema_is_healthy() ) {
			return true;
		}
		return new \WP_Error(
			'convertrack_schema_unavailable',
			'Tracking is temporarily unavailable while the database is repaired.',
			array( 'status' => 503 )
		);
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
