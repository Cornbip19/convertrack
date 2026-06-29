<?php
/**
 * Google Search Console activity logger.
 *
 * @package Convertrack
 */

namespace Convertrack\GSC;

defined( 'ABSPATH' ) || exit;

class Logger {

	/**
	 * Info log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $source, $message, array $context = array() ) {
		self::log( 'info', $source, $message, $context );
	}

	/**
	 * Warning log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $source, $message, array $context = array() ) {
		self::log( 'warning', $source, $message, $context );
	}

	/**
	 * Error log.
	 *
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $source, $message, array $context = array() ) {
		self::log( 'error', $source, $message, $context );
	}

	/**
	 * Write a log row.
	 *
	 * @param string $level   Level.
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function log( $level, $source, $message, array $context = array() ) {
		if ( class_exists( __NAMESPACE__ . '\\Database' ) ) {
			Database::insert_log( $level, $source, $message, $context );
		}
	}
}
