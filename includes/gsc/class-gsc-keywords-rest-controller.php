<?php
/**
 * REST endpoints for GSC Keyword Insights.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Rest_Controller {

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

		$get_routes = array(
			'/gsc/keywords'         => 'keywords',
			'/gsc/keywords/summary' => 'summary',
			'/gsc/keywords/pages'   => 'pages',
			'/gsc/keywords/page'    => 'page_detail',
			'/gsc/keywords/status'  => 'status',
		);
		foreach ( $get_routes as $route => $callback ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}

		foreach ( array( 'enable', 'sync', 'analyze', 'bulk' ) as $action ) {
			register_rest_route(
				$namespace,
				'/gsc/keywords/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
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
	 * Dashboard summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function summary( $request ) {
		$range = Keywords_Database::sanitize_range( (string) $request->get_param( 'range' ) );
		$data  = Keywords_Database::summary( $range );

		$data['connected']    = Credentials::is_connected();
		$data['enabled']      = (bool) Keywords_Settings::get( 'enabled' );
		$data['ready']        = Keywords_Settings::ready();
		$data['has_data']     = Keywords_Database::has_data();
		$data['seo_provider'] = Keywords_Seo_Meta::provider();
		$data['top_pages']    = array_map( array( $this, 'strip_page_row' ), (array) $data['top_pages'] );

		$sync               = Keywords_Sync::state();
		$data['sync']       = array(
			'status'   => $sync['status'],
			'running'  => ! empty( $sync['running'] ),
			'progress' => $sync['progress'],
		);
		$data['last_sync']  = $sync['last_sync'];
		$data['last_error'] = $sync['last_error'];

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Keyword table.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function keywords( $request ) {
		$data = Keywords_Database::list_keywords(
			array(
				'page'        => absint( $request->get_param( 'page' ) ),
				'per_page'    => absint( $request->get_param( 'per_page' ) ),
				'range_key'   => sanitize_key( (string) $request->get_param( 'range' ) ),
				'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'post_id'     => absint( $request->get_param( 'post_id' ) ),
				'label'       => sanitize_key( (string) $request->get_param( 'label' ) ),
				'presence'    => sanitize_key( (string) $request->get_param( 'presence' ) ),
				'opportunity' => sanitize_key( (string) $request->get_param( 'opportunity' ) ),
				'orderby'     => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'       => sanitize_key( (string) $request->get_param( 'order' ) ),
			)
		);

		$data['rows'] = array_map( array( $this, 'rest_row' ), $data['rows'] );

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Pages with keyword data (feeds the page filter select).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pages( $request ) {
		$rows = Keywords_Database::list_pages(
			array(
				'range_key' => sanitize_key( (string) $request->get_param( 'range' ) ),
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'limit'     => absint( $request->get_param( 'limit' ) ),
				'orderby'   => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			)
		);

		return $this->no_cache( new \WP_REST_Response( array( 'rows' => array_map( array( $this, 'strip_page_row' ), $rows ) ), 200 ) );
	}

	/**
	 * Page drill-down: keywords, groups, placements and suggestions for one page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function page_detail( $request ) {
		$post_id   = absint( $request->get_param( 'post_id' ) );
		$page_hash = sanitize_text_field( (string) $request->get_param( 'page_hash' ) );
		$range     = Keywords_Database::sanitize_range( (string) $request->get_param( 'range' ) );

		if ( ! $post_id && '' === $page_hash ) {
			return new \WP_Error( 'convertrack_gsc_keywords_bad_page', __( 'Missing page reference.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$rows = Keywords_Database::page_keywords( $post_id, $page_hash, $range );
		if ( empty( $rows ) ) {
			return new \WP_Error( 'convertrack_gsc_keywords_no_rows', __( 'No keyword data for this page in the selected range.', 'convertrack-click-conversion-analytics' ), array( 'status' => 404 ) );
		}

		$first   = $rows[0];
		$post_id = $post_id ? $post_id : (int) $first['post_id'];

		$totals = array(
			'keywords'    => count( $rows ),
			'clicks'      => 0,
			'impressions' => 0,
			'present'     => 0,
			'partial'     => 0,
			'missing'     => 0,
		);
		$weighted = 0.0;
		$groups   = array(
			'present' => array(),
			'partial' => array(),
			'missing' => array(),
		);
		$page_recs  = array();
		$faq        = array();
		$anchors    = array();
		$placements = array();
		$areas_sum  = array();

		foreach ( Keywords_Presence::AREAS as $area ) {
			$areas_sum[ $area ] = array(
				'area'    => $area,
				'present' => 0,
				'partial' => 0,
				'missing' => 0,
			);
		}

		$key_areas = Keywords_Presence::key_areas();

		foreach ( $rows as $index => $row ) {
			$totals['clicks']      += $row['clicks'];
			$totals['impressions'] += $row['impressions'];
			$weighted              += $row['position'] * $row['impressions'];

			$bucket = 'missing';
			if ( 'present' === $row['presence_status'] ) {
				$bucket = 'present';
			} elseif ( in_array( $row['presence_status'], array( 'partial', 'needs_improvement', 'overused' ), true ) ) {
				$bucket = 'partial';
			}
			$totals[ $bucket ]++;
			$groups[ $bucket ][] = array(
				'query'       => $row['query'],
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'position'    => $row['position'],
				'status'      => $row['presence_status'],
			);

			$areas = isset( $row['analysis']['areas'] ) && is_array( $row['analysis']['areas'] ) ? $row['analysis']['areas'] : array();
			foreach ( $areas as $area => $status ) {
				if ( isset( $areas_sum[ $area ], $areas_sum[ $area ][ $status ] ) ) {
					$areas_sum[ $area ][ $status ]++;
				}
			}

			// Recommended placements: key areas the keyword is absent from.
			if ( 'present' !== $row['presence_status'] && ! empty( $areas ) && count( $placements ) < 20 ) {
				$missing_areas = array();
				foreach ( $key_areas as $area ) {
					if ( isset( $areas[ $area ] ) && 'missing' === $areas[ $area ] ) {
						$missing_areas[] = $area;
					}
				}
				if ( ! empty( $missing_areas ) ) {
					$placements[] = array(
						'query' => $row['query'],
						'areas' => $missing_areas,
					);
				}
			}

			foreach ( $row['recommendations'] as $rec ) {
				if ( ! isset( $rec['code'], $rec['dedupe_key'] ) ) {
					continue;
				}
				if ( 'add_faq' === $rec['code'] && count( $faq ) < 10 ) {
					$faq[] = $row['query'];
				}
				if ( 'page_two_push' === $rec['code'] && count( $anchors ) < 10 ) {
					$anchors[] = $row['query'];
				}

				// Page-level dedupe: "improve the title" is one task no matter
				// how many keywords triggered it.
				$key = (string) $rec['dedupe_key'];
				if ( ! isset( $page_recs[ $key ] ) ) {
					$page_recs[ $key ] = array(
						'code'     => (string) $rec['code'],
						'priority' => (int) $rec['priority'],
						'message'  => Keywords_Recommendations::message( (string) $rec['code'], isset( $rec['params'] ) ? (array) $rec['params'] : array() ),
						'keywords' => array(),
					);
				}
				if ( count( $page_recs[ $key ]['keywords'] ) < 10 && ! in_array( $row['query'], $page_recs[ $key ]['keywords'], true ) ) {
					$page_recs[ $key ]['keywords'][] = $row['query'];
				}
			}

			$rows[ $index ] = $this->rest_row( $row, true );
		}

		$totals['avg_position'] = $totals['impressions'] > 0 ? round( $weighted / $totals['impressions'], 1 ) : 0;

		$page_recs = array_values( $page_recs );
		usort(
			$page_recs,
			static function ( $a, $b ) {
				return $b['priority'] - $a['priority'];
			}
		);

		$seo_meta = $post_id > 0 ? Keywords_Seo_Meta::for_post( $post_id ) : array( 'title' => '', 'description' => '', 'source' => 'fallback' );

		$data = array(
			'page'            => array(
				'post_id'    => $post_id,
				'page_url'   => (string) $first['page_url'],
				'post_title' => $post_id > 0 ? get_the_title( $post_id ) : '',
				'post_type'  => $post_id > 0 ? (string) get_post_type( $post_id ) : '',
				'edit_link'  => $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '',
			),
			'totals'          => $totals,
			'keywords'        => $rows,
			'groups'          => $groups,
			'recommendations' => array_slice( $page_recs, 0, 10 ),
			'faq'             => $faq,
			'anchors'         => $anchors,
			'title_meta'      => array(
				'title'       => (string) $seo_meta['title'],
				'description' => (string) $seo_meta['description'],
				'source'      => (string) $seo_meta['source'],
			),
			'areas_summary'   => array_values( $areas_sum ),
		);

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Turn the feature on from the dashboard prompt (one click, no settings trip).
	 *
	 * @return \WP_REST_Response
	 */
	public function enable() {
		$settings            = Keywords_Settings::all();
		$settings['enabled'] = 1;
		Keywords_Settings::save( $settings );
		Logger::info( 'keywords-settings', 'Keyword Insights enabled from the dashboard prompt.' );

		return $this->no_cache(
			new \WP_REST_Response(
				array(
					'ok'        => true,
					'enabled'   => true,
					'connected' => Credentials::is_connected(),
					'ready'     => Keywords_Settings::ready(),
				),
				200
			)
		);
	}

	/**
	 * Start a sync.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync( $request ) {
		$ranges     = $this->json_value( $request, 'ranges' );
		$ranges     = is_array( $ranges ) ? array_map( 'sanitize_key', $ranges ) : array();
		$date_from  = sanitize_text_field( (string) $this->json_value( $request, 'date_from' ) );
		$date_to    = sanitize_text_field( (string) $this->json_value( $request, 'date_to' ) );

		$result = Keywords_Sync::request_sync( $ranges, $date_from, $date_to );
		if ( is_wp_error( $result ) ) {
			if ( 'convertrack_gsc_keywords_sync_in_progress' === $result->get_error_code() ) {
				return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'state' => 'running', 'sync' => Keywords_Sync::state() ), 200 ) );
			}
			return $result;
		}

		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'state' => 'started', 'sync' => $result ), 200 ) );
	}

	/**
	 * Queue re-analysis (one post or everything).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze( $request ) {
		$post_id = absint( $this->json_value( $request, 'post_id' ) );

		$queued = $post_id > 0 ? Keywords_Database::mark_post_stale( $post_id ) : Keywords_Database::mark_all_stale();
		Keywords_Cron::kick_analyze( 0 );
		Logger::info( 'keywords-analyzer', 'Manual re-analysis queued.', array( 'post_id' => $post_id, 'rows' => $queued ) );

		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'queued' => (int) $queued ), 200 ) );
	}

	/**
	 * Bulk actions on keyword rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( $request ) {
		$action = sanitize_key( (string) $this->json_value( $request, 'action' ) );
		$ids    = $this->json_value( $request, 'ids' );
		$ids    = is_array( $ids ) ? array_filter( array_map( 'absint', $ids ) ) : array();

		if ( 'reanalyze' !== $action || empty( $ids ) ) {
			return new \WP_Error( 'convertrack_gsc_keywords_bad_bulk', __( 'Choose at least one keyword and a valid bulk action.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$updated = Keywords_Database::mark_rows_stale( $ids );
		Keywords_Cron::kick_analyze( 0 );

		return $this->no_cache( new \WP_REST_Response( array( 'ok' => true, 'updated' => (int) $updated ), 200 ) );
	}

	/**
	 * Sync/analysis status for UI polling.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$sync           = Keywords_Sync::state();
		$analysis_error = get_option( Keywords_Analyzer::LAST_ERROR_OPTION );

		$data = array(
			'sync'     => $sync,
			'analysis' => array(
				'pending'    => Keywords_Database::pending_analysis_count(),
				'last_error' => is_array( $analysis_error ) && ! empty( $analysis_error['message'] ) ? $analysis_error : null,
			),
		);

		return $this->no_cache( new \WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Decorate a keyword row for REST output.
	 *
	 * @param array $row           Decorated DB row.
	 * @param bool  $with_messages Render every recommendation message.
	 * @return array
	 */
	private function rest_row( array $row, $with_messages = false ) {
		$primary = null;
		if ( ! empty( $row['recommendations'] ) && isset( $row['recommendations'][0]['code'] ) ) {
			$first   = $row['recommendations'][0];
			$primary = array(
				'code'    => (string) $first['code'],
				'message' => Keywords_Recommendations::message( (string) $first['code'], isset( $first['params'] ) ? (array) $first['params'] : array() ),
			);
		}
		$row['primary_recommendation'] = $primary;

		if ( $with_messages ) {
			$messages = array();
			foreach ( (array) $row['recommendations'] as $rec ) {
				if ( isset( $rec['code'] ) ) {
					$messages[] = array(
						'code'    => (string) $rec['code'],
						'message' => Keywords_Recommendations::message( (string) $rec['code'], isset( $rec['params'] ) ? (array) $rec['params'] : array() ),
					);
				}
			}
			$row['recommendation_messages'] = $messages;
		} else {
			unset( $row['recommendations'], $row['analysis'] );
		}

		unset( $row['content_hash'], $row['keyword_hash'] );
		return $row;
	}

	/**
	 * Trim a page rollup row for REST output.
	 *
	 * @param array $row Decorated page row.
	 * @return array
	 */
	private function strip_page_row( array $row ) {
		unset( $row['recommendations'] );
		return $row;
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
