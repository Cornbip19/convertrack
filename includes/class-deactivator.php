<?php
/**
 * Runs on plugin deactivation. Stops background jobs but keeps data.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Clear scheduled jobs.
	 */
	public static function deactivate( $network_wide = false ) {
		Cron::unschedule();
		\Convertrack\GSC\Cron::unschedule();
		\Convertrack\GSC\Keywords_Cron::unschedule();
		\Convertrack\NotFound\Cron::unschedule();
		if ( $network_wide && is_multisite() ) {
			Lifecycle::network_deactivate();
		} else {
			Manifest::cancel_jobs();
		}
		flush_rewrite_rules();
	}
}
