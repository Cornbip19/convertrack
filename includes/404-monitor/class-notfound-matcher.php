<?php
/**
 * 404 redirect recommendation engine.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Matcher {

	/**
	 * Process a batch of unresolved events.
	 *
	 * @param int $limit Batch limit.
	 * @return array
	 */
	public static function process_batch( $limit = 0 ) {
		if ( ! Settings::recommendations_enabled() ) {
			return array( 'processed' => 0, 'auto_created' => 0, 'skipped' => true );
		}

		if ( Database::valid_url_count() < 1 ) {
			Sitemap_Source::refresh();
		}

		$limit = $limit > 0 ? $limit : (int) Settings::get( 'recommendation_batch', 50 );
		$rows  = Database::recommendation_batch( $limit );
		$processed = 0;
		$auto_created = 0;

		foreach ( $rows as $row ) {
			$result = self::recommend( $row );
			Database::save_recommendation( (int) $row['id'], $result );
			$processed++;

			if ( self::should_auto_redirect( $result ) ) {
				$created = Redirector::create_from_event( (int) $row['id'], true );
				if ( ! is_wp_error( $created ) && $created ) {
					$auto_created++;
				}
			}
		}

		Logger::info( 'matcher', '404 recommendation batch completed.', array( 'processed' => $processed, 'auto_created' => $auto_created ) );
		return array( 'processed' => $processed, 'auto_created' => $auto_created, 'skipped' => false );
	}

	/**
	 * Build a recommendation for one event row.
	 *
	 * @param array $event Event row.
	 * @return array
	 */
	public static function recommend( array $event ) {
		$source_path = isset( $event['path'] ) ? (string) $event['path'] : '';
		$source_tokens = self::tokens( $source_path );
		$source_slug = basename( untrailingslashit( $source_path ) );
		$best = array(
			'url'              => '',
			'confidence'       => 0,
			'reason'           => '',
			'post_id'          => 0,
			'post_type'        => '',
			'destination_type' => '',
		);

		foreach ( Database::valid_candidates() as $candidate ) {
			if ( empty( $candidate['url'] ) || empty( $candidate['path'] ) ) {
				continue;
			}
			$score = 0;
			$reason = '';
			$candidate_path = (string) $candidate['path'];
			$candidate_slug = isset( $candidate['slug'] ) ? (string) $candidate['slug'] : basename( untrailingslashit( $candidate_path ) );

			if ( $source_path === $candidate_path ) {
				$score = 100;
				$reason = 'exact_path';
			} elseif ( '' !== $source_slug && $source_slug === $candidate_slug ) {
				$score = 90;
				$reason = 'exact_slug';
			} else {
				$candidate_tokens = self::tokens( ! empty( $candidate['tokens'] ) ? $candidate['tokens'] : $candidate_path );
				$similarity = self::token_similarity( $source_tokens, $candidate_tokens );
				$score = (int) round( $similarity * 75 );
				$reason = $score >= 60 ? 'similar_keywords' : 'partial_slug';

				if ( ! empty( $candidate['post_type'] ) && self::path_mentions( $source_path, $candidate['post_type'] ) ) {
					$score += 10;
					$reason = 'post_type_match';
				}
				if ( self::parent_related( $source_path, $candidate_path ) ) {
					$score += 10;
					$reason = 'parent_page_match';
				}
				if ( in_array( (string) $candidate['source'], array( 'taxonomy_archive', 'post_type_archive' ), true ) && $similarity > 0 ) {
					$score = max( $score, 50 + (int) round( $similarity * 15 ) );
					$reason = 'archive_match';
				}
				if ( 'sitemap' === (string) $candidate['source'] && $score > 0 ) {
					$score += 5;
					$reason = 'sitemap_match';
				}
			}

			$score = min( 100, max( 0, $score + (int) ( (int) $candidate['priority'] / 25 ) ) );
			if ( $score > $best['confidence'] ) {
				$best = array(
					'url'              => (string) $candidate['url'],
					'confidence'       => $score,
					'reason'           => $reason,
					'post_id'          => isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0,
					'post_type'        => isset( $candidate['post_type'] ) ? (string) $candidate['post_type'] : '',
					'destination_type' => isset( $candidate['source'] ) ? (string) $candidate['source'] : 'candidate',
				);
			}

			if ( 100 === $best['confidence'] ) {
				break;
			}
		}

		if ( $best['confidence'] < 50 ) {
			$fallback = Settings::get( 'fallback_url' );
			if ( '' !== $fallback ) {
				$best = array(
					'url'              => esc_url_raw( $fallback ),
					'confidence'       => max( 20, $best['confidence'] ),
					'reason'           => 'fallback',
					'post_id'          => 0,
					'post_type'        => '',
					'destination_type' => 'fallback',
				);
			}
		}

		return $best;
	}

	/**
	 * Should the result auto-create a redirect?
	 *
	 * @param array $result Recommendation.
	 * @return bool
	 */
	private static function should_auto_redirect( array $result ) {
		return 'auto_high_confidence' === Settings::get( 'mode' )
			&& ! Compatibility::has_redirect_tool()
			&& ! empty( $result['url'] )
			&& (int) $result['confidence'] >= (int) Settings::get( 'auto_min_confidence', 90 );
	}

	/**
	 * Token string for a URL/path.
	 *
	 * @param string $value URL/path.
	 * @return string
	 */
	public static function tokens_string( $value ) {
		return implode( ' ', self::tokens( $value ) );
	}

	/**
	 * Tokenize a URL/path/string.
	 *
	 * @param string $value Value.
	 * @return array
	 */
	public static function tokens( $value ) {
		$value = strtolower( (string) $value );
		$parts = wp_parse_url( $value );
		if ( isset( $parts['path'] ) ) {
			$value = $parts['path'];
		}
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$tokens = preg_split( '/\s+/', trim( $value ) );
		$stop = array( 'a', 'an', 'and', 'are', 'as', 'at', 'by', 'for', 'from', 'in', 'is', 'of', 'on', 'or', 'page', 'post', 'the', 'to', 'with', 'www', 'html', 'php' );
		$out = array();
		foreach ( (array) $tokens as $token ) {
			if ( strlen( $token ) < 2 || in_array( $token, $stop, true ) ) {
				continue;
			}
			$out[] = $token;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Jaccard-ish token similarity.
	 *
	 * @param array $a A tokens.
	 * @param array $b B tokens.
	 * @return float 0..1
	 */
	private static function token_similarity( array $a, array $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$intersection = array_intersect( $a, $b );
		$union = array_unique( array_merge( $a, $b ) );
		if ( empty( $union ) ) {
			return 0.0;
		}
		return count( $intersection ) / count( $union );
	}

	/**
	 * Whether the source path mentions a post type segment.
	 *
	 * @param string $path      Source path.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function path_mentions( $path, $post_type ) {
		$post_type = sanitize_title( $post_type );
		return '' !== $post_type && false !== strpos( trim( $path, '/' ), $post_type );
	}

	/**
	 * Whether two paths share parent-child context.
	 *
	 * @param string $source    Source path.
	 * @param string $candidate Candidate path.
	 * @return bool
	 */
	private static function parent_related( $source, $candidate ) {
		$s_parts = array_values( array_filter( explode( '/', trim( $source, '/' ) ) ) );
		$c_parts = array_values( array_filter( explode( '/', trim( $candidate, '/' ) ) ) );
		if ( count( $s_parts ) < 2 || count( $c_parts ) < 1 ) {
			return false;
		}
		$source_parent = $s_parts[0];
		return in_array( $source_parent, $c_parts, true ) || ( isset( $c_parts[0] ) && $source_parent === $c_parts[0] );
	}
}

