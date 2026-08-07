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
	 * AI tools use the Agents API execution envelope. The payload key is `result`;
	 * `data` remains an ability/REST presentation concern, not a mirrored tool
	 * result field.
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
			}

			return $result;
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'ability'   => $ability_slug,
			'result'    => $result,
		);
	}

	/**
	 * Put Data Machine handler output into the Agents API result envelope.
	 *
	 * @param array  $result    Raw handler result.
	 * @param string $tool_name Tool name.
	 * @param array  $metadata  Additional metadata.
	 * @return array Tool execution result.
	 */
	public static function normalize_tool_envelope( array $result, string $tool_name, array $metadata = array() ): array {
		$result['tool_name'] = is_string( $result['tool_name'] ?? null ) && '' !== $result['tool_name'] ? $result['tool_name'] : $tool_name;
		if ( ! isset( $result['success'] ) ) {
			$result['success'] = true;
		}

		if ( ! empty( $metadata ) ) {
			$result['metadata'] = array_merge( $metadata, is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array() );
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		if ( ! array_key_exists( 'result', $result ) ) {
			$payload = $result;
			unset( $payload['success'], $payload['tool_name'], $payload['metadata'], $payload['runtime'] );
			$result['result'] = $payload;
		}

		return $result;
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

		$status = isset( $result['status'] ) ? (int) $result['status'] : ( $status_map[ $error_code ] ?? $default_status );

		return new \WP_Error(
			$error_code,
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Convert ability output into a REST-ready WP_Error when execution failed.
	 *
	 * @param mixed  $result          Ability execution result.
	 * @param string $default_code    Error code to use when the result has no error code.
	 * @param string $default_message Error message to use when the result has no message.
	 * @param int    $default_status  Default HTTP status.
	 * @return \WP_Error|null WP_Error for failures, null for successful results.
	 */
	public static function failure_to_wp_error( $result, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500 ): ?\WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::legacy_failure_to_wp_error( $result, $default_code, $default_message, array(), $default_status );
	}

	/**
	 * Present a successful ability collection with Data Machine's canonical page envelope.
	 *
	 * @param array  $result     Successful ability result.
	 * @param string $items_key  Key containing collection items in the ability result.
	 * @param array  $options    Presentation options.
	 * @return array REST/CLI-safe collection envelope.
	 */
	public static function collection_envelope( array $result, string $items_key, array $options = array() ): array {
		$items      = $result[ $items_key ] ?? array();
		$data_key   = $options['data_key'] ?? null;
		$data       = $data_key ? array( $data_key => $items ) : $items;
		$data_extra = $options['data_extra'] ?? array();
		$meta_keys  = $options['meta_keys'] ?? array( 'total', 'per_page', 'offset' );
		$top_extra  = $options['top_extra'] ?? array();

		if ( $data_key && is_array( $data ) ) {
			$data = array_merge( $data_extra, $data );
		}

		$envelope = array(
			'success' => true,
			'data'    => $data,
		);

		foreach ( $meta_keys as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$envelope[ $key ] = $result[ $key ];
			}
		}

		foreach ( $top_extra as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$envelope[ $key ] = $result[ $key ];
			}
		}

		if ( ! array_key_exists( 'total', $envelope ) ) {
			$envelope['total'] = is_countable( $items ) ? count( $items ) : 0;
		}

		return $envelope;
	}

	/**
	 * Present an ability collection as a REST response, normalizing failures first.
	 *
	 * @param mixed  $result          Ability execution result.
	 * @param string $items_key       Key containing collection items in the ability result.
	 * @param array  $options         Collection presentation options.
	 * @param string $default_code    Default error code.
	 * @param string $default_message Default error message.
	 * @param int    $default_status  Default HTTP status.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public static function rest_collection_response( $result, string $items_key, array $options = array(), string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500 ) {
		$error = self::failure_to_wp_error( $result, $default_code, $default_message, $default_status );
		if ( $error ) {
			return $error;
		}

		return rest_ensure_response( self::collection_envelope( self::normalize( $result ), $items_key, $options ) );
	}

	/**
	 * Present collection rows for CLI JSON while allowing explicit envelope opt-in.
	 *
	 * @param array  $items        Rows formatted for CLI output.
	 * @param array  $result       Ability result containing pagination metadata.
	 * @param string $items_key    Collection key to use when envelope output is requested.
	 * @param bool   $use_envelope Whether to return the shared collection envelope.
	 * @return array CLI JSON payload.
	 */
	public static function cli_collection_payload( array $items, array $result, string $items_key, bool $use_envelope = false ): array {
		if ( ! $use_envelope ) {
			return $items;
		}

		$result[ $items_key ] = $items;

		return self::collection_envelope( $result, $items_key, array( 'top_extra' => array( 'filters_applied' ) ) );
	}

	/**
	 * Present an ability result as a single-resource REST response.
	 *
	 * @param mixed  $result          Ability execution result.
	 * @param mixed  $data            Data payload for the response.
	 * @param array  $extra           Extra top-level envelope fields.
	 * @param string $default_code    Default error code.
	 * @param string $default_message Default error message.
	 * @param int    $default_status  Default HTTP status.
	 * @return \WP_REST_Response|\WP_Error REST response or error.
	 */
	public static function rest_item_response( $result, $data = null, array $extra = array(), string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500 ) {
		$error = self::failure_to_wp_error( $result, $default_code, $default_message, $default_status );
		if ( $error ) {
			return $error;
		}

		$normalized = self::normalize( $result );
		if ( null === $data ) {
			$data = $normalized;
		}

		return rest_ensure_response(
			array_merge(
				array(
					'success' => true,
					'data'    => $data,
				),
				$extra
			)
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
