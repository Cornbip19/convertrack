<?php
/**
 * Frontend 404 detector.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Detector {

	/**
	 * Register detector hook.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'capture_404' ), 20 );
	}

	/**
	 * Capture real frontend 404s.
	 */
	public function capture_404() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || ! Settings::get( 'enabled' ) || ! is_404() ) {
			return;
		}

		$url = Redirector::current_request_url();
		if ( '' === $url || self::should_ignore( $url ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( self::is_internal_user_agent( $ua ) ) {
			return;
		}

		$source = Database::normalize_source( $url );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$budget = Database::consume_capture_budget( $source, $ip );
		if ( is_wp_error( $budget ) ) {
			do_action( 'convertrack_404_capture_rejected', $budget, $source );
			return;
		}

		$id       = Database::record_404( $url, $referrer, $ua );
		if ( is_wp_error( $id ) ) {
			Logger::error( 'capture', '404 event write failed.', array( 'error' => $id->get_error_message() ) );
			return;
		}
		if ( $id && Settings::recommendations_enabled() ) {
			Cron::kick_processing( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Whether a URL should be ignored.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function should_ignore( $url ) {
		$source = Database::normalize_source( $url );
		if ( empty( $source ) ) {
			return true;
		}

		$path = $source['path'];
		if ( preg_match( '/\.(?:css|js|map|jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot|mp4|webm|pdf|zip)(?:\/)?$/i', $path ) ) {
			return true;
		}
		foreach ( Settings::lines_to_array( Settings::get( 'ignore_patterns' ) ) as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $path, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'convertrack_404_skip_capture', false, $url, $source );
	}

	/**
	 * Whether the request came from this module's own scanner/validator.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	private static function is_internal_user_agent( $ua ) {
		$ua = (string) $ua;
		return false !== strpos( $ua, 'Convertrack/' ) && false !== strpos( $ua, '404-monitor' );
	}
}
