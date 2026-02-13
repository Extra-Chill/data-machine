<?php
/**
 * Execute Workflow Ability
 *
 * Unified primitive for workflow execution. Supports both database flows (via flow_id)
 * and ephemeral workflows (via workflow steps).
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Abilities\StepTypeAbilities;

defined( 'ABSPATH' ) || exit;

class ExecuteWorkflowAbility {

	use JobHelpers;

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
				'datamachine/execute-workflow',
				array(
					'label'               => __( 'Execute Workflow', 'data-machine' ),
					'description'         => __( 'Execute a workflow immediately or with delayed scheduling. Accepts either flow_id (database flow) OR workflow (ephemeral steps) - mutually exclusive.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Database flow ID to execute (mutually exclusive with workflow)', 'data-machine' ),
							),
							'workflow'     => array(
								'type'        => 'object',
								'description' => __( 'Ephemeral workflow with steps array (mutually exclusive with flow_id)', 'data-machine' ),
								'properties'  => array(
									'steps' => array(
										'type'        => 'array',
										'description' => __( 'Array of step objects with type, handler_slug, handler_config', 'data-machine' ),
									),
								),
							),
							'count'        => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 10,
								'default'     => 1,
								'description' => __( 'Number of times to run (1-10, database flow only). Each run spawns an independent job.', 'data-machine' ),
							),
							'timestamp'    => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Future Unix timestamp for delayed execution. Omit for immediate execution.', 'data-machine' ),
							),
							'initial_data' => array(
								'type'        => 'object',
								'description' => __( 'Optional initial engine data to merge before workflow execution (ephemeral only)', 'data-machine' ),
							),
							'dry_run'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Preview execution without creating posts. Returns preview data instead of publishing (ephemeral only).', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'execution_mode' => array( 'type' => 'string' ),
							'execution_type' => array( 'type' => 'string' ),
							'flow_id'        => array( 'type' => 'integer' ),
							'flow_name'      => array( 'type' => 'string' ),
							'job_id'         => array( 'type' => 'integer' ),
							'job_ids'        => array( 'type' => 'array' ),
							'step_count'     => array( 'type' => 'integer' ),
							'count'          => array( 'type' => 'integer' ),
							'dry_run'        => array( 'type' => 'boolean' ),
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
	 * Execute workflow ability.
	 *
	 * Unified primitive for workflow execution. Handles both database flows (via flow_id)
	 * and ephemeral workflows (via workflow steps).
	 *
	 * @param array $input Input parameters with flow_id OR workflow, plus optional timestamp/count/initial_data.
	 * @return array Result with job_id(s) and execution info.
	 */
	public function execute( array $input ): array {
		$flow_id  = $input['flow_id'] ?? null;
		$workflow = $input['workflow'] ?? null;

		// Validate: must have flow_id OR workflow (mutually exclusive)
		if ( ! $flow_id && ! $workflow ) {
			return array(
				'success' => false,
				'error'   => 'Must provide either flow_id or workflow',
			);
		}

		if ( $flow_id && $workflow ) {
			return array(
				'success' => false,
				'error'   => 'Cannot provide both flow_id and workflow',
			);
		}

		// Route to appropriate execution path
		if ( $flow_id ) {
			return $this->executeDatabaseFlow( $input );
		}

		return $this->executeEphemeralWorkflow( $input );
	}

	/**
	 * Execute a database flow.
	 *
	 * @param array $input Input parameters with flow_id, optional count and timestamp.
	 * @return array Result with job_id(s) and execution info.
	 */
	private function executeDatabaseFlow( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id must be a positive integer',
			);
		}

		$flow_id        = (int) $flow_id;
		$count          = max( 1, min( 10, (int) ( $input['count'] ?? 1 ) ) );
		$timestamp      = $input['timestamp'] ?? null;
		$execution_type = 'immediate';

		if ( ! empty( $timestamp ) && is_numeric( $timestamp ) && (int) $timestamp > time() ) {
			$timestamp      = (int) $timestamp;
			$execution_type = 'delayed';

			if ( $count > 1 ) {
				return array(
					'success' => false,
					'error'   => 'Cannot schedule multiple runs with a timestamp. Use count only for immediate execution.',
				);
			}
		} else {
			$timestamp = null;
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$flow_name   = $flow['flow_name'] ?? "Flow {$flow_id}";
		$pipeline_id = (int) $flow['pipeline_id'];
		$jobs        = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$job_id = $this->createJob( $flow_id, $pipeline_id );

			if ( ! $job_id ) {
				if ( empty( $jobs ) ) {
					return array(
						'success' => false,
						'error'   => 'Failed to create job record',
					);
				}
				break;
			}

			$schedule_time = $timestamp ?? time();
			as_schedule_single_action(
				$schedule_time,
				'datamachine_run_flow_now',
				array( $flow_id, $job_id ),
				'data-machine'
			);

			$jobs[] = $job_id;
		}

		do_action(
			'datamachine_log',
			'info',
			'Workflow executed via ability (database mode)',
			array(
				'flow_id'        => $flow_id,
				'execution_mode' => 'database',
				'execution_type' => $execution_type,
				'job_count'      => count( $jobs ),
				'job_ids'        => $jobs,
			)
		);

		if ( 1 === $count ) {
			$message = 'immediate' === $execution_type
				? 'Flow queued for immediate background execution. It will start within seconds. Use job_id to check status.'
				: 'Flow scheduled for delayed background execution at the specified time.';

			return array(
				'success'        => true,
				'execution_mode' => 'database',
				'execution_type' => $execution_type,
				'flow_id'        => $flow_id,
				'flow_name'      => $flow_name,
				'job_id'         => $jobs[0] ?? null,
				'message'        => $message,
			);
		}

		return array(
			'success'        => true,
			'execution_mode' => 'database',
			'execution_type' => $execution_type,
			'flow_id'        => $flow_id,
			'flow_name'      => $flow_name,
			'count'          => count( $jobs ),
			'job_ids'        => $jobs,
			'message'        => sprintf(
				'Queued %d jobs for flow "%s". Each job will process one item independently.',
				count( $jobs ),
				$flow_name
			),
		);
	}

	/**
	 * Execute an ephemeral workflow.
	 *
	 * @param array $input Input parameters with workflow, optional timestamp and initial_data.
	 * @return array Result with job_id and execution info.
	 */
	private function executeEphemeralWorkflow( array $input ): array {
		$workflow     = $input['workflow'] ?? null;
		$timestamp    = $input['timestamp'] ?? null;
		$initial_data = $input['initial_data'] ?? null;

		// Validate workflow structure
		$validation = $this->validateWorkflow( $workflow );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}

		// Build configs from workflow
		$configs = $this->buildConfigsFromWorkflow( $workflow );

		// Create job record for direct execution
		$job_id = $this->db_jobs->create_job(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'source'      => 'chat',
				'label'       => 'Chat Workflow',
			)
		);

		if ( ! $job_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create job record',
			);
		}

		// Build engine data with configs and optional initial data
		$engine_data = array(
			'flow_config'     => $configs['flow_config'],
			'pipeline_config' => $configs['pipeline_config'],
		);

		if ( ! empty( $initial_data ) && is_array( $initial_data ) ) {
			$engine_data = array_merge( $engine_data, $initial_data );
		}

		// Set dry_run_mode flag for preview execution
		if ( ! empty( $input['dry_run'] ) ) {
			$engine_data['dry_run_mode'] = true;
		}

		$this->db_jobs->store_engine_data( $job_id, $engine_data );

		// Find first step
		$first_step_id = $this->getFirstStepId( $configs['flow_config'] );

		if ( ! $first_step_id ) {
			return array(
				'success' => false,
				'error'   => 'Could not determine first step in workflow',
			);
		}

		$step_count = count( $workflow['steps'] ?? array() );
		$is_dry_run = ! empty( $input['dry_run'] );

		// Immediate execution
		if ( ! $timestamp || ! is_numeric( $timestamp ) || (int) $timestamp <= time() ) {
			do_action( 'datamachine_schedule_next_step', $job_id, $first_step_id, array() );

			do_action(
				'datamachine_log',
				'info',
				'Workflow executed via ability (direct mode)',
				array(
					'execution_mode' => 'direct',
					'execution_type' => 'immediate',
					'job_id'         => $job_id,
					'step_count'     => $step_count,
					'dry_run'        => $is_dry_run,
				)
			);

			$message = $is_dry_run
				? 'Ephemeral workflow dry-run started. No posts will be created - preview data will be returned.'
				: 'Ephemeral workflow execution started';

			$response = array(
				'success'        => true,
				'execution_mode' => 'direct',
				'execution_type' => 'immediate',
				'job_id'         => $job_id,
				'step_count'     => $step_count,
				'message'        => $message,
			);

			if ( $is_dry_run ) {
				$response['dry_run'] = true;
			}

			return $response;
		}

		// Delayed execution
		$timestamp = (int) $timestamp;

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'success' => false,
				'error'   => 'Action Scheduler not available for delayed execution',
			);
		}

		$action_id = as_schedule_single_action(
			$timestamp,
			'datamachine_schedule_next_step',
			array( $job_id, $first_step_id, array() ),
			'data-machine'
		);

		if ( false === $action_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to schedule workflow execution',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Workflow scheduled via ability (direct mode)',
			array(
				'execution_mode' => 'direct',
				'execution_type' => 'delayed',
				'job_id'         => $job_id,
				'step_count'     => $step_count,
				'timestamp'      => $timestamp,
			)
		);

		return array(
			'success'        => true,
			'execution_mode' => 'direct',
			'execution_type' => 'delayed',
			'job_id'         => $job_id,
			'step_count'     => $step_count,
			'timestamp'      => $timestamp,
			'scheduled_time' => wp_date( 'c', $timestamp ),
			'message'        => 'Ephemeral workflow scheduled for one-time execution at ' . wp_date( 'M j, Y g:i A', $timestamp ),
		);
	}

	/**
	 * Validate workflow structure.
	 *
	 * @param array|null $workflow Workflow to validate.
	 * @return array Validation result with 'valid' boolean and optional 'error' string.
	 */
	private function validateWorkflow( $workflow ): array {
		if ( ! isset( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Workflow must contain steps array',
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
			if ( ! isset( $step['type'] ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} missing type",
				);
			}

			if ( ! in_array( $step['type'], $valid_types, true ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} has invalid type: {$step['type']}. Valid types: " . implode( ', ', $valid_types ),
				);
			}

			if ( 'ai' !== $step['type'] && ! isset( $step['handler_slug'] ) ) {
				return array(
					'valid' => false,
					'error' => "Step {$index} missing handler_slug (required for non-AI steps)",
				);
			}
		}

		return array( 'valid' => true );
	}

	/**
	 * Build flow_config and pipeline_config from workflow structure.
	 *
	 * @param array $workflow Workflow with steps.
	 * @return array Array with 'flow_config' and 'pipeline_config' keys.
	 */
	private function buildConfigsFromWorkflow( array $workflow ): array {
		$flow_config     = array();
		$pipeline_config = array();

		foreach ( $workflow['steps'] as $index => $step ) {
			$step_id          = "ephemeral_step_{$index}";
			$pipeline_step_id = "ephemeral_pipeline_{$index}";

			// Flow config (instance-specific)
			$flow_config[ $step_id ] = array(
				'flow_step_id'     => $step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step['type'],
				'execution_order'  => $index,
				'handler_slug'     => $step['handler_slug'] ?? '',
				'handler_config'   => $step['handler_config'] ?? array(),
				'user_message'     => $step['user_message'] ?? '',
				'disabled_tools'   => $step['disabled_tools'] ?? array(),
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
			);

			// Pipeline config (AI settings only)
			if ( 'ai' === $step['type'] ) {
				$pipeline_config[ $pipeline_step_id ] = array(
					'provider'       => $step['provider'] ?? '',
					'model'          => $step['model'] ?? '',
					'system_prompt'  => $step['system_prompt'] ?? '',
					'disabled_tools' => $step['disabled_tools'] ?? array(),
				);
			}
		}

		return array(
			'flow_config'     => $flow_config,
			'pipeline_config' => $pipeline_config,
		);
	}

	/**
	 * Get first step ID from flow_config.
	 *
	 * @param array $flow_config Flow configuration.
	 * @return string|null First step ID or null if not found.
	 */
	private function getFirstStepId( array $flow_config ): ?string {
		foreach ( $flow_config as $step_id => $config ) {
			if ( ( $config['execution_order'] ?? -1 ) === 0 ) {
				return $step_id;
			}
		}
		return null;
	}
}
