<?php
/**
 * Validates, sanitizes and stores incoming tracking events.
 *
 * No personally identifying data (IP, user id) is stored. The IP is used only,
 * in hashed form, as a transient key for rate limiting.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Collector {

	const MAX_EVENTS_PER_BATCH = 50;

	/**
	 * Process a batch of events.
	 *
	 * @param array $payload Decoded JSON body.
	 * @param array $context Admission context from Ingestion_Guard.
	 * @return array|\WP_Error { stored: int } on success.
	 */
	public static function collect( array $payload, array $context = array() ) {
		if ( ! Settings::get( 'enabled' ) ) {
			return array( 'stored' => 0, 'disabled' => true );
		}

		$visitor_id = self::clean_uuid( isset( $payload['vid'] ) ? $payload['vid'] : '' );
		$session_id = self::clean_uuid( isset( $payload['sid'] ) ? $payload['sid'] : '' );

		if ( '' === $visitor_id || '' === $session_id ) {
			return new \WP_Error( 'convertrack_bad_id', 'Invalid visitor or session id.', array( 'status' => 400 ) );
		}

		if ( self::is_bot() ) {
			return array( 'stored' => 0, 'ignored' => 'bot' );
		}

		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		if ( count( $events ) > self::MAX_EVENTS_PER_BATCH ) {
			$events = array_slice( $events, 0, self::MAX_EVENTS_PER_BATCH );
		}

		$rows         = array();
		$now          = current_time( 'mysql' );
		$country      = Geo::current_country();
		$last_url     = '';
		$last_post    = 0;
		$last_page_key = '';

		foreach ( $events as $raw ) {
			if ( array_key_exists( 'heatmap_allowed', $context ) && empty( $context['heatmap_allowed'] ) && is_array( $raw ) && isset( $raw['t'] ) && in_array( sanitize_key( $raw['t'] ), array( 'heatmap_click', 'scroll' ), true ) ) {
				continue;
			}
			$row = self::sanitize_event( $raw, $visitor_id, $session_id, $now );
			if ( null === $row ) {
				continue;
			}
			// Country is resolved server-side; never trust a client-supplied value.
			$row['country'] = $country;
			$rows[]   = $row;
			$last_url = $row['page_url'];
			$last_post = (int) $row['post_id'];
			$last_page_key = (string) $row['page_key'];

		}

		if ( empty( $rows ) ) {
			return array( 'stored' => 0 );
		}

		$stored = Database::insert_events( $rows );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$session_counts = Database::session_event_counts( $session_id );
		if ( is_wp_error( $session_counts ) ) {
			return $session_counts;
		}

		// Assign exact totals from the idempotent event table. A retried event ID
		// therefore cannot inflate the live session counters.
		$touched = Database::touch_session( $session_id, $visitor_id, $last_url, $last_post, $session_counts['pageviews'], $session_counts['clicks'], $country, true, $last_page_key );
		if ( is_wp_error( $touched ) ) {
			return $touched;
		}
		if ( false === $touched ) {
			return new \WP_Error( 'convertrack_session_write_failed', 'The analytics session could not be updated.', array( 'status' => 503 ) );
		}

		/**
		 * Fires after a batch of events has been stored.
		 *
		 * @param int    $stored     Rows inserted.
		 * @param string $visitor_id Visitor UUID.
		 */
		do_action( 'convertrack_events_stored', $stored, $visitor_id );

		return array(
			'stored'     => (int) $stored,
			'accepted'   => count( $rows ),
			'duplicates' => max( 0, count( $rows ) - (int) $stored ),
		);
	}

	/**
	 * Sanitize a single raw event into a DB row, or null if invalid.
	 *
	 * @param mixed  $raw        Raw event from the request.
	 * @param string $visitor_id Visitor UUID.
	 * @param string $session_id Session UUID.
	 * @param string $now        Server timestamp.
	 * @return array|null
	 */
	private static function sanitize_event( $raw, $visitor_id, $session_id, $now ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$type = isset( $raw['t'] ) && is_scalar( $raw['t'] ) ? sanitize_key( (string) $raw['t'] ) : 'click';
		if ( ! in_array( $type, array( 'click', 'heatmap_click', 'pageview', 'scroll' ), true ) ) {
			return null;
		}

		$raw_url = self::scalar( isset( $raw['url'] ) ? $raw['url'] : '' );
		if ( self::is_no_track_url( $raw_url ) ) {
			return null;
		}
		$identity = self::canonicalize_page(
			$raw_url,
			isset( $raw['pid'] ) ? absint( $raw['pid'] ) : 0,
			array(
				'page_key'   => self::scalar( isset( $raw['pk'] ) ? $raw['pk'] : '' ),
				'object_type'=> self::scalar( isset( $raw['ot'] ) ? $raw['ot'] : '' ),
				'object_id'  => isset( $raw['oid'] ) ? absint( $raw['oid'] ) : 0,
				'token'      => self::scalar( isset( $raw['pit'] ) ? $raw['pit'] : '' ),
			)
		);
		$page_url = $identity['url'];
		if ( '' === $page_url ) {
			return null;
		}

		$device = isset( $raw['dev'] ) && is_scalar( $raw['dev'] ) ? sanitize_key( (string) $raw['dev'] ) : '';
		if ( ! in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			$device = '';
		}

		$tag = isset( $raw['tag'] ) && is_scalar( $raw['tag'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw['tag'] ) ) : '';
		$tag = self::limit_bytes( $tag, 20 );

		// Constrain the channel label to a known set so the sources rollup stays
		// low-cardinality; granular utm_source/campaign are kept separately.
		$known_sources = array( 'Direct', 'Organic search', 'Social', 'Referral', 'Paid search', 'Newsletter' );
		$source        = self::clean_text( isset( $raw['src'] ) ? $raw['src'] : '', 100 );
		if ( '' === $source ) {
			$source = 'Direct';
		} elseif ( ! in_array( $source, $known_sources, true ) ) {
			$source = 'Other';
		}

		$track_keywords = (bool) Settings::get( 'track_search_keywords' );
		$utm_term       = '';
		$search_keyword = '';
		$search_source  = '';
		if ( $track_keywords ) {
			$utm_term       = self::clean_attribution( isset( $raw['ut'] ) ? $raw['ut'] : '', 150 );
			$search_keyword = self::clean_attribution( isset( $raw['kw'] ) ? $raw['kw'] : '', 191 );
			$search_source  = isset( $raw['ks'] ) && is_scalar( $raw['ks'] ) ? sanitize_key( (string) $raw['ks'] ) : '';
			if ( ! in_array( $search_source, array( 'utm_term', 'site_search', 'referrer_query' ), true ) ) {
				$search_source = '';
			}
			if ( '' === $search_keyword ) {
				$search_source = '';
			}
		}

		$element_id       = '';
		$element_classes  = '';
		$element_text     = '';
		$element_selector = '';
		$element_href     = '';
		$heatmap_selector = '';
		$is_conversion    = 0;

		if ( 'click' === $type ) {
			// Current and cached trackers may report custom role=button elements as
			// div/span. Editable tags are accepted only structurally and never retain
			// their value/label.
			if ( ! in_array( $tag, array( 'a', 'button', 'input', 'div', 'span' ), true ) ) {
				return null;
			}
			$element_id       = self::sanitize_identifier( isset( $raw['id'] ) ? $raw['id'] : '', 191 );
			$element_classes  = self::sanitize_class_list( isset( $raw['cls'] ) ? $raw['cls'] : '' );
			$element_selector = self::sanitize_structural_selector( isset( $raw['sel'] ) ? $raw['sel'] : '' );
			$heatmap_selector = self::sanitize_structural_selector( isset( $raw['hsel'] ) ? $raw['hsel'] : '' );
			$element_href     = self::sanitize_relative_url( isset( $raw['href'] ) ? $raw['href'] : '' );
			if ( ! in_array( $tag, array( 'input', 'textarea', 'select', 'option' ), true ) ) {
				$element_text = self::sanitize_static_label( isset( $raw['txt'] ) ? $raw['txt'] : '' );
			}
			$is_conversion = self::validate_click_conversion( $raw, $tag, $element_id, $element_classes, $element_selector, $element_href );
		} elseif ( 'heatmap_click' === $type ) {
			// Never retain arbitrary text, attributes, hrefs or CSS classes for
			// heatmap-only clicks. Generated tag paths and coordinates are sufficient.
			$heatmap_selector = self::sanitize_structural_selector( isset( $raw['hsel'] ) ? $raw['hsel'] : '' );
			if ( '' === $heatmap_selector ) {
				return null;
			}
		} elseif ( 'pageview' === $type ) {
			$tag           = '';
			$is_conversion = self::matches_conversion_url( $page_url ) ? 1 : 0;
		} else {
			$tag = '';
			if ( empty( $raw['sd'] ) || absint( $raw['sd'] ) > 100 ) {
				return null;
			}
		}

		return array(
			'visitor_id'       => $visitor_id,
			'session_id'       => $session_id,
			'event_type'       => $type,
			'post_id'          => $identity['post_id'],
			'page_key'         => $identity['page_key'],
			'object_type'      => $identity['object_type'],
			'object_id'        => $identity['object_id'],
			'event_id'         => self::clean_uuid( isset( $raw['eid'] ) ? $raw['eid'] : '' ),
			'page_url'         => $page_url,
			// Known public posts always use the server-derived title. Virtual routes
			// intentionally keep an empty title rather than trusting personalized text.
			'page_title'       => $identity['title'],
			'element_tag'      => $tag,
			'element_id'       => $element_id,
			'element_classes'  => $element_classes,
			'element_text'     => $element_text,
			'element_selector' => $element_selector,
			'element_href'     => $element_href,
			'is_conversion'    => $is_conversion,
			'device_type'      => $device,
			'source'           => self::limit_bytes( $source, 100 ),
			'referrer_host'    => self::sanitize_host( isset( $raw['rh'] ) ? $raw['rh'] : '' ),
			'utm_source'       => self::clean_attribution( isset( $raw['us'] ) ? $raw['us'] : '', 100 ),
			'utm_medium'       => self::clean_attribution( isset( $raw['um'] ) ? $raw['um'] : '', 100 ),
			'utm_campaign'     => self::clean_attribution( isset( $raw['uc'] ) ? $raw['uc'] : '', 150 ),
			'utm_term'         => $utm_term,
			'search_keyword'   => $search_keyword,
			'search_source'    => $search_source,
			'heatmap_selector' => $heatmap_selector,
			'pos_x'            => in_array( $type, array( 'click', 'heatmap_click' ), true ) ? min( 1000, isset( $raw['cx'] ) ? absint( $raw['cx'] ) : 0 ) : 0,
			'pos_y'            => in_array( $type, array( 'click', 'heatmap_click' ), true ) ? min( 1000, isset( $raw['cy'] ) ? absint( $raw['cy'] ) : 0 ) : 0,
			'rel_x'            => in_array( $type, array( 'click', 'heatmap_click' ), true ) ? min( 1000, isset( $raw['rx'] ) ? absint( $raw['rx'] ) : 0 ) : 0,
			'rel_y'            => in_array( $type, array( 'click', 'heatmap_click' ), true ) ? min( 1000, isset( $raw['ry'] ) ? absint( $raw['ry'] ) : 0 ) : 0,
			'viewport_w'       => min( 1000000, isset( $raw['vw'] ) ? absint( $raw['vw'] ) : 0 ),
			'viewport_h'       => min( 1000000, isset( $raw['vh'] ) ? absint( $raw['vh'] ) : 0 ),
			'document_w'       => min( 1000000, isset( $raw['dw'] ) ? absint( $raw['dw'] ) : 0 ),
			'document_h'       => min( 1000000, isset( $raw['dh'] ) ? absint( $raw['dh'] ) : 0 ),
			'scroll_x'         => min( 1000000, isset( $raw['sx'] ) ? absint( $raw['sx'] ) : 0 ),
			'scroll_y'         => min( 1000000, isset( $raw['sy'] ) ? absint( $raw['sy'] ) : 0 ),
			'scroll_depth'     => 'scroll' === $type ? min( 100, isset( $raw['sd'] ) ? absint( $raw['sd'] ) : 0 ) : 0,
			'created_at'       => self::event_timestamp( $raw, $now ),
		);
	}

	/**
	 * Resolve the event timestamp from the client payload, bounded for safety.
	 *
	 * Older tracker scripts did not send per-event timestamps, so invalid or
	 * missing values fall back to the server receive time.
	 *
	 * @param array  $raw      Raw event from the request.
	 * @param string $fallback Server-side fallback timestamp.
	 * @return string Site-local mysql datetime.
	 */
	private static function event_timestamp( array $raw, $fallback ) {
		if ( ! isset( $raw['ts'] ) || ! is_numeric( $raw['ts'] ) ) {
			return $fallback;
		}

		$unix = (int) floor( (float) $raw['ts'] / 1000 );
		$now  = time();
		if ( $unix < ( $now - DAY_IN_SECONDS ) || $unix > ( $now + ( 5 * MINUTE_IN_SECONDS ) ) ) {
			return $fallback;
		}

		$local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $unix ), 'Y-m-d H:i:s' );
		return $local ? $local : $fallback;
	}

	/**
	 * Whether a URL is explicitly marked as a non-tracking preview.
	 *
	 * @param string $url Relative URL.
	 * @return bool
	 */
	public static function is_no_track_url( $url ) {
		$parts = wp_parse_url( self::scalar( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return false;
		}
		parse_str( $parts['query'], $query );
		return isset( $query['convertrack_no_track'] ) && is_scalar( $query['convertrack_no_track'] ) && '1' === (string) $query['convertrack_no_track'];
	}

	/**
	 * Validate a UUID-ish identifier; returns '' if it does not look like one.
	 *
	 * @param mixed $value Candidate id.
	 * @return string
	 */
	public static function clean_uuid( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Reduce a URL to a path plus explicitly allowed, non-sensitive parameters.
	 *
	 * @param string $value Raw URL or path.
	 * @return string
	 */
	public static function sanitize_relative_url( $value ) {
		$value = trim( self::limit_bytes( self::scalar( $value ), 2048 ) );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$path  = isset( $parts['path'] ) ? self::sanitize_url_path( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? self::sanitize_query( $parts['query'] ) : '';
		$query = '' !== $query ? '?' . $query : '';

		// External hrefs: keep the host so they are still meaningful.
		if ( isset( $parts['host'] ) ) {
			$home = wp_parse_url( home_url() );
			$home_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
			$test_host = strtolower( $parts['host'] );
			if ( $test_host !== $home_host ) {
				if ( ! $scheme || ! preg_match( '/^[a-z0-9.-]+$/', $test_host ) ) {
					return '';
				}
				$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
				$external = $scheme . '://' . $test_host . $port . ( $path ? $path : '/' ) . $query;
				return self::limit_bytes( esc_url_raw( $external ), 255 );
			}
		}

		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		$relative = $path . $query;
		// Strip control chars / spaces and cap length.
		$relative = preg_replace( '/[\x00-\x1F\x7F]/', '', $relative );
		return self::limit_bytes( esc_url_raw( $relative ), 255 );
	}

	/**
	 * Configured query keys after the unconditional sensitive-name denylist.
	 *
	 * @return array
	 */
	public static function allowed_query_params() {
		$items = Settings::lines_to_array( Settings::get( 'query_param_allowlist', '' ) );
		$items = (array) apply_filters( 'convertrack_allowed_query_params', $items );
		$out   = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( strtolower( self::scalar( $item ) ) );
			if ( '' !== $item && ! self::is_sensitive_param_name( $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sensitive query keys are denied even when a filter/configuration attempts
	 * to include them in the allowlist.
	 *
	 * @param string $name Parameter name.
	 * @return bool
	 */
	public static function is_sensitive_param_name( $name ) {
		$name    = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', self::scalar( $name ) ) );
		$compact = str_replace( array( '_', '-' ), '', $name );
		$exact   = array( 'token', 'key', 'apikey', 'nonce', 'password', 'passwd', 'pass', 'email', 'auth', 'authorization', 'session', 'sessionid', 'code', 'secret', 'signature', 'sig', 'orderkey', 'resetkey', 'magiclink' );
		if ( '' === $name || in_array( $compact, $exact, true ) ) {
			return true;
		}
		return (bool) preg_match( '/(^|[_-])(token|key|nonce|password|passwd|email|auth|session|code|secret|signature|order[_-]?key|reset[_-]?key)($|[_-])/', $name );
	}

	/**
	 * Canonicalize a client page reference and derive a stable additive identity.
	 *
	 * @param string $raw_url Raw URL.
	 * @param int    $claimed_post     Claimed post ID.
	 * @param array  $claimed_identity Optional integrity-bound server identity.
	 * @return array
	 */
	public static function canonicalize_page( $raw_url, $claimed_post, $claimed_identity = array() ) {
		$url = self::sanitize_relative_url( $raw_url );
		if ( '' === $url || preg_match( '#^https?://#i', $url ) ) {
			return array( 'url' => '', 'post_id' => 0, 'page_key' => '', 'object_type' => 'url', 'object_id' => 0, 'title' => '' );
		}

		$parts    = wp_parse_url( $url );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$identity = Page_Identity::from_payload( $url, absint( $claimed_post ) );
		$candidate = array(
			'page_key'   => substr( sanitize_text_field( isset( $claimed_identity['page_key'] ) ? (string) $claimed_identity['page_key'] : '' ), 0, 191 ),
			'object_type'=> substr( sanitize_key( isset( $claimed_identity['object_type'] ) ? (string) $claimed_identity['object_type'] : '' ), 0, 40 ),
			'object_id'  => absint( isset( $claimed_identity['object_id'] ) ? $claimed_identity['object_id'] : 0 ),
			'post_id'    => absint( $claimed_post ),
			'path'       => (string) $identity['path'],
		);
		$identity_token = isset( $claimed_identity['token'] ) ? (string) $claimed_identity['token'] : '';
		if ( '' !== $candidate['page_key'] && '' !== $candidate['object_type'] && Ingestion_Guard::validate_page_identity_token( $identity_token, $candidate ) ) {
			$identity['page_key']    = $candidate['page_key'];
			$identity['object_type'] = $candidate['object_type'];
			$identity['object_id']   = $candidate['object_id'];
			$identity['post_id']     = $candidate['post_id'];
			if ( $candidate['post_id'] > 0 ) {
				$identity['title'] = get_the_title( $candidate['post_id'] );
			}
		}
		$identity['url']   = self::limit_bytes( $identity['path'] . $query, 255 );
		$identity['title'] = self::limit_bytes( $identity['title'], 255 );
		unset( $identity['path'] );
		return $identity;
	}

	/**
	 * Sanitize a query against the allowlist and deny sensitive values.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private static function sanitize_query( $query ) {
		$allow = array_flip( self::allowed_query_params() );
		if ( empty( $allow ) ) {
			return '';
		}
		parse_str( self::limit_bytes( (string) $query, 2048 ), $params );
		$out = array();
		foreach ( $params as $key => $value ) {
			$key = sanitize_key( strtolower( (string) $key ) );
			if ( ! isset( $allow[ $key ] ) || self::is_sensitive_param_name( $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			$value = self::clean_attribution( $value, 100 );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		ksort( $out );
		return http_build_query( $out, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Redact credential/email-like path segments used by magic-login and reset
	 * routes while preserving a useful structural route.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function sanitize_url_path( $path ) {
		$path     = preg_replace( '/[\x00-\x1F\x7F]/', '', self::limit_bytes( $path, 1024 ) );
		$segments = explode( '/', (string) $path );
		foreach ( $segments as $index => $segment ) {
			$decoded = rawurldecode( $segment );
			$prior   = $index > 0 ? strtolower( rawurldecode( $segments[ $index - 1 ] ) ) : '';
			$credential_route = (bool) preg_match( '/^(?:magic|magic-link|magic-login|magic_link|magic_login|login|passwordless|one-time-login|reset|password-reset|reset-password|lostpassword|verify|verification|auth|authenticate|token)$/', $prior );
			if ( self::looks_sensitive( $decoded ) || ( $credential_route && strlen( $decoded ) >= 16 ) ) {
				$segments[ $index ] = 'redacted';
			}
		}
		return implode( '/', $segments );
	}

	/**
	 * Validate conversion semantics from configured goals rather than accepting
	 * an arbitrary client flag.
	 */
	private static function validate_click_conversion( array $raw, $tag, $id, $classes, $selector, $href ) {
		if ( empty( $raw['conv'] ) ) {
			return 0;
		}
		$goal = isset( $raw['goal'] ) ? self::limit_bytes( self::scalar( $raw['goal'] ), 191 ) : '';
		if ( '@url' === $goal ) {
			return self::is_external_url( $href ) && self::matches_conversion_url( $href ) ? 1 : 0;
		}
		foreach ( Settings::lines_to_array( Settings::get( 'conversion_selectors' ) ) as $configured ) {
			$configured = trim( (string) $configured );
			$has_evidence = self::selector_has_evidence( $configured, $tag, $id, $classes, $selector );
			if ( $has_evidence ) {
				return 1;
			}
			// A current tracker reports the exact configured selector. Simple tag,
			// id and class rules must also agree with the structural fields; complex
			// attribute/combinator rules cannot be reconstructed server-side.
			$is_simple = (bool) preg_match( '/^(?:#[A-Za-z][A-Za-z0-9_-]*|\.[A-Za-z_-][A-Za-z0-9_-]*|[a-z][a-z0-9-]*)$/i', $configured );
			if ( ! $is_simple && '' !== $goal && hash_equals( $configured, $goal ) ) {
				return 1;
			}
		}
		return 0;
	}

	/**
	 * Match common simple selectors for cached legacy trackers.
	 */
	private static function selector_has_evidence( $configured, $tag, $id, $classes, $selector ) {
		if ( preg_match( '/^#([A-Za-z][A-Za-z0-9_-]*)$/', $configured, $m ) ) {
			return $id === $m[1];
		}
		if ( preg_match( '/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', $configured, $m ) ) {
			return in_array( $m[1], preg_split( '/\s+/', $classes ), true );
		}
		if ( preg_match( '/^[a-z][a-z0-9-]*$/i', $configured ) ) {
			return strtolower( $configured ) === $tag;
		}
		return '' !== $selector && $configured === $selector;
	}

	/**
	 * Whether a canonical URL matches any configured URL goal.
	 */
	private static function matches_conversion_url( $url ) {
		$url = self::sanitize_relative_url( $url );
		if ( '' === $url ) {
			return false;
		}

		foreach ( Settings::lines_to_array( Settings::get( 'conversion_urls' ) ) as $raw_rule ) {
			$mode  = 'exact';
			$value = trim( (string) $raw_rule );
			if ( preg_match( '/^(exact|prefix|regex):(.*)$/i', $value, $matches ) ) {
				$mode  = strtolower( $matches[1] );
				$value = trim( $matches[2] );
			}

			if ( '' === $value ) {
				continue;
			}
			if ( 'regex' === $mode ) {
				if ( self::safe_regex_match( $url, $value ) ) {
					return true;
				}
				continue;
			}

			$goal = self::sanitize_relative_url( $value );
			if ( '' === $goal ) {
				continue;
			}
			if ( 'exact' === $mode && hash_equals( $goal, $url ) ) {
				return true;
			}
			if ( 'prefix' === $mode && 0 === strpos( $url, $goal ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match an explicitly configured, conservatively bounded regular expression.
	 *
	 * Rules use the same bare-pattern format as the browser tracker. Requiring
	 * both anchors and rejecting advanced/backtracking-prone constructs keeps a
	 * settings typo from turning public collection into a regex DoS surface.
	 *
	 * @param string $value   Canonical privacy-safe URL.
	 * @param string $pattern Bare regular expression.
	 * @return bool
	 */
	private static function safe_regex_match( $value, $pattern ) {
		$pattern = (string) $pattern;
		if (
			'' === $pattern ||
			strlen( $pattern ) > 191 ||
			'^' !== substr( $pattern, 0, 1 ) ||
			'$' !== substr( $pattern, -1 ) ||
			preg_match( '/[\x00-\x1F\x7F]/', $pattern ) ||
			false !== strpos( $pattern, '(?' ) ||
			preg_match( '/\\\\[1-9]/', $pattern ) ||
			preg_match( '/\([^)]*[+*}][^)]*\)\s*[+*{]/', $pattern )
		) {
			return false;
		}

		$delimiter = '~';
		foreach ( array( '~', '#', '%', '!', '@', '`' ) as $candidate ) {
			if ( false === strpos( $pattern, $candidate ) ) {
				$delimiter = $candidate;
				break;
			}
		}
		if ( false !== strpos( $pattern, $delimiter ) ) {
			$pattern = str_replace( $delimiter, '\\' . $delimiter, $pattern );
		}

		$result = @preg_match( $delimiter . $pattern . $delimiter . 'uD', substr( (string) $value, 0, 255 ) );
		return 1 === $result;
	}

	/**
	 * Whether a sanitized href leaves this site.
	 */
	private static function is_external_url( $url ) {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && ! empty( $parts['host'] ) && ! empty( $home['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home['host'] );
	}

	/**
	 * Sanitize a generated structural selector; attribute values are forbidden.
	 */
	private static function sanitize_structural_selector( $value ) {
		// The rollup staging dimension is indexed/bounded at 191 bytes. Applying
		// the same cap at collection prevents strict-mode truncation failures.
		$value = self::limit_bytes( self::scalar( $value ), 191 );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		if ( '' === $value || ! preg_match( '/^[a-z0-9#._:()>+~\-\s]+$/i', $value ) || self::looks_sensitive( $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Safe author-defined identifier.
	 */
	private static function sanitize_identifier( $value, $bytes ) {
		$value = self::limit_bytes( self::scalar( $value ), $bytes );
		return preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,190}$/', $value ) && ! self::looks_sensitive( $value ) && ! self::is_sensitive_param_name( $value ) ? $value : '';
	}

	/**
	 * Low-cardinality CSS class tokens only.
	 */
	private static function sanitize_class_list( $value ) {
		$out = array();
		foreach ( preg_split( '/\s+/', self::scalar( $value ) ) as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]{0,63}$/', $class ) && ! self::looks_sensitive( $class ) && ! preg_match( '/^(user|customer|account|member|email|order|session|auth|token|key)[_-]/i', $class ) ) {
				$out[] = $class;
			}
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		return self::limit_bytes( implode( ' ', array_unique( $out ) ), 191 );
	}

	/**
	 * Static button/link labels only; possible PII and secrets are discarded.
	 */
	private static function sanitize_static_label( $value ) {
		$value = self::clean_text( $value, 100 );
		return self::looks_sensitive( $value ) ? '' : $value;
	}

	/**
	 * Strict referrer host sanitizer.
	 */
	private static function sanitize_host( $value ) {
		$value = strtolower( self::scalar( $value ) );
		return preg_match( '/^[a-z0-9.-]+$/', $value ) ? self::limit_bytes( $value, 191 ) : '';
	}

	/**
	 * Scalar text sanitization with a byte cap.
	 */
	private static function clean_text( $value, $bytes ) {
		return self::limit_bytes( sanitize_text_field( self::scalar( $value ) ), $bytes );
	}

	/**
	 * Attribution fields reject email/token-like values as a final privacy net.
	 */
	private static function clean_attribution( $value, $bytes ) {
		$value = self::clean_text( $value, $bytes );
		return self::looks_sensitive( $value ) ? '' : $value;
	}

	/**
	 * Detect likely email addresses and credentials in free-form values.
	 */
	private static function looks_sensitive( $value ) {
		$value = trim( self::scalar( $value ) );
		if ( '' === $value ) {
			return false;
		}
		if ( is_email( $value ) || preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value ) ) {
			return true;
		}
		if ( preg_match( '/(?:token|nonce|password|passwd|secret|signature|authorization|order[_ -]?key|reset[_ -]?key)\s*[:=]/i', $value ) ) {
			return true;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_\-+\/=]{40,}$/', $value );
	}

	/**
	 * Convert only scalar request values to strings.
	 */
	private static function scalar( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Limit UTF-8 data by bytes before it can reach a DB column.
	 */
	private static function limit_bytes( $value, $bytes ) {
		$value = self::scalar( $value );
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		}
		return substr( $value, 0, $bytes );
	}

	/**
	 * Backward-compatible method retained for integrations. Public endpoints now
	 * use Ingestion_Guard's atomic multi-scope quotas instead of transients.
	 *
	 * @return bool
	 */
	public static function is_rate_limited() {
		return false;
	}

	/**
	 * Lightweight bot filter based on the user agent.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/(bot|crawl|spider|slurp|mediapartners|facebookexternalhit|preview|monitor|pingdom|lighthouse|headless|phantom)/', $ua );
	}
}
