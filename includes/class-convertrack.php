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

		load_plugin_textdomain( 'convertrack', false, dirname( CONVERTRACK_BASENAME ) . '/languages' );

		$this->rest     = new Rest_Controller();
		$this->frontend = new Frontend();
		$this->cron     = new Cron();
		$this->admin    = new Admin();
		$this->updater  = new Updater( CONVERTRACK_FILE, CONVERTRACK_GITHUB_OWNER, CONVERTRACK_GITHUB_REPO, CONVERTRACK_SLUG );

		$this->rest->register();
		$this->frontend->register();
		$this->cron->register();
		$this->admin->register();
		$this->updater->register();
	}
}
