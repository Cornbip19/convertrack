<?php
/**
 * Google Search Console Index Monitor settings.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'convertrack_gsc_settings';

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
			'enabled'                       => 0,
			'property_url'                  => home_url( '/' ),
			'sitemap_url'                   => home_url( '/wp-sitemap.xml' ),
			'daily_quota_limit'             => 2000,
			'batch_size'                    => 100,
			'selected_post_types'           => self::default_post_types(),
			'use_indexing_api'              => 0,
			'sitemap_submit_cooldown_hours' => 24,
			'recheck_min_hours'             => 24,
			'recheck_max_hours'             => 72,
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
			self::$cache['selected_post_types'] = self::sanitize_post_types( self::$cache['selected_post_types'] );
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
			'enabled'                       => empty( $input['enabled'] ) ? 0 : 1,
			'property_url'                  => self::sanitize_property_url( isset( $input['property_url'] ) ? wp_unslash( $input['property_url'] ) : $d['property_url'] ),
			'sitemap_url'                   => isset( $input['sitemap_url'] ) ? esc_url_raw( trim( wp_unslash( $input['sitemap_url'] ) ) ) : $d['sitemap_url'],
			'daily_quota_limit'             => self::clamp_int( $input, 'daily_quota_limit', 1, 2000, $d['daily_quota_limit'] ),
			'batch_size'                    => self::clamp_int( $input, 'batch_size', 1, 500, $d['batch_size'] ),
			'selected_post_types'           => self::sanitize_post_types( isset( $input['selected_post_types'] ) ? (array) $input['selected_post_types'] : array() ),
			'use_indexing_api'              => empty( $input['use_indexing_api'] ) ? 0 : 1,
			'sitemap_submit_cooldown_hours' => self::clamp_int( $input, 'sitemap_submit_cooldown_hours', 1, 168, $d['sitemap_submit_cooldown_hours'] ),
			'recheck_min_hours'             => self::clamp_int( $input, 'recheck_min_hours', 1, 168, $d['recheck_min_hours'] ),
			'recheck_max_hours'             => self::clamp_int( $input, 'recheck_max_hours', 1, 336, $d['recheck_max_hours'] ),
		);

		if ( empty( $clean['selected_post_types'] ) ) {
			$clean['selected_post_types'] = self::default_post_types();
		}

		if ( $clean['recheck_max_hours'] < $clean['recheck_min_hours'] ) {
			$clean['recheck_max_hours'] = $clean['recheck_min_hours'];
		}

		return $clean;
	}

	/**
	 * Post types available for monitoring.
	 *
	 * @return array
	 */
	public static function available_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$out        = array();

		foreach ( $post_types as $key => $object ) {
			if ( 'attachment' === $key ) {
				continue;
			}
			$out[ $key ] = $object;
		}

		return $out;
	}

	/**
	 * Default post types.
	 *
	 * @return array
	 */
	public static function default_post_types() {
		$available = array_keys( self::available_post_types() );
		return array_values( $available );
	}

	/**
	 * Whether the feature has enough settings and tokens to run.
	 *
	 * @return bool
	 */
	public static function ready() {
		return (bool) self::get( 'enabled' )
			&& '' !== self::get( 'property_url' )
			&& '' !== self::get( 'sitemap_url' )
			&& Credentials::is_connected();
	}

	/**
	 * Reset cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitize a Search Console siteUrl value. URL-prefix properties are URLs;
	 * domain properties use sc-domain:example.com.
	 *
	 * @param string $value Raw property URL.
	 * @return string
	 */
	private static function sanitize_property_url( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( 0 === strpos( $value, 'sc-domain:' ) ) {
			return 'sc-domain:' . sanitize_text_field( substr( $value, 10 ) );
		}

		$url = esc_url_raw( $value );
		return $url ? trailingslashit( $url ) : trailingslashit( home_url( '/' ) );
	}

	/**
	 * Sanitize selected public post types.
	 *
	 * @param array $post_types Raw post type keys.
	 * @return array
	 */
	private static function sanitize_post_types( $post_types ) {
		$available = array_keys( self::available_post_types() );
		$clean     = array();

		foreach ( (array) $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( in_array( $post_type, $available, true ) ) {
				$clean[] = $post_type;
			}
		}

		return array_values( array_unique( $clean ) );
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
