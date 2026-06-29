<?php
/**
 * Main orchestrator. Wires every component to WordPress hooks.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Rest_Controller
	 */
	public $rest;

	/**
	 * @var Frontend
	 */
	public $frontend;

	/**
	 * @var Admin
	 */
	public $admin;

	/**
	 * @var Cron
	 */
	public $cron;

	/**
	 * @var Updater
	 */
	public $updater;

	/**
	 * @var \Convertrack\GSC\Rest_Controller
	 */
	public $gsc_rest;

	/**
	 * @var \Convertrack\GSC\Cron
	 */
	public $gsc_cron;

	/**
	 * @var \Convertrack\GSC\Admin
	 */
	public $gsc_admin;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Instantiate components and register hooks.
	 */
	private function boot() {
		// Keep schema current after plugin updates without needing reactivation.
		Database::maybe_upgrade();
		\Convertrack\GSC\Database::maybe_upgrade();

		load_plugin_textdomain( 'convertrack-click-conversion-analytics', false, dirname( CONVERTRACK_BASENAME ) . '/languages' );

		$this->rest     = new Rest_Controller();
		$this->frontend = new Frontend();
		$this->cron     = new Cron();
		$this->admin    = new Admin();
		$this->gsc_rest  = new \Convertrack\GSC\Rest_Controller();
		$this->gsc_cron  = new \Convertrack\GSC\Cron();
		$this->gsc_admin = new \Convertrack\GSC\Admin();

		$this->rest->register();
		$this->frontend->register();
		$this->cron->register();
		$this->admin->register();
		$this->gsc_rest->register();
		$this->gsc_cron->register();
		$this->gsc_admin->register();

		// Self-updater only exists in the self-hosted build (see main file).
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			$this->updater = new Updater( CONVERTRACK_FILE, CONVERTRACK_GITHUB_OWNER, CONVERTRACK_GITHUB_REPO, CONVERTRACK_SLUG );
			$this->updater->register();
		}
	}
}
