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
			return array(
				'success'       => false,
				'error'         => $result->get_error_message(),
				'wp_error_code' => $result->get_error_code(),
				'wp_error_data' => self::wp_error_data( $result ),
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

	/**
	 * Convert a WP_Ability::execute() result into Data Machine's tool result shape.
	 *
	 * @param mixed  $result       Ability execution result.
	 * @param string $tool_name    Tool name.
	 * @param string $ability_slug Ability slug.
	 * @return array Normalized tool execution result.
	 */
	public static function normalize_tool_result( $result, string $tool_name, string $ability_slug ): array {
		if ( is_wp_error( $result ) ) {
			return array(
				'success'       => false,
				'error'         => $result->get_error_message(),
				'tool_name'     => $tool_name,
				'ability'       => $ability_slug,
				'wp_error_code' => $result->get_error_code(),
				'wp_error_data' => self::wp_error_data( $result ),
			);
		}

		if ( is_array( $result ) ) {
			if ( array_key_exists( 'data', $result ) && ! array_key_exists( 'result', $result ) ) {
				$result['result'] = $result['data'];
			} elseif ( array_key_exists( 'result', $result ) && ! array_key_exists( 'data', $result ) ) {
				$result['data'] = $result['result'];
			}

			return $result;
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'ability'   => $ability_slug,
			'data'      => $result,
			'result'    => $result,
		);
	}

	/**
	 * Convert a legacy failed ability result array into WP_Error.
	 *
	 * @param mixed  $result          Ability execution result.
	 * @param string $default_code    Error code to use when the result has no error code.
	 * @param string $default_message Error message to use when the result has no message.
	 * @param array  $status_map      Optional map of error code to HTTP status.
	 * @param int    $default_status  Default HTTP status.
	 * @param bool   $use_error_as_code Use the legacy error string as the WP_Error code when error_code is absent.
	 * @return \WP_Error|null WP_Error for failed legacy arrays, null otherwise.
	 */
	public static function legacy_failure_to_wp_error( $result, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', array $status_map = array(), int $default_status = 500, bool $use_error_as_code = false ): ?\WP_Error {
		if ( ! is_array( $result ) || ! isset( $result['success'] ) || $result['success'] ) {
			return null;
		}

		$error_code = (string) ( $result['error_code'] ?? ( $use_error_as_code ? ( $result['error'] ?? $default_code ) : $default_code ) );
		if ( '' === $error_code ) {
			$error_code = $default_code;
		}

		$message = (string) ( $result['message'] ?? $result['error'] ?? $default_message );
		if ( '' === $message ) {
			$message = $default_message;
		}

		return new \WP_Error(
			$error_code,
			$message,
			array( 'status' => $status_map[ $error_code ] ?? $default_status )
		);
	}

	/**
	 * Return WP_Error data as an array for legacy result payloads.
	 *
	 * @param \WP_Error $error Error object.
	 * @return array
	 */
	private static function wp_error_data( \WP_Error $error ): array {
		$data = $error->get_error_data();

		return is_array( $data ) ? $data : array();
	}
}
