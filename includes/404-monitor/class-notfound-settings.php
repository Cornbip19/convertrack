<?php
/**
 * 404 Monitor settings.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'convertrack_404_settings';

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
			'enabled'                => 1,
			'mode'                   => 'recommend',
			'auto_min_confidence'    => 90,
			'fallback_url'           => home_url( '/' ),
			'sitemap_urls'           => '',
			'sitemap_refresh_hours'  => 24,
			'scan_frequency'         => 'hourly',
			'recommendation_batch'   => 50,
			'retention_days'         => 180,
			'ignore_query_params'    => "utm_source\nutm_medium\nutm_campaign\nutm_term\nutm_content\ngclid\nfbclid\nmsclkid",
			'ignore_patterns'        => "/wp-admin\n/wp-login.php\n/wp-json\n/feed\n/favicon.ico",
			'exclude_post_types'     => array( 'attachment' ),
			'exclude_taxonomies'     => array(),
			'email_notifications'    => 0,
			'spike_threshold'        => 50,
			'spike_window_minutes'   => 60,
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
			self::$cache['exclude_post_types'] = self::sanitize_post_types( self::$cache['exclude_post_types'], false );
			self::$cache['exclude_taxonomies'] = self::sanitize_taxonomies( self::$cache['exclude_taxonomies'], false );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
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
	 * Save sanitized settings.
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
		$d     = self::defaults();
		$modes = array( 'monitor', 'recommend', 'auto_high_confidence', 'manual' );

		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : $d['mode'];
		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = $d['mode'];
		}

		$clean = array(
			'enabled'               => empty( $input['enabled'] ) ? 0 : 1,
			'mode'                  => $mode,
			'auto_min_confidence'   => self::clamp_int( $input, 'auto_min_confidence', 50, 100, $d['auto_min_confidence'] ),
			'fallback_url'          => self::sanitize_destination( isset( $input['fallback_url'] ) ? wp_unslash( $input['fallback_url'] ) : $d['fallback_url'] ),
			'sitemap_urls'          => self::sanitize_url_lines( isset( $input['sitemap_urls'] ) ? wp_unslash( $input['sitemap_urls'] ) : $d['sitemap_urls'] ),
			'sitemap_refresh_hours' => self::clamp_int( $input, 'sitemap_refresh_hours', 1, 168, $d['sitemap_refresh_hours'] ),
			'scan_frequency'        => self::sanitize_frequency( isset( $input['scan_frequency'] ) ? $input['scan_frequency'] : $d['scan_frequency'] ),
			'recommendation_batch'  => self::clamp_int( $input, 'recommendation_batch', 5, 500, $d['recommendation_batch'] ),
			'retention_days'        => self::clamp_int( $input, 'retention_days', 1, 3650, $d['retention_days'] ),
			'ignore_query_params'   => self::sanitize_key_lines( isset( $input['ignore_query_params'] ) ? wp_unslash( $input['ignore_query_params'] ) : $d['ignore_query_params'] ),
			'ignore_patterns'       => self::sanitize_lines( isset( $input['ignore_patterns'] ) ? wp_unslash( $input['ignore_patterns'] ) : $d['ignore_patterns'] ),
			'exclude_post_types'    => self::sanitize_post_types( isset( $input['exclude_post_types'] ) ? (array) $input['exclude_post_types'] : array(), true ),
			'exclude_taxonomies'    => self::sanitize_taxonomies( isset( $input['exclude_taxonomies'] ) ? (array) $input['exclude_taxonomies'] : array(), true ),
			'email_notifications'   => empty( $input['email_notifications'] ) ? 0 : 1,
			'spike_threshold'       => self::clamp_int( $input, 'spike_threshold', 5, 10000, $d['spike_threshold'] ),
			'spike_window_minutes'  => self::clamp_int( $input, 'spike_window_minutes', 5, 1440, $d['spike_window_minutes'] ),
		);

		return $clean;
	}

	/**
	 * Available public post types.
	 *
	 * @return array
	 */
	public static function available_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		return $post_types;
	}

	/**
	 * Available public taxonomies.
	 *
	 * @return array
	 */
	public static function available_taxonomies() {
		return get_taxonomies( array( 'public' => true ), 'objects' );
	}

	/**
	 * Whether recommendations/redirects should be generated.
	 *
	 * @return bool
	 */
	public static function recommendations_enabled() {
		return self::get( 'enabled' ) && 'monitor' !== self::get( 'mode' );
	}

	/**
	 * Convert stored newline values to an array.
	 *
	 * @param string $value Stored value.
	 * @return array
	 */
	public static function lines_to_array( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $value ) ) ) );
	}

	/**
	 * Reset cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Clamp int field.
	 */
	private static function clamp_int( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Sanitize scan frequency.
	 *
	 * @param string $value Raw frequency.
	 * @return string
	 */
	private static function sanitize_frequency( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $value : 'hourly';
	}

	/**
	 * Sanitize fallback destination.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_destination( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, '/' ) ) {
			return esc_url_raw( home_url( $value ) );
		}
		return esc_url_raw( $value );
	}

	/**
	 * Sanitize newline-separated URLs.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_url_lines( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Sanitize newline-separated keys.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_key_lines( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $value ) as $line ) {
			$key = sanitize_key( trim( $line ) );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Sanitize plain newline values.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_lines( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Sanitize post type exclusions.
	 *
	 * @param array $values Raw values.
	 * @param bool  $validate Against current public types.
	 * @return array
	 */
	private static function sanitize_post_types( $values, $validate ) {
		$available = $validate ? array_keys( self::available_post_types() ) : array();
		$out       = array();
		foreach ( (array) $values as $value ) {
			$key = sanitize_key( $value );
			if ( '' === $key ) {
				continue;
			}
			if ( ! $validate || in_array( $key, $available, true ) ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize taxonomy exclusions.
	 *
	 * @param array $values Raw values.
	 * @param bool  $validate Against current public taxonomies.
	 * @return array
	 */
	private static function sanitize_taxonomies( $values, $validate ) {
		$available = $validate ? array_keys( self::available_taxonomies() ) : array();
		$out       = array();
		foreach ( (array) $values as $value ) {
			$key = sanitize_key( $value );
			if ( '' === $key ) {
				continue;
			}
			if ( ! $validate || in_array( $key, $available, true ) ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}
}

