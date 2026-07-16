<?php
/**
 * Front-end: decide whether to track this request and load the tracker.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Frontend {

	/**
	 * Hook into the front-end asset pipeline.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue and configure the tracker when this request should be tracked.
	 */
	public function enqueue() {
		if ( ! Settings::get( 'enabled' ) || ! Database::schema_is_healthy() || ! Ingestion_Guard::schema_is_healthy() || $this->should_skip() ) {
			return;
		}

		wp_register_script(
			'convertrack',
			CONVERTRACK_URL . 'public/js/convertrack.js',
			array(),
			CONVERTRACK_VERSION,
			true
		);

		wp_localize_script( 'convertrack', 'ConvertrackConfig', $this->config() );
		wp_enqueue_script( 'convertrack' );
	}

	/**
	 * Build the configuration object handed to the browser.
	 *
	 * @return array
	 */
	private function config() {
		// Resolve taxonomy/archive/search/home identities while WordPress still
		// has the public main query. The REST request cannot safely reconstruct
		// this context, so the compact identity is integrity-bound below.
		$identity = Page_Identity::current();

		return array(
			'collectUrl'   => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . '/collect' ) ),
			'heartbeatUrl' => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . '/heartbeat' ) ),
			'collectorToken' => Ingestion_Guard::issue_token(),
			'postId'       => (int) $identity['post_id'],
			'pageKey'      => (string) $identity['page_key'],
			'objectType'   => (string) $identity['object_type'],
			'objectId'     => (int) $identity['object_id'],
			'pageIdentityToken' => Ingestion_Guard::issue_page_identity_token( $identity ),
			'allowedQueryParams' => Collector::allowed_query_params(),
			'selectors'    => Settings::lines_to_array( Settings::get( 'track_selectors' ) ),
			'conversionSelectors' => Settings::lines_to_array( Settings::get( 'conversion_selectors' ) ),
			'conversionUrls'      => Settings::lines_to_array( Settings::get( 'conversion_urls' ) ),
			'trackSearchKeywords' => (bool) Settings::get( 'track_search_keywords' ),
			'heartbeat'    => (int) Settings::get( 'heartbeat_interval' ) * 1000,
			'flush'        => (int) Settings::get( 'flush_interval' ) * 1000,
			'batchMax'     => (int) Settings::get( 'batch_max' ),
			'sampleRate'   => (int) Settings::get( 'sample_rate' ),
			'respectDnt'   => (bool) Settings::get( 'respect_dnt' ),
			'cookieName'   => 'cvtrk_vid',
			'sessionTtl'   => 30 * 60, // session inactivity window in seconds
		);
	}

	/**
	 * Decide whether tracking should be suppressed for this request.
	 *
	 * @return bool
	 */
	private function should_skip() {
		// Never track admin, feeds, REST, or sitemap responses.
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}

		if ( isset( $_GET['convertrack_no_track'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['convertrack_no_track'] ) ) ) {
			return true;
		}

		// Global Privacy Control is an explicit opt-out. WordPress Consent API
		// integrations expose wp_has_consent() when available.
		if ( Ingestion_Guard::privacy_opted_out() ) {
			return true;
		}

		// Logged-in handling.
		if ( is_user_logged_in() ) {
			if ( ! Settings::get( 'track_logged_in' ) ) {
				return true;
			}
			$excluded = (array) Settings::get( 'exclude_roles' );
			$user     = wp_get_current_user();
			if ( $user && array_intersect( $excluded, (array) $user->roles ) ) {
				return true;
			}
		}

		// URL exclusion list.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_uri = esc_url_raw( $request_uri );
		foreach ( Settings::lines_to_array( Settings::get( 'exclude_urls' ) ) as $needle ) {
			if ( '' !== $needle && false !== strpos( $request_uri, $needle ) ) {
				return true;
			}
		}

		/**
		 * Allow themes/plugins to suppress tracking for the current request.
		 *
		 * @param bool $skip Whether to skip. Default false.
		 */
		return (bool) apply_filters( 'convertrack_skip_tracking', false );
	}
}
