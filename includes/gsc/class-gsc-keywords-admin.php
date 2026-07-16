<?php
/**
 * Admin handlers for GSC Keyword Insights.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Keywords_Admin {

	/**
	 * Register admin-post handlers.
	 */
	public function register() {
		add_action( 'admin_post_convertrack_gsc_keywords_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_convertrack_gsc_keywords_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );
	}

	/**
	 * Save Keyword Insights settings.
	 */
	public function save_settings() {
		$this->check_admin_action( 'convertrack_gsc_keywords_save_settings' );

		$input = isset( $_POST['convertrack_gsc_keywords_settings'] ) && is_array( $_POST['convertrack_gsc_keywords_settings'] )
			? wp_unslash( $_POST['convertrack_gsc_keywords_settings'] )
			: array();

		$before = Keywords_Settings::all();
		$after  = Keywords_Settings::save( $input );

		// Term lists and thresholds change classification/scoring output —
		// stored analysis is stale the moment they differ.
		$relevant = array( 'brand_terms', 'location_terms', 'service_terms', 'product_terms', 'competitor_terms', 'min_impressions', 'min_position', 'low_ctr_ratio', 'keyword_types', 'selected_post_types' );
		foreach ( $relevant as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				Keywords_Database::mark_all_stale();
				Keywords_Cron::kick_analyze( MINUTE_IN_SECONDS );
				break;
			}
		}

		Logger::info( 'keywords-settings', 'Keyword Insights settings saved.' );
		$this->redirect( 'settings-saved' );
	}

	/**
	 * Export the filtered keyword table as CSV.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'convertrack-click-conversion-analytics' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'convertrack_gsc_keywords_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'convertrack-click-conversion-analytics' ) );
		}

		$args = array(
			'page'            => 1,
			'per_page'        => 100,
			'range_key'       => isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '',
			'search'          => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'post_id'         => isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0,
			'label'           => isset( $_GET['label'] ) ? sanitize_key( wp_unslash( $_GET['label'] ) ) : '',
			'presence'        => isset( $_GET['presence'] ) ? sanitize_key( wp_unslash( $_GET['presence'] ) ) : '',
			'opportunity'     => isset( $_GET['opportunity'] ) ? sanitize_key( wp_unslash( $_GET['opportunity'] ) ) : '',
			'min_impressions' => isset( $_GET['min_impressions'] ) ? absint( $_GET['min_impressions'] ) : 0,
			'orderby'         => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'           => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=convertrack-gsc-keywords-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		\Convertrack\CSV::write( $out, array( 'Keyword', 'Page URL', 'Post Title', 'Types', 'Clicks', 'Impressions', 'CTR %', 'Position', 'Presence', 'Opportunity Score', 'Opportunity Level', 'Recommended Action', 'Last Analyzed' ) );

		do {
			$data = Keywords_Database::list_keywords( $args );
			foreach ( $data['rows'] as $row ) {
				$primary = '';
				if ( ! empty( $row['recommendations'] ) && isset( $row['recommendations'][0]['code'] ) ) {
					$first   = $row['recommendations'][0];
					$primary = Keywords_Recommendations::message( (string) $first['code'], isset( $first['params'] ) ? (array) $first['params'] : array() );
				}

				\Convertrack\CSV::write(
					$out,
					array(
						$row['query'],
						$row['page_url'],
						$row['post_title'],
						implode( '|', (array) $row['labels'] ),
						(int) $row['clicks'],
						(int) $row['impressions'],
						round( $row['ctr'] * 100, 2 ),
						round( $row['position'], 1 ),
						$row['presence_status'],
						round( $row['opportunity_score'] ),
						$row['opportunity_level'],
						$primary,
						$row['last_analyzed_at'],
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
		$error = get_transient( 'convertrack_gsc_keywords_migration_error' );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( sprintf( /* translators: %s: error message. */ __( 'Convertrack Keyword Insights migration failed: %s', 'convertrack-click-conversion-analytics' ), $error ) ); ?></p>
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
	 * Redirect back to the Keyword Insights screen.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'convertrack-gsc-keywords',
					'cvtrk_kw_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
