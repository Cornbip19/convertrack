<?php
/**
 * Valid URL candidate refresh for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

use Convertrack\Owner_Lock;
use Convertrack\Safe_Sitemap_Fetcher;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-safe-sitemap-fetcher.php';
require_once dirname( __DIR__ ) . '/class-owner-lock.php';

class Sitemap_Source {

	const MAX_SITEMAPS = 100;
	const MAX_DEPTH    = 3;
	const MAX_URLS     = 5000;
	const STATE_OPTION = 'convertrack_404_sitemap_scan_state';
	const LOCK_OPTION  = 'convertrack_404_sitemap_scan_lock';

	/**
	 * Refresh valid URL cache.
	 *
	 * @return array|\WP_Error
	 */
	public static function refresh() {
		$owner = Owner_Lock::acquire( self::LOCK_OPTION, 300 );
		if ( false === $owner ) {
			return new \WP_Error( 'convertrack_404_sitemap_busy', __( 'A 404 sitemap scan is already running.', 'convertrack-click-conversion-analytics' ), array( 'status' => 409 ) );
		}

		try {
			$state = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $state ) || empty( $state['status'] ) || ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
				$state = Safe_Sitemap_Fetcher::start(
					self::default_sitemaps(),
					array(
						'context'                => '404-monitor',
						'max_sitemaps'           => self::MAX_SITEMAPS,
						'max_depth'              => self::MAX_DEPTH,
						'max_urls'               => self::MAX_URLS,
						'requests_per_step'      => 3,
						'request_timeout'        => 8,
						'step_seconds'           => 10,
						'total_seconds'          => 180,
						'max_redirects'          => 3,
						'max_compressed_bytes'   => 2 * MB_IN_BYTES,
						'max_decompressed_bytes' => 8 * MB_IN_BYTES,
						'user_agent'             => 'Convertrack/' . CONVERTRACK_VERSION . ' 404-monitor',
					)
				);
				if ( is_wp_error( $state ) ) {
					return $state;
				}

				$state['convertrack_started_at'] = current_time( 'mysql' );
				$state['convertrack_stored']     = self::refresh_wordpress_objects( $owner );
				$state['convertrack_write_errors'] = 0;
				update_option( self::STATE_OPTION, $state, false );
			}
			$result = self::run_scan_step( $state );
		} finally {
			Owner_Lock::release( self::LOCK_OPTION, $owner );
		}

		if ( ! is_wp_error( $result ) && ! empty( $result['pending'] ) ) {
			Cron::kick_refresh_step( 30 );
		}
		return $result;
	}

	/**
	 * Continue a persisted, bounded sitemap scan.
	 *
	 * @return array|\WP_Error
	 */
	public static function continue_refresh() {
		$owner = Owner_Lock::acquire( self::LOCK_OPTION, 300 );
		if ( false === $owner ) {
			return array( 'stored' => 0, 'errors' => 0, 'total' => Database::valid_url_count(), 'pending' => true, 'busy' => true );
		}
		try {
			$state = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $state ) || empty( $state['status'] ) ) {
				return new \WP_Error( 'convertrack_404_sitemap_state_missing', __( 'No resumable 404 sitemap scan was found.', 'convertrack-click-conversion-analytics' ) );
			}
			return self::run_scan_step( $state );
		} finally {
			Owner_Lock::release( self::LOCK_OPTION, $owner );
		}
	}

	/**
	 * Execute and persist one safe fetcher step while the owner lock is held.
	 *
	 * @param array $state Scan state.
	 * @return array|\WP_Error
	 */
	private static function run_scan_step( array $state ) {
		$step = Safe_Sitemap_Fetcher::step( $state );
		if ( is_wp_error( $step ) ) {
			return $step;
		}
		$state = $step['state'];
		foreach ( (array) $step['url_batch'] as $entry ) {
			$url = isset( $entry['url'] ) ? $entry['url'] : '';
			if ( ! self::is_site_url( $url ) ) {
				continue;
			}
			$stored = Database::upsert_valid_url( $url, array( 'source' => 'sitemap', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 80 ) );
			if ( is_wp_error( $stored ) ) {
				$state['convertrack_write_errors'] = isset( $state['convertrack_write_errors'] ) ? (int) $state['convertrack_write_errors'] + 1 : 1;
				continue;
			}
			$state['convertrack_stored'] = isset( $state['convertrack_stored'] ) ? (int) $state['convertrack_stored'] + 1 : 1;
		}

		$terminal = in_array( $state['status'], array( 'completed', 'partial', 'failed' ), true );
		if ( $terminal && 'completed' === $state['status'] && empty( $state['convertrack_write_errors'] ) ) {
			$stale = Database::mark_valid_urls_stale( $state['convertrack_started_at'] );
			if ( is_wp_error( $stale ) ) {
				$state['convertrack_write_errors'] = 1;
				$state['partial'] = true;
				$state['status'] = 'partial';
			}
		}
		if ( $terminal ) {
			update_option( 'convertrack_404_last_sitemap_refresh', current_time( 'mysql' ), false );
		}
		update_option( self::STATE_OPTION, $state, false );

		$errors = count( isset( $state['errors'] ) ? (array) $state['errors'] : array() ) + ( isset( $state['convertrack_write_errors'] ) ? (int) $state['convertrack_write_errors'] : 0 );
		$result = array(
			'stored'  => isset( $state['convertrack_stored'] ) ? (int) $state['convertrack_stored'] : 0,
			'errors'  => $errors,
			'total'   => Database::valid_url_count(),
			'pending' => ! $terminal,
			'status'  => $state['status'],
			'partial' => ! empty( $state['partial'] ),
		);
		if ( $terminal ) {
			Logger::info( 'sitemap', '404 Monitor valid URL cache refresh reached a terminal state.', $result );
		}
		return $result;
	}

	/**
	 * Default sitemap URLs.
	 *
	 * @return array
	 */
	private static function default_sitemaps() {
		$urls = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
		);
		foreach ( Settings::lines_to_array( Settings::get( 'sitemap_urls' ) ) as $url ) {
			$urls[] = $url;
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/**
	 * Refresh candidates from WordPress content, archives and terms.
	 *
	 * @param string $owner Sitemap scan lock owner.
	 * @return int Stored candidates.
	 */
	private static function refresh_wordpress_objects( $owner = '' ) {
		$stored    = 0;
		$excluded  = (array) Settings::get( 'exclude_post_types', array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type => $object ) {
			if ( 'attachment' === $post_type || in_array( $post_type, $excluded, true ) ) {
				continue;
			}
			if ( ! empty( $object->has_archive ) ) {
				$archive = get_post_type_archive_link( $post_type );
				if ( $archive && self::store_candidate( $archive, array( 'post_type' => $post_type, 'source' => 'post_type_archive', 'tokens' => Matcher::tokens_string( $archive ), 'priority' => 60 ) ) ) {
					$stored++;
				}
			}

			$page = 1;
			do {
				$query = new \WP_Query(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'posts_per_page'         => 500,
						'paged'                  => $page,
						'fields'                 => 'ids',
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $query->posts as $post_id ) {
					$url = get_permalink( $post_id );
					if ( ! $url ) {
						continue;
					}
					if ( self::store_candidate( $url, array( 'post_id' => (int) $post_id, 'post_type' => $post_type, 'source' => 'post', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 100 ) ) ) {
						$stored++;
					}
				}
				if ( '' !== $owner ) {
					Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, 300 );
				}
				$page++;
			} while ( ! empty( $query->posts ) && count( $query->posts ) >= 500 );
		}

		$excluded_tax = (array) Settings::get( 'exclude_taxonomies', array() );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy => $object ) {
			if ( in_array( $taxonomy, $excluded_tax, true ) ) {
				continue;
			}
			$offset = 0;
			do {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 500,
						'offset'     => $offset,
						'orderby'    => 'term_id',
						'order'      => 'ASC',
					)
				);
				if ( is_wp_error( $terms ) ) {
					break;
				}
				foreach ( $terms as $term ) {
					$url = get_term_link( $term );
					if ( is_wp_error( $url ) ) {
						continue;
					}
					if ( self::store_candidate( $url, array( 'taxonomy' => $taxonomy, 'term_id' => (int) $term->term_id, 'source' => 'taxonomy_archive', 'tokens' => Matcher::tokens_string( $url ), 'priority' => 55 ) ) ) {
						$stored++;
					}
				}
				if ( '' !== $owner ) {
					Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, 300 );
				}
				$offset += count( $terms );
			} while ( count( $terms ) >= 500 );
		}

		return $stored;
	}

	/**
	 * Persist one candidate and surface write failures without counting them.
	 *
	 * @param string $url  Candidate URL.
	 * @param array  $args Candidate fields.
	 * @return bool
	 */
	private static function store_candidate( $url, array $args ) {
		$result = Database::upsert_valid_url( $url, $args );
		if ( is_wp_error( $result ) ) {
			Logger::error( 'sitemap', 'Valid URL candidate write failed.', array( 'url' => $url, 'error' => $result->get_error_message() ) );
			return false;
		}
		return (bool) $result;
	}

	/**
	 * Same-site URL check.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		return ! empty( Database::normalize_source( $url ) );
	}
}
