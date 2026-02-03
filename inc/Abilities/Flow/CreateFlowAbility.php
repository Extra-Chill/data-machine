<?php
/**
 * Create Flow Ability
 *
 * Handles flow creation including single mode and bulk mode.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Api\Flows\FlowScheduling;

defined( 'ABSPATH' ) || exit;

class CreateFlowAbility {

	use FlowHelpers;

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
				'datamachine/create-flow',
				array(
					'label'               => __( 'Create Flow', 'data-machine' ),
					'description'         => __( 'Create a new flow for a pipeline. Supports bulk mode via flows array.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_id'        => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID to create flow for (single mode)', 'data-machine' ),
							),
							'flow_name'          => array(
								'type'        => 'string',
								'default'     => 'Flow',
								'description' => __( 'Name for the new flow', 'data-machine' ),
							),
							'scheduling_config'  => array(
								'type'        => 'object',
								'description' => __( 'Scheduling configuration with interval property', 'data-machine' ),
								'properties'  => array(
									'interval' => array(
										'type'    => 'string',
										'default' => 'manual',
									),
								),
							),
							'flow_config'        => array(
								'type'        => 'object',
								'description' => __( 'Initial flow configuration', 'data-machine' ),
							),
							'step_configs'       => array(
								'type'        => 'object',
								'description' => __( 'Step configurations keyed by step_type (single mode)', 'data-machine' ),
							),
							'flows'              => array(
								'type'        => 'array',
								'description' => __( 'Bulk mode: create multiple flows. Each item: {pipeline_id, flow_name, step_configs?, scheduling_config?}', 'data-machine' ),
							),
							'shared_step_config' => array(
								'type'        => 'object',
								'description' => __( 'Shared step config for bulk mode applied to all flows (keyed by step_type)', 'data-machine' ),
							),
							'validate_only'      => array(
								'type'        => 'boolean',
								'description' => __( 'Dry-run mode: validate without executing', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'flow_id'       => array( 'type' => 'integer' ),
							'flow_name'     => array( 'type' => 'string' ),
							'pipeline_id'   => array( 'type' => 'integer' ),
							'synced_steps'  => array( 'type' => 'integer' ),
							'flow_data'     => array( 'type' => 'object' ),
							'created_count' => array( 'type' => 'integer' ),
							'failed_count'  => array( 'type' => 'integer' ),
							'created'       => array( 'type' => 'array' ),
							'errors'        => array( 'type' => 'array' ),
							'partial'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
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
	 * Execute create flow ability.
	 *
	 * Supports two modes:
	 * - Single mode: Create one flow (pipeline_id required)
	 * - Bulk mode: Create multiple flows (flows array provided)
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flow data on success.
	 */
	public function execute( array $input ): array {
		if ( ! empty( $input['flows'] ) && is_array( $input['flows'] ) ) {
			return $this->executeBulk( $input );
		}

		return $this->executeSingle( $input );
	}

	/**
	 * Execute single flow creation.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flow data.
	 */
	private function executeSingle( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			do_action( 'datamachine_log', 'error', 'Pipeline not found for flow creation', array( 'pipeline_id' => $pipeline_id ) );
			return array(
				'success' => false,
				'error'   => 'Pipeline not found',
			);
		}

		$flow_name = sanitize_text_field( wp_unslash( $input['flow_name'] ?? 'Flow' ) );
		if ( empty( trim( $flow_name ) ) ) {
			$flow_name = 'Flow';
		}

		$scheduling_config = $input['scheduling_config'] ?? array( 'interval' => 'manual' );
		$flow_config       = $input['flow_config'] ?? array();

		$flow_data = array(
			'pipeline_id'       => $pipeline_id,
			'flow_name'         => $flow_name,
			'flow_config'       => $flow_config,
			'scheduling_config' => $scheduling_config,
		);

		$flow_id = $this->db_flows->create_flow( $flow_data );
		if ( ! $flow_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create flow',
				array(
					'pipeline_id' => $pipeline_id,
					'flow_name'   => $flow_name,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Failed to create flow',
			);
		}

		$pipeline_config = $pipeline['pipeline_config'] ?? array();
		$synced_steps    = 0;

		if ( ! empty( $pipeline_config ) ) {
			$pipeline_steps = is_array( $pipeline_config ) ? array_values( $pipeline_config ) : array();
			$this->syncStepsToFlow( $flow_id, $pipeline_id, $pipeline_steps, $pipeline_config );
			$synced_steps = count( $pipeline_config );
		}

		if ( isset( $scheduling_config['interval'] ) && 'manual' !== $scheduling_config['interval'] ) {
			$scheduling_result = FlowScheduling::handle_scheduling_update( $flow_id, $scheduling_config );
			if ( is_wp_error( $scheduling_result ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to schedule flow with Action Scheduler',
					array(
						'flow_id' => $flow_id,
						'error'   => $scheduling_result->get_error_message(),
					)
				);
			}
		}

		$step_configs   = $input['step_configs'] ?? array();
		$config_results = array(
			'applied' => array(),
			'errors'  => array(),
		);
		if ( ! empty( $step_configs ) ) {
			$config_results = $this->applyStepConfigsToFlow( $flow_id, $step_configs );
		}

		$configured_step_types = array_keys( $step_configs );
		$defaults_results      = $this->applySiteDefaultsToUnconfiguredSteps( $flow_id, $configured_step_types );

		if ( ! empty( $defaults_results['applied'] ) ) {
			$config_results['applied'] = array_merge( $config_results['applied'], $defaults_results['applied'] );
		}

		$flow = $this->db_flows->get_flow( $flow_id );

		do_action(
			'datamachine_log',
			'info',
			'Flow created successfully',
			array(
				'flow_id'      => $flow_id,
				'flow_name'    => $flow_name,
				'pipeline_id'  => $pipeline_id,
				'synced_steps' => $synced_steps,
			)
		);

		$result = array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_name'    => $flow_name,
			'pipeline_id'  => $pipeline_id,
			'flow_data'    => $flow,
			'synced_steps' => $synced_steps,
		);

		if ( ! empty( $config_results['applied'] ) ) {
			$result['configured_steps'] = $config_results['applied'];
		}

		if ( ! empty( $config_results['errors'] ) ) {
			$result['configuration_errors'] = $config_results['errors'];
		}

		return $result;
	}

	/**
	 * Execute bulk flow creation.
	 *
	 * @param array $input Input parameters including flows array and optional shared_step_config.
	 * @return array Result with created flows data and error tracking.
	 */
	private function executeBulk( array $input ): array {
		$flows              = $input['flows'];
		$shared_step_config = $input['shared_step_config'] ?? array();
		$validate_only      = ! empty( $input['validate_only'] );

		$validation_errors = array();
		$pipeline_cache    = array();

		foreach ( $flows as $index => $flow_config ) {
			$pipeline_id = $flow_config['pipeline_id'] ?? null;
			$flow_name   = $flow_config['flow_name'] ?? null;

			if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'pipeline_id is required and must be a positive integer',
					'remediation' => 'Provide a valid pipeline_id for each flow in the flows array',
				);
				continue;
			}

			if ( empty( $flow_name ) || ! is_string( $flow_name ) ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'flow_name is required and must be a non-empty string',
					'remediation' => 'Provide a "flow_name" property for each flow in the flows array',
				);
				continue;
			}

			$pipeline_id = (int) $pipeline_id;

			if ( ! isset( $pipeline_cache[ $pipeline_id ] ) ) {
				$pipeline_cache[ $pipeline_id ] = $this->db_pipelines->get_pipeline( $pipeline_id );
			}

			if ( ! $pipeline_cache[ $pipeline_id ] ) {
				$validation_errors[] = array(
					'index'       => $index,
					'flow_name'   => $flow_name,
					'error'       => "Pipeline {$pipeline_id} not found",
					'remediation' => 'Use list_pipelines tool to find valid pipeline IDs',
				);
			}
		}

		if ( ! empty( $validation_errors ) ) {
			return array(
				'success' => false,
				'error'   => 'Validation failed for ' . count( $validation_errors ) . ' flow(s)',
				'errors'  => $validation_errors,
			);
		}

		if ( $validate_only ) {
			$preview = array();
			foreach ( $flows as $index => $flow_config ) {
				$pipeline_id       = (int) $flow_config['pipeline_id'];
				$flow_name         = $flow_config['flow_name'];
				$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );
				$step_configs      = $flow_config['step_configs'] ?? array();

				$merged_step_configs = array_merge( $shared_step_config, $step_configs );

				$preview[] = array(
					'pipeline_id'        => $pipeline_id,
					'pipeline_name'      => $pipeline_cache[ $pipeline_id ]['pipeline_name'] ?? '',
					'flow_name'          => $flow_name,
					'scheduling'         => $scheduling_config['interval'] ?? 'manual',
					'step_configs_count' => count( $merged_step_configs ),
				);
			}

			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_create' => $preview,
				'message'      => sprintf( 'Validation passed. Would create %d flow(s).', count( $flows ) ),
			);
		}

		$created       = array();
		$errors        = array();
		$created_count = 0;
		$failed_count  = 0;

		foreach ( $flows as $index => $flow_config ) {
			$pipeline_id       = (int) $flow_config['pipeline_id'];
			$flow_name         = $flow_config['flow_name'];
			$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );
			$step_configs      = $flow_config['step_configs'] ?? array();

			$merged_step_configs = array_merge( $shared_step_config, $step_configs );

			$single_result = $this->executeSingle(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
					'step_configs'      => $merged_step_configs,
				)
			);

			if ( ! $single_result['success'] ) {
				++$failed_count;
				$errors[] = array(
					'index'       => $index,
					'pipeline_id' => $pipeline_id,
					'flow_name'   => $flow_name,
					'error'       => $single_result['error'],
					'remediation' => 'Check the error message and fix the flow configuration',
				);
				continue;
			}

			$flow_id       = $single_result['flow_id'];
			$flow_step_ids = array_keys( $single_result['flow_data']['flow_config'] ?? array() );

			++$created_count;
			$created_entry = array(
				'pipeline_id'   => $pipeline_id,
				'flow_id'       => $flow_id,
				'flow_name'     => $single_result['flow_name'],
				'synced_steps'  => $single_result['synced_steps'],
				'flow_step_ids' => $flow_step_ids,
			);

			if ( ! empty( $single_result['configured_steps'] ) ) {
				$created_entry['configured_steps'] = $single_result['configured_steps'];
			}

			if ( ! empty( $single_result['configuration_errors'] ) ) {
				$created_entry['configuration_errors'] = $single_result['configuration_errors'];
			}

			$created[] = $created_entry;
		}

		$partial = $created_count > 0 && $failed_count > 0;

		do_action(
			'datamachine_log',
			'info',
			'Bulk flow creation completed',
			array(
				'created_count' => $created_count,
				'failed_count'  => $failed_count,
				'partial'       => $partial,
			)
		);

		if ( 0 === $created_count ) {
			return array(
				'success'       => false,
				'error'         => 'All flow creations failed',
				'created_count' => 0,
				'failed_count'  => $failed_count,
				'errors'        => $errors,
			);
		}

		$message = sprintf( 'Created %d flow(s).', $created_count );
		if ( $failed_count > 0 ) {
			$message .= sprintf( ' %d failed.', $failed_count );
		}

		return array(
			'success'       => true,
			'created_count' => $created_count,
			'failed_count'  => $failed_count,
			'created'       => $created,
			'errors'        => $errors,
			'partial'       => $partial,
			'message'       => $message,
			'creation_mode' => 'bulk',
		);
	}
}
