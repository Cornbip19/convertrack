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
	const SECRET_OPTION = 'convertrack_github_token';

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
			'track_search_keywords' => 0,
			'enable_geo'           => 0,
			'active_window'        => 300,
			'heartbeat_interval'   => 15,
			'flush_interval'       => 5,
			'batch_max'            => 25,
			'retention_days'       => 90,
			'aggregate_retention_days' => 400,
			'sample_rate'          => 100,
			'respect_dnt'          => 1,
			'track_logged_in'      => 0,
			'exclude_roles'        => array( 'administrator' ),
			'exclude_urls'         => "/wp-admin\n/wp-login.php",
			// Page and link query strings are stripped unless a key is explicitly
			// listed here. Sensitive key names are denied independently.
			'query_param_allowlist' => '',
			'rate_limit_per_min'   => 240,
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
		self::persist_secret_intent( $input );
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Settings API callback that handles the separately stored secret before
	 * returning the public configuration array WordPress should persist.
	 *
	 * @param mixed $input Submitted setting value.
	 * @return array
	 */
	public static function sanitize_registered( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$secret = self::persist_secret_intent( $input );
		if ( is_wp_error( $secret ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'convertrack_github_token_write',
				$secret->get_error_message(),
				'error'
			);
		}
		return self::sanitize( $input );
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
		$clean['enable_geo']      = empty( $input['enable_geo'] ) ? 0 : 1;
		$clean['track_search_keywords'] = empty( $input['track_search_keywords'] ) ? 0 : 1;

		$clean['track_selectors']      = self::sanitize_selector_list( isset( $input['track_selectors'] ) ? $input['track_selectors'] : $d['track_selectors'] );
		$clean['conversion_selectors'] = self::sanitize_selector_list( isset( $input['conversion_selectors'] ) ? $input['conversion_selectors'] : $d['conversion_selectors'] );
		$clean['conversion_urls']      = self::sanitize_lines( isset( $input['conversion_urls'] ) ? $input['conversion_urls'] : $d['conversion_urls'] );
		$clean['exclude_urls']         = self::sanitize_lines( isset( $input['exclude_urls'] ) ? $input['exclude_urls'] : $d['exclude_urls'] );
		$clean['query_param_allowlist'] = self::sanitize_param_list( isset( $input['query_param_allowlist'] ) ? $input['query_param_allowlist'] : $d['query_param_allowlist'] );

		$clean['active_window']      = self::clamp_int( $input, 'active_window', 30, 3600, $d['active_window'] );
		$clean['heartbeat_interval'] = self::clamp_int( $input, 'heartbeat_interval', 5, 120, $d['heartbeat_interval'] );
		$clean['flush_interval']     = self::clamp_int( $input, 'flush_interval', 1, 60, $d['flush_interval'] );
		$clean['batch_max']          = self::clamp_int( $input, 'batch_max', 1, 50, $d['batch_max'] );
		$clean['retention_days']     = self::clamp_int( $input, 'retention_days', 1, 3650, $d['retention_days'] );
		$clean['aggregate_retention_days'] = self::clamp_int( $input, 'aggregate_retention_days', 32, 3650, $d['aggregate_retention_days'] );
		$clean['sample_rate']        = self::clamp_int( $input, 'sample_rate', 1, 100, $d['sample_rate'] );
		$clean['rate_limit_per_min'] = self::clamp_int( $input, 'rate_limit_per_min', 10, 100000, $d['rate_limit_per_min'] );

		$roles = isset( $input['exclude_roles'] ) ? (array) $input['exclude_roles'] : array();
		$clean['exclude_roles'] = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );

		return $clean;
	}

	/**
	 * Read the updater secret without ever exposing it through Settings::all().
	 *
	 * @return string
	 */
	public static function github_token() {
		if ( defined( 'CONVERTRACK_GITHUB_TOKEN' ) && CONVERTRACK_GITHUB_TOKEN ) {
			return trim( (string) CONVERTRACK_GITHUB_TOKEN );
		}
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( '' !== (string) $secret ) {
			return trim( (string) $secret );
		}
		// One-release read-only compatibility for an older autoloaded setting.
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && ! empty( $stored['github_token'] ) ? trim( (string) $stored['github_token'] ) : '';
	}

	/**
	 * Move a legacy token to the non-autoloaded secret option in admin context.
	 *
	 * @return true|\WP_Error
	 */
	public static function migrate_secret() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['github_token'] ) ) {
			return true;
		}
		$existing_secret = get_option( self::SECRET_OPTION, false );
		if ( false === $existing_secret || '' === trim( (string) $existing_secret ) ) {
			$result = self::persist_secret_intent( array( 'github_token' => $stored['github_token'] ) );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'convertrack_secret_migration', 'The updater token could not be moved to secure storage.' );
			}
		}
		unset( $stored['github_token'] );
		if ( ! update_option( self::OPTION, $stored ) ) {
			return new \WP_Error( 'convertrack_secret_migration', 'The legacy updater token could not be removed from settings.' );
		}
		self::flush_cache();
		return true;
	}

	/**
	 * Apply an explicit replace/clear intent to the non-autoloaded secret.
	 * A blank password field means "preserve", matching password-manager-safe UI.
	 *
	 * @param array $input Submitted settings.
	 * @return true|\WP_Error
	 */
	private static function persist_secret_intent( array $input ) {
		if ( isset( $input['github_token'] ) && '' !== trim( (string) $input['github_token'] ) ) {
			$token = preg_replace( '/[\x00-\x20\x7f]+/', '', (string) $input['github_token'] );
			$token = substr( $token, 0, 512 );
			if ( '' === $token ) {
				return new \WP_Error( 'convertrack_secret_invalid', __( 'The GitHub token contained no usable characters.', 'convertrack-click-conversion-analytics' ) );
			}
			$current = get_option( self::SECRET_OPTION, null );
			if ( is_string( $current ) && hash_equals( $current, $token ) ) {
				return true;
			}
			$written = null === $current
				? add_option( self::SECRET_OPTION, $token, '', false )
				: update_option( self::SECRET_OPTION, $token, false );
			if ( ! $written && $token !== (string) get_option( self::SECRET_OPTION, '' ) ) {
				return new \WP_Error( 'convertrack_secret_write', __( 'The GitHub token could not be saved. Check database permissions and try again.', 'convertrack-click-conversion-analytics' ) );
			}
			return true;
		}

		if ( ! empty( $input['github_token_clear'] ) ) {
			delete_option( self::SECRET_OPTION );
			if ( false !== get_option( self::SECRET_OPTION, false ) ) {
				return new \WP_Error( 'convertrack_secret_delete', __( 'The stored GitHub token could not be removed. Check database permissions and try again.', 'convertrack-click-conversion-analytics' ) );
			}
		}
		return true;
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
	 * Normalize a query-parameter allowlist. A second denylist is enforced by
	 * the collector, so sensitive names cannot be enabled accidentally.
	 *
	 * @param string $value Newline/comma separated parameter names.
	 * @return string
	 */
	public static function sanitize_param_list( $value ) {
		$parts = preg_split( '/[\r\n,]+/', strtolower( (string) $value ) );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = sanitize_key( trim( $part ) );
			if ( '' !== $part && ! Collector::is_sensitive_param_name( $part ) ) {
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
