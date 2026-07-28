<?php
/**
 * Suggested fixes for index coverage problems.
 *
 * Same contract as the Broken URLs workflow: the plugin proposes, an
 * administrator approves. Nothing here runs on its own -- suggest() only ever
 * writes a proposal, and apply() is reachable only from an explicit user action.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Index_Fixes {

	const CREATE_REDIRECT  = 'create_redirect';
	const REMOVE_NOINDEX   = 'remove_noindex';
	const ROBOTS_ALLOW     = 'robots_allow';
	const ALIGN_CANONICAL  = 'align_canonical';
	const FLATTEN_CHAIN    = 'flatten_chain';
	const REQUEST_INDEXING = 'request_indexing';

	const STATE_NONE        = 'none';
	const STATE_AVAILABLE   = 'available';
	const STATE_UNAVAILABLE = 'unavailable';
	const STATE_APPLIED     = 'applied';
	const STATE_VERIFYING   = 'verifying';
	const STATE_PASSED      = 'passed';
	const STATE_FAILED      = 'failed';

	/**
	 * Re-inspections with the reason unchanged before a fix is called failed.
	 *
	 * Google can take days to recrawl, so this must be a count of confirmed
	 * re-inspections, never elapsed time.
	 */
	const MAX_VALIDATION_ATTEMPTS = 3;

	/**
	 * Build a fix proposal for one queue row.
	 *
	 * @param array $row Decorated or raw queue row.
	 * @return array {fix_code, fix_state, payload}
	 */
	public static function suggest( array $row ) {
		$reason = isset( $row['index_reason'] ) && '' !== $row['index_reason']
			? (string) $row['index_reason']
			: Index_Reasons::classify( $row );

		switch ( $reason ) {
			case Index_Reasons::NOT_FOUND_404:
				return self::suggest_redirect( $row );
			case Index_Reasons::NOINDEX_TAG:
				return self::suggest_remove_noindex( $row );
			case Index_Reasons::BLOCKED_ROBOTS_TXT:
				return self::suggest_robots_allow( $row );
			case Index_Reasons::DUPLICATE_GOOGLE_CANONICAL:
				return self::suggest_align_canonical( $row );
			case Index_Reasons::PAGE_WITH_REDIRECT:
				return self::suggest_flatten_chain( $row );
			case Index_Reasons::CRAWLED_NOT_INDEXED:
			case Index_Reasons::DISCOVERED_NOT_INDEXED:
				return self::suggest_request_indexing( $row );
		}

		// Everything else is explained by its reason descriptor but has no safe
		// automated action -- a 403, a 5xx or a soft 404 needs a human looking at
		// the server or the content, and alternate_canonical needs nothing at all.
		return self::none();
	}

	/**
	 * Apply a previously suggested fix.
	 *
	 * @param array $row Raw queue row including fix_code and fix_payload.
	 * @return array|\WP_Error {message, payload} on success.
	 */
	public static function apply( array $row ) {
		$code    = isset( $row['fix_code'] ) ? (string) $row['fix_code'] : '';
		$payload = self::payload( $row );

		if ( '' === $code ) {
			return new \WP_Error( 'convertrack_gsc_no_fix', __( 'There is no suggested fix for this URL.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}
		$state = isset( $row['fix_state'] ) ? (string) $row['fix_state'] : self::STATE_NONE;
		if ( self::STATE_UNAVAILABLE === $state ) {
			return new \WP_Error( 'convertrack_gsc_fix_unavailable', __( 'This fix cannot be applied automatically on this site.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		switch ( $code ) {
			case self::CREATE_REDIRECT:
				return self::apply_redirect( $row, $payload );
			case self::REMOVE_NOINDEX:
				return self::apply_remove_noindex( $row, $payload );
			case self::ROBOTS_ALLOW:
				return self::apply_robots_allow( $row, $payload );
			case self::ALIGN_CANONICAL:
				return self::apply_align_canonical( $row, $payload );
			case self::FLATTEN_CHAIN:
				return self::apply_flatten_chain( $row, $payload );
			case self::REQUEST_INDEXING:
				return self::apply_request_indexing( $row, $payload );
		}

		return new \WP_Error( 'convertrack_gsc_unknown_fix', __( 'Unknown fix type.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
	}

	/**
	 * Human label and description for a fix code.
	 *
	 * @param string $code Fix code.
	 * @return array
	 */
	public static function descriptor( $code ) {
		$all = array(
			self::CREATE_REDIRECT => array(
				'label'  => __( 'Create redirect', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Points this dead URL at the closest matching live page with a 301 redirect.', 'convertrack-click-conversion-analytics' ),
			),
			self::REMOVE_NOINDEX => array(
				'label'  => __( 'Remove noindex', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Clears the noindex flag that is keeping this page out of Google.', 'convertrack-click-conversion-analytics' ),
			),
			self::ROBOTS_ALLOW => array(
				'label'  => __( 'Add Allow rule', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Adds a robots.txt Allow rule so Google may request this URL again.', 'convertrack-click-conversion-analytics' ),
			),
			self::ALIGN_CANONICAL => array(
				'label'  => __( 'Use Google’s canonical', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Sets this page’s canonical to the URL Google already treats as canonical.', 'convertrack-click-conversion-analytics' ),
			),
			self::FLATTEN_CHAIN => array(
				'label'  => __( 'Flatten redirect', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Rewrites a multi-step redirect so it goes straight to the final destination.', 'convertrack-click-conversion-analytics' ),
			),
			self::REQUEST_INDEXING => array(
				'label'  => __( 'Request indexing', 'convertrack-click-conversion-analytics' ),
				'detail' => __( 'Asks Google to recrawl this URL. Google decides whether to index it.', 'convertrack-click-conversion-analytics' ),
			),
		);
		$code = (string) $code;
		return isset( $all[ $code ] ) ? $all[ $code ] : array( 'label' => '', 'detail' => '' );
	}

	/* ---------------------------------------------------------------------- */
	/* Suggestions                                                            */
	/* ---------------------------------------------------------------------- */

	/**
	 * 404 -> redirect, reusing the Broken URLs matcher wholesale.
	 *
	 * Deliberately the same engine, so a URL that appears in both features gets
	 * the same destination and the same confidence, tiers and all.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_redirect( array $row ) {
		if ( ! class_exists( '\\Convertrack\\NotFound\\Matcher' ) ) {
			return self::unavailable( self::CREATE_REDIRECT, __( 'The Broken URLs module is not available.', 'convertrack-click-conversion-analytics' ) );
		}

		$source = \Convertrack\NotFound\Database::normalize_source( (string) $row['url'] );
		if ( empty( $source ) ) {
			return self::unavailable( self::CREATE_REDIRECT, __( 'This URL is not on this site.', 'convertrack-click-conversion-analytics' ) );
		}

		// An existing rule already handles it; nothing to propose.
		if ( \Convertrack\NotFound\Database::find_active_redirect( $source['url'] ) ) {
			return self::none();
		}

		$match = \Convertrack\NotFound\Matcher::recommend( array( 'path' => $source['path'] ) );
		if ( empty( $match['url'] ) ) {
			return self::unavailable( self::CREATE_REDIRECT, __( 'No suitable destination was found for this URL.', 'convertrack-click-conversion-analytics' ) );
		}

		return self::available(
			self::CREATE_REDIRECT,
			array(
				'target'     => (string) $match['url'],
				'confidence' => (int) $match['confidence'],
				'match'      => (string) $match['reason'],
				'source'     => $source['url'],
			)
		);
	}

	/**
	 * noindex -> identify the single source and offer to clear just that.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_remove_noindex( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;

		// Site-wide setting. Never offered as a per-row click: one press would
		// change indexing for the entire site, which is not what a row-level
		// "fix this page" button implies.
		if ( ! get_option( 'blog_public' ) ) {
			return self::unavailable(
				self::REMOVE_NOINDEX,
				__( 'WordPress is set to discourage search engines, which blocks the whole site. Turn off "Discourage search engines from indexing this site" in Settings > Reading.', 'convertrack-click-conversion-analytics' ),
				array( 'scope' => 'site', 'setting' => 'blog_public' )
			);
		}

		if ( $post_id < 1 ) {
			return self::unavailable( self::REMOVE_NOINDEX, __( 'This URL is not a WordPress post or page, so the noindex is set somewhere else.', 'convertrack-click-conversion-analytics' ) );
		}

		$provider = Keywords_SEO_Meta::provider();
		$source    = self::noindex_source( $post_id, $provider );
		if ( '' === $source['key'] ) {
			return self::unavailable(
				self::REMOVE_NOINDEX,
				__( 'No per-page noindex setting was found, so it likely comes from a theme or another plugin.', 'convertrack-click-conversion-analytics' ),
				array( 'provider' => $provider )
			);
		}

		return self::available(
			self::REMOVE_NOINDEX,
			array(
				'provider' => $provider,
				'meta_key' => $source['key'],
				'post_id'  => $post_id,
			)
		);
	}

	/**
	 * robots.txt block -> propose an Allow rule.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_robots_allow( array $row ) {
		$path = self::path_of( (string) $row['url'] );
		if ( '' === $path ) {
			return self::none();
		}

		// WordPress only serves a virtual robots.txt when no real file exists. A
		// file on disk belongs to the site owner or their host, so the plugin
		// reports the exact line to add rather than writing to it.
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			return self::unavailable(
				self::ROBOTS_ALLOW,
				__( 'This site has a real robots.txt file, which the plugin will not modify. Add the rule below to it yourself.', 'convertrack-click-conversion-analytics' ),
				array( 'manual_line' => 'Allow: ' . $path, 'path' => $path, 'physical_file' => true )
			);
		}

		if ( in_array( $path, Settings::robots_allow_rules(), true ) ) {
			// Rule already present, so the block is coming from somewhere else.
			return self::unavailable(
				self::ROBOTS_ALLOW,
				__( 'An Allow rule for this path is already in place, so the block comes from elsewhere — check for a server-level robots.txt or a security plugin.', 'convertrack-click-conversion-analytics' ),
				array( 'path' => $path )
			);
		}

		return self::available( self::ROBOTS_ALLOW, array( 'path' => $path, 'rule' => 'Allow: ' . $path ) );
	}

	/**
	 * Canonical conflict -> adopt Google's choice.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_align_canonical( array $row ) {
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$google  = isset( $row['google_canonical'] ) ? (string) $row['google_canonical'] : '';

		if ( '' === $google ) {
			return self::none();
		}
		if ( $post_id < 1 ) {
			return self::unavailable( self::ALIGN_CANONICAL, __( 'This URL is not a WordPress post or page, so its canonical cannot be set here.', 'convertrack-click-conversion-analytics' ) );
		}

		$provider = Keywords_SEO_Meta::provider();
		$key      = self::canonical_meta_key( $provider );
		if ( '' === $key ) {
			return self::unavailable(
				self::ALIGN_CANONICAL,
				__( 'No supported SEO plugin was detected, so there is no canonical field to write. Set the canonical in your theme or SEO plugin.', 'convertrack-click-conversion-analytics' ),
				array( 'suggested_canonical' => $google )
			);
		}

		return self::available(
			self::ALIGN_CANONICAL,
			array(
				'provider'  => $provider,
				'meta_key'  => $key,
				'post_id'   => $post_id,
				'canonical' => $google,
				'previous'  => isset( $row['user_canonical'] ) ? (string) $row['user_canonical'] : '',
			)
		);
	}

	/**
	 * Redirecting URL -> flatten a chain we own.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_flatten_chain( array $row ) {
		if ( ! class_exists( '\\Convertrack\\NotFound\\Database' ) ) {
			return self::none();
		}

		$source = \Convertrack\NotFound\Database::normalize_source( (string) $row['url'] );
		if ( empty( $source ) ) {
			return self::none();
		}
		$redirect = \Convertrack\NotFound\Database::find_active_redirect( $source['url'] );
		if ( ! $redirect || empty( $redirect['destination_url'] ) ) {
			// The redirect is not one of ours -- it belongs to a plugin, the theme
			// or the server. Explain rather than pretend we can change it.
			return self::unavailable(
				self::FLATTEN_CHAIN,
				__( 'This URL redirects, but not through a rule this plugin owns. A redirecting URL cannot be indexed itself, which is normal — remove it from your sitemap if it should not be listed.', 'convertrack-click-conversion-analytics' )
			);
		}

		$final = self::resolve_chain( $redirect['destination_url'] );
		if ( '' === $final || $final === $redirect['destination_url'] ) {
			// Single hop already; nothing to shorten.
			return self::none();
		}

		return self::available(
			self::FLATTEN_CHAIN,
			array(
				'redirect_id' => (int) $redirect['id'],
				'from'        => (string) $redirect['destination_url'],
				'to'          => $final,
			)
		);
	}

	/**
	 * Crawled/discovered but not indexed -> ask Google to look again.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function suggest_request_indexing( array $row ) {
		if ( ! Settings::get( 'use_indexing_api' ) ) {
			return self::unavailable(
				self::REQUEST_INDEXING,
				__( 'Google decides what to index; there is no technical fault to fix here. Strengthen internal links to this page, or enable the Indexing API in settings to request a recrawl.', 'convertrack-click-conversion-analytics' )
			);
		}
		return self::available( self::REQUEST_INDEXING, array( 'post_id' => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0 ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Application                                                            */
	/* ---------------------------------------------------------------------- */

	/**
	 * Create the proposed redirect through the Broken URLs validator.
	 *
	 * Routing through Redirector::create_redirect() means loop detection, chain
	 * validation, HTTPS-downgrade rejection, same-site enforcement and the
	 * destination health check all apply unchanged.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_redirect( array $row, array $payload ) {
		if ( empty( $payload['target'] ) ) {
			return new \WP_Error( 'convertrack_gsc_fix_stale', __( 'This suggestion is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}
		$created = \Convertrack\NotFound\Redirector::create_redirect( (string) $row['url'], (string) $payload['target'], 0, false );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return array(
			/* translators: %s: destination URL. */
			'message' => sprintf( __( 'Redirect created to %s.', 'convertrack-click-conversion-analytics' ), (string) $payload['target'] ),
			'payload' => $payload,
		);
	}

	/**
	 * Clear exactly one per-post noindex flag.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_remove_noindex( array $row, array $payload ) {
		$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$key     = isset( $payload['meta_key'] ) ? (string) $payload['meta_key'] : '';
		if ( $post_id < 1 || '' === $key ) {
			return new \WP_Error( 'convertrack_gsc_fix_stale', __( 'This suggestion is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'convertrack_gsc_cannot_edit', __( 'You cannot edit this post.', 'convertrack-click-conversion-analytics' ), array( 'status' => 403 ) );
		}

		$provider = isset( $payload['provider'] ) ? (string) $payload['provider'] : '';
		$applied  = self::clear_noindex( $post_id, $provider, $key );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		Logger::info( 'fix', 'Removed a per-page noindex flag.', array( 'post_id' => $post_id, 'provider' => $provider, 'meta_key' => $key ) );
		return array(
			'message' => __( 'The noindex flag was removed from this page.', 'convertrack-click-conversion-analytics' ),
			'payload' => $payload,
		);
	}

	/**
	 * Store a robots.txt Allow rule for the virtual robots.txt.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_robots_allow( array $row, array $payload ) {
		$path = isset( $payload['path'] ) ? (string) $payload['path'] : '';
		if ( '' === $path ) {
			return new \WP_Error( 'convertrack_gsc_fix_stale', __( 'This suggestion is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}
		// Re-check at apply time: a robots.txt file could have appeared since the
		// suggestion was stored.
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			return new \WP_Error(
				'convertrack_gsc_physical_robots',
				__( 'This site now has a real robots.txt file, which the plugin will not modify.', 'convertrack-click-conversion-analytics' ),
				array( 'status' => 409 )
			);
		}

		$stored = Settings::add_robots_allow_rule( $path );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		Logger::info( 'fix', 'Added a robots.txt Allow rule.', array( 'path' => $path ) );
		return array(
			/* translators: %s: URL path. */
			'message' => sprintf( __( 'Allow rule added for %s.', 'convertrack-click-conversion-analytics' ), $path ),
			'payload' => $payload,
		);
	}

	/**
	 * Write Google's canonical into the SEO plugin's canonical field.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_align_canonical( array $row, array $payload ) {
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$key       = isset( $payload['meta_key'] ) ? (string) $payload['meta_key'] : '';
		$canonical = isset( $payload['canonical'] ) ? (string) $payload['canonical'] : '';
		if ( $post_id < 1 || '' === $key || '' === $canonical ) {
			return new \WP_Error( 'convertrack_gsc_fix_stale', __( 'This suggestion is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'convertrack_gsc_cannot_edit', __( 'You cannot edit this post.', 'convertrack-click-conversion-analytics' ), array( 'status' => 403 ) );
		}
		$clean = esc_url_raw( $canonical );
		if ( '' === $clean ) {
			return new \WP_Error( 'convertrack_gsc_bad_canonical', __( 'The canonical URL is not valid.', 'convertrack-click-conversion-analytics' ), array( 'status' => 400 ) );
		}

		update_post_meta( $post_id, $key, $clean );
		Logger::info( 'fix', 'Aligned a page canonical with Google’s choice.', array( 'post_id' => $post_id, 'canonical' => $clean ) );
		return array(
			/* translators: %s: canonical URL. */
			'message' => sprintf( __( 'Canonical set to %s.', 'convertrack-click-conversion-analytics' ), $clean ),
			'payload' => $payload,
		);
	}

	/**
	 * Point the first hop of a chain straight at its final destination.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_flatten_chain( array $row, array $payload ) {
		$to = isset( $payload['to'] ) ? (string) $payload['to'] : '';
		if ( '' === $to ) {
			return new \WP_Error( 'convertrack_gsc_fix_stale', __( 'This suggestion is out of date. Re-check the URL and try again.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		// upsert_redirect() replaces the destination for an existing source, and
		// validate_pair() first so a flattened chain cannot introduce a loop.
		$validated = \Convertrack\NotFound\Redirector::validate_pair( (string) $row['url'], $to, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$saved = \Convertrack\NotFound\Database::upsert_redirect( (string) $row['url'], $to, 0, 'active' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Logger::info( 'fix', 'Flattened a redirect chain.', array( 'url' => (string) $row['url'], 'to' => $to ) );
		return array(
			/* translators: %s: destination URL. */
			'message' => sprintf( __( 'Redirect now points straight to %s.', 'convertrack-click-conversion-analytics' ), $to ),
			'payload' => $payload,
		);
	}

	/**
	 * Ask Google to recrawl, via the existing Indexing API path.
	 *
	 * @param array $row     Queue row.
	 * @param array $payload Stored proposal.
	 * @return array|\WP_Error
	 */
	private static function apply_request_indexing( array $row, array $payload ) {
		$notified = API::indexing_api_notify( (string) $row['url'], isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0, true );
		if ( is_wp_error( $notified ) ) {
			return $notified;
		}
		return array(
			'message' => __( 'Google was asked to recrawl this URL. Indexing remains Google’s decision.', 'convertrack-click-conversion-analytics' ),
			'payload' => $payload,
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Validation                                                             */
	/* ---------------------------------------------------------------------- */

	/**
	 * Decide the new fix state after a re-inspection.
	 *
	 * Only ever advances on a confirmed re-inspection, never on elapsed time,
	 * because a recrawl can legitimately take days.
	 *
	 * @param string $state           Current fix state.
	 * @param string $reason_at_apply Reason recorded when the fix was applied.
	 * @param string $reason_now      Freshly classified reason.
	 * @param int    $attempts        Re-inspections since the fix was applied.
	 * @return array {state, attempts}
	 */
	public static function advance_validation( $state, $reason_at_apply, $reason_now, $attempts ) {
		$state    = (string) $state;
		$attempts = max( 0, (int) $attempts );

		if ( ! in_array( $state, array( self::STATE_APPLIED, self::STATE_VERIFYING ), true ) ) {
			return array( 'state' => $state, 'attempts' => $attempts );
		}

		$attempts++;

		// The reason we fixed is gone, or Google now passes the URL outright.
		if ( Index_Reasons::INDEXED === $reason_now || $reason_now !== $reason_at_apply ) {
			return array( 'state' => self::STATE_PASSED, 'attempts' => $attempts );
		}
		if ( $attempts >= self::MAX_VALIDATION_ATTEMPTS ) {
			return array( 'state' => self::STATE_FAILED, 'attempts' => $attempts );
		}
		return array( 'state' => self::STATE_VERIFYING, 'attempts' => $attempts );
	}

	/**
	 * UI label for a fix state, mirroring Search Console's vocabulary.
	 *
	 * @param string $state Fix state.
	 * @return string
	 */
	public static function validation_label( $state ) {
		switch ( (string) $state ) {
			case self::STATE_APPLIED:
			case self::STATE_VERIFYING:
				return 'started';
			case self::STATE_PASSED:
				return 'passed';
			case self::STATE_FAILED:
				return 'failed';
		}
		return 'not_started';
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * Where a post's noindex comes from, per SEO plugin.
	 *
	 * Read-only probe. Mirrors the provider meta keys each plugin writes.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $provider Provider slug.
	 * @return array {key, value}
	 */
	public static function noindex_source( $post_id, $provider ) {
		$post_id = (int) $post_id;

		switch ( $provider ) {
			case 'yoast':
				if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
					return array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1' );
				}
				break;
			case 'rank_math':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
					return array( 'key' => 'rank_math_robots', 'value' => $robots );
				}
				break;
			case 'seopress':
				if ( 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
					return array( 'key' => '_seopress_robots_index', 'value' => 'yes' );
				}
				break;
			case 'aioseo':
				// AIOSEO keeps robots settings in its own table rather than postmeta,
				// so it cannot be cleared with a meta write.
				return array( 'key' => '', 'value' => null );
		}

		return array( 'key' => '', 'value' => null );
	}

	/**
	 * Clear the one noindex flag identified by noindex_source().
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $provider Provider slug.
	 * @param string $key      Meta key.
	 * @return true|\WP_Error
	 */
	private static function clear_noindex( $post_id, $provider, $key ) {
		if ( 'rank_math' === $provider && 'rank_math_robots' === $key ) {
			// Rank Math stores a list; drop only 'noindex' and add 'index' so the
			// page has an explicit directive rather than an empty array.
			$robots = get_post_meta( $post_id, $key, true );
			$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'noindex' ) ) ) : array();
			if ( ! in_array( 'index', $robots, true ) ) {
				$robots[] = 'index';
			}
			update_post_meta( $post_id, $key, $robots );
			return true;
		}
		if ( 'seopress' === $provider && '_seopress_robots_index' === $key ) {
			// SEOPress treats 'yes' as "noindex this"; an empty value means index.
			update_post_meta( $post_id, $key, '' );
			return true;
		}
		if ( 'yoast' === $provider && '_yoast_wpseo_meta-robots-noindex' === $key ) {
			// '2' is Yoast's explicit "index" value; '0' means "use the default",
			// which may itself be noindex for this post type.
			update_post_meta( $post_id, $key, '2' );
			return true;
		}

		return new \WP_Error( 'convertrack_gsc_unsupported_noindex', __( 'This noindex setting cannot be changed automatically.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
	}

	/**
	 * Canonical postmeta key per provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function canonical_meta_key( $provider ) {
		switch ( $provider ) {
			case 'yoast':
				return '_yoast_wpseo_canonical';
			case 'rank_math':
				return 'rank_math_canonical_url';
			case 'seopress':
				return '_seopress_robots_canonical';
		}
		return '';
	}

	/**
	 * Walk our redirect graph to its final destination.
	 *
	 * Bounded so a cycle cannot spin; cycles are rejected at write time anyway.
	 *
	 * @param string $destination First destination.
	 * @return string Final destination, or '' when it cannot be resolved.
	 */
	private static function resolve_chain( $destination ) {
		$current = (string) $destination;
		$seen    = array();

		for ( $hop = 0; $hop < 10; $hop++ ) {
			$normalized = \Convertrack\NotFound\Database::normalize_source( $current );
			if ( empty( $normalized ) || isset( $seen[ $normalized['path'] ] ) ) {
				return $current;
			}
			$seen[ $normalized['path'] ] = true;
			$next = \Convertrack\NotFound\Database::find_active_redirect( $normalized['url'] );
			if ( ! $next || empty( $next['destination_url'] ) ) {
				return $current;
			}
			$current = (string) $next['destination_url'];
		}

		return $current;
	}

	/**
	 * Root-relative path of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		return '/' . ltrim( (string) $parts['path'], '/' );
	}

	/**
	 * Decode a stored proposal.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private static function payload( array $row ) {
		if ( empty( $row['fix_payload'] ) ) {
			return array();
		}
		if ( is_array( $row['fix_payload'] ) ) {
			return $row['fix_payload'];
		}
		$decoded = json_decode( (string) $row['fix_payload'], true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Shape an actionable proposal.
	 *
	 * @param string $code    Fix code.
	 * @param array  $payload Payload.
	 * @return array
	 */
	private static function available( $code, array $payload ) {
		return array( 'fix_code' => $code, 'fix_state' => self::STATE_AVAILABLE, 'payload' => $payload );
	}

	/**
	 * Shape a proposal that cannot be applied here, with the reason why.
	 *
	 * @param string $code    Fix code.
	 * @param string $note    Explanation shown to the operator.
	 * @param array  $payload Extra payload.
	 * @return array
	 */
	private static function unavailable( $code, $note, array $payload = array() ) {
		$payload['note'] = $note;
		return array( 'fix_code' => $code, 'fix_state' => self::STATE_UNAVAILABLE, 'payload' => $payload );
	}

	/**
	 * Shape "no fix applies".
	 *
	 * @return array
	 */
	private static function none() {
		return array( 'fix_code' => '', 'fix_state' => self::STATE_NONE, 'payload' => array() );
	}
}
