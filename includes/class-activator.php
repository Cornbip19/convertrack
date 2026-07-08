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
		$keywords_install = \Convertrack\GSC\Keywords_Database::install();
		$notfound_install = \Convertrack\NotFound\Database::install();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		if ( false === get_option( \Convertrack\GSC\Settings::OPTION, false ) ) {
			add_option( \Convertrack\GSC\Settings::OPTION, \Convertrack\GSC\Settings::defaults(), '', false );
		}
		if ( false === get_option( \Convertrack\GSC\Keywords_Settings::OPTION, false ) ) {
			add_option( \Convertrack\GSC\Keywords_Settings::OPTION, \Convertrack\GSC\Keywords_Settings::defaults(), '', false );
		}
		if ( false === get_option( \Convertrack\NotFound\Settings::OPTION, false ) ) {
			add_option( \Convertrack\NotFound\Settings::OPTION, \Convertrack\NotFound\Settings::defaults(), '', false );
		}
		if ( is_wp_error( $gsc_install ) ) {
			$gsc_settings            = \Convertrack\GSC\Settings::all();
			$gsc_settings['enabled'] = 0;
			\Convertrack\GSC\Settings::save( $gsc_settings );
			\Convertrack\GSC\Logger::error( 'database', 'GSC database migration failed during activation.', array( 'error' => $gsc_install->get_error_message() ) );
			set_transient( 'convertrack_gsc_migration_error', $gsc_install->get_error_message(), HOUR_IN_SECONDS );
		}
		if ( is_wp_error( $keywords_install ) ) {
			$keywords_settings            = \Convertrack\GSC\Keywords_Settings::all();
			$keywords_settings['enabled'] = 0;
			\Convertrack\GSC\Keywords_Settings::save( $keywords_settings );
			\Convertrack\GSC\Logger::error( 'keywords-db', 'Keyword Insights database migration failed during activation.', array( 'error' => $keywords_install->get_error_message() ) );
			set_transient( 'convertrack_gsc_keywords_migration_error', $keywords_install->get_error_message(), HOUR_IN_SECONDS );
		}
		if ( is_wp_error( $notfound_install ) ) {
			$notfound_settings            = \Convertrack\NotFound\Settings::all();
			$notfound_settings['enabled'] = 0;
			\Convertrack\NotFound\Settings::save( $notfound_settings );
			\Convertrack\NotFound\Logger::error( 'database', '404 Monitor database migration failed during activation.', array( 'error' => $notfound_install->get_error_message() ) );
			set_transient( 'convertrack_404_migration_error', $notfound_install->get_error_message(), HOUR_IN_SECONDS );
		}

		Cron::schedule();
		\Convertrack\GSC\Cron::schedule();
		\Convertrack\GSC\Keywords_Cron::schedule();
		\Convertrack\NotFound\Cron::schedule();

		// Let permalink/rewrite-dependent REST routes settle.
		flush_rewrite_rules();
	}
}
