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
	 * @param array $context Admission context from Ingestion_Guard.
	 * @return array|\WP_Error
	 */
	public static function heartbeat( array $payload, array $context = array() ) {
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

		$raw_url = isset( $payload['url'] ) && is_scalar( $payload['url'] ) ? (string) $payload['url'] : '';
		if ( Collector::is_no_track_url( $raw_url ) ) {
			return array( 'ok' => false, 'ignored' => 'preview' );
		}
		$identity = Collector::canonicalize_page(
			$raw_url,
			isset( $payload['pid'] ) ? absint( $payload['pid'] ) : 0,
			array(
				'page_key'    => isset( $payload['pk'] ) && is_scalar( $payload['pk'] ) ? (string) $payload['pk'] : '',
				'object_type' => isset( $payload['ot'] ) && is_scalar( $payload['ot'] ) ? (string) $payload['ot'] : '',
				'object_id'   => isset( $payload['oid'] ) ? absint( $payload['oid'] ) : 0,
				'token'       => isset( $payload['pit'] ) && is_scalar( $payload['pit'] ) ? (string) $payload['pit'] : '',
			)
		);
		$url      = $identity['url'];
		if ( '' === $url ) {
			return new \WP_Error( 'convertrack_bad_url', 'Invalid page URL.', array( 'status' => 400 ) );
		}

		$post_id = (int) $identity['post_id'];
		$country = Geo::current_country();

		$touched = Database::touch_session( $session_id, $visitor_id, $url, $post_id, 0, 0, $country, false, $identity['page_key'] );
		if ( is_wp_error( $touched ) ) {
			return $touched;
		}
		if ( false === $touched ) {
			return new \WP_Error( 'convertrack_session_write_failed', 'The analytics session could not be updated.', array( 'status' => 503 ) );
		}

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

			// Time on site so far: last activity minus session start.
			$started  = isset( $row['started_at'] ) ? strtotime( (string) $row['started_at'] ) : 0;
			$seen     = isset( $row['last_seen'] ) ? strtotime( (string) $row['last_seen'] ) : 0;
			$duration = ( $started && $seen && $seen >= $started ) ? ( $seen - $started ) : 0;

			$out[] = array(
				'url'        => $row['current_url'],
				'post_id'    => $post_id,
				'title'      => $title ? $title : $row['current_url'],
				'last_seen'  => $row['last_seen'],
				'country'    => isset( $row['country'] ) ? (string) $row['country'] : '',
				'duration'   => (int) $duration,
				'page_views' => (int) $row['page_views'],
				'clicks'     => (int) $row['click_count'],
			);
		}

		return $out;
	}
}
