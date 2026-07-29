<?php
/**
 * Redirect conflict diagnosis.
 *
 * A conflict is a URL that keeps returning 404 even though a redirect rule for
 * it exists. The module already records both halves of that contradiction -- it
 * simply never compared them: an event whose last_detected_at post-dates the
 * rule's activation is, by definition, traffic still 404ing past a live rule.
 *
 * classify() is deliberately pure: no database, no HTTP, no options. Every input
 * arrives in $context, so the whole verdict vocabulary is testable offline.
 * apply() is the only part that changes anything, and it runs solely from an
 * explicit user action.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Conflicts {

	const MONITOR_DISABLED      = 'monitor_disabled';
	const RULE_PAUSED           = 'rule_paused';
	const DESTINATION_UNHEALTHY = 'destination_unhealthy';
	const DESTINATION_IS_404    = 'destination_is_404';
	const REDIRECT_LOOP         = 'redirect_loop';
	const EXTERNAL_TOOL         = 'external_tool_conflict';
	const QUERY_STRING_MISMATCH = 'query_string_mismatch';
	const SERVER_INTERCEPTS     = 'server_intercepts';
	const RESOLVED             = 'resolved';
	const UNKNOWN              = 'unknown';

	/** Redirect owners. */
	const OWNER_INTERNAL    = 'internal';
	const OWNER_REDIRECTION = 'redirection';
	const OWNER_RANK_MATH   = 'rank_math';
	const OWNER_UNKNOWN     = 'unknown';

	/** Fix codes. Only these three are safe to apply automatically. */
	const FIX_RESUME_RULE  = 'resume_rule';
	const FIX_REPOINT_RULE = 'repoint_rule';
	const FIX_MARK_RESOLVED = 'mark_resolved';
	const FIX_EXACT_RULE   = 'add_exact_rule';
	const FIX_NONE         = '';

	/**
	 * Decide why a URL still 404s despite a redirect existing.
	 *
	 * Precedence is the design:
	 *  1. A live probe showing a redirect or a real page settles it -- the rule
	 *     works and the event row is merely stale, whatever else looks wrong.
	 *  2. Monitoring being off outranks every per-rule cause, because it disables
	 *     every rule on the site at once; fixing one rule would change nothing.
	 *  3. Then rule state, then the destination, then interception.
	 *
	 * @param array $event   Event row (url, query_string, last_detected_at).
	 * @param array $context {
	 *     internal_rule    array|null Our redirect row, any status.
	 *     external_rule    array|null Redirection/Rank Math row.
	 *     external_tool    string     Tool key when external_rule is set.
	 *     monitor_enabled  bool       Settings::get('enabled').
	 *     probe_status     int        HTTP status of the source, 0 when unprobed.
	 *     destination_probe int       HTTP status of the destination, 0 when unprobed.
	 *     loop             bool       Chain returns to the source.
	 * }
	 * @return string Verdict code.
	 */
	public static function classify( array $event, array $context ) {
		$internal = isset( $context['internal_rule'] ) && is_array( $context['internal_rule'] ) ? $context['internal_rule'] : null;
		$external = isset( $context['external_rule'] ) && is_array( $context['external_rule'] ) ? $context['external_rule'] : null;
		$enabled  = ! empty( $context['monitor_enabled'] );
		$probe    = isset( $context['probe_status'] ) ? (int) $context['probe_status'] : 0;
		$dest     = isset( $context['destination_probe'] ) ? (int) $context['destination_probe'] : 0;

		// Ground truth first. If the URL now redirects or resolves, there is no
		// conflict left to explain regardless of how the rule looks on paper.
		if ( $probe >= 300 && $probe < 400 ) {
			return self::RESOLVED;
		}
		if ( 200 === $probe ) {
			return self::RESOLVED;
		}

		// Nothing owns this URL, so there is no contradiction to diagnose.
		if ( null === $internal && null === $external ) {
			return self::UNKNOWN;
		}

		// Site-wide before per-rule: with monitoring off, no internal rule can
		// fire, so a paused-rule verdict would send the operator to fix the wrong
		// thing. Only meaningful when the stalled rule is actually ours.
		if ( ! $enabled && null !== $internal ) {
			return self::MONITOR_DISABLED;
		}

		if ( null !== $internal ) {
			$status = isset( $internal['status'] ) ? (string) $internal['status'] : '';
			if ( in_array( $status, array( 'paused', 'disabled' ), true ) ) {
				return self::RULE_PAUSED;
			}

			if ( ! empty( $context['loop'] ) ) {
				return self::REDIRECT_LOOP;
			}

			if ( $dest >= 400 ) {
				return self::DESTINATION_IS_404;
			}
			if ( 'unhealthy' === ( isset( $internal['health_status'] ) ? (string) $internal['health_status'] : '' ) ) {
				return self::DESTINATION_UNHEALTHY;
			}

			// The rule stores one exact source, hash and all. A request carrying a
			// query string the rule does not have never matches it, so the rule can
			// look perfect and still never fire for this traffic.
			if ( self::query_mismatch( $event, $internal ) ) {
				return self::QUERY_STRING_MISMATCH;
			}

			// Rule is active, healthy, loop-free and matches -- yet the probe still
			// says 404. Something ahead of WordPress is answering.
			if ( $probe >= 400 ) {
				return self::SERVER_INTERCEPTS;
			}

			return self::UNKNOWN;
		}

		// Only a third-party tool owns it, and the URL still 404s. Their rule, so
		// their fix -- naming the tool is the useful part.
		return self::EXTERNAL_TOOL;
	}

	/**
	 * Whether the event's query string keeps it from matching the rule.
	 *
	 * @param array $event    Event row.
	 * @param array $internal Our redirect row.
	 * @return bool
	 */
	private static function query_mismatch( array $event, array $internal ) {
		$event_query = isset( $event['query_string'] ) ? trim( (string) $event['query_string'] ) : '';
		if ( '' === $event_query ) {
			return false;
		}
		$rule_source = isset( $internal['source_url'] ) ? (string) $internal['source_url'] : '';
		// The rule's stored source carries its query inline after '?'.
		return false === strpos( $rule_source, '?' );
	}

	/**
	 * Full descriptor for a verdict: label, explanation, severity, fix.
	 *
	 * @param string $code Verdict code.
	 * @return array
	 */
	public static function descriptor( $code ) {
		$all  = self::all();
		$code = (string) $code;
		return isset( $all[ $code ] ) ? $all[ $code ] : $all[ self::UNKNOWN ];
	}

	/**
	 * Every verdict, ordered by how urgently it needs attention.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			self::MONITOR_DISABLED => array(
				'label'    => __( 'Broken URL monitoring is switched off', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'A redirect rule exists for this URL, but Broken URL monitoring is off, so no rule on this site can fire. Turn monitoring on in the settings below to activate every rule at once.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'critical',
				'scope'    => 'site',
				'fix'      => self::FIX_NONE,
			),
			self::RULE_PAUSED => array(
				'label'    => __( 'Redirect rule is paused', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'The rule for this URL exists but is paused or disabled, so visitors still reach a 404. Resuming it revalidates the destination first.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'critical',
				'scope'    => 'rule',
				'fix'      => self::FIX_RESUME_RULE,
			),
			self::DESTINATION_IS_404 => array(
				'label'    => __( 'Redirect destination is missing', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'The rule fires, but the page it points at no longer exists, so visitors land on a 404 anyway. Pick a destination that still exists.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'critical',
				'scope'    => 'rule',
				'fix'      => self::FIX_REPOINT_RULE,
			),
			self::DESTINATION_UNHEALTHY => array(
				'label'    => __( 'Redirect destination is unreachable', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'The last health check could not load this rule\'s destination. Visitors are being sent somewhere broken.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'critical',
				'scope'    => 'rule',
				'fix'      => self::FIX_REPOINT_RULE,
			),
			self::REDIRECT_LOOP => array(
				'label'    => __( 'Redirect loops back to itself', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'Following this rule leads back to the same URL, so the browser refuses it. Point it at a different page.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'critical',
				'scope'    => 'rule',
				'fix'      => self::FIX_REPOINT_RULE,
			),
			self::QUERY_STRING_MISMATCH => array(
				'label'    => __( 'Rule does not cover this URL\'s query string', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'The rule matches the plain address, but visitors are arriving with a query string on the end, which the rule does not match. A rule for this exact address will catch them.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'warning',
				'scope'    => 'rule',
				'fix'      => self::FIX_EXACT_RULE,
			),
			self::EXTERNAL_TOOL => array(
				'label'    => __( 'Another redirect plugin owns this URL', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'A rule for this URL lives in another redirect plugin and is not taking effect. Convertrack never edits other tools\' rules, so this one has to be fixed there.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'warning',
				'scope'    => 'external',
				'fix'      => self::FIX_NONE,
			),
			self::SERVER_INTERCEPTS => array(
				'label'    => __( 'Something is answering before WordPress', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'The rule is active and its destination is fine, yet this URL still returns 404 when tested. A page cache, a CDN, or a server-level rule is responding before WordPress gets the request. Clearing your cache is the usual fix.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'warning',
				'scope'    => 'server',
				'fix'      => self::FIX_NONE,
			),
			self::RESOLVED => array(
				'label'    => __( 'Working now', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'This URL redirects correctly when tested. The 404 record is left over from before the rule existed and can be cleared.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'info',
				'scope'    => 'none',
				'fix'      => self::FIX_MARK_RESOLVED,
			),
			self::UNKNOWN => array(
				'label'    => __( 'Cause not determined', 'convertrack-click-conversion-analytics' ),
				'summary'  => __( 'A redirect exists for this URL but the cause of the 404 could not be pinned down automatically. Test the URL in a private browser window to see what happens.', 'convertrack-click-conversion-analytics' ),
				'severity' => 'warning',
				'scope'    => 'none',
				'fix'      => self::FIX_NONE,
			),
		);
	}

	/**
	 * Human label for a redirect owner.
	 *
	 * @param string $owner Owner key.
	 * @return string
	 */
	public static function owner_label( $owner ) {
		switch ( (string) $owner ) {
			case self::OWNER_INTERNAL:
				return __( 'Convertrack', 'convertrack-click-conversion-analytics' );
			case self::OWNER_REDIRECTION:
				return __( 'Redirection', 'convertrack-click-conversion-analytics' );
			case self::OWNER_RANK_MATH:
				return __( 'Rank Math', 'convertrack-click-conversion-analytics' );
		}
		return __( 'Unknown', 'convertrack-click-conversion-analytics' );
	}

	/**
	 * Whether a verdict code is known.
	 *
	 * @param string $code Verdict code.
	 * @return bool
	 */
	public static function is_valid( $code ) {
		return array_key_exists( (string) $code, self::all() );
	}

	/**
	 * Verdicts that represent an unresolved problem.
	 *
	 * @return array
	 */
	public static function unresolved_codes() {
		$out = array();
		foreach ( self::all() as $code => $descriptor ) {
			if ( self::RESOLVED !== $code ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Fix code offered for a verdict, if any.
	 *
	 * @param string $code Verdict code.
	 * @return string
	 */
	public static function fix_for( $code ) {
		$descriptor = self::descriptor( $code );
		return isset( $descriptor['fix'] ) ? (string) $descriptor['fix'] : self::FIX_NONE;
	}

	/**
	 * Diagnose one event, optionally with a live probe.
	 *
	 * Gathers the context classify() needs and stores the verdict. The probe is
	 * opt-in because it costs one HTTP request per call.
	 *
	 * @param array $event Event row.
	 * @param bool  $probe Whether to confirm with a live request.
	 * @return string Verdict code.
	 */
	public static function diagnose( array $event, $probe = false ) {
		$url      = isset( $event['url'] ) ? (string) $event['url'] : '';
		$internal = '' !== $url ? Database::find_active_redirect( $url ) : null;
		$rule     = is_array( $internal ) ? $internal : null;

		// find_active_redirect() only returns active rules, so a paused rule would
		// look like no rule at all. Look it up by hash regardless of status.
		if ( null === $rule && '' !== $url ) {
			$rule = Database::redirect_for_source_any_status( $url );
		}

		$external = null;
		if ( null === $rule && '' !== $url ) {
			$external = Compatibility::external_redirect_for_source( $url );
		}

		$owner = self::OWNER_UNKNOWN;
		if ( is_array( $rule ) ) {
			$owner = self::OWNER_INTERNAL;
		} elseif ( is_array( $external ) ) {
			$tools = wp_list_pluck( Compatibility::detected_tools(), 'key' );
			$owner = in_array( 'redirection', $tools, true ) ? self::OWNER_REDIRECTION : self::OWNER_RANK_MATH;
		}

		$context = array(
			'internal_rule'   => $rule,
			'external_rule'   => $external,
			'monitor_enabled' => (bool) Settings::get( 'enabled' ),
			'probe_status'    => 0,
			'destination_probe' => 0,
			'loop'            => false,
		);

		if ( $probe && '' !== $url ) {
			$context['probe_status'] = Redirector::probe_status( home_url( $url ) );
			// Only probe the destination when the source is still failing and we own
			// the rule -- otherwise it is a wasted request.
			if ( $context['probe_status'] >= 400 && is_array( $rule ) && ! empty( $rule['destination_url'] ) ) {
				$context['destination_probe'] = Redirector::probe_status( $rule['destination_url'] );
			}
		}

		if ( is_array( $rule ) && ! empty( $rule['destination_url'] ) ) {
			$src = Database::normalize_source( $url );
			$dst = Database::normalize_source( $rule['destination_url'] );
			$context['loop'] = ! empty( $src ) && ! empty( $dst ) && $src['path'] === $dst['path'];
		}

		$verdict = self::classify( $event, $context );
		$detail  = array(
			'rule_status'  => is_array( $rule ) && isset( $rule['status'] ) ? (string) $rule['status'] : '',
			'probe_status' => $context['probe_status'],
			'destination'  => is_array( $rule ) && isset( $rule['destination_url'] ) ? (string) $rule['destination_url'] : '',
			'detected_by'  => $probe ? 'probe' : 'stored-data',
		);

		Database::save_conflict(
			(int) $event['id'],
			$verdict,
			$owner,
			is_array( $rule ) && isset( $rule['id'] ) ? (int) $rule['id'] : 0,
			$detail
		);

		if ( self::RESOLVED === $verdict ) {
			Database::clear_conflict( (int) $event['id'], Database::STATUS_ALREADY_REDIRECTED );
			Logger::info( 'redirect-conflict', 'This URL redirects correctly now, so the 404 record was closed.', array( 'event_id' => (int) $event['id'], 'url' => $url ) );
		} else {
			Logger::warning( 'redirect-conflict', 'A redirect exists for this URL but it is still returning 404.', array( 'event_id' => (int) $event['id'], 'url' => $url, 'verdict' => $verdict, 'owner' => $owner ) );
		}

		return $verdict;
	}

	/**
	 * Apply the fix a verdict offers.
	 *
	 * @param array $event Event row including conflict_code.
	 * @return array|\WP_Error {message} on success.
	 */
	public static function apply( array $event ) {
		$code = isset( $event['conflict_code'] ) ? (string) $event['conflict_code'] : '';
		$fix  = self::fix_for( $code );
		if ( self::FIX_NONE === $fix ) {
			$descriptor = self::descriptor( $code );
			return new \WP_Error(
				'convertrack_404_conflict_not_fixable',
				isset( $descriptor['summary'] ) ? $descriptor['summary'] : __( 'This conflict has to be resolved manually.', 'convertrack-click-conversion-analytics' ),
				array( 'status' => 409 )
			);
		}

		$rule_id = isset( $event['conflict_redirect_id'] ) ? (int) $event['conflict_redirect_id'] : 0;

		switch ( $fix ) {
			case self::FIX_MARK_RESOLVED:
				Database::clear_conflict( (int) $event['id'], Database::STATUS_ALREADY_REDIRECTED );
				Logger::info( 'redirect-conflict', 'Conflict marked resolved by an administrator.', array( 'event_id' => (int) $event['id'] ) );
				return array( 'message' => __( 'Closed. This URL already redirects correctly.', 'convertrack-click-conversion-analytics' ) );

			case self::FIX_RESUME_RULE:
				if ( $rule_id < 1 ) {
					return new \WP_Error( 'convertrack_404_conflict_stale', __( 'This diagnosis is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
				}
				$rule = Database::get_redirect( $rule_id );
				if ( ! $rule ) {
					return new \WP_Error( 'convertrack_404_conflict_stale', __( 'That redirect rule no longer exists.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
				}
				// Revalidate before resuming: the destination may have broken while
				// the rule sat paused, and resuming blindly would send visitors there.
				$validated = Redirector::validate_pair( $rule['source_url'], $rule['destination_url'], false );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$resumed = Database::set_redirect_status( $rule_id, 'active' );
				if ( is_wp_error( $resumed ) ) {
					return $resumed;
				}
				Database::clear_conflict( (int) $event['id'] );
				Logger::info( 'redirect-conflict', 'Paused redirect rule resumed to resolve a conflict.', array( 'event_id' => (int) $event['id'], 'rule_id' => $rule_id ) );
				return array( 'message' => __( 'Rule resumed. The URL will be re-checked to confirm it works.', 'convertrack-click-conversion-analytics' ) );

			case self::FIX_REPOINT_RULE:
				return self::repoint( $event, $rule_id );

			case self::FIX_EXACT_RULE:
				return self::add_exact_rule( $event );
		}

		return new \WP_Error( 'convertrack_404_conflict_unknown_fix', __( 'Unknown fix type.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
	}

	/**
	 * Point a broken rule at a destination that still exists.
	 *
	 * @param array $event   Event row.
	 * @param int   $rule_id Rule ID.
	 * @return array|\WP_Error
	 */
	private static function repoint( array $event, $rule_id ) {
		$url    = (string) $event['url'];
		$source = Database::normalize_source( $url );
		if ( empty( $source ) ) {
			return new \WP_Error( 'convertrack_404_conflict_bad_source', __( 'This URL is not on this site.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		// Reuse the Broken URLs matcher so a repointed rule lands on the same
		// destination the Detected list would have suggested.
		$match = Matcher::recommend( array( 'path' => $source['path'] ) );
		if ( empty( $match['url'] ) ) {
			return new \WP_Error( 'convertrack_404_conflict_no_target', __( 'No working destination could be found for this URL. Choose one yourself with Edit.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		$validated = Redirector::validate_pair( $url, $match['url'], false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$saved = Database::upsert_redirect( $url, $match['url'], (int) $event['id'], 'active' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Database::clear_conflict( (int) $event['id'] );
		Logger::info(
			'redirect-conflict',
			'Redirect rule repointed to a working destination.',
			array( 'event_id' => (int) $event['id'], 'rule_id' => $rule_id, 'destination' => $match['url'], 'confidence' => (int) $match['confidence'] )
		);
		return array(
			/* translators: %s: destination URL. */
			'message' => sprintf( __( 'Rule now points to %s.', 'convertrack-click-conversion-analytics' ), $match['url'] ),
		);
	}

	/**
	 * Add a rule for the exact URL, query string included.
	 *
	 * @param array $event Event row.
	 * @return array|\WP_Error
	 */
	private static function add_exact_rule( array $event ) {
		$url  = (string) $event['url'];
		$rule = Database::redirect_for_source_any_status( $url );

		// Inherit the destination from the path-only rule that already exists, so
		// the two stay consistent.
		$destination = is_array( $rule ) && ! empty( $rule['destination_url'] ) ? (string) $rule['destination_url'] : '';
		if ( '' === $destination ) {
			$source = Database::normalize_source( $url );
			$match  = empty( $source ) ? array( 'url' => '' ) : Matcher::recommend( array( 'path' => $source['path'] ) );
			$destination = (string) $match['url'];
		}
		if ( '' === $destination ) {
			return new \WP_Error( 'convertrack_404_conflict_no_target', __( 'No destination could be determined for this URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		$validated = Redirector::validate_pair( $url, $destination, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$saved = Database::upsert_redirect( $url, $destination, (int) $event['id'], 'active' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Database::clear_conflict( (int) $event['id'] );
		Logger::info( 'redirect-conflict', 'Exact-URL redirect rule added to cover a query string.', array( 'event_id' => (int) $event['id'], 'url' => $url, 'destination' => $destination ) );
		return array(
			/* translators: %s: destination URL. */
			'message' => sprintf( __( 'Rule added for this exact address, pointing to %s.', 'convertrack-click-conversion-analytics' ), $destination ),
		);
	}

	/**
	 * Human label for a fix code.
	 *
	 * @param string $fix Fix code.
	 * @return string
	 */
	public static function fix_label( $fix ) {
		switch ( (string) $fix ) {
			case self::FIX_RESUME_RULE:
				return __( 'Resume rule', 'convertrack-click-conversion-analytics' );
			case self::FIX_REPOINT_RULE:
				return __( 'Repoint rule', 'convertrack-click-conversion-analytics' );
			case self::FIX_MARK_RESOLVED:
				return __( 'Mark resolved', 'convertrack-click-conversion-analytics' );
			case self::FIX_EXACT_RULE:
				return __( 'Add exact rule', 'convertrack-click-conversion-analytics' );
		}
		return '';
	}
}
