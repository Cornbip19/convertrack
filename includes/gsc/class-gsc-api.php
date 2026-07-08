<?php
/**
 * Google Search Console API client.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class API {

	const INSPECT_URL        = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
	const SEARCH_CONSOLE_URL = 'https://www.googleapis.com/webmasters/v3/sites/';
	const INDEXING_URL       = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/**
	 * Inspect a URL.
	 *
	 * @param string $url URL.
	 * @return array|\WP_Error
	 */
	public static function inspect_url( $url ) {
		$response = self::request(
			'POST',
			self::INSPECT_URL,
			array(
				'inspectionUrl' => esc_url_raw( $url ),
				'siteUrl'       => Settings::get( 'property_url' ),
				'languageCode'  => 'en-US',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_inspection_response( $response );
	}

	/**
	 * Submit a sitemap to Search Console.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @return true|\WP_Error
	 */
	public static function submit_sitemap( $sitemap_url ) {
		$site_url = rawurlencode( Settings::get( 'property_url' ) );
		$feedpath = rawurlencode( esc_url_raw( $sitemap_url ) );
		$url      = self::SEARCH_CONSOLE_URL . $site_url . '/sitemaps/' . $feedpath;

		$response = self::request( 'PUT', $url, null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Notify Google Indexing API. The automatic (background) path stays
	 * disabled for normal content unless a filter marks the URL eligible;
	 * an explicit admin click ("Notify Google") defaults to eligible but can
	 * still be vetoed by the same filter.
	 *
	 * @param string $url     URL.
	 * @param int    $post_id Post id.
	 * @param bool   $manual  True when an admin explicitly requested it.
	 * @return true|\WP_Error
	 */
	public static function indexing_api_notify( $url, $post_id, $manual = false ) {
		$eligible = (bool) apply_filters( 'convertrack_gsc_indexing_api_eligible', $manual, $post_id, $url );
		if ( ! $eligible ) {
			return new \WP_Error( 'convertrack_gsc_indexing_api_not_eligible', __( 'This URL is not eligible for the Google Indexing API.', 'convertrack-click-conversion-analytics' ) );
		}

		$response = self::request(
			'POST',
			self::INDEXING_URL,
			array(
				'url'  => esc_url_raw( $url ),
				'type' => 'URL_UPDATED',
			)
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Verify the saved property URL is one the connected account can access.
	 * Returns true when it matches, or a WP_Error describing the mismatch. Used
	 * to surface a clear message instead of a silent 403 during inspection.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_property() {
		$property = (string) Settings::get( 'property_url' );
		if ( '' === $property ) {
			return true;
		}

		$response = self::request( 'GET', rtrim( self::SEARCH_CONSOLE_URL, '/' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$entries = isset( $response['siteEntry'] ) && is_array( $response['siteEntry'] ) ? $response['siteEntry'] : array();
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['siteUrl'] ) || $entry['siteUrl'] !== $property ) {
				continue;
			}
			if ( isset( $entry['permissionLevel'] ) && 'siteUnverifiedUser' === $entry['permissionLevel'] ) {
				return new \WP_Error(
					'convertrack_gsc_property_unverified',
					sprintf(
						/* translators: %s: property URL. */
						__( 'The connected Google account can see %s but is not a verified owner of it.', 'convertrack-click-conversion-analytics' ),
						$property
					)
				);
			}
			return true;
		}

		return new \WP_Error(
			'convertrack_gsc_property_not_found',
			sprintf(
				/* translators: %s: property URL. */
				__( '%s is not among the properties this Google account can access. Check the format (e.g. https://example.com/ vs sc-domain:example.com).', 'convertrack-click-conversion-analytics' ),
				$property
			)
		);
	}

	/**
	 * List the verified Search Console properties the connected account can
	 * access, for the property picker in the admin UI.
	 *
	 * @return array|\WP_Error List of { siteUrl, permissionLevel }.
	 */
	public static function list_sites() {
		$response = self::request( 'GET', rtrim( self::SEARCH_CONSOLE_URL, '/' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$entries = isset( $response['siteEntry'] ) && is_array( $response['siteEntry'] ) ? $response['siteEntry'] : array();
		$out     = array();
		foreach ( $entries as $entry ) {
			if ( empty( $entry['siteUrl'] ) ) {
				continue;
			}
			$out[] = array(
				'siteUrl'         => (string) $entry['siteUrl'],
				'permissionLevel' => isset( $entry['permissionLevel'] ) ? (string) $entry['permissionLevel'] : '',
			);
		}

		return $out;
	}

	/**
	 * Query the Search Analytics API for keyword/page performance rows.
	 *
	 * Uses dataState 'final' so ranges are stable and re-syncs idempotent
	 * (fresh partial days are excluded). Metrics (clicks, impressions, ctr,
	 * position) are always returned per row.
	 *
	 * @param string $start_date    Start date (Y-m-d, inclusive).
	 * @param string $end_date      End date (Y-m-d, inclusive).
	 * @param array  $dimensions    Dimensions, e.g. array( 'query', 'page' ).
	 * @param int    $row_limit     Rows per request (1-25000).
	 * @param int    $start_row     Pagination offset.
	 * @param array  $filter_groups Raw dimensionFilterGroups, optional.
	 * @return array|\WP_Error { rows: [ { keys, clicks, impressions, ctr, position } ], responseAggregationType }
	 */
	public static function search_analytics_query( $start_date, $end_date, array $dimensions = array( 'query', 'page' ), $row_limit = 25000, $start_row = 0, array $filter_groups = array() ) {
		$site = rawurlencode( (string) Settings::get( 'property_url' ) );
		$body = array(
			'startDate'  => sanitize_text_field( $start_date ),
			'endDate'    => sanitize_text_field( $end_date ),
			'dimensions' => array_values( $dimensions ),
			'rowLimit'   => max( 1, min( 25000, (int) $row_limit ) ),
			'startRow'   => max( 0, (int) $start_row ),
			'dataState'  => 'final',
			'type'       => 'web',
		);
		if ( ! empty( $filter_groups ) ) {
			$body['dimensionFilterGroups'] = $filter_groups;
		}

		$response = self::request( 'POST', self::SEARCH_CONSOLE_URL . $site . '/searchAnalytics/query', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'rows'                    => isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array(),
			'responseAggregationType' => isset( $response['responseAggregationType'] ) ? (string) $response['responseAggregationType'] : '',
		);
	}

	/**
	 * Make an authorized Google API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   Request body.
	 * @param bool       $retry  Retry once after token refresh.
	 * @return array|\WP_Error
	 */
	private static function request( $method, $url, $body = null, $retry = true ) {
		$token = OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 3,
			'user-agent'  => 'Convertrack/' . CONVERTRACK_VERSION . ' Google-Search-Console',
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();
		$data = is_array( $data ) ? $data : array();

		if ( 401 === $code && $retry ) {
			$refresh = OAuth::refresh_token();
			if ( is_wp_error( $refresh ) ) {
				return $refresh;
			}
			return self::request( $method, $url, $body, false );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'Google API returned HTTP %d.', $code );
			$error = new \WP_Error( 'convertrack_gsc_google_api_error', $message, array( 'status' => $code, 'body' => $data ) );
			if ( self::is_quota_error( $error ) ) {
				$error->add( 'convertrack_gsc_quota_error', $message, array( 'status' => $code, 'body' => $data ) );
			}
			return $error;
		}

		return $data;
	}

	/**
	 * Parse URL Inspection API response into queue fields.
	 *
	 * @param array $data API response.
	 * @return array
	 */
	private static function parse_inspection_response( array $data ) {
		$result = isset( $data['inspectionResult']['indexStatusResult'] ) && is_array( $data['inspectionResult']['indexStatusResult'] )
			? $data['inspectionResult']['indexStatusResult']
			: array();

		$verdict  = isset( $result['verdict'] ) ? (string) $result['verdict'] : '';
		$coverage = isset( $result['coverageState'] ) ? (string) $result['coverageState'] : '';
		$robots   = isset( $result['robotsTxtState'] ) ? (string) $result['robotsTxtState'] : '';
		$indexing = isset( $result['indexingState'] ) ? (string) $result['indexingState'] : '';
		$fetch    = isset( $result['pageFetchState'] ) ? (string) $result['pageFetchState'] : '';

		$status = self::map_status( $verdict, $coverage, $robots, $indexing );

		return array(
			'index_status'           => $status,
			'coverage_state'         => $coverage,
			'google_verdict'         => $verdict,
			'robots_txt_state'       => $robots,
			'indexing_state'         => $indexing,
			'page_fetch_state'       => $fetch,
			'canonical_url'          => isset( $result['canonical'] ) ? $result['canonical'] : '',
			'google_canonical'       => isset( $result['googleCanonical'] ) ? $result['googleCanonical'] : '',
			'user_canonical'         => isset( $result['userCanonical'] ) ? $result['userCanonical'] : '',
			'inspection_result_link' => isset( $data['inspectionResult']['inspectionResultLink'] ) ? $data['inspectionResult']['inspectionResultLink'] : '',
			'next_check_at'          => 'indexed' === $status ? Database::mysql_time( WEEK_IN_SECONDS ) : Database::mysql_time( rand( 24, 72 ) * HOUR_IN_SECONDS ),
		);
	}

	/**
	 * Map Google states to Convertrack statuses.
	 *
	 * @param string $verdict  Verdict.
	 * @param string $coverage Coverage state.
	 * @param string $robots   Robots state.
	 * @param string $indexing Indexing state.
	 * @return string
	 */
	private static function map_status( $verdict, $coverage, $robots, $indexing ) {
		$coverage_l = strtolower( $coverage );
		$robots_l   = strtolower( $robots );
		$indexing_l = strtolower( $indexing );

		if ( 'PASS' === strtoupper( $verdict ) ) {
			return 'indexed';
		}
		if ( '' !== $robots && false === strpos( $robots_l, 'allowed' ) ) {
			return 'blocked_by_robots';
		}
		if ( false !== strpos( $indexing_l, 'blocked_by_meta_tag' ) || false !== strpos( $coverage_l, 'noindex' ) ) {
			return 'noindex_detected';
		}
		if ( false !== strpos( $coverage_l, 'duplicate' ) || false !== strpos( $coverage_l, 'canonical' ) ) {
			return 'duplicate_canonical';
		}
		if ( false !== strpos( $coverage_l, 'discovered' ) && false !== strpos( $coverage_l, 'not indexed' ) ) {
			return 'discovered_not_indexed';
		}
		if ( false !== strpos( $coverage_l, 'crawled' ) && false !== strpos( $coverage_l, 'not indexed' ) ) {
			return 'crawled_not_indexed';
		}

		return 'not_indexed';
	}

	/**
	 * Whether an error is quota/rate-limit related.
	 *
	 * @param \WP_Error $error Error.
	 * @return bool
	 */
	public static function is_quota_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$message = strtolower( $error->get_error_message() );
		$api_status = '';

		if ( is_array( $data ) && isset( $data['body']['error']['status'] ) ) {
			$api_status = strtolower( (string) $data['body']['error']['status'] );
		}

		return 429 === $status
			|| false !== strpos( $message, 'quota' )
			|| false !== strpos( $message, 'rate limit' )
			|| false !== strpos( $api_status, 'resource_exhausted' );
	}

	/**
	 * Whether an error is a Google permission/ownership rejection.
	 *
	 * @param \WP_Error $error Error.
	 * @return bool
	 */
	public static function is_permission_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data       = $error->get_error_data();
		$status     = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$api_status = '';

		if ( is_array( $data ) && isset( $data['body']['error']['status'] ) ) {
			$api_status = strtolower( (string) $data['body']['error']['status'] );
		}

		return 403 === $status || 'permission_denied' === $api_status;
	}

	/**
	 * Whether an error means the Search Console API is not enabled in the
	 * user's Google Cloud project. Google returns two shapes for this:
	 * legacy errors[0].reason = accessNotConfigured, and the newer
	 * details[].reason = SERVICE_DISABLED ErrorInfo.
	 *
	 * @param \WP_Error $error Error.
	 * @return bool
	 */
	public static function is_api_disabled_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data  = $error->get_error_data();
		$body  = is_array( $data ) && isset( $data['body']['error'] ) && is_array( $data['body']['error'] ) ? $data['body']['error'] : array();
		$state = isset( $body['status'] ) ? strtolower( (string) $body['status'] ) : '';

		if ( isset( $body['errors'][0]['reason'] ) && 'accessnotconfigured' === strtolower( (string) $body['errors'][0]['reason'] ) ) {
			return true;
		}

		if ( isset( $body['details'] ) && is_array( $body['details'] ) ) {
			foreach ( $body['details'] as $detail ) {
				if ( isset( $detail['reason'] ) && 'service_disabled' === strtolower( (string) $detail['reason'] ) ) {
					return true;
				}
			}
		}

		$message = strtolower( $error->get_error_message() );
		return 'permission_denied' === $state
			&& ( false !== strpos( $message, 'has not been used in project' ) || false !== strpos( $message, 'is disabled' ) );
	}

	/**
	 * Actionable hint shown when the Search Console API is disabled.
	 *
	 * @return string
	 */
	public static function api_disabled_hint() {
		return sprintf(
			/* translators: %s: Google Cloud Console API library URL. */
			__( 'The Google Search Console API is not enabled in your Google Cloud project. Enable it at %s, wait a few minutes, then reload this page.', 'convertrack-click-conversion-analytics' ),
			'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com'
		);
	}
}
