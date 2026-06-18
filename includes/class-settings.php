<?php
/**
 * Settings store: defaults, retrieval and sanitization.
 *
 * All settings live in a single option array to keep autoload light.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'convertrack_settings';

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
			'enabled'              => 1,
			'track_selectors'      => "a\nbutton\ninput[type=submit]\ninput[type=button]\ninput[type=image]\n[role=button]\n.btn\n.button\n.wp-block-button__link\n[data-cvtrk]",
			'conversion_selectors' => ".cvtrk-convert\n[data-cvtrk-convert]",
			'conversion_urls'      => '',
			'active_window'        => 300,
			'heartbeat_interval'   => 15,
			'flush_interval'       => 5,
			'batch_max'            => 25,
			'retention_days'       => 90,
			'sample_rate'          => 100,
			'respect_dnt'          => 1,
			'track_logged_in'      => 0,
			'exclude_roles'        => array( 'administrator' ),
			'exclude_urls'         => "/wp-admin\n/wp-login.php",
			'rate_limit_per_min'   => 240,
			'github_token'         => '',
		);
	}

	/**
	 * Return all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a sanitized full settings array.
	 *
	 * @param array $input Raw input (e.g. from $_POST).
	 * @return array Sanitized values that were stored.
	 */
	public static function save( array $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Sanitize a raw settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d     = self::defaults();
		$clean = array();

		$clean['enabled']         = empty( $input['enabled'] ) ? 0 : 1;
		$clean['respect_dnt']     = empty( $input['respect_dnt'] ) ? 0 : 1;
		$clean['track_logged_in'] = empty( $input['track_logged_in'] ) ? 0 : 1;

		$clean['track_selectors']      = self::sanitize_selector_list( isset( $input['track_selectors'] ) ? $input['track_selectors'] : $d['track_selectors'] );
		$clean['conversion_selectors'] = self::sanitize_selector_list( isset( $input['conversion_selectors'] ) ? $input['conversion_selectors'] : $d['conversion_selectors'] );
		$clean['conversion_urls']      = self::sanitize_lines( isset( $input['conversion_urls'] ) ? $input['conversion_urls'] : $d['conversion_urls'] );
		$clean['exclude_urls']         = self::sanitize_lines( isset( $input['exclude_urls'] ) ? $input['exclude_urls'] : $d['exclude_urls'] );

		$clean['active_window']      = self::clamp_int( $input, 'active_window', 30, 3600, $d['active_window'] );
		$clean['heartbeat_interval'] = self::clamp_int( $input, 'heartbeat_interval', 5, 120, $d['heartbeat_interval'] );
		$clean['flush_interval']     = self::clamp_int( $input, 'flush_interval', 1, 60, $d['flush_interval'] );
		$clean['batch_max']          = self::clamp_int( $input, 'batch_max', 1, 50, $d['batch_max'] );
		$clean['retention_days']     = self::clamp_int( $input, 'retention_days', 1, 3650, $d['retention_days'] );
		$clean['sample_rate']        = self::clamp_int( $input, 'sample_rate', 1, 100, $d['sample_rate'] );
		$clean['rate_limit_per_min'] = self::clamp_int( $input, 'rate_limit_per_min', 10, 100000, $d['rate_limit_per_min'] );

		$roles = isset( $input['exclude_roles'] ) ? (array) $input['exclude_roles'] : array();
		$clean['exclude_roles'] = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );

		$clean['github_token'] = isset( $input['github_token'] ) ? trim( sanitize_text_field( $input['github_token'] ) ) : '';

		return $clean;
	}

	/**
	 * Clamp an integer field within bounds.
	 */
	private static function clamp_int( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $fallback;
		}
		$val = (int) $input[ $key ];
		return max( $min, min( $max, $val ) );
	}

	/**
	 * Normalize a newline/comma separated CSS-selector list into newline list.
	 * Strips characters that have no business in a selector to keep the JS safe.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_selector_list( $value ) {
		$value = (string) $value;
		$parts = preg_split( '/[\r\n,]+/', $value );
		$out   = array();

		foreach ( $parts as $part ) {
			$part = trim( wp_strip_all_tags( $part ) );
			if ( '' === $part ) {
				continue;
			}
			// Allow only characters valid in the selectors we support.
			$part = preg_replace( '/[^a-zA-Z0-9 #\.\-_\[\]=":>~+\*\(\)\']/', '', $part );
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Sanitize a newline-separated list of plain strings (URLs/paths).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_lines( $value ) {
		$value = (string) $value;
		$parts = preg_split( '/[\r\n]+/', $value );
		$out   = array();

		foreach ( $parts as $part ) {
			$part = trim( sanitize_text_field( $part ) );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Convert a stored newline list to a trimmed array.
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
	 * Reset the in-request cache (used after external option updates).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
