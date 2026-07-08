<?php
/**
 * Rule-based keyword type / intent classifier.
 *
 * Deterministic, offline string matching against filterable word lists and a
 * site vocabulary (brand variants, service/product phrases from published
 * titles). Multiple labels per keyword are expected.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Classifier {

	const LABELS = array( 'branded', 'non_branded', 'service', 'product', 'location', 'commercial', 'informational', 'transactional', 'navigational', 'question', 'long_tail', 'competitor' );

	/**
	 * Per-request context cache.
	 *
	 * @var array|null
	 */
	private static $context = null;

	/**
	 * Build the classification context once per batch.
	 *
	 * @return array
	 */
	public static function build_context() {
		if ( null !== self::$context ) {
			return self::$context;
		}

		$settings = Keywords_Settings::all();

		$brand_sources = array_merge(
			(array) $settings['brand_terms'],
			array( (string) get_bloginfo( 'name' ) ),
			self::host_tokens()
		);

		$brand_variants = array();
		foreach ( $brand_sources as $source ) {
			$folded = Keywords_Text::fold( $source );
			if ( '' === $folded ) {
				continue;
			}
			$brand_variants[ $folded ] = array(
				'folded' => $folded,
				'squash' => str_replace( ' ', '', $folded ),
			);
		}

		$service_post_types = (array) apply_filters( 'convertrack_keywords_service_vocab_post_types', array( 'page' ) );
		$product_titles     = post_type_exists( 'product' ) ? self::published_titles( array( 'product' ) ) : array();
		$product_terms      = array();
		if ( taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 500,
					'fields'     => 'names',
				)
			);
			if ( is_array( $terms ) ) {
				$product_terms = $terms;
			}
		}

		self::$context = array(
			'brand_variants'   => array_values( $brand_variants ),
			'site_name_folded' => Keywords_Text::fold( (string) get_bloginfo( 'name' ) ),
			'site_name_squash' => Keywords_Text::squash( (string) get_bloginfo( 'name' ) ),
			'location_terms'   => self::fold_terms( (array) $settings['location_terms'] ),
			'competitor_terms' => self::fold_terms( (array) $settings['competitor_terms'] ),
			'service_vocab'    => self::build_vocab( array_merge( (array) $settings['service_terms'], self::published_titles( $service_post_types ) ) ),
			'product_vocab'    => self::build_vocab( array_merge( (array) $settings['product_terms'], $product_titles, $product_terms ) ),
			'long_tail_min'    => max( 2, (int) apply_filters( 'convertrack_keywords_long_tail_min_words', 4 ) ),
		);

		return self::$context;
	}

	/**
	 * Reset the context cache (settings changed mid-request, tests).
	 */
	public static function flush_context() {
		self::$context = null;
	}

	/**
	 * Classify a query. Returns label slugs in canonical order.
	 *
	 * @param string $query Raw query.
	 * @param array  $ctx   Context from build_context().
	 * @return array
	 */
	public static function classify( $query, array $ctx ) {
		$folded = Keywords_Text::fold( $query );
		$squash = str_replace( ' ', '', $folded );
		$tokens = Keywords_Text::tokens( $folded );
		$stems  = array_flip( Keywords_Text::stems( $folded ) );
		$padded = ' ' . $folded . ' ';
		$labels = array();

		// Branded / non-branded — exactly one of the two, always.
		$branded = false;
		foreach ( $ctx['brand_variants'] as $variant ) {
			if ( Keywords_Text::contains_phrase( $folded, $variant['folded'] )
				|| ( strlen( $variant['squash'] ) >= 4 && false !== strpos( $squash, $variant['squash'] ) ) ) {
				$branded = true;
				break;
			}
		}
		$labels[ $branded ? 'branded' : 'non_branded' ] = true;

		if ( false !== strpos( (string) $query, '?' )
			|| ( isset( $tokens[0] ) && in_array( $tokens[0], Keywords_Word_Lists::question_starters(), true ) )
			|| self::any_phrase( $padded, Keywords_Word_Lists::question_phrases() ) ) {
			$labels['question'] = true;
		}

		if ( self::any_phrase( $padded, Keywords_Word_Lists::transactional() ) ) {
			$labels['transactional'] = true;
		}
		if ( self::any_phrase( $padded, Keywords_Word_Lists::commercial() ) ) {
			$labels['commercial'] = true;
		}
		if ( self::any_phrase( $padded, Keywords_Word_Lists::informational() ) ) {
			$labels['informational'] = true;
		}

		if ( self::any_phrase( $padded, $ctx['location_terms'] ) || self::any_phrase( $padded, Keywords_Word_Lists::near_me() ) ) {
			$labels['location'] = true;
		}

		if ( self::vocab_match( $padded, $stems, $ctx['service_vocab'] ) ) {
			$labels['service'] = true;
		}
		if ( self::vocab_match( $padded, $stems, $ctx['product_vocab'] ) ) {
			$labels['product'] = true;
		}

		if ( self::any_phrase( $padded, $ctx['competitor_terms'] ) ) {
			$labels['competitor'] = true;
		}

		if ( count( $tokens ) >= $ctx['long_tail_min'] ) {
			$labels['long_tail'] = true;
		}

		if ( $branded ) {
			$site_squash = (string) $ctx['site_name_squash'];
			$navigational = self::any_phrase( $padded, Keywords_Word_Lists::navigational_intent() )
				|| ( '' !== $ctx['site_name_folded'] && $folded === $ctx['site_name_folded'] );
			if ( ! $navigational && strlen( $squash ) >= 5 && strlen( $site_squash ) >= 5 && strlen( $squash ) <= 255 && strlen( $site_squash ) <= 255 ) {
				$navigational = levenshtein( $squash, $site_squash ) <= 2;
			}
			if ( $navigational ) {
				$labels['navigational'] = true;
			}
		}

		// Emit in canonical order for stable JSON.
		$out = array();
		foreach ( self::LABELS as $label ) {
			if ( isset( $labels[ $label ] ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	/**
	 * Whether any term occurs in the space-padded folded query. Terms are
	 * matched as whole words/phrases via padded substring checks (fast enough
	 * for thousands of keywords x hundreds of vocab entries).
	 *
	 * @param string $padded Padded folded query.
	 * @param array  $terms  Terms (raw; folded here defensively for word lists).
	 * @return bool
	 */
	private static function any_phrase( $padded, array $terms ) {
		foreach ( $terms as $term ) {
			$term = is_array( $term ) ? '' : (string) $term;
			// Word list entries are already lowercase ASCII; user terms are folded upstream.
			if ( '' !== $term && false !== strpos( $padded, ' ' . $term . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a vocabulary entry matches the query.
	 *
	 * @param string $padded Padded folded query.
	 * @param array  $stems  Flipped query stem set.
	 * @param array  $vocab  Vocab entries { phrase, stems, tokens }.
	 * @return bool
	 */
	private static function vocab_match( $padded, array $stems, array $vocab ) {
		foreach ( $vocab as $entry ) {
			if ( $entry['tokens'] <= 2 && false !== strpos( $padded, ' ' . $entry['phrase'] . ' ' ) ) {
				return true;
			}

			if ( $entry['tokens'] <= 4 && ! empty( $entry['stems'] ) ) {
				$all = true;
				foreach ( $entry['stems'] as $stem ) {
					if ( ! isset( $stems[ $stem ] ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Build a vocabulary from phrases (user terms + published titles).
	 *
	 * @param array $phrases Raw phrases.
	 * @return array Entries { phrase, stems, tokens }.
	 */
	private static function build_vocab( array $phrases ) {
		$vocab = array();
		$seen  = array();

		foreach ( $phrases as $phrase ) {
			$folded = Keywords_Text::fold( (string) $phrase );
			if ( '' === $folded || isset( $seen[ $folded ] ) ) {
				continue;
			}
			$seen[ $folded ] = true;

			$significant = Keywords_Text::significant_tokens( $folded );
			// Long, blog-style titles are noise for vocabulary matching.
			if ( count( $significant ) > 6 || empty( $significant ) ) {
				continue;
			}

			$vocab[] = array(
				'phrase' => $folded,
				'stems'  => array_values( array_unique( array_map( array( Keywords_Text::class, 'stem' ), $significant ) ) ),
				'tokens' => count( $significant ),
			);

			if ( count( $vocab ) >= 2000 ) {
				break;
			}
		}

		return $vocab;
	}

	/**
	 * Published titles for the given post types (single direct query — cheaper
	 * than WP_Query for a flat title list).
	 *
	 * @param array $post_types Post types.
	 * @return array
	 */
	private static function published_titles( array $post_types ) {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$limit        = max( 50, (int) apply_filters( 'convertrack_keywords_vocab_title_limit', 2000 ) );

		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) LIMIT %d",
				array_merge( $post_types, array( $limit ) )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $titles ) ? $titles : array();
	}

	/**
	 * Fold user terms once for matching.
	 *
	 * @param array $terms Raw terms.
	 * @return array
	 */
	private static function fold_terms( array $terms ) {
		$out = array();
		foreach ( $terms as $term ) {
			$folded = Keywords_Text::fold( (string) $term );
			if ( '' !== $folded ) {
				$out[] = $folded;
			}
		}
		return $out;
	}

	/**
	 * Brand tokens derived from the site host ("acme-plumbing.co.uk" →
	 * "acme", "plumbing", "acmeplumbing").
	 *
	 * @return array
	 */
	private static function host_tokens() {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		if ( '' === $host ) {
			return array();
		}

		$parts = explode( '.', $host );
		// Everything after the registrable label is TLD noise for brand purposes.
		$label = (string) $parts[0];

		$tokens = array_values( array_filter( explode( '-', $label ), static function ( $token ) {
			return strlen( $token ) >= 3;
		} ) );

		if ( count( $tokens ) > 1 ) {
			$tokens[] = str_replace( '-', '', $label );
		}

		return $tokens;
	}
}
