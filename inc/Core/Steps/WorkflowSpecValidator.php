<?php
/**
 * Workflow spec validator.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

use DataMachine\Abilities\StepTypeAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the structural workflow JSON contract shared by workflow consumers.
 */
class WorkflowSpecValidator {

	/**
	 * Validate a workflow spec.
	 *
	 * This validates only structural invariants. Step types still own their
	 * handler/config requirements at runtime.
	 *
	 * @param mixed $workflow Workflow spec to validate.
	 * @return array{valid: bool, error?: string}
	 */
	public static function validate( $workflow ): array {
		if ( ! is_array( $workflow ) || ! isset( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Workflow must contain steps array',
			);
		}

		if ( ! array_is_list( $workflow['steps'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Workflow steps must be a list',
			);
		}

		if ( empty( $workflow['steps'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Workflow must have at least one step',
			);
		}

		$step_type_abilities = new StepTypeAbilities();
		$valid_types         = array_keys( $step_type_abilities->getAllStepTypes() );

		foreach ( $workflow['steps'] as $index => $step ) {
			if ( ! is_array( $step ) ) {
				return array(
					'valid' => false,
					'error' => "Workflow step at index {$index} must be an object",
				);
			}

			foreach ( array( 'handler', 'handler_slug', 'handler_config' ) as $legacy_field ) {
				if ( array_key_exists( $legacy_field, $step ) ) {
					return array(
						'valid' => false,
						'error' => "Step {$index} uses unsupported legacy field {$legacy_field}; use handler_slugs and handler_configs",
					);
				}
			}

			$step_type = $step['step_type'] ?? null;
			if ( ! is_string( $step_type ) || '' === trim( $step_type ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} missing step_type",
				);
			}

			if ( ! in_array( $step_type, $valid_types, true ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} has invalid step_type: {$step_type}. Valid types: " . implode( ', ', $valid_types ),
				);
			}
		}

		return array( 'valid' => true );
	}
}
