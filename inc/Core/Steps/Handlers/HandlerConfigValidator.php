<?php
/**
 * Generic handler configuration validation lifecycle.
 *
 * @package DataMachine\Core\Steps\Handlers
 */

namespace DataMachine\Core\Steps\Handlers;

defined( 'ABSPATH' ) || exit;

/**
 * Validates handler runtime configuration through a generic filter surface.
 */
class HandlerConfigValidator {

	public const ERROR_CODE = 'handler_config_validation_failed';

	/**
	 * Validate handler configuration before handler execution.
	 *
	 * Validators hook `datamachine_validate_handler_config` and may return:
	 * true/null for valid, false for generic invalid, WP_Error for invalid, or
	 * an array with `valid`, `message`, `code`, and optional `data` keys.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param string $step_type Step type.
	 * @param array  $handler_config Runtime handler config.
	 * @param array  $context Execution context.
	 * @return true|\WP_Error True when valid, normalized WP_Error when invalid.
	 */
	public static function validate( string $handler_slug, string $step_type, array $handler_config, array $context = array() ): true|\WP_Error {
		$context = array_merge(
			array(
				'handler_slug' => $handler_slug,
				'step_type'    => $step_type,
			),
			$context
		);

		$result = function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_validate_handler_config', true, $handler_slug, $step_type, $handler_config, $context )
			: true;

		return self::normalize_result( $result, $handler_slug, $step_type );
	}

	/**
	 * Build stable diagnostics from a validation failure.
	 *
	 * @param \WP_Error $error Validation error.
	 * @return array<string,mixed>
	 */
	public static function diagnostics( \WP_Error $error ): array {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array_merge(
			$data,
			array(
				'reason' => self::ERROR_CODE,
				'error'  => $error->get_error_message(),
			)
		);
	}

	/**
	 * Normalize validator output into the stable failure code.
	 *
	 * @param mixed  $result Validator result.
	 * @param string $handler_slug Handler slug.
	 * @param string $step_type Step type.
	 * @return true|\WP_Error True when valid, normalized WP_Error when invalid.
	 */
	private static function normalize_result( mixed $result, string $handler_slug, string $step_type ): true|\WP_Error {
		if ( true === $result || null === $result ) {
			return true;
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			$data = array_merge(
				$data,
				array(
					'handler_slug'    => $handler_slug,
					'step_type'       => $step_type,
					'validation_code' => $result->get_error_code(),
				)
			);

			return new \WP_Error( self::ERROR_CODE, $result->get_error_message(), $data );
		}

		if ( is_array( $result ) ) {
			$valid = $result['valid'] ?? true;
			if ( false !== $valid ) {
				return true;
			}

			$data = is_array( $result['data'] ?? null ) ? $result['data'] : array();
			$data = array_merge(
				$data,
				array(
					'handler_slug'    => $handler_slug,
					'step_type'       => $step_type,
					'validation_code' => (string) ( $result['code'] ?? self::ERROR_CODE ),
				)
			);

			return new \WP_Error(
				self::ERROR_CODE,
				(string) ( $result['message'] ?? 'Handler configuration failed validation.' ),
				$data
			);
		}

		return new \WP_Error(
			self::ERROR_CODE,
			'Handler configuration failed validation.',
			array(
				'handler_slug'    => $handler_slug,
				'step_type'       => $step_type,
				'validation_code' => self::ERROR_CODE,
			)
		);
	}
}
