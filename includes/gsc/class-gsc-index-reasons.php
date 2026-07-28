<?php
/**
 * Index coverage reason classifier.
 *
 * Google exposes no API for the aggregated Page indexing report, so the reason a
 * URL is not indexed has to be derived per URL from what the URL Inspection API
 * does return. Every field this reads is already stored on the queue row by
 * Api::parse_inspection_response(), which is what makes reclassification of
 * historic rows possible without spending inspection quota.
 *
 * Deliberately pure: no database, no HTTP, no options. Everything here is a
 * function of the row passed in, so the whole reason vocabulary is testable
 * offline.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Index_Reasons {

	const INDEXED                    = 'indexed';
	const NOT_FOUND_404              = 'not_found_404';
	const PAGE_WITH_REDIRECT         = 'page_with_redirect';
	const SOFT_404                   = 'soft_404';
	const FORBIDDEN_403              = 'forbidden_403';
	const UNAUTHORIZED_401           = 'unauthorized_401';
	const SERVER_ERROR_5XX           = 'server_error_5xx';
	const BLOCKED_4XX                = 'blocked_4xx';
	const BLOCKED_ROBOTS_TXT         = 'blocked_robots_txt';
	const NOINDEX_TAG                = 'noindex_tag';
	const DUPLICATE_GOOGLE_CANONICAL = 'duplicate_google_canonical';
	const DUPLICATE_NO_CANONICAL     = 'duplicate_no_canonical';
	const ALTERNATE_CANONICAL        = 'alternate_canonical';
	const CRAWLED_NOT_INDEXED        = 'crawled_not_indexed';
	const DISCOVERED_NOT_INDEXED     = 'discovered_not_indexed';
	const CRAWL_ERROR                = 'crawl_error';
	const NOT_INDEXED                = 'not_indexed';
	const UNKNOWN                    = 'unknown';

	/**
	 * Owner of the problem: something to fix on the site, or a Google judgement.
	 */
	const OWNER_SITE   = 'site';
	const OWNER_GOOGLE = 'google';
	const OWNER_NONE   = 'none';

	/**
	 * Classify one queue row into a single reason code.
	 *
	 * Precedence is the whole design. Page fetch state is authoritative and is
	 * checked before the coverage text, because Google frequently reports a URL
	 * as "Crawled - currently not indexed" whose fetch state is NOT_FOUND -- and
	 * a 404 is a 404 first. Robots and noindex outrank coverage for the same
	 * reason: they are causes, and the coverage text is a consequence.
	 *
	 * @param array $row Queue row or parsed inspection result.
	 * @return string Reason code.
	 */
	public static function classify( array $row ) {
		$verdict  = strtoupper( trim( self::field( $row, 'google_verdict' ) ) );
		$coverage = strtolower( trim( self::field( $row, 'coverage_state' ) ) );
		$robots    = strtoupper( trim( self::field( $row, 'robots_txt_state' ) ) );
		$indexing  = strtoupper( trim( self::field( $row, 'indexing_state' ) ) );
		$fetch     = strtoupper( trim( self::field( $row, 'page_fetch_state' ) ) );

		// A pass is a pass; nothing else needs deciding.
		if ( 'PASS' === $verdict ) {
			return self::INDEXED;
		}

		// Fetch state first: Google could not read the page, so no amount of
		// coverage prose changes what has to be fixed.
		$by_fetch = self::classify_fetch_state( $fetch );
		if ( '' !== $by_fetch ) {
			return $by_fetch;
		}

		// Blocked by robots.txt. Checked before noindex because a disallowed URL
		// is never fetched, so Google cannot have seen a meta robots tag at all.
		if ( in_array( $robots, array( 'DISALLOWED', 'BLOCKED' ), true ) || false !== strpos( strtolower( $robots ), 'disallow' ) ) {
			return self::BLOCKED_ROBOTS_TXT;
		}

		// Excluded by a noindex directive, whether a meta tag or an X-Robots-Tag.
		if ( false !== strpos( $indexing, 'BLOCKED_BY_META_TAG' )
			|| false !== strpos( $indexing, 'BLOCKED_BY_HTTP_HEADER' )
			|| false !== strpos( $indexing, 'BLOCKED_BY_ROBOTS_TAG' )
			|| false !== strpos( $coverage, 'noindex' ) ) {
			return self::NOINDEX_TAG;
		}

		$by_coverage = self::classify_coverage( $coverage, $row );
		if ( '' !== $by_coverage ) {
			return $by_coverage;
		}

		// Nothing populated at all: never inspected, or an inspection that told us
		// nothing. Distinct from "not indexed", which is a real Google answer.
		if ( '' === $coverage && '' === $fetch
			&& in_array( $robots, array( '', 'ROBOTS_TXT_STATE_UNSPECIFIED', 'UNSPECIFIED' ), true )
			&& in_array( $verdict, array( '', 'VERDICT_UNSPECIFIED', 'NEUTRAL' ), true ) ) {
			return self::UNKNOWN;
		}

		return self::NOT_INDEXED;
	}

	/**
	 * Reason implied by pageFetchState alone.
	 *
	 * These are the branches the previous mapper omitted entirely, which is why
	 * "Not found (404)" and "Page with redirect" could never appear.
	 *
	 * @param string $fetch Uppercased pageFetchState.
	 * @return string Reason code, or '' when the fetch state decides nothing.
	 */
	private static function classify_fetch_state( $fetch ) {
		if ( '' === $fetch || in_array( $fetch, array( 'SUCCESSFUL', 'PAGE_FETCH_STATE_UNSPECIFIED' ), true ) ) {
			return '';
		}

		$map = array(
			'NOT_FOUND'          => self::NOT_FOUND_404,
			'SOFT_404'           => self::SOFT_404,
			'REDIRECT_ERROR'     => self::PAGE_WITH_REDIRECT,
			'REDIRECT'           => self::PAGE_WITH_REDIRECT,
			'ACCESS_FORBIDDEN'   => self::FORBIDDEN_403,
			'ACCESS_DENIED'      => self::UNAUTHORIZED_401,
			'BLOCKED_ROBOTS_TXT' => self::BLOCKED_ROBOTS_TXT,
			'BLOCKED_4XX'        => self::BLOCKED_4XX,
			'SERVER_ERROR'       => self::SERVER_ERROR_5XX,
			'INTERNAL_CRAWL_ERROR' => self::CRAWL_ERROR,
			'INVALID_URL'        => self::CRAWL_ERROR,
		);
		if ( isset( $map[ $fetch ] ) ) {
			return $map[ $fetch ];
		}

		// Unrecognised but non-successful states still mean Google could not read
		// the page; report a crawl error rather than silently ignoring it.
		return self::CRAWL_ERROR;
	}

	/**
	 * Reason implied by the coverage text.
	 *
	 * Google localises and reworks these strings, so match on substrings rather
	 * than equality.
	 *
	 * @param string $coverage Lowercased coverage state.
	 * @param array  $row      Queue row, for canonical comparison.
	 * @return string Reason code, or '' when the coverage text decides nothing.
	 */
	private static function classify_coverage( $coverage, array $row ) {
		if ( '' === $coverage ) {
			return '';
		}

		if ( false !== strpos( $coverage, 'not found' ) || false !== strpos( $coverage, '404' ) ) {
			return self::NOT_FOUND_404;
		}
		if ( false !== strpos( $coverage, 'redirect' ) ) {
			return self::PAGE_WITH_REDIRECT;
		}
		if ( false !== strpos( $coverage, 'forbidden' ) ) {
			return self::FORBIDDEN_403;
		}
		if ( false !== strpos( $coverage, 'unauthorized' ) ) {
			return self::UNAUTHORIZED_401;
		}

		// "Alternate page with proper canonical tag" is working as intended: the
		// page is a deliberate duplicate pointing at its canonical. Separate it
		// from real duplicate problems before any canonical matching.
		if ( false !== strpos( $coverage, 'alternate page' ) ) {
			return self::ALTERNATE_CANONICAL;
		}
		if ( false !== strpos( $coverage, 'duplicate' ) || false !== strpos( $coverage, 'canonical' ) ) {
			if ( false !== strpos( $coverage, 'without user-selected canonical' )
				|| false !== strpos( $coverage, 'no user-selected canonical' ) ) {
				return self::DUPLICATE_NO_CANONICAL;
			}
			if ( self::canonicals_disagree( $row ) || false !== strpos( $coverage, 'google chose different' ) ) {
				return self::DUPLICATE_GOOGLE_CANONICAL;
			}
			return self::DUPLICATE_NO_CANONICAL;
		}

		if ( false !== strpos( $coverage, 'discovered' ) ) {
			return self::DISCOVERED_NOT_INDEXED;
		}
		if ( false !== strpos( $coverage, 'crawled' ) ) {
			return self::CRAWLED_NOT_INDEXED;
		}
		if ( false !== strpos( $coverage, 'indexed' ) && false === strpos( $coverage, 'not indexed' ) ) {
			return self::INDEXED;
		}

		return '';
	}

	/**
	 * Whether Google picked a canonical the site did not declare.
	 *
	 * @param array $row Queue row.
	 * @return bool
	 */
	private static function canonicals_disagree( array $row ) {
		$user   = self::normalize_canonical( self::field( $row, 'user_canonical' ) );
		$google = self::normalize_canonical( self::field( $row, 'google_canonical' ) );
		if ( '' === $user || '' === $google ) {
			return false;
		}
		return $user !== $google;
	}

	/**
	 * Compare canonicals without tripping over cosmetic differences.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_canonical( $url ) {
		$url = strtolower( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		$url = preg_replace( '#^https?://#', '', $url );
		return rtrim( $url, '/' );
	}

	/**
	 * Full descriptor for a reason code: label, owner, severity, fixability.
	 *
	 * Labels intentionally mirror Search Console's own wording so the screen can
	 * be read side by side with the Page indexing report.
	 *
	 * @param string $reason Reason code.
	 * @return array
	 */
	public static function descriptor( $reason ) {
		$all = self::all();
		$reason = (string) $reason;
		return isset( $all[ $reason ] ) ? $all[ $reason ] : $all[ self::UNKNOWN ];
	}

	/**
	 * Every reason, in the order the breakdown panel should present them:
	 * site-owned problems that are actionable first, Google judgements next,
	 * informational states last.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			self::NOT_FOUND_404 => array(
				'label'    => __( 'Not found (404)', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'critical',
				'is_error' => true,
				'summary'  => __( 'Google requested this URL and the site returned 404. Any links or search results pointing here are dead ends.', 'convertrack-click-conversion-analytics' ),
			),
			self::SERVER_ERROR_5XX => array(
				'label'    => __( 'Server error (5xx)', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'critical',
				'is_error' => true,
				'summary'  => __( 'The server failed while Google was fetching this URL. This usually needs a host or error-log investigation rather than a content change.', 'convertrack-click-conversion-analytics' ),
			),
			self::BLOCKED_ROBOTS_TXT => array(
				'label'    => __( 'Blocked by robots.txt', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'critical',
				'is_error' => true,
				'summary'  => __( 'A robots.txt rule stops Google requesting this URL, so it can never be indexed while the rule stands.', 'convertrack-click-conversion-analytics' ),
			),
			self::NOINDEX_TAG => array(
				'label'    => __( 'Excluded by ‘noindex’ tag', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'critical',
				'is_error' => true,
				'summary'  => __( 'The page tells Google not to index it, via a meta robots tag or an X-Robots-Tag header.', 'convertrack-click-conversion-analytics' ),
			),
			self::PAGE_WITH_REDIRECT => array(
				'label'    => __( 'Page with redirect', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'This URL redirects elsewhere, so it is not indexable itself. Google indexes the destination instead.', 'convertrack-click-conversion-analytics' ),
			),
			self::SOFT_404 => array(
				'label'    => __( 'Soft 404', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'The URL returns a success status but the page looks empty or missing to Google. Either give it real content or return a genuine 404.', 'convertrack-click-conversion-analytics' ),
			),
			self::FORBIDDEN_403 => array(
				'label'    => __( 'Blocked due to access forbidden (403)', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'The server refused Google access. Check file permissions, a security plugin, or firewall rules blocking Googlebot.', 'convertrack-click-conversion-analytics' ),
			),
			self::UNAUTHORIZED_401 => array(
				'label'    => __( 'Blocked due to unauthorized request (401)', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'The URL requires authentication, so Google cannot reach it. Expected on staging or members-only pages.', 'convertrack-click-conversion-analytics' ),
			),
			self::BLOCKED_4XX => array(
				'label'    => __( 'Blocked due to another 4xx issue', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'The server returned a 4xx status other than 404, 401 or 403 when Google fetched this URL.', 'convertrack-click-conversion-analytics' ),
			),
			self::CRAWL_ERROR => array(
				'label'    => __( 'Crawl error', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_SITE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google hit an error fetching this URL that does not map to a specific status code.', 'convertrack-click-conversion-analytics' ),
			),
			self::DUPLICATE_GOOGLE_CANONICAL => array(
				'label'    => __( 'Duplicate, Google chose different canonical than user', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_GOOGLE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google considers this a duplicate and picked a different canonical than the one the page declares.', 'convertrack-click-conversion-analytics' ),
			),
			self::DUPLICATE_NO_CANONICAL => array(
				'label'    => __( 'Duplicate without user-selected canonical', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_GOOGLE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google sees this as a duplicate and the page declares no canonical of its own, so Google chose one.', 'convertrack-click-conversion-analytics' ),
			),
			self::CRAWLED_NOT_INDEXED => array(
				'label'    => __( 'Crawled - currently not indexed', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_GOOGLE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google read the page and chose not to index it. There is no technical fault to correct; this is a quality and internal-linking judgement.', 'convertrack-click-conversion-analytics' ),
			),
			self::DISCOVERED_NOT_INDEXED => array(
				'label'    => __( 'Discovered - currently not indexed', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_GOOGLE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google knows the URL exists but has not crawled it yet, often because of crawl budget. Internal links and a request to index both help.', 'convertrack-click-conversion-analytics' ),
			),
			self::NOT_INDEXED => array(
				'label'    => __( 'Not indexed', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_GOOGLE,
				'severity' => 'warning',
				'is_error' => true,
				'summary'  => __( 'Google reports this URL as not indexed without giving a more specific reason.', 'convertrack-click-conversion-analytics' ),
			),
			self::ALTERNATE_CANONICAL => array(
				'label'    => __( 'Alternate page with proper canonical tag', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_NONE,
				'severity' => 'info',
				'is_error' => false,
				'summary'  => __( 'Working as intended. This page is a deliberate duplicate that correctly points at its canonical, so Google indexes that one instead. Nothing to fix.', 'convertrack-click-conversion-analytics' ),
			),
			self::INDEXED => array(
				'label'    => __( 'Indexed', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_NONE,
				'severity' => 'info',
				'is_error' => false,
				'summary'  => __( 'The page is in Google\'s index.', 'convertrack-click-conversion-analytics' ),
			),
			self::UNKNOWN => array(
				'label'    => __( 'Not yet inspected', 'convertrack-click-conversion-analytics' ),
				'owner'    => self::OWNER_NONE,
				'severity' => 'info',
				'is_error' => false,
				'summary'  => __( 'This URL has not been inspected yet, or the inspection returned no coverage detail.', 'convertrack-click-conversion-analytics' ),
			),
		);
	}

	/**
	 * Reason codes that represent a real problem.
	 *
	 * @return array
	 */
	public static function error_codes() {
		$out = array();
		foreach ( self::all() as $code => $descriptor ) {
			if ( ! empty( $descriptor['is_error'] ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Whether a reason code is known.
	 *
	 * @param string $reason Reason code.
	 * @return bool
	 */
	public static function is_valid( $reason ) {
		return array_key_exists( (string) $reason, self::all() );
	}

	/**
	 * Read a field from a queue row or a parsed inspection result.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return string
	 */
	private static function field( array $row, $key ) {
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}
}
