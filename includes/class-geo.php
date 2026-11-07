<?php
/**
 * Visitor geolocation: resolve a request's country, and optionally its
 * state/region, city and network (internet provider and connection type).
 *
 * This is the ONLY part of Convertrack that may contact a third-party service,
 * and it is OFF by default. When enabled it sends the visitor's IP address to a
 * geolocation API; neither the IP nor the raw API response is ever stored.
 * Results are cached per IP so the lookup runs rarely.
 *
 * Two levels, both opt-in:
 * - "enable_geo" keeps the two-letter country only (the original behavior).
 * - "geo_detail" also keeps the region code, city, internet provider name, AS
 *   number and a coarse connection type (isp, mobile, hosting, proxy). These
 *   describe the network a visitor is on, never the visitor, and exist so a
 *   site owner can tell real local customers from datacenter bots.
 *
 * Resolution order: CDN/edge headers (free, instant) first, then the configured
 * API. Private/reserved IPs (e.g. local development) resolve to nothing.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Geo {

	/** Connection types a profile can carry. */
	const NET_ISP     = 'isp';
	const NET_MOBILE  = 'mobile';
	const NET_HOSTING = 'hosting';
	const NET_PROXY   = 'proxy';

	/**
	 * Per-request memoized profile.
	 *
	 * @var array|null
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
	 * Whether region, city and network detail is collected as well.
	 *
	 * @return bool
	 */
	public static function detail_enabled() {
		return self::enabled() && (bool) Settings::get( 'geo_detail' );
	}

	/**
	 * An empty profile: every field present, nothing resolved.
	 *
	 * @return array
	 */
	public static function empty_profile() {
		return array(
			'country'  => '',
			'region'   => '',
			'city'     => '',
			'network'  => '',
			'asn'      => '',
			'net_type' => '',
		);
	}

	/**
	 * Country code for the current request ('' when disabled or unresolved).
	 *
	 * @return string
	 */
	public static function current_country() {
		$profile = self::current_profile();
		return $profile['country'];
	}

	/**
	 * Location/network profile for the current request.
	 *
	 * Fields beyond the country are empty unless "geo_detail" is enabled.
	 *
	 * @return array { country, region, city, network, asn, net_type }
	 */
	public static function current_profile() {
		if ( ! self::enabled() ) {
			return self::empty_profile();
		}
		if ( null !== self::$current ) {
			return self::$current;
		}

		self::$current = self::profile_for_ip( self::client_ip(), self::detail_enabled() );
		return self::$current;
	}

	/**
	 * Forget the memoized profile (tests and long-running workers).
	 */
	public static function reset() {
		self::$current = null;
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
	 * Resolve a country code for an IP (kept for backwards compatibility).
	 *
	 * @param string $ip Client IP.
	 * @return string Two-letter uppercase code, or ''.
	 */
	public static function country_for_ip( $ip ) {
		$profile = self::profile_for_ip( $ip, false );
		return $profile['country'];
	}

	/**
	 * Resolve a profile for an IP, using edge headers, cache, then the API.
	 *
	 * @param string $ip     Client IP.
	 * @param bool   $detail Whether region/city/network are wanted.
	 * @return array
	 */
	public static function profile_for_ip( $ip, $detail ) {
		$profile = self::empty_profile();

		// 1. CDN/host headers — free and instant when present.
		$header = self::header_profile();
		if ( '' !== $header['country'] ) {
			$profile = array_merge( $profile, $detail ? $header : array( 'country' => $header['country'] ) );
			// Country-only mode never needs the API when the edge supplied it.
			// Detail mode still asks the API for the network, which no common
			// edge header carries.
			if ( ! $detail ) {
				return $profile;
			}
		}

		// 2. Skip private / reserved / invalid addresses (e.g. local dev).
		if ( '' === $ip || ! self::is_public_ip( $ip ) ) {
			return $profile;
		}

		// 3. Cache per IP and mode so the external lookup runs at most once per window.
		$key    = ( $detail ? 'convertrack_geo2_' : 'convertrack_geo_' ) . substr( wp_hash( $ip ), 0, 16 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			$looked_up = self::coerce_cached( $cached );
		} else {
			$looked_up = self::lookup( $ip, $detail );
			// Cache hits for 6h; cache misses briefly so a flaky API is retried
			// soon without hammering it on every request.
			set_transient( $key, $looked_up, '' !== $looked_up['country'] ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}

		// Edge values win where present; the API fills the gaps.
		foreach ( $looked_up as $field => $value ) {
			if ( '' === $profile[ $field ] && ( $detail || 'country' === $field ) ) {
				$profile[ $field ] = $value;
			}
		}
		return $profile;
	}

	/**
	 * Accept both the legacy cached string (country only) and the array form.
	 *
	 * @param mixed $cached Transient value.
	 * @return array
	 */
	private static function coerce_cached( $cached ) {
		if ( is_string( $cached ) ) {
			$profile            = self::empty_profile();
			$profile['country'] = self::normalize_code( $cached );
			return $profile;
		}
		return is_array( $cached ) ? self::sanitize_profile( $cached ) : self::empty_profile();
	}

	/**
	 * Read location values from CDN/edge headers if the host sets them.
	 *
	 * Only well-known infrastructure headers are trusted by default, because a
	 * request header is otherwise attacker-controlled: on a site NOT behind an
	 * edge that sets/overwrites these, a visitor could spoof their location.
	 * These defaults (Cloudflare, mod_geoip/nginx) are normally set and
	 * overwritten at the edge. Cloudflare sends region and city only when the
	 * "Add visitor location headers" managed transform is on.
	 *
	 * @return array Profile with country/region/city (network fields empty).
	 */
	private static function header_profile() {
		$profile = self::empty_profile();

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
				$profile['country'] = $code;
				break;
			}
		}
		if ( '' === $profile['country'] ) {
			return $profile;
		}

		/**
		 * Filter the trusted edge region and city headers ($_SERVER keys).
		 *
		 * @param array $headers { region: string[], city: string[] }.
		 */
		$detail = apply_filters(
			'convertrack_geo_trusted_detail_headers',
			array(
				'region' => array( 'HTTP_CF_REGION_CODE', 'HTTP_GEOIP_REGION' ),
				'city'   => array( 'HTTP_CF_IPCITY', 'HTTP_GEOIP_CITY' ),
			)
		);
		foreach ( array( 'region', 'city' ) as $field ) {
			foreach ( isset( $detail[ $field ] ) ? (array) $detail[ $field ] : array() as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$value = 'region' === $field ? self::normalize_region( wp_unslash( $_SERVER[ $h ] ) ) : self::normalize_city( wp_unslash( $_SERVER[ $h ] ) );
					if ( '' !== $value ) {
						$profile[ $field ] = $value;
						break;
					}
				}
			}
		}
		return $profile;
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
	 * Query the geolocation API.
	 *
	 * The endpoint is filterable so a site can point at a different provider or
	 * a self-hosted service. It must return JSON with a country code under one
	 * of countryCode, country_code, country. Detail mode also reads region
	 * (region, region_code), city, isp/org, as/asn and the boolean flags
	 * mobile, proxy and hosting when the provider supplies them.
	 *
	 * @param string $ip     Public client IP.
	 * @param bool   $detail Whether region/city/network are wanted.
	 * @return array Profile.
	 */
	private static function lookup( $ip, $detail ) {
		$fields = $detail ? 'status,countryCode,region,city,isp,org,as,mobile,proxy,hosting' : 'status,countryCode';

		/**
		 * Filter the geolocation lookup URL. Return '' to disable API lookups
		 * entirely (relying only on CDN headers).
		 *
		 * @param string $endpoint Lookup URL.
		 * @param string $ip       Client IP.
		 * @param bool   $detail   Whether region/city/network fields are wanted.
		 */
		$endpoint = apply_filters(
			'convertrack_geo_endpoint',
			'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=' . $fields,
			$ip,
			$detail
		);

		if ( '' === $endpoint ) {
			return self::empty_profile();
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
			return self::empty_profile();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return self::empty_profile();
		}

		$profile = self::parse_api_body( $body );
		if ( ! $detail ) {
			$only            = self::empty_profile();
			$only['country'] = $profile['country'];
			return $only;
		}
		return $profile;
	}

	/**
	 * Map a provider response onto a sanitized profile.
	 *
	 * @param array $body Decoded JSON.
	 * @return array
	 */
	public static function parse_api_body( array $body ) {
		$profile = self::empty_profile();

		foreach ( array( 'countryCode', 'country_code', 'country' ) as $field ) {
			if ( ! empty( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
				$code = self::normalize_code( $body[ $field ] );
				if ( '' !== $code ) {
					$profile['country'] = $code;
					break;
				}
			}
		}
		if ( '' === $profile['country'] ) {
			return $profile;
		}

		foreach ( array( 'region', 'region_code' ) as $field ) {
			if ( ! empty( $body[ $field ] ) && is_scalar( $body[ $field ] ) ) {
				$profile['region'] = self::normalize_region( $body[ $field ] );
				if ( '' !== $profile['region'] ) {
					break;
				}
			}
		}
		if ( ! empty( $body['city'] ) && is_scalar( $body['city'] ) ) {
			$profile['city'] = self::normalize_city( $body['city'] );
		}
		foreach ( array( 'isp', 'org' ) as $field ) {
			if ( ! empty( $body[ $field ] ) && is_scalar( $body[ $field ] ) ) {
				$profile['network'] = self::normalize_network( $body[ $field ] );
				if ( '' !== $profile['network'] ) {
					break;
				}
			}
		}
		foreach ( array( 'as', 'asn' ) as $field ) {
			if ( ! empty( $body[ $field ] ) && is_scalar( $body[ $field ] ) ) {
				$profile['asn'] = self::normalize_asn( $body[ $field ] );
				if ( '' !== $profile['asn'] ) {
					break;
				}
			}
		}

		// Proxy beats hosting beats mobile: a VPN exit in a datacenter is a
		// proxy first, and a phone tethered through a VPN is not "mobile".
		if ( ! empty( $body['proxy'] ) ) {
			$profile['net_type'] = self::NET_PROXY;
		} elseif ( ! empty( $body['hosting'] ) ) {
			$profile['net_type'] = self::NET_HOSTING;
		} elseif ( ! empty( $body['mobile'] ) ) {
			$profile['net_type'] = self::NET_MOBILE;
		} elseif ( array_key_exists( 'hosting', $body ) || array_key_exists( 'mobile', $body ) || array_key_exists( 'proxy', $body ) ) {
			$profile['net_type'] = self::NET_ISP;
		}

		return $profile;
	}

	/**
	 * Re-sanitize a stored profile array.
	 *
	 * @param array $raw Profile-shaped array.
	 * @return array
	 */
	public static function sanitize_profile( array $raw ) {
		$profile             = self::empty_profile();
		$profile['country']  = isset( $raw['country'] ) ? self::normalize_code( $raw['country'] ) : '';
		$profile['region']   = isset( $raw['region'] ) ? self::normalize_region( $raw['region'] ) : '';
		$profile['city']     = isset( $raw['city'] ) ? self::normalize_city( $raw['city'] ) : '';
		$profile['network']  = isset( $raw['network'] ) ? self::normalize_network( $raw['network'] ) : '';
		$profile['asn']      = isset( $raw['asn'] ) ? self::normalize_asn( $raw['asn'] ) : '';
		$profile['net_type'] = isset( $raw['net_type'] ) && in_array( $raw['net_type'], array( self::NET_ISP, self::NET_MOBILE, self::NET_HOSTING, self::NET_PROXY ), true ) ? $raw['net_type'] : '';
		return $profile;
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

	/**
	 * Region/state code: 1-3 uppercase letters or digits ("FL", "ENG", "13").
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_region( $value ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
		return ( strlen( $code ) >= 1 && strlen( $code ) <= 3 ) ? $code : '';
	}

	/**
	 * City name: plain text, at most 80 characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_city( $value ) {
		$city = trim( preg_replace( '/[^\p{L}\p{M}0-9 .\'\-]/u', '', sanitize_text_field( rawurldecode( (string) $value ) ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $city, 0, 80 ) : substr( $city, 0, 80 );
	}

	/**
	 * Internet provider / organization name: plain text, at most 100 characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_network( $value ) {
		$name = trim( sanitize_text_field( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
	}

	/**
	 * Autonomous system number as "AS1234" (accepts "AS1234 Name" or "1234").
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_asn( $value ) {
		if ( preg_match( '/^\s*(?:AS)?(\d{1,10})\b/i', (string) $value, $m ) ) {
			return 'AS' . $m[1];
		}
		return '';
	}
}
