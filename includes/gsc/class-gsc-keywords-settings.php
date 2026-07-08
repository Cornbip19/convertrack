<?php
/**
 * GSC Keyword Insights settings.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Settings {

	const OPTION = 'convertrack_gsc_keywords_settings';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => 0,
			'auto_sync'           => 'weekly',
			'default_range'       => '28d',
			'sync_ranges'         => array( '7d', '28d', '3m' ),
			'min_impressions'     => 10,
			'min_position'        => 4,
			'low_ctr_ratio'       => 0.5,
			'selected_post_types' => Settings::default_post_types(),
			'keyword_types'       => array(),
			'brand_terms'         => array(),
			'location_terms'      => array(),
			'service_terms'       => array(),
			'product_terms'       => array(),
			'competitor_terms'    => array(),
			'row_cap'             => 5000,
			'country_filter'      => '',
			'track_devices'       => 0,
		);
	}

	/**
	 * Return all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist sanitized settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function save( array $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d = self::defaults();

		$clean = array(
			'enabled'             => empty( $input['enabled'] ) ? 0 : 1,
			'auto_sync'           => self::whitelist( $input, 'auto_sync', array( 'daily', 'weekly', 'manual' ), $d['auto_sync'] ),
			'default_range'       => self::whitelist( $input, 'default_range', self::ranges_vocabulary(), $d['default_range'] ),
			'sync_ranges'         => self::sanitize_ranges( isset( $input['sync_ranges'] ) ? (array) $input['sync_ranges'] : array() ),
			'min_impressions'     => self::clamp_int( $input, 'min_impressions', 0, 10000, $d['min_impressions'] ),
			'min_position'        => self::clamp_int( $input, 'min_position', 1, 100, $d['min_position'] ),
			'low_ctr_ratio'       => self::clamp_int( $input, 'low_ctr_sensitivity', 10, 100, (int) ( $d['low_ctr_ratio'] * 100 ) ) / 100,
			'selected_post_types' => self::sanitize_post_types( isset( $input['selected_post_types'] ) ? (array) $input['selected_post_types'] : array() ),
			'keyword_types'       => self::sanitize_types( isset( $input['keyword_types'] ) ? (array) $input['keyword_types'] : array() ),
			'brand_terms'         => self::sanitize_terms( isset( $input['brand_terms'] ) ? $input['brand_terms'] : array() ),
			'location_terms'      => self::sanitize_terms( isset( $input['location_terms'] ) ? $input['location_terms'] : array() ),
			'service_terms'       => self::sanitize_terms( isset( $input['service_terms'] ) ? $input['service_terms'] : array() ),
			'product_terms'       => self::sanitize_terms( isset( $input['product_terms'] ) ? $input['product_terms'] : array() ),
			'competitor_terms'    => self::sanitize_terms( isset( $input['competitor_terms'] ) ? $input['competitor_terms'] : array() ),
			'row_cap'             => self::clamp_int( $input, 'row_cap', 100, 25000, $d['row_cap'] ),
			'country_filter'      => self::sanitize_country( isset( $input['country_filter'] ) ? $input['country_filter'] : '' ),
			'track_devices'       => empty( $input['track_devices'] ) ? 0 : 1,
		);

		if ( empty( $clean['selected_post_types'] ) ) {
			$clean['selected_post_types'] = Settings::default_post_types();
		}
		if ( empty( $clean['sync_ranges'] ) ) {
			$clean['sync_ranges'] = array( $clean['default_range'] );
		}

		return $clean;
	}

	/**
	 * Whether the feature has enough settings and tokens to run. Deliberately
	 * independent of the Index Monitor's own 'enabled' flag: keyword insights
	 * only needs OAuth plus a property.
	 *
	 * @return bool
	 */
	public static function ready() {
		return (bool) self::get( 'enabled' )
			&& '' !== Settings::get( 'property_url' )
			&& Credentials::is_connected();
	}

	/**
	 * Supported preset range keys.
	 *
	 * @return array
	 */
	public static function ranges_vocabulary() {
		return array( '7d', '28d', '3m', '6m' );
	}

	/**
	 * Keyword type slugs the classifier can emit; used as the settings whitelist.
	 *
	 * @return array
	 */
	public static function types_vocabulary() {
		return apply_filters(
			'convertrack_gsc_keywords_types',
			array( 'branded', 'non_branded', 'service', 'product', 'location', 'commercial', 'informational', 'transactional', 'navigational', 'question', 'long_tail', 'competitor' )
		);
	}

	/**
	 * Reset cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Keep only known range keys, preserving order and uniqueness.
	 *
	 * @param array $ranges Raw range keys.
	 * @return array
	 */
	private static function sanitize_ranges( array $ranges ) {
		$vocabulary = self::ranges_vocabulary();
		$clean      = array();
		foreach ( $ranges as $range ) {
			$range = sanitize_key( $range );
			if ( in_array( $range, $vocabulary, true ) && ! in_array( $range, $clean, true ) ) {
				$clean[] = $range;
			}
		}
		return $clean;
	}

	/**
	 * Keep only known keyword type slugs.
	 *
	 * @param array $types Raw type slugs.
	 * @return array
	 */
	private static function sanitize_types( array $types ) {
		$vocabulary = self::types_vocabulary();
		$clean      = array();
		foreach ( $types as $type ) {
			$type = sanitize_key( $type );
			if ( in_array( $type, $vocabulary, true ) && ! in_array( $type, $clean, true ) ) {
				$clean[] = $type;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize a user term list. Accepts an array or a textarea string split on
	 * newlines/commas. Capped at 100 entries.
	 *
	 * @param array|string $terms Raw terms.
	 * @return array
	 */
	private static function sanitize_terms( $terms ) {
		if ( is_string( $terms ) ) {
			$terms = preg_split( '/[\r\n,]+/', wp_unslash( $terms ) );
		}

		$clean = array();
		foreach ( (array) $terms as $term ) {
			$term = trim( sanitize_text_field( (string) $term ) );
			if ( '' !== $term && ! in_array( $term, $clean, true ) ) {
				$clean[] = $term;
			}
			if ( count( $clean ) >= 100 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize the ISO-3166-1 alpha-3 country filter.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_country( $value ) {
		$value = strtolower( trim( sanitize_key( (string) $value ) ) );
		return preg_match( '/^[a-z]{3}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize selected public post types (mirrors GSC\Settings, whose helper is private).
	 *
	 * @param array $post_types Raw post type keys.
	 * @return array
	 */
	private static function sanitize_post_types( array $post_types ) {
		$available = array_keys( Settings::available_post_types() );
		$clean     = array();

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( in_array( $post_type, $available, true ) ) {
				$clean[] = $post_type;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whitelist a string field.
	 *
	 * @param array  $input    Input.
	 * @param string $key      Field key.
	 * @param array  $allowed  Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function whitelist( $input, $key, array $allowed, $fallback ) {
		$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Clamp an integer field.
	 *
	 * @param array  $input    Input.
	 * @param string $key      Field key.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	private static function clamp_int( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $fallback;
		}

		$val = (int) $input[ $key ];
		return max( $min, min( $max, $val ) );
	}
}
