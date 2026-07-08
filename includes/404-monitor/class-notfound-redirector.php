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
		$redirect = Database::find_active_redirect( $source );
		if ( ! $redirect || empty( $redirect['destination_url'] ) ) {
			return;
		}
		$validation = self::validate_pair( $redirect['source_url'], $redirect['destination_url'], false );
		if ( is_wp_error( $validation ) ) {
			Logger::warning( 'redirect', 'Active redirect skipped validation.', array( 'id' => $redirect['id'], 'error' => $validation->get_error_message() ) );
			return;
		}

		Database::record_redirect_hit( (int) $redirect['id'] );
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
				Database::set_event_status( $event_id, 'manual_review');
			}
			Logger::warning( 'redirect', 'Redirect validation failed.', array( 'source' => $source, 'destination' => $destination, 'error' => $validation->get_error_message() ) );
			return $validation;
		}

		$created = Database::upsert_redirect( $source, $destination, $event_id, 'active' );
		if ( $created && $event_id ) {
			Database::set_event_status( $event_id, $auto ? 'auto_redirected' : 'approved' );
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

		$home = wp_parse_url( home_url( '/' ) );
		$dest = wp_parse_url( $dst['url'] );
		if ( empty( $home['host'] ) || empty( $dest['host'] ) || strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) !== strtolower( preg_replace( '/^www\./', '', $dest['host'] ) ) ) {
			return new \WP_Error( 'convertrack_404_external_destination', __( '404 Monitor redirects are limited to this site in v1.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		if ( $src['path'] === $dst['path'] ) {
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

		$chain = Database::find_active_redirect( $dst['path'] );
		if ( $chain && isset( $chain['destination_url'] ) ) {
			$next = Database::normalize_destination( $chain['destination_url'] );
			if ( ! empty( $next ) && $next['path'] === $src['path'] ) {
				return new \WP_Error( 'convertrack_404_redirect_chain_loop', __( 'This redirect would create a loop through another internal redirect.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
			}
		}

		if ( ! self::destination_exists( $dst['url'] ) ) {
			return new \WP_Error( 'convertrack_404_destination_not_found', __( 'The destination URL does not appear to return a valid page.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
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
		return home_url( $request_uri );
	}

	/**
	 * Destination existence check.
	 *
	 * @param string $url Destination URL.
	 * @return bool
	 */
	private static function destination_exists( $url ) {
		$dst = Database::normalize_destination( $url );
		if ( empty( $dst ) ) {
			return false;
		}

		if ( '/' === $dst['path'] ) {
			return true;
		}

		$post_id = url_to_postid( $dst['url'] );
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			return true;
		}
		foreach ( Database::valid_candidates() as $candidate ) {
			if ( isset( $candidate['path'] ) && $candidate['path'] === $dst['path'] ) {
				return true;
			}
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'user-agent'  => 'Convertrack/' . CONVERTRACK_VERSION . ' 404-monitor',
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
