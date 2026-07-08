<?php
/**
 * Keyword-vs-content presence check.
 *
 * Pure function over a keyword and a content fingerprint: per-area status
 * (present = exact phrase, partial = all significant stems, missing) plus an
 * overall status including overuse detection.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Presence {

	const AREAS = array( 'seo_title', 'meta_description', 'h1', 'headings', 'first_paragraph', 'body', 'image_alts', 'anchor_texts', 'url_slug' );

	/**
	 * Areas whose exact presence marks a keyword as well-placed.
	 *
	 * @return array
	 */
	public static function key_areas() {
		return (array) apply_filters( 'convertrack_keywords_key_areas', array( 'seo_title', 'h1', 'headings', 'meta_description' ) );
	}

	/**
	 * Check a keyword against a fingerprint.
	 *
	 * @param string $keyword     Raw keyword.
	 * @param array  $fingerprint Fingerprint from Keywords_Fingerprint::for_post().
	 * @return array { areas, status, body_exact_count, density }
	 */
	public static function check( $keyword, array $fingerprint ) {
		$folded = Keywords_Text::fold( $keyword );
		$stems  = array_map( array( Keywords_Text::class, 'stem' ), Keywords_Text::significant_tokens( $folded ) );

		$areas = array();
		foreach ( self::AREAS as $area ) {
			$value          = isset( $fingerprint[ $area ] ) ? $fingerprint[ $area ] : '';
			$areas[ $area ] = self::area_status( $folded, $stems, $value );
		}

		$body_count = Keywords_Text::count_phrase( isset( $fingerprint['body'] ) ? $fingerprint['body'] : '', $folded );
		$body_words = isset( $fingerprint['body_word_count'] ) ? (int) $fingerprint['body_word_count'] : 0;
		$kw_words   = max( 1, count( Keywords_Text::tokens( $folded ) ) );
		$density    = $body_words > 0 ? round( 100 * $body_count * $kw_words / $body_words, 2 ) : 0.0;

		return array(
			'areas'            => $areas,
			'status'           => self::overall_status( $areas, $body_count, $body_words, $density ),
			'body_exact_count' => $body_count,
			'density'          => $density,
		);
	}

	/**
	 * Status of one content area.
	 *
	 * @param string       $folded Folded keyword.
	 * @param array        $stems  Keyword stems.
	 * @param string|array $value  Area text or list of texts.
	 * @return string present|partial|missing
	 */
	public static function area_status( $folded, array $stems, $value ) {
		$texts = is_array( $value ) ? $value : array( (string) $value );
		$texts = array_filter( $texts, 'strlen' );
		if ( empty( $texts ) || '' === $folded ) {
			return 'missing';
		}

		foreach ( $texts as $text ) {
			if ( Keywords_Text::contains_phrase( $text, $folded ) ) {
				return 'present';
			}
		}

		if ( ! empty( $stems ) ) {
			foreach ( $texts as $text ) {
				$area_stems = array_flip( array_map( array( Keywords_Text::class, 'stem' ), Keywords_Text::tokens( $text ) ) );
				$all        = true;
				foreach ( $stems as $stem ) {
					if ( ! isset( $area_stems[ $stem ] ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return 'partial';
				}
			}
		}

		return 'missing';
	}

	/**
	 * Overall status from per-area results.
	 *
	 * @param array $areas      Per-area statuses.
	 * @param int   $body_count Exact body occurrences.
	 * @param int   $body_words Body word count.
	 * @param float $density    Density percent.
	 * @return string
	 */
	private static function overall_status( array $areas, $body_count, $body_words, $density ) {
		$max_density = (float) apply_filters( 'convertrack_keywords_overuse_density', 2.5 );
		$max_count   = (int) apply_filters( 'convertrack_keywords_overuse_count', 15 );
		$min_count   = (int) apply_filters( 'convertrack_keywords_overuse_min_count', 3 );

		// Repetition, not a single mention, is stuffing. A multi-word phrase
		// appearing once can exceed the density threshold on its own (density
		// scales with keyword length), so require a minimum occurrence count.
		if ( $body_count >= $min_count && $body_words >= 100 && ( $density > $max_density || $body_count > $max_count ) ) {
			return 'overused';
		}

		$key_areas = self::key_areas();
		foreach ( $key_areas as $area ) {
			if ( isset( $areas[ $area ] ) && 'present' === $areas[ $area ] ) {
				return 'present';
			}
		}

		$body_status      = isset( $areas['body'] ) ? $areas['body'] : 'missing';
		$key_all_missing  = true;
		foreach ( $key_areas as $area ) {
			if ( isset( $areas[ $area ] ) && 'missing' !== $areas[ $area ] ) {
				$key_all_missing = false;
				break;
			}
		}

		if ( 'missing' !== $body_status && $key_all_missing ) {
			return 'needs_improvement';
		}

		foreach ( $areas as $area => $status ) {
			if ( 'partial' === $status ) {
				return 'partial';
			}
			if ( 'present' === $status && ! in_array( $area, $key_areas, true ) ) {
				return 'partial';
			}
		}

		return 'missing';
	}
}
