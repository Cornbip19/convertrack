<?php
/**
 * Per-post content fingerprint for keyword presence checks.
 *
 * Renders block markup with do_blocks() but deliberately skips the full
 * the_content filter stack (plugins hook heavy, side-effectful work there)
 * and shortcode execution (bracket tags are stripped, inner text kept).
 * All extracted text is pre-folded so matching is a pure string operation.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Fingerprint {

	const MAX_HTML_BYTES = 512000;
	const MAX_LIST_ITEMS = 100;
	const CACHE_SIZE     = 20;

	/**
	 * Per-request fingerprint cache (batches are post_id-ordered, so a small
	 * cache is enough).
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Build (or return cached) fingerprint for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|\WP_Error
	 */
	public static function for_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'convertrack_gsc_keywords_no_post', __( 'The post no longer exists or is not published.', 'convertrack-click-conversion-analytics' ) );
		}

		$fingerprint = self::build( $post );

		if ( count( self::$cache ) >= self::CACHE_SIZE ) {
			array_shift( self::$cache );
		}
		self::$cache[ $post_id ] = $fingerprint;

		return $fingerprint;
	}

	/**
	 * Cheap staleness hash — no content parsing, just the inputs that change
	 * analysis output.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function hash( $post_id ) {
		$post = get_post( $post_id );
		$meta = Keywords_Seo_Meta::for_post( $post_id );

		$settings = Keywords_Settings::all();
		$relevant = array(
			'brand'      => $settings['brand_terms'],
			'location'   => $settings['location_terms'],
			'service'    => $settings['service_terms'],
			'product'    => $settings['product_terms'],
			'competitor' => $settings['competitor_terms'],
			'min_imp'    => $settings['min_impressions'],
			'min_pos'    => $settings['min_position'],
			'ctr_ratio'  => $settings['low_ctr_ratio'],
		);

		return md5(
			( $post ? (string) $post->post_modified_gmt : '' )
			. '|' . $meta['title']
			. '|' . $meta['description']
			. '|' . Keywords_Word_Lists::VERSION
			. '|' . md5( serialize( $relevant ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		);
	}

	/**
	 * Reset the per-request cache.
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Assemble the fingerprint.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function build( $post ) {
		$seo_meta = Keywords_Seo_Meta::for_post( $post->ID );
		$rendered = self::extract_html( $post );
		$html     = $rendered['html'];
		$quality  = $rendered['quality'];

		if ( class_exists( 'DOMDocument' ) && '' !== $html ) {
			$parsed = self::parse_dom( $html );
		} else {
			$parsed  = null;
		}
		if ( null === $parsed ) {
			$parsed  = self::parse_regex( $html );
			$quality = 'full' === $quality ? 'regex_fallback' : $quality;
		}

		$body_folded = Keywords_Text::fold( $parsed['body'] );

		// Page-builder pages keep almost nothing in post_content — scrape the
		// builder's data blob as a best effort and flag the reduced fidelity.
		if ( str_word_count( $body_folded ) < 50 ) {
			$builder_text = self::elementor_text( $post->ID );
			if ( '' !== $builder_text ) {
				$body_folded = trim( $body_folded . ' ' . Keywords_Text::fold( $builder_text ) );
				$quality     = 'partial_builder';
			} elseif ( '' === trim( $parsed['body'] ) ) {
				$quality = 'empty';
			}
		}

		$h1 = Keywords_Text::fold( $post->post_title );
		if ( ! empty( $parsed['h1'] ) ) {
			$h1 = trim( $h1 . ' ' . Keywords_Text::fold( implode( ' ', $parsed['h1'] ) ) );
		}

		return array(
			'post_id'            => (int) $post->ID,
			'url_slug'           => Keywords_Text::fold( str_replace( '-', ' ', (string) $post->post_name ) ),
			'seo_title'          => Keywords_Text::fold( $seo_meta['title'] ),
			'meta_description'   => Keywords_Text::fold( $seo_meta['description'] ),
			'seo_title_raw'      => (string) $seo_meta['title'],
			'meta_description_raw' => (string) $seo_meta['description'],
			'seo_source'         => (string) $seo_meta['source'],
			'h1'                 => $h1,
			'headings'           => self::fold_list( $parsed['headings'] ),
			'first_paragraph'    => Keywords_Text::fold( $parsed['first_paragraph'] ),
			'body'               => $body_folded,
			'body_word_count'    => str_word_count( $body_folded ),
			'image_alts'         => self::fold_list( $parsed['image_alts'] ),
			'anchor_texts'       => self::fold_list( $parsed['anchor_texts'] ),
			'has_faq'            => self::has_faq( (string) $post->post_content ),
			'extraction_quality' => $quality,
			'content_hash'       => self::hash( $post->ID ),
		);
	}

	/**
	 * Render post content to analyzable HTML.
	 *
	 * @param \WP_Post $post Post.
	 * @return array { html, quality }
	 */
	private static function extract_html( $post ) {
		$raw     = (string) $post->post_content;
		$quality = 'full';

		$previous_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;
		try {
			$html = function_exists( 'do_blocks' ) ? do_blocks( $raw ) : $raw;
		} catch ( \Throwable $e ) {
			$html    = $raw;
			$quality = 'regex_fallback';
		} finally {
			$GLOBALS['post'] = $previous_post;
		}

		$html = wpautop( $html );

		// Strip shortcode brackets but keep enclosed copy (strip_shortcodes()
		// would delete it), so shortcode-wrapped text still matches.
		$html = preg_replace( '/\[\/?[a-z0-9_-]+[^\]]*\]/i', ' ', $html );

		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			$html = substr( $html, 0, self::MAX_HTML_BYTES );
		}

		$html = (string) apply_filters( 'convertrack_keywords_post_content_html', $html, $post->ID );

		return array(
			'html'    => $html,
			'quality' => $quality,
		);
	}

	/**
	 * DOM extraction.
	 *
	 * @param string $html Rendered HTML.
	 * @return array|null Null when the DOM could not be built.
	 */
	private static function parse_dom( $html ) {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();

		// Meta-charset preamble keeps multibyte text intact without the
		// deprecated mb_convert_encoding(..., 'HTML-ENTITIES') trick.
		$wrapped = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
		$loaded  = $dom->loadHTML( $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$out = array(
			'h1'              => array(),
			'headings'        => array(),
			'first_paragraph' => '',
			'body'            => '',
			'image_alts'      => array(),
			'anchor_texts'    => array(),
		);

		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$text = trim( $node->textContent );
				if ( '' === $text ) {
					continue;
				}
				if ( 'h1' === $tag ) {
					$out['h1'][] = $text;
				} else {
					$out['headings'][] = $text;
				}
			}
		}

		foreach ( $dom->getElementsByTagName( 'p' ) as $node ) {
			$text = trim( $node->textContent );
			if ( '' !== $text ) {
				$out['first_paragraph'] = $text;
				break;
			}
		}

		foreach ( $dom->getElementsByTagName( 'img' ) as $node ) {
			$alt = trim( $node->getAttribute( 'alt' ) );
			if ( '' !== $alt ) {
				$out['image_alts'][] = $alt;
			}
		}

		foreach ( $dom->getElementsByTagName( 'a' ) as $node ) {
			$text = trim( $node->textContent );
			if ( '' !== $text && self::is_internal_link( $node->getAttribute( 'href' ) ) ) {
				$out['anchor_texts'][] = $text;
			}
		}

		$bodies      = $dom->getElementsByTagName( 'body' );
		$out['body'] = $bodies->length ? trim( $bodies->item( 0 )->textContent ) : '';

		return $out;
	}

	/**
	 * Regex extraction fallback for hosts without DOMDocument.
	 *
	 * @param string $html Rendered HTML.
	 * @return array
	 */
	private static function parse_regex( $html ) {
		$out = array(
			'h1'              => array(),
			'headings'        => array(),
			'first_paragraph' => '',
			'body'            => wp_strip_all_tags( $html ),
			'image_alts'      => array(),
			'anchor_texts'    => array(),
		);

		if ( preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches ) ) {
			$out['h1'] = array_filter( array_map( 'wp_strip_all_tags', $matches[1] ) );
		}
		if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, $matches ) ) {
			$out['headings'] = array_filter( array_map( 'wp_strip_all_tags', $matches[1] ) );
		}
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $html, $match ) ) {
			$out['first_paragraph'] = trim( wp_strip_all_tags( $match[1] ) );
		}
		if ( preg_match_all( '/<img[^>]+alt=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$out['image_alts'] = $matches[1];
		}
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $text && self::is_internal_link( $match[1] ) ) {
					$out['anchor_texts'][] = $text;
				}
			}
		}

		return $out;
	}

	/**
	 * Best-effort text scrape of Elementor's data blob.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function elementor_text( $post_id ) {
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $data ) || '' === $data ) {
			return '';
		}

		$decoded = json_decode( $data, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$keys = (array) apply_filters(
			'convertrack_keywords_elementor_text_keys',
			array( 'title', 'editor', 'text', 'description_text', 'item_description', 'tab_content', 'testimonial_content', 'caption', 'alt' )
		);

		$texts = array();
		self::collect_json_text( $decoded, $keys, $texts );

		return implode( ' ', array_slice( $texts, 0, 500 ) );
	}

	/**
	 * Recursively collect string values of whitelisted keys.
	 *
	 * @param array $node  JSON node.
	 * @param array $keys  Text keys.
	 * @param array $texts Output (by reference).
	 */
	private static function collect_json_text( array $node, array $keys, array &$texts ) {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_json_text( $value, $keys, $texts );
			} elseif ( is_string( $value ) && '' !== trim( $value ) && in_array( (string) $key, $keys, true ) ) {
				$texts[] = wp_strip_all_tags( $value );
			}
		}
	}

	/**
	 * FAQ block / schema detection on raw block markup.
	 *
	 * @param string $raw_content Raw post_content.
	 * @return bool
	 */
	private static function has_faq( $raw_content ) {
		foreach ( array( 'wp:rank-math/faq-block', 'wp:yoast/faq-block', 'wp:yoast/how-to-block', 'FAQPage' ) as $marker ) {
			if ( false !== strpos( $raw_content, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a link href points at this site.
	 *
	 * @param string $href Href attribute.
	 * @return bool
	 */
	private static function is_internal_link( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href || '#' === $href[0] ) {
			return false;
		}
		if ( '/' === $href[0] && ( ! isset( $href[1] ) || '/' !== $href[1] ) ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$site = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		$strip = static function ( $value ) {
			return 0 === strpos( $value, 'www.' ) ? substr( $value, 4 ) : $value;
		};

		return $strip( $host ) === $strip( $site );
	}

	/**
	 * Fold a list of strings, drop empties, cap size.
	 *
	 * @param array $items Raw strings.
	 * @return array
	 */
	private static function fold_list( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$folded = Keywords_Text::fold( (string) $item );
			if ( '' !== $folded ) {
				$out[] = $folded;
			}
			if ( count( $out ) >= self::MAX_LIST_ITEMS ) {
				break;
			}
		}
		return $out;
	}
}
