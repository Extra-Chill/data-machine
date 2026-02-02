<?php
/**
 * Flow Step Helpers Trait
 *
 * Shared helper methods used across all FlowStep ability classes.
 * Provides database access, validation, and update operations.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.15.3
 */

namespace DataMachine\Abilities\FlowStep;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;

defined( 'ABSPATH' ) || exit;

trait FlowStepHelpers {

	protected Flows $db_flows;
	protected Pipelines $db_pipelines;
	protected HandlerAbilities $handler_abilities;

	protected function initDatabases(): void {
		$this->db_flows          = new Flows();
		$this->db_pipelines      = new Pipelines();
		$this->handler_abilities = new HandlerAbilities();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validate a flow_step_id and return diagnostic context if invalid.
	 *
	 * @param string $flow_step_id Flow step ID to validate.
	 * @return array{valid: bool, step_config?: array, error_response?: array}
	 */
	protected function validateFlowStepId( string $flow_step_id ): array {
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );

		if ( ! $parts || empty( $parts['flow_id'] ) ) {
			return array(
				'valid'          => false,
				'error_response' => array(
					'success'     => false,
					'error'       => 'Invalid flow_step_id format',
					'error_type'  => 'validation',
					'diagnostic'  => array(
						'flow_step_id'    => $flow_step_id,
						'expected_format' => '{pipeline_step_id}_{flow_id}',
					),
					'remediation' => array(
						'action'    => 'get_valid_step_ids',
						'message'   => 'Use get_flow_steps with flow_id to retrieve valid flow_step_ids.',
						'tool_hint' => 'api_query',
					),
				),
			);
		}

		$flow_id = (int) $parts['flow_id'];
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return array(
				'valid'          => false,
				'error_response' => array(
					'success'     => false,
					'error'       => 'Flow not found',
					'error_type'  => 'not_found',
					'diagnostic'  => array(
						'flow_step_id' => $flow_step_id,
						'flow_id'      => $flow_id,
					),
					'remediation' => array(
						'action'    => 'verify_flow_id',
						'message'   => sprintf( 'Flow %d does not exist. Use list_flows to find valid flow IDs.', $flow_id ),
						'tool_hint' => 'list_flows',
					),
				),
			);
		}

		$flow_config = $flow['flow_config'] ?? array();

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			$available_step_ids = array_keys( $flow_config );

			return array(
				'valid'          => false,
				'error_response' => array(
					'success'     => false,
					'error'       => 'Flow step not found in flow configuration',
					'error_type'  => 'not_found',
					'diagnostic'  => array(
						'flow_step_id'       => $flow_step_id,
						'flow_id'            => $flow_id,
						'flow_name'          => $flow['flow_name'] ?? '',
						'pipeline_id'        => $flow['pipeline_id'] ?? null,
						'available_step_ids' => $available_step_ids,
						'step_count'         => count( $available_step_ids ),
					),
					'remediation' => array(
						'action'    => 'use_available_step_id',
						'message'   => empty( $available_step_ids )
							? 'Flow has no steps configured. The flow may need pipeline step synchronization.'
							: sprintf( 'Use one of the available step IDs: %s', implode( ', ', $available_step_ids ) ),
						'tool_hint' => 'configure_flow_steps',
					),
				),
			);
		}

		return array(
			'valid'       => true,
			'step_config' => $flow_config[ $flow_step_id ],
		);
	}

	/**
	 * Validate handler_config fields against handler schema.
	 *
	 * Returns structured error data with field specs when validation fails,
	 * enabling AI agents to self-correct without trial-and-error.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param array  $handler_config Configuration to validate.
	 * @return true|array True if valid, structured error array if invalid.
	 */
	protected function validateHandlerConfig( string $handler_slug, array $handler_config ): true|array {
		$config_fields = $this->handler_abilities->getConfigFields( $handler_slug );
		$valid_fields  = array_keys( $config_fields );

		if ( empty( $valid_fields ) ) {
			return true;
		}

		$unknown_fields = array_diff( array_keys( $handler_config ), $valid_fields );

		if ( ! empty( $unknown_fields ) ) {
			$field_specs = array();
			foreach ( $config_fields as $key => $field ) {
				$spec = array(
					'type'        => $field['type'] ?? 'text',
					'required'    => $field['required'] ?? false,
					'description' => $field['description'] ?? '',
				);
				if ( isset( $field['options'] ) ) {
					$spec['options'] = array_keys( $field['options'] );
				}
				if ( isset( $field['default'] ) ) {
					$spec['default'] = $field['default'];
				}
				$field_specs[ $key ] = $spec;
			}

			return array(
				'error'          => sprintf(
					'Unknown handler_config fields for %s: %s. Valid fields: %s',
					$handler_slug,
					implode( ', ', $unknown_fields ),
					implode( ', ', $valid_fields )
				),
				'unknown_fields' => $unknown_fields,
				'field_specs'    => $field_specs,
			);
		}

		return true;
	}

	/**
	 * Map handler config fields when switching handlers.
	 *
	 * @param array  $existing_config Current handler_config.
	 * @param string $target_handler Target handler slug.
	 * @param array  $explicit_map Explicit field mappings (old_field => new_field).
	 * @return array Mapped config with only valid target handler fields.
	 */
	protected function mapHandlerConfig( array $existing_config, string $target_handler, array $explicit_map ): array {
		$target_fields = array_keys( $this->handler_abilities->getConfigFields( $target_handler ) );

		if ( empty( $target_fields ) ) {
			return array();
		}

		$mapped_config = array();

		foreach ( $existing_config as $field => $value ) {
			if ( isset( $explicit_map[ $field ] ) ) {
				$mapped_field = $explicit_map[ $field ];
				if ( in_array( $mapped_field, $target_fields, true ) ) {
					$mapped_config[ $mapped_field ] = $value;
				}
				continue;
			}

			if ( in_array( $field, $target_fields, true ) ) {
				$mapped_config[ $field ] = $value;
			}
		}

		return $mapped_config;
	}

	/**
	 * Update handler configuration for a flow step.
	 *
	 * @param string $flow_step_id Flow step ID (format: pipeline_step_id_flow_id).
	 * @param string $handler_slug Handler slug to set (uses existing if empty).
	 * @param array  $handler_settings Handler configuration settings.
	 * @return bool Success status.
	 */
	protected function updateHandler( string $flow_step_id, string $handler_slug = '', array $handler_settings = array() ): bool {
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts ) {
			do_action( 'datamachine_log', 'error', 'Invalid flow_step_id format for handler update', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}
		$flow_id = $parts['flow_id'];

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow handler update failed - flow not found',
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			if ( ! isset( $parts['pipeline_step_id'] ) || empty( $parts['pipeline_step_id'] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Pipeline step ID is required for flow handler update',
					array(
						'flow_step_id' => $flow_step_id,
						'parts'        => $parts,
					)
				);
				return false;
			}
			$pipeline_step_id             = $parts['pipeline_step_id'];
			$flow_config[ $flow_step_id ] = array(
				'flow_step_id'     => $flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $flow['pipeline_id'],
				'flow_id'          => $flow_id,
				'handler'          => null,
			);
		}

		// Priority: explicit handler_slug > existing handler_slug > step_type (for non-handler steps like agent_ping).
		$effective_slug = ! empty( $handler_slug )
			? $handler_slug
			: ( ! empty( $flow_config[ $flow_step_id ]['handler_slug'] )
				? $flow_config[ $flow_step_id ]['handler_slug']
				: ( $flow_config[ $flow_step_id ]['step_type'] ?? null ) );

		if ( empty( $effective_slug ) ) {
			do_action( 'datamachine_log', 'error', 'No handler slug or step_type available for flow step update', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		// If switching handlers, strip legacy config fields that don't belong to the new handler.
		if ( ( $flow_config[ $flow_step_id ]['handler_slug'] ?? '' ) !== $effective_slug ) {
			$valid_fields = array_keys( $this->handler_abilities->getConfigFields( $effective_slug ) );
			if ( ! empty( $valid_fields ) ) {
				$existing_handler_config = array_intersect_key( $flow_config[ $flow_step_id ]['handler_config'] ?? array(), array_flip( $valid_fields ) );
			} else {
				$existing_handler_config = array();
			}
		} else {
			$existing_handler_config = $flow_config[ $flow_step_id ]['handler_config'] ?? array();
		}

		$flow_config[ $flow_step_id ]['handler_slug']   = $effective_slug;
		$merged_config                                  = array_merge( $existing_handler_config, $handler_settings );
		$flow_config[ $flow_step_id ]['handler_config'] = $this->handler_abilities->applyDefaults( $effective_slug, $merged_config );
		$flow_config[ $flow_step_id ]['enabled']        = true;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array(
				'flow_config' => $flow_config,
			)
		);

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow handler update failed - database update failed',
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'handler_slug' => $handler_slug,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Update user message for an AI flow step.
	 *
	 * @param string $flow_step_id Flow step ID (format: pipeline_step_id_flow_id).
	 * @param string $user_message User message content.
	 * @return bool Success status.
	 */
	protected function updateUserMessage( string $flow_step_id, string $user_message ): bool {
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts ) {
			do_action( 'datamachine_log', 'error', 'Invalid flow_step_id format for user message update', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}
		$flow_id = $parts['flow_id'];

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow user message update failed - flow not found',
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow user message update failed - flow step not found',
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return false;
		}

		$flow_config[ $flow_step_id ]['user_message'] = wp_unslash( sanitize_textarea_field( $user_message ) );

		$success = $this->db_flows->update_flow(
			$flow_id,
			array(
				'flow_config' => $flow_config,
			)
		);

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow user message update failed - database update error',
				array(
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return false;
		}

		return true;
	}
}
