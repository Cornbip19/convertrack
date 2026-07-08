<?php
/**
 * Compatibility checks for common redirect tools.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Compatibility {

	/**
	 * Status for the admin UI.
	 *
	 * @return array
	 */
	public static function status() {
		$tools = self::detected_tools();
		return array(
			'has_redirect_tool' => ! empty( $tools ),
			'tools'             => $tools,
			'htaccess_hint'     => self::htaccess_hint(),
		);
	}

	/**
	 * Whether a known redirect tool is active.
	 *
	 * @return bool
	 */
	public static function has_redirect_tool() {
		return ! empty( self::detected_tools() );
	}

	/**
	 * List detected tools.
	 *
	 * @return array
	 */
	public static function detected_tools() {
		$tools = array();

		if ( self::plugin_active( 'redirection/redirection.php' ) || class_exists( '\Redirection' ) || class_exists( '\Red_Item' ) ) {
			$tools[] = array( 'key' => 'redirection', 'label' => 'Redirection' );
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) || self::plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			$tools[] = array( 'key' => 'rank_math', 'label' => 'Rank Math' );
		}
		if ( defined( 'WPSEO_VERSION' ) || self::plugin_active( 'wordpress-seo/wp-seo.php' ) || self::plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
			$tools[] = array( 'key' => 'yoast', 'label' => 'Yoast SEO' );
		}
		if ( defined( 'SEOPRESS_VERSION' ) || self::plugin_active( 'wp-seopress/seopress.php' ) || self::plugin_active( 'wp-seopress-pro/seopress-pro.php' ) ) {
			$tools[] = array( 'key' => 'seopress', 'label' => 'SEOPress' );
		}

		return $tools;
	}

	/**
	 * Check whether an external redirect already handles a source.
	 *
	 * @param string $source Source URL/path.
	 * @return array|null
	 */
	public static function external_redirect_for_source( $source ) {
		$normalized = Database::normalize_source( $source );
		if ( empty( $normalized ) ) {
			return null;
		}

		$path = untrailingslashit( $normalized['path'] );
		if ( '' === $path ) {
			$path = '/';
		}

		foreach ( self::redirection_rows( 500, $path ) as $row ) {
			return $row;
		}
		foreach ( self::rank_math_rows( 500, $path ) as $row ) {
			return $row;
		}

		return null;
	}

	/**
	 * Read-only external redirect rows.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function external_redirects( $limit = 100 ) {
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = array_merge( self::redirection_rows( $limit ), self::rank_math_rows( $limit ) );
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Plugin active helper.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function plugin_active( $plugin ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin );
	}

	/**
	 * Redirection plugin rows.
	 *
	 * @param int         $limit Limit.
	 * @param string|null $path  Optional source path.
	 * @return array
	 */
	private static function redirection_rows( $limit, $path = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		if ( ! Database::table_exists( $table ) ) {
			return array();
		}
		$columns = self::columns( $table );
		if ( empty( $columns ) || ! in_array( 'url', $columns, true ) ) {
			return array();
		}

		$select = array(
			'id',
			'url',
			in_array( 'action_code', $columns, true ) ? 'action_code' : '301 AS action_code',
			in_array( 'action_data', $columns, true ) ? 'action_data' : "'' AS action_data",
			in_array( 'hits', $columns, true ) ? 'hits' : '0 AS hits',
			in_array( 'last_access', $columns, true ) ? 'last_access' : "NULL AS last_access",
			in_array( 'status', $columns, true ) ? 'status' : "'enabled' AS status",
		);
		$where = '';
		$args  = array();
		if ( null !== $path ) {
			$where = ' WHERE url = %s OR url = %s';
			$args[] = $path;
			$args[] = trailingslashit( $path );
		}

		$sql = 'SELECT ' . implode( ',', $select ) . " FROM $table" . $where . ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 200, (int) $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'              => 'redirection-' . (int) $row['id'],
				'source_url'      => (string) $row['url'],
				'destination_url' => (string) $row['action_data'],
				'redirect_type'   => (int) $row['action_code'],
				'status'          => (string) $row['status'],
				'created_at'      => '',
				'last_hit_at'     => isset( $row['last_access'] ) ? (string) $row['last_access'] : '',
				'hit_count'       => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
				'provider'        => 'Redirection',
				'external'        => true,
			);
		}
		return $out;
	}

	/**
	 * Rank Math redirect rows.
	 *
	 * @param int         $limit Limit.
	 * @param string|null $path  Optional source path.
	 * @return array
	 */
	private static function rank_math_rows( $limit, $path = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! Database::table_exists( $table ) ) {
			return array();
		}
		$columns = self::columns( $table );
		if ( empty( $columns ) || ! in_array( 'sources', $columns, true ) || ! in_array( 'url_to', $columns, true ) ) {
			return array();
		}

		$where = '';
		$args  = array();
		if ( null !== $path ) {
			$where = ' WHERE sources LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $path ) . '%';
		}

		$status_col = in_array( 'status', $columns, true ) ? 'status' : "'active' AS status";
		$hits_col   = in_array( 'hits', $columns, true ) ? 'hits' : '0 AS hits';
		$created    = in_array( 'created', $columns, true ) ? 'created' : "'' AS created";
		$sql        = "SELECT id,sources,url_to,header_code,$status_col,$hits_col,$created FROM $table" . $where . ' ORDER BY id DESC LIMIT %d';
		$args[]     = max( 1, min( 200, (int) $limit ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$sources = self::rank_math_sources( $row['sources'] );
			if ( null !== $path && ! in_array( $path, $sources, true ) && ! in_array( trailingslashit( $path ), $sources, true ) ) {
				continue;
			}
			$out[] = array(
				'id'              => 'rank-math-' . (int) $row['id'],
				'source_url'      => implode( ', ', $sources ),
				'destination_url' => (string) $row['url_to'],
				'redirect_type'   => isset( $row['header_code'] ) ? (int) $row['header_code'] : 301,
				'status'          => (string) $row['status'],
				'created_at'      => isset( $row['created'] ) ? (string) $row['created'] : '',
				'last_hit_at'     => '',
				'hit_count'       => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
				'provider'        => 'Rank Math',
				'external'        => true,
			);
		}
		return $out;
	}

	/**
	 * Decode Rank Math source values.
	 *
	 * @param string $raw Serialized/raw source.
	 * @return array
	 */
	private static function rank_math_sources( $raw ) {
		$out = array();
		$decoded = maybe_unserialize( $raw );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $entry ) {
				if ( is_array( $entry ) && isset( $entry['pattern'] ) ) {
					$out[] = (string) $entry['pattern'];
				} elseif ( is_string( $entry ) ) {
					$out[] = $entry;
				}
			}
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$out[] = $raw;
		}
		return array_values( array_filter( array_unique( $out ) ) );
	}

	/**
	 * Table column names.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	private static function columns( $table ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'DESCRIBE ' . $table, ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $row ) {
				return isset( $row['Field'] ) ? (string) $row['Field'] : '';
			},
			(array) $rows
		);
	}

	/**
	 * Return a warning if .htaccess appears to contain redirects.
	 *
	 * @return string
	 */
	private static function htaccess_hint() {
		$file = trailingslashit( ABSPATH ) . '.htaccess';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_string( $contents ) || '' === $contents ) {
			return '';
		}
		if ( preg_match( '/\b(Redirect|RedirectMatch|RewriteRule)\b/i', $contents ) ) {
			return __( '.htaccess contains redirect-style rules. Convertrack shows this as a warning only and does not modify the file.', 'convertrack-click-conversion-analytics' );
		}
		return '';
	}
}

