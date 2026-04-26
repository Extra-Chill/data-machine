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

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\FlowStepConfig;

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
		return PermissionHelper::can_manage();
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
	 * Sanitize handler configuration values via the handler's settings class.
	 *
	 * Delegates to the handler's SettingsClass::sanitize() method, which performs
	 * type coercion, term ID resolution, and value validation. This ensures all
	 * write paths (REST, CLI, chat tools, abilities API) produce consistent data.
	 *
	 * @since 0.38.0
	 * @param string $handler_slug Handler slug.
	 * @param array  $handler_config Raw configuration values to sanitize.
	 * @return array Sanitized configuration values.
	 */
	protected function sanitizeHandlerConfig( string $handler_slug, array $handler_config ): array {
		$settings_class = $this->handler_abilities->getSettingsClass( $handler_slug );

		if ( ! $settings_class || ! method_exists( $settings_class, 'sanitize' ) ) {
			return $handler_config;
		}

		try {
			return $settings_class->sanitize( $handler_config );
		} catch ( \Exception $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'Handler config sanitization failed, using raw values',
				array(
					'handler_slug' => $handler_slug,
					'error'        => $e->getMessage(),
				)
			);
			return $handler_config;
		}
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

		$step = &$flow_config[ $flow_step_id ];

		$uses_handler     = FlowStepConfig::usesHandler( $step );
		$is_multi_handler = FlowStepConfig::isMultiHandler( $step );
		$effective_slug   = $uses_handler
			? FlowStepConfig::getEffectiveSlug( $step, $handler_slug )
			: ( $step['step_type'] ?? '' );

		if ( empty( $effective_slug ) ) {
			do_action( 'datamachine_log', 'error', 'No handler slug or step_type available for flow step update', array( 'flow_step_id' => $flow_step_id ) );
			unset( $step );
			return false;
		}

		// Get existing config for this handler/settings slug.
		$existing_handler_config = FlowStepConfig::getHandlerConfigForSlug( $step, $effective_slug );

		// If switching handlers, strip legacy config fields that don't belong to the new handler.
		$current_primary = FlowStepConfig::getPrimaryHandlerSlug( $step ) ?? '';
		if ( $uses_handler && $current_primary !== $effective_slug ) {
			$valid_fields = array_keys( $this->handler_abilities->getConfigFields( $effective_slug ) );
			if ( ! empty( $valid_fields ) ) {
				$existing_handler_config = array_intersect_key( $existing_handler_config, array_flip( $valid_fields ) );
			} else {
				$existing_handler_config = array();
			}
		}

		// Sanitize incoming values via handler's settings class before merge.
		$handler_settings = $this->sanitizeHandlerConfig( $effective_slug, $handler_settings );

		$merged_config = array_merge( $existing_handler_config, $handler_settings );
		$stored_config = $this->handler_abilities->applyDefaults( $effective_slug, $merged_config );

		if ( ! $uses_handler ) {
			$step['handler_config'] = $stored_config;
			unset( $step['handler_slug'], $step['handler_slugs'], $step['handler_configs'] );
		} elseif ( $is_multi_handler ) {
			$current_slugs = FlowStepConfig::getHandlerSlugs( $step );
			if ( ! in_array( $effective_slug, $current_slugs, true ) ) {
				$current_slugs = array( $effective_slug );
			} elseif ( $current_slugs[0] !== $effective_slug ) {
				$current_slugs = array_values( array_diff( $current_slugs, array( $effective_slug ) ) );
				array_unshift( $current_slugs, $effective_slug );
			}

			$handler_configs                    = is_array( $step['handler_configs'] ?? null ) ? $step['handler_configs'] : array();
			$handler_configs[ $effective_slug ] = $stored_config;
			$step['handler_slugs']              = $current_slugs;
			$step['handler_configs']            = $handler_configs;
			unset( $step['handler_slug'], $step['handler_config'] );
		} else {
			$step['handler_slug']   = $effective_slug;
			$step['handler_config'] = $stored_config;
			unset( $step['handler_slugs'], $step['handler_configs'] );
		}

		$step['enabled'] = true;
		unset( $step );

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
	 * Add an additional handler to a flow step (multi-handler support).
	 *
	 * Adds handler_slug to handler_slugs array and stores its config in handler_configs.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @param string $handler_slug Handler slug to add.
	 * @param array  $handler_config Handler configuration for this handler.
	 * @return bool Success status.
	 */
	protected function addHandler( string $flow_step_id, string $handler_slug, array $handler_config = array() ): bool {
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts ) {
			do_action( 'datamachine_log', 'error', 'Invalid flow_step_id format for add handler', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}
		$flow_id = $parts['flow_id'];

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow not found for add handler', array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
			) );
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			do_action( 'datamachine_log', 'error', 'Flow step not found for add handler', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		$step = &$flow_config[ $flow_step_id ];

		if ( ! FlowStepConfig::isMultiHandler( $step ) ) {
			do_action( 'datamachine_log', 'error', 'Cannot add multiple handlers to single-handler step', array(
				'flow_step_id' => $flow_step_id,
				'step_type'    => $step['step_type'] ?? '',
			) );
			unset( $step );
			return false;
		}

		$existing_slugs = FlowStepConfig::getHandlerSlugs( $step );

		// Don't add duplicates.
		if ( in_array( $handler_slug, $existing_slugs, true ) ) {
			do_action( 'datamachine_log', 'warning', 'Handler already exists on step', array(
				'flow_step_id' => $flow_step_id,
				'handler_slug' => $handler_slug,
			) );
			return true;
		}

		$existing_slugs[]      = $handler_slug;
		$step['handler_slugs'] = $existing_slugs;

		// Sanitize and store per-handler config.
		$handler_configs = $step['handler_configs'] ?? array();

		if ( ! empty( $handler_config ) ) {
			$sanitized_config                 = $this->sanitizeHandlerConfig( $handler_slug, $handler_config );
			$validated_config                 = $this->handler_abilities->applyDefaults( $handler_slug, $sanitized_config );
			$handler_configs[ $handler_slug ] = $validated_config;
		} else {
			$handler_configs[ $handler_slug ] = $this->handler_abilities->applyDefaults( $handler_slug, array() );
		}

		$step['handler_configs'] = $handler_configs;
		$step['enabled']         = true;

		unset( $step );

		$success = $this->db_flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );

		if ( ! $success ) {
			do_action( 'datamachine_log', 'error', 'Failed to add handler to flow step', array(
				'flow_step_id' => $flow_step_id,
				'handler_slug' => $handler_slug,
			) );
			return false;
		}

		do_action(
			'datamachine_log',
			'info',
			'Handler added to flow step',
			array(
				'flow_step_id'   => $flow_step_id,
				'handler_slug'   => $handler_slug,
				'total_handlers' => count( $existing_slugs ),
			)
		);

		return true;
	}

	/**
	 * Remove a handler from a multi-handler flow step.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @param string $handler_slug Handler slug to remove.
	 * @return bool Success status.
	 */
	protected function removeHandler( string $flow_step_id, string $handler_slug ): bool {
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts ) {
			return false;
		}
		$flow_id = $parts['flow_id'];

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return false;
		}

		$step = &$flow_config[ $flow_step_id ];
		if ( ! FlowStepConfig::isMultiHandler( $step ) ) {
			unset( $step );
			return false;
		}

		$existing_slugs = FlowStepConfig::getHandlerSlugs( $step );
		$existing_slugs = array_values( array_filter( $existing_slugs, fn( $s ) => $s !== $handler_slug ) );

		$step['handler_slugs'] = $existing_slugs;

		$handler_configs = $step['handler_configs'] ?? array();
		unset( $handler_configs[ $handler_slug ] );
		$step['handler_configs'] = $handler_configs;

		unset( $step );

		return $this->db_flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );
	}

	/**
	 * Update the per-flow user message for an AI step.
	 *
	 * Post-#1291 the dedicated `user_message` slot is gone. This helper
	 * is the public-facing shim that lets existing callers (CLI's
	 * `--set-user-message`, ConfigureFlowSteps, CreateFlow chat tools,
	 * the React UI's save path) keep their input contract while we
	 * route the write through the unified `prompt_queue` storage:
	 *
	 *   - Replace the entire prompt_queue with a single `{prompt, added_at}`
	 *     entry containing the new user message.
	 *   - Set queue_mode to "static" so the entry is peeked (not popped)
	 *     every tick — this is the direct equivalent of the legacy
	 *     "single user_message that runs every tick" semantic.
	 *
	 * Empty input (`""`) clears the queue entirely. The seeded entry's
	 * shape mirrors `QueueAbility::executeQueueAdd`'s output so any
	 * subsequent `flow queue list` rendering looks identical to a
	 * manually-added prompt.
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

		$sanitized = wp_unslash( sanitize_textarea_field( $user_message ) );

		// Empty input clears the queue entirely (matches the pre-#1291
		// behaviour of unsetting user_message via empty string).
		if ( '' === trim( $sanitized ) ) {
			$flow_config[ $flow_step_id ]['prompt_queue'] = array();
		} else {
			$flow_config[ $flow_step_id ]['prompt_queue'] = array(
				array(
					'prompt'   => $sanitized,
					'added_at' => gmdate( 'c' ),
				),
			);
		}
		$flow_config[ $flow_step_id ]['queue_mode'] = 'static';

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
