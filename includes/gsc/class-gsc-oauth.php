<?php
/**
 * Google OAuth flow.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class OAuth {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const SCOPE     = 'https://www.googleapis.com/auth/webmasters';
	const INDEXING_SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * Build authorization URL.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $state        State.
	 * @return string|\WP_Error
	 */
	public static function authorization_url( $redirect_uri, $state ) {
		$client_id = Settings::get( 'client_id' );
		if ( '' === $client_id || ! Credentials::has_client_secret() ) {
			return new \WP_Error( 'convertrack_gsc_missing_oauth', __( 'Google OAuth Client ID and Client Secret are required.', 'convertrack-click-conversion-analytics' ) );
		}

		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => self::scope(),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI.
	 * @return true|\WP_Error
	 */
	public static function exchange_code( $code, $redirect_uri ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => Settings::get( 'client_id' ),
					'client_secret' => Credentials::client_secret(),
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'oauth', 'OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google OAuth token exchange failed.', 'convertrack-click-conversion-analytics' );
			Logger::error( 'oauth', 'OAuth token exchange failed.', array( 'status' => $code_status, 'body' => $body ) );
			return new \WP_Error( 'convertrack_gsc_oauth_exchange_failed', $message );
		}

		$stored = Credentials::store_tokens( $body );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'oauth', 'Google Search Console OAuth connected.' );
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
	 * Refresh OAuth access token.
	 *
	 * @return string|\WP_Error
	 */
	public static function refresh_token() {
		$refresh_token = Credentials::refresh_token();
		if ( '' === $refresh_token ) {
			return new \WP_Error( 'convertrack_gsc_no_refresh_token', __( 'Google refresh token is missing. Reconnect Search Console.', 'convertrack-click-conversion-analytics' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => Settings::get( 'client_id' ),
					'client_secret' => Credentials::client_secret(),
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'oauth', 'OAuth token refresh failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google OAuth token refresh failed.', 'convertrack-click-conversion-analytics' );
			Logger::error( 'oauth', 'OAuth token refresh failed.', array( 'status' => $code_status, 'body' => $body ) );
			return new \WP_Error( 'convertrack_gsc_oauth_refresh_failed', $message );
		}

		$stored = Credentials::store_tokens( $body );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'oauth', 'Google OAuth access token refreshed.' );
		return Credentials::access_token();
	}

	/**
	 * OAuth scope string.
	 *
	 * @return string
	 */
	private static function scope() {
		$scopes = array( self::SCOPE );
		if ( Settings::get( 'use_indexing_api' ) ) {
			$scopes[] = self::INDEXING_SCOPE;
		}
		return implode( ' ', $scopes );
	}
}
