<?php
/**
 * SEO title / meta description reader across popular SEO plugins.
 *
 * Reads Yoast, Rank Math, AIOSEO and SEOPress per-post fields where present,
 * resolving their template variables best-effort. When a post has no override
 * saved we fall back to the post title and report the description as missing
 * rather than trying to reconstruct plugin-global templates.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Seo_Meta {

	/**
	 * Cached AIOSEO table availability.
	 *
	 * @var bool|null
	 */
	private static $aioseo_table = null;

	/**
	 * SEO title + description for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array { title, description, source }
	 */
	public static function for_post( $post_id ) {
		$post_id = absint( $post_id );
		$meta    = array(
			'title'       => '',
			'description' => '',
			'source'      => self::provider(),
		);

		switch ( $meta['source'] ) {
			case 'yoast':
				$meta['title']       = self::resolve( (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ), $post_id, 'yoast' );
				$meta['description'] = self::resolve( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ), $post_id, 'yoast' );
				break;
			case 'rank_math':
				$meta['title']       = self::resolve( (string) get_post_meta( $post_id, 'rank_math_title', true ), $post_id, 'rank_math' );
				$meta['description'] = self::resolve( (string) get_post_meta( $post_id, 'rank_math_description', true ), $post_id, 'rank_math' );
				break;
			case 'aioseo':
				$row                 = self::aioseo_row( $post_id );
				$meta['title']       = self::resolve( isset( $row['title'] ) ? (string) $row['title'] : '', $post_id, 'aioseo' );
				$meta['description'] = self::resolve( isset( $row['description'] ) ? (string) $row['description'] : '', $post_id, 'aioseo' );
				break;
			case 'seopress':
				$meta['title']       = self::resolve( (string) get_post_meta( $post_id, '_seopress_titles_title', true ), $post_id, 'seopress' );
				$meta['description'] = self::resolve( (string) get_post_meta( $post_id, '_seopress_titles_desc', true ), $post_id, 'seopress' );
				break;
		}

		if ( '' === $meta['title'] ) {
			$meta['title'] = (string) get_the_title( $post_id );
		}
		if ( '' === $meta['description'] && 'fallback' === $meta['source'] ) {
			$post = get_post( $post_id );
			if ( $post ) {
				// Never get_the_excerpt() here — it runs the_content filters.
				$meta['description'] = '' !== $post->post_excerpt
					? (string) $post->post_excerpt
					: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 30, '' );
			}
		}

		return apply_filters( 'convertrack_keywords_seo_meta', $meta, $post_id );
	}

	/**
	 * Detected SEO plugin slug.
	 *
	 * @return string yoast|rank_math|aioseo|seopress|fallback
	 */
	public static function provider() {
		static $provider = null;
		if ( null !== $provider ) {
			return $provider;
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$provider = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$provider = 'rank_math';
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$provider = 'aioseo';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$provider = 'seopress';
		} else {
			$provider = 'fallback';
		}

		return apply_filters( 'convertrack_keywords_seo_provider', $provider );
	}

	/**
	 * Resolve template variables in a stored value.
	 *
	 * @param string $value    Raw stored value.
	 * @param int    $post_id  Post id.
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function resolve( $value, $post_id, $provider ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$post = get_post( $post_id );

		if ( 'yoast' === $provider && function_exists( 'wpseo_replace_vars' ) && $post ) {
			$replaced = wpseo_replace_vars( $value, $post );
			if ( is_string( $replaced ) && '' !== $replaced ) {
				return trim( $replaced );
			}
		}

		if ( 'rank_math' === $provider && class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'replace_vars' ) && $post ) {
			$replaced = \RankMath\Helper::replace_vars( $value, $post );
			if ( is_string( $replaced ) && '' !== $replaced ) {
				return trim( $replaced );
			}
		}

		return self::generic_vars( $value, $post_id );
	}

	/**
	 * Generic %%var%% / %var% / #tag substitution, then strip leftovers.
	 *
	 * @param string $value   Template value.
	 * @param int    $post_id Post id.
	 * @return string
	 */
	private static function generic_vars( $value, $post_id ) {
		$post    = get_post( $post_id );
		$excerpt = $post && '' !== $post->post_excerpt ? (string) $post->post_excerpt : '';

		$map = array(
			'title'       => (string) get_the_title( $post_id ),
			'post_title'  => (string) get_the_title( $post_id ),
			'sitename'    => (string) get_bloginfo( 'name' ),
			'sitetitle'   => (string) get_bloginfo( 'name' ),
			'site_title'  => (string) get_bloginfo( 'name' ),
			'sitedesc'    => (string) get_bloginfo( 'description' ),
			'tagline'     => (string) get_bloginfo( 'description' ),
			'sep'         => '-',
			'separator'   => '-',
			'separator_sa' => '-',
			'excerpt'     => $excerpt,
			'post_excerpt' => $excerpt,
			'currentyear' => date( 'Y' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			'current_year' => date( 'Y' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		);

		foreach ( $map as $key => $replacement ) {
			$value = str_ireplace( array( '%%' . $key . '%%', '%' . $key . '%', '#' . $key ), $replacement, $value );
		}

		// Drop unresolved tokens instead of leaking template syntax into matching.
		$value = preg_replace( '/%%?[a-z0-9_]+%%?/i', ' ', $value );
		$value = preg_replace( '/#[a-z0-9_]+/i', ' ', (string) $value );

		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Read the AIOSEO row for a post defensively (own table, schema may vary).
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private static function aioseo_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';

		if ( null === self::$aioseo_table ) {
			$exists = strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) === strtolower( $table );
			if ( $exists ) {
				$columns = $wpdb->get_col( "DESCRIBE `$table`", 0 ); // phpcs:ignore WordPress.DB
				$columns = is_array( $columns ) ? array_map( 'strtolower', $columns ) : array();
				$exists  = in_array( 'post_id', $columns, true ) && in_array( 'title', $columns, true ) && in_array( 'description', $columns, true );
			}
			self::$aioseo_table = $exists;
		}

		if ( ! self::$aioseo_table ) {
			return array();
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT title, description FROM `$table` WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : array();
	}
}
