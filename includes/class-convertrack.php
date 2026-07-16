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
	 * @var \Convertrack\GSC\Keywords_Cron
	 */
	public $gsc_keywords_cron;

	/**
	 * @var \Convertrack\GSC\Keywords_Analyzer
	 */
	public $gsc_keywords_analyzer;

	/**
	 * @var \Convertrack\GSC\Keywords_Rest_Controller
	 */
	public $gsc_keywords_rest;

	/**
	 * @var \Convertrack\GSC\Keywords_Admin
	 */
	public $gsc_keywords_admin;

	/**
	 * @var \Convertrack\NotFound\Rest_Controller
	 */
	public $notfound_rest;

	/**
	 * @var \Convertrack\NotFound\Cron
	 */
	public $notfound_cron;

	/**
	 * @var \Convertrack\NotFound\Admin
	 */
	public $notfound_admin;

	/**
	 * @var \Convertrack\NotFound\Redirector
	 */
	public $notfound_redirector;

	/**
	 * @var \Convertrack\NotFound\Detector
	 */
	public $notfound_detector;

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
		// Schema changes can be expensive. Run them only in an administrator or
		// explicit CLI context, never while serving public traffic.
		add_action( 'admin_init', array( $this, 'maybe_upgrade_schema' ), 1 );
		add_action( 'admin_notices', array( $this, 'schema_admin_notice' ) );
		Privacy_Scrubber::register();
		Site_Health::register();
		Lifecycle::register();

		load_plugin_textdomain( 'convertrack-click-conversion-analytics', false, dirname( CONVERTRACK_BASENAME ) . '/languages' );

		$this->rest               = new Rest_Controller();
		$this->frontend           = new Frontend();
		$this->cron               = new Cron();
		$this->admin              = new Admin();
		$this->gsc_rest           = new \Convertrack\GSC\Rest_Controller();
		$this->gsc_cron           = new \Convertrack\GSC\Cron();
		$this->gsc_admin          = new \Convertrack\GSC\Admin();
		$this->gsc_keywords_cron     = new \Convertrack\GSC\Keywords_Cron();
		$this->gsc_keywords_analyzer = new \Convertrack\GSC\Keywords_Analyzer();
		$this->gsc_keywords_rest     = new \Convertrack\GSC\Keywords_Rest_Controller();
		$this->gsc_keywords_admin    = new \Convertrack\GSC\Keywords_Admin();
		$this->notfound_rest      = new \Convertrack\NotFound\Rest_Controller();
		$this->notfound_cron      = new \Convertrack\NotFound\Cron();
		$this->notfound_admin     = new \Convertrack\NotFound\Admin();
		$this->notfound_redirector = new \Convertrack\NotFound\Redirector();
		$this->notfound_detector  = new \Convertrack\NotFound\Detector();

		$this->rest->register();
		$this->frontend->register();
		$this->cron->register();
		$this->admin->register();
		$this->gsc_rest->register();
		$this->gsc_cron->register();
		$this->gsc_admin->register();
		$this->gsc_keywords_cron->register();
		$this->gsc_keywords_analyzer->register();
		$this->gsc_keywords_rest->register();
		$this->gsc_keywords_admin->register();
		$this->notfound_rest->register();
		$this->notfound_cron->register();
		$this->notfound_admin->register();
		$this->notfound_redirector->register();
		$this->notfound_detector->register();

		// Self-updater only exists in the self-hosted build (see main file).
		if ( class_exists( __NAMESPACE__ . '\\Updater' ) ) {
			$this->updater = new Updater( CONVERTRACK_FILE, CONVERTRACK_GITHUB_OWNER, CONVERTRACK_GITHUB_REPO, CONVERTRACK_SLUG );
			$this->updater->register();
		}
	}

	/**
	 * Upgrade every module schema in a controlled administrator request.
	 */
	public function maybe_upgrade_schema() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$results = array(
			'secret'   => Settings::migrate_secret(),
			'core'     => Database::maybe_upgrade(),
			'ingestion'=> Ingestion_Guard::maybe_upgrade(),
			'gsc'      => \Convertrack\GSC\Database::maybe_upgrade(),
			'keywords' => \Convertrack\GSC\Keywords_Database::maybe_upgrade(),
			'notfound' => \Convertrack\NotFound\Database::maybe_upgrade(),
		);
		foreach ( $results as $module => $result ) {
			if ( is_wp_error( $result ) ) {
				update_option( 'convertrack_' . $module . '_schema_error', $result->get_error_message(), false );
			} else {
				delete_option( 'convertrack_' . $module . '_schema_error' );
			}
		}
	}

	/**
	 * Surface actionable schema failures instead of allowing silent data loss.
	 */
	public function schema_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || Database::schema_is_healthy() ) {
			return;
		}
		$message = get_option( Database::SCHEMA_ERROR_OPTION, __( 'The analytics database schema is incomplete.', 'convertrack-click-conversion-analytics' ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Convertrack collection is paused.', 'convertrack-click-conversion-analytics' ) . '</strong> ' . esc_html( $message ) . ' ' . esc_html__( 'Reload this page to retry the migration, then review the database user permissions if it continues.', 'convertrack-click-conversion-analytics' ) . '</p></div>';
	}
}
