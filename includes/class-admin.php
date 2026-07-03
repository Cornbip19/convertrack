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
		add_action( 'admin_post_convertrack_export', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'register_privacy' ) );
	}

	/**
	 * Add a suggested privacy-policy paragraph for the site's Privacy Policy page.
	 */
	public function register_privacy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = __( 'Convertrack records anonymous interaction analytics — pageviews, clicks on buttons and links, the traffic source, and a "currently online" count. It stores a random visitor identifier in the browser\'s local storage and keeps all data in this site\'s own database. It does not collect names, email addresses, or IP addresses, and by default it does not send any visitor data to third parties. Visitors whose browser sends a "Do Not Track" signal are not tracked by default.', 'convertrack-click-conversion-analytics' );

		if ( Settings::get( 'track_search_keywords' ) ) {
			$content .= ' ' . __( 'Search keyword tracking is enabled: Convertrack stores search terms from UTM term parameters, this site search query parameter, and search-engine referrer query strings when browsers provide them. Search engines often hide organic search queries, in which case no keyword is stored.', 'convertrack-click-conversion-analytics' );
		}

		// When visitor location is enabled, the IP is sent to a geolocation API to
		// resolve the country only; disclose that here.
		if ( Settings::get( 'enable_geo' ) ) {
			$content .= ' ' . __( 'Visitor location is enabled: each visitor\'s IP address is sent to a third-party geolocation service (ip-api.com) solely to determine their country. The IP address is not stored — only the resulting two-letter country code is kept.', 'convertrack-click-conversion-analytics' );
		}

		wp_add_privacy_policy_content( 'Convertrack', wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Stream a CSV export of a dashboard table.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'convertrack_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'convertrack-click-conversion-analytics' ) );
		}

		$type  = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'buttons';
		$range = isset( $_GET['range'] ) ? max( 1, min( 365, (int) $_GET['range'] ) ) : 7;
		$post  = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=convertrack-' . $type . '-' . $range . 'd-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		switch ( $type ) {
			case 'pages':
				fputcsv( $out, array( 'Page', 'URL', 'Clicks', 'Pageviews', 'Conversions' ) );
				foreach ( Database::top_pages( $range, 1000 ) as $r ) {
					$pid = (int) $r['post_id'];
					fputcsv( $out, array(
						$pid > 0 ? get_the_title( $pid ) : '(unknown / global)',
						$pid > 0 ? get_permalink( $pid ) : '',
						(int) $r['clicks'], (int) $r['pageviews'], (int) $r['conversions'],
					) );
				}
				break;

			case 'sources':
				fputcsv( $out, array( 'Source', 'Pageviews', 'Clicks', 'Conversions', 'Visitors' ) );
				foreach ( Database::top_sources( $range, 1000 ) as $r ) {
					fputcsv( $out, array( $r['source'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'], (int) $r['visitors'] ) );
				}
				break;

			case 'keywords':
				fputcsv( $out, array( 'Keyword', 'Keyword source', 'Traffic source', 'Pageviews', 'Clicks', 'Conversions', 'Visitors' ) );
				if ( Settings::get( 'track_search_keywords' ) ) {
					foreach ( Database::top_search_terms( $range, 1000, $post ) as $r ) {
						fputcsv( $out, array( $r['keyword'], $r['keyword_source'], $r['traffic_source'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'], (int) $r['visitors'] ) );
					}
				}
				break;

			case 'countries':
				fputcsv( $out, array( 'Country code', 'Country', 'Visitors', 'Pageviews', 'Clicks', 'Conversions' ) );
				foreach ( Database::top_countries( $range, 1000 ) as $r ) {
					$code = (string) $r['country'];
					$name = $code;
					if ( '' !== $code && class_exists( '\\Locale' ) ) {
						$display = \Locale::getDisplayRegion( '-' . $code, 'en' );
						if ( $display && $display !== $code ) {
							$name = $display;
						}
					}
					fputcsv( $out, array( $code, $name, (int) $r['visitors'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'] ) );
				}
				break;

			case 'daily':
				fputcsv( $out, array( 'Date', 'Pageviews', 'Clicks', 'Conversions' ) );
				foreach ( Database::clicks_timeseries( $range ) as $date => $r ) {
					fputcsv( $out, array( $date, (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'] ) );
				}
				break;

			case 'buttons':
			default:
				fputcsv( $out, array( 'Button', 'Selector', 'Clicks', 'Conversions' ) );
				foreach ( Database::top_buttons( $range, 1000, $post ) as $r ) {
					$label = trim( (string) $r['element_text'] );
					if ( '' === $label ) {
						$label = (string) $r['element_selector'];
					}
					fputcsv( $out, array( $label, (string) $r['element_selector'], (int) $r['clicks'], (int) $r['conversions'] ) );
				}
				break;
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Tools: insert sample data so the dashboard can be evaluated without
	 * waiting for live traffic.
	 */
	public function handle_seed_demo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
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
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( 'convertrack_reset_data' );
		Database::reset_all();
		wp_safe_redirect( add_query_arg( array( 'cvtrk_notice' => 'reset' ), admin_url( 'admin.php?page=convertrack-settings' ) ) );
		exit;
	}

	/**
	 * Render the shared header (brand + live pill) and tab navigation.
	 *
	 * @param string $current Active tab key: overview|pages|heatmaps|funnels|settings.
	 */
	public static function render_header( $current ) {
		$tabs = array(
			'overview' => array( 'label' => __( 'Overview', 'convertrack-click-conversion-analytics' ), 'icon' => 'overview', 'page' => 'convertrack' ),
			'pages'    => array( 'label' => __( 'Pages & Buttons', 'convertrack-click-conversion-analytics' ), 'icon' => 'pages', 'page' => 'convertrack-pages' ),
			'heatmaps' => array( 'label' => __( 'Heatmaps', 'convertrack-click-conversion-analytics' ), 'icon' => 'heatmap', 'page' => 'convertrack-heatmaps' ),
			'funnels'  => array( 'label' => __( 'Funnels', 'convertrack-click-conversion-analytics' ), 'icon' => 'funnel', 'page' => 'convertrack-funnels' ),
			'gsc'      => array( 'label' => __( 'Google Index Monitor', 'convertrack-click-conversion-analytics' ), 'icon' => 'search', 'page' => 'convertrack-gsc' ),
			'settings' => array( 'label' => __( 'Settings', 'convertrack-click-conversion-analytics' ), 'icon' => 'settings', 'page' => 'convertrack-settings' ),
		);
		$logo_path    = plugin_dir_path( CONVERTRACK_FILE ) . 'admin/assets/convertrack-logo.svg';
		$logo_version = file_exists( $logo_path ) ? filemtime( $logo_path ) : CONVERTRACK_VERSION;
		?>
		<div class="cvtrk-header">
			<h1 class="cvtrk-brand">
				<img class="cvtrk-logo" src="<?php echo esc_url( CONVERTRACK_URL . 'admin/assets/convertrack-logo.svg?ver=' . $logo_version ); ?>" width="196" height="48" alt="<?php esc_attr_e( 'Convertrack', 'convertrack-click-conversion-analytics' ); ?>" />
				<span class="cvtrk-ver">v<?php echo esc_html( CONVERTRACK_VERSION ); ?></span>
			</h1>
			<div class="cvtrk-live">
				<span class="cvtrk-dot"></span>
				<b data-cvtrk="active">–</b>
				<span><?php esc_html_e( 'on the site now', 'convertrack-click-conversion-analytics' ); ?></span>
			</div>
		</div>
		<nav class="cvtrk-tabs">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="cvtrk-tab <?php echo $key === $current ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab['page'] ) ); ?>">
					<?php echo self::icon( $tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render a compact, consistent line icon used across admin screens.
	 *
	 * @param string $name Icon key.
	 * @return string SVG markup.
	 */
	public static function icon( $name ) {
		$name  = sanitize_key( $name );
		$icons = array(
			'overview'      => '<path d="M4 17.5h16"/><path d="M6 14l3.5-4.5 4 3 4.5-7"/><circle cx="9.5" cy="9.5" r="1.5"/><circle cx="13.5" cy="12.5" r="1.5"/><circle cx="18" cy="5.5" r="1.5"/>',
			'pages'         => '<path d="M7 4h8l4 4v12H7z"/><path d="M14 4v5h5"/><path d="M10 13h6"/><path d="M10 17h4"/>',
			'heatmap'       => '<path d="M5 18c2.5-6 5-9 9-9 2.5 0 4.2 1.1 5 3"/><circle cx="8" cy="16" r="2"/><circle cx="14" cy="10" r="2.5"/><circle cx="18" cy="13" r="1.7"/>',
			'funnel'        => '<path d="M5 5h14l-5.5 6.5V18l-3 1.5v-8z"/>',
			'search'        => '<circle cx="10.5" cy="10.5" r="5.5"/><path d="M15 15l4 4"/>',
			'settings'      => '<path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6z"/><path d="M12 3.5v2.2M12 18.3v2.2M4.6 7.2l1.9 1.1M17.5 15.7l1.9 1.1M4.6 16.8l1.9-1.1M17.5 8.3l1.9-1.1"/>',
			'pageviews'     => '<path d="M4 6.5h16v11H4z"/><path d="M8 21h8"/><path d="M12 17.5V21"/><circle cx="12" cy="12" r="2.7"/>',
			'clicks'        => '<path d="M8 4v11l2.4-2.4L14 20l2.2-1.1-3.5-6.9H16z"/>',
			'conversions'   => '<path d="M5 12.5l4 4L19 6.5"/><path d="M4 5h11"/><path d="M4 19h16"/>',
			'rate'          => '<path d="M5 18V9"/><path d="M10 18V5"/><path d="M15 18v-6"/><path d="M20 18V8"/>',
			'click-through' => '<path d="M5 17l5-5 3 3 6-8"/><path d="M14 7h5v5"/>',
			'visitors'      => '<circle cx="9" cy="8" r="3"/><path d="M4 19c.8-3 2.5-4.5 5-4.5s4.2 1.5 5 4.5"/><path d="M15 11.5a2.5 2.5 0 1 0-.5-4.9"/><path d="M15.5 15c2.2.3 3.7 1.7 4.5 4"/>',
			'duration'      => '<circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>',
			'refresh'       => '<path d="M18 8a7 7 0 0 0-12-2l-2 2"/><path d="M4 4v4h4"/><path d="M6 16a7 7 0 0 0 12 2l2-2"/><path d="M20 20v-4h-4"/>',
			'info'          => '<circle cx="12" cy="12" r="8"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
			'sessions'      => '<path d="M5 18c.9-3 3.2-4.5 7-4.5s6.1 1.5 7 4.5"/><circle cx="12" cy="8" r="4"/>',
			'award'         => '<circle cx="12" cy="8" r="4"/><path d="M9.5 11.5L8 20l4-2 4 2-1.5-8.5"/>',
			'desktop'       => '<path d="M4 5.5h16v10H4z"/><path d="M9 20h6"/><path d="M12 15.5V20"/>',
			'tablet'        => '<rect x="7" y="3.5" width="10" height="17" rx="2"/><path d="M11 17.5h2"/>',
			'mobile'        => '<rect x="8.5" y="3" width="7" height="18" rx="2"/><path d="M11.5 17.5h1"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			$name = 'overview';
		}

		return '<svg class="cvtrk-icon cvtrk-icon-' . esc_attr( $name ) . '" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $icons[ $name ] . '</svg>';
	}

	/**
	 * URL for the WordPress admin menu icon.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		return CONVERTRACK_URL . 'admin/assets/convertrack-menu-icon.png?ver=' . CONVERTRACK_VERSION;
	}

	/**
	 * Add the top-level menu and sub-pages.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Convertrack', 'convertrack-click-conversion-analytics' ),
			__( 'Convertrack', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' ),
			self::menu_icon(),
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'convertrack-click-conversion-analytics' ),
			__( 'Overview', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Pages & Buttons', 'convertrack-click-conversion-analytics' ),
			__( 'Pages & Buttons', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-pages',
			array( $this, 'render_pages' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Heatmaps', 'convertrack-click-conversion-analytics' ),
			__( 'Heatmaps', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-heatmaps',
			array( $this, 'render_heatmaps' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Funnels', 'convertrack-click-conversion-analytics' ),
			__( 'Funnels', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-funnels',
			array( $this, 'render_funnels' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Google Index Monitor', 'convertrack-click-conversion-analytics' ),
			__( 'Google Index Monitor', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-gsc',
			array( $this, 'render_gsc' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'convertrack-click-conversion-analytics' ),
			__( 'Settings', 'convertrack-click-conversion-analytics' ),
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
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'convertrack-click-conversion-analytics' ) . '</a>' );
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
				'exportUrl'      => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_export' ) ),
				'exportNonce'    => wp_create_nonce( 'convertrack_export' ),
				'gscExportUrl'   => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_gsc_export' ) ),
				'gscExportNonce' => wp_create_nonce( 'convertrack_gsc_export' ),
				'i18n'           => array(
					'liveNow'      => __( 'visitors on the site now', 'convertrack-click-conversion-analytics' ),
					'clicks'       => __( 'Clicks', 'convertrack-click-conversion-analytics' ),
					'pageviews'    => __( 'Pageviews', 'convertrack-click-conversion-analytics' ),
					'conversions'  => __( 'Conversions', 'convertrack-click-conversion-analytics' ),
					'conversion'   => __( 'Conversion', 'convertrack-click-conversion-analytics' ),
					'convRate'     => __( 'Conversion rate', 'convertrack-click-conversion-analytics' ),
					'ctr'          => __( 'Click-through rate', 'convertrack-click-conversion-analytics' ),
					'uniques'      => __( 'Unique visitors', 'convertrack-click-conversion-analytics' ),
					'visitor'      => __( 'Visitor', 'convertrack-click-conversion-analytics' ),
					'noData'       => __( 'No data yet for this range.', 'convertrack-click-conversion-analytics' ),
					'updated'      => __( 'Updated', 'convertrack-click-conversion-analytics' ),
					'activityTrend' => __( 'Activity trend', 'convertrack-click-conversion-analytics' ),
					'pageVisit'    => __( 'Page visit', 'convertrack-click-conversion-analytics' ),
					'click'        => __( 'Click', 'convertrack-click-conversion-analytics' ),
					'scroll'       => __( 'Scroll', 'convertrack-click-conversion-analytics' ),
					'scrolls'      => __( 'Scrolls', 'convertrack-click-conversion-analytics' ),
					'event'        => __( 'Event', 'convertrack-click-conversion-analytics' ),
					'device'       => __( 'Device', 'convertrack-click-conversion-analytics' ),
					'loading'      => __( 'Loading…', 'convertrack-click-conversion-analytics' ),
					'copied'       => __( 'Copied', 'convertrack-click-conversion-analytics' ),
					'pending'          => __( 'Pending', 'convertrack-click-conversion-analytics' ),
					'issues'           => __( 'Issues', 'convertrack-click-conversion-analytics' ),
					'indexCoverage'    => __( 'Index coverage', 'convertrack-click-conversion-analytics' ),
					'indexingProgress' => __( 'Indexing progress', 'convertrack-click-conversion-analytics' ),
					'collectingData'   => __( 'Collecting daily data — the progress line appears after a couple of days of monitoring.', 'convertrack-click-conversion-analytics' ),
					'indexCoverageSub'  => __( 'Click a status below to see its URLs', 'convertrack-click-conversion-analytics' ),
					'coverageBreakdown' => __( 'Coverage breakdown', 'convertrack-click-conversion-analytics' ),
					'noDataYet'         => __( 'No data yet', 'convertrack-click-conversion-analytics' ),
					'topButtons'   => __( 'Most clicked buttons', 'convertrack-click-conversion-analytics' ),
					'topPages'     => __( 'Top pages', 'convertrack-click-conversion-analytics' ),
					'page'         => __( 'Page', 'convertrack-click-conversion-analytics' ),
					'button'       => __( 'Button', 'convertrack-click-conversion-analytics' ),
					'count'        => __( 'Count', 'convertrack-click-conversion-analytics' ),
					'allPages'     => __( 'All pages', 'convertrack-click-conversion-analytics' ),
					'source'       => __( 'Source', 'convertrack-click-conversion-analytics' ),
					'visitors'     => __( 'Visitors', 'convertrack-click-conversion-analytics' ),
					'topSources'   => __( 'Traffic sources', 'convertrack-click-conversion-analytics' ),
					'country'        => __( 'Country', 'convertrack-click-conversion-analytics' ),
					'location'       => __( 'Location', 'convertrack-click-conversion-analytics' ),
					'timeOnSite'     => __( 'Time on site', 'convertrack-click-conversion-analytics' ),
					'unknownCountry' => __( 'Unknown', 'convertrack-click-conversion-analytics' ),
					'geoOff'         => __( 'Turn on "Visitor location" in Settings to see where visitors are from.', 'convertrack-click-conversion-analytics' ),
					'vsPrev'       => __( 'vs. previous period', 'convertrack-click-conversion-analytics' ),
					'reached'      => __( 'reached this depth', 'convertrack-click-conversion-analytics' ),
					'clicksHere'   => __( 'clicks', 'convertrack-click-conversion-analytics' ),
					'showPage'     => __( 'Show page behind heatmap', 'convertrack-click-conversion-analytics' ),
					'noHeatmap'    => __( 'No heatmap data for this page yet.', 'convertrack-click-conversion-analytics' ),
					'noHeatmapPages' => __( 'No page activity in this range yet. Heatmaps appear once visitors view and click on your pages.', 'convertrack-click-conversion-analytics' ),
					'pageTop'      => __( 'Top of page', 'convertrack-click-conversion-analytics' ),
					'pageBottom'   => __( 'Bottom of page', 'convertrack-click-conversion-analytics' ),
					'allDevices'   => __( 'All devices', 'convertrack-click-conversion-analytics' ),
					'desktop'      => __( 'Desktop', 'convertrack-click-conversion-analytics' ),
					'tablet'       => __( 'Tablet', 'convertrack-click-conversion-analytics' ),
					'mobile'       => __( 'Mobile', 'convertrack-click-conversion-analytics' ),
					'anchored'     => __( 'Element anchored', 'convertrack-click-conversion-analytics' ),
					'pagePosition' => __( 'Page position', 'convertrack-click-conversion-analytics' ),
					'funnels'      => __( 'Funnels', 'convertrack-click-conversion-analytics' ),
					'sessions'     => __( 'Sessions', 'convertrack-click-conversion-analytics' ),
					'convertingSessions' => __( 'Converting sessions', 'convertrack-click-conversion-analytics' ),
					'totalConversions'   => __( 'Total conversions', 'convertrack-click-conversion-analytics' ),
					'commonPaths'        => __( 'Common paths before conversion', 'convertrack-click-conversion-analytics' ),
					'dropoffs'           => __( 'Drop-off pages', 'convertrack-click-conversion-analytics' ),
					'preConversionButtons' => __( 'Buttons clicked before conversion', 'convertrack-click-conversion-analytics' ),
					'campaign'           => __( 'Campaign', 'convertrack-click-conversion-analytics' ),
					'keyword'            => __( 'Keyword', 'convertrack-click-conversion-analytics' ),
					'utmTerm'            => __( 'UTM term', 'convertrack-click-conversion-analytics' ),
					'siteSearch'         => __( 'Site search', 'convertrack-click-conversion-analytics' ),
					'referrerQuery'      => __( 'Search referrer', 'convertrack-click-conversion-analytics' ),
					'notProvided'        => __( 'Not provided', 'convertrack-click-conversion-analytics' ),
					'keywordsOff'        => __( 'Enable search keyword tracking in Settings to collect supported queries.', 'convertrack-click-conversion-analytics' ),
					'noSearchTerms'      => __( 'No search keywords for this range.', 'convertrack-click-conversion-analytics' ),
					'unknown'            => __( 'Unknown', 'convertrack-click-conversion-analytics' ),
					'totalUrls'          => __( 'Total URLs Found', 'convertrack-click-conversion-analytics' ),
					'indexed'            => __( 'Indexed', 'convertrack-click-conversion-analytics' ),
					'notIndexed'         => __( 'Not Indexed', 'convertrack-click-conversion-analytics' ),
					'pendingQuota'       => __( 'Pending Due to Quota', 'convertrack-click-conversion-analytics' ),
					'pendingSitemap'     => __( 'Pending From Sitemap', 'convertrack-click-conversion-analytics' ),
					'crawledNotIndexed'  => __( 'Crawled But Not Indexed', 'convertrack-click-conversion-analytics' ),
					'discoveredNotIndexed' => __( 'Discovered But Not Indexed', 'convertrack-click-conversion-analytics' ),
					'duplicateCanonical' => __( 'Duplicate/Canonical Issue', 'convertrack-click-conversion-analytics' ),
					'blockedRobots'      => __( 'Blocked by Robots', 'convertrack-click-conversion-analytics' ),
					'noindexDetected'    => __( 'Noindex Detected', 'convertrack-click-conversion-analytics' ),
					'errors'             => __( 'Errors', 'convertrack-click-conversion-analytics' ),
					'lastSync'           => __( 'Last Sync Time', 'convertrack-click-conversion-analytics' ),
					'nextCheck'          => __( 'Next Scheduled Check', 'convertrack-click-conversion-analytics' ),
					'gscStatus'          => __( 'Google Index Status', 'convertrack-click-conversion-analytics' ),
					'coverageState'      => __( 'Coverage State', 'convertrack-click-conversion-analytics' ),
					'googleVerdict'      => __( 'Google Verdict', 'convertrack-click-conversion-analytics' ),
					'attempts'           => __( 'Attempts', 'convertrack-click-conversion-analytics' ),
					'actions'            => __( 'Actions', 'convertrack-click-conversion-analytics' ),
					'gscScanning'        => __( 'Scanning sitemap…', 'convertrack-click-conversion-analytics' ),
					'gscScanDone'        => __( 'Sitemap scan complete — URLs queued:', 'convertrack-click-conversion-analytics' ),
					'gscScanFailed'      => __( 'Sitemap scan failed:', 'convertrack-click-conversion-analytics' ),
					'gscStartingInspection' => __( 'Starting inspection…', 'convertrack-click-conversion-analytics' ),
					'gscNothingDue'      => __( 'No URLs are currently due for inspection.', 'convertrack-click-conversion-analytics' ),
					'gscInspecting'      => __( 'Inspecting URLs…', 'convertrack-click-conversion-analytics' ),
					'gscInspectDone'     => __( 'Inspection finished:', 'convertrack-click-conversion-analytics' ),
					'gscInspectStopped'  => __( 'Inspection stopped:', 'convertrack-click-conversion-analytics' ),
					'gscInspectFailed'   => __( 'Inspection failed:', 'convertrack-click-conversion-analytics' ),
					'gscUrlsChecked'     => __( 'URLs checked', 'convertrack-click-conversion-analytics' ),
					'gscRemaining'       => __( 'remaining, continuing in the background', 'convertrack-click-conversion-analytics' ),
					'gscQuotaReached'    => __( 'Daily inspection quota reached. Remaining URLs will be checked automatically tomorrow.', 'convertrack-click-conversion-analytics' ),
					'gscBackground'      => __( 'Another inspection run is already active — processing will continue in the background.', 'convertrack-click-conversion-analytics' ),
					'gscPropsLoading'    => __( 'Loading Search Console properties…', 'convertrack-click-conversion-analytics' ),
					'gscPropsError'      => __( 'Couldn\'t load properties:', 'convertrack-click-conversion-analytics' ),
					'gscPropsEmpty'      => __( 'No Search Console properties found for this account. Verify your site in Search Console first.', 'convertrack-click-conversion-analytics' ),
					'retry'              => __( 'Retry', 'convertrack-click-conversion-analytics' ),
					'gscInspectHint'     => __( 'Opens Google Search Console, where you can use "Request Indexing".', 'convertrack-click-conversion-analytics' ),
					'gscNotifyGoogle'    => __( 'Notify Google', 'convertrack-click-conversion-analytics' ),
					'gscNotifyHint'      => __( 'Sends a Google Indexing API notification for this URL. Google officially supports this only for job-posting and livestream pages.', 'convertrack-click-conversion-analytics' ),
					'gscIndexingNotified' => __( 'Google has been notified about this URL. The next recheck will show whether it was picked up.', 'convertrack-click-conversion-analytics' ),
					'gscNoInspectLinks'  => __( 'No URLs with Search Console links in the current view. Adjust the filters above first.', 'convertrack-click-conversion-analytics' ),
					'gscTabsOpened'      => __( 'Opened in Search Console:', 'convertrack-click-conversion-analytics' ),
					'gscTabsHint'        => __( 'Click "Request Indexing" in each tab.', 'convertrack-click-conversion-analytics' ),
					'gscTabsBlocked'     => __( 'Your browser blocked some tabs — allow pop-ups for this site and click again. Opened:', 'convertrack-click-conversion-analytics' ),
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

	public function render_heatmaps() {
		$this->render_view( 'heatmap' );
	}

	public function render_funnels() {
		$this->render_view( 'funnels' );
	}

	public function render_gsc() {
		$this->render_view( 'gsc-index-monitor' );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'convertrack-click-conversion-analytics' ) );
		}
		$file = CONVERTRACK_DIR . 'admin/views/' . $view . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}
}
