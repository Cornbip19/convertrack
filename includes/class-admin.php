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
		add_action( 'wp_dashboard_setup', array( '\\Convertrack\\Dashboard_Widget', 'register' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent_menu' ), 99 );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ), 99, 2 );
		add_filter( 'admin_title', array( $this, 'hidden_page_admin_title' ), 10, 2 );
		add_filter( 'plugin_action_links_' . CONVERTRACK_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_post_convertrack_seed_demo', array( $this, 'handle_seed_demo' ) );
		add_action( 'admin_post_convertrack_reset_data', array( $this, 'handle_reset_data' ) );
		add_action( 'admin_post_convertrack_delete_operational_data', array( $this, 'handle_delete_operational_data' ) );
		add_action( 'admin_post_convertrack_privacy_scrub', array( $this, 'handle_privacy_scrub' ) );
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
		$content = __( 'Convertrack records first-party interaction analytics — pageviews, clicks on configured button-like controls, privacy-safe heatmap coordinates, the traffic source, and a "currently online" count. It never reads form or editable-control values. Page and link query strings are removed by default; an administrator may explicitly retain non-sensitive parameter names, while credential, email, session, reset, and order-key parameters are always removed. Convertrack stores a random visitor identifier in browser local storage and keeps analytics in this site\'s own database. It does not store IP addresses and by default sends no visitor data to third parties. Global Privacy Control and a denied WordPress Consent API statistics purpose are respected; Do Not Track is respected by default.', 'convertrack-click-conversion-analytics' );

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
				CSV::write( $out, array( 'Page', 'URL', 'Clicks', 'Pageviews', 'Conversions' ) );
				foreach ( Database::top_pages( $range, 1000 ) as $r ) {
					$pid = (int) $r['post_id'];
					CSV::write( $out, array(
						$pid > 0 ? get_the_title( $pid ) : '(unknown / global)',
						$pid > 0 ? get_permalink( $pid ) : '',
						(int) $r['clicks'], (int) $r['pageviews'], (int) $r['conversions'],
					) );
				}
				break;

			case 'sources':
				CSV::write( $out, array( 'Source', 'Pageviews', 'Clicks', 'Conversions', 'Visitors' ) );
				foreach ( Database::top_sources( $range, 1000 ) as $r ) {
					CSV::write( $out, array( $r['source'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'], (int) $r['visitors'] ) );
				}
				break;

			case 'keywords':
				CSV::write( $out, array( 'Keyword', 'Keyword source', 'Traffic source', 'Pageviews', 'Clicks', 'Conversions', 'Visitors' ) );
				if ( Settings::get( 'track_search_keywords' ) ) {
					foreach ( Database::top_search_terms( $range, 1000, $post ) as $r ) {
						CSV::write( $out, array( $r['keyword'], $r['keyword_source'], $r['traffic_source'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'], (int) $r['visitors'] ) );
					}
				}
				break;

			case 'countries':
				CSV::write( $out, array( 'Country code', 'Country', 'Visitors', 'Pageviews', 'Clicks', 'Conversions' ) );
				foreach ( Database::top_countries( $range, 1000 ) as $r ) {
					$code = (string) $r['country'];
					$name = $code;
					if ( '' !== $code && class_exists( '\\Locale' ) ) {
						$display = \Locale::getDisplayRegion( '-' . $code, 'en' );
						if ( $display && $display !== $code ) {
							$name = $display;
						}
					}
					CSV::write( $out, array( $code, $name, (int) $r['visitors'], (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'] ) );
				}
				break;

			case 'daily':
				CSV::write( $out, array( 'Date', 'Pageviews', 'Clicks', 'Conversions' ) );
				foreach ( Database::clicks_timeseries( $range ) as $date => $r ) {
					CSV::write( $out, array( $date, (int) $r['pageviews'], (int) $r['clicks'], (int) $r['conversions'] ) );
				}
				break;

			case 'buttons':
			default:
				CSV::write( $out, array( 'Button', 'Selector', 'Clicks', 'Conversions' ) );
				foreach ( Database::top_buttons( $range, 1000, $post ) as $r ) {
					$label = trim( (string) $r['element_text'] );
					if ( '' === $label ) {
						$label = (string) $r['element_selector'];
					}
					CSV::write( $out, array( $label, (string) $r['element_selector'], (int) $r['clicks'], (int) $r['conversions'] ) );
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
		if ( is_wp_error( $rows ) ) {
			$this->store_action_error( $rows );
			$this->redirect_settings( 'error' );
		}
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
		$confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
		if ( 'RESET ANALYTICS' !== $confirmation ) {
			wp_die( esc_html__( 'Type RESET ANALYTICS exactly to confirm.', 'convertrack-click-conversion-analytics' ), '', array( 'response' => 400 ) );
		}
		$result = Data_Management::reset_analytics();
		if ( is_wp_error( $result ) ) {
			$this->store_action_error( $result );
			$this->redirect_settings( 'error' );
		}
		wp_safe_redirect( add_query_arg( array( 'cvtrk_notice' => 'reset' ), admin_url( 'admin.php?page=convertrack-settings' ) ) );
		exit;
	}

	/** Permanently remove all module operational rows, preserving configuration. */
	public function handle_delete_operational_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( 'convertrack_delete_operational_data' );
		$confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
		if ( 'DELETE CONVERTRACK' !== $confirmation ) {
			wp_die( esc_html__( 'Type DELETE CONVERTRACK exactly to confirm.', 'convertrack-click-conversion-analytics' ), '', array( 'response' => 400 ) );
		}
		$result = Data_Management::delete_operational_data();
		if ( is_wp_error( $result ) ) {
			$this->store_action_error( $result );
			$this->redirect_settings( 'error' );
		}
		$this->redirect_settings( 'operational-reset' );
	}

	/** Queue a dry-run or live historical privacy scrub. */
	public function handle_privacy_scrub() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( 'convertrack_privacy_scrub' );
		$mode   = isset( $_POST['scrub_mode'] ) ? sanitize_key( wp_unslash( $_POST['scrub_mode'] ) ) : 'dry-run';
		$result = Privacy_Scrubber::start( 'apply' !== $mode );
		if ( is_wp_error( $result ) ) {
			$this->store_action_error( $result );
			$this->redirect_settings( 'error' );
		}
		$this->redirect_settings( 'scrub-started' );
	}

	/** Store a short-lived administrator-only action error. */
	private function store_action_error( $error ) {
		set_transient( 'convertrack_action_error_' . get_current_user_id(), $error->get_error_message(), MINUTE_IN_SECONDS );
	}

	/** Redirect to Settings with a display-only result code. */
	private function redirect_settings( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'cvtrk_notice' => sanitize_key( $notice ) ), admin_url( 'admin.php?page=convertrack-settings' ) ) );
		exit;
	}

	/**
	 * Render the compact shared product header.
	 *
	 * @param string $current Current screen key. Retained for view compatibility.
	 */
	public static function render_header( $current ) {
		unset( $current );
		$logo_path    = plugin_dir_path( CONVERTRACK_FILE ) . 'admin/assets/convertrack-logo.svg';
		$logo_version = file_exists( $logo_path ) ? filemtime( $logo_path ) : CONVERTRACK_VERSION;
		?>
		<div class="cvtrk-header cvtrk-header-compact">
			<div class="cvtrk-brand-block">
				<div class="cvtrk-brand">
					<img class="cvtrk-logo" src="<?php echo esc_url( CONVERTRACK_URL . 'admin/assets/convertrack-logo.svg?ver=' . $logo_version ); ?>" width="196" height="48" alt="<?php esc_attr_e( 'Convertrack', 'convertrack-click-conversion-analytics' ); ?>" />
				</div>
				<p class="cvtrk-tagline"><?php esc_html_e( 'First-party visitor analytics for WordPress', 'convertrack-click-conversion-analytics' ); ?></p>
			</div>
			<div class="cvtrk-header-status">
				<div class="cvtrk-live" aria-live="polite">
					<span class="cvtrk-dot" aria-hidden="true"></span>
					<b data-cvtrk="active">-</b>
					<span><?php esc_html_e( 'on the site now', 'convertrack-click-conversion-analytics' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render local navigation within a grouped admin destination.
	 *
	 * @param string $group   Navigation group: analytics|search.
	 * @param string $current Active local screen key.
	 */
	public static function render_subnav( $group, $current ) {
		$group_labels = array(
			'analytics' => __( 'Analytics', 'convertrack-click-conversion-analytics' ),
			'search'    => __( 'Search & SEO', 'convertrack-click-conversion-analytics' ),
		);
		$groups = array(
			'analytics' => array(
				'pages'    => array( 'label' => __( 'Pages & Buttons', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-pages' ),
				'heatmaps' => array( 'label' => __( 'Heatmaps', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-heatmaps' ),
				'funnels'  => array( 'label' => __( 'Journeys', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-funnels' ),
			),
			'search'    => array(
				'gsc'      => array( 'label' => __( 'Indexing', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-gsc' ),
				'keywords' => array( 'label' => __( 'Keyword Opportunities', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-gsc-keywords' ),
				'autoapply' => array( 'label' => __( 'Auto-Apply', 'convertrack-click-conversion-analytics' ), 'page' => 'convertrack-gsc-autoapply' ),
			),
		);

		if ( empty( $groups[ $group ] ) ) {
			return;
		}
		?>
		<nav class="cvtrk-subnav" aria-label="<?php esc_attr_e( 'Current section', 'convertrack-click-conversion-analytics' ); ?>">
			<span class="cvtrk-subnav-group"><?php echo esc_html( $group_labels[ $group ] ); ?></span>
			<?php foreach ( $groups[ $group ] as $key => $item ) : ?>
				<a class="cvtrk-subnav-link <?php echo $key === $current ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>" <?php echo $key === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
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
			'keywords'      => '<path d="M12 3.5l1.8 4.4 4.4 1.8-4.4 1.8L12 16l-1.8-4.5L5.8 9.7l4.4-1.8z"/><path d="M18 15.5l.9 2 2 .9-2 .9-.9 2-.9-2-2-.9 2-.9z"/>',
			'warning'       => '<path d="M12 4l9 16H3z"/><path d="M12 9v5"/><path d="M12 17h.01"/>',
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
	 * Plain-language definitions shared by the PHP views (help icons, guides)
	 * and the admin script (table header help). Keyed by a short term id.
	 *
	 * @return array<string,string>
	 */
	public static function glossary() {
		static $terms = null;
		if ( null !== $terms ) {
			return $terms;
		}
		$terms = array(
			'pageviews'          => __( 'How many times a page was opened. Ten views by one person count as ten.', 'convertrack-click-conversion-analytics' ),
			'clicks'             => __( 'Clicks on the buttons and links Convertrack tracks on your pages.', 'convertrack-click-conversion-analytics' ),
			'conversions'        => __( 'Valuable actions: calls, bookings, messages, form sends, or reaching a thank-you page.', 'convertrack-click-conversion-analytics' ),
			'conversionRate'     => __( 'The share of visits that included at least one valuable action.', 'convertrack-click-conversion-analytics' ),
			'sessions'           => __( 'Visits. One person can make several visits over time; each one is a session.', 'convertrack-click-conversion-analytics' ),
			'convertingSessions' => __( 'Visits in which at least one valuable action happened: a call, a booking, a message.', 'convertrack-click-conversion-analytics' ),
			'visitors'           => __( 'Different people, as far as a browser can tell. One person on two devices counts twice.', 'convertrack-click-conversion-analytics' ),
			'cta'                => __( 'A call to action: a button or link you want visitors to press, such as Book now or Call.', 'convertrack-click-conversion-analytics' ),
			'heatmapClicks'      => __( 'Clicks recorded together with where on the page they happened.', 'convertrack-click-conversion-analytics' ),
			'scrollDepth'        => __( 'The share of visitors who scrolled at least this far down the page.', 'convertrack-click-conversion-analytics' ),
			'path'               => __( 'The pages a visitor opened, in order, before the valuable action happened.', 'convertrack-click-conversion-analytics' ),
			'dropoff'            => __( 'The last page someone saw before leaving without doing anything valuable.', 'convertrack-click-conversion-analytics' ),
			'source'             => __( 'Where the visit came from: a Google search, a link on another site, social media, or the address typed directly.', 'convertrack-click-conversion-analytics' ),
			'impressions'        => __( 'How many times your site appeared in Google\'s search results for this search.', 'convertrack-click-conversion-analytics' ),
			'gscClicks'          => __( 'People who clicked through to your site from Google\'s results for this search.', 'convertrack-click-conversion-analytics' ),
			'ctr'                => __( 'Click-through rate: clicks divided by impressions. 5% means 1 in 20 people who saw you clicked.', 'convertrack-click-conversion-analytics' ),
			'position'           => __( 'Your average spot on Google\'s results page. 1 is the top result; 11 or more is page two.', 'convertrack-click-conversion-analytics' ),
			'presence'           => __( 'Whether the words people search for actually appear on the page. Missing means they do not appear at all.', 'convertrack-click-conversion-analytics' ),
			'opportunity'        => __( 'A 0 to 100 score for how much a small change could gain. Many impressions, a weak position and missing words score highest.', 'convertrack-click-conversion-analytics' ),
			'keywordType'        => __( 'What kind of search this is: a service, a place, a question, your brand name, and so on.', 'convertrack-click-conversion-analytics' ),
			'branded'            => __( 'Branded searches include your business name. Non-branded searches come from people who do not know you yet.', 'convertrack-click-conversion-analytics' ),
			'indexed'            => __( 'Google shows this page in its search results.', 'convertrack-click-conversion-analytics' ),
			'pending'            => __( 'Google knows the page exists but has not listed it yet. Normal for new pages.', 'convertrack-click-conversion-analytics' ),
			'issues'             => __( 'Google decided not to list the page. The reason is shown next to it.', 'convertrack-click-conversion-analytics' ),
			'indexStatus'        => __( 'What Google reported the last time this page was checked.', 'convertrack-click-conversion-analytics' ),
			'hits'               => __( 'How many times visitors reached this broken address.', 'convertrack-click-conversion-analytics' ),
			'suggestedRedirect'  => __( 'The page Convertrack thinks the visitor was looking for.', 'convertrack-click-conversion-analytics' ),
			'confidence'         => __( 'How sure the match is. 90% and above is safe to approve; below 60% deserves a look first.', 'convertrack-click-conversion-analytics' ),
			'status404'          => __( 'New: not reviewed yet. Recommended: a redirect is suggested. Manual review: no safe suggestion. Approved or Auto redirected: visitors are now sent to the right page.', 'convertrack-click-conversion-analytics' ),
			'redirectHits'       => __( 'Visitors who were sent to the right page by a redirect created here.', 'convertrack-click-conversion-analytics' ),
			'validUrls'          => __( 'Pages on your site that Convertrack knows exist. Suggestions are picked from these.', 'convertrack-click-conversion-analytics' ),
			'recentHits'         => __( 'Broken-link hits inside the alert window. An email goes out when the count passes the limit.', 'convertrack-click-conversion-analytics' ),
			'mode404'            => __( 'Monitor only: just records. Recommend: suggests redirects for you to approve. Auto: creates redirects itself for high-confidence matches.', 'convertrack-click-conversion-analytics' ),
			'dryRun'             => __( 'Shows what would change without changing anything.', 'convertrack-click-conversion-analytics' ),
			'liveRun'            => __( 'Writes the changes to your pages. Every change can be undone.', 'convertrack-click-conversion-analytics' ),
			'seoTitle'           => __( 'The headline Google shows for your page in its search results.', 'convertrack-click-conversion-analytics' ),
			'focusKeyword'       => __( 'The search phrase Rank Math checks the page against.', 'convertrack-click-conversion-analytics' ),
			'sampleRate'         => __( 'The share of visitors that are tracked. 100% tracks everyone.', 'convertrack-click-conversion-analytics' ),
			'retention'          => __( 'How long detailed records are kept before they are deleted.', 'convertrack-click-conversion-analytics' ),
			'trackedElements'    => __( 'Which buttons and links count as clicks. The defaults cover links, buttons and form submits.', 'convertrack-click-conversion-analytics' ),
		);
		return $terms;
	}

	/**
	 * A small help icon that explains a term in plain language on hover,
	 * focus or tap. Pass a glossary key, or a key plus custom text.
	 *
	 * @param string $key  Glossary key.
	 * @param string $text Optional custom text (overrides the glossary).
	 * @return string Markup, already escaped. Empty when nothing to say.
	 */
	public static function help( $key, $text = '' ) {
		if ( '' === $text ) {
			$terms = self::glossary();
			$text  = isset( $terms[ $key ] ) ? $terms[ $key ] : '';
		}
		if ( '' === $text ) {
			return '';
		}
		return '<button type="button" class="cvtrk-help" data-cvtrk-help="' . esc_attr( $text ) . '" aria-label="' . esc_attr__( 'What does this mean?', 'convertrack-click-conversion-analytics' ) . '">'
			. '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6.6"/><path d="M6.1 6.4a1.95 1.95 0 1 1 2.8 1.75c-.6.3-.9.7-.9 1.3"/><path d="M8 11.6h.01"/></svg>'
			. '</button>';
	}

	/**
	 * "How to read this screen": a short, collapsible, plain-language guide
	 * shown at the top of a screen. The admin script remembers whether the
	 * user collapsed it.
	 *
	 * @param string $key   Screen key (remembered per screen).
	 * @param array  $guide { title?, lead?, points? (array of [term, text]), tip?, tipLabel? }.
	 */
	public static function render_guide( $key, array $guide ) {
		$key    = sanitize_key( $key );
		$title  = isset( $guide['title'] ) ? $guide['title'] : __( 'How to read this screen', 'convertrack-click-conversion-analytics' );
		$lead   = isset( $guide['lead'] ) ? $guide['lead'] : '';
		$points = isset( $guide['points'] ) && is_array( $guide['points'] ) ? $guide['points'] : array();
		$tip    = isset( $guide['tip'] ) ? $guide['tip'] : '';
		$label  = isset( $guide['tipLabel'] ) ? $guide['tipLabel'] : __( 'Start here:', 'convertrack-click-conversion-analytics' );
		?>
		<details class="cvtrk-guide" data-cvtrk-guide="<?php echo esc_attr( $key ); ?>" open>
			<summary>
				<span class="cvtrk-guide-mark" aria-hidden="true"><?php echo self::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cvtrk-guide-title"><?php echo esc_html( $title ); ?></span>
				<span class="cvtrk-guide-toggle" aria-hidden="true"><span class="is-open"><?php esc_html_e( 'Hide', 'convertrack-click-conversion-analytics' ); ?></span><span class="is-closed"><?php esc_html_e( 'Show', 'convertrack-click-conversion-analytics' ); ?></span></span>
			</summary>
			<div class="cvtrk-guide-body">
				<?php if ( '' !== $lead ) : ?>
					<p class="cvtrk-guide-lead"><?php echo esc_html( $lead ); ?></p>
				<?php endif; ?>
				<?php if ( $points ) : ?>
					<dl class="cvtrk-guide-points">
						<?php foreach ( $points as $point ) : ?>
							<div class="cvtrk-guide-point">
								<dt><?php echo esc_html( $point[0] ); ?></dt>
								<dd><?php echo esc_html( $point[1] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
				<?php if ( '' !== $tip ) : ?>
					<p class="cvtrk-guide-tip"><strong><?php echo esc_html( $label ); ?></strong> <?php echo esc_html( $tip ); ?></p>
				<?php endif; ?>
			</div>
		</details>
		<?php
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
			__( 'Dashboard', 'convertrack-click-conversion-analytics' ),
			__( 'Dashboard', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics', 'convertrack-click-conversion-analytics' ),
			__( 'Analytics', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-pages',
			array( $this, 'render_pages' )
		);

		add_submenu_page(
			null,
			__( 'Heatmaps', 'convertrack-click-conversion-analytics' ),
			__( 'Heatmaps', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-heatmaps',
			array( $this, 'render_heatmaps' )
		);

		add_submenu_page(
			null,
			__( 'Journeys', 'convertrack-click-conversion-analytics' ),
			__( 'Journeys', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-funnels',
			array( $this, 'render_funnels' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Search & SEO', 'convertrack-click-conversion-analytics' ),
			__( 'Search & SEO', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-gsc',
			array( $this, 'render_gsc' )
		);

		add_submenu_page(
			null,
			__( 'Keyword Opportunities', 'convertrack-click-conversion-analytics' ),
			__( 'Keyword Opportunities', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-gsc-keywords',
			array( $this, 'render_gsc_keywords' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Broken URLs', 'convertrack-click-conversion-analytics' ),
			__( 'Broken URLs', 'convertrack-click-conversion-analytics' ),
			'manage_options',
			'convertrack-404-monitor',
			array( $this, 'render_404_monitor' )
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
	 * Keep the Convertrack menu open while viewing a hidden local sub-screen.
	 *
	 * @param string $parent_file Current WordPress parent menu file.
	 * @return string
	 */
	public function highlight_parent_menu( $parent_file ) {
		$page = self::current_admin_page_slug();
		if ( in_array( $page, array( 'convertrack-heatmaps', 'convertrack-funnels', 'convertrack-gsc-keywords', 'convertrack-gsc-autoapply' ), true ) ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlight the grouped primary destination for hidden local sub-screens.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @param string $parent_file  Current parent menu file.
	 * @return string
	 */
	public function highlight_submenu( $submenu_file, $parent_file ) {
		if ( self::MENU_SLUG !== $parent_file ) {
			return $submenu_file;
		}

		$page = self::current_admin_page_slug();
		if ( in_array( $page, array( 'convertrack-heatmaps', 'convertrack-funnels' ), true ) ) {
			return 'convertrack-pages';
		}
		if ( in_array( $page, array( 'convertrack-gsc-keywords', 'convertrack-gsc-autoapply' ), true ) ) {
			return 'convertrack-gsc';
		}

		return $submenu_file;
	}

	/**
	 * Supply an accessible browser title for submenu pages hidden from the
	 * WordPress sidebar. WordPress otherwise renders these with a blank title.
	 *
	 * @param string $admin_title Complete browser title.
	 * @param string $title       Current admin screen title.
	 * @return string
	 */
	public function hidden_page_admin_title( $admin_title, $title ) {
		if ( '' !== trim( (string) $title ) ) {
			return $admin_title;
		}

		$page   = self::current_admin_page_slug();
		$titles = array(
			'convertrack-heatmaps'     => __( 'Heatmaps', 'convertrack-click-conversion-analytics' ),
			'convertrack-funnels'      => __( 'Journeys', 'convertrack-click-conversion-analytics' ),
			'convertrack-gsc-keywords' => __( 'Keyword Opportunities', 'convertrack-click-conversion-analytics' ),
			'convertrack-gsc-autoapply' => __( 'Keyword Auto-Apply', 'convertrack-click-conversion-analytics' ),
		);

		if ( ! isset( $titles[ $page ] ) ) {
			return $admin_title;
		}

		return $titles[ $page ] . ' ' . ltrim( $admin_title );
	}

	/**
	 * Return the current Convertrack admin page slug at every admin lifecycle
	 * stage, including the early menu-header filters.
	 *
	 * @return string
	 */
	private static function current_admin_page_slug() {
		if ( ! empty( $GLOBALS['plugin_page'] ) ) {
			return sanitize_key( $GLOBALS['plugin_page'] );
		}
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
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
				'sanitize_callback' => array( __NAMESPACE__ . '\\Settings', 'sanitize_registered' ),
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
		// The WordPress dashboard gets only the widget's own small stylesheet.
		// Loading the plugin's full admin CSS/JS on the busiest admin screen for
		// one server-rendered widget would not be a fair trade.
		if ( 'index.php' === $hook ) {
			if ( current_user_can( 'manage_options' ) ) {
				wp_enqueue_style(
					'convertrack-dashboard-widget',
					CONVERTRACK_URL . 'admin/css/dashboard-widget.css',
					array(),
					CONVERTRACK_VERSION
				);
			}
			return;
		}

		if ( false === strpos( $hook, 'convertrack' ) ) {
			return;
		}

		$admin_script_path    = plugin_dir_path( CONVERTRACK_FILE ) . 'admin/js/admin.js';
		$admin_script_version = file_exists( $admin_script_path ) ? filemtime( $admin_script_path ) : CONVERTRACK_VERSION;

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
			$admin_script_version,
			true
		);

		wp_localize_script(
			'convertrack-admin',
			'ConvertrackAdmin',
			array(
				'root'           => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'activeRefresh'  => 10000,
				'version'        => CONVERTRACK_VERSION,
				'logoUrl'        => esc_url_raw( CONVERTRACK_URL . 'admin/assets/convertrack-logo.svg' ),
				'adminUrls'      => array(
					'overview' => esc_url_raw( admin_url( 'admin.php?page=convertrack' ) ),
					'settings' => esc_url_raw( admin_url( 'admin.php?page=convertrack-settings' ) ),
					'gsc'      => esc_url_raw( admin_url( 'admin.php?page=convertrack-gsc' ) ),
					'gscKeywords' => esc_url_raw( admin_url( 'admin.php?page=convertrack-gsc-keywords' ) ),
					'notFound' => esc_url_raw( admin_url( 'admin.php?page=convertrack-404-monitor' ) ),
				),
				'terms'          => self::glossary(),
				'health'         => array(
					'wpVersion'  => get_bloginfo( 'version' ),
					'phpVersion' => PHP_VERSION,
				),
				'exportUrl'      => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_export' ) ),
				'exportNonce'    => wp_create_nonce( 'convertrack_export' ),
				'gscExportUrl'   => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_gsc_export' ) ),
				'gscExportNonce' => wp_create_nonce( 'convertrack_gsc_export' ),
				'notFoundExportUrl'   => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_404_export' ) ),
				'notFoundExportNonce' => wp_create_nonce( 'convertrack_404_export' ),
				'gscKeywordsExportUrl'   => esc_url_raw( admin_url( 'admin-post.php?action=convertrack_gsc_keywords_export' ) ),
				'gscKeywordsExportNonce' => wp_create_nonce( 'convertrack_gsc_keywords_export' ),
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
					'siteHealth'   => __( 'Site health at a glance', 'convertrack-click-conversion-analytics' ),
					'siteHealthSub' => __( 'Analytics, redirects, indexing, and plugin status in one place', 'convertrack-click-conversion-analytics' ),
					'analyticsActivity' => __( 'Analytics activity', 'convertrack-click-conversion-analytics' ),
					'pluginHealth' => __( 'Plugin health', 'convertrack-click-conversion-analytics' ),
					'operational'  => __( 'Operational', 'convertrack-click-conversion-analytics' ),
					'total404s'    => __( 'Total 404 URLs', 'convertrack-click-conversion-analytics' ),
					'activeRedirects' => __( 'Active redirects', 'convertrack-click-conversion-analytics' ),
					'pendingRecommendations' => __( 'Pending recommendations', 'convertrack-click-conversion-analytics' ),
					'sitemapStatus' => __( 'Sitemap status', 'convertrack-click-conversion-analytics' ),
					'lastScan'     => __( 'Last scan', 'convertrack-click-conversion-analytics' ),
					'trackingActive' => __( 'Tracking active', 'convertrack-click-conversion-analytics' ),
					'noActivityYet' => __( 'No activity yet', 'convertrack-click-conversion-analytics' ),
					'setupNeeded'  => __( 'Setup needed', 'convertrack-click-conversion-analytics' ),
					'notConnected' => __( 'Not connected', 'convertrack-click-conversion-analytics' ),
					'connected'    => __( 'Connected', 'convertrack-click-conversion-analytics' ),
					'never'        => __( 'Never', 'convertrack-click-conversion-analytics' ),
					'activityTrend' => __( 'Activity trend', 'convertrack-click-conversion-analytics' ),
					'pageVisit'    => __( 'Page visit', 'convertrack-click-conversion-analytics' ),
					'click'        => __( 'Click', 'convertrack-click-conversion-analytics' ),
					'scroll'       => __( 'Scroll', 'convertrack-click-conversion-analytics' ),
					'scrolls'      => __( 'Scrolls', 'convertrack-click-conversion-analytics' ),
					'event'        => __( 'Event', 'convertrack-click-conversion-analytics' ),
					'view'            => __( 'View', 'convertrack-click-conversion-analytics' ),
					'journey'         => __( 'Journey', 'convertrack-click-conversion-analytics' ),
					'byClickKind'     => __( 'By click kind', 'convertrack-click-conversion-analytics' ),
					'convertedClicks' => __( 'Converted clicks', 'convertrack-click-conversion-analytics' ),
					'ofConversions'   => __( 'Share', 'convertrack-click-conversion-analytics' ),
					'rule'            => __( 'Rule', 'convertrack-click-conversion-analytics' ),
					'showClicks'      => __( 'Show clicks', 'convertrack-click-conversion-analytics' ),
					'hideClicks'      => __( 'Hide clicks', 'convertrack-click-conversion-analytics' ),
					/* translators: 1: number of clicks shown, 2: total clicks of this kind. */
					'latestOf'        => __( 'Latest %1$s of %2$s', 'convertrack-click-conversion-analytics' ),
					'noConversions'   => __( 'No conversions in this range yet.', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of converting sessions. */
					'convertingSessionsCount' => __( '%1$s converting sessions', 'convertrack-click-conversion-analytics' ),
					/* translators: 1: converting sessions, 2: all sessions. */
					'ofSessions'      => __( '%1$s of %2$s sessions', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of sessions. */
					'sessionsCount'   => __( '%1$s sessions', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: average per day. */
					'avgPerDay'       => __( 'avg %1$s per day', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: percentage. */
					'ofPageviews'     => __( '%1$s of pageviews', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: percentage. */
					'ofClicks'        => __( '%1$s of clicks', 'convertrack-click-conversion-analytics' ),
					'scrollCheckpoints' => __( 'Scroll checkpoints', 'convertrack-click-conversion-analytics' ),
					'scrollHint'      => __( 'Scroll-depth events recorded alongside page visits', 'convertrack-click-conversion-analytics' ),
					'convertingSessions' => __( 'Converting sessions', 'convertrack-click-conversion-analytics' ),
					'peakDay'         => __( 'Peak day', 'convertrack-click-conversion-analytics' ),
					'unlabelledElement' => __( 'Unlabelled element', 'convertrack-click-conversion-analytics' ),
					'noConversionsHour' => __( 'none', 'convertrack-click-conversion-analytics' ),
					'helpWhat'        => __( 'What does this mean?', 'convertrack-click-conversion-analytics' ),
					'choosePage'      => __( 'Choose a page above, or press View details in the table, to see which of its buttons and links get pressed.', 'convertrack-click-conversion-analytics' ),
					'today'           => __( 'Today', 'convertrack-click-conversion-analytics' ),
					'yesterday'       => __( 'Yesterday', 'convertrack-click-conversion-analytics' ),
					'firstSeen'       => __( 'First seen', 'convertrack-click-conversion-analytics' ),
					'homePage'        => __( 'Home', 'convertrack-click-conversion-analytics' ),
					'pathLabel'       => __( 'Pages visited, in order', 'convertrack-click-conversion-analytics' ),
					'buttonOrLink'    => __( 'Button or link', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: percentage. */
					'scrolledThisFar' => __( '%1$s of visitors scrolled this far', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of visitors. */
					'visitorsSampled' => __( '%1$s visitors sampled', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of clicks. */
					'clicksPlotted'   => __( '%1$s clicks plotted. Brighter spots mean more clicks in the same place.', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of clicks. */
					'confidenceHigh'  => __( 'High confidence · %1$s clicks in this view', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of clicks. */
					'confidenceModerate' => __( 'Moderate confidence · %1$s clicks in this view', 'convertrack-click-conversion-analytics' ),
					/* translators: %1$s: number of clicks. */
					'confidenceLow'   => __( 'Low confidence · only %1$s clicks in this view', 'convertrack-click-conversion-analytics' ),
					'confSafe'        => __( 'Safe to approve', 'convertrack-click-conversion-analytics' ),
					'confCheck'       => __( 'Worth a look before approving', 'convertrack-click-conversion-analytics' ),
					'confLow'         => __( 'Weak match', 'convertrack-click-conversion-analytics' ),
					'postType'        => __( 'Type', 'convertrack-click-conversion-analytics' ),
					'lastChecked'     => __( 'Last checked', 'convertrack-click-conversion-analytics' ),
					'gscGroupIndexedDesc' => __( 'Google shows these pages in its search results.', 'convertrack-click-conversion-analytics' ),
					'gscGroupPendingDesc' => __( 'Google knows these pages but has not listed them yet. New pages usually sit here for a while.', 'convertrack-click-conversion-analytics' ),
					'gscGroupIssuesDesc'  => __( 'Google decided not to list these pages. Each one has a reason, shown below.', 'convertrack-click-conversion-analytics' ),
					'nfModeLabels'    => array(
						'monitor'              => __( 'Monitor only', 'convertrack-click-conversion-analytics' ),
						'recommend'            => __( 'Recommend only (you approve)', 'convertrack-click-conversion-analytics' ),
						'manual'               => __( 'Manual approval', 'convertrack-click-conversion-analytics' ),
						'auto_high_confidence' => __( 'Auto redirect (high confidence)', 'convertrack-click-conversion-analytics' ),
					),
					'nfMatchReasons'  => array(
						'exact_path'             => __( 'Exact match', 'convertrack-click-conversion-analytics' ),
						'exact_slug'             => __( 'Same page name', 'convertrack-click-conversion-analytics' ),
						'renamed_slug'           => __( 'Renamed page', 'convertrack-click-conversion-analytics' ),
						'renamed_slug_ambiguous' => __( 'Renamed page (unsure)', 'convertrack-click-conversion-analytics' ),
						'renamed_date'           => __( 'Moved from a dated address', 'convertrack-click-conversion-analytics' ),
						'normalized_path'        => __( 'Same address, tidied up', 'convertrack-click-conversion-analytics' ),
						'page_path'              => __( 'Matching page address', 'convertrack-click-conversion-analytics' ),
						'ambiguous_slug'         => __( 'Similar name (unsure)', 'convertrack-click-conversion-analytics' ),
						'token_subset'           => __( 'Shares the key words', 'convertrack-click-conversion-analytics' ),
						'token_subset_ambiguous' => __( 'Shares some words (unsure)', 'convertrack-click-conversion-analytics' ),
						'unpublished_parent'     => __( 'Parent of an unpublished page', 'convertrack-click-conversion-analytics' ),
						'similar_keywords'       => __( 'Similar words', 'convertrack-click-conversion-analytics' ),
						'partial_slug'           => __( 'Partly matching name', 'convertrack-click-conversion-analytics' ),
						'post_type_match'        => __( 'Same kind of content', 'convertrack-click-conversion-analytics' ),
						'parent_page_match'      => __( 'Parent page', 'convertrack-click-conversion-analytics' ),
						'archive_match'          => __( 'Category or archive page', 'convertrack-click-conversion-analytics' ),
						'fallback'               => __( 'Fallback page (no real match)', 'convertrack-click-conversion-analytics' ),
						'unpublished_source'     => __( 'The page was unpublished', 'convertrack-click-conversion-analytics' ),
					),
					'rightScale'      => __( 'right scale', 'convertrack-click-conversion-analytics' ),
					'busiestHour'     => __( 'Busiest hour', 'convertrack-click-conversion-analytics' ),
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
					'gscColReason'        => __( 'Reason', 'convertrack-click-conversion-analytics' ),
					'gscColFix'           => __( 'Suggested fix', 'convertrack-click-conversion-analytics' ),
					'gscColSource'        => __( 'Source', 'convertrack-click-conversion-analytics' ),
					'gscColValidation'    => __( 'Validation', 'convertrack-click-conversion-analytics' ),
					'gscColUrls'          => __( 'URLs', 'convertrack-click-conversion-analytics' ),
					'gscColFixable'       => __( 'Fixable', 'convertrack-click-conversion-analytics' ),
					'gscOwnerSite'        => __( 'Website', 'convertrack-click-conversion-analytics' ),
					'gscOwnerGoogle'      => __( 'Google systems', 'convertrack-click-conversion-analytics' ),
					'gscValNotStarted'    => __( 'Not started', 'convertrack-click-conversion-analytics' ),
					'gscValStarted'       => __( 'Started', 'convertrack-click-conversion-analytics' ),
					'gscValPassed'        => __( 'Passed', 'convertrack-click-conversion-analytics' ),
					'gscValFailed'        => __( 'Failed', 'convertrack-click-conversion-analytics' ),
					'gscShowUrls'         => __( 'Show URLs', 'convertrack-click-conversion-analytics' ),
					'gscFixAll'           => __( 'Fix', 'convertrack-click-conversion-analytics' ),
					'gscNoReasons'        => __( 'No coverage problems found in the URLs inspected so far.', 'convertrack-click-conversion-analytics' ),
					'gscReasonNote'       => __( 'Based on the URLs this plugin has inspected', 'convertrack-click-conversion-analytics' ),
					'gscReasonNoteTwo'    => __( 'Validation tracks whether the fix applied here worked; it is not Google’s own validation state. Totals will differ from Search Console, which reports on every URL Google has ever seen.', 'convertrack-click-conversion-analytics' ),
					'gscNoAutoFix'        => __( 'No automatic fix for this.', 'convertrack-click-conversion-analytics' ),
					'gscFixApplied'       => __( 'Fix applied. The URL will be re-checked to confirm it worked.', 'convertrack-click-conversion-analytics' ),
					'gscFixFailed'        => __( 'Still reported after re-checking. Needs a manual look.', 'convertrack-click-conversion-analytics' ),
					'gscFixRedirect'      => __( 'Create redirect', 'convertrack-click-conversion-analytics' ),
					'gscFixNoindex'       => __( 'Remove noindex', 'convertrack-click-conversion-analytics' ),
					'gscFixRobots'        => __( 'Add Allow rule', 'convertrack-click-conversion-analytics' ),
					'gscFixCanonical'     => __( 'Use Google’s canonical', 'convertrack-click-conversion-analytics' ),
					'gscFixChain'         => __( 'Flatten redirect', 'convertrack-click-conversion-analytics' ),
					'gscFixIndexing'      => __( 'Request indexing', 'convertrack-click-conversion-analytics' ),
					'gscBulkFixConfirm'   => __( 'Apply the suggested fix to every URL with this problem?', 'convertrack-click-conversion-analytics' ),
					'gscBulkFixDone'      => __( 'Applied', 'convertrack-click-conversion-analytics' ),
					'gscBulkFixErrors'    => __( 'could not be applied', 'convertrack-click-conversion-analytics' ),
					'gscBulkFixRemaining' => __( 'still to go — run it again to continue.', 'convertrack-click-conversion-analytics' ),
					'gscNoInspectLinks'  => __( 'No URLs with Search Console links in the current view. Adjust the filters above first.', 'convertrack-click-conversion-analytics' ),
					'gscTabsOpened'      => __( 'Opened in Search Console:', 'convertrack-click-conversion-analytics' ),
					'gscTabsHint'        => __( 'Click "Request Indexing" in each tab.', 'convertrack-click-conversion-analytics' ),
					'gscTabsBlocked'     => __( 'Your browser blocked some tabs — allow pop-ups for this site and click again. Opened:', 'convertrack-click-conversion-analytics' ),
					'kwTotalKeywords'    => __( 'Searches tracked', 'convertrack-click-conversion-analytics' ),
					'kwHighOpportunity'  => __( 'High opportunity', 'convertrack-click-conversion-analytics' ),
					'kwPagesMissing'     => __( 'Pages missing their keywords', 'convertrack-click-conversion-analytics' ),
					'kwPageTwo'          => __( 'Stuck on page two (position 11-20)', 'convertrack-click-conversion-analytics' ),
					'kwLowCtr'           => __( 'Seen often, rarely clicked', 'convertrack-click-conversion-analytics' ),
					'kwBranded'          => __( 'Branded', 'convertrack-click-conversion-analytics' ),
					'kwNonBranded'       => __( 'Non-branded', 'convertrack-click-conversion-analytics' ),
					'kwLastSync'         => __( 'Last keyword sync', 'convertrack-click-conversion-analytics' ),
					'kwLastAnalysis'     => __( 'Last content analysis', 'convertrack-click-conversion-analytics' ),
					'kwPendingAnalysis'  => __( 'Keywords awaiting analysis', 'convertrack-click-conversion-analytics' ),
					'kwSyncRunning'      => __( 'Syncing keyword data from Search Console…', 'convertrack-click-conversion-analytics' ),
					'kwSyncDone'         => __( 'Keyword sync complete. Content analysis continues in the background.', 'convertrack-click-conversion-analytics' ),
					'kwSyncFailed'       => __( 'Keyword sync failed:', 'convertrack-click-conversion-analytics' ),
					'kwSyncQueued'       => __( 'Keyword sync started…', 'convertrack-click-conversion-analytics' ),
					'kwAnalyzeQueued'    => __( 'Re-analysis queued for', 'convertrack-click-conversion-analytics' ),
					'kwRowsLabel'        => __( 'keywords', 'convertrack-click-conversion-analytics' ),
					'kwNotConnected'     => __( 'Connect Google Search Console on the Indexing screen to collect keyword data.', 'convertrack-click-conversion-analytics' ),
					'kwDisabled'         => __( 'Enable Keyword Insights in the settings below, save, then run your first sync.', 'convertrack-click-conversion-analytics' ),
					'kwNeverSynced'      => __( 'No keyword data yet. Run your first sync to import queries from Search Console.', 'convertrack-click-conversion-analytics' ),
					'kwSyncFirst'        => __( 'Sync now', 'convertrack-click-conversion-analytics' ),
					'kwNoResults'        => __( 'No keywords match the current filters.', 'convertrack-click-conversion-analytics' ),
					'kwSelectRows'       => __( 'Choose at least one keyword first.', 'convertrack-click-conversion-analytics' ),
					'kwBulkQueued'       => __( 'Queued for re-analysis:', 'convertrack-click-conversion-analytics' ),
					'kwDetails'          => __( 'Details', 'convertrack-click-conversion-analytics' ),
					'kwReanalyze'        => __( 'Re-analyze', 'convertrack-click-conversion-analytics' ),
					'kwKeyword'          => __( 'Keyword', 'convertrack-click-conversion-analytics' ),
					'kwTypes'            => __( 'Type', 'convertrack-click-conversion-analytics' ),
					'kwImpressions'      => __( 'Impressions', 'convertrack-click-conversion-analytics' ),
					'kwCtrShort'         => __( 'CTR', 'convertrack-click-conversion-analytics' ),
					'kwPosition'         => __( 'Position', 'convertrack-click-conversion-analytics' ),
					'kwPresence'         => __( 'Presence', 'convertrack-click-conversion-analytics' ),
					'kwOpportunity'      => __( 'Opportunity', 'convertrack-click-conversion-analytics' ),
					'kwAction'           => __( 'Recommended action', 'convertrack-click-conversion-analytics' ),
					'kwAnalyzed'         => __( 'Analyzed', 'convertrack-click-conversion-analytics' ),
					'kwPresent'          => __( 'Present', 'convertrack-click-conversion-analytics' ),
					'kwPartial'          => __( 'Partial', 'convertrack-click-conversion-analytics' ),
					'kwMissing'          => __( 'Missing', 'convertrack-click-conversion-analytics' ),
					'kwOverused'         => __( 'Overused', 'convertrack-click-conversion-analytics' ),
					'kwNeedsImprovement' => __( 'Needs improvement', 'convertrack-click-conversion-analytics' ),
					'kwUnknown'          => __( 'Not analyzable', 'convertrack-click-conversion-analytics' ),
					'kwOppHigh'          => __( 'High', 'convertrack-click-conversion-analytics' ),
					'kwOppMedium'        => __( 'Medium', 'convertrack-click-conversion-analytics' ),
					'kwOppLow'           => __( 'Low', 'convertrack-click-conversion-analytics' ),
					'kwOppOptimized'     => __( 'Optimized', 'convertrack-click-conversion-analytics' ),
					'kwQueuedForAnalysis' => __( 'Queued for analysis', 'convertrack-click-conversion-analytics' ),
					'kwIncluded'         => __( 'Included on page', 'convertrack-click-conversion-analytics' ),
					'kwPartialGroup'     => __( 'Partially covered', 'convertrack-click-conversion-analytics' ),
					'kwMissingGroup'     => __( 'Missing from page', 'convertrack-click-conversion-analytics' ),
					'kwPageRecs'         => __( 'Recommended actions for this page', 'convertrack-click-conversion-analytics' ),
					'kwPlacements'       => __( 'Recommended placements', 'convertrack-click-conversion-analytics' ),
					'kwFaq'              => __( 'Suggested FAQ questions', 'convertrack-click-conversion-analytics' ),
					'kwAnchors'          => __( 'Suggested internal link anchor texts', 'convertrack-click-conversion-analytics' ),
					'kwTitleMeta'        => __( 'Current SEO title & description', 'convertrack-click-conversion-analytics' ),
					'kwAreas'            => __( 'Content area coverage', 'convertrack-click-conversion-analytics' ),
					'kwEditPage'         => __( 'Edit page', 'convertrack-click-conversion-analytics' ),
					'kwDetailFailed'     => __( 'Could not load the page detail:', 'convertrack-click-conversion-analytics' ),
					'kwTruncated'        => __( 'Row cap reached — the least-clicked queries were skipped.', 'convertrack-click-conversion-analytics' ),
					'kwCustomHint'       => __( 'Pick both dates, then press "Sync range" to pull that period from Search Console.', 'convertrack-click-conversion-analytics' ),
					'kwAreaSeoTitle'     => __( 'SEO title', 'convertrack-click-conversion-analytics' ),
					'kwAreaMetaDescription' => __( 'Meta description', 'convertrack-click-conversion-analytics' ),
					'kwAreaH1'           => __( 'H1 heading', 'convertrack-click-conversion-analytics' ),
					'kwAreaHeadings'     => __( 'H2/H3 headings', 'convertrack-click-conversion-analytics' ),
					'kwAreaFirstParagraph' => __( 'First paragraph', 'convertrack-click-conversion-analytics' ),
					'kwAreaBody'         => __( 'Body content', 'convertrack-click-conversion-analytics' ),
					'kwAreaImageAlts'    => __( 'Image alt text', 'convertrack-click-conversion-analytics' ),
					'kwAreaAnchorTexts'  => __( 'Internal anchor text', 'convertrack-click-conversion-analytics' ),
					'kwAreaUrlSlug'      => __( 'URL slug', 'convertrack-click-conversion-analytics' ),
					'kwTypeBranded'      => __( 'Branded', 'convertrack-click-conversion-analytics' ),
					'kwTypeNonBranded'   => __( 'Non-branded', 'convertrack-click-conversion-analytics' ),
					'kwTypeService'      => __( 'Service', 'convertrack-click-conversion-analytics' ),
					'kwTypeProduct'      => __( 'Product', 'convertrack-click-conversion-analytics' ),
					'kwTypeLocation'     => __( 'Location', 'convertrack-click-conversion-analytics' ),
					'kwTypeCommercial'   => __( 'Commercial', 'convertrack-click-conversion-analytics' ),
					'kwTypeInformational' => __( 'Informational', 'convertrack-click-conversion-analytics' ),
					'kwTypeTransactional' => __( 'Transactional', 'convertrack-click-conversion-analytics' ),
					'kwTypeNavigational' => __( 'Navigational', 'convertrack-click-conversion-analytics' ),
					'kwTypeQuestion'     => __( 'Question', 'convertrack-click-conversion-analytics' ),
					'kwTypeLongTail'     => __( 'Long-tail', 'convertrack-click-conversion-analytics' ),
					'kwTypeCompetitor'   => __( 'Competitor', 'convertrack-click-conversion-analytics' ),
					'kwPromptEnableTitle'   => __( 'Turn on Keyword Insights', 'convertrack-click-conversion-analytics' ),
					'kwPromptEnableBody'    => __( 'This feature is off. Enable it to pull your Search Console keywords and get on-page content recommendations — no need to open the settings below.', 'convertrack-click-conversion-analytics' ),
					'kwPromptEnableBtn'     => __( 'Enable & sync now', 'convertrack-click-conversion-analytics' ),
					'kwPromptDismiss'       => __( 'Not now', 'convertrack-click-conversion-analytics' ),
					'kwPromptConnectTitle'  => __( 'Connect Google Search Console', 'convertrack-click-conversion-analytics' ),
					'kwPromptConnectBody'   => __( 'Keyword Insights needs your Search Console connection to pull keyword data. Connect Google first, then enable this feature.', 'convertrack-click-conversion-analytics' ),
					'kwPromptConnectBtn'    => __( 'Open the Indexing screen', 'convertrack-click-conversion-analytics' ),
					'kwPromptEnableAnyway'  => __( 'Enable anyway', 'convertrack-click-conversion-analytics' ),
					'kwEnabling'            => __( 'Enabling…', 'convertrack-click-conversion-analytics' ),
					'kwEnabledMsg'          => __( 'Keyword Insights enabled.', 'convertrack-click-conversion-analytics' ),
					'kwEnableFailed'        => __( 'Could not enable Keyword Insights:', 'convertrack-click-conversion-analytics' ),
					'kwClose'               => __( 'Close', 'convertrack-click-conversion-analytics' ),
					'loadError'             => __( 'Something went wrong while loading this data.', 'convertrack-click-conversion-analytics' ),
					'noAttentionNeeded'     => __( 'No setup or health issues need attention right now.', 'convertrack-click-conversion-analytics' ),
					'pageWord'              => __( 'Page', 'convertrack-click-conversion-analytics' ),
					'kwSelected'            => __( 'selected', 'convertrack-click-conversion-analytics' ),
					'kwNoneSelected'        => __( 'No keywords selected', 'convertrack-click-conversion-analytics' ),
					'kwSelectRow'           => __( 'Select keyword', 'convertrack-click-conversion-analytics' ),
					'kwSelectAll'           => __( 'Select all keywords on this page', 'convertrack-click-conversion-analytics' ),
					'kwStatusLost'          => __( 'Lost contact with the sync status endpoint. The sync may still be running in the background — refresh to check.', 'convertrack-click-conversion-analytics' ),
					'nfTotal'               => __( 'Broken addresses', 'convertrack-click-conversion-analytics' ),
					'nfNew'                 => __( 'New, not reviewed', 'convertrack-click-conversion-analytics' ),
					'nfPending'             => __( 'Awaiting your approval', 'convertrack-click-conversion-analytics' ),
					'nfResolved'            => __( 'Fixed by a redirect', 'convertrack-click-conversion-analytics' ),
					'nfManual'              => __( 'Need your decision', 'convertrack-click-conversion-analytics' ),
					'nfIgnored'             => __( 'Ignored', 'convertrack-click-conversion-analytics' ),
					'nfMode'                => __( 'Mode', 'convertrack-click-conversion-analytics' ),
					'nfRedirectHits'        => __( 'Visitors redirected', 'convertrack-click-conversion-analytics' ),
					'nfValidUrls'           => __( 'Known good pages', 'convertrack-click-conversion-analytics' ),
					'nfRecentHits'          => __( 'Recent 404 hits', 'convertrack-click-conversion-analytics' ),
					'nfToolDetected'        => __( 'Redirect tool detected', 'convertrack-click-conversion-analytics' ),
					'nfSelected'            => __( 'selected', 'convertrack-click-conversion-analytics' ),
					'nfNoneSelected'        => __( 'No rows selected', 'convertrack-click-conversion-analytics' ),
					'nfSelectRow'           => __( 'Select 404 row', 'convertrack-click-conversion-analytics' ),
					'nfSelectAll'           => __( 'Select all rows on this page', 'convertrack-click-conversion-analytics' ),
					'nfApprove'             => __( 'Approve', 'convertrack-click-conversion-analytics' ),
					'nfEdit'                => __( 'Edit', 'convertrack-click-conversion-analytics' ),
					'nfIgnore'              => __( 'Ignore', 'convertrack-click-conversion-analytics' ),
					'nfDelete'              => __( 'Delete', 'convertrack-click-conversion-analytics' ),
					'nfDetails'             => __( 'Details', 'convertrack-click-conversion-analytics' ),
					'nfNoRows'              => __( 'No 404 rows match the current filters.', 'convertrack-click-conversion-analytics' ),
					'nfFirstSeen'           => __( 'First', 'convertrack-click-conversion-analytics' ),
					'nfBulkDeleteConfirm'   => __( 'Delete the selected 404 rows?', 'convertrack-click-conversion-analytics' ),
					'nfBulkUpdated'         => __( 'Bulk action updated', 'convertrack-click-conversion-analytics' ),
					'nfBulkRows'            => __( 'rows', 'convertrack-click-conversion-analytics' ),
					'nfBulkWith'            => __( 'with', 'convertrack-click-conversion-analytics' ),
					'nfBulkErrors'          => __( 'errors', 'convertrack-click-conversion-analytics' ),
					'nfBulkRemaining'       => __( 'more still match - run the action again to continue.', 'convertrack-click-conversion-analytics' ),
					'nfNoConflicts'         => __( 'No redirect conflicts. Every URL with a redirect rule is being redirected.', 'convertrack-click-conversion-analytics' ),
					'nfConflictPending'     => __( 'Awaiting a background re-check to confirm the cause.', 'convertrack-click-conversion-analytics' ),
					'nfColCause'            => __( 'Cause', 'convertrack-click-conversion-analytics' ),
					'nfColRuleFrom'         => __( 'Rule from', 'convertrack-click-conversion-analytics' ),
					'nfDiagnose'            => __( 'Re-check', 'convertrack-click-conversion-analytics' ),
					'nfOpenSettings'        => __( 'Open settings', 'convertrack-click-conversion-analytics' ),
					'redirectConflicts'     => __( 'Redirect conflicts', 'convertrack-click-conversion-analytics' ),
					'redirectConflictsMeta' => __( 'Rules exist but these URLs still return 404', 'convertrack-click-conversion-analytics' ),
					'nfColUrl'              => __( '404 URL', 'convertrack-click-conversion-analytics' ),
					'nfColReferrer'         => __( 'Referrer', 'convertrack-click-conversion-analytics' ),
					'nfColDetected'         => __( 'Detected', 'convertrack-click-conversion-analytics' ),
					'nfColHits'             => __( 'Hits', 'convertrack-click-conversion-analytics' ),
					'nfColSuggestion'       => __( 'Suggested redirect', 'convertrack-click-conversion-analytics' ),
					'nfColConfidence'       => __( 'Confidence', 'convertrack-click-conversion-analytics' ),
					'nfColStatus'           => __( 'Status', 'convertrack-click-conversion-analytics' ),
					'nfColActions'          => __( 'Actions', 'convertrack-click-conversion-analytics' ),
					'nfDetailSource'        => __( '404 URL', 'convertrack-click-conversion-analytics' ),
					'nfDetailReferrer'      => __( 'Referrer', 'convertrack-click-conversion-analytics' ),
					'nfDetailFirst'         => __( 'First detected', 'convertrack-click-conversion-analytics' ),
					'nfDetailLast'          => __( 'Last detected', 'convertrack-click-conversion-analytics' ),
					'nfDetailHits'          => __( 'Hit count', 'convertrack-click-conversion-analytics' ),
					'nfDetailSuggested'     => __( 'Suggested redirect', 'convertrack-click-conversion-analytics' ),
					'nfDetailConfidence'    => __( 'Match confidence', 'convertrack-click-conversion-analytics' ),
					'nfDetailReason'        => __( 'Match reason', 'convertrack-click-conversion-analytics' ),
					'nfDetailPostType'      => __( 'Suggested post type', 'convertrack-click-conversion-analytics' ),
					'nfDetailStatus'        => __( 'Status', 'convertrack-click-conversion-analytics' ),
					'nfDetailError'         => __( 'Last error', 'convertrack-click-conversion-analytics' ),
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

	public function render_gsc_keywords() {
		$this->render_view( 'gsc-keywords' );
	}

	public function render_404_monitor() {
		$this->render_view( '404-monitor' );
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
