<?php
/**
 * Batch analyzer: classify -> fingerprint -> presence -> score -> recommend.
 *
 * Pure-local work (no Google API calls). Batches are post-ordered so the
 * content fingerprint cache pays off. Staleness is event-driven (save_post /
 * deleted_post) with a content hash stored per row as the backstop.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-owner-lock.php';

class Keywords_Analyzer {

	const LOCK_OPTION       = 'convertrack_gsc_keywords_analysis_lock';
	const LAST_ERROR_OPTION = 'convertrack_gsc_keywords_last_analysis_error';
	const LOCK_TIMEOUT      = 120;
	const ERROR_ABORT_THRESHOLD = 5;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'convertrack_gsc_keywords_sync_completed', array( __CLASS__, 'on_sync_completed' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ) );
	}

	/**
	 * Analyze a batch of keyword rows.
	 *
	 * @param int $limit Batch size (0 = filterable default).
	 * @return array { processed, errors, remaining, busy, aborted, abort_reason }
	 */
	public static function analyze_batch( $limit = 0 ) {
		if ( ! Keywords_Settings::get( 'enabled' ) ) {
			return array(
				'processed' => 0,
				'errors'    => 0,
				'remaining' => 0,
				'busy'      => false,
				'aborted'   => false,
			);
		}

		$owner = \Convertrack\Owner_Lock::acquire( self::LOCK_OPTION, self::LOCK_TIMEOUT );
		if ( false === $owner ) {
			return array(
				'processed' => 0,
				'errors'    => 0,
				'remaining' => Keywords_Database::pending_analysis_count(),
				'busy'      => true,
				'aborted'   => false,
			);
		}

		try {
			return self::run_batch( (int) $limit, $owner );
		} finally {
			\Convertrack\Owner_Lock::release( self::LOCK_OPTION, $owner );
		}
	}

	/**
	 * Kick the analysis chain after a completed sync.
	 */
	public static function on_sync_completed() {
		Keywords_Cron::kick_analyze( 0 );
	}

	/**
	 * Mark a saved post's keyword rows stale.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function on_save_post( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! Keywords_Settings::get( 'enabled' ) ) {
			return;
		}
		if ( ! $post || ! in_array( $post->post_type, (array) Keywords_Settings::get( 'selected_post_types', array() ), true ) ) {
			return;
		}

		if ( Keywords_Database::mark_post_stale( $post_id ) > 0 ) {
			// The 5-minute delay plus the kick dedupe doubles as an edit-session debounce.
			Keywords_Cron::kick_analyze( 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Mark a deleted post's rows stale so they revert to metric-only analysis.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_deleted_post( $post_id ) {
		if ( ! Keywords_Settings::get( 'enabled' ) ) {
			return;
		}
		if ( Keywords_Database::mark_post_stale( $post_id ) > 0 ) {
			Keywords_Cron::kick_analyze( 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Batch body, called with the lock held.
	 *
	 * @param int $limit Batch size.
	 * @return array
	 */
	private static function run_batch( $limit, $owner ) {
		$limit = $limit > 0 ? $limit : (int) apply_filters( 'convertrack_gsc_keywords_analysis_batch_size', 200 );
		$mapped = Keywords_Database::map_page_posts( $limit );
		if ( is_wp_error( $mapped ) ) {
			return array( 'processed' => 0, 'errors' => 1, 'remaining' => Keywords_Database::pending_analysis_count(), 'busy' => false, 'aborted' => true, 'abort_reason' => $mapped->get_error_message() );
		}
		$rows  = Keywords_Database::analysis_batch( $limit );
		if ( is_wp_error( $rows ) ) {
			return array( 'processed' => 0, 'errors' => 1, 'remaining' => Keywords_Database::pending_analysis_count(), 'busy' => false, 'aborted' => true, 'abort_reason' => $rows->get_error_message() );
		}

		Keywords_Fingerprint::flush_cache();
		Keywords_Classifier::flush_context();
		$context = Keywords_Classifier::build_context();
		$tracked = (array) Keywords_Settings::get( 'keyword_types', array() );

		$processed    = 0;
		$errors       = 0;
		$streak       = 0;
		$aborted      = false;
		$abort_reason = '';
		$page_hashes  = array();

		foreach ( $rows as $row ) {
			if ( ! \Convertrack\Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, self::LOCK_TIMEOUT ) ) {
				$aborted      = true;
				$abort_reason = __( 'The keyword analyzer lost ownership of its lease.', 'convertrack-click-conversion-analytics' );
				break;
			}

			try {
				$fields = self::analyze_row( $row, $context, $tracked );
				$saved = Keywords_Database::save_analysis( (int) $row['id'], $fields, false );
				if ( is_wp_error( $saved ) ) {
					throw new \RuntimeException( $saved->get_error_message() );
				}
				$processed++;
				$page_hashes[] = (string) $row['page_hash'];
				$streak = 0;
			} catch ( \Throwable $e ) {
				$errors++;
				$streak++;
				Keywords_Database::mark_analysis_failed( (int) $row['id'] );
				Logger::error( 'keywords-analyzer', 'Keyword analysis failed for a row.', array( 'id' => (int) $row['id'], 'error' => $e->getMessage() ) );

				if ( $streak >= self::ERROR_ABORT_THRESHOLD ) {
					$aborted      = true;
					$abort_reason = $e->getMessage();
					update_option(
						self::LAST_ERROR_OPTION,
						array(
							'message' => $abort_reason,
							'time'    => current_time( 'mysql' ),
						),
						false
					);
					Logger::error( 'keywords-analyzer', 'Analysis batch stopped after consecutive errors.', array( 'errors' => $streak ) );
					break;
				}
			}
		}

		if ( $processed > 0 ) {
			$refreshed = Keywords_Database::refresh_page_analysis( $page_hashes );
			if ( is_wp_error( $refreshed ) ) {
				$errors++;
				$aborted      = true;
				$abort_reason = $refreshed->get_error_message();
			}
			if ( ! $aborted ) {
				delete_option( self::LAST_ERROR_OPTION );
			}
		}

		return array(
			'processed'    => $processed,
			'errors'       => $errors,
			'remaining'    => Keywords_Database::pending_analysis_count(),
			'busy'         => false,
			'aborted'      => $aborted,
			'abort_reason' => $abort_reason,
		);
	}

	/**
	 * Analyze one keyword row.
	 *
	 * @param array $row     Keyword row.
	 * @param array $context Classifier context.
	 * @param array $tracked Tracked keyword types ([] = all).
	 * @return array Fields for Keywords_Database::save_analysis().
	 */
	private static function analyze_row( array $row, array $context, array $tracked ) {
		$query  = (string) $row['query'];
		$labels = Keywords_Classifier::classify( $query, $context );

		$post_id     = (int) $row['post_id'];
		$fingerprint = null;
		if ( $post_id > 0 ) {
			$maybe = Keywords_Fingerprint::for_post( $post_id );
			if ( ! is_wp_error( $maybe ) ) {
				$fingerprint = $maybe;
			}
		}

		if ( $fingerprint ) {
			$presence     = Keywords_Presence::check( $query, $fingerprint );
			$has_faq      = ! empty( $fingerprint['has_faq'] );
			$quality      = (string) $fingerprint['extraction_quality'];
			$content_hash = (string) $fingerprint['content_hash'];
		} else {
			$presence = array(
				'areas'            => array(),
				'status'           => 'unknown',
				'body_exact_count' => 0,
				'density'          => 0.0,
			);
			$has_faq      = false;
			$quality      = '';
			$content_hash = '';
		}

		$score = Keywords_Scorer::score(
			array(
				'impressions'     => (int) $row['impressions'],
				'ctr'             => (float) $row['ctr'],
				'position'        => (float) $row['position'],
				'presence_status' => $presence['status'],
				'labels'          => $labels,
			)
		);

		$recommendations = Keywords_Recommendations::build(
			array(
				'keyword'            => $query,
				'labels'             => $labels,
				'impressions'        => (int) $row['impressions'],
				'ctr'                => (float) $row['ctr'],
				'position'           => (float) $row['position'],
				'post_id'            => $post_id,
				'presence'           => $presence,
				'has_faq'            => $has_faq,
				'extraction_quality' => $quality,
			)
		);

		// "Keyword types to track": untracked keywords keep their labels and
		// presence data but are not scored or recommended.
		if ( ! empty( $tracked ) && ! array_intersect( $labels, $tracked ) ) {
			$score['score']  = 0;
			$score['level']  = '';
			$recommendations = array();
		}

		return array(
			'labels'            => $labels,
			'presence_status'   => $presence['status'],
			'opportunity_score' => $score['score'],
			'opportunity_level' => $score['level'],
			'recommendations'   => $recommendations,
			'analysis_json'     => array(
				'areas'              => $presence['areas'],
				'breakdown'          => $score['breakdown'],
				'density'            => $presence['density'],
				'body_exact_count'   => $presence['body_exact_count'],
				'extraction_quality' => $quality,
			),
			'content_hash'      => $content_hash,
			'analysis_state'    => 'done',
		);
	}

}
