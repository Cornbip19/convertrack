<?php
/**
 * Encrypted storage for Google OAuth credentials and tokens.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Credentials {

	const OPTION = 'convertrack_gsc_credentials';

	/**
	 * Get redacted credential metadata.
	 *
	 * @return array
	 */
	public static function public_status() {
		$data = self::all();
		return array(
			'has_client_secret' => ! empty( $data['client_secret'] ),
			'connected'         => ! empty( $data['refresh_token'] ) || ! empty( $data['access_token'] ),
			'expires_at'        => isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
			'connected_at'      => isset( $data['connected_at'] ) ? (string) $data['connected_at'] : '',
			'scope'             => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		);
	}

	/**
	 * Whether a client secret exists.
	 *
	 * @return bool
	 */
	public static function has_client_secret() {
		$data = self::all();
		return ! empty( $data['client_secret'] );
	}

	/**
	 * Whether OAuth tokens are present.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$data = self::all();
		return ! empty( $data['refresh_token'] ) || ! empty( $data['access_token'] );
	}

	/**
	 * Store OAuth client secret.
	 *
	 * @param string $secret Secret.
	 * @return true|\WP_Error
	 */
	public static function set_client_secret( $secret ) {
		$secret = trim( (string) $secret );
		if ( '' === $secret ) {
			return true;
		}

		$data = self::all();
		$data['client_secret'] = $secret;
		return self::save( $data );
	}

	/**
	 * Return OAuth client secret.
	 *
	 * @return string
	 */
	public static function client_secret() {
		$data = self::all();
		return isset( $data['client_secret'] ) ? (string) $data['client_secret'] : '';
	}

	/**
	 * Store token response.
	 *
	 * @param array $tokens Token response from Google.
	 * @return true|\WP_Error
	 */
	public static function store_tokens( array $tokens ) {
		$data = self::all();

		if ( ! empty( $tokens['access_token'] ) ) {
			$data['access_token'] = (string) $tokens['access_token'];
		}
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$data['refresh_token'] = (string) $tokens['refresh_token'];
		}
		if ( ! empty( $tokens['token_type'] ) ) {
			$data['token_type'] = sanitize_text_field( $tokens['token_type'] );
		}
		if ( ! empty( $tokens['scope'] ) ) {
			$data['scope'] = sanitize_text_field( $tokens['scope'] );
		}

		$expires_in = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600;
		$data['expires_at'] = time() + max( 60, $expires_in ) - 60;
		$data['connected_at'] = current_time( 'mysql' );

		return self::save( $data );
	}

	/**
	 * Get an access token.
	 *
	 * @return string
	 */
	public static function access_token() {
		$data = self::all();
		return isset( $data['access_token'] ) ? (string) $data['access_token'] : '';
	}

	/**
	 * Get a refresh token.
	 *
	 * @return string
	 */
	public static function refresh_token() {
		$data = self::all();
		return isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '';
	}

	/**
	 * Whether the access token should be refreshed.
	 *
	 * @return bool
	 */
	public static function access_token_expired() {
		$data = self::all();
		return empty( $data['access_token'] ) || empty( $data['expires_at'] ) || time() >= (int) $data['expires_at'];
	}

	/**
	 * Clear OAuth tokens but keep the saved client secret.
	 */
	public static function clear_tokens() {
		$data = self::all();
		unset( $data['access_token'], $data['refresh_token'], $data['expires_at'], $data['token_type'], $data['scope'], $data['connected_at'] );
		self::save( $data );
	}

	/**
	 * Delete all stored credentials.
	 */
	public static function delete_all() {
		delete_option( self::OPTION );
	}

	/**
	 * Read and decrypt credential option.
	 *
	 * @return array
	 */
	private static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( $stored as $key => $value ) {
			if ( in_array( $key, array( 'client_secret', 'access_token', 'refresh_token' ), true ) ) {
				$out[ $key ] = self::decrypt( $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Encrypt and save credential option with autoload disabled.
	 *
	 * @param array $data Plain data.
	 * @return true|\WP_Error
	 */
	private static function save( array $data ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return new \WP_Error( 'convertrack_gsc_openssl_missing', __( 'OpenSSL is required to store Google credentials securely.', 'convertrack-click-conversion-analytics' ) );
		}

		$stored = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'client_secret', 'access_token', 'refresh_token' ), true ) ) {
				$stored[ $key ] = self::encrypt( (string) $value );
			} else {
				$stored[ $key ] = $value;
			}
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $stored, '', false );
		} else {
			update_option( self::OPTION, $stored, false );
		}

		return true;
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain value.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}

		$iv = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : wp_generate_password( 16, true, true );
		$iv = substr( $iv, 0, 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a value.
	 *
	 * @param string $encoded Encoded encrypted value.
	 * @return string
	 */
	private static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Encryption key derived from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . CONVERTRACK_BASENAME, true );
	}
}
