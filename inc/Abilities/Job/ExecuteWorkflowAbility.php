<?php
/**
 * Execute Workflow Ability
 *
 * Executes ephemeral workflows — raw JSON steps without a database flow
 * or pipeline. For database flow execution, use datamachine/run-flow.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class ExecuteWorkflowAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/execute-workflow',
				array(
					'label'               => __( 'Execute Workflow', 'data-machine' ),
					'description'         => __( 'Execute an ephemeral workflow from raw JSON steps. For database flow execution, use datamachine/run-flow.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'workflow' ),
						'properties' => array(
							'workflow'     => array(
								'type'        => 'object',
								'description' => __( 'Ephemeral workflow with steps array', 'data-machine' ),
								'properties'  => array(
									'steps' => array(
										'type'        => 'array',
										'description' => __( 'Array of step objects with type, handler_slug, handler_config', 'data-machine' ),
									),
								),
							),
							'timestamp'    => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Future Unix timestamp for delayed execution. Omit for immediate execution.', 'data-machine' ),
							),
							'initial_data' => array(
								'type'        => 'object',
								'description' => __( 'Optional initial engine data to merge into the engine data alongside configs.', 'data-machine' ),
							),
							'dry_run'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Preview execution without creating posts. Returns preview data instead of publishing.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'execution_mode' => array( 'type' => 'string' ),
							'execution_type' => array( 'type' => 'string' ),
							'job_id'         => array( 'type' => 'integer' ),
							'step_count'     => array( 'type' => 'integer' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute an ephemeral workflow.
	 *
	 * @param array $input Input parameters with workflow, optional timestamp, initial_data, dry_run.
	 * @return array Result with job_id and execution info.
	 */
	public function execute( array $input ): array {
		$workflow     = $input['workflow'] ?? null;
		$timestamp    = $input['timestamp'] ?? null;
		$initial_data = is_array( $input['initial_data'] ?? null ) ? $input['initial_data'] : array();

		// Validate workflow structure
		$validation = $this->validateWorkflow( $workflow );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}

		// Build configs from workflow
		$configs = WorkflowConfigFactory::buildEphemeralConfigs( $workflow );

		// Create job record for direct execution. Honor parent_job_id
		// from initial_data so callers (e.g. TaskScheduler scheduling
		// fan-out children, scheduleBatch passing through caller
		// linkage) can stamp the indexed parent_job_id column — the
		// path Jobs::get_children walks for status / undo.
		$create_args = array(
			'pipeline_id' => 'direct',
			'flow_id'     => 'direct',
			'source'      => 'chat',
			'label'       => 'Chat Workflow',
		);

		$initial_parent_job_id = (int) ( $initial_data['parent_job_id'] ?? 0 );
		if ( $initial_parent_job_id > 0 ) {
			$create_args['parent_job_id'] = $initial_parent_job_id;
		}

		$job_id = $this->db_jobs->create_job( $create_args );

		if ( ! $job_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create job record',
			);
		}

		// Build engine data with caller data underneath engine-owned configs.
		$engine_data                    = $initial_data;
		$engine_data['flow_config']     = $configs['flow_config'];
		$engine_data['pipeline_config'] = $configs['pipeline_config'];

		// Mirror RunFlowAbility's engine_data['job'] shape so downstream
		// step types (AIStep, SystemTaskStep) can read job + agent
		// identity from the engine snapshot the same way they do for
		// flow jobs. Callers (e.g. TaskScheduler) may provide a partial
		// 'job' snapshot in initial_data with agent_id/user_id; this
		// layers our authoritative job_id on top of any caller-provided
		// snapshot.
		$caller_snapshot = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
		$job_snapshot    = array_merge(
			array( 'user_id' => (int) ( $initial_data['user_id'] ?? 0 ) ),
			$caller_snapshot,
			array( 'job_id' => $job_id )
		);
		if ( ! empty( $initial_data['agent_id'] ) && empty( $job_snapshot['agent_id'] ) ) {
			$job_snapshot['agent_id'] = (int) $initial_data['agent_id'];
		}
		$engine_data['job'] = $job_snapshot;

		// Set dry_run_mode flag for preview execution
		if ( ! empty( $input['dry_run'] ) ) {
			$engine_data['dry_run_mode'] = true;
		}

		$this->db_jobs->store_engine_data( $job_id, $engine_data );

		try {
			$first_step_id = ExecutionPlan::from_flow_config( $configs['flow_config'] )->first_step_id();
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Workflow execution failed - invalid execution plan',
				array(
					'job_id' => $job_id,
					'error'  => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

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
		}

		// Step types own their config requirements — handler_slug
		// presence, handler_config shape, and any other per-type
		// invariants are validated by each step's own executeStep() at
		// runtime, not here. The workflow validator only enforces
		// structural invariants (the workflow has steps; each step has
		// a registered type) and leaves per-type validation to the
		// step types themselves.

		return array( 'valid' => true );
	}

}
