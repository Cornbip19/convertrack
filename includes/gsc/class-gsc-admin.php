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
		add_action( 'admin_post_convertrack_gsc_oauth_start', array( $this, 'oauth_start' ) );
		add_action( 'admin_post_convertrack_gsc_oauth_callback', array( $this, 'oauth_callback' ) );
		add_action( 'admin_post_convertrack_gsc_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_convertrack_gsc_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );
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

		$secret = isset( $_POST['convertrack_gsc_client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['convertrack_gsc_client_secret'] ) ) ) : '';
		if ( '' !== $secret ) {
			$stored = Credentials::set_client_secret( $secret );
			if ( is_wp_error( $stored ) ) {
				$this->redirect( 'settings-error', $stored->get_error_message() );
			}
		}

		Logger::info( 'settings', 'Google Index Monitor settings saved.' );
		$this->redirect( 'settings-saved' );
	}

	/**
	 * Start OAuth flow.
	 */
	public function oauth_start() {
		$this->check_admin_action( 'convertrack_gsc_oauth_start' );

		$state = wp_generate_password( 32, false, false );
		set_transient( self::state_key(), $state, 10 * MINUTE_IN_SECONDS );

		$url = OAuth::authorization_url( self::callback_url(), $state );
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

		$expected = get_transient( self::state_key() );
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		delete_transient( self::state_key() );

		if ( empty( $expected ) || ! hash_equals( (string) $expected, (string) $state ) ) {
			Logger::error( 'oauth', 'OAuth callback failed state validation.' );
			$this->redirect( 'oauth-error', __( 'Invalid OAuth state. Please try connecting again.', 'convertrack-click-conversion-analytics' ) );
		}

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			Logger::error( 'oauth', 'Google OAuth returned an error.', array( 'error' => $error ) );
			$this->redirect( 'oauth-error', $error );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect( 'oauth-error', __( 'Google OAuth did not return an authorization code.', 'convertrack-click-conversion-analytics' ) );
		}

		$result = OAuth::exchange_code( $code, self::callback_url() );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'oauth-error', $result->get_error_message() );
		}

		$this->redirect( 'oauth-connected' );
	}

	/**
	 * Disconnect OAuth tokens.
	 */
	public function disconnect() {
		$this->check_admin_action( 'convertrack_gsc_disconnect' );
		Credentials::clear_tokens();
		Logger::info( 'oauth', 'Google Search Console OAuth disconnected.' );
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
