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
	 * @return array|\WP_Error { stored: int } on success.
	 */
	public static function collect( array $payload ) {
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

		if ( self::is_rate_limited() ) {
			return new \WP_Error( 'convertrack_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		if ( count( $events ) > self::MAX_EVENTS_PER_BATCH ) {
			$events = array_slice( $events, 0, self::MAX_EVENTS_PER_BATCH );
		}

		$rows         = array();
		$now          = current_time( 'mysql' );
		$country      = Geo::current_country();
		$pageview_inc = 0;
		$click_inc    = 0;
		$last_url     = '';
		$last_post    = 0;

		foreach ( $events as $raw ) {
			$row = self::sanitize_event( $raw, $visitor_id, $session_id, $now );
			if ( null === $row ) {
				continue;
			}
			// Country is resolved server-side; never trust a client-supplied value.
			$row['country'] = $country;
			$rows[]   = $row;
			$last_url = $row['page_url'];
			$last_post = (int) $row['post_id'];

			if ( 'pageview' === $row['event_type'] ) {
				$pageview_inc++;
			} elseif ( 'click' === $row['event_type'] ) {
				$click_inc++;
			}
		}

		if ( empty( $rows ) ) {
			return array( 'stored' => 0 );
		}

		$stored = Database::insert_events( $rows );

		// Keep the live session fresh and counts accurate.
		Database::touch_session( $session_id, $visitor_id, $last_url, $last_post, $pageview_inc, $click_inc, $country );

		/**
		 * Fires after a batch of events has been stored.
		 *
		 * @param int    $stored     Rows inserted.
		 * @param string $visitor_id Visitor UUID.
		 */
		do_action( 'convertrack_events_stored', $stored, $visitor_id );

		return array( 'stored' => (int) $stored );
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

		$type = isset( $raw['t'] ) ? sanitize_key( $raw['t'] ) : 'click';
		if ( ! in_array( $type, array( 'click', 'pageview', 'scroll' ), true ) ) {
			return null;
		}

		$page_url = self::sanitize_relative_url( isset( $raw['url'] ) ? $raw['url'] : '' );
		if ( self::is_no_track_url( $page_url ) ) {
			return null;
		}

		$device = isset( $raw['dev'] ) ? sanitize_key( $raw['dev'] ) : '';
		if ( ! in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			$device = '';
		}

		$tag = isset( $raw['tag'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw['tag'] ) ) : '';

		// Constrain the channel label to a known set so the sources rollup stays
		// low-cardinality; granular utm_source/campaign are kept separately.
		$known_sources = array( 'Direct', 'Organic search', 'Social', 'Referral', 'Paid search', 'Newsletter' );
		$source        = sanitize_text_field( isset( $raw['src'] ) ? $raw['src'] : '' );
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
			$utm_term       = self::truncate( sanitize_text_field( isset( $raw['ut'] ) ? $raw['ut'] : '' ), 150 );
			$search_keyword = self::truncate( sanitize_text_field( isset( $raw['kw'] ) ? $raw['kw'] : '' ), 191 );
			$search_source  = sanitize_key( isset( $raw['ks'] ) ? $raw['ks'] : '' );
			if ( ! in_array( $search_source, array( 'utm_term', 'site_search', 'referrer_query' ), true ) ) {
				$search_source = '';
			}
			if ( '' === $search_keyword ) {
				$search_source = '';
			}
		}

		return array(
			'visitor_id'       => $visitor_id,
			'session_id'       => $session_id,
			'event_type'       => $type,
			'post_id'          => isset( $raw['pid'] ) ? absint( $raw['pid'] ) : 0,
			'page_url'         => $page_url,
			'page_title'       => self::truncate( sanitize_text_field( isset( $raw['title'] ) ? $raw['title'] : '' ), 255 ),
			'element_tag'      => self::truncate( $tag, 20 ),
			'element_id'       => self::truncate( sanitize_text_field( isset( $raw['id'] ) ? $raw['id'] : '' ), 191 ),
			'element_classes'  => self::truncate( sanitize_text_field( isset( $raw['cls'] ) ? $raw['cls'] : '' ), 255 ),
			'element_text'     => self::truncate( sanitize_text_field( isset( $raw['txt'] ) ? $raw['txt'] : '' ), 255 ),
			'element_selector' => self::truncate( sanitize_text_field( isset( $raw['sel'] ) ? $raw['sel'] : '' ), 255 ),
			'element_href'     => self::sanitize_relative_url( isset( $raw['href'] ) ? $raw['href'] : '' ),
			'is_conversion'    => ( ! empty( $raw['conv'] ) ) ? 1 : 0,
			'device_type'      => $device,
			'source'           => self::truncate( $source, 100 ),
			'referrer_host'    => self::truncate( sanitize_text_field( isset( $raw['rh'] ) ? $raw['rh'] : '' ), 191 ),
			'utm_source'       => self::truncate( sanitize_text_field( isset( $raw['us'] ) ? $raw['us'] : '' ), 100 ),
			'utm_medium'       => self::truncate( sanitize_text_field( isset( $raw['um'] ) ? $raw['um'] : '' ), 100 ),
			'utm_campaign'     => self::truncate( sanitize_text_field( isset( $raw['uc'] ) ? $raw['uc'] : '' ), 150 ),
			'utm_term'         => $utm_term,
			'search_keyword'   => $search_keyword,
			'search_source'    => $search_source,
			'heatmap_selector' => self::truncate( sanitize_text_field( isset( $raw['hsel'] ) ? $raw['hsel'] : '' ), 255 ),
			'pos_x'            => min( 1000, isset( $raw['cx'] ) ? absint( $raw['cx'] ) : 0 ),
			'pos_y'            => min( 1000, isset( $raw['cy'] ) ? absint( $raw['cy'] ) : 0 ),
			'rel_x'            => min( 1000, isset( $raw['rx'] ) ? absint( $raw['rx'] ) : 0 ),
			'rel_y'            => min( 1000, isset( $raw['ry'] ) ? absint( $raw['ry'] ) : 0 ),
			'viewport_w'       => min( 1000000, isset( $raw['vw'] ) ? absint( $raw['vw'] ) : 0 ),
			'viewport_h'       => min( 1000000, isset( $raw['vh'] ) ? absint( $raw['vh'] ) : 0 ),
			'document_w'       => min( 1000000, isset( $raw['dw'] ) ? absint( $raw['dw'] ) : 0 ),
			'document_h'       => min( 1000000, isset( $raw['dh'] ) ? absint( $raw['dh'] ) : 0 ),
			'scroll_x'         => min( 1000000, isset( $raw['sx'] ) ? absint( $raw['sx'] ) : 0 ),
			'scroll_y'         => min( 1000000, isset( $raw['sy'] ) ? absint( $raw['sy'] ) : 0 ),
			'scroll_depth'     => min( 100, isset( $raw['sd'] ) ? absint( $raw['sd'] ) : 0 ),
			'created_at'       => $now,
		);
	}

	/**
	 * Whether a URL is explicitly marked as a non-tracking preview.
	 *
	 * @param string $url Relative URL.
	 * @return bool
	 */
	public static function is_no_track_url( $url ) {
		return false !== strpos( (string) $url, 'convertrack_no_track=1' );
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
	 * Reduce any URL to a safe, relative path+query string capped at 255 chars.
	 *
	 * @param string $value Raw URL or path.
	 * @return string
	 */
	public static function sanitize_relative_url( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		// External hrefs: keep the host so they are still meaningful.
		if ( isset( $parts['host'] ) && isset( $parts['scheme'] ) ) {
			$home = wp_parse_url( home_url() );
			$home_host = isset( $home['host'] ) ? $home['host'] : '';
			if ( $parts['host'] !== $home_host ) {
				$relative = $parts['scheme'] . '://' . $parts['host'] . $path . $query;
				return self::truncate( esc_url_raw( $relative ), 255 );
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
		return self::truncate( esc_url_raw( $relative ), 255 );
	}

	/**
	 * Truncate a multibyte string.
	 *
	 * @param string $value String.
	 * @param int    $len   Max characters.
	 * @return string
	 */
	private static function truncate( $value, $len ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $len );
		}
		return substr( $value, 0, $len );
	}

	/**
	 * Per-IP fixed-window rate limiter backed by transients.
	 *
	 * @return bool True when the request should be blocked.
	 */
	public static function is_rate_limited() {
		$limit = (int) Settings::get( 'rate_limit_per_min' );
		if ( $limit <= 0 ) {
			return false;
		}

		$key = 'convertrack_rl_' . self::ip_hash();

		// With a persistent object cache (Redis/Memcached) use an atomic counter
		// so high-traffic ingestion never writes to the options table. Without
		// one, fall back to transients.
		if ( wp_using_ext_object_cache() ) {
			$count = wp_cache_get( $key, 'convertrack_rl' );
			if ( false === $count ) {
				wp_cache_add( $key, 0, 'convertrack_rl', MINUTE_IN_SECONDS );
				$count = 0;
			}
			if ( (int) $count >= $limit ) {
				return true;
			}
			wp_cache_incr( $key, 1, 'convertrack_rl' );
			return false;
		}

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Hash of the client IP for use as a non-identifying rate-limit key.
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		return substr( wp_hash( $ip ), 0, 16 );
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
