<?php
/**
 * Client for the vendor-hosted "Sign in with Google" relay.
 *
 * The relay (see /relay in the repository) owns the Google OAuth client and
 * only brokers: it registers a site, sends the admin to Google's consent
 * screen, bounces Google's authorization code back to this site, and forwards
 * token requests to Google with the client secret added. This site keeps its
 * own anti-CSRF state, its PKCE verifier and the tokens Google issues.
 *
 * Relay and transport failures are returned as WP_Errors whose codes never
 * equal 'convertrack_gsc_reconnect_required', so a relay outage can never be
 * mistaken for a revoked Google grant (which only Google may declare, with a
 * literal `invalid_grant`).
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

/**
 * Registers this site with the relay, builds the sign-in URL and posts token
 * requests through it.
 */
class Relay {

	/**
	 * Default relay origin. Replace with the deployed Worker's custom domain,
	 * or filter `convertrack_gsc_relay_url`.
	 */
	const DEFAULT_URL = 'https://connect.example.com';

	/**
	 * Privacy policy for the relay, linked from the sign-in button so the notice
	 * is reachable from inside the product (Google API Services User Data Policy).
	 */
	const PRIVACY_URL = 'https://convertrack.io/privacy';

	const CHALLENGE_TRANSIENT = 'convertrack_gsc_relay_challenge';
	const CHALLENGE_TTL       = 600;
	const TIMEOUT             = 20;

	/**
	 * The fixed tail of this site's OAuth callback. The relay reconstructs the
	 * callback from the registered site URL plus this path, so the site URL sent
	 * at registration is the callback with this tail removed.
	 */
	const CALLBACK_SUFFIX = '/wp-admin/admin-post.php?action=convertrack_gsc_oauth_callback';

	/**
	 * Relay origin without a trailing slash.
	 *
	 * @return string
	 */
	public static function url() {
		/**
		 * Filter the relay origin, e.g. to point a staging site at a self-hosted
		 * relay or at `wrangler dev`.
		 *
		 * @param string $url Relay origin.
		 */
		$url = (string) apply_filters( 'convertrack_gsc_relay_url', self::DEFAULT_URL );
		return untrailingslashit( esc_url_raw( trim( $url ) ) );
	}

	/**
	 * Whether a real relay is configured (the shipped placeholder is not one).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$url = self::url();
		return '' !== $url && false === strpos( $url, 'connect.example.com' );
	}

	/**
	 * The redirect URI registered on the relay's Google client. It is the
	 * `redirect_uri` for both the consent request and the token exchange.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return self::url() . '/v1/callback';
	}

	/**
	 * Whether this site holds a registration for the currently configured relay.
	 *
	 * @return bool
	 */
	public static function is_registered() {
		return Credentials::has_site_registration() && Credentials::relay_url() === self::url();
	}

	/**
	 * This site's canonical URL for the relay: the OAuth callback with its
	 * fixed tail removed, so sub-directory installs and the
	 * `convertrack_gsc_redirect_uri` filter are both honoured.
	 *
	 * @return string|\WP_Error
	 */
	public static function site_url() {
		$callback = (string) OAuth::redirect_uri();
		$suffix   = self::CALLBACK_SUFFIX;
		if ( strlen( $callback ) <= strlen( $suffix ) || substr( $callback, -strlen( $suffix ) ) !== $suffix ) {
			return new \WP_Error(
				'convertrack_gsc_relay_callback_mismatch',
				sprintf(
					/* translators: %s: the OAuth callback URL. */
					__( 'Sign in with Google needs the standard callback URL (ending in %s). Remove the convertrack_gsc_redirect_uri filter or use your own Google Cloud credentials.', 'convertrack-click-conversion-analytics' ),
					$suffix
				)
			);
		}
		return substr( $callback, 0, -strlen( $suffix ) );
	}

	/**
	 * The challenge the REST route publishes while a registration is in flight.
	 *
	 * @return string
	 */
	public static function pending_challenge() {
		$challenge = get_transient( self::CHALLENGE_TRANSIENT );
		return is_string( $challenge ) ? $challenge : '';
	}

	/**
	 * Register (or re-register) this site with the relay.
	 *
	 * The relay proves site control by fetching a challenge this site publishes
	 * for ten minutes on a public REST route, then issues a site_id and a
	 * site_secret. Registration is idempotent, so it is safe to repeat.
	 *
	 * @return true|\WP_Error
	 */
	public static function register_site() {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'convertrack_gsc_relay_not_configured', __( 'Sign in with Google is not available in this build: no sign-in service is configured. Use your own Google Cloud credentials instead.', 'convertrack-click-conversion-analytics' ) );
		}
		$site_url = self::site_url();
		if ( is_wp_error( $site_url ) ) {
			return $site_url;
		}

		$challenge = wp_generate_password( 48, false, false );
		set_transient( self::CHALLENGE_TRANSIENT, $challenge, self::CHALLENGE_TTL );

		$response = wp_remote_post(
			self::url() . '/v1/sites',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'Convertrack/' . CONVERTRACK_VERSION,
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'       => wp_json_encode(
					array(
						'site_url'      => $site_url,
						'challenge'     => $challenge,
						'challenge_url' => rest_url( \Convertrack\Rest_Controller::REST_NAMESPACE . '/gsc/relay-challenge' ),
					)
				),
			)
		);
		delete_transient( self::CHALLENGE_TRANSIENT );

		$result = self::parse_response( $response );
		if ( is_wp_error( $result ) ) {
			Logger::error( 'relay', 'Site registration with the sign-in service failed.', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		$data = $result['data'];
		if ( 200 !== $result['code'] || empty( $data['site_id'] ) || empty( $data['site_secret'] ) ) {
			$message = self::error_message( $data, __( 'The sign-in service did not accept this site.', 'convertrack-click-conversion-analytics' ) );
			Logger::error(
				'relay',
				'Site registration with the sign-in service was refused.',
				array(
					'status' => $result['code'],
					'error'  => $message,
				)
			);
			return new \WP_Error( 'convertrack_gsc_relay_registration_failed', $message, array( 'status' => $result['code'] ) );
		}

		$stored = Credentials::store_site_registration( $data['site_id'], $data['site_secret'], self::url() );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'relay', 'Site registered with the sign-in service.', array( 'site_url' => $site_url ) );
		return true;
	}

	/**
	 * Build the relay URL the admin's browser is sent to. The relay shows a
	 * short confirmation naming this site, then forwards to Google with the
	 * relay's client and this site's state and PKCE challenge.
	 *
	 * @param string $state          This site's anti-CSRF state.
	 * @param string $code_challenge This site's PKCE S256 challenge.
	 * @return string|\WP_Error
	 */
	public static function authorize_url( $state, $code_challenge ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'convertrack_gsc_relay_not_configured', __( 'Sign in with Google is not available in this build: no sign-in service is configured. Use your own Google Cloud credentials instead.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( ! Credentials::has_site_registration() ) {
			return new \WP_Error( 'convertrack_gsc_no_site_registration', __( 'This site is not registered with the sign-in service yet. Click Sign in with Google to register it.', 'convertrack-click-conversion-analytics' ) );
		}
		$site_url = self::site_url();
		if ( is_wp_error( $site_url ) ) {
			return $site_url;
		}

		$params = array(
			'site_id'        => Credentials::site_id(),
			'site_url'       => $site_url,
			'state'          => $state,
			'code_challenge' => $code_challenge,
			'scopes'         => OAuth::scopes(),
		);

		return self::url() . '/v1/authorize?' . http_build_query( $params, '', '&' );
	}

	/**
	 * POST a token request through the relay, which adds the Google client
	 * secret and forwards it to Google's token endpoint.
	 *
	 * Google's answer comes back verbatim as { code, data }. Anything the relay
	 * itself raises (bad site credentials, outage, Google unreachable) is a
	 * WP_Error, so callers can only ever see Google's `invalid_grant` when
	 * Google said it.
	 *
	 * @param array $params           grant_type plus code/code_verifier/redirect_uri or refresh_token.
	 * @param bool  $allow_reregister Register the site once more when the relay rejects its credentials.
	 * @return array|\WP_Error { code:int, data:array }
	 */
	public static function token( array $params, $allow_reregister = true ) {
		if ( ! self::is_registered() ) {
			if ( ! $allow_reregister ) {
				return new \WP_Error( 'convertrack_gsc_no_site_registration', __( 'This site is not registered with the sign-in service.', 'convertrack-click-conversion-analytics' ) );
			}
			$registered = self::register_site();
			if ( is_wp_error( $registered ) ) {
				return $registered;
			}
		}

		$body = array_merge(
			$params,
			array(
				'site_id'     => Credentials::site_id(),
				'site_secret' => Credentials::site_secret(),
			)
		);

		$response = wp_remote_post(
			self::url() . '/v1/token',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'Convertrack/' . CONVERTRACK_VERSION,
				'headers'    => array( 'Accept' => 'application/json' ),
				'body'       => $body,
			)
		);

		$result = self::parse_response( $response );
		if ( is_wp_error( $result ) ) {
			Logger::error( 'relay', 'Token request through the sign-in service failed.', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		$data  = $result['data'];
		$error = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
		if ( 'error' === $result['relay'] || 0 === strpos( $error, 'relay_' ) ) {
			// The relay no longer accepts this site's credentials (its signing
			// key was rotated, or the registration is stale): register once
			// more and retry, then give up without touching the Google tokens.
			if ( 'relay_invalid_site_credentials' === $error && $allow_reregister ) {
				Logger::warning( 'relay', 'The sign-in service rejected the site credentials; registering again.' );
				$registered = self::register_site();
				if ( is_wp_error( $registered ) ) {
					return $registered;
				}
				return self::token( $params, false );
			}
			$message = self::error_message( $data, __( 'The sign-in service returned an error.', 'convertrack-click-conversion-analytics' ) );
			Logger::error(
				'relay',
				'The sign-in service returned an error.',
				array(
					'status' => $result['code'],
					'error'  => $error,
				)
			);
			return new \WP_Error(
				'convertrack_gsc_relay_error',
				$message,
				array(
					'status'      => $result['code'],
					'relay_error' => $error,
				)
			);
		}

		return array(
			'code' => $result['code'],
			'data' => $data,
		);
	}

	/**
	 * Turn a wp_remote_* response into { code, data, relay } or a transport error.
	 *
	 * @param array|\WP_Error $response HTTP API response.
	 * @return array|\WP_Error
	 */
	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'convertrack_gsc_relay_unreachable',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'The sign-in service could not be reached: %s', 'convertrack-click-conversion-analytics' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'convertrack_gsc_relay_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The sign-in service returned an unexpected response (HTTP %d).', 'convertrack-click-conversion-analytics' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		return array(
			'code'  => $code,
			'data'  => $data,
			'relay' => strtolower( (string) wp_remote_retrieve_header( $response, 'x-convertrack-relay' ) ),
		);
	}

	/**
	 * Human-readable message from a relay or Google error body.
	 *
	 * @param array  $data     Decoded body.
	 * @param string $fallback Default message.
	 * @return string
	 */
	private static function error_message( array $data, $fallback ) {
		if ( ! empty( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
			return sanitize_text_field( $data['error_description'] );
		}
		if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
			return sanitize_text_field( $data['error'] );
		}
		return $fallback;
	}
}
