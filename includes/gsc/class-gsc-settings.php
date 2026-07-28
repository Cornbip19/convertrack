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
			'log_retention_days'            => 30,
			'queue_retention_days'          => 90,
			// Paths unblocked by the "Add Allow rule" fix, emitted into the virtual
			// robots.txt. Never written to a physical robots.txt file.
			'robots_allow_paths'            => array(),
			// Extra URL discovery beyond the sitemap, so 404 and redirect reasons
			// can surface at all -- those URLs are by definition not in a sitemap.
			'discover_from_search_console'  => 1,
			'discover_from_broken_urls'     => 1,
		);
	}

	/**
	 * Paths with a stored robots.txt Allow rule.
	 *
	 * @return array
	 */
	public static function robots_allow_rules() {
		$paths = self::get( 'robots_allow_paths', array() );
		return is_array( $paths ) ? array_values( array_filter( array_map( 'strval', $paths ) ) ) : array();
	}

	/**
	 * Store one robots.txt Allow path.
	 *
	 * @param string $path Root-relative path.
	 * @return true|\WP_Error
	 */
	public static function add_robots_allow_rule( $path ) {
		$path = '/' . ltrim( trim( (string) $path ), '/' );
		if ( '/' === $path || strlen( $path ) > 500 || preg_match( '/[\r\n]/', $path ) ) {
			return new \WP_Error( 'convertrack_gsc_bad_robots_path', __( 'That path cannot be added to robots.txt.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		$paths = self::robots_allow_rules();
		if ( in_array( $path, $paths, true ) ) {
			return true;
		}
		// Bounded: robots.txt is fetched on every crawl, so this must not grow
		// without limit.
		if ( count( $paths ) >= 200 ) {
			return new \WP_Error( 'convertrack_gsc_too_many_robots_rules', __( 'The robots.txt Allow list is full. Remove some rules first.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		$paths[] = $path;
		$all     = self::all();
		$all['robots_allow_paths'] = $paths;
		self::save( $all );
		return true;
	}

	/**
	 * Emit stored Allow rules into WordPress's virtual robots.txt.
	 *
	 * WordPress only serves this when no robots.txt file exists on disk, so there
	 * is no risk of contradicting a real file.
	 */
	public static function register_robots_filter() {
		add_filter(
			'robots_txt',
			static function ( $output ) {
				$paths = self::robots_allow_rules();
				if ( empty( $paths ) ) {
					return $output;
				}
				$lines = array( '', '# Convertrack: unblocked so Google can index these paths' );
				foreach ( $paths as $path ) {
					$lines[] = 'Allow: ' . $path;
				}
				return $output . implode( "\n", $lines ) . "\n";
			},
			20
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
			'log_retention_days'            => self::clamp_int( $input, 'log_retention_days', 1, 365, isset( $input['log_retention_days'] ) ? $d['log_retention_days'] : self::get( 'log_retention_days', $d['log_retention_days'] ) ),
			'queue_retention_days'          => self::clamp_int( $input, 'queue_retention_days', 7, 730, isset( $input['queue_retention_days'] ) ? $d['queue_retention_days'] : self::get( 'queue_retention_days', $d['queue_retention_days'] ) ),
			'discover_from_search_console'  => array_key_exists( 'discover_from_search_console', $input ) ? ( empty( $input['discover_from_search_console'] ) ? 0 : 1 ) : (int) self::get( 'discover_from_search_console', $d['discover_from_search_console'] ),
			'discover_from_broken_urls'     => array_key_exists( 'discover_from_broken_urls', $input ) ? ( empty( $input['discover_from_broken_urls'] ) ? 0 : 1 ) : (int) self::get( 'discover_from_broken_urls', $d['discover_from_broken_urls'] ),
			// Plugin-managed state, not a form field. sanitize() returns only the
			// keys it lists, so without carrying this through every settings save
			// would silently wipe the stored Allow rules.
			'robots_allow_paths'            => self::sanitize_robots_paths( array_key_exists( 'robots_allow_paths', $input ) ? $input['robots_allow_paths'] : self::get( 'robots_allow_paths', array() ) ),
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
	 * Sanitize stored robots.txt Allow paths.
	 *
	 * Newlines would let a single entry inject arbitrary robots.txt directives,
	 * so they are stripped rather than escaped.
	 *
	 * @param mixed $paths Stored or submitted paths.
	 * @return array
	 */
	private static function sanitize_robots_paths( $paths ) {
		if ( ! is_array( $paths ) ) {
			return array();
		}
		$out = array();
		foreach ( $paths as $path ) {
			$path = preg_replace( '/[\r\n]+/', '', (string) $path );
			$path = trim( $path );
			if ( '' === $path || '/' === $path ) {
				continue;
			}
			$path = '/' . ltrim( $path, '/' );
			if ( strlen( $path ) > 500 ) {
				continue;
			}
			$out[ $path ] = $path;
		}
		return array_slice( array_values( $out ), 0, 200 );
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
