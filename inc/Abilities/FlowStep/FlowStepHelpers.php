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
	 * Normalize flow step config to use handler_slugs/handler_configs as source of truth.
	 * Migrates legacy singular handler_slug/handler_config to plural format.
	 * Call this when reading step config from DB.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Normalized step configuration.
	 */
	public static function normalizeHandlerFields( array $step_config ): array {
		if ( ! empty( $step_config['handler_slugs'] ) && is_array( $step_config['handler_slugs'] ) ) {
			// Already has plural — ensure handler_configs exists.
			if ( empty( $step_config['handler_configs'] ) || ! is_array( $step_config['handler_configs'] ) ) {
				$primary = $step_config['handler_slugs'][0] ?? '';
				$config  = $step_config['handler_config'] ?? array();
				$step_config['handler_configs'] = ! empty( $primary ) ? array( $primary => $config ) : array();
			}
			unset( $step_config['handler_slug'], $step_config['handler_config'] );
			return $step_config;
		}

		// Legacy: only has singular fields.
		$slug   = $step_config['handler_slug'] ?? '';
		$config = $step_config['handler_config'] ?? array();

		if ( ! empty( $slug ) ) {
			$step_config['handler_slugs']  = array( $slug );
			$step_config['handler_configs'] = array( $slug => $config );
		} else {
			$step_config['handler_slugs']  = array();
			$step_config['handler_configs'] = array();
		}

		unset( $step_config['handler_slug'], $step_config['handler_config'] );
		return $step_config;
	}

	/**
	 * Get the primary handler slug from a normalized step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return string Primary handler slug.
	 */
	public static function getPrimaryHandlerSlug( array $step_config ): string {
		if ( ! empty( $step_config['handler_slugs'] ) && is_array( $step_config['handler_slugs'] ) ) {
			return $step_config['handler_slugs'][0] ?? '';
		}
		return $step_config['handler_slug'] ?? '';
	}

	/**
	 * Get the primary handler config from a normalized step config.
	 *
	 * @param array $step_config Step configuration array.
	 * @return array Primary handler configuration.
	 */
	public static function getPrimaryHandlerConfig( array $step_config ): array {
		$slug = self::getPrimaryHandlerSlug( $step_config );
		if ( ! empty( $slug ) && ! empty( $step_config['handler_configs'][ $slug ] ) ) {
			return $step_config['handler_configs'][ $slug ];
		}
		return $step_config['handler_config'] ?? array();
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

		// Normalize the existing step config to use plural fields as source of truth.
		$flow_config[ $flow_step_id ] = self::normalizeHandlerFields( $flow_config[ $flow_step_id ] );

		// Priority: explicit handler_slug > existing handler_slugs[0] > step_type (for non-handler steps like agent_ping).
		$effective_slug = ! empty( $handler_slug )
			? $handler_slug
			: ( self::getPrimaryHandlerSlug( $flow_config[ $flow_step_id ] )
				?: ( $flow_config[ $flow_step_id ]['step_type'] ?? null ) );

		if ( empty( $effective_slug ) ) {
			do_action( 'datamachine_log', 'error', 'No handler slug or step_type available for flow step update', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		// Ensure handler_slugs array contains the effective slug.
		$current_slugs = $flow_config[ $flow_step_id ]['handler_slugs'] ?? array();
		if ( ! in_array( $effective_slug, $current_slugs, true ) ) {
			$current_slugs = array( $effective_slug );
		} elseif ( $current_slugs[0] !== $effective_slug ) {
			// Move to first position (primary).
			$current_slugs = array_diff( $current_slugs, array( $effective_slug ) );
			array_unshift( $current_slugs, $effective_slug );
		}
		$flow_config[ $flow_step_id ]['handler_slugs'] = $current_slugs;

		// Get existing config for this handler.
		$existing_handler_config = $flow_config[ $flow_step_id ]['handler_configs'][ $effective_slug ] ?? array();

		// If switching handlers, strip legacy config fields that don't belong to the new handler.
		$current_primary = self::getPrimaryHandlerSlug( $flow_config[ $flow_step_id ] );
		if ( $current_primary !== $effective_slug ) {
			$valid_fields = array_keys( $this->handler_abilities->getConfigFields( $effective_slug ) );
			if ( ! empty( $valid_fields ) ) {
				$existing_handler_config = array_intersect_key( $existing_handler_config, array_flip( $valid_fields ) );
			} else {
				$existing_handler_config = array();
			}
		}

		// Merge and store in handler_configs.
		$merged_config = array_merge( $existing_handler_config, $handler_settings );
		$flow_config[ $flow_step_id ]['handler_configs'][ $effective_slug ] = $this->handler_abilities->applyDefaults( $effective_slug, $merged_config );
		$flow_config[ $flow_step_id ]['enabled'] = true;

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
			do_action( 'datamachine_log', 'error', 'Flow not found for add handler', array( 'flow_id' => $flow_id, 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			do_action( 'datamachine_log', 'error', 'Flow step not found for add handler', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		// Normalize first to handle legacy data.
		$flow_config[ $flow_step_id ] = self::normalizeHandlerFields( $flow_config[ $flow_step_id ] );
		$step = &$flow_config[ $flow_step_id ];

		$existing_slugs = $step['handler_slugs'] ?? array();

		// Don't add duplicates.
		if ( in_array( $handler_slug, $existing_slugs, true ) ) {
			do_action( 'datamachine_log', 'warning', 'Handler already exists on step', array( 'flow_step_id' => $flow_step_id, 'handler_slug' => $handler_slug ) );
			return true;
		}

		$existing_slugs[]      = $handler_slug;
		$step['handler_slugs'] = $existing_slugs;

		// Store per-handler config.
		$handler_configs = $step['handler_configs'] ?? array();

		if ( ! empty( $handler_config ) ) {
			$validated_config                = $this->handler_abilities->applyDefaults( $handler_slug, $handler_config );
			$handler_configs[ $handler_slug ] = $validated_config;
		} else {
			$handler_configs[ $handler_slug ] = $this->handler_abilities->applyDefaults( $handler_slug, array() );
		}

		$step['handler_configs'] = $handler_configs;
		$step['enabled']         = true;

		unset( $step );

		$success = $this->db_flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );

		if ( ! $success ) {
			do_action( 'datamachine_log', 'error', 'Failed to add handler to flow step', array( 'flow_step_id' => $flow_step_id, 'handler_slug' => $handler_slug ) );
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

		// Normalize first to handle legacy data.
		$flow_config[ $flow_step_id ] = self::normalizeHandlerFields( $flow_config[ $flow_step_id ] );
		$step = &$flow_config[ $flow_step_id ];

		$existing_slugs = $step['handler_slugs'] ?? array();
		$existing_slugs = array_values( array_filter( $existing_slugs, fn( $s ) => $s !== $handler_slug ) );

		if ( empty( $existing_slugs ) ) {
			do_action( 'datamachine_log', 'error', 'Cannot remove last handler from step', array( 'flow_step_id' => $flow_step_id ) );
			return false;
		}

		$step['handler_slugs'] = $existing_slugs;

		$handler_configs = $step['handler_configs'] ?? array();
		unset( $handler_configs[ $handler_slug ] );
		$step['handler_configs'] = $handler_configs;

		unset( $step );

		return $this->db_flows->update_flow( $flow_id, array( 'flow_config' => $flow_config ) );
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
