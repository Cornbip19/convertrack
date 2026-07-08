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
	public static function deactivate() {
		Cron::unschedule();
		\Convertrack\GSC\Cron::unschedule();
		\Convertrack\GSC\Keywords_Cron::unschedule();
		\Convertrack\NotFound\Cron::unschedule();
		flush_rewrite_rules();
	}
}
