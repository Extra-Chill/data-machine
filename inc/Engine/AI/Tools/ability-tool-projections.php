<?php
/**
 * Ability tool projection helpers.
 *
 * @package DataMachine\Engine\AI\Tools
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'datamachine_register_ability_tool' ) ) {
	/**
	 * Register an ability as a model-facing tool projection.
	 *
	 * The declaration must include an `ability` slug or non-empty `ability_map`.
	 * AbilityToolSource uses the primary slug for projection metadata.
	 * Optional keys such as `modes`, `description`, `parameters`,
	 * `requires_opt_in`, `action_policy`, and `runtime` are passed through.
	 *
	 * @param string $tool_name   Model-facing tool name.
	 * @param array  $declaration Ability projection declaration.
	 * @return bool Whether the projection was registered.
	 */
	function datamachine_register_ability_tool( string $tool_name, array $declaration ): bool {
		if ( '' === $tool_name || ( ( empty( $declaration['ability'] ) || ! is_string( $declaration['ability'] ) ) && ( empty( $declaration['ability_map'] ) || ! is_array( $declaration['ability_map'] ) ) ) ) {
			return false;
		}

		add_filter(
			'datamachine_ability_tool_projections',
			static function ( array $tools ) use ( $tool_name, $declaration ): array {
				$tools[ $tool_name ] = $declaration;
				return $tools;
			}
		);

		return true;
	}
}
