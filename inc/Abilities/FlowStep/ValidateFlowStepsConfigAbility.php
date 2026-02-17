<?php
/**
 * Validate Flow Steps Config Ability
 *
 * Dry-run validation for configure_flow_steps operations.
 * Validates without executing.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.15.3
 */

namespace DataMachine\Abilities\FlowStep;

defined( 'ABSPATH' ) || exit;

class ValidateFlowStepsConfigAbility {

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
				'datamachine/validate-flow-steps-config',
				array(
					'label'               => __( 'Validate Flow Steps Config', 'data-machine' ),
					'description'         => __( 'Dry-run validation for configure_flow_steps operations. Validates without executing.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pipeline_id' ),
						'properties' => array(
							'pipeline_id'         => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID to validate configuration for', 'data-machine' ),
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
							'handler_config'      => array(
								'type'        => 'object',
								'description' => __( 'Handler configuration to validate', 'data-machine' ),
							),
							'flow_configs'        => array(
								'type'        => 'array',
								'description' => __( 'Per-flow configurations: [{flow_id: int, handler_config: object}]', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'valid'             => array( 'type' => 'boolean' ),
							'flow_count'        => array( 'type' => 'integer' ),
							'matching_steps'    => array( 'type' => 'integer' ),
							'would_update'      => array( 'type' => 'array' ),
							'validation_errors' => array( 'type' => 'array' ),
							'warnings'          => array( 'type' => 'array' ),
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
	 * Execute validation of flow steps configuration (dry-run mode).
	 *
	 * Validates all parameters without executing changes, enabling AI agents
	 * to preview what would happen before committing.
	 *
	 * @param array $input Input parameters.
	 * @return array Validation result with would_update and validation_errors.
	 */
	public function execute( array $input ): array {
		$pipeline_id         = $input['pipeline_id'] ?? null;
		$step_type           = $input['step_type'] ?? null;
		$handler_slug        = $input['handler_slug'] ?? null;
		$target_handler_slug = $input['target_handler_slug'] ?? null;
		$handler_config      = $input['handler_config'] ?? array();
		$flow_configs        = $input['flow_configs'] ?? array();

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'valid'             => false,
				'validation_errors' => array(
					array(
						'field' => 'pipeline_id',
						'error' => 'pipeline_id is required and must be a positive integer',
					),
				),
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			return array(
				'valid'             => false,
				'validation_errors' => array(
					array(
						'field'       => 'pipeline_id',
						'error'       => 'Pipeline not found',
						'remediation' => 'Use list_pipelines (api_query) to find valid pipeline IDs.',
					),
				),
			);
		}

		if ( ! empty( $target_handler_slug ) && ! $this->handler_abilities->handlerExists( $target_handler_slug ) ) {
			return array(
				'valid'             => false,
				'validation_errors' => array(
					array(
						'field'       => 'target_handler_slug',
						'error'       => "Target handler '{$target_handler_slug}' not found",
						'remediation' => 'Use list_handlers (api_query) to find valid handler slugs.',
					),
				),
			);
		}

		$flows = $this->db_flows->get_flows_for_pipeline( $pipeline_id );

		if ( empty( $flows ) ) {
			$pipeline_config = $pipeline['pipeline_config'] ?? array();

			return array(
				'valid'             => false,
				'flow_count'        => 0,
				'matching_steps'    => 0,
				'would_update'      => array(),
				'validation_errors' => array(
					array(
						'field'       => 'pipeline_id',
						'error'       => 'Pipeline has no flows yet',
						'diagnostic'  => array(
							'pipeline_name' => $pipeline['pipeline_name'] ?? '',
							'step_count'    => count( $pipeline_config ),
						),
						'remediation' => 'Create a flow first using create_flow tool.',
					),
				),
			);
		}

		$flow_configs_by_id = array();
		foreach ( $flow_configs as $fc ) {
			if ( isset( $fc['flow_id'] ) ) {
				$flow_configs_by_id[ (int) $fc['flow_id'] ] = $fc['handler_config'] ?? array();
			}
		}

		$pipeline_flow_ids    = array_column( $flows, 'flow_id' );
		$would_update         = array();
		$validation_errors    = array();
		$warnings             = array();
		$total_matching_steps = 0;

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

				++$total_matching_steps;

				$existing_handler_slug  = $step_config['handler_slug'] ?? null;
				$effective_handler_slug = $target_handler_slug ?? $existing_handler_slug;

				if ( empty( $effective_handler_slug ) ) {
					$validation_errors[] = array(
						'flow_step_id' => $flow_step_id,
						'flow_id'      => $flow_id,
						'error'        => 'Step has no handler_slug configured and no target_handler_slug provided',
					);
					continue;
				}

				$is_switching  = ! empty( $target_handler_slug ) && $target_handler_slug !== $existing_handler_slug;
				$merged_config = $handler_config;

				if ( isset( $flow_configs_by_id[ $flow_id ] ) ) {
					$merged_config = array_merge( $merged_config, $flow_configs_by_id[ $flow_id ] );
				}

				if ( ! empty( $merged_config ) ) {
					$validation_result = $this->validateHandlerConfig( $effective_handler_slug, $merged_config );
					if ( true !== $validation_result ) {
						$validation_errors[] = array(
							'flow_step_id'   => $flow_step_id,
							'flow_id'        => $flow_id,
							'error'          => $validation_result['error'],
							'unknown_fields' => $validation_result['unknown_fields'],
							'field_specs'    => $validation_result['field_specs'],
						);
						continue;
					}
				}

				$update_preview = array(
					'flow_id'      => $flow_id,
					'flow_name'    => $flow_name,
					'flow_step_id' => $flow_step_id,
					'handler_slug' => $effective_handler_slug,
				);
				if ( $is_switching ) {
					$update_preview['would_switch_from'] = $existing_handler_slug;
				}
				if ( ! empty( $merged_config ) ) {
					$update_preview['config_fields'] = array_keys( $merged_config );
				}
				$would_update[] = $update_preview;
			}
		}

		foreach ( array_keys( $flow_configs_by_id ) as $requested_flow_id ) {
			if ( ! in_array( $requested_flow_id, $pipeline_flow_ids, true ) ) {
				$flow = $this->db_flows->get_flow( $requested_flow_id );
				if ( $flow ) {
					$actual_pipeline_id  = (int) ( $flow['pipeline_id'] ?? 0 );
					$validation_errors[] = array(
						'flow_id'     => $requested_flow_id,
						'error'       => 'Flow belongs to different pipeline',
						'diagnostic'  => array(
							'actual_pipeline_id' => $actual_pipeline_id,
							'requested_pipeline' => $pipeline_id,
						),
						'remediation' => sprintf( 'Use pipeline_id=%d or remove flow %d from flow_configs.', $actual_pipeline_id, $requested_flow_id ),
					);
				} else {
					$validation_errors[] = array(
						'flow_id'     => $requested_flow_id,
						'error'       => 'Flow does not exist',
						'remediation' => sprintf( 'Use list_flows with pipeline_id=%d to see available flows.', $pipeline_id ),
					);
				}
			}
		}

		$is_valid = empty( $validation_errors );

		$response = array(
			'valid'          => $is_valid,
			'pipeline_id'    => $pipeline_id,
			'pipeline_name'  => $pipeline['pipeline_name'] ?? '',
			'flow_count'     => count( $flows ),
			'matching_steps' => $total_matching_steps,
			'would_update'   => $would_update,
		);

		if ( ! empty( $validation_errors ) ) {
			$response['validation_errors'] = $validation_errors;
		}

		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		if ( $is_valid && ! empty( $would_update ) ) {
			$response['message'] = sprintf(
				'Validation passed. Would update %d step(s) across %d flow(s).',
				count( $would_update ),
				count( array_unique( array_column( $would_update, 'flow_id' ) ) )
			);
		}

		return $response;
	}
}
