<?php
/**
 * Conversion kinds: classify a conversion by what the visitor did (called,
 * emailed, texted, left for another site, clicked a configured button,
 * reached a goal page) and label each kind for reports.
 *
 * The kind and the matched rule ("goal") are decided once, when the event is
 * stored, so every report groups the same way and daily rollups can keep
 * per-kind totals long after the raw events are purged.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

final class Conversion_Kinds {

	const KIND_CALL     = 'call';
	const KIND_EMAIL    = 'email';
	const KIND_SMS      = 'sms';
	const KIND_OUTBOUND = 'outbound';
	const KIND_RULE     = 'rule';
	const KIND_PAGE     = 'page';
	const KIND_OTHER    = 'other';

	const GOAL_URL = '@url';

	const KIND_MAX_LENGTH  = 60;
	const GOAL_MAX_LENGTH  = 191;
	const LABEL_MAX_LENGTH = 60;

	/**
	 * Parsed label overrides for the current request.
	 *
	 * @var array|null
	 */
	private static $labels = null;

	/**
	 * Scheme of a click target when it is one of the contact schemes.
	 *
	 * @param string $href Raw href as reported by the tracker.
	 * @return string 'tel', 'mailto', 'sms' or ''.
	 */
	public static function href_scheme( $href ) {
		$href = strtolower( ltrim( (string) $href ) );
		foreach ( array( 'tel', 'mailto', 'sms' ) as $scheme ) {
			if ( 0 === strpos( $href, $scheme . ':' ) ) {
				return $scheme;
			}
		}
		return '';
	}

	/**
	 * Classify a click conversion.
	 *
	 * The scheme wins over the rule: a configured ".call-button" that links to
	 * tel: is a call whichever selector matched it. The matched rule is kept
	 * as the goal so reports can still break a kind down by rule later.
	 *
	 * @param string $raw_href Raw href as reported by the tracker.
	 * @param string $goal     Matched conversion rule (selector or @url).
	 * @return array { kind, goal }
	 */
	public static function classify_click( $raw_href, $goal ) {
		$goal   = self::clip( (string) $goal, self::GOAL_MAX_LENGTH );
		$scheme = self::href_scheme( $raw_href );
		if ( 'tel' === $scheme ) {
			return array( self::KIND_CALL, 'tel:' );
		}
		if ( 'mailto' === $scheme ) {
			return array( self::KIND_EMAIL, 'mailto:' );
		}
		if ( 'sms' === $scheme ) {
			return array( self::KIND_SMS, 'sms:' );
		}
		if ( self::GOAL_URL === $goal ) {
			return array( self::KIND_OUTBOUND, self::GOAL_URL );
		}
		return array( self::KIND_RULE, $goal );
	}

	/**
	 * Classify a pageview conversion (a configured goal page was reached).
	 *
	 * @param string $matched_rule The conversion URL rule that matched.
	 * @return array { kind, goal }
	 */
	public static function classify_pageview( $matched_rule ) {
		return array( self::KIND_PAGE, self::clip( (string) $matched_rule, self::GOAL_MAX_LENGTH ) );
	}

	/**
	 * Every kind the reports can show, with its default label and icon.
	 *
	 * @return array kind => { label, icon }
	 */
	public static function catalog() {
		return array(
			self::KIND_CALL     => array( 'label' => __( 'Call', 'convertrack-click-conversion-analytics' ), 'icon' => 'phone' ),
			self::KIND_EMAIL    => array( 'label' => __( 'Email', 'convertrack-click-conversion-analytics' ), 'icon' => 'mail' ),
			self::KIND_SMS      => array( 'label' => __( 'Text message', 'convertrack-click-conversion-analytics' ), 'icon' => 'message' ),
			self::KIND_OUTBOUND => array( 'label' => __( 'Outbound link', 'convertrack-click-conversion-analytics' ), 'icon' => 'external' ),
			self::KIND_RULE     => array( 'label' => __( 'Button', 'convertrack-click-conversion-analytics' ), 'icon' => 'click' ),
			self::KIND_PAGE     => array( 'label' => __( 'Goal page', 'convertrack-click-conversion-analytics' ), 'icon' => 'page' ),
			self::KIND_OTHER    => array( 'label' => __( 'Other conversion', 'convertrack-click-conversion-analytics' ), 'icon' => 'conversion' ),
		);
	}

	/**
	 * Report label for a kind/goal pair.
	 *
	 * Precedence: a label configured for the goal (or for the kind's scheme),
	 * then the kind's default for scheme kinds, then the button text, then
	 * the selector itself.
	 *
	 * @param string $kind         Kind key.
	 * @param string $goal         Matched rule.
	 * @param string $element_text Representative button text, if any.
	 * @return string
	 */
	public static function label( $kind, $goal, $element_text = '' ) {
		$kind    = self::normalize_kind( $kind );
		$goal    = (string) $goal;
		$catalog = self::catalog();
		$labels  = self::labels();

		if ( '' !== $goal && isset( $labels[ strtolower( $goal ) ] ) ) {
			return $labels[ strtolower( $goal ) ];
		}
		if ( isset( $labels[ $kind ] ) ) {
			return $labels[ $kind ];
		}
		if ( self::KIND_RULE === $kind ) {
			$text = trim( (string) $element_text );
			if ( '' !== $text ) {
				return self::clip( $text, self::LABEL_MAX_LENGTH );
			}
			return '' !== $goal ? $goal : $catalog[ self::KIND_RULE ]['label'];
		}
		if ( self::KIND_PAGE === $kind && '' !== $goal ) {
			/* translators: %s: configured goal page rule. */
			return sprintf( __( 'Reached %s', 'convertrack-click-conversion-analytics' ), preg_replace( '/^(exact|prefix|regex):/i', '', $goal ) );
		}
		return $catalog[ $kind ]['label'];
	}

	/**
	 * Icon name for a kind (see svgIcon() in admin.js).
	 *
	 * @param string $kind Kind key.
	 * @return string
	 */
	public static function icon( $kind ) {
		$catalog = self::catalog();
		return $catalog[ self::normalize_kind( $kind ) ]['icon'];
	}

	/**
	 * Map an arbitrary stored kind onto the catalog (empty = recorded before
	 * kinds existed).
	 *
	 * @param string $kind Stored kind.
	 * @return string
	 */
	public static function normalize_kind( $kind ) {
		$kind = sanitize_key( (string) $kind );
		return array_key_exists( $kind, self::catalog() ) ? $kind : self::KIND_OTHER;
	}

	/**
	 * Parsed label overrides: lower-cased goal or kind => label.
	 *
	 * @return array
	 */
	public static function labels() {
		if ( null === self::$labels ) {
			self::$labels = self::parse_labels( Settings::get( 'conversion_kind_labels', '' ) );
		}
		return self::$labels;
	}

	/**
	 * Forget the parsed overrides (after a settings save).
	 */
	public static function flush_cache() {
		self::$labels = null;
	}

	/**
	 * Parse "goal | Label" lines. Goals are matched case-insensitively.
	 *
	 * @param string $value Stored setting.
	 * @return array
	 */
	public static function parse_labels( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $goal, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' === $goal || '' === $label ) {
				continue;
			}
			$out[ strtolower( $goal ) ] = $label;
		}
		return $out;
	}

	/**
	 * Sanitize the labels setting: one "goal | Label" per line, goals limited
	 * to selector characters plus the scheme and @url forms, labels to plain
	 * text of LABEL_MAX_LENGTH.
	 *
	 * @param string $value Raw setting.
	 * @return string
	 */
	public static function sanitize_labels( $value ) {
		$out  = array();
		$seen = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ) );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $goal, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$goal  = preg_replace( '/[^a-zA-Z0-9 #\.\-_\[\]=":>~+\*\(\)\'@\/]/', '', $goal );
			$goal  = self::clip( trim( $goal ), self::GOAL_MAX_LENGTH );
			$label = self::clip( sanitize_text_field( $label ), self::LABEL_MAX_LENGTH );
			$key   = strtolower( $goal );
			if ( '' === $goal || '' === $label || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $goal . ' | ' . $label;
		}
		return implode( "\n", $out );
	}

	/**
	 * Byte-safe truncation.
	 *
	 * @param string $value Value.
	 * @param int    $bytes Maximum bytes.
	 * @return string
	 */
	private static function clip( $value, $bytes ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $bytes, 'UTF-8' ) : substr( $value, 0, $bytes );
	}
}
