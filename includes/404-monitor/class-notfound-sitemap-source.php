<?php
/**
 * Valid URL candidate refresh for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Sitemap_Source {

	const MAX_SITEMAPS = 100;
	const MAX_DEPTH    = 3;
	const MAX_URLS     = 5000;

	/**
	 * Refresh valid URL cache.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh() {
		$started = current_time( 'mysql' );
		$stored  = 0;
		$errors  = 0;

		foreach ( self::default_sitemaps() as $sitemap ) {
			$seen = array();
			$urls = self::scan_sitemap( $sitemap, 0, $seen );
			if ( is_wp_error( $urls ) ) {
				$errors++;
				Logger::warning( 'sitemap', 'Sitemap scan failed.', array( 'sitemap' => $sitemap, 'error' => $urls->get_error_message() ) );
				continue;
			}
			foreach ( $urls as $url ) {
				if ( Database::upsert_valid_url( $url, array( 'source' => 'sitemap', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 80 ) ) ) {
					$stored++;
				}
			}
			if ( $stored >= self::MAX_URLS ) {
				break;
			}
		}

		$stored += self::refresh_wordpress_objects();
		Database::mark_valid_urls_stale( $started );
		update_option( 'convertrack_404_last_sitemap_refresh', current_time( 'mysql' ), false );
		Logger::info( 'sitemap', '404 Monitor valid URL cache refreshed.', array( 'stored' => $stored, 'errors' => $errors ) );

		return array(
			'stored' => $stored,
			'errors' => $errors,
			'total'  => Database::valid_url_count(),
		);
	}

	/**
	 * Default sitemap URLs.
	 *
	 * @return array
	 */
	private static function default_sitemaps() {
		$urls = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
		);
		foreach ( Settings::lines_to_array( Settings::get( 'sitemap_urls' ) ) as $url ) {
			$urls[] = $url;
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/**
	 * Refresh candidates from WordPress content, archives and terms.
	 *
	 * @return int Stored candidates.
	 */
	private static function refresh_wordpress_objects() {
		$stored    = 0;
		$excluded  = (array) Settings::get( 'exclude_post_types', array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type => $object ) {
			if ( 'attachment' === $post_type || in_array( $post_type, $excluded, true ) ) {
				continue;
			}
			if ( ! empty( $object->has_archive ) ) {
				$archive = get_post_type_archive_link( $post_type );
				if ( $archive && Database::upsert_valid_url( $archive, array( 'post_type' => $post_type, 'source' => 'post_type_archive', 'tokens' => Matcher::tokens_string( $archive ), 'priority' => 60 ) ) ) {
					$stored++;
				}
			}

			$page = 1;
			do {
				$query = new \WP_Query(
					array(
						'post_type'              => $post_type,
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
					if ( Database::upsert_valid_url( $url, array( 'post_id' => (int) $post_id, 'post_type' => $post_type, 'source' => 'post', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 100 ) ) ) {
						$stored++;
					}
				}
				$page++;
			} while ( ! empty( $query->posts ) && count( $query->posts ) >= 500 );
		}

		$excluded_tax = (array) Settings::get( 'exclude_taxonomies', array() );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy => $object ) {
			if ( in_array( $taxonomy, $excluded_tax, true ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1000,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				if ( Database::upsert_valid_url( $url, array( 'taxonomy' => $taxonomy, 'term_id' => (int) $term->term_id, 'source' => 'taxonomy_archive', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 55 ) ) ) {
					$stored++;
				}
			}
		}

		return $stored;
	}

	/**
	 * Recursive sitemap scan.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @param int    $depth       Depth.
	 * @param array  $seen        Seen map.
	 * @return array|\WP_Error
	 */
	private static function scan_sitemap( $sitemap_url, $depth, array &$seen ) {
		$sitemap_url = esc_url_raw( $sitemap_url );
		if ( '' === $sitemap_url || $depth > self::MAX_DEPTH || count( $seen ) >= self::MAX_SITEMAPS || isset( $seen[ $sitemap_url ] ) ) {
			return array();
		}
		$seen[ $sitemap_url ] = true;

		$response = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'user-agent'  => 'Convertrack/' . CONVERTRACK_VERSION . ' 404-monitor',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'convertrack_404_sitemap_http', sprintf( 'Sitemap returned HTTP %d.', $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/\.gz($|\?)/i', $sitemap_url ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $body );
			if ( false !== $decoded ) {
				$body = $decoded;
			}
		}
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new \WP_Error( 'convertrack_404_simplexml_missing', __( 'SimpleXML is required to parse sitemaps.', 'convertrack-click-conversion-analytics' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'convertrack_404_bad_sitemap_xml', __( 'The sitemap XML could not be parsed.', 'convertrack-click-conversion-analytics' ) );
		}

		$out = array();
		$children = self::xpath_text( $xml, '//*[local-name()="sitemap"]/*[local-name()="loc"]' );
		if ( ! empty( $children ) ) {
			foreach ( $children as $child ) {
				$child_urls = self::scan_sitemap( $child, $depth + 1, $seen );
				if ( is_wp_error( $child_urls ) ) {
					continue;
				}
				$out = array_merge( $out, $child_urls );
				if ( count( $out ) >= self::MAX_URLS ) {
					break;
				}
			}
			return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_URLS );
		}

		foreach ( self::xpath_text( $xml, '//*[local-name()="url"]/*[local-name()="loc"]' ) as $url ) {
			if ( self::is_site_url( $url ) ) {
				$out[] = $url;
			}
			if ( count( $out ) >= self::MAX_URLS ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * XPath text helper.
	 *
	 * @param \SimpleXMLElement $xml XML.
	 * @param string            $query Query.
	 * @return array
	 */
	private static function xpath_text( $xml, $query ) {
		$out = array();
		foreach ( (array) $xml->xpath( $query ) as $item ) {
			$value = trim( (string) $item );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Same-site URL check.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		if ( empty( $home['host'] ) || empty( $test['host'] ) ) {
			return false;
		}
		return strtolower( preg_replace( '/^www\./', '', $home['host'] ) ) === strtolower( preg_replace( '/^www\./', '', $test['host'] ) );
	}
}

