<?php
/**
 * Encrypted storage for the Search Console connection.
 *
 * Direct-OAuth model: the site owner supplies their own Google Cloud OAuth
 * client (client_id + client_secret) and the plugin talks to Google directly,
 * holding the OAuth refresh token and short-lived access token. The client
 * secret and refresh token are encrypted at rest.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Credentials {

	const OPTION = 'convertrack_gsc_credentials';

	/**
	 * Fields stored encrypted at rest.
	 *
	 * @var string[]
	 */
	private static $secret_fields = array( 'access_token', 'refresh_token', 'client_secret' );

	/**
	 * Get redacted credential metadata for the admin UI / REST.
	 *
	 * @return array
	 */
	public static function public_status() {
		$data = self::all();
		return array(
			'connected'      => self::is_connected(),
			'has_client'     => self::has_client(),
			'expires_at'     => isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
			'connected_at'   => isset( $data['connected_at'] ) ? (string) $data['connected_at'] : '',
			'scope'          => isset( $data['scope'] ) ? (string) $data['scope'] : '',
			'google_account' => isset( $data['google_account'] ) ? (string) $data['google_account'] : '',
			'client_id'      => self::client_id(),
		);
	}

	/**
	 * Whether Google Search Console is connected (a refresh token is held).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$data = self::all();
		return ! empty( $data['refresh_token'] ) || ! empty( $data['access_token'] );
	}

	/**
	 * Whether the site owner has stored their Google OAuth client credentials.
	 *
	 * @return bool
	 */
	public static function has_client() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * Store the site owner's Google OAuth client credentials.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @return true|\WP_Error
	 */
	public static function store_client( $client_id, $client_secret ) {
		$data                  = self::all();
		$data['client_id']     = trim( (string) $client_id );
		$data['client_secret'] = trim( (string) $client_secret );
		return self::save( $data );
	}

	/**
	 * The stored OAuth client ID (not secret).
	 *
	 * @return string
	 */
	public static function client_id() {
		$data = self::all();
		return isset( $data['client_id'] ) ? (string) $data['client_id'] : '';
	}

	/**
	 * The stored OAuth client secret.
	 *
	 * @return string
	 */
	public static function client_secret() {
		$data = self::all();
		return isset( $data['client_secret'] ) ? (string) $data['client_secret'] : '';
	}

	/**
	 * The stored OAuth refresh token.
	 *
	 * @return string
	 */
	public static function refresh_token() {
		$data = self::all();
		return isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '';
	}

	/**
	 * Store a freshly established connection (from the authorization-code exchange).
	 *
	 * Google only returns a refresh token on the first consent (or when
	 * prompt=consent is forced); if one is not returned we keep the existing one.
	 *
	 * @param string $access_token   Initial access token.
	 * @param int    $expires_in     Seconds until the access token expires.
	 * @param string $scope          Granted scope string.
	 * @param string $refresh_token  Refresh token (may be empty on re-consent).
	 * @param string $google_account Connected Google account email (for display).
	 * @return true|\WP_Error
	 */
	public static function store_connection( $access_token, $expires_in, $scope, $refresh_token = '', $google_account = '' ) {
		$data = self::all();

		$data['access_token'] = (string) $access_token;
		$data['scope']        = sanitize_text_field( (string) $scope );
		$data['expires_at']   = self::expiry( $expires_in );
		$data['connected_at'] = current_time( 'mysql' );

		if ( '' !== (string) $refresh_token ) {
			$data['refresh_token'] = (string) $refresh_token;
		}
		if ( '' !== (string) $google_account ) {
			$data['google_account'] = sanitize_text_field( (string) $google_account );
		}

		return self::save( $data );
	}

	/**
	 * Store a refreshed access token.
	 *
	 * @param string $access_token Access token.
	 * @param int    $expires_in   Seconds until expiry.
	 * @return true|\WP_Error
	 */
	public static function store_access_token( $access_token, $expires_in ) {
		$data                 = self::all();
		$data['access_token'] = (string) $access_token;
		$data['expires_at']   = self::expiry( $expires_in );
		return self::save( $data );
	}

	/**
	 * Get the current access token.
	 *
	 * @return string
	 */
	public static function access_token() {
		$data = self::all();
		return isset( $data['access_token'] ) ? (string) $data['access_token'] : '';
	}

	/**
	 * Whether the access token is missing or expired.
	 *
	 * @return bool
	 */
	public static function access_token_expired() {
		$data = self::all();
		return empty( $data['access_token'] ) || empty( $data['expires_at'] ) || time() >= (int) $data['expires_at'];
	}

	/**
	 * Clear the connection tokens but keep the stored OAuth client credentials,
	 * so the site owner does not have to re-enter their client on reconnect.
	 */
	public static function clear_tokens() {
		$data = self::all();
		unset(
			$data['access_token'],
			$data['refresh_token'],
			$data['expires_at'],
			$data['scope'],
			$data['connected_at'],
			$data['google_account']
		);
		self::save( $data );
	}

	/**
	 * Delete all stored credentials, including the OAuth client.
	 */
	public static function delete_all() {
		delete_option( self::OPTION );
	}

	/**
	 * Compute an absolute expiry with a safety margin.
	 *
	 * @param int $expires_in Seconds until expiry.
	 * @return int
	 */
	private static function expiry( $expires_in ) {
		$expires_in = (int) $expires_in;
		// Margin (180s) so a slow round-trip can't leave the stored expiry
		// optimistic; API::request() also self-heals one 401.
		return time() + max( 120, $expires_in ) - 180;
	}

	/**
	 * Read and decrypt the credential option.
	 *
	 * @return array
	 */
	private static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( $stored as $key => $value ) {
			if ( in_array( $key, self::$secret_fields, true ) ) {
				$out[ $key ] = self::decrypt( $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Encrypt and save the credential option with autoload disabled.
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
			if ( in_array( $key, self::$secret_fields, true ) ) {
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
