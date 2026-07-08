<?php
/**
 * Recommendation engine for keyword opportunities.
 *
 * Rules emit compact { code, priority, params, dedupe_key } objects; the
 * human-readable message is rendered from the code at display time so stored
 * rows stay locale-independent. Recommendations never encourage keyword
 * stuffing — every "add" rule is suppressed on already-dense content.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Recommendations {

	/**
	 * Build recommendations for one analyzed keyword.
	 *
	 * @param array $ctx {
	 *     keyword, labels, impressions, ctr (0-1), position, post_id,
	 *     presence (from Keywords_Presence::check()), has_faq, extraction_quality.
	 * }
	 * @return array Up to N { code, priority, params, dedupe_key }, priority desc.
	 */
	public static function build( array $ctx ) {
		$keyword     = (string) $ctx['keyword'];
		$labels      = (array) $ctx['labels'];
		$impressions = (int) $ctx['impressions'];
		$ctr         = (float) $ctx['ctr'];
		$position    = (float) $ctx['position'];
		$post_id     = (int) $ctx['post_id'];
		$presence    = isset( $ctx['presence'] ) ? (array) $ctx['presence'] : array();
		$status      = isset( $presence['status'] ) ? (string) $presence['status'] : 'unknown';
		$areas       = isset( $presence['areas'] ) ? (array) $presence['areas'] : array();
		$density     = isset( $presence['density'] ) ? (float) $presence['density'] : 0.0;
		$body_count  = isset( $presence['body_exact_count'] ) ? (int) $presence['body_exact_count'] : 0;
		$has_faq     = ! empty( $ctx['has_faq'] );
		$quality     = isset( $ctx['extraction_quality'] ) ? (string) $ctx['extraction_quality'] : 'full';

		$min_impressions = (int) Keywords_Settings::get( 'min_impressions', 10 );
		$low_ctr_ratio   = (float) Keywords_Settings::get( 'low_ctr_ratio', 0.5 );
		$expected        = Keywords_Scorer::expected_ctr( $position );
		$low_ctr         = $impressions >= $min_impressions && $expected > 0 && $ctr < $expected * $low_ctr_ratio;

		// Anti-stuffing: never suggest adding a keyword that is already dense.
		$can_add = $density <= 1.5 && $body_count < 10;

		// Content-area rules make no sense for URLs we could not map to a post.
		$is_post = $post_id > 0 && 'unknown' !== $status;

		// Rule 1 is exclusive: a winning, well-placed keyword needs nothing else.
		if ( $is_post && $position >= 1 && $position <= 5 && 'present' === $status && ! $low_ctr ) {
			return array( self::item( 'already_optimized', 10, array( 'keyword' => $keyword ), 'already_optimized:' . $post_id . ':' . md5( $keyword ) ) );
		}

		$out = array();

		if ( $is_post && 'overused' === $status ) {
			$out[] = self::item(
				'reduce_density',
				95,
				array(
					'keyword' => $keyword,
					'count'   => $body_count,
					'density' => $density,
				),
				'reduce_density:' . $post_id . ':' . md5( $keyword )
			);
		}

		if ( $low_ctr && $position >= 1 && $position <= 20 ) {
			$out[] = self::item(
				'improve_title_meta',
				90,
				array(
					'keyword'     => $keyword,
					'impressions' => $impressions,
					'ctr'         => round( $ctr * 100, 2 ),
					'expected'    => round( $expected * 100, 2 ),
					'position'    => round( $position, 1 ),
				),
				'improve_title_meta:' . $post_id
			);
		}

		if ( $is_post && 'missing' === $status && $impressions >= $min_impressions && $can_add ) {
			$out[] = self::item( 'add_to_content', 85, array( 'keyword' => $keyword ), 'add_to_content:' . $post_id . ':' . md5( $keyword ) );
		}

		if ( $is_post && 'needs_improvement' === $status && $can_add && 'partial_builder' !== $quality ) {
			$out[] = self::item( 'promote_to_headings', 75, array( 'keyword' => $keyword ), 'promote_to_headings:' . $post_id );
		}

		if ( $is_post && in_array( 'question', $labels, true ) && $can_add
			&& ( ! $has_faq || 'missing' === ( isset( $areas['body'] ) ? $areas['body'] : 'missing' ) ) ) {
			$out[] = self::item( 'add_faq', 70, array( 'keyword' => $keyword ), 'add_faq:' . $post_id . ':' . md5( $keyword ) );
		}

		if ( $position >= 11 && $position <= 20 ) {
			$out[] = self::item(
				'page_two_push',
				65,
				array(
					'keyword'  => $keyword,
					'position' => round( $position, 1 ),
				),
				'page_two_push:' . $post_id . ':' . md5( $keyword )
			);
		}

		if ( $is_post && in_array( 'location', $labels, true ) && in_array( $status, array( 'missing', 'partial' ), true ) && $can_add ) {
			$out[] = self::item( 'add_location', 60, array( 'keyword' => $keyword ), 'add_location:' . $post_id );
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['priority'] - $a['priority'];
			}
		);

		$max = max( 1, (int) apply_filters( 'convertrack_keywords_max_recommendations', 3 ) );
		return array_slice( $out, 0, $max );
	}

	/**
	 * Render a stored recommendation to a translated message.
	 *
	 * @param string $code   Recommendation code.
	 * @param array  $params Stored params.
	 * @return string
	 */
	public static function message( $code, array $params = array() ) {
		$keyword = isset( $params['keyword'] ) ? '"' . $params['keyword'] . '"' : __( 'this keyword', 'convertrack-click-conversion-analytics' );

		switch ( $code ) {
			case 'already_optimized':
				/* translators: %s: keyword. */
				return sprintf( __( '%s already ranks well and appears in your title and headings — no action needed.', 'convertrack-click-conversion-analytics' ), $keyword );

			case 'reduce_density':
				return sprintf(
					/* translators: 1: keyword, 2: occurrence count, 3: density percent. */
					__( '%1$s appears %2$d times (%3$s%% density). Reduce the repetition and use natural variations to avoid keyword stuffing.', 'convertrack-click-conversion-analytics' ),
					$keyword,
					isset( $params['count'] ) ? (int) $params['count'] : 0,
					isset( $params['density'] ) ? (string) $params['density'] : '0'
				);

			case 'improve_title_meta':
				return sprintf(
					/* translators: 1: impressions, 2: keyword, 3: CTR percent, 4: expected CTR percent, 5: position. */
					__( 'This page gets %1$s impressions for %2$s but only %3$s%% CTR (≈%4$s%% is typical at position %5$s). Rewrite the SEO title and meta description to make the result more compelling.', 'convertrack-click-conversion-analytics' ),
					number_format_i18n( isset( $params['impressions'] ) ? (int) $params['impressions'] : 0 ),
					$keyword,
					isset( $params['ctr'] ) ? (string) $params['ctr'] : '0',
					isset( $params['expected'] ) ? (string) $params['expected'] : '0',
					isset( $params['position'] ) ? (string) $params['position'] : '?'
				);

			case 'add_to_content':
				/* translators: %s: keyword. */
				return sprintf( __( 'Google shows this page for %s but the phrase never appears on it. Work it into the copy naturally where it fits.', 'convertrack-click-conversion-analytics' ), $keyword );

			case 'promote_to_headings':
				/* translators: %s: keyword. */
				return sprintf( __( '%s appears in the body but not in the title, H1, or subheadings. Add it to an H2 or the page title if it reads naturally.', 'convertrack-click-conversion-analytics' ), $keyword );

			case 'add_faq':
				/* translators: %s: keyword. */
				return sprintf( __( '%s is a question searchers ask. Answer it in a short FAQ section on this page.', 'convertrack-click-conversion-analytics' ), $keyword );

			case 'page_two_push':
				return sprintf(
					/* translators: 1: keyword, 2: position. */
					__( '%1$s sits on page 2 (position %2$s). Strengthen the page with supporting content and add internal links to it using this phrase as anchor text.', 'convertrack-click-conversion-analytics' ),
					$keyword,
					isset( $params['position'] ) ? (string) $params['position'] : '?'
				);

			case 'add_location':
				/* translators: %s: keyword. */
				return sprintf( __( 'Searchers include a location in %s. Mention the area you serve naturally on this page.', 'convertrack-click-conversion-analytics' ), $keyword );
		}

		return '';
	}

	/**
	 * Build one recommendation item.
	 *
	 * @param string $code       Code.
	 * @param int    $priority   Priority (higher = more urgent).
	 * @param array  $params     Message params.
	 * @param string $dedupe_key Page-level dedupe key.
	 * @return array
	 */
	private static function item( $code, $priority, array $params, $dedupe_key ) {
		return array(
			'code'       => $code,
			'priority'   => (int) $priority,
			'params'     => $params,
			'dedupe_key' => $dedupe_key,
		);
	}
}
