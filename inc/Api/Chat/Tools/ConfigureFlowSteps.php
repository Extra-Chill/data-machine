<?php
/**
 * Configure Flow Steps Tool
 *
 * Configures handler settings or AI user messages on flow steps.
 * Supports both single-step and bulk pipeline-scoped operations.
 * Delegates to FlowStepAbilities for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.4.2
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ConfigureFlowSteps extends BaseTool {

	public function __construct() {
		$this->registerTool( 'configure_flow_steps', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$handler_docs = HandlerDocumentation::buildAllHandlersSections();

		$description = 'Configure flow steps with handlers or AI user messages. REQUIRES EXPLICIT TARGETING to prevent accidental bulk updates.' . "\n\n"
			. 'SELECTION MODES (all explicit):' . "\n"
			. '1. By flow_step_id: flow_step_id="18_abc_138" (single) or flow_step_ids=["18_abc_138","18_abc_137"] (multiple)' . "\n"
			. '2. By handler within pipeline: pipeline_id=18, handler_slug="dice_fm" (only flows using dice_fm)' . "\n"
			. '3. By handler globally: handler_slug="dice_fm" (all flows using dice_fm across ALL pipelines)' . "\n"
			. '4. All flows in pipeline (explicit): pipeline_id=18, all_flows=true' . "\n"
			. '5. Cross-pipeline: updates=[{flow_id, step_configs}] for different settings per flow' . "\n\n"
			. 'SAFETY: pipeline_id alone will ERROR. You must also provide handler_slug (filter), all_flows=true, or specific flow_step_id(s).' . "\n\n"
			. 'HANDLER SWITCHING:' . "\n"
			. '- Use target_handler_slug to switch handlers' . "\n"
			. '- field_map maps old fields to new fields (e.g. {"endpoint_url": "source_url"})' . "\n"
			. '- Fields with matching names auto-map without explicit field_map' . "\n\n"
			. 'PER-FLOW CONFIG (bulk mode):' . "\n"
			. '- flow_configs: [{flow_id: 9, handler_config: {source_url: "..."}}]' . "\n"
			. '- Per-flow config merges with shared handler_config (per-flow takes precedence)' . "\n\n"
			. 'BEFORE CONFIGURING:' . "\n"
			. '- Query existing flows to learn established patterns' . "\n"
			. '- Only use handler_config fields documented below - unknown fields are rejected' . "\n\n"
			. $handler_docs;

		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $description,
			'parameters'  => array(
				'flow_step_id'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Single flow step ID (format: {pipeline_step_id}_{flow_id})',
				),
				'flow_step_ids'       => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of flow step IDs for batch updates on specific steps',
				),
				'pipeline_id'         => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Pipeline scope. REQUIRES either handler_slug (filter) or all_flows=true',
				),
				'all_flows'           => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'When true with pipeline_id, applies to ALL flows in pipeline. Explicit opt-in required for bulk operations.',
				),
				'step_type'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter by step type (fetch, publish, update, ai)',
				),
				'handler_slug'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Handler slug to set (single mode) OR filter by existing handler (bulk mode). Works with or without pipeline_id scope.',
				),
				'target_handler_slug' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Handler to switch TO. When provided, handler_slug filters existing handlers (bulk) and target_handler_slug sets the new handler.',
				),
				'field_map'           => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Field mappings when switching handlers, e.g. {"endpoint_url": "source_url"}. Fields with matching names auto-map by default.',
				),
				'handler_config'      => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Handler-specific configuration to merge into existing config',
				),
				'flow_configs'        => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Per-flow configurations for bulk mode. Array of {flow_id: int, handler_config: object}. Merged with shared handler_config (per-flow takes precedence).',
				),
				'user_message'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'User message/prompt for AI steps',
				),
				'updates'             => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Cross-pipeline mode: configure multiple flows with different settings. Each item: {flow_id, step_configs (keyed by step_type: {handler_slug?, handler_config?, user_message?})}',
				),
				'shared_config'       => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Shared step config for cross-pipeline mode (keyed by step_type). Per-flow step_configs override these.',
				),
				'validate_only'       => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Dry-run mode: validate configuration without executing. Returns what would be updated.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$flow_step_id        = $parameters['flow_step_id'] ?? null;
		$flow_step_ids       = $parameters['flow_step_ids'] ?? array();
		$pipeline_id         = isset( $parameters['pipeline_id'] ) ? (int) $parameters['pipeline_id'] : null;
		$all_flows           = ! empty( $parameters['all_flows'] );
		$step_type           = $parameters['step_type'] ?? null;
		$handler_slug        = $parameters['handler_slug'] ?? null;
		$target_handler_slug = $parameters['target_handler_slug'] ?? null;
		$field_map           = $parameters['field_map'] ?? array();
		$handler_config      = $parameters['handler_config'] ?? array();
		$flow_configs        = $parameters['flow_configs'] ?? array();
		$user_message        = $parameters['user_message'] ?? null;
		$updates             = $parameters['updates'] ?? array();
		$shared_config       = $parameters['shared_config'] ?? array();
		$validate_only       = ! empty( $parameters['validate_only'] );

		// 1. Cross-pipeline mode: updates array provided
		if ( ! empty( $updates ) && is_array( $updates ) ) {
			return $this->handleCrossPipelineMode( $updates, $shared_config, $validate_only );
		}

		// 2. Specific flow_step_id(s) - always works
		if ( ! empty( $flow_step_id ) ) {
			return $this->handleSingleMode( $flow_step_id, $handler_slug, $target_handler_slug, $field_map, $handler_config, $user_message );
		}

		if ( ! empty( $flow_step_ids ) && is_array( $flow_step_ids ) ) {
			return $this->handleMultipleStepsMode( $flow_step_ids, $handler_slug, $target_handler_slug, $field_map, $handler_config, $user_message );
		}

		// 3. Handler filter (with optional pipeline scope) - works globally or scoped
		if ( ! empty( $handler_slug ) && empty( $target_handler_slug ) ) {
			// handler_slug alone acts as a filter, requiring target_handler_slug or handler_config for changes
			if ( empty( $handler_config ) && empty( $user_message ) ) {
				return array(
					'success'   => false,
					'error'     => 'handler_slug provided as filter but no changes specified. Provide handler_config, user_message, or target_handler_slug.',
					'tool_name' => 'configure_flow_steps',
				);
			}
		}

		// 4. Pipeline-scoped operations require explicit targeting
		if ( ! empty( $pipeline_id ) ) {
			// SAFETY: pipeline_id alone is forbidden - require explicit selection
			$has_handler_filter = ! empty( $handler_slug );
			$has_explicit_all   = $all_flows;

			if ( ! $has_handler_filter && ! $has_explicit_all ) {
				return array(
					'success'     => false,
					'error'       => 'pipeline_id requires explicit targeting to prevent accidental bulk updates',
					'error_type'  => 'safety_guard',
					'tool_name'   => 'configure_flow_steps',
					'remediation' => array(
						'options' => array(
							'Filter by handler: add handler_slug to target only flows using that handler',
							'Explicit bulk: add all_flows=true to confirm you want ALL flows in the pipeline',
							'Specific steps: use flow_step_id or flow_step_ids instead of pipeline_id',
						),
						'example' => 'pipeline_id=18, handler_slug="dice_fm" OR pipeline_id=18, all_flows=true',
					),
				);
			}

			// Handle validate_only mode for bulk operations
			if ( $validate_only ) {
				return $this->handleValidateOnly( $pipeline_id, $step_type, $handler_slug, $target_handler_slug, $handler_config, $flow_configs );
			}

			// Validation: target_handler_slug requires valid handler
			if ( ! empty( $target_handler_slug ) ) {
				$ability = wp_get_ability( 'datamachine/validate-handler' );
				if ( ! $ability ) {
					return array(
						'success'   => false,
						'error'     => 'Handler validation ability not available',
						'tool_name' => 'configure_flow_steps',
					);
				}
				$validation_result = $ability->execute( array( 'handler_slug' => $target_handler_slug ) );
				if ( is_wp_error( $validation_result ) || ! ( $validation_result['valid'] ?? false ) ) {
					return $this->buildErrorResponse( "Target handler '{$target_handler_slug}' not found", 'configure_flow_steps' );
				}
			}

			return $this->handleBulkMode( $pipeline_id, $step_type, $handler_slug, $target_handler_slug, $field_map, $handler_config, $flow_configs, $user_message );
		}

		// 5. Global handler filter (no pipeline_id) - target all flows using this handler
		if ( ! empty( $handler_slug ) ) {
			// Validation: target_handler_slug requires valid handler
			if ( ! empty( $target_handler_slug ) ) {
				$ability = wp_get_ability( 'datamachine/validate-handler' );
				if ( ! $ability ) {
					return array(
						'success'   => false,
						'error'     => 'Handler validation ability not available',
						'tool_name' => 'configure_flow_steps',
					);
				}
				$validation_result = $ability->execute( array( 'handler_slug' => $target_handler_slug ) );
				if ( is_wp_error( $validation_result ) || ! ( $validation_result['valid'] ?? false ) ) {
					return $this->buildErrorResponse( "Target handler '{$target_handler_slug}' not found", 'configure_flow_steps' );
				}
			}

			return $this->handleGlobalHandlerMode( $handler_slug, $step_type, $target_handler_slug, $field_map, $handler_config, $user_message, $validate_only );
		}

		// 6. No valid selection
		return array(
			'success'     => false,
			'error'       => 'No target specified',
			'error_type'  => 'missing_target',
			'tool_name'   => 'configure_flow_steps',
			'remediation' => array(
				'options' => array(
					'flow_step_id: single step by ID',
					'flow_step_ids: array of specific step IDs',
					'pipeline_id + handler_slug: filter by handler within pipeline',
					'pipeline_id + all_flows=true: all flows in pipeline (explicit)',
					'handler_slug: all flows using this handler globally',
					'updates: cross-pipeline mode with per-flow settings',
				),
			),
		);
	}

	/**
	 * Handle single flow step configuration.
	 */
	private function handleSingleMode(
		string $flow_step_id,
		?string $handler_slug,
		?string $target_handler_slug,
		array $field_map,
		array $handler_config,
		?string $user_message
	): array {
		$ability = wp_get_ability( 'datamachine/update-flow-step' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Update flow step ability not available',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$effective_slug = $target_handler_slug ?? $handler_slug;

		$input = array( 'flow_step_id' => $flow_step_id );

		if ( ! empty( $effective_slug ) ) {
			$input['handler_slug'] = $effective_slug;
		}

		if ( ! empty( $handler_config ) ) {
			$input['handler_config'] = $handler_config;
		}

		if ( ! empty( $user_message ) ) {
			$input['user_message'] = $user_message;
		}

		$result = $ability->execute( $input );

		$result['tool_name'] = 'configure_flow_steps';

		if ( $result['success'] ) {
			$result['data'] = array(
				'flow_step_id' => $flow_step_id,
				'message'      => $result['message'] ?? 'Flow step configured successfully.',
			);
			if ( ! empty( $effective_slug ) ) {
				$result['data']['handler_slug']    = $effective_slug;
				$result['data']['handler_updated'] = true;
			}
			if ( ! empty( $user_message ) ) {
				$result['data']['user_message_updated'] = true;
			}
			unset( $result['message'] );
		}

		return $result;
	}

	/**
	 * Handle multiple specific flow steps by ID array.
	 *
	 * @param array   $flow_step_ids Array of flow step IDs.
	 * @param ?string $handler_slug Handler slug to set.
	 * @param ?string $target_handler_slug Handler to switch to.
	 * @param array   $field_map Field mappings for handler switching.
	 * @param array   $handler_config Handler configuration.
	 * @param ?string $user_message User message for AI steps.
	 * @return array Tool response.
	 */
	private function handleMultipleStepsMode(
		array $flow_step_ids,
		?string $handler_slug,
		?string $target_handler_slug,
		array $field_map,
		array $handler_config,
		?string $user_message
	): array {
		if ( empty( $flow_step_ids ) ) {
			return array(
				'success'   => false,
				'error'     => 'flow_step_ids array is empty',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$results = array();
		$errors  = array();

		foreach ( $flow_step_ids as $step_id ) {
			if ( ! is_string( $step_id ) || empty( $step_id ) ) {
				$errors[] = array(
					'flow_step_id' => $step_id,
					'error'        => 'Invalid flow_step_id format',
				);
				continue;
			}

			$result = $this->handleSingleMode( $step_id, $handler_slug, $target_handler_slug, $field_map, $handler_config, $user_message );

			if ( $result['success'] ?? false ) {
				$results[] = array(
					'flow_step_id' => $step_id,
					'success'      => true,
				);
			} else {
				$errors[] = array(
					'flow_step_id' => $step_id,
					'error'        => $result['error'] ?? 'Unknown error',
				);
			}
		}

		$success_count = count( $results );
		$error_count   = count( $errors );

		if ( 0 === $success_count ) {
			return array(
				'success'   => false,
				'error'     => "All {$error_count} step(s) failed to update",
				'errors'    => $errors,
				'tool_name' => 'configure_flow_steps',
			);
		}

		$response = array(
			'success'   => true,
			'tool_name' => 'configure_flow_steps',
			'data'      => array(
				'steps_modified' => $success_count,
				'updated_steps'  => $results,
				'message'        => sprintf( 'Updated %d step(s).', $success_count ),
			),
		);

		if ( ! empty( $errors ) ) {
			$response['data']['errors']   = $errors;
			$response['data']['message'] .= sprintf( ' %d error(s).', $error_count );
		}

		return $response;
	}

	/**
	 * Handle global handler mode - configure all flows using a specific handler across ALL pipelines.
	 *
	 * @param string  $handler_slug Handler slug to filter by.
	 * @param ?string $step_type Optional step type filter.
	 * @param ?string $target_handler_slug Handler to switch to.
	 * @param array   $field_map Field mappings for handler switching.
	 * @param array   $handler_config Handler configuration to apply.
	 * @param ?string $user_message User message for AI steps.
	 * @param bool    $validate_only Whether to validate without executing.
	 * @return array Tool response.
	 */
	private function handleGlobalHandlerMode(
		string $handler_slug,
		?string $step_type,
		?string $target_handler_slug,
		array $field_map,
		array $handler_config,
		?string $user_message,
		bool $validate_only
	): array {
		$ability = wp_get_ability( 'datamachine/configure-flow-steps' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Configure flow steps ability not available',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$input = array(
			'handler_slug'  => $handler_slug,
			'global_scope'  => true,
			'validate_only' => $validate_only,
		);

		if ( ! empty( $step_type ) ) {
			$input['step_type'] = $step_type;
		}

		if ( ! empty( $target_handler_slug ) ) {
			$input['target_handler_slug'] = $target_handler_slug;
		}

		if ( ! empty( $field_map ) ) {
			$input['field_map'] = $field_map;
		}

		if ( ! empty( $handler_config ) ) {
			$input['handler_config'] = $handler_config;
		}

		if ( ! empty( $user_message ) ) {
			$input['user_message'] = $user_message;
		}

		$result              = $ability->execute( $input );
		$result['tool_name'] = 'configure_flow_steps';

		if ( $result['success'] ?? false ) {
			if ( $validate_only ) {
				$result['data'] = array(
					'mode'         => 'validate_only',
					'handler_slug' => $handler_slug,
					'global_scope' => true,
					'would_update' => $result['would_update'] ?? array(),
					'message'      => $result['message'] ?? 'Validation passed.',
				);
				unset( $result['would_update'], $result['valid'], $result['mode'] );
			} else {
				$result['data'] = array(
					'handler_slug'   => $handler_slug,
					'global_scope'   => true,
					'flows_updated'  => $result['flows_updated'] ?? 0,
					'steps_modified' => $result['steps_modified'] ?? 0,
					'details'        => $result['updated_steps'] ?? array(),
					'message'        => $result['message'] ?? 'Global handler configuration completed.',
				);

				if ( ! empty( $result['errors'] ) ) {
					$result['data']['errors'] = $result['errors'];
				}

				unset( $result['flows_updated'], $result['steps_modified'], $result['updated_steps'], $result['errors'], $result['mode'] );
			}
		}

		return $result;
	}

	/**
	 * Handle bulk pipeline-scoped configuration.
	 */
	private function handleBulkMode(
		int $pipeline_id,
		?string $step_type,
		?string $handler_slug,
		?string $target_handler_slug,
		array $field_map,
		array $handler_config,
		array $flow_configs,
		?string $user_message
	): array {
		$ability = wp_get_ability( 'datamachine/configure-flow-steps' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Configure flow steps ability not available',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$input = array( 'pipeline_id' => $pipeline_id );

		if ( ! empty( $step_type ) ) {
			$input['step_type'] = $step_type;
		}

		if ( ! empty( $handler_slug ) ) {
			$input['handler_slug'] = $handler_slug;
		}

		if ( ! empty( $target_handler_slug ) ) {
			$input['target_handler_slug'] = $target_handler_slug;
		}

		if ( ! empty( $field_map ) ) {
			$input['field_map'] = $field_map;
		}

		if ( ! empty( $handler_config ) ) {
			$input['handler_config'] = $handler_config;
		}

		if ( ! empty( $flow_configs ) ) {
			$input['flow_configs'] = $flow_configs;
		}

		if ( ! empty( $user_message ) ) {
			$input['user_message'] = $user_message;
		}

		$result = $ability->execute( $input );

		$result['tool_name'] = 'configure_flow_steps';

		if ( $result['success'] ) {
			$result['data'] = array(
				'pipeline_id'    => $result['pipeline_id'],
				'flows_updated'  => $result['flows_updated'],
				'steps_modified' => $result['steps_modified'],
				'details'        => $result['updated_steps'] ?? array(),
				'message'        => $result['message'],
			);

			if ( ! empty( $result['errors'] ) ) {
				$result['data']['errors'] = $result['errors'];
			}

			if ( ! empty( $result['skipped'] ) ) {
				$result['data']['skipped'] = $result['skipped'];
			}

			unset( $result['pipeline_id'], $result['flows_updated'], $result['steps_modified'], $result['updated_steps'], $result['message'], $result['errors'], $result['skipped'] );
		}

		return $result;
	}

	/**
	 * Handle validate_only mode - dry-run validation without execution.
	 */
	private function handleValidateOnly(
		int $pipeline_id,
		?string $step_type,
		?string $handler_slug,
		?string $target_handler_slug,
		array $handler_config,
		array $flow_configs
	): array {
		$ability = wp_get_ability( 'datamachine/validate-flow-steps-config' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Validate flow steps config ability not available',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$input = array( 'pipeline_id' => $pipeline_id );

		if ( ! empty( $step_type ) ) {
			$input['step_type'] = $step_type;
		}

		if ( ! empty( $handler_slug ) ) {
			$input['handler_slug'] = $handler_slug;
		}

		if ( ! empty( $target_handler_slug ) ) {
			$input['target_handler_slug'] = $target_handler_slug;
		}

		if ( ! empty( $handler_config ) ) {
			$input['handler_config'] = $handler_config;
		}

		if ( ! empty( $flow_configs ) ) {
			$input['flow_configs'] = $flow_configs;
		}

		$result              = $ability->execute( $input );
		$result['tool_name'] = 'configure_flow_steps';
		$result['mode']      = 'validate_only';

		return $result;
	}

	/**
	 * Handle cross-pipeline mode - configure multiple flows across different pipelines.
	 *
	 * @param array $updates Array of {flow_id, step_configs} objects.
	 * @param array $shared_config Shared step config applied before per-flow overrides.
	 * @param bool  $validate_only Whether to validate without executing.
	 * @return array Tool response.
	 */
	private function handleCrossPipelineMode( array $updates, array $shared_config, bool $validate_only ): array {
		$ability = wp_get_ability( 'datamachine/configure-flow-steps' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Configure flow steps ability not available',
				'tool_name' => 'configure_flow_steps',
			);
		}

		$result = $ability->execute(
			array(
				'updates'       => $updates,
				'shared_config' => $shared_config,
				'validate_only' => $validate_only,
			)
		);

		$result['tool_name'] = 'configure_flow_steps';

		if ( $result['success'] ?? false ) {
			if ( $validate_only ) {
				$result['data'] = array(
					'mode'         => 'validate_only',
					'would_update' => $result['would_update'] ?? array(),
					'message'      => $result['message'] ?? 'Validation passed.',
				);
				unset( $result['would_update'], $result['valid'], $result['mode'] );
			} else {
				$result['data'] = array(
					'flows_updated'  => $result['flows_updated'],
					'steps_modified' => $result['steps_modified'],
					'details'        => $result['updated_steps'] ?? array(),
					'errors'         => $result['errors'] ?? array(),
					'message'        => $result['message'] ?? 'Cross-pipeline configuration completed.',
					'mode'           => 'cross_pipeline',
				);
				unset( $result['flows_updated'], $result['steps_modified'], $result['updated_steps'], $result['errors'], $result['mode'] );
			}
		}

		return $result;
	}
}
