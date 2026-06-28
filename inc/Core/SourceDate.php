<?php
/**
 * Source date normalization helpers.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes source-provided datetimes for WordPress storage.
 */
class SourceDate {

	/**
	 * Normalize an arbitrary source date into a MySQL GMT datetime.
	 *
	 * @param mixed $date Source date value.
	 * @return string|null MySQL GMT datetime or null when invalid.
	 */
	public static function normalizeGmt( $date ): ?string {
		if ( empty( $date ) || ! is_scalar( $date ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $date );
		if ( false === $timestamp || $timestamp > time() ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
