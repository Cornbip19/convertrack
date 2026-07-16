<?php
/**
 * Formula-safe CSV output helpers.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class CSV {

	/**
	 * Write one CSV row after neutralizing spreadsheet formulas.
	 *
	 * @param resource $handle Open output stream.
	 * @param array    $cells  Row cells.
	 * @return int|false
	 */
	public static function write( $handle, array $cells ) {
		return fputcsv( $handle, array_map( array( __CLASS__, 'cell' ), $cells ) );
	}

	/**
	 * Make a cell safe for Excel, LibreOffice and Google Sheets imports.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	public static function cell( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$value = str_replace( "\0", '', $value );
		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
