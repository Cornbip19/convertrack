<?php
/**
 * 404 redirect recommendation engine.
 *
 * Resolution runs in tiers, highest authority first. Only when every
 * authoritative signal misses does it fall back to fuzzy scoring, and a fuzzy
 * result is never allowed to reach the confidence band that auto-creates a 301.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Matcher {

	/**
	 * Confidence floor at which a suggestion is offered as "Recommended".
	 */
	const RECOMMEND_FLOOR = 50;

	/**
	 * Ceiling for anything decided by fuzzy similarity rather than an
	 * authoritative signal. Kept below the slug tier so a guess can never
	 * outrank a real match.
	 */
	const FUZZY_CEILING = 75;

	/**
	 * Floor below which a fuzzy result is noise rather than a suggestion.
	 *
	 * Character-level distance returns something non-zero for almost any pair of
	 * slugs, so without a floor every unmatchable 404 would still be handed a
	 * meaningless destination.
	 */
	const FUZZY_FLOOR = 25;

	/**
	 * Ceiling for taxonomy/post-type archive candidates. Below RECOMMEND_FLOOR
	 * so an archive can surface as the best review hint but never ship as a
	 * recommendation on its own.
	 */
	const ARCHIVE_CEILING = 45;

	/**
	 * Stop words dropped when tokenizing a path.
	 *
	 * @var array
	 */
	private static $stop_words = array( 'a', 'an', 'and', 'are', 'as', 'at', 'by', 'for', 'from', 'in', 'is', 'of', 'on', 'or', 'page', 'post', 'the', 'to', 'with', 'www', 'html', 'php' );

	/**
	 * Process a batch of unresolved events.
	 *
	 * @param int $limit Batch limit.
	 * @return array
	 */
	public static function process_batch( $limit = 0 ) {
		if ( ! Settings::recommendations_enabled() ) {
			return array( 'processed' => 0, 'auto_created' => 0, 'failed' => 0, 'pending' => false, 'skipped' => true );
		}

		if ( Database::valid_url_count() < 1 ) {
			Sitemap_Source::refresh();
		}

		$limit = $limit > 0 ? $limit : (int) Settings::get( 'recommendation_batch', 50 );
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'convertrack-404-', true ) );
		$rows  = Database::claim_recommendations( $limit, $owner );
		if ( is_wp_error( $rows ) ) {
			Logger::error( 'matcher', '404 recommendation batch could not claim work.', array( 'error' => $rows->get_error_message() ) );
			return array( 'processed' => 0, 'auto_created' => 0, 'failed' => 1, 'pending' => Database::has_pending_recommendations(), 'skipped' => false, 'error' => $rows->get_error_message() );
		}

		$processed = 0;
		$auto_created = 0;
		$failed = 0;

		foreach ( $rows as $row ) {
			try {
				$result = self::recommend( $row );
				$saved  = Database::save_recommendation(
					(int) $row['id'],
					$result,
					$owner,
					isset( $row['recommendation_generation'] ) ? (int) $row['recommendation_generation'] : Database::RECOMMENDATION_GENERATION
				);
				if ( is_wp_error( $saved ) ) {
					Database::fail_recommendation( (int) $row['id'], $owner, $saved->get_error_message() );
					$failed++;
					continue;
				}
				$processed++;
			} catch ( \Throwable $error ) {
				Database::fail_recommendation( (int) $row['id'], $owner, $error->getMessage() );
				$failed++;
				continue;
			}

			if ( self::should_auto_redirect( $result ) ) {
				$created = Redirector::create_from_event( (int) $row['id'], true );
				if ( ! is_wp_error( $created ) && $created ) {
					$auto_created++;
				}
			}
		}

		$pending = Database::has_pending_recommendations();
		Logger::info( 'matcher', '404 recommendation batch completed.', array( 'processed' => $processed, 'auto_created' => $auto_created, 'failed' => $failed, 'pending' => $pending ) );
		return array( 'processed' => $processed, 'auto_created' => $auto_created, 'failed' => $failed, 'pending' => $pending, 'skipped' => false );
	}

	/**
	 * Build a recommendation for one event row.
	 *
	 * @param array      $event      Event row.
	 * @param array|null $candidates Optional candidate snapshot for fuzzy scoring.
	 * @return array
	 */
	public static function recommend( array $event, $candidates = null ) {
		$source_path = isset( $event['path'] ) ? (string) $event['path'] : '';
		$empty = array(
			'url'              => '',
			'confidence'       => 0,
			'reason'           => '',
			'post_id'          => 0,
			'post_type'        => '',
			'destination_type' => '',
		);
		if ( '' === $source_path ) {
			return $empty;
		}

		$variants = self::path_variants( $source_path );

		// Braces are required: self::$tier() would be parsed as a static property.
		foreach ( array( 'match_exact_path', 'match_renamed_slug', 'match_normalized_path', 'match_slug' ) as $tier ) {
			$hit = self::{$tier}( $source_path, $variants );
			if ( is_array( $hit ) && '' !== $hit['url'] ) {
				return $hit;
			}
		}

		// Nothing authoritative resolved. Before guessing, check whether the URL
		// used to be real: a draft or trashed post means the honest answer is
		// "needs review", not the best fuzzy guess or the homepage.
		$unpublished = self::match_unpublished( $variants );
		$fuzzy       = self::match_fuzzy( $source_path, $variants, $candidates );

		// A published ancestor of a since-unpublished post is better evidence than
		// a weak guess, but a strong fuzzy match still deserves to win. Ties go to
		// the unpublished signal because it is grounded in real content.
		$best = null;
		foreach ( array( $unpublished, $fuzzy ) as $option ) {
			if ( is_array( $option ) && '' !== $option['url'] && ( null === $best || $option['confidence'] > $best['confidence'] ) ) {
				$best = $option;
			}
		}
		if ( null !== $best ) {
			return $best;
		}

		// No destination anywhere. If the URL was real once, say so rather than
		// papering over it with a fallback.
		if ( is_array( $unpublished ) ) {
			return $unpublished;
		}

		// Only a genuinely empty result may fall back. Overwriting a real
		// low-confidence candidate with the fallback would throw away the very
		// answer the operator needs to see.
		$fallback = (string) Settings::get( 'fallback_url', '' );
		if ( '' !== trim( $fallback ) ) {
			return array(
				'url'              => esc_url_raw( $fallback ),
				'confidence'       => 15,
				'reason'           => 'fallback',
				'post_id'          => 0,
				'post_type'        => '',
				'destination_type' => 'fallback',
			);
		}

		return $empty;
	}

	/**
	 * Tier 1 — the exact path exists as a known-good URL.
	 *
	 * @param string $source_path Normalized source path.
	 * @param array  $variants    Path variants.
	 * @return array|null
	 */
	private static function match_exact_path( $source_path, array $variants ) {
		$candidate = Database::candidate_by_path( $source_path );
		return $candidate ? self::from_candidate( $candidate, 100, 'exact_path' ) : null;
	}

	/**
	 * Tier 2 — the slug was renamed, per core's '_wp_old_slug' / '_wp_old_date'.
	 *
	 * Core's own wp_old_slug_redirect() runs at template_redirect priority 10,
	 * ahead of this module's detector, so the simple case is usually redirected
	 * before it is ever recorded. This tier covers what that gate misses: the
	 * existing event backlog, custom post types whose post_type query var was
	 * guessed wrong, permalink structure changes, and paths that never resolve
	 * to a 'name' query var. It cannot help pages — core never records
	 * '_wp_old_slug' for hierarchical post types.
	 *
	 * @param string $source_path Normalized source path.
	 * @param array  $variants    Path variants.
	 * @return array|null
	 */
	private static function match_renamed_slug( $source_path, array $variants ) {
		$slug = $variants['slug'];
		if ( '' === $slug ) {
			return null;
		}

		$rows = self::allowed_post_rows( Database::posts_by_old_slug( $slug ) );
		$ambiguous = count( $rows ) > 1;
		if ( $ambiguous && ! empty( $variants['date']['year'] ) ) {
			$narrowed = array();
			foreach ( $rows as $row ) {
				if ( self::post_date_matches( (string) $row['post_date'], $variants['date'] ) ) {
					$narrowed[] = $row;
				}
			}
			if ( ! empty( $narrowed ) ) {
				$rows      = $narrowed;
				$ambiguous = count( $rows ) > 1;
			}
		}

		if ( ! empty( $rows ) ) {
			$hit = self::from_post( (int) $rows[0]['ID'], $ambiguous ? 70 : 96, $ambiguous ? 'renamed_slug_ambiguous' : 'renamed_slug', $variants );
			if ( $hit ) {
				return $hit;
			}
		}

		$dated = self::allowed_post_rows( Database::posts_by_old_date( $slug, $variants['date'] ) );
		if ( ! empty( $dated ) ) {
			$hit = self::from_post( (int) $dated[0]['ID'], count( $dated ) > 1 ? 70 : 90, 'renamed_date', $variants );
			if ( $hit ) {
				return $hit;
			}
		}

		return null;
	}

	/**
	 * Tier 3 — the path matches once mechanical decoration is stripped.
	 *
	 * @param string $source_path Normalized source path.
	 * @param array  $variants    Path variants.
	 * @return array|null
	 */
	private static function match_normalized_path( $source_path, array $variants ) {
		if ( $variants['core'] === $source_path || '/' === $variants['core'] ) {
			return null;
		}

		$confidence = self::normalized_confidence( $variants['stripped'] );

		$candidate = Database::candidate_by_path( $variants['core'] );
		if ( $candidate ) {
			return self::from_candidate( $candidate, $confidence, 'normalized_path' );
		}

		// Hierarchical content is absent from '_wp_old_slug' entirely, so resolve
		// the stripped path against the page tree directly.
		$page = get_page_by_path( trim( $variants['core'], '/' ), OBJECT, self::hierarchical_post_types() );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$hit = self::from_post( (int) $page->ID, min( 90, $confidence ), 'page_path', $variants );
			if ( $hit ) {
				return $hit;
			}
		}

		return null;
	}

	/**
	 * Tier 4 — a known-good URL shares this slug.
	 *
	 * Scored at 85 rather than the old 90 so a bare slug collision sits below the
	 * default auto-redirect threshold: only an authoritative tier may auto-301.
	 *
	 * @param string $source_path Normalized source path.
	 * @param array  $variants    Path variants.
	 * @return array|null
	 */
	private static function match_slug( $source_path, array $variants ) {
		if ( '' === $variants['slug'] ) {
			return null;
		}

		$rows = Database::candidates_by_slug( $variants['slug'] );
		if ( empty( $rows ) ) {
			return null;
		}
		if ( 1 === count( $rows ) ) {
			return self::from_candidate( $rows[0], 85, 'exact_slug' );
		}

		// Several URLs share the slug under different parents. Prefer the one
		// whose ancestry lines up with the broken URL, and score it low enough
		// that a human confirms the choice.
		$best  = $rows[0];
		$score = -1;
		foreach ( $rows as $row ) {
			$overlap = self::segment_overlap( $variants['core'], isset( $row['path'] ) ? (string) $row['path'] : '' );
			if ( $overlap > $score ) {
				$score = $overlap;
				$best  = $row;
			}
		}
		return self::from_candidate( $best, 62, 'ambiguous_slug' );
	}

	/**
	 * Tier 5 — the slug belongs to a post that is no longer published.
	 *
	 * Returns no destination unless a published ancestor exists, but records why
	 * the row needs a human so the reason surfaces in the row details.
	 *
	 * @param array $variants Path variants.
	 * @return array|null
	 */
	private static function match_unpublished( array $variants ) {
		if ( '' === $variants['slug'] ) {
			return null;
		}

		$rows = self::allowed_post_rows( Database::unpublished_posts_by_slug( $variants['slug'] ) );
		if ( empty( $rows ) ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$parent = isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0;
			if ( $parent > 0 && 'publish' === get_post_status( $parent ) ) {
				$hit = self::from_post( $parent, 55, 'unpublished_parent', $variants );
				if ( $hit ) {
					return $hit;
				}
			}
		}

		return array(
			'url'              => '',
			'confidence'       => 0,
			'reason'           => 'unpublished_source',
			'post_id'          => 0,
			'post_type'        => isset( $rows[0]['post_type'] ) ? (string) $rows[0]['post_type'] : '',
			'destination_type' => '',
		);
	}

	/**
	 * Tier 6 — fuzzy similarity over a bounded candidate set.
	 *
	 * @param string     $source_path Normalized source path.
	 * @param array      $variants    Path variants.
	 * @param array|null $candidates  Optional candidate snapshot.
	 * @return array|null
	 */
	private static function match_fuzzy( $source_path, array $variants, $candidates = null ) {
		$source_tokens = self::tokens( $variants['core'] );
		if ( ! is_array( $candidates ) ) {
			$candidates = Database::candidates_by_tokens( self::rank_tokens( $source_tokens ) );
			if ( empty( $candidates ) ) {
				$candidates = Database::valid_candidates();
			}
		}

		$best     = null;
		$best_key = array( 0, 0, 0 );
		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['url'] ) || empty( $candidate['path'] ) ) {
				continue;
			}
			$candidate_path = (string) $candidate['path'];
			if ( $candidate_path === $variants['core'] || $candidate_path === $source_path ) {
				continue; // An exact path is an authoritative tier's job, not a guess.
			}
			if ( '/' === $candidate_path ) {
				continue; // The homepage is a fallback destination, never a fuzzy match.
			}

			$candidate_slug   = isset( $candidate['slug'] ) ? (string) $candidate['slug'] : self::path_slug( $candidate_path );
			$candidate_tokens = self::tokens( ! empty( $candidate['tokens'] ) ? $candidate['tokens'] : $candidate_path );
			$similarity       = self::similarity( $source_tokens, $candidate_tokens, $variants['slug'], $candidate_slug );
			if ( $similarity <= 0 ) {
				continue;
			}

			$score  = (int) round( $similarity * self::FUZZY_CEILING );
			$reason = $score >= 60 ? 'similar_keywords' : 'partial_slug';

			if ( self::post_type_segment_matches( $variants['core'], $candidate ) ) {
				$score += 6;
				$reason = 'post_type_match';
			}
			if ( self::parent_segment_matches( $variants['core'], $candidate_path ) ) {
				$score += 8;
				$reason = 'parent_page_match';
			}

			// Archives used to be floored to 50-65, which let one shared token
			// with a category archive outrank a genuine post. They are now capped
			// instead, so they can only ever be a review hint.
			if ( in_array( (string) $candidate['source'], array( 'taxonomy_archive', 'post_type_archive' ), true ) ) {
				$score  = min( self::ARCHIVE_CEILING, $score );
				$reason = 'archive_match';
			}

			$score = max( 0, min( self::FUZZY_CEILING, $score ) );
			if ( $score < self::FUZZY_FLOOR ) {
				continue;
			}

			// Deterministic tie-break: score, then candidate priority, then the
			// oldest row, which favours canonical content over a recent duplicate.
			$key = array( $score, isset( $candidate['priority'] ) ? (int) $candidate['priority'] : 0, - (int) $candidate['id'] );
			if ( null === $best || $key > $best_key ) {
				$best_key = $key;
				$best     = self::from_candidate( $candidate, $score, $reason );
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
			&& ! empty( $result['url'] )
			&& (int) $result['confidence'] >= (int) Settings::get( 'auto_min_confidence', 90 );
	}

	/**
	 * Split a path into its meaningful core plus the decoration that was removed.
	 *
	 * Paths arrive lowercased with a trailing slash from normalize_source().
	 *
	 * @param string $path Normalized path.
	 * @return array {core, slug, stripped, date}
	 */
	public static function path_variants( $path ) {
		$core     = '/' . trim( (string) $path, '/' );
		$core     = '/' === $core ? '/' : $core . '/';
		$stripped = array();
		$date     = array( 'year' => 0, 'month' => 0, 'day' => 0 );

		// index.php in the path is a rewrite artefact, never part of a permalink.
		$without_index = preg_replace( '#/index\.php(?=/)#', '', $core );
		if ( null !== $without_index && $without_index !== $core ) {
			$core       = '' === $without_index ? '/' : $without_index;
			$stripped[] = 'index';
		}

		// Trailing decoration can repeat (e.g. /feed/ inside /page/2/), so loop.
		$patterns = array(
			'paged'   => '#/page/\d+/$#',
			'comment' => '#/comment-page-\d+/$#',
			'variant' => '#/(?:amp|embed|trackback|feed(?:/(?:atom|rdf|rss|rss2))?)/$#',
		);
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$changed = false;
			foreach ( $patterns as $label => $pattern ) {
				$next = preg_replace( $pattern, '/', $core );
				if ( null !== $next && $next !== $core ) {
					if ( 'paged' === $label && preg_match( '#/page/(\d+)/$#', $core, $m ) ) {
						$stripped['paged'] = (int) $m[1];
					} else {
						$stripped[] = $label;
					}
					$core    = $next;
					$changed = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}

		// A file extension on a permalink is almost always a legacy URL.
		$without_ext = preg_replace( '#\.(?:html?|php|aspx?)/$#', '/', $core );
		if ( null !== $without_ext && $without_ext !== $core ) {
			$core       = $without_ext;
			$stripped[] = 'extension';
		}

		// Leading date segments: keep them for disambiguation, drop them from the
		// path so /2020/05/my-post/ can match /my-post/.
		if ( preg_match( '#^/(\d{4})/(?:(\d{1,2})/)?(?:(\d{1,2})/)?#', $core, $m ) ) {
			$year = (int) $m[1];
			if ( $year >= 1970 && $year <= 2200 ) {
				$remainder = substr( $core, strlen( $m[0] ) );
				if ( '' !== $remainder ) {
					$date['year']  = $year;
					$date['month'] = isset( $m[2] ) ? (int) $m[2] : 0;
					$date['day']   = isset( $m[3] ) ? (int) $m[3] : 0;
					$core          = '/' . $remainder;
					$stripped[]    = 'date';
				}
			}
		}

		$core = preg_replace( '#/+#', '/', $core );
		$core = '/' === $core ? '/' : '/' . trim( $core, '/' ) . '/';

		return array(
			'core'     => $core,
			'slug'     => self::path_slug( $core ),
			'stripped' => $stripped,
			'date'     => $date,
		);
	}

	/**
	 * Last path segment, sanitized the same way candidate slugs are stored.
	 *
	 * upsert_valid_url() stores sanitize_title( $slug ), so an unsanitized
	 * source slug could never match one; basename() is also the wrong tool for
	 * URL paths since it treats a backslash as a separator on Windows.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function path_slug( $path ) {
		$parts = array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return '';
		}
		return sanitize_title( $parts[ count( $parts ) - 1 ] );
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
		$out = array();
		foreach ( (array) $tokens as $token ) {
			if ( strlen( $token ) < 2 || in_array( $token, self::$stop_words, true ) ) {
				continue;
			}
			$out[] = $token;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Combined token and character similarity, 0..1.
	 *
	 * Token coverage replaces the previous Jaccard index, which divided by the
	 * union and so punished any candidate that was a superset of the source:
	 * /contact/ against /contact-us/ scored 0.5 and fell below the recommend
	 * floor even though it is plainly the right target.
	 *
	 * @param array  $source_tokens    Source tokens.
	 * @param array  $candidate_tokens Candidate tokens.
	 * @param string $source_slug      Source slug.
	 * @param string $candidate_slug   Candidate slug.
	 * @return float
	 */
	private static function similarity( array $source_tokens, array $candidate_tokens, $source_slug, $candidate_slug ) {
		$token = 0.0;
		if ( ! empty( $source_tokens ) && ! empty( $candidate_tokens ) ) {
			$shared   = count( array_intersect( $source_tokens, $candidate_tokens ) );
			$coverage = $shared / count( $source_tokens );
			$density  = $shared / count( $candidate_tokens );
			$token    = ( 0.75 * $coverage ) + ( 0.25 * $density );
		}

		// Character distance catches typos and near-miss slugs, which share no
		// tokens at all and previously scored exactly zero.
		$char = self::slug_similarity( (string) $source_slug, (string) $candidate_slug );

		return max( $token, $char );
	}

	/**
	 * Character-level slug similarity, 0..1.
	 *
	 * @param string $a Slug A.
	 * @param string $b Slug B.
	 * @return float
	 */
	private static function slug_similarity( $a, $b ) {
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		$len = max( strlen( $a ), strlen( $b ) );
		if ( $len < 4 ) {
			return $a === $b ? 1.0 : 0.0;
		}

		// PHP 7.4 levenshtein() returns -1 above 255 bytes; the cap was lifted in
		// PHP 8.0. Fall back to similar_text() so long slugs still score.
		if ( $len <= 255 ) {
			$distance = levenshtein( $a, $b );
			if ( $distance >= 0 ) {
				return max( 0.0, 1 - ( $distance / $len ) );
			}
		}

		$percent = 0.0;
		similar_text( $a, $b, $percent );
		return max( 0.0, min( 1.0, $percent / 100 ) );
	}

	/**
	 * Confidence for a path that only matched after stripping decoration.
	 *
	 * @param array $stripped Labels/values removed by path_variants().
	 * @return int
	 */
	private static function normalized_confidence( array $stripped ) {
		$scores = array(
			'paged'     => 95,
			'comment'   => 95,
			'extension' => 94,
			'index'     => 94,
			'variant'   => 93,
			'date'      => 90,
		);
		$confidence = 95;
		foreach ( $stripped as $key => $value ) {
			$label = is_int( $key ) ? (string) $value : (string) $key;
			if ( isset( $scores[ $label ] ) ) {
				$confidence = min( $confidence, $scores[ $label ] );
			}
		}
		return $confidence;
	}

	/**
	 * Whether a source path segment matches the candidate post type's public slug.
	 *
	 * Replaces a raw substring test against the post type name, which handed +10
	 * to every 'post' candidate for any path containing "post" and to every
	 * 'page' candidate for any path containing "page".
	 *
	 * @param string $path      Source core path.
	 * @param array  $candidate Candidate row.
	 * @return bool
	 */
	private static function post_type_segment_matches( $path, array $candidate ) {
		$post_type = isset( $candidate['post_type'] ) ? (string) $candidate['post_type'] : '';
		if ( '' === $post_type || in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return false;
		}

		$object = get_post_type_object( $post_type );
		$slug   = $post_type;
		if ( $object && ! empty( $object->rewrite['slug'] ) ) {
			$slug = (string) $object->rewrite['slug'];
		}
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		return in_array( $slug, self::segments( $path ), true );
	}

	/**
	 * Whether two paths share a parent segment at the same depth.
	 *
	 * The previous check accepted the source's first segment appearing anywhere
	 * in the candidate, which fires on nearly every row under a
	 * /%category%/%postname%/ permalink structure.
	 *
	 * @param string $source    Source core path.
	 * @param string $candidate Candidate path.
	 * @return bool
	 */
	private static function parent_segment_matches( $source, $candidate ) {
		$s = self::segments( $source );
		$c = self::segments( $candidate );
		if ( count( $s ) < 2 || count( $c ) < 2 ) {
			return false;
		}
		return $s[ count( $s ) - 2 ] === $c[ count( $c ) - 2 ];
	}

	/**
	 * Count of shared leading segments between two paths.
	 *
	 * @param string $source    Source path.
	 * @param string $candidate Candidate path.
	 * @return int
	 */
	private static function segment_overlap( $source, $candidate ) {
		$s = self::segments( $source );
		$c = self::segments( $candidate );
		$shared = 0;
		$max = min( count( $s ), count( $c ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $s[ $i ] !== $c[ $i ] ) {
				break;
			}
			$shared++;
		}
		return $shared;
	}

	/**
	 * Path segments.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	private static function segments( $path ) {
		return array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );
	}

	/**
	 * Tokens ordered longest first — the most selective prefilter terms.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private static function rank_tokens( array $tokens ) {
		usort(
			$tokens,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $tokens;
	}

	/**
	 * Drop post rows whose post type the operator excluded.
	 *
	 * Filtered in PHP rather than in SQL so the bounded LIMIT stays predictable.
	 *
	 * @param array $rows Post rows including post_type.
	 * @return array
	 */
	private static function allowed_post_rows( array $rows ) {
		$excluded = (array) Settings::get( 'exclude_post_types', array() );
		$out = array();
		foreach ( $rows as $row ) {
			$post_type = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
			if ( '' !== $post_type && in_array( $post_type, $excluded, true ) ) {
				continue;
			}
			$out[] = $row;
		}
		return array_values( $out );
	}

	/**
	 * Whether a post date satisfies the date segments taken from the source path.
	 *
	 * @param string $post_date MySQL datetime.
	 * @param array  $date      {year, month, day}.
	 * @return bool
	 */
	private static function post_date_matches( $post_date, array $date ) {
		$stamp = strtotime( $post_date );
		if ( ! $stamp ) {
			return false;
		}
		if ( ! empty( $date['year'] ) && (int) gmdate( 'Y', $stamp ) !== (int) $date['year'] ) {
			return false;
		}
		if ( ! empty( $date['month'] ) && (int) gmdate( 'n', $stamp ) !== (int) $date['month'] ) {
			return false;
		}
		if ( ! empty( $date['day'] ) && (int) gmdate( 'j', $stamp ) !== (int) $date['day'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Public hierarchical post types, for page-tree path resolution.
	 *
	 * @return array
	 */
	private static function hierarchical_post_types() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( is_post_type_hierarchical( $post_type ) ) {
				$types[] = $post_type;
			}
		}
		return empty( $types ) ? array( 'page' ) : $types;
	}

	/**
	 * Shape a result from a valid_urls candidate row.
	 *
	 * @param array  $candidate  Candidate row.
	 * @param int    $confidence Confidence.
	 * @param string $reason     Match reason.
	 * @return array
	 */
	private static function from_candidate( array $candidate, $confidence, $reason ) {
		return array(
			'url'              => (string) $candidate['url'],
			'confidence'       => max( 0, min( 100, (int) $confidence ) ),
			'reason'           => $reason,
			'post_id'          => isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0,
			'post_type'        => isset( $candidate['post_type'] ) ? (string) $candidate['post_type'] : '',
			'destination_type' => isset( $candidate['source'] ) ? (string) $candidate['source'] : 'candidate',
		);
	}

	/**
	 * Shape a result from a post ID, re-applying any stripped pagination.
	 *
	 * @param int    $post_id    Post ID.
	 * @param int    $confidence Confidence.
	 * @param string $reason     Match reason.
	 * @param array  $variants   Path variants.
	 * @return array|null
	 */
	private static function from_post( $post_id, $confidence, $reason, array $variants ) {
		$post_id = (int) $post_id;
		$url     = $post_id ? get_permalink( $post_id ) : '';
		if ( ! $url ) {
			return null;
		}
		if ( ! empty( $variants['stripped']['paged'] ) ) {
			$url = user_trailingslashit( trailingslashit( $url ) . 'page/' . (int) $variants['stripped']['paged'] );
		}

		return array(
			'url'              => (string) $url,
			'confidence'       => max( 0, min( 100, (int) $confidence ) ),
			'reason'           => $reason,
			'post_id'          => $post_id,
			'post_type'        => (string) get_post_type( $post_id ),
			'destination_type' => 'post',
		);
	}
}
