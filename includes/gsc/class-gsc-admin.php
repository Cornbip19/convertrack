<?php
/**
 * Admin handlers for Google Index Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Admin {

	/**
	 * Register admin-post handlers.
	 */
	public function register() {
		add_action( 'admin_post_convertrack_gsc_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_convertrack_gsc_save_client', array( $this, 'save_client' ) );
		add_action( 'admin_post_convertrack_gsc_oauth_start', array( $this, 'oauth_start' ) );
		add_action( 'admin_post_convertrack_gsc_oauth_callback', array( $this, 'oauth_callback' ) );
		add_action( 'admin_post_convertrack_gsc_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_convertrack_gsc_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );
		add_action( 'admin_notices', array( $this, 'reconnect_notice' ) );
	}

	/**
	 * Save GSC settings.
	 */
	public function save_settings() {
		$this->check_admin_action( 'convertrack_gsc_save_settings' );

		$input = isset( $_POST['convertrack_gsc_settings'] ) && is_array( $_POST['convertrack_gsc_settings'] )
			? wp_unslash( $_POST['convertrack_gsc_settings'] )
			: array();

		Settings::save( $input );

		if ( Credentials::is_connected() ) {
			$verify = API::verify_property();
			if ( is_wp_error( $verify ) && in_array( $verify->get_error_code(), array( 'convertrack_gsc_property_not_found', 'convertrack_gsc_property_unverified' ), true ) ) {
				Logger::warning( 'settings', 'Settings saved, but the property failed verification.', array( 'error' => $verify->get_error_message() ) );
				$this->redirect( 'settings-property-warning', $verify->get_error_message() );
			}
		}

		Logger::info( 'settings', 'Google Index Monitor settings saved.' );
		$this->redirect( 'settings-saved' );
	}

	/**
	 * Save the site owner's Google OAuth client credentials.
	 */
	public function save_client() {
		$this->check_admin_action( 'convertrack_gsc_save_client' );

		$client_id     = isset( $_POST['convertrack_gsc_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['convertrack_gsc_client_id'] ) ) : '';
		$client_secret = isset( $_POST['convertrack_gsc_client_secret'] ) ? trim( (string) wp_unslash( $_POST['convertrack_gsc_client_secret'] ) ) : '';

		// The secret is never echoed back to the form, so an empty submission
		// means "keep the stored one".
		if ( '' === $client_secret ) {
			$client_secret = Credentials::client_secret();
		}

		$stored = Credentials::store_client( $client_id, $client_secret );
		if ( is_wp_error( $stored ) ) {
			$this->redirect( 'client-error', $stored->get_error_message() );
		}

		Logger::info( 'oauth', 'Google OAuth client credentials saved.' );
		$this->redirect( 'client-saved' );
	}

	/**
	 * Start OAuth flow.
	 */
	public function oauth_start() {
		$this->check_admin_action( 'convertrack_gsc_oauth_start' );

		if ( ! Credentials::has_client() ) {
			$this->redirect( 'oauth-error', __( 'Enter and save your Google OAuth Client ID and Secret before connecting.', 'convertrack-click-conversion-analytics' ) );
		}

		$state    = wp_generate_password( 32, false, false );
		$verifier = wp_generate_password( 64, false, false );
		set_transient( self::state_key(), array( 'state' => $state, 'verifier' => $verifier ), 10 * MINUTE_IN_SECONDS );

		$url = OAuth::connect_url( OAuth::redirect_uri(), $state, OAuth::code_challenge( $verifier ) );
		if ( is_wp_error( $url ) ) {
			$this->redirect( 'oauth-error', $url->get_error_message() );
		}

		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * OAuth callback.
	 */
	public function oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}

		$stored = get_transient( self::state_key() );
		delete_transient( self::state_key() );
		$state          = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected_state = is_array( $stored ) && isset( $stored['state'] ) ? (string) $stored['state'] : '';
		$verifier       = is_array( $stored ) && isset( $stored['verifier'] ) ? (string) $stored['verifier'] : '';

		if ( '' === $expected_state || ! hash_equals( $expected_state, (string) $state ) ) {
			// A duplicate or back-button hit of the callback after a successful
			// connect: don't show a scary error for what already worked.
			if ( ! isset( $_GET['error'] ) && Credentials::is_connected() ) {
				$this->redirect( 'oauth-connected' );
			}
			Logger::error( 'oauth', 'OAuth callback failed state validation.' );
			$this->redirect( 'oauth-error', __( 'Invalid OAuth state. Please try connecting again.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			Logger::error( 'oauth', 'Google returned an OAuth error.', array( 'error' => $error ) );
			$this->redirect( 'oauth-error', $error );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect( 'oauth-error', __( 'The connection did not complete. Please try again.', 'convertrack-click-conversion-analytics' ) );
		}

		$result = OAuth::exchange_code( $code, $verifier, OAuth::redirect_uri() );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'oauth-error', $result->get_error_message() );
		}

		// Connection succeeded; the reconnect prompt (if any) no longer applies.
		delete_transient( 'convertrack_gsc_reconnect_required' );

		// Adopt the account's matching property when the setting is still the
		// default or provably wrong, so verify_property() checks the right one.
		$chosen = $this->maybe_adopt_property();

		// Warn (without failing) only for genuine ownership mismatches; a transient
		// network/5xx error from the verify call shouldn't masquerade as one.
		$verify = API::verify_property();
		if ( is_wp_error( $verify ) ) {
			Logger::warning( 'oauth', 'Connected, but property verification failed.', array( 'error' => $verify->get_error_message() ) );
			if ( in_array( $verify->get_error_code(), array( 'convertrack_gsc_property_not_found', 'convertrack_gsc_property_unverified' ), true ) ) {
				$this->redirect( 'oauth-property-warning', $verify->get_error_message() );
			}
		}

		$this->redirect( 'oauth-connected', $chosen );
	}

	/**
	 * After a successful connect, point the property setting at the account
	 * property that matches this site — but never clobber a deliberate,
	 * valid choice.
	 *
	 * @return string The adopted property, or '' when nothing changed.
	 */
	private function maybe_adopt_property() {
		$sites = API::list_sites();
		if ( is_wp_error( $sites ) || empty( $sites ) ) {
			if ( is_wp_error( $sites ) ) {
				Logger::warning( 'oauth', 'Could not list Search Console properties after connect.', array( 'error' => $sites->get_error_message() ) );
			}
			return '';
		}

		$verified = array();
		foreach ( $sites as $site ) {
			if ( isset( $site['permissionLevel'] ) && 'siteUnverifiedUser' === $site['permissionLevel'] ) {
				continue;
			}
			$verified[] = (string) $site['siteUrl'];
		}
		if ( empty( $verified ) ) {
			return '';
		}

		$current  = (string) Settings::get( 'property_url' );
		$default  = trailingslashit( home_url( '/' ) );
		$site_all = wp_list_pluck( $sites, 'siteUrl' );

		// The user already picked a property the account can access — keep it.
		if ( '' !== $current && $current !== $default && in_array( $current, $site_all, true ) ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$bare = preg_replace( '/^www\./', '', $host );

		$candidates = array(
			'sc-domain:' . $bare,
			$default,
			'https://' . $host . '/',
			'https://' . $bare . '/',
			'https://www.' . $bare . '/',
			'http://' . $host . '/',
			'http://' . $bare . '/',
			'http://www.' . $bare . '/',
		);

		foreach ( $candidates as $candidate ) {
			if ( ! in_array( $candidate, $verified, true ) || $candidate === $current ) {
				continue;
			}
			Settings::save( array_merge( Settings::all(), array( 'property_url' => $candidate ) ) );
			Logger::info( 'oauth', 'Search Console property auto-selected after connect.', array( 'property' => $candidate, 'previous' => $current ) );
			return $candidate;
		}

		return '';
	}

	/**
	 * Disconnect OAuth tokens.
	 */
	public function disconnect() {
		$this->check_admin_action( 'convertrack_gsc_disconnect' );
		OAuth::revoke();
		Credentials::clear_tokens();
		Logger::info( 'oauth', 'Google Search Console disconnected.' );
		$this->redirect( 'oauth-disconnected' );
	}

	/**
	 * Export URL queue CSV.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'convertrack_gsc_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'convertrack-click-conversion-analytics' ) );
		}

		$args = array(
			'page'      => 1,
			'per_page'  => 100,
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
			'post_type' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'all',
			'priority'  => isset( $_GET['priority'] ) ? sanitize_text_field( wp_unslash( $_GET['priority'] ) ) : '',
			'sitemap_hash' => isset( $_GET['sitemap_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['sitemap_hash'] ) ) : '',
			'checked_from' => isset( $_GET['checked_from'] ) ? sanitize_text_field( wp_unslash( $_GET['checked_from'] ) ) : '',
			'checked_to'   => isset( $_GET['checked_to'] ) ? sanitize_text_field( wp_unslash( $_GET['checked_to'] ) ) : '',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=convertrack-gsc-index-monitor-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'URL', 'Post ID', 'Post Type', 'Index Status', 'Coverage State', 'Google Verdict', 'Last Checked', 'Next Check', 'Attempts', 'Priority', 'Sitemap URL', 'Error' ) );

		do {
			$data = Database::list_urls( $args );
			foreach ( $data['rows'] as $row ) {
				fputcsv(
					$out,
					array(
						$row['url'],
						(int) $row['post_id'],
						$row['post_type'],
						$row['index_status'],
						$row['coverage_state'],
						$row['google_verdict'],
						$row['last_checked_at'],
						$row['next_check_at'],
						(int) $row['attempt_count'],
						(int) $row['priority'],
						$row['sitemap_url'],
						$row['error_message'],
					)
				);
			}
			$args['page']++;
		} while ( $args['page'] <= $data['pages'] );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Display migration failures.
	 */
	public function migration_notice() {
		$error = get_transient( 'convertrack_gsc_migration_error' );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( sprintf( __( 'Convertrack Google Index Monitor migration failed: %s', 'convertrack-click-conversion-analytics' ), $error ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prompt the admin to reconnect after Google has revoked or expired the grant.
	 */
	public function reconnect_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'convertrack_gsc_reconnect_required' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=convertrack-gsc' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Search Console settings page URL. */
						__( 'Convertrack lost its Google Search Console connection — Google revoked or expired it. <a href="%s">Reconnect Search Console</a>.', 'convertrack-click-conversion-analytics' ),
						esc_url( $url )
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * OAuth callback URL.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return admin_url( 'admin-post.php?action=convertrack_gsc_oauth_callback' );
	}

	/**
	 * Check capability and nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private function check_admin_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the GSC screen.
	 *
	 * @param string $notice Notice key.
	 * @param string $detail Optional detail.
	 */
	private function redirect( $notice, $detail = '' ) {
		$args = array(
			'page'             => 'convertrack-gsc',
			'cvtrk_gsc_notice' => sanitize_key( $notice ),
		);
		if ( '' !== $detail ) {
			$args['cvtrk_gsc_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * OAuth state key scoped to the current admin user.
	 *
	 * @return string
	 */
	private static function state_key() {
		return 'convertrack_gsc_oauth_state_' . get_current_user_id();
	}
}
