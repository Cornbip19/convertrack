<?php
/**
 * Text normalization utilities for keyword analysis.
 *
 * Keywords and page content both pass through the same fold() pipeline so
 * "Café" matches "cafe" and "wood-working" matches "woodworking's".
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Text {

	/**
	 * Normalize a string: strip markup, decode entities, lowercase, replace
	 * separators with spaces, strip punctuation, collapse whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = str_replace( array( '-', '_', '/' ), ' ', $text );

		$stripped = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		if ( null === $stripped ) {
			// Invalid UTF-8 can make the unicode regex fail — fall back to ASCII.
			$stripped = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		}

		return trim( preg_replace( '/\s+/', ' ', (string) $stripped ) );
	}

	/**
	 * Normalized, accent-folded form used for all matching.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function fold( $text ) {
		return self::normalize( remove_accents( (string) $text ) );
	}

	/**
	 * Tokens of a folded string.
	 *
	 * @param string $folded Folded text.
	 * @return array
	 */
	public static function tokens( $folded ) {
		if ( '' === $folded ) {
			return array();
		}
		return array_values( array_filter( explode( ' ', $folded ), 'strlen' ) );
	}

	/**
	 * Tokens minus stopwords. Falls back to the raw tokens when everything is
	 * a stopword (e.g. the query "what is it").
	 *
	 * @param string $folded Folded text.
	 * @return array
	 */
	public static function significant_tokens( $folded ) {
		$tokens      = self::tokens( $folded );
		$significant = array_values( array_diff( $tokens, self::stopwords() ) );
		return empty( $significant ) ? $tokens : $significant;
	}

	/**
	 * English stopwords, filterable for other languages.
	 *
	 * @return array
	 */
	public static function stopwords() {
		static $stopwords = null;
		if ( null === $stopwords ) {
			$stopwords = apply_filters(
				'convertrack_keywords_stopwords',
				array( 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he', 'how', 'i', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'we', 'what', 'when', 'where', 'which', 'who', 'will', 'with', 'your', 'you' )
			);
		}
		return $stopwords;
	}

	/**
	 * Cheap singular/plural trimming — deliberately not a real stemmer.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function stem( $token ) {
		$token = (string) $token;
		$len   = strlen( $token );
		if ( $len <= 3 ) {
			return $token;
		}

		if ( 'ies' === substr( $token, -3 ) && $len > 4 ) {
			return substr( $token, 0, -3 ) . 'y';
		}
		if ( 'es' === substr( $token, -2 ) && preg_match( '/(s|x|z|ch|sh)es$/', $token ) ) {
			return substr( $token, 0, -2 );
		}
		if ( 's' === substr( $token, -1 ) && 'ss' !== substr( $token, -2 ) ) {
			return substr( $token, 0, -1 );
		}

		return $token;
	}

	/**
	 * Stemmed significant tokens of a folded string.
	 *
	 * @param string $folded Folded text.
	 * @return array
	 */
	public static function stems( $folded ) {
		return array_values( array_unique( array_map( array( __CLASS__, 'stem' ), self::significant_tokens( $folded ) ) ) );
	}

	/**
	 * Whether a folded needle phrase occurs in a folded haystack, on word
	 * boundaries.
	 *
	 * @param string $haystack Folded haystack.
	 * @param string $needle   Folded needle.
	 * @return bool
	 */
	public static function contains_phrase( $haystack, $needle ) {
		return self::count_phrase( $haystack, $needle ) > 0;
	}

	/**
	 * Count word-boundary occurrences of a folded phrase.
	 *
	 * @param string $haystack Folded haystack.
	 * @param string $needle   Folded needle.
	 * @return int
	 */
	public static function count_phrase( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		if ( '' === $haystack || '' === $needle ) {
			return 0;
		}

		$count = preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $haystack );
		if ( false === $count || null === $count ) {
			// Regex failure (invalid UTF-8): padded substring count is close enough.
			return substr_count( ' ' . $haystack . ' ', ' ' . $needle . ' ' );
		}

		return (int) $count;
	}

	/**
	 * Folded form with all spaces removed ("convert track" → "converttrack"),
	 * for brand matching across spacing variants.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function squash( $text ) {
		return str_replace( ' ', '', self::fold( $text ) );
	}
}
