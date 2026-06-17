<?php
/**
 * Real-time presence: heartbeats in, active-visitor counts out.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Presence {

	/**
	 * Handle a heartbeat ping (keeps a session marked as "on the site now").
	 *
	 * @param array $payload Decoded JSON body.
	 * @return array|\WP_Error
	 */
	public static function heartbeat( array $payload ) {
		if ( ! Settings::get( 'enabled' ) ) {
			return array( 'ok' => false, 'disabled' => true );
		}

		$visitor_id = Collector::clean_uuid( isset( $payload['vid'] ) ? $payload['vid'] : '' );
		$session_id = Collector::clean_uuid( isset( $payload['sid'] ) ? $payload['sid'] : '' );

		if ( '' === $visitor_id || '' === $session_id ) {
			return new \WP_Error( 'convertrack_bad_id', 'Invalid visitor or session id.', array( 'status' => 400 ) );
		}

		if ( Collector::is_bot() ) {
			return array( 'ok' => false, 'ignored' => 'bot' );
		}

		if ( Collector::is_rate_limited() ) {
			return new \WP_Error( 'convertrack_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		$url     = Collector::sanitize_relative_url( isset( $payload['url'] ) ? $payload['url'] : '' );
		$post_id = isset( $payload['pid'] ) ? absint( $payload['pid'] ) : 0;

		Database::touch_session( $session_id, $visitor_id, $url, $post_id, 0, 0 );

		return array( 'ok' => true );
	}

	/**
	 * Number of distinct visitors active within the configured window.
	 *
	 * @return int
	 */
	public static function active_count() {
		$window = (int) Settings::get( 'active_window' );
		return Database::active_visitor_count( $window );
	}

	/**
	 * Detailed list of active sessions for the dashboard, with resolved titles.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function active_detail( $limit = 50 ) {
		$window   = (int) Settings::get( 'active_window' );
		$sessions = Database::active_sessions( $window, $limit );
		$out      = array();

		foreach ( $sessions as $row ) {
			$post_id = (int) $row['current_post_id'];
			$title   = $post_id > 0 ? get_the_title( $post_id ) : '';

			$out[] = array(
				'url'        => $row['current_url'],
				'post_id'    => $post_id,
				'title'      => $title ? $title : $row['current_url'],
				'last_seen'  => $row['last_seen'],
				'page_views' => (int) $row['page_views'],
				'clicks'     => (int) $row['click_count'],
			);
		}

		return $out;
	}
}
