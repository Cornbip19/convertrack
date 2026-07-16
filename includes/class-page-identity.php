<?php
/**
 * Canonical page identity and URL normalization.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

/**
 * Builds stable identities without treating term/user IDs as post IDs.
 */
class Page_Identity {

	/**
	 * Resolve the current public WordPress query to a canonical identity.
	 *
	 * @return array{page_key:string,object_type:string,object_id:int,post_id:int,path:string,title:string}
	 */
	public static function current() {
		$path        = self::current_path();
		$object_type = 'url';
		$object_id   = 0;
		$post_id     = 0;
		$title       = '';

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$post     = $post_id > 0 ? get_post( $post_id ) : null;
			if ( $post && 'publish' === $post->post_status ) {
				$object_type = 'post:' . sanitize_key( $post->post_type );
				$object_id   = $post_id;
				$title       = get_the_title( $post_id );
			}
		} elseif ( function_exists( 'is_category' ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$object_type = 'term:' . sanitize_key( $term->taxonomy );
				$object_id   = (int) $term->term_id;
				$title       = (string) $term->name;
			}
		} elseif ( function_exists( 'is_author' ) && is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$object_type = 'author';
				$object_id   = (int) $user->ID;
				$title       = (string) $user->display_name;
			}
		} elseif ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
			$post_type   = get_query_var( 'post_type' );
			$post_type   = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			$object_type = 'archive:' . sanitize_key( (string) $post_type );
			$title       = post_type_archive_title( '', false );
		} elseif ( function_exists( 'is_search' ) && is_search() ) {
			// Keep searches distinct without persisting the visitor's search text.
			$object_type = 'search:' . substr( hash_hmac( 'sha256', (string) get_search_query( false ), wp_salt( 'auth' ) ), 0, 20 );
			$title       = __( 'Site search', 'convertrack-click-conversion-analytics' );
		} elseif ( function_exists( 'is_404' ) && is_404() ) {
			$object_type = '404';
			$title       = __( 'Not found', 'convertrack-click-conversion-analytics' );
		} elseif ( function_exists( 'is_front_page' ) && is_front_page() ) {
			$object_type = 'front';
			$title       = get_bloginfo( 'name' );
		} elseif ( function_exists( 'is_home' ) && is_home() ) {
			$object_type = 'posts-home';
			$title       = __( 'Posts', 'convertrack-click-conversion-analytics' );
		} elseif ( function_exists( 'is_archive' ) && is_archive() ) {
			$object_type = 'archive';
			$title       = get_the_archive_title();
		}

		return self::build( $path, $object_type, $object_id, $post_id, $title );
	}

	/**
	 * Resolve a legacy/client payload against server-owned public content.
	 *
	 * A client post ID is accepted only when its public permalink path matches
	 * the submitted path. Everything else is represented as a URL identity.
	 *
	 * @param string $url     Submitted page URL/path.
	 * @param int    $post_id Submitted legacy post ID.
	 * @return array
	 */
	public static function from_payload( $url, $post_id ) {
		$path        = self::normalize_path( $url );
		$post_id     = absint( $post_id );
		$object_type = 'url';
		$object_id   = 0;
		$title       = '';

		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
			$post      = get_post( $post_id );
			$permalink = get_permalink( $post_id );
			$post_path = $permalink ? self::normalize_path( $permalink ) : '';
			if ( $post && '' !== $post_path && $post_path === $path ) {
				$object_type = 'post:' . sanitize_key( $post->post_type );
				$object_id   = $post_id;
				$title       = get_the_title( $post_id );
			} else {
				$post_id = 0;
			}
		}

		return self::build( $path, $object_type, $object_id, $post_id, $title );
	}

	/**
	 * Normalize an absolute or relative URL to a query-free site path.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function normalize_path( $url ) {
		$url  = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$path = preg_replace( '#/+#', '/', '/' . ltrim( $path, '/' ) );
		$path = '/' . ltrim( self::remove_dot_segments( $path ), '/' );
		if ( strlen( $path ) > 1024 ) {
			$path = substr( $path, 0, 1024 );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Return the current request path without its query string.
	 *
	 * @return string
	 */
	private static function current_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = self::normalize_path( $request_uri );
		// The page key is persisted alongside the scrubbed page URL, so it must
		// apply the same credential/email path redaction before being signed.
		if ( class_exists( __NAMESPACE__ . '\\Collector' ) ) {
			$path = self::normalize_path( Collector::sanitize_url_path( $path ) );
		}
		return $path;
	}

	/**
	 * Build and bound the stored identity.
	 *
	 * @param string $path        Canonical path.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $post_id     Compatible singular post ID.
	 * @param string $title       Server-derived public title.
	 * @return array
	 */
	private static function build( $path, $object_type, $object_id, $post_id, $title ) {
		$path        = self::normalize_path( $path );
		$object_type = substr( sanitize_key( str_replace( ':', '-', (string) $object_type ) ), 0, 40 );
		$identity    = $object_type . ':' . absint( $object_id ) . ':' . $path;
		$page_key    = strlen( $identity ) <= 191 ? $identity : $object_type . ':' . absint( $object_id ) . ':sha256-' . hash( 'sha256', $path );

		return array(
			'page_key'   => $page_key,
			'object_type'=> $object_type,
			'object_id'  => absint( $object_id ),
			'post_id'    => absint( $post_id ),
			'path'       => $path,
			'title'      => sanitize_text_field( (string) $title ),
		);
	}

	/**
	 * Collapse RFC 3986 dot segments without decoding encoded path content.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function remove_dot_segments( $path ) {
		$out = array();
		foreach ( explode( '/', (string) $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}
		return '/' . implode( '/', $out );
	}
}
