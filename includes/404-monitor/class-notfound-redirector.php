<?php
/**
 * Internal 301 redirect handling for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Redirector {

	/**
	 * Register frontend redirect hook.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
	}

	/**
	 * Redirect active internal rules.
	 */
	public function maybe_redirect() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ! Settings::get( 'enabled' ) ) {
			return;
		}

		$source = self::current_request_url();
		if ( '' === $source ) {
			return;
		}
		$override_valid = (bool) Settings::get( 'override_valid_content', 0 );
		$override_valid = (bool) apply_filters( 'convertrack_404_override_valid_content', $override_valid, $source );
		if ( ! is_404() && ! $override_valid ) {
			return;
		}
		$redirect = Database::find_active_redirect( $source );
		if ( ! $redirect || empty( $redirect['destination_url'] ) ) {
			return;
		}

		$recorded = Database::record_redirect_hit( (int) $redirect['id'] );
		if ( is_wp_error( $recorded ) ) {
			Logger::error( 'redirect', 'Redirect hit write failed.', array( 'id' => $redirect['id'], 'error' => $recorded->get_error_message() ) );
		}
		wp_redirect( esc_url_raw( $redirect['destination_url'] ), (int) $redirect['redirect_type'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Create redirect from an event recommendation.
	 *
	 * @param int  $event_id Event ID.
	 * @param bool $auto     Whether this is automatic.
	 * @return int|\WP_Error|false
	 */
	public static function create_from_event( $event_id, $auto = false ) {
		$event = Database::get_event( $event_id );
		if ( ! $event || empty( $event['suggested_url'] ) ) {
			return new \WP_Error( 'convertrack_404_missing_destination', __( 'No suggested destination is available for this 404.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}
		return self::create_redirect( $event['url'], $event['suggested_url'], $event_id, $auto );
	}

	/**
	 * Approve an event with optional destination override.
	 *
	 * @param int    $event_id    Event ID.
	 * @param string $destination Optional destination.
	 * @return int|\WP_Error|false
	 */
	public static function approve_event( $event_id, $destination = '' ) {
		$event = Database::get_event( $event_id );
		if ( ! $event ) {
			return new \WP_Error( 'convertrack_404_missing_event', __( '404 row not found.', 'convertrack-click-conversion-analytics' ), array( 'status' => 404 ) );
		}
		$destination = '' !== $destination ? $destination : $event['suggested_url'];
		return self::create_redirect( $event['url'], $destination, $event_id, false );
	}

	/**
	 * Create a redirect after validation.
	 *
	 * @param string $source      Source URL/path.
	 * @param string $destination Destination URL.
	 * @param int    $event_id    Event ID.
	 * @param bool   $auto        Whether auto-created.
	 * @return int|\WP_Error|false
	 */
	public static function create_redirect( $source, $destination, $event_id = 0, $auto = false ) {
		$validation = self::validate_pair( $source, $destination, true );
		if ( is_wp_error( $validation ) ) {
			if ( $event_id ) {
				$updated = Database::set_event_status( $event_id, 'manual_review' );
				if ( is_wp_error( $updated ) ) {
					Logger::error( 'redirect', 'Failed to move an invalid redirect recommendation to manual review.', array( 'event_id' => $event_id, 'error' => $updated->get_error_message() ) );
				}
			}
			Logger::warning( 'redirect', 'Redirect validation failed.', array( 'source' => $source, 'destination' => $destination, 'error' => $validation->get_error_message() ) );
			return $validation;
		}

		$created = Database::upsert_redirect( $source, $destination, $event_id, 'active' );
		if ( is_wp_error( $created ) ) {
			Logger::error( 'redirect', 'Redirect write failed.', array( 'source' => $source, 'destination' => $destination, 'error' => $created->get_error_message() ) );
			return $created;
		}
		if ( $event_id ) {
			$updated = Database::set_event_status( $event_id, $auto ? 'auto_redirected' : 'approved' );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		Logger::info( 'redirect', $auto ? 'Automatic 301 redirect created.' : 'Manual 301 redirect approved.', array( 'source' => $source, 'destination' => $destination ) );
		return $created;
	}

	/**
	 * Validate redirect source/destination.
	 *
	 * @param string $source            Source.
	 * @param string $destination       Destination.
	 * @param bool   $check_duplicates  Check existing redirects.
	 * @return true|\WP_Error
	 */
	public static function validate_pair( $source, $destination, $check_duplicates = true ) {
		$src = Database::normalize_source( $source );
		$dst = Database::normalize_destination( $destination );
		if ( empty( $src ) || empty( $dst ) ) {
			return new \WP_Error( 'convertrack_404_bad_redirect_url', __( 'Source or destination URL is invalid.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		$dest_parts = wp_parse_url( $dst['url'] );
		if ( is_array( $home_parts ) && is_array( $dest_parts ) && isset( $home_parts['scheme'], $dest_parts['scheme'] ) && 'https' === strtolower( $home_parts['scheme'] ) && 'http' === strtolower( $dest_parts['scheme'] ) ) {
			return new \WP_Error( 'convertrack_404_https_downgrade', __( 'HTTPS redirect destinations cannot downgrade to HTTP.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$destination_source = Database::normalize_source( $dst['url'] );
		if ( empty( $destination_source ) ) {
			return new \WP_Error( 'convertrack_404_external_destination', __( '404 Monitor redirects are limited to this site in v1.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		if ( $src['path'] === $destination_source['path'] ) {
			return new \WP_Error( 'convertrack_404_redirect_loop', __( 'Source and destination cannot be the same URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		if ( $check_duplicates ) {
			if ( Database::find_active_redirect( $src['url'] ) ) {
				return new \WP_Error( 'convertrack_404_duplicate_redirect', __( 'An internal redirect already exists for this source URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
			}
			if ( Compatibility::external_redirect_for_source( $src['url'] ) ) {
				return new \WP_Error( 'convertrack_404_external_conflict', __( 'A detected external redirect tool already appears to handle this source URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
			}
		}

		$graph = self::validate_redirect_graph( $src['path'], $destination_source['path'] );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$health = self::check_destination_health( $dst['url'] );
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		return true;
	}

	/**
	 * Current request absolute URL.
	 *
	 * @return string
	 */
	public static function current_request_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return '';
		}
		return $request_uri;
	}

	/**
	 * Destination existence/health check for rule writes and background jobs.
	 *
	 * @param string $url Destination URL.
	 * @return array|\WP_Error
	 */
	public static function check_destination_health( $url ) {
		$dst = Database::normalize_destination( $url );
		if ( empty( $dst ) ) {
			return new \WP_Error( 'convertrack_404_bad_destination', __( 'The redirect destination is invalid.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( empty( Database::normalize_source( $dst['url'] ) ) ) {
			return new \WP_Error( 'convertrack_404_external_destination', __( '404 Monitor redirects are limited to this site in v1.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( '/' === $dst['path'] ) {
			return array( 'code' => 200, 'method' => 'home' );
		}

		$post_id = url_to_postid( $dst['url'] );
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			return array( 'code' => 200, 'method' => 'post' );
		}
		foreach ( Database::valid_candidates() as $candidate ) {
			if ( isset( $candidate['path'] ) && $candidate['path'] === $dst['path'] ) {
				return array( 'code' => 200, 'method' => 'candidate' );
			}
		}

		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'user-agent'  => 'Convertrack/' . CONVERTRACK_VERSION . ' 404-monitor',
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'convertrack_404_destination_unreachable', __( 'The destination URL could not be reached.', 'convertrack-click-conversion-analytics' ), array( 'transport_error' => $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'convertrack_404_destination_not_found', __( 'The destination URL does not appear to return a valid page.', 'convertrack-click-conversion-analytics' ), array( 'http_code' => $code ) );
		}
		return array( 'code' => $code, 'method' => 'http' );
	}

	/**
	 * Traverse the complete active redirect graph and reject every cycle.
	 *
	 * @param string $source_path      Proposed source path.
	 * @param string $destination_path Proposed destination path.
	 * @return true|\WP_Error
	 */
	private static function validate_redirect_graph( $source_path, $destination_path ) {
		$visited = array( $source_path => true );
		$current = $destination_path;
		for ( $hops = 0; $hops < 100; $hops++ ) {
			if ( isset( $visited[ $current ] ) ) {
				return new \WP_Error( 'convertrack_404_redirect_chain_loop', __( 'This redirect would create a loop through the internal redirect graph.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
			}
			$visited[ $current ] = true;
			$chain = Database::find_active_redirect( $current );
			if ( ! $chain || empty( $chain['destination_url'] ) ) {
				return true;
			}
			$next = Database::normalize_source( $chain['destination_url'] );
			if ( empty( $next ) ) {
				return new \WP_Error( 'convertrack_404_redirect_chain_invalid', __( 'An existing redirect in this chain has an invalid destination.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
			}
			$current = $next['path'];
		}

		return new \WP_Error( 'convertrack_404_redirect_chain_too_long', __( 'The redirect chain is too long to validate safely.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
	}
}
