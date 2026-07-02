<?php
/**
 * Direct Google OAuth 2.0 (Authorization Code + PKCE) for Search Console.
 *
 * The site owner supplies their own Google Cloud OAuth client. The plugin talks
 * to Google directly: it redirects to Google's consent screen, exchanges the
 * returned code for access + refresh tokens, and refreshes/revokes them itself.
 * No third-party broker is involved.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class OAuth {

	const AUTH_ENDPOINT   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
	const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	/**
	 * The redirect URI Google returns the browser to. Must exactly match a URI
	 * registered on the OAuth client in Google Cloud, and is used identically in
	 * the auth request and the token exchange. Filterable so a tunnel/HTTPS host
	 * can be used for local testing.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return (string) apply_filters( 'convertrack_gsc_redirect_uri', Admin::callback_url() );
	}

	/**
	 * Space-delimited OAuth scopes.
	 *
	 * webmasters (read/write) covers URL Inspection, sitemaps.submit and
	 * sites.list; openid+email surface the connected account for display; the
	 * Indexing API scope is requested only when that feature is enabled.
	 *
	 * @return string
	 */
	public static function scopes() {
		$scopes = array(
			'https://www.googleapis.com/auth/webmasters',
			'openid',
			'email',
		);
		if ( Settings::get( 'use_indexing_api' ) ) {
			$scopes[] = 'https://www.googleapis.com/auth/indexing';
		}
		return implode( ' ', $scopes );
	}

	/**
	 * Build the Google consent-screen URL the admin's browser is sent to.
	 *
	 * @param string $redirect_uri   Registered redirect URI.
	 * @param string $state          Anti-CSRF state echoed back by Google.
	 * @param string $code_challenge PKCE challenge (S256).
	 * @return string|\WP_Error
	 */
	public static function connect_url( $redirect_uri, $state, $code_challenge ) {
		if ( ! Credentials::has_client() ) {
			return new \WP_Error( 'convertrack_gsc_no_client', __( 'Enter your Google OAuth Client ID and Secret before connecting.', 'convertrack-click-conversion-analytics' ) );
		}

		$params = array(
			'client_id'              => Credentials::client_id(),
			'redirect_uri'           => $redirect_uri,
			'response_type'          => 'code',
			'scope'                  => self::scopes(),
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
			'code_challenge'         => $code_challenge,
			'code_challenge_method'  => 'S256',
		);

		return self::AUTH_ENDPOINT . '?' . http_build_query( $params, '', '&' );
	}

	/**
	 * Derive the PKCE code challenge (base64url SHA-256) from a verifier.
	 *
	 * @param string $verifier High-entropy verifier.
	 * @return string
	 */
	public static function code_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', (string) $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Exchange an authorization code for access + refresh tokens.
	 *
	 * @param string $code          Authorization code from Google's redirect.
	 * @param string $code_verifier PKCE verifier proving this site started the flow.
	 * @param string $redirect_uri  Same redirect URI used to start the flow.
	 * @return true|\WP_Error
	 */
	public static function exchange_code( $code, $code_verifier, $redirect_uri ) {
		if ( ! Credentials::has_client() ) {
			return new \WP_Error( 'convertrack_gsc_no_client', __( 'Google OAuth client credentials are missing. Re-enter them and connect again.', 'convertrack-click-conversion-analytics' ) );
		}

		$result = self::post_token(
			array(
				'code'          => $code,
				'client_id'     => Credentials::client_id(),
				'client_secret' => Credentials::client_secret(),
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
				'code_verifier' => $code_verifier,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result['data'];
		if ( 200 !== $result['code'] || empty( $data['access_token'] ) ) {
			$message = self::error_message( $data, __( 'The Search Console connection could not be completed.', 'convertrack-click-conversion-analytics' ) );
			Logger::error( 'oauth', 'Token exchange failed.', array( 'status' => $result['code'], 'error' => $message ) );
			return new \WP_Error( 'convertrack_gsc_token_failed', $message );
		}

		$email  = isset( $data['id_token'] ) ? self::email_from_id_token( $data['id_token'] ) : '';
		$stored = Credentials::store_connection(
			$data['access_token'],
			isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
			isset( $data['scope'] ) ? $data['scope'] : '',
			isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
			$email
		);
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'oauth', 'Google Search Console connected.' );
		return true;
	}

	/**
	 * Return a valid access token, refreshing if necessary.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		if ( ! Credentials::access_token_expired() ) {
			return Credentials::access_token();
		}

		return self::refresh_token();
	}

	/**
	 * Refresh the access token directly with Google.
	 *
	 * @return string|\WP_Error
	 */
	public static function refresh_token() {
		$refresh = Credentials::refresh_token();
		if ( '' === $refresh ) {
			return new \WP_Error( 'convertrack_gsc_not_connected', __( 'Search Console is not connected. Please reconnect.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( ! Credentials::has_client() ) {
			return new \WP_Error( 'convertrack_gsc_no_client', __( 'Google OAuth client credentials are missing. Re-enter them and reconnect.', 'convertrack-click-conversion-analytics' ) );
		}

		$result = self::post_token(
			array(
				'client_id'     => Credentials::client_id(),
				'client_secret' => Credentials::client_secret(),
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data  = $result['data'];
		$error = isset( $data['error'] ) ? (string) $data['error'] : '';

		// A dead/revoked refresh token is permanent — surface a reconnect prompt.
		if ( 'invalid_grant' === $error ) {
			Credentials::clear_tokens();
			set_transient( 'convertrack_gsc_reconnect_required', 1, MONTH_IN_SECONDS );
			Logger::error( 'oauth', 'Refresh token invalid; reconnect required.', array( 'error' => $error ) );
			return new \WP_Error( 'convertrack_gsc_reconnect_required', __( 'Google revoked or expired this connection. Please reconnect Search Console.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( 200 !== $result['code'] || empty( $data['access_token'] ) ) {
			$message = self::error_message( $data, __( 'Could not refresh the Search Console access token.', 'convertrack-click-conversion-analytics' ) );
			Logger::error( 'oauth', 'Token refresh failed.', array( 'status' => $result['code'], 'error' => $message ) );
			return new \WP_Error( 'convertrack_gsc_refresh_failed', $message );
		}

		$stored = Credentials::store_access_token( $data['access_token'], isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600 );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'oauth', 'Google access token refreshed.' );
		return Credentials::access_token();
	}

	/**
	 * Revoke the grant with Google. Best effort.
	 */
	public static function revoke() {
		$token = Credentials::refresh_token();
		if ( '' === $token ) {
			$token = Credentials::access_token();
		}
		if ( '' === $token ) {
			return;
		}

		$response = wp_remote_post(
			self::REVOKE_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array( 'token' => $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::warning( 'oauth', 'Token revoke call failed; clearing local credentials anyway.', array( 'error' => $response->get_error_message() ) );
		}
	}

	/**
	 * POST a form body to Google's token endpoint.
	 *
	 * @param array $body Request parameters.
	 * @return array|\WP_Error { code:int, data:array }
	 */
	private static function post_token( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::error( 'oauth', 'Token endpoint request failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'data' => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Extract a human-readable message from a Google token error body.
	 *
	 * @param array  $data     Decoded response.
	 * @param string $fallback Default message.
	 * @return string
	 */
	private static function error_message( array $data, $fallback ) {
		if ( ! empty( $data['error_description'] ) ) {
			return (string) $data['error_description'];
		}
		if ( ! empty( $data['error'] ) ) {
			return is_array( $data['error'] ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : (string) $data['error'];
		}
		return $fallback;
	}

	/**
	 * Read the `email` claim from an OpenID Connect id_token (display only).
	 * The token arrived over TLS directly from Google, so no signature check is
	 * needed for a non-authorizing display value.
	 *
	 * @param string $id_token Compact JWT.
	 * @return string
	 */
	private static function email_from_id_token( $id_token ) {
		$parts = explode( '.', (string) $id_token );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$payload = json_decode( self::base64url_decode( $parts[1] ), true );
		return is_array( $payload ) && isset( $payload['email'] ) ? sanitize_text_field( (string) $payload['email'] ) : '';
	}

	/**
	 * Decode a base64url string.
	 *
	 * @param string $data Base64url data.
	 * @return string
	 */
	private static function base64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $decoded ? '' : $decoded;
	}
}
