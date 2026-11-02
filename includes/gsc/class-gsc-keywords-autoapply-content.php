<?php
/**
 * Keyword Auto-Apply — content insertions.
 *
 * Pipeline: scan -> (writer drafts one sentence) -> human approves -> apply
 * -> measure -> keep or revert.
 *
 *  - scan:    picks at most one missing keyword per page from the analyzed
 *             add_to_content / promote_to_headings / add_location
 *             opportunities, verified against the LIVE rendered page (the
 *             analyzer only sees post_content, so it misses theme-rendered
 *             copy), and queues it as "needs_draft".
 *  - draft:   a writer (WP-CLI or wp-admin) supplies one natural sentence and
 *             the paragraph to extend. The sentence is validated: it must
 *             carry the keyword, stay short, not repeat it, keep page density
 *             sane, and not be a near-copy of a sentence used on another page.
 *  - approve: appends the sentence to the end of that paragraph. The full
 *             previous post_content is snapshotted (revisions may be off).
 *  - measure: after the evaluation window the page's Search Console clicks
 *             and CTR are compared with the baseline; a loss reverts it.
 *
 * Nothing is rewritten or deleted: an insertion is one sentence, removable
 * by exact string, one per page per cooldown.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Autoapply_Content {

	const DB_VERSION     = 1;
	const DB_OPTION      = 'convertrack_autoapply_content_db';
	const CODES          = array( 'add_to_content', 'promote_to_headings', 'add_location' );
	const ACTIVE         = array( 'needs_draft', 'drafted', 'approved', 'applied' );
	const NEAR_ME_WORDS  = array( 'near', 'me', 'nearby', 'closest', 'open', 'now', 'today', 'best', 'top', 'cheap', 'affordable', 'prices', 'price', 'cost', 'costs' );
	const MIN_WORDS      = 8;
	const MAX_WORDS      = 40;
	const MAX_SIMILARITY = 0.3;

	/* ------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/** @return string */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'convertrack_autoapply_content';
	}

	/**
	 * Create/upgrade the queue table when needed.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				keyword varchar(255) NOT NULL DEFAULT '',
				core varchar(255) NOT NULL DEFAULT '',
				code varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'needs_draft',
				paragraph_index int(11) NOT NULL DEFAULT -1,
				paragraph_text longtext NULL,
				draft text NULL,
				inserted text NULL,
				kw_stats text NULL,
				baseline text NULL,
				evaluation text NULL,
				snapshot longtext NULL,
				note text NULL,
				drafted_by varchar(60) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				applied_at datetime NULL,
				ts_applied bigint(20) unsigned NOT NULL DEFAULT 0,
				ts_closed bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY post_id (post_id)
			) $charset;"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * One queue item.
	 *
	 * @param int $id Item id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Items by status (newest first).
	 *
	 * @param string|array $status Status or list.
	 * @param int          $limit  Max rows.
	 * @return array[]
	 */
	public static function items( $status, $limit = 200 ) {
		global $wpdb;
		$status = (array) $status;
		$in     = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		$params = array_merge( $status, array( (int) $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status IN ($in) ORDER BY updated_at DESC, id DESC LIMIT %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Status counts.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Update fields on an item.
	 *
	 * @param int   $id     Item id.
	 * @param array $fields Fields (arrays are JSON-encoded).
	 */
	private static function update( $id, array $fields ) {
		global $wpdb;
		foreach ( array( 'kw_stats', 'baseline', 'evaluation' ) as $json ) {
			if ( isset( $fields[ $json ] ) && is_array( $fields[ $json ] ) ) {
				$fields[ $json ] = wp_json_encode( $fields[ $json ] );
			}
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode( array $row ) {
		foreach ( array( 'kw_stats', 'baseline', 'evaluation' ) as $json ) {
			$row[ $json ] = $row[ $json ] ? json_decode( $row[ $json ], true ) : array();
		}
		return $row;
	}

	/**
	 * Whether a page had a content insertion applied within N days.
	 *
	 * @param int $post_id Post id.
	 * @param int $days    Days.
	 * @return bool
	 */
	public static function recently_changed( $post_id, $days ) {
		global $wpdb;
		if ( (int) get_option( self::DB_OPTION, 0 ) < 1 ) {
			return false;
		}
		$since = time() - (int) $days * DAY_IN_SECONDS;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE post_id = %d AND ts_applied > %d LIMIT 1', (int) $post_id, $since ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------------
	 * Scan
	 * ------------------------------------------------------------------ */

	/**
	 * Queue new content opportunities (one per page).
	 *
	 * @param array $s     Auto-Apply settings.
	 * @param int   $limit Max new items.
	 * @return array { queued: array[], skipped: array[] }
	 */
	public static function scan( array $s, $limit = 10 ) {
		global $wpdb;
		self::maybe_install();

		$keywords = Keywords_Database::keywords_table();
		$likes    = array();
		foreach ( self::CODES as $code ) {
			$likes[] = $wpdb->prepare( 'recommendations LIKE %s', '%"' . $code . '"%' );
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, post_id, query, impressions, clicks, ctr, position, labels, recommendations
				FROM $keywords
				WHERE range_key = %s AND analysis_state = 'done' AND post_id > 0 AND (" . implode( ' OR ', $likes ) . ')
				ORDER BY impressions DESC, position ASC',
				$s['range']
			),
			ARRAY_A
		);

		$by = array();
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row['post_id'] ][] = $row;
		}

		$active = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT post_id FROM " . self::table() . " WHERE status IN ('needs_draft','drafted','approved','applied')" ) ); // phpcs:ignore WordPress.DB
		$out    = array(
			'queued'  => array(),
			'skipped' => array(),
		);

		foreach ( $by as $pid => $kws ) {
			if ( count( $out['queued'] ) >= $limit ) {
				break;
			}
			$reason = self::page_blocker( $pid, $s, $active );
			if ( '' !== $reason ) {
				$out['skipped'][] = array( 'post_id' => $pid, 'reason' => $reason );
				continue;
			}

			$pick = self::pick_keyword( $pid, $kws, $s );
			if ( is_string( $pick ) ) {
				$out['skipped'][] = array( 'post_id' => $pid, 'reason' => $pick );
				continue;
			}

			$now = current_time( 'mysql' );
			$wpdb->insert( // phpcs:ignore WordPress.DB
				self::table(),
				array(
					'post_id'    => $pid,
					'keyword'    => $pick['query'],
					'core'       => $pick['core'],
					'code'       => $pick['code'],
					'status'     => 'needs_draft',
					'kw_stats'   => wp_json_encode(
						array(
							'impressions' => (int) $pick['impressions'],
							'clicks'      => (int) $pick['clicks'],
							'ctr'         => round( (float) $pick['ctr'] * 100, 2 ),
							'position'    => round( (float) $pick['position'], 1 ),
						)
					),
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
			$active[]        = $pid;
			$out['queued'][] = array(
				'id'      => (int) $wpdb->insert_id,
				'post_id' => $pid,
				'keyword' => $pick['query'],
				'core'    => $pick['core'],
			);
		}
		return $out;
	}

	/**
	 * Why a page cannot take a content insertion now ('' when it can).
	 *
	 * @param int   $pid    Post id.
	 * @param array $s      Settings.
	 * @param array $active Pages with an open item.
	 * @return string
	 */
	private static function page_blocker( $pid, array $s, array $active ) {
		$post = get_post( $pid );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 'not published';
		}
		if ( ! in_array( $post->post_type, (array) Keywords_Settings::get( 'selected_post_types', array() ), true ) ) {
			return 'post type not tracked';
		}
		if ( $s['exclude_front'] && $pid === (int) get_option( 'page_on_front' ) ) {
			return 'front page is excluded';
		}
		if ( in_array( $pid, (array) $s['exclude_ids'], true ) ) {
			return 'excluded in settings';
		}
		$robots = (array) get_post_meta( $pid, 'rank_math_robots', true );
		if ( get_post_meta( $pid, '_convertrack_noindex_intentional', true ) || in_array( 'noindex', $robots, true ) ) {
			return 'page is noindexed';
		}
		if ( in_array( $pid, $active, true ) ) {
			return 'already has an open content item';
		}
		$cool = (int) $s['cooldown_days'];
		if ( self::recently_changed( $pid, $cool ) ) {
			return 'content changed recently (cooldown)';
		}
		// Don't stack a content edit on a fresh title change: the measurement
		// could not tell which one moved the numbers.
		foreach ( array_reverse( Keywords_Autoapply::history( $pid ) ) as $entry ) {
			if ( in_array( $entry['status'], array( 'applied' ), true ) || ( time() - (int) $entry['ts'] ) < $cool * DAY_IN_SECONDS ) {
				return 'title changed recently';
			}
			break;
		}
		if ( count( self::paragraphs( $post->post_content ) ) < 1 ) {
			return 'no editable paragraph in the post content';
		}
		return '';
	}

	/**
	 * Best keyword for a page, or why none qualifies.
	 *
	 * @param int   $pid Post id.
	 * @param array $kws Keyword rows (impressions desc).
	 * @param array $s   Settings.
	 * @return array|string
	 */
	private static function pick_keyword( $pid, array $kws, array $s ) {
		$post        = get_post( $pid );
		$focus       = (string) get_post_meta( $pid, 'rank_math_focus_keyword', true );
		$meta        = Keywords_SEO_Meta::for_post( $pid );
		$page_places = Keywords_Autoapply::match_places( Keywords_Text::fold( $meta['title'] . ' ' . $focus . ' ' . $post->post_name ) );
		$live        = null;
		$reasons     = array();

		// Keywords already rejected, undone or reverted on this page stay closed.
		global $wpdb;
		$closed = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT core FROM ' . self::table() . " WHERE post_id = %d AND status IN ('rejected','undone','reverted')", $pid ) ); // phpcs:ignore WordPress.DB

		foreach ( $kws as $kw ) {
			$codes = array();
			foreach ( (array) json_decode( (string) $kw['recommendations'], true ) as $rec ) {
				$codes[] = isset( $rec['code'] ) ? $rec['code'] : '';
			}
			$code = current( array_intersect( self::CODES, $codes ) );
			if ( ! $code ) {
				continue;
			}

			$reject = self::reject( $kw, $s, $page_places );
			if ( '' !== $reject ) {
				$reasons[ $reject ] = true;
				continue;
			}

			$core = self::core( (string) $kw['query'] );
			if ( count( Keywords_Text::tokens( $core ) ) < 2 ) {
				$reasons['only "near me"-style words'] = true;
				continue;
			}
			if ( in_array( $core, $closed, true ) ) {
				$reasons['previously rejected or undone'] = true;
				continue;
			}

			if ( null === $live ) {
				$live = self::live_text( $pid );
				if ( '' === $live ) {
					return 'could not load the live page to verify';
				}
			}
			if ( Keywords_Text::contains_phrase( $live, $core ) ) {
				$reasons['already on the live page'] = true;
				continue;
			}

			$kw['core'] = $core;
			$kw['code'] = $code;
			return $kw;
		}

		$reasons = array_keys( $reasons );
		return $reasons ? 'no eligible keyword (' . implode( '; ', array_slice( $reasons, 0, 3 ) ) . ')' : 'no eligible keyword';
	}

	/**
	 * Keyword rules for content (looser rank range than titles: content is
	 * what lifts page-two terms; everything else matches the title rules).
	 *
	 * @param array $kw          Keyword row.
	 * @param array $s           Settings.
	 * @param array $page_places Places the page targets.
	 * @return string
	 */
	private static function reject( array $kw, array $s, array $page_places ) {
		$query  = (string) $kw['query'];
		$labels = json_decode( (string) $kw['labels'], true );
		$labels = is_array( $labels ) ? $labels : array();
		$folded = Keywords_Text::fold( $query );
		$tokens = Keywords_Text::tokens( $folded );

		if ( (int) $kw['impressions'] < (int) $s['content_min_impressions'] ) {
			return 'below the impressions threshold';
		}
		if ( (float) $kw['position'] > (float) $s['content_max_position'] ) {
			return 'ranks too low to benefit';
		}
		if ( array_intersect( $labels, array( 'branded', 'competitor', 'question' ) ) ) {
			return 'branded, competitor or question keyword';
		}
		if ( preg_match( '/["\x{201C}\x{201D}:]|(^|\s)-\w|\bsite\b|https?|www\./iu', $query ) || preg_match( '/[^\x20-\x7E]/', $query ) ) {
			return 'search-operator or non-English query';
		}
		if ( array_intersect( $tokens, (array) $s['blocked_words'] ) ) {
			return 'keyword signals an intent the page does not serve';
		}
		if ( count( $tokens ) > 7 ) {
			return 'too long to work into a sentence';
		}
		if ( array_diff( Keywords_Autoapply::match_places( $folded ), $page_places ) ) {
			return 'keyword targets a different location';
		}
		return '';
	}

	/**
	 * The phrase that actually goes into the copy: the query minus
	 * "near me", "best", price words and similar.
	 *
	 * @param string $query Query.
	 * @return string Folded phrase.
	 */
	public static function core( $query ) {
		$tokens = array_values( array_diff( Keywords_Text::tokens( Keywords_Text::fold( $query ) ), self::NEAR_ME_WORDS, array( 'fl', 'florida' ) ) );
		return implode( ' ', $tokens );
	}

	/**
	 * Folded visible text of the live page.
	 *
	 * @param int $pid Post id.
	 * @return string
	 */
	public static function live_text( $pid ) {
		$response = wp_remote_get(
			add_query_arg( 'cvtrk_verify', wp_rand( 1000, 999999 ), get_permalink( $pid ) ),
			array(
				'timeout'    => 20,
				'user-agent' => 'Mozilla/5.0 (compatible; Convertrack-Verify; +' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$html = (string) wp_remote_retrieve_body( $response );
		$html = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', ' ', $html );
		return Keywords_Text::fold( wp_strip_all_tags( (string) $html ) );
	}

	/**
	 * Top-level paragraphs of post_content that can safely be extended:
	 * plain prose of at least 20 words.
	 *
	 * @param string $content Post content.
	 * @return array[] { index, html, text, words }
	 */
	public static function paragraphs( $content ) {
		$out = array();
		if ( ! preg_match_all( '#<p>(.*?)</p>#s', (string) $content, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $i => $inner ) {
			$text  = trim( wp_strip_all_tags( $inner ) );
			$words = str_word_count( $text );
			if ( $words < 20 || preg_match( '/[?]\s*$/', $text ) || preg_match( '/<(img|iframe|script|form|input|button)/i', $inner ) ) {
				continue;
			}
			$out[] = array(
				'index' => (int) $i,
				'html'  => $inner,
				'text'  => $text,
				'words' => $words,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Draft
	 * ------------------------------------------------------------------ */

	/**
	 * Attach a drafted sentence to an item after validating it.
	 *
	 * @param int    $id        Item id.
	 * @param int    $paragraph Paragraph index (from paragraphs()).
	 * @param string $sentence  Plain-text sentence.
	 * @param string $by        Who drafted it.
	 * @return true|string True, or why it was rejected.
	 */
	public static function draft( $id, $paragraph, $sentence, $by = 'cli' ) {
		$item = self::get( $id );
		if ( ! $item || ! in_array( $item['status'], array( 'needs_draft', 'drafted' ), true ) ) {
			return 'Item is not waiting for a draft.';
		}
		$post = get_post( (int) $item['post_id'] );
		if ( ! $post ) {
			return 'Post not found.';
		}

		$sentence = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $sentence, ENT_QUOTES, 'UTF-8' ) ) ) );
		$check    = self::validate( $item, $post, (int) $paragraph, $sentence );
		if ( true !== $check ) {
			return $check;
		}

		$para = self::paragraph_at( $post->post_content, (int) $paragraph );
		self::update(
			$id,
			array(
				'status'          => 'drafted',
				'paragraph_index' => (int) $paragraph,
				'paragraph_text'  => $para,
				'draft'           => $sentence,
				'drafted_by'      => sanitize_text_field( $by ),
				'note'            => '',
			)
		);
		return true;
	}

	/**
	 * Validation rules for a drafted sentence.
	 *
	 * @param array    $item      Item.
	 * @param \WP_Post $post      Post.
	 * @param int      $paragraph Paragraph index.
	 * @param string   $sentence  Sentence.
	 * @return true|string
	 */
	private static function validate( array $item, $post, $paragraph, $sentence ) {
		$valid = false;
		foreach ( self::paragraphs( $post->post_content ) as $p ) {
			if ( $p['index'] === $paragraph ) {
				$valid = true;
			}
		}
		if ( ! $valid ) {
			return 'That paragraph cannot be extended (pick one listed for this item).';
		}

		$words = str_word_count( $sentence );
		if ( $words < self::MIN_WORDS || $words > self::MAX_WORDS ) {
			return sprintf( 'The sentence must be %d-%d words (it has %d).', self::MIN_WORDS, self::MAX_WORDS, $words );
		}
		if ( ! preg_match( '/[.!]$/', $sentence ) ) {
			return 'The sentence must end with a full stop.';
		}
		if ( preg_match( '/[<>\[\]{}]|https?:|www\./i', $sentence ) ) {
			return 'Plain text only: no markup, links or shortcodes.';
		}

		// It must carry every word of the keyword (any order, plural/-ing ok).
		$folded = Keywords_Text::fold( $sentence );
		$need   = Keywords_Autoapply::roots( (string) $item['core'] );
		$have   = Keywords_Autoapply::roots( $folded );
		$miss   = array_diff( $need, $have );
		if ( $miss ) {
			return 'The sentence does not contain the keyword (missing: ' . implode( ', ', $miss ) . ').';
		}
		if ( Keywords_Text::count_phrase( $folded, (string) $item['core'] ) > 1 ) {
			return 'Use the keyword once, not repeatedly.';
		}

		// Density after insertion stays under 1.5% and the exact phrase
		// appears at most 3 times in the page copy. Measured on the live page
		// when reachable: theme-rendered copy is part of what Google reads, and
		// post_content alone can be a short fragment of it.
		$live   = self::live_text( $post->ID );
		$base   = '' !== $live ? $live : Keywords_Text::fold( wp_strip_all_tags( $post->post_content ) );
		$body   = $base . ' ' . $folded;
		$count  = Keywords_Text::count_phrase( $body, (string) $item['core'] );
		$total  = max( 1, str_word_count( $body ) );
		$length = count( Keywords_Text::tokens( (string) $item['core'] ) );
		if ( $count > 3 || ( $count * $length / $total ) * 100 > 1.5 ) {
			return 'The page would repeat this keyword too often.';
		}

		// No near-copies across pages: that would recreate the template
		// problem this is meant to avoid.
		foreach ( self::items( array( 'drafted', 'approved', 'applied', 'kept' ), 500 ) as $other ) {
			if ( (int) $other['id'] === (int) $item['id'] || '' === (string) $other['draft'] ) {
				continue;
			}
			if ( self::similarity( $sentence, $other['draft'] ) > self::MAX_SIMILARITY ) {
				return 'Too similar to the sentence drafted for "' . get_the_title( (int) $other['post_id'] ) . '". Write it differently.';
			}
		}
		return true;
	}

	/**
	 * Jaccard similarity of word trigrams (place names stripped).
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return float
	 */
	public static function similarity( $a, $b ) {
		$grams = static function ( $text ) {
			$folded = ' ' . Keywords_Text::fold( $text ) . ' ';
			foreach ( Keywords_Autoapply::places() as $place ) {
				$folded = str_replace( ' ' . $place . ' ', ' ', $folded );
			}
			$t   = Keywords_Text::tokens( trim( $folded ) );
			$out = array();
			for ( $i = 0; $i + 2 < count( $t ); $i++ ) {
				$out[ $t[ $i ] . ' ' . $t[ $i + 1 ] . ' ' . $t[ $i + 2 ] ] = true;
			}
			return $out;
		};
		$x = $grams( $a );
		$y = $grams( $b );
		if ( ! $x || ! $y ) {
			return 0.0;
		}
		return count( array_intersect_key( $x, $y ) ) / count( $x + $y );
	}

	/**
	 * Inner HTML of the Nth <p> in content, or ''.
	 *
	 * @param string $content Content.
	 * @param int    $index   Index.
	 * @return string
	 */
	private static function paragraph_at( $content, $index ) {
		if ( preg_match_all( '#<p>(.*?)</p>#s', (string) $content, $m ) && isset( $m[1][ $index ] ) ) {
			return $m[1][ $index ];
		}
		return '';
	}

	/**
	 * Reject an item (it will not be offered again for that keyword).
	 *
	 * @param int    $id   Item id.
	 * @param string $note Reason.
	 * @return bool
	 */
	public static function reject_item( $id, $note = '' ) {
		$item = self::get( $id );
		if ( ! $item || ! in_array( $item['status'], array( 'needs_draft', 'drafted', 'approved' ), true ) ) {
			return false;
		}
		self::update( $id, array( 'status' => 'rejected', 'note' => sanitize_text_field( $note ), 'ts_closed' => time() ) );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Apply / revert / measure
	 * ------------------------------------------------------------------ */

	/**
	 * Approve a drafted item and insert it.
	 *
	 * @param int $id Item id.
	 * @return true|string
	 */
	public static function approve( $id ) {
		$item = self::get( $id );
		if ( ! $item || 'drafted' !== $item['status'] ) {
			return 'Only drafted items can be approved.';
		}
		$post = get_post( (int) $item['post_id'] );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 'The page is no longer published.';
		}

		$current = self::paragraph_at( $post->post_content, (int) $item['paragraph_index'] );
		if ( '' === $current || $current !== $item['paragraph_text'] ) {
			self::update( $id, array( 'status' => 'needs_draft', 'note' => 'The paragraph changed since the draft; it needs a new draft.' ) );
			return 'The paragraph changed since the draft was written; it went back to the drafting queue.';
		}
		$recheck = self::validate( $item, $post, (int) $item['paragraph_index'], (string) $item['draft'] );
		if ( true !== $recheck ) {
			return 'No longer valid: ' . $recheck;
		}

		$inserted = ' ' . esc_html( $item['draft'] );
		$old_tag  = '<p>' . $current . '</p>';
		$new_tag  = '<p>' . rtrim( $current ) . $inserted . '</p>';
		$position = self::nth_position( $post->post_content, (int) $item['paragraph_index'] );
		if ( false === $position || substr( $post->post_content, $position, strlen( $old_tag ) ) !== $old_tag ) {
			return 'Could not locate the paragraph safely.';
		}
		$content = substr_replace( $post->post_content, $new_tag, $position, strlen( $old_tag ) );

		$snapshot = $post->post_content;
		if ( ! self::write_content( $post->ID, $content ) ) {
			return 'The page could not be saved.';
		}

		$s = Keywords_Autoapply::settings();
		self::update(
			$id,
			array(
				'status'     => 'applied',
				'inserted'   => $inserted,
				'snapshot'   => $snapshot,
				'baseline'   => Keywords_Autoapply::page_metrics( $post->ID, $s['range'] ),
				'applied_at' => current_time( 'mysql' ),
				'ts_applied' => time(),
				'note'       => '',
			)
		);
		Logger::info( 'autoapply', 'Content sentence inserted.', array( 'post_id' => $post->ID, 'item' => (int) $id, 'keyword' => $item['keyword'] ) );
		return true;
	}

	/**
	 * Remove an applied sentence.
	 *
	 * Removes exactly the inserted string so later edits to the page survive.
	 * With $force and the string gone, restores the full snapshot instead.
	 *
	 * @param int    $id     Item id.
	 * @param string $status undone|reverted.
	 * @param bool   $force  Restore the snapshot when the sentence was edited.
	 * @return true|string
	 */
	public static function revert( $id, $status = 'undone', $force = false ) {
		$item = self::get( $id );
		if ( ! $item || ! in_array( $item['status'], array( 'applied', 'kept' ), true ) ) {
			return 'Only applied items can be undone.';
		}
		$post = get_post( (int) $item['post_id'] );
		if ( ! $post ) {
			return 'Post not found.';
		}

		$inserted = (string) $item['inserted'];
		$pos      = '' !== $inserted ? strpos( $post->post_content, $inserted . '</p>' ) : false;
		if ( false !== $pos ) {
			$content = substr_replace( $post->post_content, '', $pos, strlen( $inserted ) );
		} elseif ( $force && '' !== (string) $item['snapshot'] ) {
			$content = (string) $item['snapshot'];
		} else {
			return 'The inserted sentence was edited or moved; use force to restore the page snapshot.';
		}

		if ( ! self::write_content( $post->ID, $content ) ) {
			return 'The page could not be saved.';
		}
		self::update( $id, array( 'status' => $status, 'ts_closed' => time() ) );
		Logger::info( 'autoapply', 'Content sentence removed.', array( 'post_id' => $post->ID, 'item' => (int) $id, 'status' => $status ) );
		return true;
	}

	/**
	 * Measure applied items whose window has passed.
	 *
	 * @param array $s       Settings.
	 * @param bool  $dry_run Report only.
	 * @return array[]
	 */
	public static function evaluate( array $s, $dry_run = false ) {
		if ( (int) get_option( self::DB_OPTION, 0 ) < 1 ) {
			return array();
		}
		$out    = array();
		$window = (int) $s['eval_days'] * DAY_IN_SECONDS;
		$drop   = (int) $s['revert_drop'] / 100;
		$last   = get_option( 'convertrack_gsc_keywords_last_sync', array() );

		foreach ( self::items( 'applied', 500 ) as $item ) {
			$range  = isset( $item['baseline']['range'] ) ? $item['baseline']['range'] : $s['range'];
			$synced = isset( $last[ $range ]['time'] ) ? strtotime( (string) $last[ $range ]['time'] ) : 0;
			if ( time() - (int) $item['ts_applied'] < $window || $synced < strtotime( (string) $item['applied_at'] ) + $window ) {
				continue;
			}
			$base  = (array) $item['baseline'];
			$now   = Keywords_Autoapply::page_metrics( (int) $item['post_id'], $range );
			$worse = ( (int) $base['clicks'] >= 3 && $now['clicks'] < $base['clicks'] * ( 1 - $drop ) && $now['impressions'] >= $base['impressions'] * 0.7 )
				|| ( (int) $base['impressions'] >= 100 && (float) $base['ctr'] > 0 && $now['impressions'] >= $base['impressions'] * 0.7 && $now['ctr'] < $base['ctr'] * ( 1 - $drop ) )
				|| ( (int) $base['impressions'] >= 50 && $now['impressions'] < $base['impressions'] * ( 1 - $drop ) && (float) $now['position'] > (float) $base['position'] + 3 );

			$result = array(
				'post_id'  => (int) $item['post_id'],
				'title'    => get_the_title( (int) $item['post_id'] ),
				'keyword'  => $item['keyword'],
				'baseline' => $base,
				'after'    => $now,
				'verdict'  => $worse ? 'worse' : 'kept',
				'action'   => 'none',
			);
			if ( ! $dry_run ) {
				self::update( (int) $item['id'], array( 'evaluation' => array( 'time' => current_time( 'mysql' ), 'metrics' => $now, 'verdict' => $result['verdict'] ), 'status' => 'kept' ) );
				if ( $worse && $s['auto_revert'] ) {
					$r                = self::revert( (int) $item['id'], 'reverted', false );
					$result['action'] = true === $r ? 'reverted' : 'revert-skipped: ' . $r;
				}
			}
			$out[] = $result;
		}
		return $out;
	}

	/**
	 * Byte offset of the Nth "<p>" opening tag.
	 *
	 * @param string $content Content.
	 * @param int    $index   Index.
	 * @return int|false
	 */
	private static function nth_position( $content, $index ) {
		if ( ! preg_match_all( '#<p>(.*?)</p>#s', (string) $content, $m, PREG_OFFSET_CAPTURE ) || ! isset( $m[0][ $index ] ) ) {
			return false;
		}
		return (int) $m[0][ $index ][1];
	}

	/**
	 * Save post_content directly (no kses re-filtering of existing markup,
	 * no save_post side effects), then refresh analysis and caches.
	 *
	 * @param int    $post_id Post id.
	 * @param string $content New content.
	 * @return bool
	 */
	private static function write_content( $post_id, $content ) {
		global $wpdb;
		$ok = false !== $wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => (int) $post_id )
		);
		clean_post_cache( $post_id );
		Keywords_Database::mark_post_stale( $post_id );
		Keywords_Cron::kick_analyze( MINUTE_IN_SECONDS );
		$url = get_permalink( $post_id );
		if ( $url && function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}
		do_action( 'litespeed_purge_post', $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		do_action( 'convertrack_autoapply_changed', $post_id );
		return $ok;
	}

	/* ------------------------------------------------------------------
	 * Wiring: admin actions + WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		foreach ( array( 'approve', 'reject', 'undo', 'edit' ) as $action ) {
			add_action( 'admin_post_convertrack_autoapply_content_' . $action, array( $this, 'handle_' . $action ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'convertrack autoapply content', __NAMESPACE__ . '\\Keywords_Autoapply_Content_CLI' );
		}
	}

	/**
	 * Check capability + nonce and return the posted item id.
	 *
	 * @param string $action Action.
	 * @return int
	 */
	private function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( 'convertrack_autoapply_content_' . $action );
		return isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
	}

	/**
	 * Redirect back to the Auto-Apply screen.
	 *
	 * @param string $code   Notice code.
	 * @param string $detail Detail message.
	 */
	private function back( $code, $detail = '' ) {
		$args = array( 'page' => Keywords_Autoapply::PAGE, 'cvtrk_aa' => $code );
		if ( '' !== $detail ) {
			set_transient( 'convertrack_aa_detail_' . get_current_user_id(), $detail, 120 );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#content-review' );
		exit;
	}

	/** Approve = insert now. */
	public function handle_approve() {
		$r = self::approve( $this->verify( 'approve' ) );
		$this->back( true === $r ? 'content-applied' : 'content-error', true === $r ? '' : $r );
	}

	/** Reject a draft. */
	public function handle_reject() {
		self::reject_item( $this->verify( 'reject' ), 'Rejected in wp-admin.' );
		$this->back( 'content-rejected' );
	}

	/** Undo an applied sentence. */
	public function handle_undo() {
		$id = $this->verify( 'undo' );
		$r  = self::revert( $id, 'undone', ! empty( $_POST['force'] ) );
		$this->back( true === $r ? 'content-undone' : 'content-error', true === $r ? '' : $r );
	}

	/** Edit a draft's wording before approving. */
	public function handle_edit() {
		$id   = $this->verify( 'edit' );
		$item = self::get( $id );
		$text = isset( $_POST['draft'] ) ? sanitize_textarea_field( wp_unslash( $_POST['draft'] ) ) : '';
		$r    = $item ? self::draft( $id, (int) $item['paragraph_index'], $text, wp_get_current_user()->user_login ) : 'Item not found.';
		$this->back( true === $r ? 'content-edited' : 'content-error', true === $r ? '' : $r );
	}
}

/**
 * WP-CLI: queue, draft, review and undo content insertions.
 */
class Keywords_Autoapply_Content_CLI {

	/**
	 * Queue new opportunities (one per page; verified against the live page).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max new items. Default: content_max_per_scan setting.
	 *
	 * [--verbose]
	 * : Also list skipped pages and why.
	 */
	public function scan( $args, $assoc ) {
		$s   = Keywords_Autoapply::settings();
		$out = Keywords_Autoapply_Content::scan( $s, isset( $assoc['limit'] ) ? (int) $assoc['limit'] : (int) $s['content_max_per_scan'] );
		\WP_CLI::line( count( $out['queued'] ) . ' queued, ' . count( $out['skipped'] ) . ' pages skipped.' );
		if ( $out['queued'] ) {
			\WP_CLI\Utils\format_items( 'table', $out['queued'], array( 'id', 'post_id', 'keyword', 'core' ) );
		}
		if ( isset( $assoc['verbose'] ) && $out['skipped'] ) {
			\WP_CLI\Utils\format_items( 'table', $out['skipped'], array( 'post_id', 'reason' ) );
		}
	}

	/**
	 * List items. With --format=json, items waiting for a draft include the
	 * page context and the paragraphs that may be extended.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : needs_draft|drafted|applied|kept|rejected|undone|reverted. Default needs_draft.
	 *
	 * [--format=<format>]
	 * : table|json. Default table.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$status = isset( $assoc['status'] ) ? sanitize_key( $assoc['status'] ) : 'needs_draft';
		$items  = Keywords_Autoapply_Content::items( $status, 200 );
		$rows   = array();
		foreach ( $items as $item ) {
			$pid  = (int) $item['post_id'];
			$post = get_post( $pid );
			$row  = array(
				'id'       => (int) $item['id'],
				'post_id'  => $pid,
				'page'     => get_the_title( $pid ),
				'url'      => get_permalink( $pid ),
				'keyword'  => $item['keyword'],
				'core'     => $item['core'],
				'code'     => $item['code'],
				'kw_stats' => $item['kw_stats'],
				'status'   => $item['status'],
				'draft'    => $item['draft'],
				'note'     => $item['note'],
			);
			if ( 'needs_draft' === $item['status'] && $post ) {
				$row['h1']         = wp_strip_all_tags( (string) get_post_meta( $pid, 'location_title_h1', true ) ) ?: get_the_title( $pid );
				$row['city']       = (string) get_post_meta( $pid, 'city', true );
				$row['paragraphs'] = array_map(
					static function ( $p ) {
						return array( 'index' => $p['index'], 'text' => $p['text'] );
					},
					Keywords_Autoapply_Content::paragraphs( $post->post_content )
				);
				$row['other_drafts'] = array_values( array_filter( wp_list_pluck( Keywords_Autoapply_Content::items( array( 'drafted', 'applied', 'kept' ), 100 ), 'draft' ) ) );
			}
			$rows[] = $row;
		}
		if ( isset( $assoc['format'] ) && 'json' === $assoc['format'] ) {
			\WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'post_id', 'page', 'keyword', 'status', 'draft' ) );
	}

	/**
	 * Supply the sentence for an item.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Item id.
	 *
	 * --paragraph=<index>
	 * : Paragraph index to extend.
	 *
	 * --text=<sentence>
	 * : One plain-text sentence containing the keyword.
	 */
	public function draft( $args, $assoc ) {
		$r = Keywords_Autoapply_Content::draft( (int) $args[0], (int) $assoc['paragraph'], (string) $assoc['text'], 'claude-cli' );
		true === $r ? \WP_CLI::success( 'Drafted; waiting for approval in wp-admin.' ) : \WP_CLI::error( $r, false );
	}

	/**
	 * Approve (insert) a drafted item.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Item id.
	 */
	public function approve( $args ) {
		$r = Keywords_Autoapply_Content::approve( (int) $args[0] );
		true === $r ? \WP_CLI::success( 'Inserted.' ) : \WP_CLI::error( $r, false );
	}

	/**
	 * Reject an item.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Item id.
	 */
	public function reject( $args ) {
		Keywords_Autoapply_Content::reject_item( (int) $args[0], 'Rejected via CLI.' ) ? \WP_CLI::success( 'Rejected.' ) : \WP_CLI::error( 'Not rejectable.', false );
	}

	/**
	 * Remove an applied sentence.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Item id.
	 *
	 * [--force]
	 * : Restore the page snapshot if the sentence was edited.
	 */
	public function undo( $args, $assoc ) {
		$r = Keywords_Autoapply_Content::revert( (int) $args[0], 'undone', isset( $assoc['force'] ) );
		true === $r ? \WP_CLI::success( 'Removed.' ) : \WP_CLI::error( $r, false );
	}
}
