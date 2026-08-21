<?php
/**
 * Helpers for consuming WordPress Ability execution results.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Presents core WP_Ability results at transport boundaries.
 *
 * Native callbacks return WP_Error directly. Array shaping remains only for
 * CLI, REST, and AI-tool presentation.
 */
class AbilityResult {

	/**
	 * Convert a WP_Ability::execute() result into the CLI result shape.
	 *
	 * Core returns WP_Error for validation, permission, and callback failures. This
	 * array conversion is reserved for presentation by CLI and external runners.
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
	 * Present a successful ability collection as a REST response.
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
		if ( is_wp_error( $result ) ) {
			return $result;
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
		if ( is_wp_error( $result ) ) {
			return $result;
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
	 * Return WP_Error data as an array for presentation payloads.
	 *
	 * @param \WP_Error $error Error object.
	 * @return array
	 */
	private static function wp_error_data( \WP_Error $error ): array {
		$data = $error->get_error_data();

		return is_array( $data ) ? $data : array();
	}
}
