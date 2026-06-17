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
		add_action( 'admin_post_convertrack_seed_demo', array( $this, 'handle_seed_demo' ) );
		add_action( 'admin_post_convertrack_reset_data', array( $this, 'handle_reset_data' ) );
	}

	/**
	 * Tools: insert sample data so the dashboard can be evaluated without
	 * waiting for live traffic.
	 */
	public function handle_seed_demo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack' ) );
		}
		check_admin_referer( 'convertrack_seed_demo' );
		$rows = Database::seed_demo();
		wp_safe_redirect( add_query_arg( array( 'cvtrk_notice' => 'seeded', 'cvtrk_rows' => (int) $rows ), admin_url( 'admin.php?page=convertrack-settings' ) ) );
		exit;
	}

	/**
	 * Tools: delete all tracked data (events, sessions, rollups).
	 */
	public function handle_reset_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack' ) );
		}
		check_admin_referer( 'convertrack_reset_data' );
		Database::reset_all();
		wp_safe_redirect( add_query_arg( array( 'cvtrk_notice' => 'reset' ), admin_url( 'admin.php?page=convertrack-settings' ) ) );
		exit;
	}

	/**
	 * Render the shared header (brand + live pill) and tab navigation.
	 *
	 * @param string $current Active tab key: overview|pages|settings.
	 */
	public static function render_header( $current ) {
		$tabs = array(
			'overview' => array( 'label' => __( 'Overview', 'convertrack' ), 'icon' => 'chart-area', 'page' => 'convertrack' ),
			'pages'    => array( 'label' => __( 'Pages & Buttons', 'convertrack' ), 'icon' => 'admin-links', 'page' => 'convertrack-pages' ),
			'settings' => array( 'label' => __( 'Settings', 'convertrack' ), 'icon' => 'admin-generic', 'page' => 'convertrack-settings' ),
		);
		?>
		<div class="cvtrk-header">
			<h1 class="cvtrk-brand">
				<span class="dashicons dashicons-chart-line"></span>
				<?php esc_html_e( 'Convertrack', 'convertrack' ); ?>
				<span class="cvtrk-ver">v<?php echo esc_html( CONVERTRACK_VERSION ); ?></span>
			</h1>
			<div class="cvtrk-live">
				<span class="cvtrk-dot"></span>
				<b data-cvtrk="active">–</b>
				<span><?php esc_html_e( 'on the site now', 'convertrack' ); ?></span>
			</div>
		</div>
		<nav class="cvtrk-tabs">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="cvtrk-tab <?php echo $key === $current ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab['page'] ) ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>"></span>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
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
