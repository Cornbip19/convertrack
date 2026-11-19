<?php
/**
 * Encrypted storage for the Search Console connection.
 *
 * Two ways to hold a Google OAuth grant:
 *
 * - "relay" (default for new installs): the site signs in through the
 *   vendor-hosted relay, which owns the Google OAuth client. The site holds a
 *   relay site registration (site_id + site_secret) and the tokens Google
 *   issues; the relay only brokers.
 * - "direct": the site owner supplies their own Google Cloud OAuth client
 *   (client_id + client_secret) and the plugin talks to Google directly.
 *
 * Sites that connected before relay mode existed keep working unchanged: they
 * hold a client and no site registration, which reads as "direct". The client
 * secret, refresh token and relay site secret are encrypted at rest.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Credentials {

	const OPTION = 'convertrack_gsc_credentials';

	const MODE_DIRECT = 'direct';
	const MODE_RELAY  = 'relay';

	/**
	 * Fields stored encrypted at rest.
	 *
	 * @var string[]
	 */
	private static $secret_fields = array( 'access_token', 'refresh_token', 'client_secret', 'site_secret' );

	/**
	 * Get redacted credential metadata for the admin UI / REST.
	 *
	 * @return array
	 */
	public static function public_status() {
		$data = self::all();
		return array(
			'connected'             => self::is_connected(),
			'has_client'            => self::has_client(),
			'auth_mode'             => self::auth_mode(),
			'has_site_registration' => self::has_site_registration(),
			'relay_url'             => self::relay_url(),
			'expires_at'            => isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
			'connected_at'          => isset( $data['connected_at'] ) ? (string) $data['connected_at'] : '',
			'scope'                 => isset( $data['scope'] ) ? (string) $data['scope'] : '',
			'google_account'        => isset( $data['google_account'] ) ? (string) $data['google_account'] : '',
			'client_id'             => self::client_id(),
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
	 * How the site authorises with Google: 'direct' or 'relay'.
	 *
	 * An explicitly chosen mode wins. Otherwise a site holding its own client
	 * and no relay registration is a pre-relay install and stays on direct
	 * OAuth; everything else (including a fresh install) uses the relay. A
	 * build with no relay configured has only direct OAuth to offer.
	 *
	 * @return string
	 */
	public static function auth_mode() {
		$data = self::all();
		if ( isset( $data['auth_mode'] ) && in_array( $data['auth_mode'], array( self::MODE_DIRECT, self::MODE_RELAY ), true ) ) {
			return $data['auth_mode'];
		}
		if ( self::has_client() && empty( $data['site_id'] ) ) {
			return self::MODE_DIRECT;
		}
		if ( class_exists( __NAMESPACE__ . '\\Relay' ) && ! Relay::is_configured() ) {
			return self::MODE_DIRECT;
		}
		return self::MODE_RELAY;
	}

	/**
	 * Whether the relay ("Sign in with Google") mode is active.
	 *
	 * @return bool
	 */
	public static function is_relay_mode() {
		return self::MODE_RELAY === self::auth_mode();
	}

	/**
	 * Choose the authorisation mode explicitly. Stored client credentials and
	 * the relay registration are both kept, so switching back is one click.
	 *
	 * @param string $mode 'direct' or 'relay'.
	 * @return true|\WP_Error
	 */
	public static function set_auth_mode( $mode ) {
		$mode = (string) $mode;
		if ( ! in_array( $mode, array( self::MODE_DIRECT, self::MODE_RELAY ), true ) ) {
			return new \WP_Error( 'convertrack_gsc_bad_auth_mode', __( 'Unknown Search Console connection mode.', 'convertrack-click-conversion-analytics' ) );
		}
		$data              = self::all();
		$data['auth_mode'] = $mode;
		return self::save( $data );
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
	 * Whether the site holds a relay site registration.
	 *
	 * @return bool
	 */
	public static function has_site_registration() {
		return '' !== self::site_id() && '' !== self::site_secret();
	}

	/**
	 * The relay site id (public, appears in sign-in URLs).
	 *
	 * @return string
	 */
	public static function site_id() {
		$data = self::all();
		return isset( $data['site_id'] ) ? (string) $data['site_id'] : '';
	}

	/**
	 * The relay site secret (proves this site to the relay's token endpoint).
	 *
	 * @return string
	 */
	public static function site_secret() {
		$data = self::all();
		return isset( $data['site_secret'] ) ? (string) $data['site_secret'] : '';
	}

	/**
	 * The relay the site registered with, so a changed relay URL triggers a
	 * fresh registration instead of a confusing credential rejection.
	 *
	 * @return string
	 */
	public static function relay_url() {
		$data = self::all();
		return isset( $data['relay_url'] ) ? (string) $data['relay_url'] : '';
	}

	/**
	 * Store a relay site registration.
	 *
	 * @param string $site_id     Site id issued by the relay.
	 * @param string $site_secret Site secret issued by the relay.
	 * @param string $relay_url   Relay origin the registration belongs to.
	 * @return true|\WP_Error
	 */
	public static function store_site_registration( $site_id, $site_secret, $relay_url ) {
		$data                = self::all();
		$data['site_id']     = sanitize_text_field( (string) $site_id );
		$data['site_secret'] = trim( (string) $site_secret );
		$data['relay_url']   = esc_url_raw( untrailingslashit( (string) $relay_url ) );
		return self::save( $data );
	}

	/**
	 * Forget the relay site registration (the next sign-in registers again).
	 *
	 * @return true|\WP_Error
	 */
	public static function clear_site_registration() {
		$data = self::all();
		unset( $data['site_id'], $data['site_secret'], $data['relay_url'] );
		return self::save( $data );
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
	 * Clear the connection tokens but keep the stored OAuth client credentials
	 * and the relay registration, so the site owner does not have to re-enter
	 * anything on reconnect.
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

		$iv     = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : wp_generate_password( 16, true, true );
		$iv     = substr( $iv, 0, 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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

		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
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
