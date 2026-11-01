<?php
/**
 * Keyword Auto-Apply.
 *
 * Turns analyzed "improve_title_meta" opportunities into on-site changes:
 * the SEO title gains the missing keyword variant and the keyword is added
 * as a secondary focus keyword. Every change is snapshotted, rate-limited
 * (per-run cap + per-page cooldown), measured against a Search Console
 * baseline, and automatically reverted when clicks/CTR fall afterwards.
 *
 * Content-level recommendations (add_to_content, headings, FAQ, location)
 * are never auto-applied: they need real copy and stay in the Keyword
 * Opportunities queue for review.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Autoapply {

	const OPTION      = 'convertrack_autoapply_settings';
	const LOG_OPTION  = 'convertrack_autoapply_log';
	const LAST_RUN    = 'convertrack_autoapply_last_run';
	const LOCK_OPTION = 'convertrack_autoapply_lock';
	const META        = '_convertrack_autoapply';
	const HOOK        = 'convertrack_gsc_keywords_autoapply';
	const GROUP       = 'convertrack';
	const PAGE        = 'convertrack-gsc-autoapply';
	const LOG_MAX     = 300;
	const HISTORY_MAX = 20;

	/** @var array|null Place phrase cache. */
	private static $places = null;

	/** @var array|null Brand variant cache. */
	private static $brands = null;

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Default settings. Ships disabled and in dry-run mode.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'         => 0,
			'mode'            => 'dry_run',
			'range'           => '28d',
			'max_per_run'     => 5,
			'min_impressions' => 20,
			'max_position'    => 10,
			'cooldown_days'   => 28,
			'eval_days'       => 31,
			'auto_revert'     => 1,
			'revert_drop'     => 20,
			'title_max'       => 60,
			'exclude_front'   => 1,
			'exclude_ids'     => array(),
			'apply_title'     => 1,
			'apply_focus'     => 1,
			// Content insertions (drafted by a writer, approved in wp-admin).
			'content_enabled'         => 0,
			'content_min_impressions' => 10,
			'content_max_position'    => 30,
			'content_max_per_scan'    => 10,
			// Keywords with these words signal an intent the page does not
			// serve (DIY, jobs, products...). Never written into a title.
			'blocked_words'   => array( 'self', 'serve', 'diy', 'yourself', 'job', 'jobs', 'hiring', 'salary', 'career', 'school', 'course', 'class', 'classes', 'certification', 'training', 'license', 'kit', 'supplies', 'supply', 'tool', 'tools', 'clipper', 'clippers', 'shampoo', 'product', 'products', 'brush', 'table', 'van', 'trailer', 'franchise', 'free', 'used', 'sale', 'buy', 'reviews', 'review', 'reddit', 'yelp' ),
		);
	}

	/**
	 * Roots that never count as "missing" on their own - generic filler.
	 *
	 * @return string[]
	 */
	private static function generic_roots() {
		return (array) apply_filters( 'convertrack_autoapply_generic_roots', array( 'service', 'company', 'professional', 'business', 'local', 'pro', 'expert', 'specialist', 'provider', 'option', 'place', 'center' ) );
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Sanitize and persist settings. Unknown keys are ignored; missing
	 * checkbox keys mean "off" only when $from_form is true.
	 *
	 * @param array $in        Raw input.
	 * @param bool  $from_form Whether the input came from the admin form.
	 * @return array Saved settings.
	 */
	public static function save_settings( array $in, $from_form = false ) {
		$cur = self::settings();
		$d   = self::defaults();

		foreach ( array( 'enabled', 'auto_revert', 'exclude_front', 'apply_title', 'apply_focus', 'content_enabled' ) as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$cur[ $flag ] = empty( $in[ $flag ] ) || 'false' === $in[ $flag ] || 'no' === $in[ $flag ] ? 0 : 1;
			} elseif ( $from_form ) {
				$cur[ $flag ] = 0;
			}
		}

		if ( isset( $in['mode'] ) ) {
			$cur['mode'] = 'live' === $in['mode'] ? 'live' : 'dry_run';
		}
		if ( isset( $in['range'] ) ) {
			$cur['range'] = in_array( $in['range'], array( '7d', '28d', '3m' ), true ) ? $in['range'] : $d['range'];
		}

		$ints = array(
			'max_per_run'     => array( 1, 50 ),
			'min_impressions' => array( 1, 100000 ),
			'max_position'    => array( 1, 20 ),
			'cooldown_days'   => array( 7, 365 ),
			'eval_days'       => array( 14, 120 ),
			'revert_drop'     => array( 5, 90 ),
			'title_max'       => array( 40, 80 ),
			'content_min_impressions' => array( 1, 100000 ),
			'content_max_position'    => array( 1, 100 ),
			'content_max_per_scan'    => array( 1, 50 ),
		);
		foreach ( $ints as $key => $bounds ) {
			if ( isset( $in[ $key ] ) && '' !== $in[ $key ] ) {
				$cur[ $key ] = max( $bounds[0], min( $bounds[1], (int) $in[ $key ] ) );
			}
		}

		if ( isset( $in['blocked_words'] ) ) {
			$words = is_array( $in['blocked_words'] ) ? $in['blocked_words'] : preg_split( '/[\s,]+/', (string) $in['blocked_words'] );
			$cur['blocked_words'] = array_values( array_unique( array_filter( array_map( array( Keywords_Text::class, 'fold' ), (array) $words ), 'strlen' ) ) );
		}

		if ( isset( $in['exclude_ids'] ) ) {
			$ids = is_array( $in['exclude_ids'] ) ? $in['exclude_ids'] : preg_split( '/[\s,]+/', (string) $in['exclude_ids'] );
			$cur['exclude_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		}

		update_option( self::OPTION, $cur, false );
		if ( $cur['enabled'] ) {
			self::schedule();
		}
		return $cur;
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------ */

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_convertrack_autoapply_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_convertrack_autoapply_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_convertrack_autoapply_undo', array( $this, 'handle_undo' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'convertrack autoapply run', array( __CLASS__, 'cli_run' ) );
			\WP_CLI::add_command( 'convertrack autoapply status', array( __CLASS__, 'cli_status' ) );
			\WP_CLI::add_command( 'convertrack autoapply undo', array( __CLASS__, 'cli_undo' ) );
			\WP_CLI::add_command( 'convertrack autoapply config', array( __CLASS__, 'cli_config' ) );
		}
	}

	/**
	 * Keep the daily job scheduled while the feature is enabled.
	 */
	public static function maybe_schedule() {
		if ( self::settings()['enabled'] ) {
			self::schedule();
		}
	}

	/**
	 * Schedule the daily run (Action Scheduler when present, WP-Cron otherwise).
	 */
	public static function schedule() {
		\Convertrack\Scheduler::when_ready( array( __CLASS__, 'schedule_recurring' ) );
	}

	/**
	 * Arm the daily run. Call schedule() instead unless you know the
	 * scheduler is ready.
	 */
	public static function schedule_recurring() {
		if ( \Convertrack\Scheduler::available() ) {
			if ( ! as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled job.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Next scheduled run as a Unix timestamp, or 0.
	 *
	 * @return int
	 */
	public static function next_run() {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( is_int( $next ) ) {
				return $next;
			}
		}
		$next = wp_next_scheduled( self::HOOK );
		return $next ? (int) $next : 0;
	}

	/**
	 * Scheduled entry point.
	 */
	public static function run_scheduled() {
		if ( ! self::settings()['enabled'] ) {
			return;
		}
		self::run( array( 'trigger' => 'schedule' ) );
	}

	/* ------------------------------------------------------------------
	 * Run
	 * ------------------------------------------------------------------ */

	/**
	 * Evaluate past changes, then plan (and, in live mode, apply) new ones.
	 *
	 * @param array $args { dry_run?: bool, limit?: int, post_id?: int, trigger?: string }
	 * @return array Run report.
	 */
	public static function run( array $args = array() ) {
		$s       = self::settings();
		$dry_run = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : ( 'live' !== $s['mode'] );
		$limit   = ! empty( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : (int) $s['max_per_run'];
		$only    = ! empty( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$report = array(
			'time'      => current_time( 'mysql' ),
			'trigger'   => isset( $args['trigger'] ) ? sanitize_key( $args['trigger'] ) : 'manual',
			'dry_run'   => $dry_run,
			'range'     => $s['range'],
			'blocked'   => '',
			'evaluated' => array(),
			'applied'   => array(),
			'skipped'   => array(),
		);

		$blocked = self::preflight( $s );
		if ( '' !== $blocked ) {
			$report['blocked'] = $blocked;
			return self::finish( $report );
		}

		$owner = \Convertrack\Owner_Lock::acquire( self::LOCK_OPTION, 300 );
		if ( false === $owner ) {
			$report['blocked'] = __( 'Another auto-apply run is in progress.', 'convertrack-click-conversion-analytics' );
			return self::finish( $report );
		}

		try {
			$report['evaluated'] = self::evaluate( $s, $dry_run );

			foreach ( self::candidates( $s['range'], $only ) as $candidate ) {
				if ( count( $report['applied'] ) >= $limit ) {
					break;
				}
				$plan = self::plan( $candidate, $s );
				if ( is_string( $plan ) ) {
					$report['skipped'][] = array(
						'post_id' => (int) $candidate['post_id'],
						'title'   => get_the_title( (int) $candidate['post_id'] ),
						'reason'  => $plan,
					);
					continue;
				}
				if ( ! $dry_run ) {
					self::apply( $plan );
				}
				$report['applied'][] = $plan;
			}

			// Content insertions: measure the applied ones, queue new ones for
			// a writer. Nothing is inserted here - only an approval inserts.
			if ( $s['content_enabled'] && ! $only ) {
				$report['content_evaluated'] = Keywords_Autoapply_Content::evaluate( $s, $dry_run );
				if ( ! $dry_run ) {
					$scan                     = Keywords_Autoapply_Content::scan( $s, (int) $s['content_max_per_scan'] );
					$report['content_queued'] = $scan['queued'];
				}
			}
		} finally {
			\Convertrack\Owner_Lock::release( self::LOCK_OPTION, $owner );
		}

		return self::finish( $report );
	}

	/**
	 * Store and log a run report.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private static function finish( array $report ) {
		update_option( self::LAST_RUN, $report, false );
		Logger::info(
			'autoapply',
			$report['blocked'] ? 'Keyword auto-apply did not run.' : 'Keyword auto-apply run finished.',
			array(
				'dry_run'   => $report['dry_run'],
				'blocked'   => $report['blocked'],
				'applied'   => count( $report['applied'] ),
				'skipped'   => count( $report['skipped'] ),
				'evaluated' => count( $report['evaluated'] ),
			)
		);
		return $report;
	}

	/**
	 * Reasons a run must not proceed ('' when clear).
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function preflight( array $s ) {
		if ( ! Keywords_Settings::ready() ) {
			return __( 'Keyword Opportunities is not connected to Search Console or is disabled.', 'convertrack-click-conversion-analytics' );
		}
		if ( 'rank_math' !== Keywords_SEO_Meta::provider() ) {
			return __( 'Auto-apply currently writes Rank Math fields; Rank Math is not the active SEO plugin.', 'convertrack-click-conversion-analytics' );
		}
		if ( Keywords_Database::pending_analysis_count() > 0 ) {
			Keywords_Cron::kick_analyze( 0 );
			return __( 'Keyword analysis is still running; the next run will continue once it finishes.', 'convertrack-click-conversion-analytics' );
		}
		$age = self::data_age( $s['range'] );
		if ( null === $age || $age > 10 * DAY_IN_SECONDS ) {
			return __( 'Keyword data for this range is missing or more than 10 days old. Sync keywords first.', 'convertrack-click-conversion-analytics' );
		}
		return '';
	}

	/**
	 * Seconds since the range was last synced, or null.
	 *
	 * @param string $range Range key.
	 * @return int|null
	 */
	public static function data_age( $range ) {
		$ts = self::synced_ts( $range );
		return $ts ? max( 0, current_time( 'timestamp' ) - $ts ) : null; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * Local timestamp of the last sync for a range, or 0.
	 *
	 * @param string $range Range key.
	 * @return int
	 */
	private static function synced_ts( $range ) {
		$last = get_option( 'convertrack_gsc_keywords_last_sync', array() );
		if ( empty( $last[ $range ]['time'] ) ) {
			return 0;
		}
		$ts = strtotime( (string) $last[ $range ]['time'] );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Mapped posts with title/meta opportunities, highest impressions first.
	 *
	 * @param string $range   Range key.
	 * @param int    $post_id Restrict to one post (0 = all).
	 * @return array[] { post_id, impressions, keywords[] }
	 */
	private static function candidates( $range, $post_id = 0 ) {
		global $wpdb;
		$table = Keywords_Database::keywords_table();
		$sql   = "SELECT id, post_id, query, impressions, clicks, ctr, position, labels
			FROM $table
			WHERE range_key = %s AND analysis_state = 'done' AND post_id > 0 AND recommendations LIKE %s";
		$params = array( $range, '%"improve_title_meta"%' );
		if ( $post_id > 0 ) {
			$sql     .= ' AND post_id = %d';
			$params[] = $post_id;
		}
		$sql .= ' ORDER BY impressions DESC, position ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $by[ $pid ] ) ) {
				$by[ $pid ] = array(
					'post_id'     => $pid,
					'impressions' => 0,
					'keywords'    => array(),
				);
			}
			$by[ $pid ]['impressions'] += (int) $row['impressions'];
			$by[ $pid ]['keywords'][]   = $row;
		}

		$out = array_values( $by );
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['impressions'] - $a['impressions'];
			}
		);
		return $out;
	}

	/**
	 * Build a change plan for one post, or return the reason it is skipped.
	 *
	 * @param array $candidate Candidate from candidates().
	 * @param array $s         Settings.
	 * @return array|string
	 */
	private static function plan( array $candidate, array $s ) {
		$pid  = (int) $candidate['post_id'];
		$post = get_post( $pid );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return __( 'Not a published post.', 'convertrack-click-conversion-analytics' );
		}
		if ( $s['exclude_front'] && $pid === (int) get_option( 'page_on_front' ) ) {
			return __( 'Front page is excluded.', 'convertrack-click-conversion-analytics' );
		}
		if ( in_array( $pid, (array) $s['exclude_ids'], true ) ) {
			return __( 'Excluded in settings.', 'convertrack-click-conversion-analytics' );
		}

		$last = self::last_entry( $pid );
		if ( $last && 'applied' === $last['status'] ) {
			return __( 'A previous change is still being measured.', 'convertrack-click-conversion-analytics' );
		}
		if ( $last && ( time() - (int) $last['ts'] ) < (int) $s['cooldown_days'] * DAY_IN_SECONDS ) {
			return __( 'Changed recently (cooldown).', 'convertrack-click-conversion-analytics' );
		}
		if ( Keywords_Autoapply_Content::recently_changed( $pid, (int) $s['cooldown_days'] ) ) {
			return __( 'Content was changed recently (cooldown).', 'convertrack-click-conversion-analytics' );
		}

		$meta  = Keywords_SEO_Meta::for_post( $pid );
		$title = trim( (string) $meta['title'] );
		if ( '' === $title ) {
			return __( 'No SEO title to build on.', 'convertrack-click-conversion-analytics' );
		}

		$focus_raw   = (string) get_post_meta( $pid, 'rank_math_focus_keyword', true );
		$page_folded = Keywords_Text::fold( $title . ' ' . $focus_raw . ' ' . $post->post_name );
		$page_places = self::match_places( $page_folded );
		$title_roots = self::roots( Keywords_Text::fold( $title ) );

		$reasons = array();
		foreach ( $candidate['keywords'] as $kw ) {
			$reject = self::reject_keyword( $kw, $s, $page_places );
			if ( '' !== $reject ) {
				$reasons[ $reject ] = true;
				continue;
			}

			$variant = self::variant( (string) $kw['query'], $title_roots );
			if ( '' === $variant ) {
				$reasons[ __( 'nothing to add beyond the location', 'convertrack-click-conversion-analytics' ) ] = true;
				continue;
			}
			if ( ! array_diff( self::roots( Keywords_Text::fold( $variant ) ), $title_roots, self::generic_roots() ) ) {
				$reasons[ __( 'title already covers the keyword', 'convertrack-click-conversion-analytics' ) ] = true;
				continue;
			}

			$new_title = $s['apply_title'] ? self::compose_title( $title, $variant, (int) $s['title_max'] ) : $title;
			if ( '' === $new_title ) {
				$reasons[ __( 'title would be too long', 'convertrack-click-conversion-analytics' ) ] = true;
				continue;
			}

			$new_focus = $s['apply_focus'] ? self::add_focus( $focus_raw, (string) $kw['query'] ) : $focus_raw;
			if ( $new_title === $title && $new_focus === $focus_raw ) {
				$reasons[ __( 'nothing to change', 'convertrack-click-conversion-analytics' ) ] = true;
				continue;
			}

			return array(
				'post_id'  => $pid,
				'title'    => get_the_title( $pid ),
				'url'      => get_permalink( $pid ),
				'keyword'  => (string) $kw['query'],
				'variant'  => $variant,
				'kw_stats' => array(
					'impressions' => (int) $kw['impressions'],
					'clicks'      => (int) $kw['clicks'],
					'ctr'         => round( (float) $kw['ctr'] * 100, 2 ),
					'position'    => round( (float) $kw['position'], 1 ),
				),
				'before'   => array(
					'title_raw' => (string) get_post_meta( $pid, 'rank_math_title', true ),
					'title'     => $title,
					'focus'     => $focus_raw,
				),
				'after'    => array(
					'title' => $new_title,
					'focus' => $new_focus,
				),
				'baseline' => self::page_metrics( $pid, $s['range'] ),
			);
		}

		$reasons = array_keys( $reasons );
		return $reasons
			? sprintf( /* translators: %s: reasons */ __( 'No eligible keyword (%s).', 'convertrack-click-conversion-analytics' ), implode( '; ', array_slice( $reasons, 0, 3 ) ) )
			: __( 'No eligible keyword.', 'convertrack-click-conversion-analytics' );
	}

	/* ------------------------------------------------------------------
	 * Keyword rules
	 * ------------------------------------------------------------------ */

	/**
	 * Why a keyword must not drive a title change ('' when eligible).
	 *
	 * @param array $kw          Keyword row.
	 * @param array $s           Settings.
	 * @param array $page_places Places the page targets.
	 * @return string
	 */
	private static function reject_keyword( array $kw, array $s, array $page_places ) {
		$query  = (string) $kw['query'];
		$labels = json_decode( (string) $kw['labels'], true );
		$labels = is_array( $labels ) ? $labels : array();

		if ( (int) $kw['impressions'] < (int) $s['min_impressions'] ) {
			return __( 'below the impressions threshold', 'convertrack-click-conversion-analytics' );
		}
		if ( (float) $kw['position'] > (float) $s['max_position'] ) {
			return __( 'not ranking on page one', 'convertrack-click-conversion-analytics' );
		}
		if ( array_intersect( $labels, array( 'branded', 'competitor', 'question' ) ) || self::is_branded( $query ) ) {
			return __( 'branded, competitor or question keyword', 'convertrack-click-conversion-analytics' );
		}
		if ( preg_match( '/["\x{201C}\x{201D}:]|(^|\s)-\w|\bsite\b|https?|www\./iu', $query ) ) {
			return __( 'search-operator query', 'convertrack-click-conversion-analytics' );
		}
		if ( preg_match( '/[^\x20-\x7E]/', $query ) ) {
			return __( 'non-English query', 'convertrack-click-conversion-analytics' );
		}

		$folded = Keywords_Text::fold( $query );
		$tokens = Keywords_Text::tokens( $folded );
		$count  = count( $tokens );
		if ( array_intersect( $tokens, (array) $s['blocked_words'] ) ) {
			return __( 'keyword signals an intent the page does not serve', 'convertrack-click-conversion-analytics' );
		}
		if ( $count < 2 || $count > 7 ) {
			return __( 'too short or too long for a title', 'convertrack-click-conversion-analytics' );
		}

		// Never retitle a page for a place it does not target - that would
		// cannibalise the page that does target it.
		if ( array_diff( self::match_places( $folded ), $page_places ) ) {
			return __( 'keyword targets a different location', 'convertrack-click-conversion-analytics' );
		}

		return '';
	}

	/**
	 * Whether a query mentions the brand (settings terms, site name, host).
	 *
	 * @param string $query Query.
	 * @return bool
	 */
	private static function is_branded( $query ) {
		if ( null === self::$brands ) {
			self::$brands = array();
			$ctx          = Keywords_Classifier::build_context();
			foreach ( (array) $ctx['brand_variants'] as $variant ) {
				self::$brands[] = $variant;
			}
		}
		$folded = Keywords_Text::fold( $query );
		$squash = str_replace( ' ', '', $folded );
		foreach ( self::$brands as $variant ) {
			if ( Keywords_Text::contains_phrase( $folded, $variant['folded'] )
				|| ( strlen( $variant['squash'] ) >= 4 && false !== strpos( $squash, $variant['squash'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Place phrases known to the site, longest first.
	 *
	 * Built from the Keyword Opportunities location terms plus the focus
	 * keywords of location landing pages (post types filterable), with the
	 * service words stripped: "mobile pet grooming Key Largo FL" -> "key largo".
	 *
	 * @return string[]
	 */
	public static function places() {
		if ( null !== self::$places ) {
			return self::$places;
		}

		$places = array();
		foreach ( (array) Keywords_Settings::get( 'location_terms', array() ) as $term ) {
			$folded = Keywords_Text::fold( $term );
			if ( '' !== $folded ) {
				$places[ $folded ] = true;
			}
		}

		// Programmatic location pages share a "<service>-<place>[-<state>]"
		// slug pattern. A slug prefix used by many pages is a service; what
		// follows it is the place. Place-led prefixes ("north", "palm") are
		// shared by only a handful of pages, so they stay below the cutoff.
		global $wpdb;
		$types = array_filter( (array) Keywords_Settings::get( 'selected_post_types', array() ), 'post_type_exists' );
		$types = (array) apply_filters( 'convertrack_autoapply_place_post_types', $types );
		if ( $types ) {
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$slugs = $wpdb->get_col( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

			$split  = array();
			$counts = array();
			foreach ( (array) $slugs as $slug ) {
				$tokens = array_values( array_filter( explode( '-', urldecode( (string) $slug ) ), 'strlen' ) );
				if ( count( $tokens ) > 2 && 2 === strlen( end( $tokens ) ) ) {
					array_pop( $tokens ); // State code.
				}
				if ( count( $tokens ) < 2 ) {
					continue;
				}
				$split[] = $tokens;
				for ( $k = 1; $k < count( $tokens ); $k++ ) {
					$prefix            = implode( '-', array_slice( $tokens, 0, $k ) );
					$counts[ $prefix ] = isset( $counts[ $prefix ] ) ? $counts[ $prefix ] + 1 : 1;
				}
			}

			$cutoff = (int) apply_filters( 'convertrack_autoapply_service_prefix_min', max( 5, (int) ceil( sqrt( count( $split ) ) ) ) );
			foreach ( $split as $tokens ) {
				for ( $k = count( $tokens ) - 1; $k >= 1; $k-- ) {
					$prefix = implode( '-', array_slice( $tokens, 0, $k ) );
					if ( isset( $counts[ $prefix ] ) && $counts[ $prefix ] >= $cutoff ) {
						$rest  = array_slice( $tokens, $k );
						$place = Keywords_Text::fold( implode( ' ', $rest ) );
						if ( count( $rest ) <= 4 && strlen( $place ) >= 3 && ! in_array( $place, Keywords_Text::stopwords(), true ) ) {
							$places[ $place ] = true;
							if ( ' county' === substr( $place, -7 ) ) {
								$places[ substr( $place, 0, -7 ) ] = true;
							}
						}
						break;
					}
				}
			}
		}

		$places = array_keys( $places );
		usort(
			$places,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		self::$places = (array) apply_filters( 'convertrack_autoapply_places', $places );
		return self::$places;
	}

	/**
	 * Places in a folded string, longest match first (so "north miami beach"
	 * is not also counted as "miami").
	 *
	 * @param string $folded Folded text.
	 * @return string[]
	 */
	public static function match_places( $folded ) {
		$found = array();
		$text  = ' ' . $folded . ' ';
		foreach ( self::places() as $place ) {
			if ( Keywords_Text::contains_phrase( $text, $place ) ) {
				$found[] = $place;
				$text    = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $place, '/' ) . '(?![\p{L}\p{N}])/u', ' | ', $text );
			}
		}
		return $found;
	}

	/**
	 * Word roots for coverage checks: groomer/groomers/grooming -> groom.
	 *
	 * @param string $folded Folded text.
	 * @return string[]
	 */
	public static function roots( $folded ) {
		$out = array();
		foreach ( Keywords_Text::significant_tokens( $folded ) as $token ) {
			if ( in_array( $token, array( 'fl', 'florida' ), true ) ) {
				continue;
			}
			$root = Keywords_Text::stem( $token );
			if ( strlen( $root ) > 5 && 'ing' === substr( $root, -3 ) ) {
				$root = substr( $root, 0, -3 );
			} elseif ( strlen( $root ) > 4 && 'er' === substr( $root, -2 ) ) {
				$root = substr( $root, 0, -2 );
			}
			$out[ $root ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * The keyword minus places, "near me" words and filler, title-cased:
	 * "dog grooming aventura" -> "Dog Grooming".
	 *
	 * Leading words the title already has are trimmed (while two words
	 * remain), so "mobile dog grooming" on a "Mobile Pet Grooming" title
	 * adds "Dog Grooming", not a near-duplicate phrase.
	 *
	 * @param string $query       Query.
	 * @param array  $title_roots Roots already in the title.
	 * @return string
	 */
	public static function variant( $query, array $title_roots = array() ) {
		$folded = Keywords_Text::fold( $query );
		$text   = ' ' . $folded . ' ';
		foreach ( self::match_places( $folded ) as $place ) {
			$text = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $place, '/' ) . '(?![\p{L}\p{N}])/u', ' ', $text );
		}

		$drop   = array_merge( Keywords_Text::stopwords(), array( 'near', 'me', 'nearby', 'closest', 'fl', 'florida', 'best', 'top', 'cheap', 'affordable', 'open', 'now', 'today' ) );
		$tokens = array_values( array_diff( Keywords_Text::tokens( trim( $text ) ), $drop ) );
		while ( count( $tokens ) > 2 && in_array( self::roots( $tokens[0] )[0] ?? $tokens[0], $title_roots, true ) ) {
			array_shift( $tokens );
		}
		if ( ! $tokens ) {
			return '';
		}
		return ucwords( implode( ' ', $tokens ) );
	}

	/**
	 * Insert the variant after the title's primary segment, keeping the
	 * primary phrase and brand; drops middle segments to fit the limit.
	 *
	 * @param string $title   Current resolved title.
	 * @param string $variant Variant to add.
	 * @param int    $max     Max characters.
	 * @return string New title, or '' when it cannot fit.
	 */
	public static function compose_title( $title, $variant, $max ) {
		$sep = ' | ';
		foreach ( array( ' | ', ' - ', ' – ', ' — ', ' · ' ) as $candidate ) {
			if ( false !== strpos( $title, $candidate ) ) {
				$sep = $candidate;
				break;
			}
		}

		$segments = array_values( array_filter( array_map( 'trim', explode( $sep, $title ) ), 'strlen' ) );
		$primary  = array_shift( $segments );
		$brand    = '';
		if ( $segments && self::is_branded( end( $segments ) ) ) {
			$brand = array_pop( $segments );
		}
		$middle = $segments;

		$options = array();
		for ( $keep = count( $middle ); $keep >= 0; $keep-- ) {
			$options[] = array_merge( array( $primary, $variant ), array_slice( $middle, 0, $keep ), $brand ? array( $brand ) : array() );
		}
		if ( $brand ) {
			$options[] = array( $primary, $variant );
		}

		foreach ( $options as $parts ) {
			$candidate = implode( $sep, $parts );
			$length    = function_exists( 'mb_strlen' ) ? mb_strlen( $candidate ) : strlen( $candidate );
			if ( $length <= $max ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Append a keyword to Rank Math's comma-separated focus keywords
	 * (primary stays first; max 5).
	 *
	 * @param string $focus Current value.
	 * @param string $query Keyword.
	 * @return string
	 */
	public static function add_focus( $focus, $query ) {
		$list   = array_values( array_filter( array_map( 'trim', explode( ',', (string) $focus ) ), 'strlen' ) );
		$folded = Keywords_Text::fold( $query );
		foreach ( $list as $item ) {
			if ( Keywords_Text::fold( $item ) === $folded ) {
				return $focus;
			}
		}
		if ( count( $list ) >= 5 ) {
			return $focus;
		}
		$list[] = sanitize_text_field( $query );
		return implode( ',', $list );
	}

	/**
	 * Page-level Search Console metrics for a post in a range.
	 *
	 * @param int    $post_id Post id.
	 * @param string $range   Range key.
	 * @return array { range, synced_at, clicks, impressions, ctr, position }
	 */
	public static function page_metrics( $post_id, $range ) {
		global $wpdb;
		$pages = Keywords_Database::pages_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions,
					SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) AS position
				FROM $pages WHERE post_id = %d AND range_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$range
			),
			ARRAY_A
		);
		$clicks      = (int) ( $row['clicks'] ?? 0 );
		$impressions = (int) ( $row['impressions'] ?? 0 );
		$last        = get_option( 'convertrack_gsc_keywords_last_sync', array() );

		return array(
			'range'       => $range,
			'synced_at'   => isset( $last[ $range ]['time'] ) ? (string) $last[ $range ]['time'] : '',
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? round( $clicks / $impressions * 100, 2 ) : 0.0,
			'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 1 ) : 0.0,
		);
	}

	/* ------------------------------------------------------------------
	 * Apply / measure / revert
	 * ------------------------------------------------------------------ */

	/**
	 * Write a plan to the post and record it.
	 *
	 * @param array $plan Plan from plan().
	 * @return array Stored history entry.
	 */
	private static function apply( array $plan ) {
		$pid = (int) $plan['post_id'];

		if ( $plan['after']['title'] !== $plan['before']['title'] ) {
			update_post_meta( $pid, 'rank_math_title', wp_slash( $plan['after']['title'] ) );
		}
		if ( $plan['after']['focus'] !== $plan['before']['focus'] ) {
			update_post_meta( $pid, 'rank_math_focus_keyword', wp_slash( $plan['after']['focus'] ) );
		}

		$entry = array(
			'id'         => wp_generate_uuid4(),
			'ts'         => time(),
			'applied_at' => current_time( 'mysql' ),
			'status'     => 'applied',
			'keyword'    => $plan['keyword'],
			'variant'    => $plan['variant'],
			'kw_stats'   => $plan['kw_stats'],
			'before'     => $plan['before'],
			'after'      => $plan['after'],
			'baseline'   => $plan['baseline'],
		);

		$history   = self::history( $pid );
		$history[] = $entry;
		update_post_meta( $pid, self::META, array_slice( $history, -self::HISTORY_MAX ) );

		self::log( $pid, $entry, 'applied' );
		self::touch( $pid );
		return $entry;
	}

	/**
	 * Measure live changes whose evaluation window has passed; revert the
	 * ones that lost clicks/CTR while impressions held.
	 *
	 * @param array $s       Settings.
	 * @param bool  $dry_run Report only.
	 * @return array[] Evaluation results.
	 */
	public static function evaluate( array $s, $dry_run = false ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = array();
		$window = (int) $s['eval_days'] * DAY_IN_SECONDS;
		$drop   = (int) $s['revert_drop'] / 100;

		foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
			$history = self::history( $pid );
			$index   = self::last_index( $history, array( 'applied' ) );
			if ( null === $index ) {
				continue;
			}
			$entry = $history[ $index ];
			$range = isset( $entry['baseline']['range'] ) ? $entry['baseline']['range'] : $s['range'];

			// Needs a full post-change data window: N days since the change
			// AND a sync that happened at least N days after it.
			$applied_local = strtotime( (string) $entry['applied_at'] );
			if ( time() - (int) $entry['ts'] < $window || self::synced_ts( $range ) < $applied_local + $window ) {
				continue;
			}

			$base  = (array) $entry['baseline'];
			$now   = self::page_metrics( $pid, $range );
			$worse = false;
			if ( (int) $base['clicks'] >= 3 && $now['clicks'] < $base['clicks'] * ( 1 - $drop ) && $now['impressions'] >= $base['impressions'] * 0.7 ) {
				$worse = true;
			} elseif ( (int) $base['impressions'] >= 100 && (float) $base['ctr'] > 0 && $now['impressions'] >= $base['impressions'] * 0.7 && $now['ctr'] < $base['ctr'] * ( 1 - $drop ) ) {
				$worse = true;
			}

			$result = array(
				'post_id'  => $pid,
				'title'    => get_the_title( $pid ),
				'keyword'  => $entry['keyword'],
				'baseline' => $base,
				'after'    => $now,
				'verdict'  => $worse ? 'worse' : 'kept',
				'action'   => 'none',
			);

			if ( ! $dry_run ) {
				$history[ $index ]['evaluation']   = array(
					'time'    => current_time( 'mysql' ),
					'metrics' => $now,
					'verdict' => $result['verdict'],
				);
				$history[ $index ]['status'] = 'kept';
				update_post_meta( $pid, self::META, $history );

				if ( $worse && $s['auto_revert'] ) {
					$reverted         = self::revert( $pid, 'reverted', false );
					$result['action'] = true === $reverted ? 'reverted' : 'revert-skipped: ' . $reverted;
				} else {
					self::log( $pid, $history[ $index ], 'kept' );
				}
			}
			$out[] = $result;
		}
		return $out;
	}

	/**
	 * Restore the values captured before the latest applied/kept change.
	 *
	 * Refuses when the fields were edited by hand since the change, unless
	 * forced, so manual work is never overwritten.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  New status: reverted|undone.
	 * @param bool   $force   Overwrite manual edits.
	 * @return true|string True, or the reason nothing was restored.
	 */
	public static function revert( $post_id, $status = 'undone', $force = false ) {
		$pid     = (int) $post_id;
		$history = self::history( $pid );
		$index   = self::last_index( $history, array( 'applied', 'kept' ) );
		if ( null === $index ) {
			return __( 'No active auto-applied change on this post.', 'convertrack-click-conversion-analytics' );
		}
		$entry = $history[ $index ];

		$cur_title = (string) get_post_meta( $pid, 'rank_math_title', true );
		$cur_focus = (string) get_post_meta( $pid, 'rank_math_focus_keyword', true );
		$changed_title = $entry['after']['title'] !== $entry['before']['title'];
		$changed_focus = $entry['after']['focus'] !== $entry['before']['focus'];

		if ( ! $force && ( ( $changed_title && $cur_title !== $entry['after']['title'] ) || ( $changed_focus && $cur_focus !== $entry['after']['focus'] ) ) ) {
			return __( 'The SEO title or focus keyword was edited after the change; use force to overwrite.', 'convertrack-click-conversion-analytics' );
		}

		if ( $changed_title ) {
			if ( '' === (string) $entry['before']['title_raw'] ) {
				delete_post_meta( $pid, 'rank_math_title' );
			} else {
				update_post_meta( $pid, 'rank_math_title', wp_slash( $entry['before']['title_raw'] ) );
			}
		}
		if ( $changed_focus ) {
			if ( '' === (string) $entry['before']['focus'] ) {
				delete_post_meta( $pid, 'rank_math_focus_keyword' );
			} else {
				update_post_meta( $pid, 'rank_math_focus_keyword', wp_slash( $entry['before']['focus'] ) );
			}
		}

		$history[ $index ]['status']      = $status;
		$history[ $index ]['reverted_at'] = current_time( 'mysql' );
		$history[ $index ]['ts_reverted'] = time();
		update_post_meta( $pid, self::META, $history );

		self::log( $pid, $history[ $index ], $status );
		self::touch( $pid );
		return true;
	}

	/**
	 * Change history for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array[]
	 */
	public static function history( $post_id ) {
		$history = get_post_meta( (int) $post_id, self::META, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Latest history entry, or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	private static function last_entry( $post_id ) {
		$history = self::history( $post_id );
		if ( ! $history ) {
			return null;
		}
		$entry = end( $history );
		// Cooldown counts from the most recent write (apply or revert).
		if ( ! empty( $entry['ts_reverted'] ) ) {
			$entry['ts'] = (int) $entry['ts_reverted'];
		}
		return $entry;
	}

	/**
	 * Index of the newest entry with one of the statuses, or null.
	 *
	 * @param array $history  History.
	 * @param array $statuses Statuses.
	 * @return int|null
	 */
	private static function last_index( array $history, array $statuses ) {
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( in_array( $history[ $i ]['status'], $statuses, true ) ) {
				return $i;
			}
			// Only the newest change can be active.
			if ( in_array( $history[ $i ]['status'], array( 'reverted', 'undone' ), true ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Append to the site-wide change log.
	 *
	 * @param int    $post_id Post id.
	 * @param array  $entry   History entry.
	 * @param string $event   applied|kept|reverted|undone.
	 */
	private static function log( $post_id, array $entry, $event ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'event'   => $event,
				'post_id' => (int) $post_id,
				'keyword' => (string) $entry['keyword'],
				'before'  => (string) $entry['before']['title'],
				'after'   => (string) $entry['after']['title'],
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * Refresh analysis and caches after a write.
	 *
	 * @param int $post_id Post id.
	 */
	private static function touch( $post_id ) {
		Keywords_Database::mark_post_stale( $post_id );
		Keywords_Cron::kick_analyze( MINUTE_IN_SECONDS );
		clean_post_cache( $post_id );

		$url = get_permalink( $post_id );
		if ( $url && function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}
		do_action( 'litespeed_purge_post', $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		do_action( 'convertrack_autoapply_changed', $post_id );
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Register the hidden admin screen (reached from the Search & SEO subnav).
	 */
	public function add_menu() {
		add_submenu_page(
			null,
			__( 'Auto-Apply', 'convertrack-click-conversion-analytics' ),
			__( 'Auto-Apply', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require CONVERTRACK_DIR . 'admin/views/gsc-autoapply.php';
	}

	/**
	 * Verify an admin-post request.
	 *
	 * @param string $action Nonce action.
	 */
	private function verify( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the screen with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private function back( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'cvtrk_aa' => sanitize_key( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Save settings. */
	public function handle_save() {
		$this->verify( 'convertrack_autoapply_save' );
		$input = isset( $_POST['autoapply'] ) && is_array( $_POST['autoapply'] ) ? wp_unslash( $_POST['autoapply'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::save_settings( array_map( static function ( $v ) { return is_array( $v ) ? $v : sanitize_text_field( $v ); }, $input ), true );
		$this->back( 'saved' );
	}

	/** Run now (dry or live). */
	public function handle_run() {
		$this->verify( 'convertrack_autoapply_run' );
		$live = isset( $_POST['live'] ) && '1' === $_POST['live']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::run(
			array(
				'dry_run' => ! $live,
				'trigger' => 'admin',
			)
		);
		$this->back( $live ? 'ran-live' : 'ran-dry' );
	}

	/** Undo the latest change on a post. */
	public function handle_undo() {
		$this->verify( 'convertrack_autoapply_undo' );
		$pid    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$force  = ! empty( $_POST['force'] );
		$result = $pid ? self::revert( $pid, 'undone', $force ) : 'missing post';
		$this->back( true === $result ? 'undone' : 'undo-blocked' );
	}

	/* ------------------------------------------------------------------
	 * WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * Run auto-apply.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Plan only (default follows the configured mode).
	 *
	 * [--live]
	 * : Write changes even if the configured mode is dry-run.
	 *
	 * [--limit=<n>]
	 * : Max pages to change.
	 *
	 * [--post=<id>]
	 * : Only this post.
	 *
	 * [--format=<format>]
	 * : table|json. Default table.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public static function cli_run( $args, $assoc ) {
		$opts = array( 'trigger' => 'cli' );
		if ( isset( $assoc['dry-run'] ) ) {
			$opts['dry_run'] = true;
		} elseif ( isset( $assoc['live'] ) ) {
			$opts['dry_run'] = false;
		}
		if ( ! empty( $assoc['limit'] ) ) {
			$opts['limit'] = (int) $assoc['limit'];
		}
		if ( ! empty( $assoc['post'] ) ) {
			$opts['post_id'] = (int) $assoc['post'];
		}

		$report = self::run( $opts );
		if ( isset( $assoc['format'] ) && 'json' === $assoc['format'] ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( $report['blocked'] ) {
			\WP_CLI::warning( $report['blocked'] );
			return;
		}

		\WP_CLI::line( sprintf( '%s run · range %s · %d evaluated · %d %s · %d skipped', $report['dry_run'] ? 'DRY' : 'LIVE', $report['range'], count( $report['evaluated'] ), count( $report['applied'] ), $report['dry_run'] ? 'planned' : 'applied', count( $report['skipped'] ) ) );
		if ( $report['applied'] ) {
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( $p ) {
						return array(
							'post'    => $p['post_id'],
							'keyword' => $p['keyword'] . ' (' . $p['kw_stats']['impressions'] . 'i, p' . $p['kw_stats']['position'] . ')',
							'before'  => $p['before']['title'],
							'after'   => $p['after']['title'],
						);
					},
					$report['applied']
				),
				array( 'post', 'keyword', 'before', 'after' )
			);
		}
		if ( $report['skipped'] ) {
			\WP_CLI\Utils\format_items( 'table', $report['skipped'], array( 'post_id', 'title', 'reason' ) );
		}
		foreach ( $report['evaluated'] as $e ) {
			\WP_CLI::line( sprintf( 'eval #%d %s: clicks %d->%d, ctr %s->%s %s', $e['post_id'], $e['verdict'], $e['baseline']['clicks'], $e['after']['clicks'], $e['baseline']['ctr'], $e['after']['ctr'], $e['action'] ) );
		}
	}

	/**
	 * Show settings, schedule and the latest change log.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public static function cli_status( $args, $assoc ) {
		$s    = self::settings();
		$next = self::next_run();
		\WP_CLI::line( 'Settings: ' . wp_json_encode( $s ) );
		\WP_CLI::line( 'Next scheduled run: ' . ( $next ? wp_date( 'Y-m-d H:i', $next ) : 'not scheduled' ) );
		$last = get_option( self::LAST_RUN );
		if ( $last ) {
			\WP_CLI::line( sprintf( 'Last run: %s (%s) %s', $last['time'], $last['dry_run'] ? 'dry' : 'live', $last['blocked'] ? 'BLOCKED: ' . $last['blocked'] : count( $last['applied'] ) . ' changes' ) );
		}
		$log = array_slice( (array) get_option( self::LOG_OPTION, array() ), 0, 20 );
		if ( $log ) {
			\WP_CLI\Utils\format_items( 'table', $log, array( 'time', 'event', 'post_id', 'keyword', 'after' ) );
		}
	}

	/**
	 * Undo the latest auto-applied change on a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post id.
	 *
	 * [--force]
	 * : Overwrite manual edits made after the change.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public static function cli_undo( $args, $assoc ) {
		$result = self::revert( (int) $args[0], 'undone', isset( $assoc['force'] ) );
		true === $result ? \WP_CLI::success( 'Restored the previous SEO title and focus keyword.' ) : \WP_CLI::error( $result );
	}

	/**
	 * Show or change settings: wp convertrack autoapply config --enabled=1 --mode=live
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public static function cli_config( $args, $assoc ) {
		$s = $assoc ? self::save_settings( $assoc ) : self::settings();
		\WP_CLI::line( wp_json_encode( $s, JSON_PRETTY_PRINT ) );
	}
}
