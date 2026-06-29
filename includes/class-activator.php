<?php
/**
 * Runs on plugin activation.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * Create tables, seed defaults and schedule background jobs.
	 */
	public static function activate() {
		Database::install();
		$gsc_install = \Convertrack\GSC\Database::install();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		if ( false === get_option( \Convertrack\GSC\Settings::OPTION, false ) ) {
			add_option( \Convertrack\GSC\Settings::OPTION, \Convertrack\GSC\Settings::defaults(), '', false );
		}
		if ( is_wp_error( $gsc_install ) ) {
			$gsc_settings            = \Convertrack\GSC\Settings::all();
			$gsc_settings['enabled'] = 0;
			\Convertrack\GSC\Settings::save( $gsc_settings );
			\Convertrack\GSC\Logger::error( 'database', 'GSC database migration failed during activation.', array( 'error' => $gsc_install->get_error_message() ) );
			set_transient( 'convertrack_gsc_migration_error', $gsc_install->get_error_message(), HOUR_IN_SECONDS );
		}

		Cron::schedule();
		\Convertrack\GSC\Cron::schedule();

		// Let permalink/rewrite-dependent REST routes settle.
		flush_rewrite_rules();
	}
}
