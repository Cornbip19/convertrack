<?php
/**
 * Sitemap scanner for the Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Sitemap_Scanner {

	const MAX_SITEMAPS = 200;
	const MAX_DEPTH    = 4;

	/**
	 * Scan configured sitemap and selected post types.
	 *
	 * @return array|\WP_Error
	 */
	public static function scan() {
		if ( ! Settings::get( 'enabled' ) ) {
			return new \WP_Error( 'convertrack_gsc_disabled', __( 'Google Index Monitor is disabled.', 'convertrack-click-conversion-analytics' ) );
		}

		$root = Settings::get( 'sitemap_url' );
		if ( '' === $root ) {
			return new \WP_Error( 'convertrack_gsc_no_sitemap', __( 'Sitemap URL is missing.', 'convertrack-click-conversion-analytics' ) );
		}

		$seen_sitemaps = array();
		$urls          = self::scan_sitemap( $root, 0, $seen_sitemaps );
		if ( is_wp_error( $urls ) ) {
			Logger::error( 'sitemap', 'Sitemap scan failed.', array( 'error' => $urls->get_error_message() ) );
			return $urls;
		}

		$stored = 0;
		foreach ( $urls as $url => $meta ) {
			$match = self::match_post( $url );
			$id = Database::upsert_url(
				$url,
				array(
					'post_id'       => $match['post_id'],
					'post_type'     => $match['post_type'],
					'sitemap_url'   => $meta['sitemap_url'],
					'in_sitemap'    => 1,
					'index_status'  => 'pending_from_sitemap',
					'preserve_status' => 1,
				)
			);
			if ( $id ) {
				$stored++;
			}
		}

		$posts_added = self::queue_selected_posts();

		Logger::info(
			'sitemap',
			'Sitemap scan completed.',
			array(
				'sitemap_urls' => count( $urls ),
				'stored'       => $stored,
				'post_urls'    => $posts_added,
			)
		);

		return array(
			'sitemap_urls' => count( $urls ),
			'stored'       => $stored,
			'post_urls'    => $posts_added,
		);
	}

	/**
	 * Recursively scan a sitemap index or URL set.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @param int    $depth       Depth.
	 * @param array  $seen        Seen sitemaps.
	 * @return array|\WP_Error
	 */
	private static function scan_sitemap( $sitemap_url, $depth, array &$seen ) {
		$sitemap_url = esc_url_raw( $sitemap_url );
		if ( '' === $sitemap_url ) {
			return array();
		}
		if ( $depth > self::MAX_DEPTH || count( $seen ) >= self::MAX_SITEMAPS || isset( $seen[ $sitemap_url ] ) ) {
			return array();
		}

		$seen[ $sitemap_url ] = true;
		$response = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'user-agent'  => 'Convertrack/' . CONVERTRACK_VERSION . ' sitemap-scanner',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'convertrack_gsc_sitemap_http', sprintf( 'Sitemap returned HTTP %d.', $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( self::looks_gzipped( $sitemap_url, $body ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $body );
			if ( false !== $decoded ) {
				$body = $decoded;
			}
		}

		$xml = self::parse_xml( $body );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$out = array();
		$child_sitemaps = self::xpath_text( $xml, '//*[local-name()="sitemap"]/*[local-name()="loc"]' );
		if ( ! empty( $child_sitemaps ) ) {
			foreach ( $child_sitemaps as $child ) {
				$child_urls = self::scan_sitemap( $child, $depth + 1, $seen );
				if ( is_wp_error( $child_urls ) ) {
					Logger::warning( 'sitemap', 'Child sitemap scan failed.', array( 'sitemap' => $child, 'error' => $child_urls->get_error_message() ) );
					continue;
				}
				$out = array_merge( $out, $child_urls );
			}
			return $out;
		}

		foreach ( self::xpath_text( $xml, '//*[local-name()="url"]/*[local-name()="loc"]' ) as $url ) {
			$url = Database::normalize_url( $url );
			if ( '' !== $url && self::is_site_url( $url ) ) {
				$out[ $url ] = array( 'sitemap_url' => $sitemap_url );
			}
		}

		return $out;
	}

	/**
	 * Queue selected published posts, including URLs missing from the sitemap.
	 *
	 * @return int
	 */
	private static function queue_selected_posts() {
		$post_types = Settings::get( 'selected_post_types', array() );
		if ( empty( $post_types ) ) {
			return 0;
		}

		$page   = 1;
		$stored = 0;

		do {
			$query = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => 500,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query->posts as $post_id ) {
				$url = get_permalink( $post_id );
				if ( ! $url ) {
					continue;
				}
				$id = Database::upsert_url(
					$url,
					array(
						'post_id'       => (int) $post_id,
						'post_type'     => get_post_type( $post_id ),
						'in_sitemap'    => 0,
						'index_status'  => 'queued',
						'preserve_status' => 1,
					)
				);
				if ( $id ) {
					$stored++;
				}
			}

			$page++;
		} while ( ! empty( $query->posts ) && count( $query->posts ) >= 500 );

		return $stored;
	}

	/**
	 * Match a URL to a selected WordPress post.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private static function match_post( $url ) {
		$post_id   = url_to_postid( $url );
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		if ( $post_id && ! in_array( $post_type, Settings::get( 'selected_post_types', array() ), true ) ) {
			$post_id   = 0;
			$post_type = '';
		}

		return array(
			'post_id'   => (int) $post_id,
			'post_type' => $post_type ? sanitize_key( $post_type ) : '',
		);
	}

	/**
	 * Parse XML safely.
	 *
	 * @param string $body XML body.
	 * @return \SimpleXMLElement|\WP_Error
	 */
	private static function parse_xml( $body ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new \WP_Error( 'convertrack_gsc_simplexml_missing', __( 'SimpleXML is required to parse sitemaps.', 'convertrack-click-conversion-analytics' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new \WP_Error( 'convertrack_gsc_bad_sitemap_xml', __( 'The sitemap XML could not be parsed.', 'convertrack-click-conversion-analytics' ) );
		}

		return $xml;
	}

	/**
	 * Extract XPath text values.
	 *
	 * @param \SimpleXMLElement $xml   XML.
	 * @param string            $query XPath query.
	 * @return array
	 */
	private static function xpath_text( $xml, $query ) {
		$items = $xml->xpath( $query );
		$out   = array();

		foreach ( (array) $items as $item ) {
			$value = trim( (string) $item );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Check whether a URL belongs to the current site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		$home = wp_parse_url( home_url() );
		$test = wp_parse_url( $url );

		if ( empty( $home['host'] ) || empty( $test['host'] ) ) {
			return false;
		}

		return strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) === strtolower( preg_replace( '/^www\./', '', $test['host'] ) );
	}

	/**
	 * Whether the sitemap response appears gzipped.
	 *
	 * @param string $url  URL.
	 * @param string $body Body.
	 * @return bool
	 */
	private static function looks_gzipped( $url, $body ) {
		return preg_match( '/\.gz($|\?)/i', $url ) || 0 === strpos( $body, "\x1f\x8b" );
	}
}
