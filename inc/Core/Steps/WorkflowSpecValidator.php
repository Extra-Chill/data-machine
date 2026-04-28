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

			$step_type = $step['type'] ?? null;
			if ( ! is_string( $step_type ) || '' === trim( $step_type ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} missing type",
				);
			}

			if ( ! in_array( $step_type, $valid_types, true ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} has invalid type: {$step_type}. Valid types: " . implode( ', ', $valid_types ),
				);
			}
		}

		return array( 'valid' => true );
	}
}
