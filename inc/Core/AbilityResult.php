<?php
/**
 * Helpers for consuming WordPress Ability execution results.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes core WP_Ability results at legacy caller boundaries.
 */
class AbilityResult {

	/**
	 * Convert a WP_Ability::execute() result into Data Machine's legacy array shape.
	 *
	 * Core returns WP_Error for validation, permission, and callback failures. Many
	 * Data Machine callers still expect arrays with a success flag; this keeps
	 * those callers safe while callbacks migrate to returning WP_Error directly.
	 *
	 * @param mixed $result Ability execution result.
	 * @return array Normalized result array.
	 */
	public static function normalize( $result ): array {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			return array(
				'success'       => false,
				'error'         => $result->get_error_message(),
				'wp_error_code' => $result->get_error_code(),
				'wp_error_data' => is_array( $data ) ? $data : array(),
			);
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}
}
