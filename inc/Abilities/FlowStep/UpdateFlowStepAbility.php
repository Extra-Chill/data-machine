<?php
/**
 * Update Flow Step Ability
 *
 * Handles single flow step handler configuration or user message updates.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.15.3
 */

namespace DataMachine\Abilities\FlowStep;

defined( 'ABSPATH' ) || exit;

class UpdateFlowStepAbility {

	use FlowStepHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/update-flow-step',
				array(
					'label'               => __( 'Update Flow Step', 'data-machine' ),
					'description'         => __( 'Update a single flow step handler configuration or user message.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_step_id' ),
						'properties' => array(
							'flow_step_id'   => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to update', 'data-machine' ),
							),
							'handler_slug'   => array(
								'type'        => 'string',
								'description' => __( 'Handler slug to set (uses existing if empty)', 'data-machine' ),
							),
							'handler_config' => array(
								'type'        => 'object',
								'description' => __( 'Handler configuration settings to merge', 'data-machine' ),
							),
							'user_message'   => array(
								'type'        => 'string',
								'description' => __( 'User message for AI steps', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'flow_step_id' => array( 'type' => 'string' ),
							'message'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute update flow step ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function execute( array $input ): array {
		$flow_step_id   = $input['flow_step_id'] ?? null;
		$handler_slug   = $input['handler_slug'] ?? null;
		$handler_config = $input['handler_config'] ?? array();
		$user_message   = $input['user_message'] ?? null;

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		$has_handler_update = ! empty( $handler_slug ) || ! empty( $handler_config );
		$has_message_update = null !== $user_message;

		if ( ! $has_handler_update && ! $has_message_update ) {
			return array(
				'success' => false,
				'error'   => 'At least one of handler_slug, handler_config, or user_message is required',
			);
		}

		$validation = $this->validateFlowStepId( $flow_step_id );
		if ( ! $validation['valid'] ) {
			return $validation['error_response'];
		}

		$existing_step = $validation['step_config'];

		$updated_fields = array();

		if ( $has_handler_update ) {
			// Priority: explicit handler_slug > existing handler_slug > step_type (for non-handler steps like agent_ping).
			$effective_slug = ! empty( $handler_slug )
				? $handler_slug
				: ( ! empty( $existing_step['handler_slug'] )
					? $existing_step['handler_slug']
					: ( $existing_step['step_type'] ?? '' ) );

			if ( empty( $effective_slug ) ) {
				return array(
					'success' => false,
					'error'   => 'Unable to determine handler: no handler_slug provided, stored, or step_type available',
				);
			}

			if ( ! empty( $handler_config ) ) {
				$validation_result = $this->validateHandlerConfig( $effective_slug, $handler_config );
				if ( true !== $validation_result ) {
					return array(
						'success'        => false,
						'error'          => $validation_result['error'],
						'unknown_fields' => $validation_result['unknown_fields'],
						'field_specs'    => $validation_result['field_specs'],
					);
				}
			}

			$success = $this->updateHandler( $flow_step_id, $effective_slug, $handler_config );

			if ( ! $success ) {
				return array(
					'success' => false,
					'error'   => 'Failed to update handler configuration',
				);
			}

			if ( ! empty( $handler_slug ) ) {
				$updated_fields[] = 'handler_slug';
			}
			if ( ! empty( $handler_config ) ) {
				$updated_fields[] = 'handler_config';
			}
		}

		if ( $has_message_update ) {
			$success = $this->updateUserMessage( $flow_step_id, $user_message );

			if ( ! $success ) {
				return array(
					'success' => false,
					'error'   => 'Failed to update user message. Verify the step exists.',
				);
			}

			$updated_fields[] = 'user_message';
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow step updated via ability',
			array(
				'flow_step_id'   => $flow_step_id,
				'updated_fields' => $updated_fields,
			)
		);

		return array(
			'success'      => true,
			'flow_step_id' => $flow_step_id,
			'message'      => 'Flow step updated successfully. Fields updated: ' . implode( ', ', $updated_fields ),
		);
	}
}
