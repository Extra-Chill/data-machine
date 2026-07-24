<?php
/**
 * Execute Agents API Workflow ability.
 *
 * @package DataMachine\Abilities\Job
 */

namespace DataMachine\Abilities\Job;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use DataMachine\Core\AgentsApiWorkflowJobRecorder;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ExecuteAgentWorkflowAbility {

	use JobHelpers;

	private const SUPPORTED_STEP_TYPES = array( 'ability', 'agent' );

	public function __construct() {
		$this->initDatabases();
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/execute-agent-workflow',
				array(
					'label'               => __( 'Execute Agents API Workflow', 'data-machine' ),
					'description'         => __( 'Execute a simple Agents API workflow spec through the Agents API runner and record it as a Data Machine job.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'spec' ),
						'properties' => array(
							'spec'              => array(
								'type'        => 'object',
								'description' => __( 'Agents API WP_Agent_Workflow_Spec array. Data Machine supports only top-level ability and agent steps here.', 'data-machine' ),
							),
							'inputs'            => array(
								'type'        => 'object',
								'description' => __( 'Inputs passed to WP_Agent_Workflow_Runner.', 'data-machine' ),
							),
							'run_id'            => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Optional caller-stable Agents API run ID.', 'data-machine' ),
							),
							'continue_on_error' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Forwarded to WP_Agent_Workflow_Runner.', 'data-machine' ),
							),
							'metadata'          => array(
								'type'        => 'object',
								'description' => __( 'Optional metadata forwarded to the Agents API workflow result.', 'data-machine' ),
							),
							'label'             => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Optional Data Machine job label.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'job_id'      => array( 'type' => array( 'integer', 'null' ) ),
							'run_id'      => array( 'type' => 'string' ),
							'workflow_id' => array( 'type' => 'string' ),
							'status'      => array( 'type' => 'string' ),
							'steps'       => array( 'type' => 'array' ),
							'output'      => array( 'type' => 'object' ),
							'error'       => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute a simple Agents API workflow spec and record it as a Data Machine job.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input ): array {
		$spec_array = $input['spec'] ?? null;
		if ( ! is_array( $spec_array ) ) {
			return $this->error_response( 'invalid_spec', 'spec must be an object.' );
		}

		$unsupported = $this->validateBridgeableSpec( $spec_array );
		if ( is_wp_error( $unsupported ) ) {
			return $this->error_response( $unsupported->get_error_code(), $unsupported->get_error_message(), $unsupported->get_error_data() );
		}

		if ( ! class_exists( WP_Agent_Workflow_Spec::class ) || ! class_exists( WP_Agent_Workflow_Runner::class ) ) {
			return $this->error_response( 'agents_api_workflows_unavailable', 'Agents API workflow classes are unavailable.' );
		}

		$spec = WP_Agent_Workflow_Spec::from_array( $spec_array );
		if ( is_wp_error( $spec ) ) {
			return $this->error_response( $spec->get_error_code(), $spec->get_error_message(), $spec->get_error_data() );
		}

		$recorder = new AgentsApiWorkflowJobRecorder(
			$this->db_jobs,
			$spec->to_array(),
			array(
				'label'    => is_string( $input['label'] ?? null ) ? $input['label'] : null,
				'user_id'  => get_current_user_id(),
				'agent_id' => isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null,
			)
		);

		$options = array(
			'continue_on_error' => ! empty( $input['continue_on_error'] ),
			'metadata'          => is_array( $input['metadata'] ?? null ) ? $input['metadata'] : array(),
		);
		if ( is_string( $input['run_id'] ?? null ) && '' !== $input['run_id'] ) {
			$options['run_id'] = $input['run_id'];
		}

		$result = ( new WP_Agent_Workflow_Runner( $recorder ) )->run(
			$spec,
			is_array( $input['inputs'] ?? null ) ? $input['inputs'] : array(),
			$options
		);

		return array(
			'success'     => $result->is_succeeded(),
			'job_id'      => $recorder->get_job_id(),
			'run_id'      => $result->get_run_id(),
			'workflow_id' => $result->get_workflow_id(),
			'status'      => $result->get_status(),
			'steps'       => $result->get_steps(),
			'output'      => $result->get_output(),
			'error'       => $result->get_error(),
		);
	}

	/**
	 * Validate Data Machine's bridge subset without replacing the Agents API validator/runner.
	 *
	 * @param array $spec Workflow spec array.
	 * @return true|WP_Error
	 */
	public function validateBridgeableSpec( array $spec ) {
		$triggers = $spec['triggers'] ?? array();
		if ( ! empty( $triggers ) ) {
			foreach ( $triggers as $index => $trigger ) {
				$type = is_array( $trigger ) ? (string) ( $trigger['type'] ?? '' ) : '';
				if ( 'on_demand' !== $type ) {
					return new WP_Error(
						'agents_api_workflow_trigger_unsupported',
						sprintf( 'Data Machine can execute Agents API workflows on demand only; trigger at index %d uses `%s`.', $index, '' !== $type ? $type : 'unknown' ),
						array(
							'trigger_index' => $index,
							'trigger_type'  => $type,
						)
					);
				}
			}
		}

		foreach ( (array) ( $spec['steps'] ?? array() ) as $index => $step ) {
			$type = is_array( $step ) ? (string) ( $step['type'] ?? '' ) : '';
			if ( ! in_array( $type, self::SUPPORTED_STEP_TYPES, true ) ) {
				return new WP_Error(
					'agents_api_workflow_step_unsupported',
					sprintf( 'Data Machine can record Agents API workflow steps of type ability or agent only; step at index %d uses `%s`.', $index, '' !== $type ? $type : 'unknown' ),
					array(
						'step_index'           => $index,
						'step_type'            => $type,
						'supported_step_types' => self::SUPPORTED_STEP_TYPES,
					)
				);
			}
		}

		return true;
	}

	private function error_response( string $code, string $message, $data = null ): array {
		$response = array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);

		if ( null !== $data ) {
			$response['error']['data'] = $data;
		}

		return $response;
	}
}
