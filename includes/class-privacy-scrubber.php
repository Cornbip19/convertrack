<?php
/**
 * Resumable historical privacy remediation.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Privacy_Scrubber {

	const HOOK         = 'convertrack_privacy_scrub_batch';
	const STATE_OPTION = 'convertrack_privacy_scrub_state';
	const DEFAULT_BATCH = 500;

	/** Register cron and optional WP-CLI surfaces. */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled_batch' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'convertrack privacy-scrub', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Start a new scrub or return the already-running state.
	 *
	 * @param bool $dry_run Count changes without writing.
	 * @return array|\WP_Error
	 */
	public static function start( $dry_run = false ) {
		if ( ! Database::schema_is_healthy() ) {
			return new \WP_Error( 'convertrack_schema_unhealthy', 'Install the current Convertrack database schema before starting a privacy scrub.' );
		}
		$current = get_option( self::STATE_OPTION, array() );
		if ( is_array( $current ) && isset( $current['status'] ) && 'running' === $current['status'] ) {
			return new \WP_Error( 'convertrack_scrub_running', 'A privacy scrub is already running.', $current );
		}

		$state = array(
			'run_id'       => wp_generate_uuid4(),
			'status'       => 'running',
			'dry_run'      => (bool) $dry_run,
			'stage'        => 'events',
			'cursor'       => 0,
			'scanned'      => 0,
			'changed'      => 0,
			'merged_404'   => 0,
			'started_at'   => current_time( 'mysql', true ),
			'completed_at' => null,
			'last_error'   => '',
		);
		if ( ! update_option( self::STATE_OPTION, $state, false ) && get_option( self::STATE_OPTION ) !== $state ) {
			return new \WP_Error( 'convertrack_scrub_state_write', 'Could not save privacy scrub state.' );
		}
		self::schedule_next();
		return $state;
	}

	/** Run a bounded scheduled batch. */
	public static function run_scheduled_batch() {
		$result = self::process_batch( self::DEFAULT_BATCH );
		if ( is_wp_error( $result ) ) {
			return;
		}
		if ( isset( $result['status'] ) && 'running' === $result['status'] ) {
			self::schedule_next();
		}
	}

	/**
	 * Process one keyset-paginated batch.
	 *
	 * @param int $limit Batch size.
	 * @return array|\WP_Error
	 */
	public static function process_batch( $limit = self::DEFAULT_BATCH ) {
		$limit = max( 10, min( 2000, (int) $limit ) );
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			return new \WP_Error( 'convertrack_scrub_inactive', 'No privacy scrub is running.' );
		}

		if ( 'events' === $state['stage'] ) {
			$result = self::scrub_events( $state, $limit );
		} elseif ( 'sessions' === $state['stage'] ) {
			$result = self::scrub_sessions( $state, $limit );
		} elseif ( 'notfound' === $state['stage'] ) {
			$result = self::scrub_notfound( $state, $limit );
		} else {
			$state['status']       = 'complete';
			$state['completed_at'] = current_time( 'mysql', true );
			$result                = $state;
		}

		if ( is_wp_error( $result ) ) {
			$state['status']     = 'failed';
			$state['last_error'] = $result->get_error_message();
			update_option( self::STATE_OPTION, $state, false );
			return $result;
		}

		if ( ! update_option( self::STATE_OPTION, $result, false ) && get_option( self::STATE_OPTION ) !== $result ) {
			return new \WP_Error( 'convertrack_scrub_state_write', 'Could not save privacy scrub progress.' );
		}
		return $result;
	}

	/**
	 * Scrub analytics event URL/text fields.
	 *
	 * @param array $state State.
	 * @param int   $limit Batch size.
	 * @return array|\WP_Error
	 */
	private static function scrub_events( array $state, $limit ) {
		global $wpdb;
		$table = Database::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id,page_url,page_title,element_tag,element_selector,heatmap_selector,element_text,element_href,post_id,page_key,object_type,object_id FROM $table WHERE id>%d ORDER BY id ASC LIMIT %d", (int) $state['cursor'], $limit ),
			ARRAY_A
		);
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_scrub_read', $wpdb->last_error );
		}

		foreach ( $rows as $row ) {
			$changes = array();
			$url     = Collector::sanitize_relative_url( $row['page_url'] );
			$href    = Collector::sanitize_relative_url( $row['element_href'] );
			if ( $url !== $row['page_url'] ) {
				$changes['page_url'] = $url;
			}
			if ( $href !== $row['element_href'] ) {
				$changes['element_href'] = $href;
			}
			$identity = Page_Identity::from_payload( $url, (int) $row['post_id'] );
			foreach ( array( 'page_key', 'object_type', 'object_id', 'post_id' ) as $identity_field ) {
				if ( (string) $identity[ $identity_field ] !== (string) $row[ $identity_field ] ) {
					$changes[ $identity_field ] = $identity[ $identity_field ];
				}
			}

			$structural = strtolower( $row['element_tag'] . ' ' . $row['element_selector'] . ' ' . $row['heatmap_selector'] );
			$is_editable = (bool) preg_match( '/(^|[\s>.#])(input|textarea|select|option)([\s>\[.#:]|$)|contenteditable|password|payment|checkout|card[-_ ]?(number|cvc|cvv)/i', $structural );
			if ( '' !== (string) $row['element_text'] && ( $is_editable || self::looks_sensitive( $row['element_text'] ) ) ) {
				$changes['element_text'] = '';
			}
			$server_title = $identity['post_id'] > 0 ? $identity['title'] : '';
			if ( $server_title !== (string) $row['page_title'] ) {
				$changes['page_title'] = $server_title;
			}

			$state['scanned']++;
			$state['cursor'] = (int) $row['id'];
			if ( empty( $changes ) ) {
				continue;
			}
			$state['changed']++;
			if ( ! $state['dry_run'] ) {
				$updated = $wpdb->update( $table, $changes, array( 'id' => (int) $row['id'] ) );
				if ( false === $updated ) {
					return new \WP_Error( 'convertrack_scrub_write', $wpdb->last_error );
				}
			}
		}

		if ( count( $rows ) < $limit ) {
			$state['stage']  = 'sessions';
			$state['cursor'] = '';
		}
		return $state;
	}

	/** Scrub presence session URLs using a lexical primary-key cursor. */
	private static function scrub_sessions( array $state, $limit ) {
		global $wpdb;
		$table  = Database::sessions_table();
		$cursor = isset( $state['cursor'] ) ? (string) $state['cursor'] : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT session_id,current_url FROM $table WHERE session_id>%s ORDER BY session_id ASC LIMIT %d", $cursor, $limit ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_scrub_read', $wpdb->last_error );
		}
		foreach ( $rows as $row ) {
			$url = Collector::sanitize_relative_url( $row['current_url'] );
			$state['scanned']++;
			$state['cursor'] = $row['session_id'];
			if ( $url === $row['current_url'] ) {
				continue;
			}
			$state['changed']++;
			if ( ! $state['dry_run'] && false === $wpdb->update( $table, array( 'current_url' => $url ), array( 'session_id' => $row['session_id'] ) ) ) {
				return new \WP_Error( 'convertrack_scrub_write', $wpdb->last_error );
			}
		}
		if ( count( $rows ) < $limit ) {
			$state['stage']  = 'notfound';
			$state['cursor'] = 0;
		}
		return $state;
	}

	/** Scrub and merge legacy query-keyed 404 events. */
	private static function scrub_notfound( array $state, $limit ) {
		global $wpdb;
		if ( ! class_exists( '\Convertrack\NotFound\Database' ) ) {
			$state['stage'] = 'complete';
			return $state;
		}
		$table = \Convertrack\NotFound\Database::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,url_hash,url,path,query_string,referrer_url,first_detected_at,last_detected_at,hit_count FROM $table WHERE id>%d ORDER BY id ASC LIMIT %d", (int) $state['cursor'], $limit ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_scrub_read', $wpdb->last_error );
		}
		foreach ( $rows as $row ) {
			$safe_url = Collector::sanitize_relative_url( $row['url'] );
			$path     = Page_Identity::normalize_path( Collector::sanitize_url_path( wp_parse_url( $safe_url, PHP_URL_PATH ) ) );
			$url      = $path;
			$referrer = Collector::sanitize_relative_url( $row['referrer_url'] );
			$hash     = md5( $path );
			$changed  = $url !== $row['url'] || $path !== $row['path'] || '' !== $row['query_string'] || $referrer !== $row['referrer_url'] || $hash !== $row['url_hash'];
			$state['scanned']++;
			$state['cursor'] = (int) $row['id'];
			if ( ! $changed ) {
				continue;
			}
			$state['changed']++;
			if ( $state['dry_run'] ) {
				continue;
			}

			// Merge a legacy query-specific row into the normalized path row.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$target_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE url_hash=%s AND id<>%d LIMIT 1", $hash, (int) $row['id'] ) );
			if ( $target_id > 0 ) {
				$sql = "UPDATE $table SET hit_count=hit_count+%d,first_detected_at=LEAST(first_detected_at,%s),last_detected_at=GREATEST(last_detected_at,%s),referrer_url=IF(referrer_url='',%s,referrer_url),updated_at=%s WHERE id=%d";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( false === $wpdb->query( $wpdb->prepare( $sql, (int) $row['hit_count'], $row['first_detected_at'], $row['last_detected_at'], $referrer, current_time( 'mysql' ), $target_id ) ) || false === $wpdb->delete( $table, array( 'id' => (int) $row['id'] ) ) ) {
					return new \WP_Error( 'convertrack_scrub_write', $wpdb->last_error );
				}
				$state['merged_404']++;
			} else {
				$updated = $wpdb->update(
					$table,
					array( 'url_hash' => $hash, 'url' => $url, 'path' => $path, 'query_string' => '', 'referrer_url' => $referrer, 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => (int) $row['id'] )
				);
				if ( false === $updated ) {
					return new \WP_Error( 'convertrack_scrub_write', $wpdb->last_error );
				}
			}
		}
		if ( count( $rows ) < $limit ) {
			$state['stage']        = 'complete';
			$state['status']       = 'complete';
			$state['completed_at'] = current_time( 'mysql', true );
		}
		return $state;
	}

	/** Detect email/token/value-like historical text. */
	private static function looks_sensitive( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value ) ) {
			return true;
		}
		if ( preg_match( '/\b(token|nonce|password|passwd|secret|signature|session|order[_ -]?key|reset[_ -]?key|auth|bearer)\b\s*[:=]/i', $value ) ) {
			return true;
		}
		return strlen( $value ) >= 32 && (bool) preg_match( '/^[A-Za-z0-9_\-.\/=+]+$/', $value );
	}

	/** Schedule the next bounded batch once. */
	private static function schedule_next() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * WP-CLI: wp convertrack privacy-scrub [--dry-run] [--batch-size=500].
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public static function cli( $args, $assoc_args ) {
		unset( $args );
		$dry_run = isset( $assoc_args['dry-run'] );
		$batch   = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : self::DEFAULT_BATCH;
		$restart = isset( $assoc_args['restart'] );
		if ( $restart ) {
			delete_option( self::STATE_OPTION );
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			$state = self::start( $dry_run );
			if ( is_wp_error( $state ) ) {
				\WP_CLI::error( $state->get_error_message() );
			}
		}
		do {
			$state = self::process_batch( $batch );
			if ( is_wp_error( $state ) ) {
				\WP_CLI::error( $state->get_error_message() );
			}
			\WP_CLI::log( sprintf( 'Stage: %s; scanned: %d; would change/changed: %d', $state['stage'], $state['scanned'], $state['changed'] ) );
		} while ( 'running' === $state['status'] );
		\WP_CLI::success( sprintf( 'Privacy scrub complete. Scanned %d rows; %s %d; merged 404 rows: %d.', $state['scanned'], $state['dry_run'] ? 'would change' : 'changed', $state['changed'], $state['merged_404'] ) );
	}
}
