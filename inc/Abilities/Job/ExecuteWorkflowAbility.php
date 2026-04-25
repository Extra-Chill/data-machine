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
			$handler_slug   = $step['handler_slug'] ?? '';
			$handler_config = $step['handler_config'] ?? array();
			$step_type      = $step['type'];

			// Resolve handler_slugs. Single-purpose: it names the step's
			// handler (always length 0..1). Three shapes match
			// inc/migrations/handler-keys.php (v0.60.0):
			//
			//   1. Explicit handler_slug → [handler_slug] (fetch, publish,
			//      upsert, …).
			//   2. Self-configuring step types (system_task, webhook_gate,
			//      agent_ping) with a non-empty handler_config → [step_type].
			//      Synthetic-slug shape lets FlowStepConfig::getPrimary
			//      HandlerConfig() resolve uniformly via handler_slugs[0].
			//   3. Otherwise → []. AI steps always land here: their tool
			//      list lives in `enabled_tools`, not handler_slugs.
			$handler_slugs = array();
			if ( ! empty( $handler_slug ) ) {
				$handler_slugs = array( $handler_slug );
			} elseif ( 'ai' !== $step_type && ! empty( $handler_config ) ) {
				$handler_slugs = array( $step_type );
			}

			// Key handler_configs by the primary slug (handler_slugs[0]) so
			// FlowStepConfig::getPrimaryHandlerConfig() finds the slot
			// without branching on step type.
			$handler_configs = array();
			if ( ! empty( $handler_slug ) ) {
				$handler_configs[ $handler_slug ] = $handler_config;
			} elseif ( 'ai' !== $step_type && ! empty( $handler_config ) ) {
				$handler_configs[ $step_type ] = $handler_config;
			}

			// AI's tool list lives in its own field. handler_slugs is for
			// handlers; enabled_tools is for AI tools. No overload.
			$enabled_tools = ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
				? array_values( $step['enabled_tools'] )
				: array();

			$flow_config[ $step_id ] = array(
				'flow_step_id'     => $step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step['type'],
				'execution_order'  => $index,
				'handler_slugs'    => $handler_slugs,
				'handler_configs'  => $handler_configs,
				'enabled_tools'    => $enabled_tools,
				'user_message'     => $step['user_message'] ?? '',
				'disabled_tools'   => $step['disabled_tools'] ?? array(),
				'pipeline_id'      => 'direct',
				'flow_id'          => 'direct',
			);

			// Pipeline config (AI settings only — model/provider resolved via context system, not stored here).
			if ( 'ai' === $step['type'] ) {
				$pipeline_config[ $pipeline_step_id ] = array(
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
