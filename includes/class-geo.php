<?php
/**
 * Visitor geolocation: resolve a request's country (2-letter ISO code).
 *
 * This is the ONLY part of Convertrack that may contact a third-party service,
 * and it is OFF by default. When enabled it sends the visitor's IP address to a
 * geolocation API to obtain the country only; neither the IP nor the raw API
 * response is ever stored. Results are cached per IP so the lookup runs rarely.
 *
 * Resolution order: a CDN-provided country header (free, instant) first, then
 * the configured API. Private/reserved IPs (e.g. local development) resolve to
 * an empty string so nothing is recorded.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Geo {

	/**
	 * Per-request memoized country code.
	 *
	 * @var string|null
	 */
	private static $current = null;

	/**
	 * Whether visitor location collection is enabled in settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'enable_geo' );
	}

	/**
	 * Country code for the current request ('' when disabled or unresolved).
	 *
	 * @return string
	 */
	public static function current_country() {
		if ( ! self::enabled() ) {
			return '';
		}
		if ( null !== self::$current ) {
			return self::$current;
		}

		self::$current = self::country_for_ip( self::client_ip() );
		return self::$current;
	}

	/**
	 * Best-effort client IP from the request (used transiently, never stored).
	 *
	 * @return string
	 */
	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Resolve a country code for an IP, using a CDN header, cache, then the API.
	 *
	 * @param string $ip Client IP.
	 * @return string Two-letter uppercase code, or ''.
	 */
	public static function country_for_ip( $ip ) {
		// 1. CDN/host country header — free and instant when present.
		$header = self::header_country();
		if ( '' !== $header ) {
			return $header;
		}

		// 2. Skip private / reserved / invalid addresses (e.g. local dev).
		if ( '' === $ip || ! self::is_public_ip( $ip ) ) {
			return '';
		}

		// 3. Cache per IP so the external lookup runs at most once per window.
		$key    = 'convertrack_geo_' . substr( wp_hash( $ip ), 0, 16 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return is_string( $cached ) ? $cached : '';
		}

		$country = self::lookup( $ip );

		// Cache hits for 6h; cache misses briefly so a flaky API is retried soon
		// without hammering it on every request.
		set_transient( $key, $country, '' !== $country ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $country;
	}

	/**
	 * Read a country code from a CDN/edge header if the host sets one.
	 *
	 * Only well-known infrastructure headers are trusted by default, because a
	 * request header is otherwise attacker-controlled: on a site NOT behind an
	 * edge that sets/overwrites these, a visitor could spoof their country. These
	 * defaults (Cloudflare, mod_geoip/nginx) are normally set and overwritten at
	 * the edge. Use the filter to add a header only if your edge enforces it.
	 *
	 * @return string
	 */
	private static function header_country() {
		/**
		 * Filter the list of trusted edge country headers ($_SERVER keys).
		 *
		 * @param array $headers Trusted header keys.
		 */
		$headers = apply_filters( 'convertrack_geo_trusted_headers', array( 'HTTP_CF_IPCOUNTRY', 'HTTP_GEOIP_COUNTRY_CODE' ) );
		foreach ( (array) $headers as $h ) {
			if ( empty( $_SERVER[ $h ] ) ) {
				continue;
			}
			$code = self::normalize_code( wp_unslash( $_SERVER[ $h ] ) );
			// Cloudflare uses XX/T1 for unknown/Tor; treat those as unresolved.
			if ( '' !== $code && 'XX' !== $code && 'T1' !== $code ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * True for a routable public IP (rejects private and reserved ranges).
	 *
	 * @param string $ip Candidate IP.
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Query the geolocation API for a country code.
	 *
	 * The endpoint is filterable so a site can point at a different provider or
	 * a self-hosted service. It must return JSON containing a country code under
	 * one of: countryCode, country_code, country.
	 *
	 * @param string $ip Public client IP.
	 * @return string
	 */
	private static function lookup( $ip ) {
		/**
		 * Filter the geolocation lookup URL. {ip} placeholders are not used; the
		 * IP is appended/encoded here. Return '' to disable API lookups entirely
		 * (relying only on CDN headers).
		 *
		 * @param string $endpoint Lookup URL.
		 * @param string $ip       Client IP.
		 */
		$endpoint = apply_filters(
			'convertrack_geo_endpoint',
			'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,countryCode',
			$ip
		);

		if ( '' === $endpoint ) {
			return '';
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 2,
				'redirection' => 1,
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'Convertrack/' . ( defined( 'CONVERTRACK_VERSION' ) ? CONVERTRACK_VERSION : '1.0' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return '';
		}

		foreach ( array( 'countryCode', 'country_code', 'country' ) as $field ) {
			if ( ! empty( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
				$code = self::normalize_code( $body[ $field ] );
				if ( '' !== $code ) {
					return $code;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize an arbitrary value to a 2-letter uppercase ISO code or ''.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_code( $value ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		return 2 === strlen( $code ) ? $code : '';
	}
}
