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
			'overview' => array( 'label' => __( 'Overview', 'convertrack-click-conversion-analytics' ), 'icon' => 'chart-area', 'page' => 'convertrack' ),
			'pages'    => array( 'label' => __( 'Pages & Buttons', 'convertrack-click-conversion-analytics' ), 'icon' => 'admin-links', 'page' => 'convertrack-pages' ),
			'heatmaps' => array( 'label' => __( 'Heatmaps', 'convertrack-click-conversion-analytics' ), 'icon' => 'visibility', 'page' => 'convertrack-heatmaps' ),
			'funnels'  => array( 'label' => __( 'Funnels', 'convertrack-click-conversion-analytics' ), 'icon' => 'networking', 'page' => 'convertrack-funnels' ),
			'gsc'      => array( 'label' => __( 'Google Index Monitor', 'convertrack-click-conversion-analytics' ), 'icon' => 'search', 'page' => 'convertrack-gsc' ),
			'settings' => array( 'label' => __( 'Settings', 'convertrack-click-conversion-analytics' ), 'icon' => 'admin-generic', 'page' => 'convertrack-settings' ),
		);
		?>
		<div class="cvtrk-header">
			<h1 class="cvtrk-brand">
				<span class="dashicons dashicons-chart-line"></span>
				<?php esc_html_e( 'Convertrack', 'convertrack-click-conversion-analytics' ); ?>
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
			__( 'Convertrack', 'convertrack-click-conversion-analytics' ),
			__( 'Convertrack', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' ),
			'dashicons-chart-line',
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
					'convRate'     => __( 'Conversion rate', 'convertrack-click-conversion-analytics' ),
					'ctr'          => __( 'Click-through rate', 'convertrack-click-conversion-analytics' ),
					'uniques'      => __( 'Unique visitors', 'convertrack-click-conversion-analytics' ),
					'noData'       => __( 'No data yet for this range.', 'convertrack-click-conversion-analytics' ),
					'loading'      => __( 'Loading…', 'convertrack-click-conversion-analytics' ),
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
