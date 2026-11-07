<?php
/**
 * Click authenticity: score each tracked click 0-100 from evidence that a real
 * person in the site's market made it.
 *
 * Evidence comes from two places:
 * - the browser, captured by the tracker at click time: how the element was
 *   pressed (touch, mouse, pen or keyboard), whether a press preceded the
 *   click, how long the page had been open, whether the visitor had scrolled or
 *   moved before clicking, whether the tab had focus, and whether the browser
 *   reports itself as automated (navigator.webdriver);
 * - the server, from the geolocation profile: the connection type (mobile
 *   carrier, home/business broadband, datacenter, VPN/proxy) and whether the
 *   location falls inside the site's configured market.
 *
 * The score is deliberately explainable: every deduction leaves a flag, and
 * the dashboard shows the flags next to the verdict. Nothing here blocks or
 * drops an event — it only labels it.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Trust {

	const VERDICT_REAL       = 'real';
	const VERDICT_CHECK      = 'check';
	const VERDICT_SUSPICIOUS = 'suspicious';
	const VERDICT_UNSCORED   = 'unscored';

	/** Scores at or above this are "real". */
	const REAL_MIN = 70;

	/** Scores at or above this (and below REAL_MIN) are "check". */
	const CHECK_MIN = 40;

	/**
	 * Flag => [ deduction, plain-language label ].
	 *
	 * @return array
	 */
	public static function flag_catalog() {
		return array(
			'automation'      => array( 60, __( 'Automated browser', 'convertrack-click-conversion-analytics' ) ),
			'datacenter'      => array( 45, __( 'Datacenter network', 'convertrack-click-conversion-analytics' ) ),
			'outside_area'    => array( 30, __( 'Outside service country', 'convertrack-click-conversion-analytics' ) ),
			'vpn_proxy'       => array( 25, __( 'VPN or proxy', 'convertrack-click-conversion-analytics' ) ),
			'no_press'        => array( 25, __( 'No finger or mouse press', 'convertrack-click-conversion-analytics' ) ),
			'instant'         => array( 25, __( 'Clicked within 1.5s of load', 'convertrack-click-conversion-analytics' ) ),
			'outside_region'  => array( 15, __( 'Outside service state', 'convertrack-click-conversion-analytics' ) ),
			'no_activity'     => array( 15, __( 'No scroll or movement first', 'convertrack-click-conversion-analytics' ) ),
			'fast'            => array( 10, __( 'Clicked within 4s of load', 'convertrack-click-conversion-analytics' ) ),
			'unfocused'       => array( 10, __( 'Tab not focused', 'convertrack-click-conversion-analytics' ) ),
			'device_mismatch' => array( 10, __( 'Mouse on a phone layout', 'convertrack-click-conversion-analytics' ) ),
			'no_signals'      => array( 0, __( 'Browser signals missing (old tracker cache)', 'convertrack-click-conversion-analytics' ) ),
		);
	}

	/**
	 * Read the click-time browser signals from a raw tracker event.
	 *
	 * Returns null when the tracker that sent the event predates 2.17 and so
	 * sent no signals at all (a page served from a stale cache).
	 *
	 * @param array $raw Raw event.
	 * @return array|null { pointer, pressed, dwell_ms, activity, focused, webdriver, keyboard }
	 */
	public static function signals_from_raw( array $raw ) {
		if ( ! isset( $raw['tq'] ) || ! is_array( $raw['tq'] ) ) {
			return null;
		}
		$q       = $raw['tq'];
		$pointer = isset( $q['pt'] ) && is_scalar( $q['pt'] ) ? sanitize_key( (string) $q['pt'] ) : '';
		if ( ! in_array( $pointer, array( 'touch', 'mouse', 'pen', 'keyboard' ), true ) ) {
			$pointer = '';
		}
		return array(
			'pointer'   => $pointer,
			'pressed'   => ! empty( $q['pd'] ),
			'dwell_ms'  => isset( $q['ms'] ) && is_numeric( $q['ms'] ) ? max( 0, min( 86400000, (int) $q['ms'] ) ) : 0,
			// null = not measured, which is not the same as "no activity".
			'activity'  => isset( $q['ia'] ) && is_numeric( $q['ia'] ) ? max( 0, min( 9999, (int) $q['ia'] ) ) : null,
			'focused'   => ! isset( $q['hf'] ) || ! empty( $q['hf'] ),
			'webdriver' => ! empty( $q['wd'] ),
			'keyboard'  => 'keyboard' === $pointer,
		);
	}

	/**
	 * Parse the configured market ("US", "US-FL", "US-FL,US-GA") into rules.
	 *
	 * @param string $market Setting value.
	 * @return array { countries: string[], regions: array<string,string[]> }
	 */
	public static function parse_market( $market ) {
		$rules = array( 'countries' => array(), 'regions' => array() );
		foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $market ) ) as $part ) {
			if ( ! preg_match( '/^([A-Z]{2})(?:-([A-Z0-9]{1,3}))?$/', $part, $m ) ) {
				continue;
			}
			$rules['countries'][ $m[1] ] = $m[1];
			if ( ! empty( $m[2] ) ) {
				$rules['regions'][ $m[1] ][] = $m[2];
			}
		}
		$rules['countries'] = array_values( $rules['countries'] );
		return $rules;
	}

	/**
	 * Score one click.
	 *
	 * @param array|null $signals Browser signals (null = old tracker).
	 * @param array      $profile Geo profile.
	 * @param string     $device  Layout device reported with the event.
	 * @param string     $market  Market setting.
	 * @return array { score: int, flags: string[] }
	 */
	public static function score( $signals, array $profile, $device = '', $market = '' ) {
		$flags = array();

		if ( null === $signals ) {
			$flags[] = 'no_signals';
		} else {
			if ( $signals['webdriver'] ) {
				$flags[] = 'automation';
			}
			if ( ! $signals['pressed'] && ! $signals['keyboard'] ) {
				$flags[] = 'no_press';
			}
			if ( $signals['dwell_ms'] > 0 && $signals['dwell_ms'] < 1500 ) {
				$flags[] = 'instant';
			} elseif ( $signals['dwell_ms'] > 0 && $signals['dwell_ms'] < 4000 ) {
				$flags[] = 'fast';
			}
			if ( 0 === $signals['activity'] ) {
				$flags[] = 'no_activity';
			}
			if ( ! $signals['focused'] ) {
				$flags[] = 'unfocused';
			}
			if ( 'mobile' === $device && 'mouse' === $signals['pointer'] ) {
				$flags[] = 'device_mismatch';
			}
		}

		$net_type = isset( $profile['net_type'] ) ? (string) $profile['net_type'] : '';
		if ( Geo::NET_HOSTING === $net_type ) {
			$flags[] = 'datacenter';
		} elseif ( Geo::NET_PROXY === $net_type ) {
			$flags[] = 'vpn_proxy';
		}

		$rules   = self::parse_market( $market );
		$country = isset( $profile['country'] ) ? (string) $profile['country'] : '';
		$region  = isset( $profile['region'] ) ? (string) $profile['region'] : '';
		if ( '' !== $country && ! empty( $rules['countries'] ) ) {
			if ( ! in_array( $country, $rules['countries'], true ) ) {
				$flags[] = 'outside_area';
			} elseif ( '' !== $region && ! empty( $rules['regions'][ $country ] ) && ! in_array( $region, $rules['regions'][ $country ], true ) ) {
				$flags[] = 'outside_region';
			}
		}

		$catalog = self::flag_catalog();
		$score   = 100;
		foreach ( $flags as $flag ) {
			$score -= isset( $catalog[ $flag ] ) ? (int) $catalog[ $flag ][0] : 0;
		}

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'flags' => $flags,
		);
	}

	/**
	 * Verdict for a stored score (null = not scored).
	 *
	 * @param int|string|null $score Stored score.
	 * @param string          $flags Stored comma-separated flags.
	 * @return string
	 */
	public static function verdict( $score, $flags = '' ) {
		if ( null === $score || '' === $score ) {
			return self::VERDICT_UNSCORED;
		}
		$score = (int) $score;
		if ( $score >= self::REAL_MIN ) {
			return self::VERDICT_REAL;
		}
		return $score >= self::CHECK_MIN ? self::VERDICT_CHECK : self::VERDICT_SUSPICIOUS;
	}

	/**
	 * Human labels for a stored flag list.
	 *
	 * @param string $flags Comma-separated flags.
	 * @return string[]
	 */
	public static function flag_labels( $flags ) {
		$catalog = self::flag_catalog();
		$labels  = array();
		foreach ( array_filter( explode( ',', (string) $flags ) ) as $flag ) {
			$labels[] = isset( $catalog[ $flag ] ) ? $catalog[ $flag ][1] : $flag;
		}
		return $labels;
	}

	/**
	 * Sanitize a market setting value ("US, us-fl" => "US,US-FL").
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_market( $value ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $value ) ) as $part ) {
			if ( preg_match( '/^[A-Z]{2}(?:-[A-Z0-9]{1,3})?$/', $part ) ) {
				$out[ $part ] = $part;
			}
		}
		return implode( ',', array_slice( array_values( $out ), 0, 20 ) );
	}
}
