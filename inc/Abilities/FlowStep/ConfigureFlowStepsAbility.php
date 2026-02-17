<?php
/**
 * Configure Flow Steps Ability
 *
 * Bulk configure flow steps across a pipeline or globally.
 * Supports handler switching with field mapping.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.15.3
 */

namespace DataMachine\Abilities\FlowStep;

defined( 'ABSPATH' ) || exit;

class ConfigureFlowStepsAbility {

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
				'datamachine/configure-flow-steps',
				array(
					'label'               => __( 'Configure Flow Steps', 'data-machine' ),
					'description'         => __( 'Bulk configure flow steps across a pipeline or globally. Supports handler switching with field mapping.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_id'         => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID for pipeline-scoped bulk mode. Requires handler_slug filter or all_flows=true.', 'data-machine' ),
							),
							'all_flows'           => array(
								'type'        => 'boolean',
								'description' => __( 'When true with pipeline_id, targets ALL flows in pipeline. Explicit opt-in required.', 'data-machine' ),
							),
							'global_scope'        => array(
								'type'        => 'boolean',
								'description' => __( 'When true with handler_slug, targets all flows using that handler across ALL pipelines.', 'data-machine' ),
							),
							'step_type'           => array(
								'type'        => 'string',
								'description' => __( 'Filter by step type (fetch, publish, update, ai)', 'data-machine' ),
							),
							'handler_slug'        => array(
								'type'        => 'string',
								'description' => __( 'Filter by existing handler slug', 'data-machine' ),
							),
							'target_handler_slug' => array(
								'type'        => 'string',
								'description' => __( 'Handler to switch TO', 'data-machine' ),
							),
							'field_map'           => array(
								'type'        => 'object',
								'description' => __( 'Field mappings when switching handlers (old_field => new_field)', 'data-machine' ),
							),
							'handler_config'      => array(
								'type'        => 'object',
								'description' => __( 'Handler configuration to apply to all matching steps', 'data-machine' ),
							),
							'flow_configs'        => array(
								'type'        => 'array',
								'description' => __( 'Per-flow configurations: [{flow_id: int, handler_config: object}]', 'data-machine' ),
							),
							'user_message'        => array(
								'type'        => 'string',
								'description' => __( 'User message for AI steps', 'data-machine' ),
							),
							'updates'             => array(
								'type'        => 'array',
								'description' => __( 'Cross-pipeline mode: configure multiple flows with different settings. Each item: {flow_id, step_configs (keyed by step_type)}', 'data-machine' ),
							),
							'shared_config'       => array(
								'type'        => 'object',
								'description' => __( 'Shared step config for updates mode applied before per-flow overrides (keyed by step_type)', 'data-machine' ),
							),
							'validate_only'       => array(
								'type'        => 'boolean',
								'description' => __( 'Dry-run mode: validate without executing', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'pipeline_id'    => array( 'type' => 'integer' ),
							'updated_steps'  => array( 'type' => 'array' ),
							'flows_updated'  => array( 'type' => 'integer' ),
							'steps_modified' => array( 'type' => 'integer' ),
							'skipped'        => array( 'type' => 'array' ),
							'errors'         => array( 'type' => 'array' ),
							'message'        => array( 'type' => 'string' ),
							'error'          => array( 'type' => 'string' ),
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
	 * Execute configure flow steps ability.
	 *
	 * Supports three modes:
	 * - Single step: via update-flow-step ability
	 * - Same-settings bulk: pipeline_id for all flows in one pipeline
	 * - Cross-pipeline bulk: updates array for different settings across flows
	 *
	 * @param array $input Input parameters.
	 * @return array Result with configuration status.
	 */
	public function execute( array $input ): array {
		// Check for cross-pipeline mode
		if ( ! empty( $input['updates'] ) && is_array( $input['updates'] ) ) {
			return $this->executeCrossPipeline( $input );
		}

		// Check for global handler scope mode
		if ( ! empty( $input['global_scope'] ) && ! empty( $input['handler_slug'] ) ) {
			return $this->executeGlobalHandler( $input );
		}

		$pipeline_id         = $input['pipeline_id'] ?? null;
		$step_type           = $input['step_type'] ?? null;
		$handler_slug        = $input['handler_slug'] ?? null;
		$target_handler_slug = $input['target_handler_slug'] ?? null;
		$field_map           = $input['field_map'] ?? array();
		$handler_config      = $input['handler_config'] ?? array();
		$flow_configs        = $input['flow_configs'] ?? array();
		$user_message        = $input['user_message'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;

		if ( ! empty( $target_handler_slug ) && ! $this->handler_abilities->handlerExists( $target_handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => "Target handler '{$target_handler_slug}' not found",
			);
		}

		$flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		if ( empty( $flows ) ) {
			$pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );

			if ( ! $pipeline ) {
				return array(
					'success'     => false,
					'error'       => 'Pipeline not found',
					'error_type'  => 'not_found',
					'diagnostic'  => array(
						'pipeline_id' => $pipeline_id,
					),
					'remediation' => array(
						'action'    => 'verify_pipeline_id',
						'message'   => 'Pipeline does not exist. Use list_pipelines to find valid pipeline IDs.',
						'tool_hint' => 'api_query',
					),
				);
			}

			$pipeline_config = $pipeline['pipeline_config'] ?? array();
			$step_count      = count( $pipeline_config );

			return array(
				'success'     => false,
				'error'       => 'Pipeline has no flows yet',
				'error_type'  => 'prerequisite_missing',
				'diagnostic'  => array(
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline['pipeline_name'] ?? '',
					'step_count'    => $step_count,
					'has_steps'     => $step_count > 0,
				),
				'remediation' => array(
					'action'    => 'create_flow',
					'message'   => 'Create a flow for this pipeline first using create_flow tool.',
					'tool_hint' => 'create_flow',
				),
			);
		}

		$flow_configs_by_id = array();
		foreach ( $flow_configs as $fc ) {
			if ( isset( $fc['flow_id'] ) ) {
				$flow_configs_by_id[ (int) $fc['flow_id'] ] = $fc['handler_config'] ?? array();
			}
		}

		$found_flow_ids    = array();
		$pipeline_flow_ids = array_column( $flows, 'flow_id' );

		$updated_details = array();
		$errors          = array();
		$skipped         = array();

		foreach ( $flows as $flow ) {
			$flow_id     = (int) $flow['flow_id'];
			$flow_name   = $flow['flow_name'] ?? __( 'Unnamed Flow', 'data-machine' );
			$flow_config = $flow['flow_config'] ?? array();

			foreach ( $flow_config as $flow_step_id => $step_config ) {
				if ( ! empty( $step_type ) ) {
					$config_step_type = $step_config['step_type'] ?? null;
					if ( $config_step_type !== $step_type ) {
						continue;
					}
				}

				if ( ! empty( $handler_slug ) ) {
					$config_handler_slug  = $step_config['handler_slug'] ?? null;
					$config_handler_slugs = $step_config['handler_slugs'] ?? array();
					// Match on singular field OR presence in handler_slugs array.
					if ( $config_handler_slug !== $handler_slug && ! in_array( $handler_slug, $config_handler_slugs, true ) ) {
						continue;
					}
				}

				$existing_handler_slug   = $step_config['handler_slug'] ?? null;
				$existing_handler_config = $step_config['handler_config'] ?? array();

				$effective_handler_slug = $target_handler_slug ?? $existing_handler_slug;

				if ( empty( $effective_handler_slug ) ) {
					$errors[] = array(
						'flow_step_id' => $flow_step_id,
						'flow_id'      => $flow_id,
						'error'        => 'Step has no handler_slug configured and no target_handler_slug provided',
					);
					continue;
				}

				$is_switching = ! empty( $target_handler_slug ) && $target_handler_slug !== $existing_handler_slug;

				if ( $is_switching && ! empty( $existing_handler_config ) ) {
					$mapped_config = $this->mapHandlerConfig( $existing_handler_config, $effective_handler_slug, $field_map );
				} else {
					$mapped_config = array();
				}

				$merged_config = array_merge( $mapped_config, $handler_config );

				if ( isset( $flow_configs_by_id[ $flow_id ] ) ) {
					$found_flow_ids[] = $flow_id;
					$merged_config    = array_merge( $merged_config, $flow_configs_by_id[ $flow_id ] );
				}

				if ( empty( $merged_config ) && empty( $user_message ) && ! $is_switching ) {
					continue;
				}

				if ( ! empty( $merged_config ) ) {
					$validation_result = $this->validateHandlerConfig( $effective_handler_slug, $merged_config );
					if ( true !== $validation_result ) {
						$errors[] = array(
							'flow_step_id'   => $flow_step_id,
							'flow_id'        => $flow_id,
							'error'          => $validation_result['error'],
							'unknown_fields' => $validation_result['unknown_fields'],
							'field_specs'    => $validation_result['field_specs'],
						);
						continue;
					}
				}

				$success = $this->updateHandler( $flow_step_id, $effective_handler_slug, $merged_config );
				if ( ! $success ) {
					$errors[] = array(
						'flow_step_id' => $flow_step_id,
						'flow_id'      => $flow_id,
						'error'        => 'Failed to update handler',
					);
					continue;
				}

				if ( ! empty( $user_message ) ) {
					$message_success = $this->updateUserMessage( $flow_step_id, $user_message );
					if ( ! $message_success ) {
						$errors[] = array(
							'flow_step_id' => $flow_step_id,
							'flow_id'      => $flow_id,
							'error'        => 'Failed to update user message',
						);
						continue;
					}
				}

				$detail = array(
					'flow_id'      => $flow_id,
					'flow_name'    => $flow_name,
					'flow_step_id' => $flow_step_id,
					'handler_slug' => $effective_handler_slug,
				);
				if ( $is_switching ) {
					$detail['switched_from'] = $existing_handler_slug;
				}
				$updated_details[] = $detail;
			}
		}

		foreach ( array_keys( $flow_configs_by_id ) as $requested_flow_id ) {
			if ( ! in_array( $requested_flow_id, $pipeline_flow_ids, true ) ) {
				$skip_entry = array(
					'flow_id' => $requested_flow_id,
					'error'   => 'Flow not found in pipeline',
				);

				$flow = $this->db_flows->get_flow( $requested_flow_id );
				if ( $flow ) {
					$actual_pipeline_id            = (int) ( $flow['pipeline_id'] ?? 0 );
					$skip_entry['actual_pipeline'] = $actual_pipeline_id;
					$skip_entry['remediation']     = sprintf(
						'Flow %d belongs to pipeline %d, not %d. Use pipeline_id=%d or remove from flow_configs.',
						$requested_flow_id,
						$actual_pipeline_id,
						$pipeline_id,
						$actual_pipeline_id
					);
				} else {
					$skip_entry['remediation'] = sprintf(
						'Flow %d does not exist. Use list_flows with pipeline_id=%d to see available flows.',
						$requested_flow_id,
						$pipeline_id
					);
				}

				$skipped[] = $skip_entry;
			}
		}

		$flows_updated  = count( array_unique( array_column( $updated_details, 'flow_id' ) ) );
		$steps_modified = count( $updated_details );

		if ( 0 === $steps_modified && ! empty( $errors ) ) {
			return array(
				'success' => false,
				'error'   => 'No steps were updated. ' . count( $errors ) . ' error(s) occurred.',
				'errors'  => $errors,
				'skipped' => $skipped,
			);
		}

		if ( 0 === $steps_modified ) {
			return array(
				'success' => false,
				'error'   => 'No matching steps found for the specified criteria',
				'skipped' => $skipped,
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow steps configured via ability',
			array(
				'pipeline_id'    => $pipeline_id,
				'flows_updated'  => $flows_updated,
				'steps_modified' => $steps_modified,
			)
		);

		$message = sprintf( 'Updated %d step(s) across %d flow(s).', $steps_modified, $flows_updated );
		if ( ! empty( $skipped ) ) {
			$message .= sprintf( ' %d flow_id(s) skipped.', count( $skipped ) );
		}

		$response = array(
			'success'        => true,
			'pipeline_id'    => $pipeline_id,
			'flows_updated'  => $flows_updated,
			'steps_modified' => $steps_modified,
			'updated_steps'  => $updated_details,
			'message'        => $message,
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		if ( ! empty( $skipped ) ) {
			$response['skipped'] = $skipped;
		}

		return $response;
	}

	/**
	 * Execute cross-pipeline flow step configuration.
	 *
	 * Configures multiple flows across different pipelines with different settings per flow.
	 *
	 * @param array $input Input parameters including updates array and optional shared_config.
	 * @return array Result with configuration status.
	 */
	private function executeCrossPipeline( array $input ): array {
		$updates       = $input['updates'];
		$shared_config = $input['shared_config'] ?? array();
		$validate_only = ! empty( $input['validate_only'] );

		// Pre-validation: validate all flow entries
		$validation_errors = array();
		$flow_cache        = array();

		foreach ( $updates as $index => $update_config ) {
			$flow_id = $update_config['flow_id'] ?? null;

			if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'flow_id is required and must be a positive integer',
					'remediation' => 'Provide a valid flow_id for each update in the updates array',
				);
				continue;
			}

			$flow_id = (int) $flow_id;

			// Cache flow lookups
			if ( ! isset( $flow_cache[ $flow_id ] ) ) {
				$flow_cache[ $flow_id ] = $this->db_flows->get_flow( $flow_id );
			}

			if ( ! $flow_cache[ $flow_id ] ) {
				$validation_errors[] = array(
					'index'       => $index,
					'flow_id'     => $flow_id,
					'error'       => "Flow {$flow_id} not found",
					'remediation' => 'Use api_query tool to find valid flow IDs',
				);
				continue;
			}

			$step_configs = $update_config['step_configs'] ?? array();
			if ( empty( $step_configs ) && empty( $shared_config ) ) {
				$validation_errors[] = array(
					'index'       => $index,
					'flow_id'     => $flow_id,
					'error'       => 'No step_configs provided and no shared_config available',
					'remediation' => 'Provide step_configs or shared_config to configure steps',
				);
			}
		}

		if ( ! empty( $validation_errors ) ) {
			return array(
				'success' => false,
				'error'   => 'Validation failed for ' . count( $validation_errors ) . ' update(s)',
				'errors'  => $validation_errors,
			);
		}

		// Validate-only mode: return preview without executing
		if ( $validate_only ) {
			$preview = array();
			foreach ( $updates as $index => $update_config ) {
				$flow_id      = (int) $update_config['flow_id'];
				$flow         = $flow_cache[ $flow_id ];
				$step_configs = $update_config['step_configs'] ?? array();

				// Merge shared config with per-flow config
				$merged_step_configs = array_merge( $shared_config, $step_configs );

				$preview[] = array(
					'flow_id'            => $flow_id,
					'flow_name'          => $flow['flow_name'] ?? '',
					'pipeline_id'        => $flow['pipeline_id'] ?? null,
					'step_configs_count' => count( $merged_step_configs ),
					'step_types'         => array_keys( $merged_step_configs ),
				);
			}

			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_update' => $preview,
				'message'      => sprintf( 'Validation passed. Would configure %d flow(s).', count( $updates ) ),
			);
		}

		// Execute cross-pipeline configuration
		$updated_details = array();
		$errors          = array();
		$flows_updated   = 0;
		$steps_modified  = 0;

		foreach ( $updates as $index => $update_config ) {
			$flow_id      = (int) $update_config['flow_id'];
			$flow         = $flow_cache[ $flow_id ];
			$flow_name    = $flow['flow_name'] ?? '';
			$flow_config  = $flow['flow_config'] ?? array();
			$step_configs = $update_config['step_configs'] ?? array();

			// Merge shared config with per-flow config (per-flow takes precedence)
			$merged_step_configs = array_merge( $shared_config, $step_configs );

			// Build step_type to flow_step_id mapping
			$step_type_to_flow_step = array();
			foreach ( $flow_config as $flow_step_id => $step_data ) {
				$step_type = $step_data['step_type'] ?? '';
				if ( ! empty( $step_type ) ) {
					$step_type_to_flow_step[ $step_type ] = $flow_step_id;
				}
			}

			$flow_updated    = false;
			$flow_step_count = 0;

			foreach ( $merged_step_configs as $step_type => $config ) {
				$flow_step_id = $step_type_to_flow_step[ $step_type ] ?? null;

				if ( ! $flow_step_id ) {
					$errors[] = array(
						'flow_id'   => $flow_id,
						'step_type' => $step_type,
						'error'     => "No step of type '{$step_type}' found in flow",
					);
					continue;
				}

				$handler_slug   = $config['handler_slug'] ?? null;
				$handler_config = $config['handler_config'] ?? array();
				$user_message   = $config['user_message'] ?? null;

				$effective_slug = $handler_slug ?? ( $flow_config[ $flow_step_id ]['handler_slug'] ?? null );

				if ( ! empty( $handler_config ) && ! empty( $effective_slug ) ) {
					$validation_result = $this->validateHandlerConfig( $effective_slug, $handler_config );
					if ( true !== $validation_result ) {
						$errors[] = array(
							'flow_id'        => $flow_id,
							'flow_step_id'   => $flow_step_id,
							'step_type'      => $step_type,
							'error'          => $validation_result['error'],
							'unknown_fields' => $validation_result['unknown_fields'] ?? array(),
						);
						continue;
					}
				}

				$has_update = ! empty( $handler_slug ) || ! empty( $handler_config ) || null !== $user_message;
				if ( ! $has_update ) {
					continue;
				}

				if ( ! empty( $handler_slug ) || ! empty( $handler_config ) ) {
					$success = $this->updateHandler( $flow_step_id, $effective_slug ?? '', $handler_config );
					if ( ! $success ) {
						$errors[] = array(
							'flow_id'      => $flow_id,
							'flow_step_id' => $flow_step_id,
							'step_type'    => $step_type,
							'error'        => 'Failed to update handler',
						);
						continue;
					}
				}

				if ( null !== $user_message ) {
					$success = $this->updateUserMessage( $flow_step_id, $user_message );
					if ( ! $success ) {
						$errors[] = array(
							'flow_id'      => $flow_id,
							'flow_step_id' => $flow_step_id,
							'step_type'    => $step_type,
							'error'        => 'Failed to update user message',
						);
						continue;
					}
				}

				$updated_details[] = array(
					'flow_id'      => $flow_id,
					'flow_name'    => $flow_name,
					'flow_step_id' => $flow_step_id,
					'step_type'    => $step_type,
					'handler_slug' => $effective_slug,
				);

				$flow_updated = true;
				++$flow_step_count;
			}

			if ( $flow_updated ) {
				++$flows_updated;
				$steps_modified += $flow_step_count;
			}
		}

		if ( 0 === $steps_modified && ! empty( $errors ) ) {
			return array(
				'success' => false,
				'error'   => 'No steps were updated. ' . count( $errors ) . ' error(s) occurred.',
				'errors'  => $errors,
			);
		}

		if ( 0 === $steps_modified ) {
			return array(
				'success' => false,
				'error'   => 'No steps were updated. Check that step_configs keys match flow step types.',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Cross-pipeline flow steps configured via ability',
			array(
				'flows_updated'  => $flows_updated,
				'steps_modified' => $steps_modified,
			)
		);

		$message = sprintf( 'Updated %d step(s) across %d flow(s).', $steps_modified, $flows_updated );
		if ( ! empty( $errors ) ) {
			$message .= sprintf( ' %d error(s) occurred.', count( $errors ) );
		}

		$response = array(
			'success'        => true,
			'flows_updated'  => $flows_updated,
			'steps_modified' => $steps_modified,
			'updated_steps'  => $updated_details,
			'message'        => $message,
			'mode'           => 'cross_pipeline',
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		return $response;
	}

	/**
	 * Execute global handler scope configuration.
	 *
	 * Configures all flows using a specific handler across ALL pipelines.
	 *
	 * @param array $input Input parameters including handler_slug and global_scope.
	 * @return array Result with configuration status.
	 */
	private function executeGlobalHandler( array $input ): array {
		$handler_slug        = $input['handler_slug'];
		$step_type           = $input['step_type'] ?? null;
		$target_handler_slug = $input['target_handler_slug'] ?? null;
		$field_map           = $input['field_map'] ?? array();
		$handler_config      = $input['handler_config'] ?? array();
		$user_message        = $input['user_message'] ?? null;
		$validate_only       = ! empty( $input['validate_only'] );

		if ( ! $this->handler_abilities->handlerExists( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => "Handler '{$handler_slug}' not found",
			);
		}

		if ( ! empty( $target_handler_slug ) && ! $this->handler_abilities->handlerExists( $target_handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => "Target handler '{$target_handler_slug}' not found",
			);
		}

		$all_flows = $this->db_flows->get_all_flows();

		if ( empty( $all_flows ) ) {
			return array(
				'success' => false,
				'error'   => 'No flows found in the system',
			);
		}

		$matching_flows = array();

		foreach ( $all_flows as $flow ) {
			$flow_config = $flow['flow_config'] ?? array();

			foreach ( $flow_config as $flow_step_id => $step_config ) {
				if ( ! empty( $step_type ) ) {
					$config_step_type = $step_config['step_type'] ?? null;
					if ( $config_step_type !== $step_type ) {
						continue;
					}
				}

				$config_handler_slug  = $step_config['handler_slug'] ?? null;
				$config_handler_slugs = $step_config['handler_slugs'] ?? array();
				// Match on singular field OR presence in handler_slugs array.
				if ( $config_handler_slug === $handler_slug || in_array( $handler_slug, $config_handler_slugs, true ) ) {
					$matching_flows[] = array(
						'flow'         => $flow,
						'flow_step_id' => $flow_step_id,
						'step_config'  => $step_config,
					);
				}
			}
		}

		if ( empty( $matching_flows ) ) {
			return array(
				'success' => false,
				'error'   => "No flows found using handler '{$handler_slug}'",
			);
		}

		if ( $validate_only ) {
			$would_update = array();
			foreach ( $matching_flows as $match ) {
				$flow         = $match['flow'];
				$flow_step_id = $match['flow_step_id'];

				$would_update[] = array(
					'flow_id'      => $flow['flow_id'],
					'flow_name'    => $flow['flow_name'] ?? '',
					'pipeline_id'  => $flow['pipeline_id'] ?? null,
					'flow_step_id' => $flow_step_id,
					'handler_slug' => $target_handler_slug ?? $handler_slug,
				);
			}

			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_update' => $would_update,
				'message'      => sprintf( 'Validation passed. Would configure %d step(s) across %d flow(s).', count( $would_update ), count( array_unique( array_column( $would_update, 'flow_id' ) ) ) ),
			);
		}

		$updated_details = array();
		$errors          = array();

		foreach ( $matching_flows as $match ) {
			$flow         = $match['flow'];
			$flow_step_id = $match['flow_step_id'];
			$step_config  = $match['step_config'];
			$flow_id      = (int) $flow['flow_id'];
			$flow_name    = $flow['flow_name'] ?? __( 'Unnamed Flow', 'data-machine' );

			$existing_handler_slug   = $step_config['handler_slug'] ?? null;
			$existing_handler_config = $step_config['handler_config'] ?? array();

			$effective_handler_slug = $target_handler_slug ?? $existing_handler_slug;
			$is_switching           = ! empty( $target_handler_slug ) && $target_handler_slug !== $existing_handler_slug;

			if ( $is_switching && ! empty( $existing_handler_config ) ) {
				$mapped_config = $this->mapHandlerConfig( $existing_handler_config, $effective_handler_slug, $field_map );
			} else {
				$mapped_config = array();
			}

			$merged_config = array_merge( $mapped_config, $handler_config );

			if ( empty( $merged_config ) && empty( $user_message ) && ! $is_switching ) {
				continue;
			}

			if ( ! empty( $merged_config ) ) {
				$validation_result = $this->validateHandlerConfig( $effective_handler_slug, $merged_config );
				if ( true !== $validation_result ) {
					$errors[] = array(
						'flow_step_id'   => $flow_step_id,
						'flow_id'        => $flow_id,
						'error'          => $validation_result['error'],
						'unknown_fields' => $validation_result['unknown_fields'],
					);
					continue;
				}
			}

			$success = $this->updateHandler( $flow_step_id, $effective_handler_slug, $merged_config );
			if ( ! $success ) {
				$errors[] = array(
					'flow_step_id' => $flow_step_id,
					'flow_id'      => $flow_id,
					'error'        => 'Failed to update handler',
				);
				continue;
			}

			if ( ! empty( $user_message ) ) {
				$message_success = $this->updateUserMessage( $flow_step_id, $user_message );
				if ( ! $message_success ) {
					$errors[] = array(
						'flow_step_id' => $flow_step_id,
						'flow_id'      => $flow_id,
						'error'        => 'Failed to update user message',
					);
					continue;
				}
			}

			$detail = array(
				'flow_id'      => $flow_id,
				'flow_name'    => $flow_name,
				'pipeline_id'  => $flow['pipeline_id'] ?? null,
				'flow_step_id' => $flow_step_id,
				'handler_slug' => $effective_handler_slug,
			);
			if ( $is_switching ) {
				$detail['switched_from'] = $existing_handler_slug;
			}
			$updated_details[] = $detail;
		}

		$flows_updated  = count( array_unique( array_column( $updated_details, 'flow_id' ) ) );
		$steps_modified = count( $updated_details );

		if ( 0 === $steps_modified && ! empty( $errors ) ) {
			return array(
				'success' => false,
				'error'   => 'No steps were updated. ' . count( $errors ) . ' error(s) occurred.',
				'errors'  => $errors,
			);
		}

		if ( 0 === $steps_modified ) {
			return array(
				'success' => false,
				'error'   => 'No matching steps found for the specified criteria',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Global handler flow steps configured via ability',
			array(
				'handler_slug'   => $handler_slug,
				'flows_updated'  => $flows_updated,
				'steps_modified' => $steps_modified,
			)
		);

		$message = sprintf( 'Updated %d step(s) across %d flow(s) using handler %s.', $steps_modified, $flows_updated, $handler_slug );

		$response = array(
			'success'        => true,
			'handler_slug'   => $handler_slug,
			'global_scope'   => true,
			'flows_updated'  => $flows_updated,
			'steps_modified' => $steps_modified,
			'updated_steps'  => $updated_details,
			'message'        => $message,
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		return $response;
	}
}
