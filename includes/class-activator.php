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

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		Cron::schedule();

		// Let permalink/rewrite-dependent REST routes settle.
		flush_rewrite_rules();
	}
}
