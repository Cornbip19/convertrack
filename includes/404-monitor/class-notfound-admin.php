<?php
/**
 * Admin handlers for 404 Monitor.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Admin {

	/**
	 * Register admin-post handlers and notices.
	 */
	public function register() {
		add_action( 'admin_post_convertrack_404_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_convertrack_404_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );
		add_action( 'admin_notices', array( $this, 'compatibility_notice' ) );
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		$this->check_admin_action( 'convertrack_404_save_settings' );

		$input = isset( $_POST['convertrack_404_settings'] ) && is_array( $_POST['convertrack_404_settings'] )
			? wp_unslash( $_POST['convertrack_404_settings'] )
			: array();

		Settings::save( $input );
		Cron::reschedule();
		Logger::info( 'settings', '404 Monitor settings saved.' );
		$this->redirect( 'settings-saved' );
	}

	/**
	 * Export detected 404 events.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'convertrack_404_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'convertrack-click-conversion-analytics' ) );
		}

		$args = array(
			'page'           => 1,
			'per_page'       => 100,
			'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
			'post_type'      => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'all',
			'confidence_min' => isset( $_GET['confidence_min'] ) ? sanitize_text_field( wp_unslash( $_GET['confidence_min'] ) ) : '',
			'confidence_max' => isset( $_GET['confidence_max'] ) ? sanitize_text_field( wp_unslash( $_GET['confidence_max'] ) ) : '',
			'detected_from'  => isset( $_GET['detected_from'] ) ? sanitize_text_field( wp_unslash( $_GET['detected_from'] ) ) : '',
			'detected_to'    => isset( $_GET['detected_to'] ) ? sanitize_text_field( wp_unslash( $_GET['detected_to'] ) ) : '',
			'search'         => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=convertrack-404-monitor-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'Source URL', 'Referrer', 'First detected', 'Last detected', 'Hits', 'Suggested destination', 'Confidence', 'Match reason', 'Post type', 'Status' ) );

		do {
			$data = Database::list_events( $args );
			foreach ( $data['rows'] as $row ) {
				fputcsv(
					$out,
					array(
						$row['url'],
						$row['referrer_url'],
						$row['first_detected_at'],
						$row['last_detected_at'],
						(int) $row['hit_count'],
						$row['suggested_url'],
						(int) $row['confidence'],
						$row['match_reason'],
						$row['suggested_post_type'],
						$row['status'],
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
		$error = get_transient( 'convertrack_404_migration_error' );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( sprintf( __( 'Convertrack 404 Monitor migration failed: %s', 'convertrack-click-conversion-analytics' ), $error ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Warn when another redirect plugin is present.
	 */
	public function compatibility_notice() {
		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['page'] ) || 'convertrack-404-monitor' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Compatibility::has_redirect_tool() ) {
			return;
		}
		$labels = wp_list_pluck( Compatibility::detected_tools(), 'label' );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: detected redirect tool names. */
						__( 'Convertrack detected an existing redirect tool (%s). 404 Monitor will show read-only visibility where possible and will not create duplicate automatic redirects for sources handled externally.', 'convertrack-click-conversion-analytics' ),
						implode( ', ', $labels )
					)
				);
				?>
			</p>
		</div>
		<?php
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
	 * Redirect back to the 404 Monitor screen.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'convertrack-404-monitor',
					'cvtrk_404_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
