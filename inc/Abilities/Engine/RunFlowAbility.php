<?php
/**
 * Run Flow Ability
 *
 * Executes a flow immediately. Loads flow/pipeline configurations,
 * creates a job record if needed, builds the engine snapshot, and
 * schedules the first step.
 *
 * Backs the datamachine_run_flow_now action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class RunFlowAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/run-flow ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/run-flow',
				array(
					'label'               => __( 'Run Flow', 'data-machine' ),
					'description'         => __( 'Execute a flow immediately. Loads configs, creates job if needed, schedules first step.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to execute.', 'data-machine' ),
							),
							'job_id'       => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Pre-created job ID (optional, for API-triggered executions).', 'data-machine' ),
							),
							'initial_data' => array(
								'type'        => 'object',
								'description' => __( 'Optional initial engine data to merge (e.g. webhook payloads, API context).', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'flow_id'    => array( 'type' => 'integer' ),
							'job_id'     => array( 'type' => 'integer' ),
							'first_step' => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
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
	 * Execute the run-flow ability.
	 *
	 * @param array $input Input with flow_id, optional job_id and initial_data.
	 * @return array Result with success status and execution details.
	 */
	public function execute( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );
		$job_id  = $input['job_id'] ?? null;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow execution failed - flow not found', array( 'flow_id' => $flow_id ) );
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found.', $flow_id ),
			);
		}

		// Check if flow is paused (enabled=false). Safety net for AS hooks
		// that were already queued before the flow was paused.
		$scheduling_config = $flow['scheduling_config'] ?? array();
		if ( ! \DataMachine\Core\Database\Flows\Flows::is_flow_enabled( $scheduling_config ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Flow execution skipped - flow is paused',
				array( 'flow_id' => $flow_id )
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d is paused.', $flow_id ),
			);
		}

		$pipeline_id = (int) $flow['pipeline_id'];

		// Use provided job_id or create new one (for scheduled/recurring flows).
		if ( ! $job_id ) {
			$job_data = array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
				'source'      => 'pipeline',
				'label'       => $flow['flow_name'] ?? null,
				'user_id'     => (int) ( $flow['user_id'] ?? 0 ),
			);

			// Propagate agent_id from flow to job.
			if ( ! empty( $flow['agent_id'] ) ) {
				$job_data['agent_id'] = (int) $flow['agent_id'];
			}

			$job_id = $this->db_jobs->create_job( $job_data );
			if ( ! $job_id ) {
				do_action(
					'datamachine_log',
					'error',
					'Job creation failed - database insert failed',
					array(
						'flow_id'     => $flow_id,
						'pipeline_id' => $pipeline_id,
					)
				);
				return array(
					'success' => false,
					'error'   => 'Job creation failed - database insert failed.',
				);
			}
			do_action(
				'datamachine_log',
				'debug',
				'Job created',
				array(
					'job_id'      => $job_id,
					'flow_id'     => $flow_id,
					'pipeline_id' => $pipeline_id,
				)
			);
		}

		// Transition job from pending to processing.
		$this->db_jobs->start_job( $job_id );

		$flow_config       = $flow['flow_config'] ?? array();
		$scheduling_config = $flow['scheduling_config'] ?? array();

		// Load pipeline config.
		$pipeline        = $this->db_pipelines->get_pipeline( $pipeline_id );
		$pipeline_config = $pipeline['pipeline_config'] ?? array();

		$flow_config     = datamachine_normalize_engine_config( $flow_config );
		$pipeline_config = datamachine_normalize_engine_config( $pipeline_config );

		$job_snapshot = array(
			'job_id'      => $job_id,
			'flow_id'     => $flow_id,
			'pipeline_id' => $pipeline_id,
			'user_id'     => (int) ( $flow['user_id'] ?? 0 ),
			'created_at'  => current_time( 'mysql', true ),
		);

		if ( ! empty( $flow['agent_id'] ) ) {
			$job_snapshot['agent_id'] = (int) $flow['agent_id'];
		}

		$engine_snapshot = array(
			'job'             => $job_snapshot,
			'flow'            => array(
				'name'        => $flow['flow_name'] ?? '',
				'description' => $flow['flow_description'] ?? '',
				'scheduling'  => $scheduling_config,
			),
			'pipeline'        => array(
				'name'        => $pipeline['pipeline_name'] ?? '',
				'description' => $pipeline['pipeline_description'] ?? '',
			),
			'flow_config'     => $flow_config,
			'pipeline_config' => $pipeline_config,
		);

		// Merge initial_data (e.g. webhook payloads, API context) into the
		// engine snapshot. initial_data keys go underneath so engine
		// snapshot keys (job, flow, pipeline, configs) take precedence.
		$initial_data = $input['initial_data'] ?? null;
		if ( ! empty( $initial_data ) && is_array( $initial_data ) ) {
			$engine_snapshot = array_merge( $initial_data, $engine_snapshot );
		}

		// Preserve any pre-existing engine data stored directly on the job.
		$existing_data = \DataMachine\Core\EngineData::retrieve( $job_id );
		if ( ! empty( $existing_data ) ) {
			$engine_snapshot = array_merge( $existing_data, $engine_snapshot );
		}

		datamachine_set_engine_data( $job_id, $engine_snapshot );

		try {
			$first_flow_step_id = ExecutionPlan::from_flow_config( $flow_config )->first_step_id();
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow execution failed - invalid execution plan',
				array(
					'job_id'      => $job_id,
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
					'error'       => $e->getMessage(),
				)
			);
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		if ( ! $first_flow_step_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow execution failed - no first step found',
				array(
					'job_id'      => $job_id,
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Flow execution failed - no first step found.',
			);
		}

		do_action( 'datamachine_schedule_next_step', $job_id, $first_flow_step_id, array() );

		do_action(
			'datamachine_log',
			'info',
			'Flow execution started successfully',
			array(
				'flow_id'    => $flow_id,
				'job_id'     => $job_id,
				'first_step' => $first_flow_step_id,
			)
		);

		return array(
			'success'    => true,
			'flow_id'    => $flow_id,
			'job_id'     => $job_id,
			'first_step' => $first_flow_step_id,
		);
	}
}
