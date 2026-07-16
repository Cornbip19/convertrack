<?php
/**
 * Bounded, resumable and SSRF-resistant sitemap fetching.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Safe_Sitemap_Fetcher {

	const STATE_VERSION = 1;

	/**
	 * Create a serializable scan state.
	 *
	 * Each supplied root explicitly grants only its own normalized origin. Child
	 * sitemaps and redirects must stay on one of those origins unless a developer
	 * deliberately adds another origin through the documented filter.
	 *
	 * @param array $roots   Root sitemap URLs.
	 * @param array $options Limits and context.
	 * @return array|\WP_Error
	 */
	public static function start( array $roots, array $options = array() ) {
		$clean_roots = array();
		$origins     = array();
		foreach ( $roots as $root ) {
			$root = self::normalize_url( $root );
			if ( '' === $root ) {
				continue;
			}
			$origin = self::origin( $root );
			if ( '' === $origin ) {
				continue;
			}
			$clean_roots[ $root ] = true;
			$origins[ $origin ]   = true;
		}

		if ( empty( $clean_roots ) ) {
			return new \WP_Error( 'convertrack_sitemap_no_roots', __( 'No valid sitemap URL was supplied.', 'convertrack-click-conversion-analytics' ) );
		}

		$options = self::sanitize_options( $options );
		$extra   = (array) apply_filters( 'convertrack_safe_sitemap_allowed_origins', array(), array_keys( $clean_roots ), $options['context'] );
		foreach ( $extra as $origin ) {
			$origin = self::origin( (string) $origin );
			if ( '' !== $origin ) {
				$origins[ $origin ] = true;
			}
		}

		$queue = array();
		foreach ( array_keys( $clean_roots ) as $root ) {
			$queue[] = array( 'url' => $root, 'depth' => 0, 'root' => 1 );
		}

		return array(
			'version'            => self::STATE_VERSION,
			'status'             => 'queued',
			'context'            => $options['context'],
			'options'            => $options,
			'allowed_origins'    => array_keys( $origins ),
			'queue'              => $queue,
			'seen'               => array(),
			'processed_sitemaps' => 0,
			'urls_seen'          => 0,
			'active_seconds'     => 0.0,
			'partial'            => false,
			'truncated_reason'   => '',
			'errors'             => array(),
			'started_at'         => time(),
			'finished_at'        => 0,
		);
	}

	/**
	 * Execute one bounded step.
	 *
	 * URL entries are returned separately in url_batch and are never accumulated
	 * in the persisted state, allowing callers to stream them into their tables.
	 *
	 * @param array $state Persisted state from start() or a prior step.
	 * @return array|\WP_Error { state, url_batch }
	 */
	public static function step( array $state ) {
		if ( empty( $state['version'] ) || self::STATE_VERSION !== (int) $state['version'] ) {
			return new \WP_Error( 'convertrack_sitemap_bad_state', __( 'The saved sitemap scan state is invalid.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return array( 'state' => $state, 'url_batch' => array() );
		}

		$options  = self::sanitize_options( isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array() );
		$started  = microtime( true );
		$requests = 0;
		$batch    = array();
		$state['status'] = 'running';

		while ( ! empty( $state['queue'] ) ) {
			$elapsed = microtime( true ) - $started;
			if ( $requests >= $options['requests_per_step'] || $elapsed >= $options['step_seconds'] ) {
				break;
			}
			if ( (float) $state['active_seconds'] + $elapsed >= $options['total_seconds'] ) {
				$state['partial']          = true;
				$state['truncated_reason'] = 'wall_clock_budget';
				$state['queue']            = array();
				break;
			}

			$item = array_shift( $state['queue'] );
			$url  = isset( $item['url'] ) ? self::normalize_url( $item['url'] ) : '';
			$depth = isset( $item['depth'] ) ? (int) $item['depth'] : 0;
			if ( '' === $url ) {
				continue;
			}
			if ( $depth > $options['max_depth'] ) {
				self::add_error( $state, 'depth_limit', $url, __( 'Maximum sitemap depth exceeded.', 'convertrack-click-conversion-analytics' ) );
				continue;
			}

			$hash = md5( strtolower( $url ) );
			if ( isset( $state['seen'][ $hash ] ) ) {
				continue;
			}
			if ( (int) $state['processed_sitemaps'] >= $options['max_sitemaps'] ) {
				$state['partial']          = true;
				$state['truncated_reason'] = 'sitemap_count_limit';
				$state['queue']            = array();
				break;
			}

			$state['seen'][ $hash ] = true;
			$requests++;
			$fetched = self::fetch_and_parse( $url, (array) $state['allowed_origins'], $options );
			$state['processed_sitemaps'] = (int) $state['processed_sitemaps'] + 1;
			if ( is_wp_error( $fetched ) ) {
				self::add_error( $state, $fetched->get_error_code(), $url, $fetched->get_error_message() );
				continue;
			}

			foreach ( $fetched['children'] as $child ) {
				if ( ! self::reference_allowed( $child, (array) $state['allowed_origins'] ) ) {
					self::add_error( $state, 'child_origin_denied', $child, __( 'A child sitemap referenced an origin that is not allowed.', 'convertrack-click-conversion-analytics' ) );
					continue;
				}
				if ( count( $state['queue'] ) + (int) $state['processed_sitemaps'] >= $options['max_sitemaps'] ) {
					$state['partial']          = true;
					$state['truncated_reason'] = 'sitemap_count_limit';
					break;
				}
				$child_hash = md5( strtolower( $child ) );
				if ( ! isset( $state['seen'][ $child_hash ] ) ) {
					$state['queue'][] = array( 'url' => $child, 'depth' => $depth + 1, 'root' => 0 );
				}
			}

			foreach ( $fetched['urls'] as $page_url ) {
				// A fetched sitemap is untrusted input. Never stream an external or
				// credential-bearing page URL into indexing/redirect candidate tables
				// merely because the sitemap document itself came from an allowed host.
				if ( ! self::reference_allowed( $page_url, (array) $state['allowed_origins'] ) ) {
					self::add_error( $state, 'page_origin_denied', $page_url, __( 'A sitemap page URL used an origin that is not allowed.', 'convertrack-click-conversion-analytics' ) );
					continue;
				}
				if ( (int) $state['urls_seen'] >= $options['max_urls'] ) {
					$state['partial']          = true;
					$state['truncated_reason'] = 'url_count_limit';
					$state['queue']            = array();
					break;
				}
				$batch[] = array( 'url' => $page_url, 'sitemap_url' => $fetched['final_url'] );
				$state['urls_seen'] = (int) $state['urls_seen'] + 1;
			}
		}

		$state['active_seconds'] = round( (float) $state['active_seconds'] + ( microtime( true ) - $started ), 4 );
		if ( empty( $state['queue'] ) ) {
			if ( ! empty( $state['partial'] ) || ! empty( $state['errors'] ) ) {
				$state['status'] = ( 0 === (int) $state['processed_sitemaps'] || ( 0 === (int) $state['urls_seen'] && count( $state['errors'] ) >= (int) $state['processed_sitemaps'] ) ) ? 'failed' : 'partial';
			} else {
				$state['status'] = 'completed';
			}
			$state['finished_at'] = time();
		}

		return array( 'state' => $state, 'url_batch' => $batch );
	}

	/**
	 * Check a non-fetched sitemap/page reference against the explicit origin
	 * allowlist. Network destinations receive the stronger DNS/IP validation in
	 * validate_url() immediately before transport.
	 *
	 * @param string $url             Referenced URL.
	 * @param array  $allowed_origins Normalized allowed origins.
	 * @return bool
	 */
	private static function reference_allowed( $url, array $allowed_origins ) {
		$url   = self::normalize_url( $url );
		$parts = wp_parse_url( $url );
		return '' !== $url
			&& is_array( $parts )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& in_array( self::origin( $url ), $allowed_origins, true );
	}

	/**
	 * Validate one outbound URL against origin and IP policy.
	 *
	 * Public for focused regression tests and preflight validation.
	 *
	 * @param string $url             URL.
	 * @param array  $allowed_origins Normalized allowed origins.
	 * @param string $context         Calling module context.
	 * @return true|\WP_Error
	 */
	public static function validate_url( $url, array $allowed_origins, $context = '' ) {
		$url   = self::normalize_url( $url );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'convertrack_sitemap_invalid_url', __( 'The sitemap URL is invalid.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'convertrack_sitemap_userinfo', __( 'Sitemap URLs cannot contain credentials.', 'convertrack-click-conversion-analytics' ) );
		}

		$origin = self::origin( $url );
		if ( ! in_array( $origin, $allowed_origins, true ) ) {
			return new \WP_Error( 'convertrack_sitemap_origin_denied', __( 'The sitemap redirected to or referenced an origin that is not allowed.', 'convertrack-click-conversion-analytics' ) );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( 'localhost' === $host || preg_match( '/\.(?:localhost|local|internal)$/i', $host ) ) {
			return new \WP_Error( 'convertrack_sitemap_private_host', __( 'Private and local sitemap hosts are not allowed.', 'convertrack-click-conversion-analytics' ) );
		}

		$allow_private = (bool) apply_filters( 'convertrack_safe_sitemap_allow_private_origin', false, $url, $context );
		$ips           = self::resolve_ips( $host );
		if ( empty( $ips ) ) {
			return new \WP_Error( 'convertrack_sitemap_dns_failed', __( 'The sitemap host could not be resolved.', 'convertrack-click-conversion-analytics' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! $allow_private && ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'convertrack_sitemap_private_ip', __( 'The sitemap host resolves to a private, loopback, link-local, or reserved address.', 'convertrack-click-conversion-analytics' ) );
			}
		}

		if ( function_exists( 'wp_http_validate_url' ) && ! $allow_private && ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'convertrack_sitemap_unsafe_url', __( 'WordPress rejected the sitemap URL as unsafe.', 'convertrack-click-conversion-analytics' ) );
		}

		return true;
	}

	/**
	 * Fetch with manual redirect validation, bounded bytes, and safe XML parsing.
	 *
	 * @param string $url             URL.
	 * @param array  $allowed_origins Allowed origins.
	 * @param array  $options         Limits.
	 * @return array|\WP_Error
	 */
	private static function fetch_and_parse( $url, array $allowed_origins, array $options ) {
		$current = $url;
		for ( $redirects = 0; $redirects <= $options['max_redirects']; $redirects++ ) {
			$valid = self::validate_url( $current, $allowed_origins, $options['context'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => $options['request_timeout'],
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => $options['max_compressed_bytes'] + 1,
					'decompress'          => false,
					'user-agent'          => $options['user_agent'],
					'headers'             => array( 'Accept' => 'application/xml,text/xml,application/gzip;q=0.8' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( '' === (string) $location ) {
					return new \WP_Error( 'convertrack_sitemap_redirect_missing', __( 'The sitemap redirect did not include a destination.', 'convertrack-click-conversion-analytics' ) );
				}
				if ( $redirects >= $options['max_redirects'] ) {
					return new \WP_Error( 'convertrack_sitemap_redirect_limit', __( 'The sitemap exceeded the redirect limit.', 'convertrack-click-conversion-analytics' ) );
				}
				$current = self::absolute_url( (string) $location, $current );
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error( 'convertrack_sitemap_http', sprintf( /* translators: %d: HTTP status. */ __( 'Sitemap returned HTTP %d.', 'convertrack-click-conversion-analytics' ), $code ) );
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) > $options['max_compressed_bytes'] ) {
				return new \WP_Error( 'convertrack_sitemap_compressed_too_large', __( 'The sitemap response exceeded the compressed byte limit.', 'convertrack-click-conversion-analytics' ) );
			}

			$encoding = strtolower( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) );
			if ( false !== strpos( $encoding, 'gzip' ) || 0 === strpos( $body, "\x1f\x8b" ) || preg_match( '/\.gz(?:$|\?)/i', $current ) ) {
				if ( ! function_exists( 'gzdecode' ) ) {
					return new \WP_Error( 'convertrack_sitemap_gzip_unavailable', __( 'This sitemap is compressed, but gzip support is unavailable.', 'convertrack-click-conversion-analytics' ) );
				}
				$decoded = @gzdecode( $body, $options['max_decompressed_bytes'] + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $decoded ) {
					return new \WP_Error( 'convertrack_sitemap_bad_gzip', __( 'The compressed sitemap could not be decoded.', 'convertrack-click-conversion-analytics' ) );
				}
				$body = $decoded;
			}

			if ( strlen( $body ) > $options['max_decompressed_bytes'] ) {
				return new \WP_Error( 'convertrack_sitemap_decompressed_too_large', __( 'The sitemap exceeded the decompressed byte limit.', 'convertrack-click-conversion-analytics' ) );
			}

			$parsed = self::parse_xml( $body );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$parsed['final_url'] = $current;
			return $parsed;
		}

		return new \WP_Error( 'convertrack_sitemap_redirect_limit', __( 'The sitemap exceeded the redirect limit.', 'convertrack-click-conversion-analytics' ) );
	}

	/**
	 * Parse bounded XML without network/entity access.
	 *
	 * @param string $body XML.
	 * @return array|\WP_Error
	 */
	private static function parse_xml( $body ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new \WP_Error( 'convertrack_sitemap_simplexml_missing', __( 'SimpleXML is required to parse sitemaps.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( preg_match( '/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body ) ) {
			return new \WP_Error( 'convertrack_sitemap_entities_denied', __( 'Sitemaps containing document type or entity declarations are not allowed.', 'convertrack-click-conversion-analytics' ) );
		}

		$previous_errors = libxml_use_internal_errors( true );
		$previous_entity = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$previous_entity = libxml_disable_entity_loader( true );
		}
		$flags = LIBXML_NONET | LIBXML_NOCDATA;
		if ( defined( 'LIBXML_COMPACT' ) ) {
			$flags |= LIBXML_COMPACT;
		}
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', $flags );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		if ( null !== $previous_entity ) {
			libxml_disable_entity_loader( $previous_entity );
		}
		if ( false === $xml ) {
			return new \WP_Error( 'convertrack_sitemap_bad_xml', __( 'The sitemap XML could not be parsed.', 'convertrack-click-conversion-analytics' ) );
		}

		return array(
			'children' => self::xpath_text( $xml, '//*[local-name()="sitemap"]/*[local-name()="loc"]' ),
			'urls'     => self::xpath_text( $xml, '//*[local-name()="url"]/*[local-name()="loc"]' ),
		);
	}

	/**
	 * Extract unique text nodes with a per-document safety cap.
	 *
	 * @param \SimpleXMLElement $xml   XML.
	 * @param string            $query XPath.
	 * @return array
	 */
	private static function xpath_text( $xml, $query ) {
		$out = array();
		foreach ( (array) $xml->xpath( $query ) as $item ) {
			$value = self::normalize_url( trim( (string) $item ) );
			if ( '' !== $value ) {
				$out[ $value ] = true;
			}
			if ( count( $out ) >= 50000 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Normalize safe fetch URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return '';
		}
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $url || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return preg_replace( '/#.*$/', '', $url );
	}

	/**
	 * Normalize scheme, host, and effective port.
	 *
	 * @param string $url URL (or origin URL).
	 * @return string
	 */
	private static function origin( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Resolve a redirect location against its current URL.
	 *
	 * @param string $location Location header.
	 * @param string $base     Current URL.
	 * @return string
	 */
	private static function absolute_url( $location, $base ) {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return self::normalize_url( $location );
		}
		if ( class_exists( '\\WP_Http' ) && method_exists( '\\WP_Http', 'make_absolute_url' ) ) {
			return self::normalize_url( \WP_Http::make_absolute_url( $location, $base ) );
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		if ( 0 === strpos( $location, '/' ) ) {
			return self::normalize_url( $origin . $location );
		}
		$path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
		return self::normalize_url( trailingslashit( $origin . '/' . trim( $path, '/' ) ) . $location );
	}

	/**
	 * Resolve all available A and AAAA addresses.
	 *
	 * @param string $host Host.
	 * @return array
	 */
	private static function resolve_ips( $host ) {
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return array( trim( $host, '[]' ) );
		}

		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$resolved = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ips      = is_array( $resolved ) ? $resolved : array();
		}
		return array_values( array_unique( array_filter( $ips ) ) );
	}

	/**
	 * Reject every private, reserved, loopback, link-local and metadata range.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Clamp all persisted limits.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	private static function sanitize_options( array $options ) {
		$defaults = array(
			'context'                => 'sitemap',
			'max_sitemaps'           => 200,
			'max_depth'              => 4,
			'max_urls'               => 25000,
			'requests_per_step'      => 5,
			'request_timeout'        => 8,
			'step_seconds'           => 12,
			'total_seconds'          => 180,
			'max_redirects'          => 3,
			'max_compressed_bytes'   => 2 * MB_IN_BYTES,
			'max_decompressed_bytes' => 8 * MB_IN_BYTES,
			'user_agent'             => 'Convertrack/' . ( defined( 'CONVERTRACK_VERSION' ) ? CONVERTRACK_VERSION : 'unknown' ) . ' safe-sitemap',
		);
		$options = wp_parse_args( $options, $defaults );
		return array(
			'context'                => sanitize_key( $options['context'] ),
			'max_sitemaps'           => max( 1, min( 500, (int) $options['max_sitemaps'] ) ),
			'max_depth'              => max( 0, min( 8, (int) $options['max_depth'] ) ),
			'max_urls'               => max( 1, min( 100000, (int) $options['max_urls'] ) ),
			'requests_per_step'      => max( 1, min( 20, (int) $options['requests_per_step'] ) ),
			'request_timeout'        => max( 2, min( 15, (int) $options['request_timeout'] ) ),
			'step_seconds'           => max( 2, min( 30, (int) $options['step_seconds'] ) ),
			'total_seconds'          => max( 10, min( 600, (int) $options['total_seconds'] ) ),
			'max_redirects'          => max( 0, min( 5, (int) $options['max_redirects'] ) ),
			'max_compressed_bytes'   => max( 65536, min( 10 * MB_IN_BYTES, (int) $options['max_compressed_bytes'] ) ),
			'max_decompressed_bytes' => max( 262144, min( 25 * MB_IN_BYTES, (int) $options['max_decompressed_bytes'] ) ),
			'user_agent'             => substr( sanitize_text_field( $options['user_agent'] ), 0, 191 ),
		);
	}

	/**
	 * Record a bounded diagnostic and mark the run partial.
	 *
	 * @param array  $state   State by reference.
	 * @param string $code    Error code.
	 * @param string $url     URL.
	 * @param string $message Message.
	 */
	private static function add_error( array &$state, $code, $url, $message ) {
		$state['partial'] = true;
		if ( count( $state['errors'] ) >= 50 ) {
			return;
		}
		$state['errors'][] = array(
			'code'    => sanitize_key( $code ),
			'url'     => esc_url_raw( $url ),
			'message' => substr( sanitize_text_field( $message ), 0, 500 ),
		);
	}
}
