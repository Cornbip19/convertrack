<?php
/**
 * Owner-safe option locks for background workers.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

/**
 * Small compare-and-swap lock built on the WordPress options table.
 *
 * add_option() provides the uncontended atomic acquire. Expired takeover,
 * heartbeat, and release all compare the complete serialized value so an old
 * worker can never refresh or delete a newer worker's lease.
 */
class Owner_Lock {

	/**
	 * Acquire a lease.
	 *
	 * @param string $option Option name.
	 * @param int    $ttl    Lease lifetime in seconds.
	 * @return string|false Owner token on success, false while another live owner holds it.
	 */
	public static function acquire( $option, $ttl = 120 ) {
		$option = sanitize_key( $option );
		$ttl    = max( 15, (int) $ttl );
		$owner  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cvtrk-', true );
		$now    = time();
		$lock   = array(
			'owner'     => $owner,
			'acquired'  => $now,
			'heartbeat' => $now,
			'expires'   => $now + $ttl,
		);

		if ( add_option( $option, $lock, '', 'no' ) ) {
			return $owner;
		}

		$existing = get_option( $option, null );
		if ( self::is_value_live( $existing, $now, $ttl ) ) {
			return false;
		}

		return self::compare_and_swap( $option, $existing, $lock ) ? $owner : false;
	}

	/**
	 * Extend a lease only when it is still owned by the caller.
	 *
	 * @param string $option Option name.
	 * @param string $owner  Owner token returned by acquire().
	 * @param int    $ttl    Lease lifetime in seconds.
	 * @return bool
	 */
	public static function heartbeat( $option, $owner, $ttl = 120 ) {
		$option   = sanitize_key( $option );
		$existing = get_option( $option, null );
		if ( ! is_array( $existing ) || empty( $existing['owner'] ) || ! hash_equals( (string) $existing['owner'], (string) $owner ) ) {
			return false;
		}

		$now                   = time();
		$replacement           = $existing;
		$replacement['heartbeat'] = $now;
		$replacement['expires']   = $now + max( 15, (int) $ttl );

		return self::compare_and_swap( $option, $existing, $replacement );
	}

	/**
	 * Release a lease only when owned by the caller.
	 *
	 * @param string $option Option name.
	 * @param string $owner  Owner token.
	 * @return bool
	 */
	public static function release( $option, $owner ) {
		global $wpdb;

		$option   = sanitize_key( $option );
		$existing = get_option( $option, null );
		if ( ! is_array( $existing ) || empty( $existing['owner'] ) || ! hash_equals( (string) $existing['owner'], (string) $owner ) ) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				maybe_serialize( $existing )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		self::clear_option_cache( $option );
		return 1 === (int) $result;
	}

	/**
	 * Whether an option currently contains a live owner lease.
	 *
	 * Legacy integer locks are understood during the upgrade window.
	 *
	 * @param string $option Option name.
	 * @param int    $legacy_ttl TTL for an integer legacy lock.
	 * @return bool
	 */
	public static function is_live( $option, $legacy_ttl = 120 ) {
		return self::is_value_live( get_option( sanitize_key( $option ), null ), time(), max( 15, (int) $legacy_ttl ) );
	}

	/**
	 * Compare-and-swap a complete option value.
	 *
	 * @param string $option      Option name.
	 * @param mixed  $expected    Expected value.
	 * @param mixed  $replacement Replacement value.
	 * @return bool
	 */
	private static function compare_and_swap( $option, $expected, $replacement ) {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				$option,
				maybe_serialize( $expected )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		self::clear_option_cache( $option );
		return 1 === (int) $result;
	}

	/**
	 * Check current or legacy lock value.
	 *
	 * @param mixed $value      Stored value.
	 * @param int   $now        Current Unix timestamp.
	 * @param int   $legacy_ttl Legacy integer TTL.
	 * @return bool
	 */
	private static function is_value_live( $value, $now, $legacy_ttl ) {
		if ( is_array( $value ) ) {
			return ! empty( $value['owner'] ) && ! empty( $value['expires'] ) && (int) $value['expires'] > $now;
		}

		if ( is_numeric( $value ) ) {
			return ( (int) $value + $legacy_ttl ) > $now;
		}

		return false;
	}

	/**
	 * Evict direct-SQL changes from the option caches.
	 *
	 * @param string $option Option name.
	 */
	private static function clear_option_cache( $option ) {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
