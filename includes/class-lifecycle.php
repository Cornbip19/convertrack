<?php
/**
 * Multisite and lifecycle coordination.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Lifecycle {

	const PROVISION_HOOK  = 'convertrack_multisite_provision';
	const PROVISION_STATE = 'convertrack_network_provision_state';
	const CLEANUP_STATE   = 'convertrack_network_cleanup_state';

	/** Register multisite hooks and CLI commands. */
	public static function register() {
		add_action( self::PROVISION_HOOK, array( __CLASS__, 'run_provision_batch' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_site' ), 20, 1 );
		add_action( 'switch_blog', array( __CLASS__, 'flush_site_caches' ), 10, 0 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'convertrack multisite-provision', array( __CLASS__, 'cli_provision' ) );
			\WP_CLI::add_command( 'convertrack multisite-cleanup', array( __CLASS__, 'cli_cleanup' ) );
		}
	}

	/**
	 * Begin bounded provisioning after network activation.
	 *
	 * @param bool $schedule Whether to enqueue the first WP-Cron step.
	 * @return true|\WP_Error
	 */
	public static function network_activate( $schedule = true ) {
		if ( ! is_multisite() ) {
			return true;
		}
		delete_site_option( self::CLEANUP_STATE );
		$initial = array( 'status' => 'running', 'cursor' => 0, 'provisioned' => 0, 'failed' => 0, 'failures' => array(), 'started_at' => current_time( 'mysql', true ) );
		$saved   = update_site_option( self::PROVISION_STATE, $initial );
		if ( ! $saved && $initial !== get_site_option( self::PROVISION_STATE, array() ) ) {
			return new \WP_Error( 'convertrack_provision_state_failed', 'The multisite provisioning state could not be saved.' );
		}
		if ( ! $schedule ) {
			return true;
		}
		$scheduled = self::schedule_provision_step();
		if ( is_wp_error( $scheduled ) ) {
			$state = get_site_option( self::PROVISION_STATE, array() );
			$state['status']         = 'failed';
			$state['schedule_error'] = $scheduled->get_error_message();
			$state['completed_at']   = current_time( 'mysql', true );
			update_site_option( self::PROVISION_STATE, $state );
			return $scheduled;
		}
		return true;
	}

	/** Provision the next keyset-paginated group of sites. */
	public static function run_provision_batch( $limit = 25 ) {
		global $wpdb;
		if ( ! is_multisite() ) {
			return;
		}
		$lock_name = 'convertrack_network_provision_' . md5( (string) $wpdb->siteid );
		$lock_value = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		$owns_lock  = 1 === (int) $lock_value;
		if ( null !== $lock_value && ! $owns_lock ) {
			// The other owner normally schedules the next batch. A dupe-guarded
			// fallback also recovers from a failed advisory-lock query.
			self::schedule_provision_step();
			return;
		}

		try {
			$state = get_site_option( self::PROVISION_STATE, array() );
			if ( ! is_array( $state ) || ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
				return;
			}
			$cursor = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
			$limit  = max( 1, min( 100, (int) $limit ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id>%d ORDER BY blog_id ASC LIMIT %d", $cursor, $limit ) );
			if ( ! empty( $wpdb->last_error ) ) {
				$state['status']         = 'partial';
				$state['schedule_error'] = $wpdb->last_error;
				$state['completed_at']   = current_time( 'mysql', true );
				update_site_option( self::PROVISION_STATE, $state );
				return;
			}
			foreach ( (array) $ids as $blog_id ) {
				$blog_id = (int) $blog_id;
				switch_to_blog( $blog_id );
				try {
					$result = self::provision_current_site();
				} catch ( \Throwable $error ) {
					$result = new \WP_Error( 'convertrack_provision_exception', $error->getMessage() );
				} finally {
					restore_current_blog();
				}
				$state['cursor'] = $blog_id;
				if ( is_wp_error( $result ) ) {
					$state['failed'] = isset( $state['failed'] ) ? (int) $state['failed'] + 1 : 1;
					// Keep the network option bounded even when a database permission
					// problem affects thousands of sites.
					if ( count( (array) $state['failures'] ) < 100 ) {
						$state['failures'][ $blog_id ] = $result->get_error_message();
					}
				} else {
					$state['provisioned'] = isset( $state['provisioned'] ) ? (int) $state['provisioned'] + 1 : 1;
				}
			}
			if ( count( (array) $ids ) === $limit ) {
				update_site_option( self::PROVISION_STATE, $state );
				$scheduled = self::schedule_provision_step();
				if ( is_wp_error( $scheduled ) ) {
					$state['status']         = 'partial';
					$state['schedule_error'] = $scheduled->get_error_message();
					$state['completed_at']   = current_time( 'mysql', true );
					update_site_option( self::PROVISION_STATE, $state );
				}
				return;
			}
			$state['status']       = empty( $state['failed'] ) ? 'complete' : 'partial';
			$state['completed_at'] = current_time( 'mysql', true );
			update_site_option( self::PROVISION_STATE, $state );
		} finally {
			if ( $owns_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/** Provision a newly-created network site. */
	public static function initialize_site( $site ) {
		if ( ! is_multisite() || ! $site instanceof \WP_Site || ! self::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::provision_current_site();
		} finally {
			restore_current_blog();
		}
	}

	/** Install schemas/defaults/jobs for the current blog. */
	public static function provision_current_site() {
		$results = array(
			Database::install(),
			Ingestion_Guard::install(),
			\Convertrack\GSC\Database::install(),
			\Convertrack\GSC\Keywords_Database::install(),
			\Convertrack\NotFound\Database::install(),
		);
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$options = array(
			Settings::OPTION                           => Settings::defaults(),
			\Convertrack\GSC\Settings::OPTION          => \Convertrack\GSC\Settings::defaults(),
			\Convertrack\GSC\Keywords_Settings::OPTION => \Convertrack\GSC\Keywords_Settings::defaults(),
			\Convertrack\NotFound\Settings::OPTION     => \Convertrack\NotFound\Settings::defaults(),
		);
		foreach ( $options as $name => $defaults ) {
			if ( false === get_option( $name, false ) && ! add_option( $name, $defaults, '', false ) && false === get_option( $name, false ) ) {
				return new \WP_Error( 'convertrack_defaults_write_failed', sprintf( 'Could not create the %s option.', $name ) );
			}
		}
		self::flush_site_caches();
		Cron::schedule();
		\Convertrack\GSC\Cron::schedule();
		\Convertrack\GSC\Keywords_Cron::schedule();
		\Convertrack\NotFound\Cron::schedule();
		return true;
	}

	/** Cancel work per blog without loading an entire large network in memory. */
	public static function network_deactivate() {
		if ( ! is_multisite() ) {
			return Manifest::cancel_jobs();
		}
		$cursor   = 0;
		$failures = array();
		do {
			$batch = self::cleanup_network_batch( $cursor, 100 );
			if ( is_wp_error( $batch ) ) {
				$failures['network'] = array( $batch->get_error_message() );
				break;
			}
			$cursor   = $batch['cursor'];
			$failures = $failures + $batch['failures'];
		} while ( ! $batch['complete'] );
		delete_site_option( self::PROVISION_STATE );
		delete_site_option( self::CLEANUP_STATE );
		return $failures;
	}

	/**
	 * Cancel jobs for one keyset-paginated network batch.
	 *
	 * @param int $cursor Last processed blog ID.
	 * @param int $limit  Maximum sites to process.
	 * @return array|\WP_Error
	 */
	public static function cleanup_network_batch( $cursor = 0, $limit = 100 ) {
		global $wpdb;
		$cursor = max( 0, (int) $cursor );
		$limit  = max( 1, min( 1000, (int) $limit ) );
		if ( ! is_multisite() ) {
			$failed = Manifest::cancel_jobs();
			return array( 'cursor' => 0, 'processed' => 1, 'complete' => true, 'failures' => empty( $failed ) ? array() : array( get_current_blog_id() => $failed ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id>%d ORDER BY blog_id ASC LIMIT %d", $cursor, $limit ) );
		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error( 'convertrack_network_cleanup_query', $wpdb->last_error );
		}
		$failures = array();
		foreach ( (array) $ids as $blog_id ) {
			$cursor = (int) $blog_id;
			switch_to_blog( $cursor );
			try {
				$result = Manifest::cancel_jobs();
				if ( ! empty( $result ) ) {
					$failures[ $cursor ] = $result;
				}
			} finally {
				restore_current_blog();
			}
		}
		return array(
			'cursor'    => $cursor,
			'processed' => count( (array) $ids ),
			'complete'  => count( (array) $ids ) < $limit,
			'failures'  => $failures,
		);
	}

	/** WP-CLI bounded provision command. */
	public static function cli_provision() {
		if ( ! self::is_network_active() ) {
			\WP_CLI::error( 'Convertrack must be network-active before provisioning every site.' );
		}
		wp_clear_scheduled_hook( self::PROVISION_HOOK );
		self::network_activate( false );
		do {
			self::run_provision_batch( 100 );
			$state = get_site_option( self::PROVISION_STATE, array() );
		} while ( isset( $state['status'] ) && 'running' === $state['status'] );
		wp_clear_scheduled_hook( self::PROVISION_HOOK );
		\WP_CLI::success( sprintf( 'Provisioned %d site(s); failures: %d.', isset( $state['provisioned'] ) ? $state['provisioned'] : 0, isset( $state['failed'] ) ? $state['failed'] : 0 ) );
	}

	/** WP-CLI job cleanup path for large networks. */
	public static function cli_cleanup( $args, $assoc_args ) {
		unset( $args );
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Pass --yes to cancel all Convertrack jobs across the network.' );
		}
		if ( ! is_multisite() ) {
			$failures = Manifest::cancel_jobs();
			if ( ! empty( $failures ) ) {
				\WP_CLI::error( 'Some Convertrack jobs could not be cancelled: ' . implode( ', ', $failures ) );
			}
			\WP_CLI::success( 'Convertrack scheduled work was cancelled.' );
			return;
		}

		if ( ! empty( $assoc_args['restart'] ) ) {
			delete_site_option( self::CLEANUP_STATE );
		}
		$state  = get_site_option( self::CLEANUP_STATE, array() );
		$state  = is_array( $state ) ? wp_parse_args( $state, array( 'cursor' => 0, 'processed' => 0, 'failed' => 0 ) ) : array( 'cursor' => 0, 'processed' => 0, 'failed' => 0 );
		$cursor = isset( $assoc_args['cursor'] ) ? max( 0, (int) $assoc_args['cursor'] ) : (int) $state['cursor'];
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, min( 1000, (int) $assoc_args['limit'] ) ) : 100;
		$batch  = self::cleanup_network_batch( $cursor, $limit );
		if ( is_wp_error( $batch ) ) {
			\WP_CLI::error( $batch->get_error_message() );
		}
		$state['cursor']    = $batch['cursor'];
		$state['processed'] = (int) $state['processed'] + (int) $batch['processed'];
		$state['failed']    = (int) $state['failed'] + count( $batch['failures'] );
		$state['updated_at'] = current_time( 'mysql', true );
		if ( $batch['complete'] ) {
			delete_site_option( self::CLEANUP_STATE );
			\WP_CLI::success( sprintf( 'Convertrack scheduled work was cancelled across %d site(s); failures: %d.', $state['processed'], $state['failed'] ) );
			return;
		}
		update_site_option( self::CLEANUP_STATE, $state );
		\WP_CLI::warning( sprintf( 'Processed %1$d site(s) through blog ID %2$d. Re-run the same command to continue.', $state['processed'], $state['cursor'] ) );
	}

	/** Reset per-request option caches after switch_to_blog(). */
	public static function flush_site_caches() {
		Settings::flush_cache();
		\Convertrack\GSC\Settings::flush_cache();
		\Convertrack\GSC\Keywords_Settings::flush_cache();
		\Convertrack\NotFound\Settings::flush_cache();
	}

	/** Whether this plugin is currently active network-wide. */
	private static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		return defined( 'CONVERTRACK_BASENAME' ) && isset( $active[ CONVERTRACK_BASENAME ] );
	}

	/** Schedule one provisioning continuation with an explicit error result. */
	private static function schedule_provision_step() {
		if ( wp_next_scheduled( self::PROVISION_HOOK ) ) {
			return true;
		}
		$result = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PROVISION_HOOK, array(), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result ? true : new \WP_Error( 'convertrack_provision_schedule_failed', 'The multisite provisioning continuation could not be scheduled.' );
	}
}
