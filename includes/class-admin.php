<?php
/**
 * Admin UI: menus, settings registration, and dashboard assets.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SETTINGS_GROUP = 'convertrack_settings_group';
	const MENU_SLUG      = 'convertrack';

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . CONVERTRACK_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the top-level menu and sub-pages.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Convertrack', 'convertrack' ),
			__( 'Convertrack', 'convertrack' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'convertrack' ),
			__( 'Overview', 'convertrack' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Pages & Buttons', 'convertrack' ),
			__( 'Pages & Buttons', 'convertrack' ),
			'manage_options',
			'convertrack-pages',
			array( $this, 'render_pages' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'convertrack' ),
			__( 'Settings', 'convertrack' ),
			'manage_options',
			'convertrack-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Register the single settings option with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __NAMESPACE__ . '\\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Quick links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=convertrack-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'convertrack' ) . '</a>' );
		return $links;
	}

	/**
	 * Enqueue dashboard assets only on Convertrack screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'convertrack' ) ) {
			return;
		}

		wp_enqueue_style(
			'convertrack-admin',
			CONVERTRACK_URL . 'admin/css/admin.css',
			array(),
			CONVERTRACK_VERSION
		);

		wp_enqueue_script(
			'convertrack-admin',
			CONVERTRACK_URL . 'admin/js/admin.js',
			array(),
			CONVERTRACK_VERSION,
			true
		);

		wp_localize_script(
			'convertrack-admin',
			'ConvertrackAdmin',
			array(
				'root'           => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'activeRefresh'  => 10000,
				'i18n'           => array(
					'liveNow'      => __( 'visitors on the site now', 'convertrack' ),
					'clicks'       => __( 'Clicks', 'convertrack' ),
					'pageviews'    => __( 'Pageviews', 'convertrack' ),
					'conversions'  => __( 'Conversions', 'convertrack' ),
					'convRate'     => __( 'Conversion rate', 'convertrack' ),
					'ctr'          => __( 'Click-through rate', 'convertrack' ),
					'uniques'      => __( 'Unique visitors', 'convertrack' ),
					'noData'       => __( 'No data yet for this range.', 'convertrack' ),
					'loading'      => __( 'Loading…', 'convertrack' ),
					'topButtons'   => __( 'Most clicked buttons', 'convertrack' ),
					'topPages'     => __( 'Top pages', 'convertrack' ),
					'page'         => __( 'Page', 'convertrack' ),
					'button'       => __( 'Button', 'convertrack' ),
					'count'        => __( 'Count', 'convertrack' ),
					'allPages'     => __( 'All pages', 'convertrack' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * View renderers
	 * ------------------------------------------------------------------ */

	public function render_overview() {
		$this->render_view( 'overview' );
	}

	public function render_pages() {
		$this->render_view( 'pages' );
	}

	public function render_settings() {
		$this->render_view( 'settings' );
	}

	/**
	 * Include a view file.
	 *
	 * @param string $view View name (without extension).
	 */
	private function render_view( $view ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'convertrack' ) );
		}
		$file = CONVERTRACK_DIR . 'admin/views/' . $view . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}
}
