<?php
/**
 * Resumable sitemap scanner for the Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-owner-lock.php';
require_once dirname( __DIR__ ) . '/class-safe-sitemap-fetcher.php';

class Sitemap_Scanner {

	const STATE_OPTION = 'convertrack_gsc_sitemap_scan_state';
	const LOCK_OPTION  = 'convertrack_gsc_sitemap_scan_lock';
	const LOCK_TIMEOUT = 120;
	const POST_BATCH   = 250;

	/**
	 * Queue a new scan without doing network work in the admin request.
	 *
	 * @param string $trigger manual|scheduled|full_audit.
	 * @return array|\WP_Error Public scan state.
	 */
	public static function request_scan( $trigger = 'manual' ) {
		if ( ! Settings::get( 'enabled' ) ) {
			return new \WP_Error( 'convertrack_gsc_disabled', __( 'Google Index Monitor is disabled.', 'convertrack-click-conversion-analytics' ) );
		}

		$root = (string) Settings::get( 'sitemap_url' );
		if ( '' === $root ) {
			return new \WP_Error( 'convertrack_gsc_no_sitemap', __( 'Sitemap URL is missing.', 'convertrack-click-conversion-analytics' ) );
		}

		$current = self::raw_state();
		if ( in_array( $current['status'], array( 'queued', 'running' ), true ) && ! self::is_stale( $current ) ) {
			return self::public_state( $current );
		}

		$fetch = \Convertrack\Safe_Sitemap_Fetcher::start(
			array( $root ),
			array(
				'context'                => 'gsc',
				'max_sitemaps'           => 200,
				'max_depth'              => 4,
				'max_urls'               => max( 1000, min( 100000, (int) apply_filters( 'convertrack_gsc_sitemap_max_urls', 50000 ) ) ),
				'requests_per_step'      => 5,
				'request_timeout'        => 8,
				'step_seconds'           => 12,
				'total_seconds'          => 240,
				'max_compressed_bytes'   => 2 * MB_IN_BYTES,
				'max_decompressed_bytes' => 8 * MB_IN_BYTES,
				'user_agent'             => 'Convertrack/' . CONVERTRACK_VERSION . ' gsc-sitemap',
			)
		);
		if ( is_wp_error( $fetch ) ) {
			return $fetch;
		}

		$state = array(
			'status'          => 'queued',
			'phase'           => 'sitemaps',
			'trigger'         => in_array( $trigger, array( 'scheduled', 'full_audit' ), true ) ? $trigger : 'manual',
			'generation'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'gsc-', true ),
			'fetch'           => $fetch,
			'post_cursor'     => 0,
			'sitemap_urls'    => 0,
			'sitemap_stored'  => 0,
			'post_urls'       => 0,
			'write_errors'    => 0,
			'retired'         => 0,
			'queued_at'       => time(),
			'updated_at'      => time(),
			'finished_at'     => 0,
			'error'           => null,
		);

		if ( ! update_option( self::STATE_OPTION, $state, false ) ) {
			$stored = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $stored ) || $stored !== $state ) {
				return new \WP_Error( 'convertrack_gsc_scan_state_write_failed', __( 'The sitemap scan could not be queued.', 'convertrack-click-conversion-analytics' ) );
			}
		}

		return self::public_state( $state );
	}

	/**
	 * Execute one budgeted background step.
	 *
	 * @return array|\WP_Error
	 */
	public static function scan() {
		$state = self::raw_state();
		if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			$queued = self::request_scan( 'scheduled' );
			if ( is_wp_error( $queued ) ) {
				return $queued;
			}
			$state = self::raw_state();
		}

		$owner = \Convertrack\Owner_Lock::acquire( self::LOCK_OPTION, self::LOCK_TIMEOUT );
		if ( false === $owner ) {
			$out         = self::public_state( $state );
			$out['busy'] = true;
			return $out;
		}

		try {
			$state['status']     = 'running';
			$state['updated_at'] = time();

			if ( 'sitemaps' === $state['phase'] ) {
				$step = \Convertrack\Safe_Sitemap_Fetcher::step( $state['fetch'] );
				if ( is_wp_error( $step ) ) {
					return self::fail( $state, $step );
				}
				$state['fetch']        = $step['state'];
				$state['sitemap_urls'] = (int) $state['fetch']['urls_seen'];
				$writes                = self::persist_url_batch( $step['url_batch'], $state['generation'] );
				$state['sitemap_stored'] += $writes['stored'];
				$state['write_errors']   += $writes['errors'];

				if ( in_array( $state['fetch']['status'], array( 'completed', 'partial', 'failed' ), true ) ) {
					$state['phase'] = 'posts';
				}
			}

			if ( 'posts' === $state['phase'] ) {
				\Convertrack\Owner_Lock::heartbeat( self::LOCK_OPTION, $owner, self::LOCK_TIMEOUT );
				$post_step = self::queue_selected_posts_batch( (int) $state['post_cursor'], $state['generation'] );
				if ( is_wp_error( $post_step ) ) {
					return self::fail( $state, $post_step );
				}
				$state['post_cursor']  = $post_step['cursor'];
				$state['post_urls']   += $post_step['stored'];
				$state['write_errors'] += $post_step['errors'];

				if ( $post_step['done'] ) {
					return self::complete( $state );
				}
			}

			$state['updated_at'] = time();
			$saved = self::save_state( $state );
			if ( is_wp_error( $saved ) ) {
				return self::fail( $state, $saved );
			}
			return self::public_state( $state );
		} finally {
			\Convertrack\Owner_Lock::release( self::LOCK_OPTION, $owner );
		}
	}

	/**
	 * Current public state for REST/UI diagnostics.
	 *
	 * @return array
	 */
	public static function state() {
		return self::public_state( self::raw_state() );
	}

	/**
	 * Stream one sitemap URL batch into the queue.
	 *
	 * @param array  $batch      URL entries.
	 * @param string $generation Scan generation.
	 * @return array {stored,errors}
	 */
	private static function persist_url_batch( array $batch, $generation ) {
		$stored = 0;
		$errors = 0;
		$seen   = current_time( 'mysql' );

		foreach ( $batch as $entry ) {
			$url = isset( $entry['url'] ) ? Database::normalize_url( $entry['url'] ) : '';
			if ( '' === $url || ! self::is_site_url( $url ) ) {
				continue;
			}
			$match  = self::match_post( $url );
			$result = Database::upsert_url(
				$url,
				array(
					'post_id'         => $match['post_id'],
					'post_type'       => $match['post_type'],
					'sitemap_url'     => isset( $entry['sitemap_url'] ) ? $entry['sitemap_url'] : '',
					'in_sitemap'      => 1,
					'index_status'    => 'pending_from_sitemap',
					'preserve_status' => 1,
					'scan_generation' => $generation,
					'last_seen_at'    => $seen,
				)
			);
			if ( is_wp_error( $result ) || ! $result ) {
				$errors++;
			} else {
				$stored++;
			}
		}

		return array( 'stored' => $stored, 'errors' => $errors );
	}

	/**
	 * Keyset-paginated selected-post pass.
	 *
	 * This pass deliberately omits sitemap_url, sitemap_hash, and in_sitemap so
	 * patch-only upserts preserve sitemap membership discovered in phase one.
	 *
	 * @param int    $cursor     Last post ID.
	 * @param string $generation Scan generation.
	 * @return array|\WP_Error
	 */
	private static function queue_selected_posts_batch( $cursor, $generation ) {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) Settings::get( 'selected_post_types', array() ) ) ) );
		if ( empty( $post_types ) ) {
			return array( 'stored' => 0, 'errors' => 0, 'cursor' => $cursor, 'done' => true );
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( array( max( 0, (int) $cursor ) ), $post_types, array( self::POST_BATCH ) );
		$sql          = "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($placeholders) ORDER BY ID ASC LIMIT %d";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $rows && ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_gsc_posts_query_failed', $wpdb->last_error );
		}

		$stored = 0;
		$errors = 0;
		$seen   = current_time( 'mysql' );
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['ID'];
			$cursor  = max( $cursor, $post_id );
			$url     = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}
			$result = Database::upsert_url(
				$url,
				array(
					'post_id'         => $post_id,
					'post_type'       => $row['post_type'],
					'index_status'    => 'queued',
					'preserve_status' => 1,
					'scan_generation' => $generation,
					'last_seen_at'    => $seen,
				)
			);
			if ( is_wp_error( $result ) || ! $result ) {
				$errors++;
			} else {
				$stored++;
			}
		}

		return array(
			'stored' => $stored,
			'errors' => $errors,
			'cursor' => $cursor,
			'done'   => count( (array) $rows ) < self::POST_BATCH,
		);
	}

	/**
	 * Complete and reconcile a scan.
	 *
	 * Unseen rows are retired only after a fully successful sitemap traversal
	 * and a selected-post pass with no write failures. Partial scans retain the
	 * prior valid generation intact.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function complete( array $state ) {
		$fetch_ok = isset( $state['fetch']['status'] ) && 'completed' === $state['fetch']['status'];
		if ( $fetch_ok && 0 === (int) $state['write_errors'] ) {
			$retired = Database::retire_unseen( $state['generation'] );
			if ( is_wp_error( $retired ) ) {
				$state['write_errors']++;
				$state['error'] = array( 'code' => $retired->get_error_code(), 'message' => $retired->get_error_message() );
			} else {
				$state['retired'] = (int) $retired;
			}
		}

		$state['phase']       = 'done';
		$state['finished_at'] = time();
		$state['updated_at']  = time();
		$state['status']      = $fetch_ok && 0 === (int) $state['write_errors'] ? 'completed' : 'partial';
		if ( 'full_audit' === $state['trigger'] && 'completed' === $state['status'] ) {
			$audit = Database::schedule_full_audit();
			if ( is_wp_error( $audit ) ) {
				$state['status'] = 'partial';
				$state['error']  = array( 'code' => $audit->get_error_code(), 'message' => $audit->get_error_message() );
			}
		}
		$snapshot = Database::record_snapshot();
		if ( is_wp_error( $snapshot ) ) {
			$state['status'] = 'partial';
			$state['error']  = array( 'code' => $snapshot->get_error_code(), 'message' => $snapshot->get_error_message() );
		}
		$cleanup = Database::cleanup();
		if ( is_wp_error( $cleanup ) ) {
			$state['status'] = 'partial';
			$state['error']  = array( 'code' => $cleanup->get_error_code(), 'message' => $cleanup->get_error_message() );
		}
		$saved = self::save_state( $state );
		if ( is_wp_error( $saved ) ) {
			Logger::error( 'sitemap', 'Sitemap scan state could not be finalized.', array( 'error' => $saved->get_error_message() ) );
			return $saved;
		}
		Logger::info(
			'sitemap',
			'completed' === $state['status'] ? 'Sitemap scan completed.' : 'Sitemap scan completed with partial results; prior unseen records were preserved.',
			array(
				'generation'   => $state['generation'],
				'sitemap_urls' => (int) $state['sitemap_urls'],
				'stored'       => (int) $state['sitemap_stored'],
				'post_urls'    => (int) $state['post_urls'],
				'write_errors' => (int) $state['write_errors'],
				'retired'      => (int) $state['retired'],
			)
		);

		return self::public_state( $state );
	}

	/**
	 * Persist terminal failure.
	 *
	 * @param array     $state State.
	 * @param \WP_Error $error Error.
	 * @return \WP_Error
	 */
	private static function fail( array $state, $error ) {
		$state['status']      = 'failed';
		$state['phase']       = 'done';
		$state['finished_at'] = time();
		$state['updated_at']  = time();
		$state['error']       = array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() );
		self::save_state( $state );
		Logger::error( 'sitemap', 'Sitemap scan failed.', array( 'error' => $error->get_error_message() ) );
		return $error;
	}

	/**
	 * Match a URL to a selected WordPress post.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private static function match_post( $url ) {
		$post_id   = url_to_postid( $url );
		$post_type = $post_id ? get_post_type( $post_id ) : '';
		if ( $post_id && ! in_array( $post_type, Settings::get( 'selected_post_types', array() ), true ) ) {
			$post_id   = 0;
			$post_type = '';
		}
		return array( 'post_id' => (int) $post_id, 'post_type' => $post_type ? sanitize_key( $post_type ) : '' );
	}

	/**
	 * Same-site page URL check (sitemap files themselves may use another explicitly allowed origin).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		if ( empty( $home['host'] ) || empty( $test['host'] ) ) {
			return false;
		}
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( isset( $home['scheme'] ) && 'https' === strtolower( $home['scheme'] ) ? 443 : 80 );
		$test_port = isset( $test['port'] ) ? (int) $test['port'] : ( isset( $test['scheme'] ) && 'https' === strtolower( $test['scheme'] ) ? 443 : 80 );
		return strtolower( rtrim( $home['host'], '.' ) ) === strtolower( rtrim( $test['host'], '.' ) ) && $home_port === $test_port;
	}

	/**
	 * Saved state with stable defaults.
	 *
	 * @return array
	 */
	private static function raw_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status' => 'idle', 'phase' => '', 'trigger' => '', 'generation' => '', 'fetch' => array(),
				'post_cursor' => 0, 'sitemap_urls' => 0, 'sitemap_stored' => 0, 'post_urls' => 0,
				'write_errors' => 0, 'retired' => 0, 'queued_at' => 0, 'updated_at' => 0,
				'finished_at' => 0, 'error' => null,
			)
		);
	}

	/**
	 * Save state and make write failure observable.
	 *
	 * @param array $state State.
	 * @return true|\WP_Error
	 */
	private static function save_state( array $state ) {
		$result = update_option( self::STATE_OPTION, $state, false );
		if ( ! $result && get_option( self::STATE_OPTION ) !== $state ) {
			return new \WP_Error( 'convertrack_gsc_scan_state_write_failed', __( 'The sitemap scan state could not be saved.', 'convertrack-click-conversion-analytics' ) );
		}
		return true;
	}

	/**
	 * Compact state returned through existing REST contracts.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function public_state( array $state ) {
		return array(
			'status'        => (string) $state['status'],
			'phase'         => (string) $state['phase'],
			'generation'    => (string) $state['generation'],
			'sitemap_urls'  => (int) $state['sitemap_urls'],
			'stored'         => (int) $state['sitemap_stored'],
			'post_urls'      => (int) $state['post_urls'],
			'write_errors'   => (int) $state['write_errors'],
			'retired'        => (int) $state['retired'],
			'queued_at'      => (int) $state['queued_at'],
			'updated_at'     => (int) $state['updated_at'],
			'finished_at'    => (int) $state['finished_at'],
			'error'          => $state['error'],
			'partial'        => isset( $state['fetch']['status'] ) && in_array( $state['fetch']['status'], array( 'partial', 'failed' ), true ),
			'fetch_errors'   => isset( $state['fetch']['errors'] ) ? count( (array) $state['fetch']['errors'] ) : 0,
		);
	}

	/**
	 * Treat an abandoned queued/running state as replaceable.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function is_stale( array $state ) {
		$updated = isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0;
		return ! $updated || ( time() - $updated ) > HOUR_IN_SECONDS;
	}
}
