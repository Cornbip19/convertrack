<?php
/**
 * 404 Monitor logger.
 *
 * @package Convertrack
 */

namespace Convertrack\NotFound;

defined( 'ABSPATH' ) || exit;

class Logger {

	/**
	 * Insert info log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $source, $message, array $context = array() ) {
		Database::insert_log( 'info', $source, $message, $context );
	}

	/**
	 * Insert warning log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $source, $message, array $context = array() ) {
		Database::insert_log( 'warning', $source, $message, $context );
	}

	/**
	 * Insert error log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $source, $message, array $context = array() ) {
		Database::insert_log( 'error', $source, $message, $context );
	}
}

