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

use DataMachine\Abilities\ExecutionScope;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Core\Steps\WorkflowSpecValidator;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class ExecuteWorkflowAbility {

	use JobHelpers;

	public function __construct( bool $register = true ) {
		$this->initDatabases();

		if ( $register ) {
			$this->registerAbility();
		}
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
										'description' => __( 'Array of step objects with step_type, handler_slugs, handler_configs, and flow_step_settings', 'data-machine' ),
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
							'operation_key' => array(
								'type'        => 'string',
								'maxLength'   => 191,
								'description' => __( 'Optional caller-stable key for idempotent workflow submission.', 'data-machine' ),
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
							'replayed'       => array( 'type' => 'boolean' ),
							'retryable'      => array( 'type' => 'boolean' ),
							'enqueue_state'  => array( 'type' => 'string' ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute an ephemeral workflow.
	 *
	 * @param array $input Input parameters with workflow, optional timestamp, initial_data, dry_run.
	 * @return array Result with job_id and execution info.
	 */
	public function execute( array $input ): array {
		return $this->executeWithAuthority( $input, false );
	}

	/**
	 * Execute trusted internal system work without accepting public authority flags.
	 *
	 * @param array $input Workflow input.
	 * @return array
	 */
	public function executeInternal( array $input ): array {
		return $this->executeWithAuthority( $input, true );
	}

	/**
	 * Execute an ephemeral workflow under an explicit trusted authority boundary.
	 *
	 * @param array $input          Workflow input.
	 * @param bool  $system_context Trusted internal system execution.
	 * @return array
	 */
	private function executeWithAuthority( array $input, bool $system_context ): array {
		$workflow       = $input['workflow'] ?? null;
		$timestamp      = $input['timestamp'] ?? null;
		$initial_data   = is_array( $input['initial_data'] ?? null ) ? $input['initial_data'] : array();
		$operation_key  = is_string( $input['operation_key'] ?? null ) ? trim( $input['operation_key'] ) : '';

		// Validate workflow structure
		$validation = WorkflowSpecValidator::validate( $workflow );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
			);
		}

		// Validate the complete execution plan before claiming an operation key.
		$configs = WorkflowConfigFactory::buildEphemeralConfigs( $workflow );
		try {
			$first_step_id = ExecutionPlan::from_flow_config( $configs['flow_config'] )->first_step_id();
		} catch ( \InvalidArgumentException $e ) {
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

		$ownership = $this->resolveOwnership( $initial_data, $system_context );
		if ( isset( $ownership['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $ownership['error'],
			);
		}

		// Create job record for direct execution. Honor parent_job_id
		// from initial_data so callers (e.g. TaskScheduler scheduling
		// fan-out children, scheduleBatch passing through caller
		// linkage) can stamp the indexed parent_job_id column — the
		// path Jobs::get_children walks for status / undo.
		$requested_job_source = $initial_data['job_source'] ?? null;
		$requested_job_label  = $initial_data['job_label'] ?? null;
		$job_source           = is_string( $requested_job_source ) && '' !== $requested_job_source
			? $requested_job_source
			: 'chat';
		$job_label            = is_string( $requested_job_label ) && '' !== $requested_job_label
			? $requested_job_label
			: 'Chat Workflow';

		$create_args = array(
			'pipeline_id'      => 'direct',
			'flow_id'          => 'direct',
			'source'           => $job_source,
			'label'            => $job_label,
			'user_id'          => $ownership['user_id'],
			'operation_state'  => 'preparing',
			'operation_step_id' => $first_step_id,
		);
		if ( $ownership['agent_id'] > 0 ) {
			$create_args['agent_id'] = $ownership['agent_id'];
		}

		$initial_parent_job_id = (int) ( $initial_data['parent_job_id'] ?? 0 );
		if ( $initial_parent_job_id > 0 ) {
			$create_args['parent_job_id'] = $initial_parent_job_id;
		}

		// Build engine data with caller data underneath engine-owned configs.
		$engine_data                    = $initial_data;
		$engine_data['flow_config']     = $configs['flow_config'];
		$engine_data['pipeline_config'] = $configs['pipeline_config'];
		$engine_data['user_id']         = $ownership['user_id'];
		$engine_data['calling_user_id'] = $ownership['calling_user_id'];

		// Mirror RunFlowAbility's engine_data['job'] shape so downstream
		// step types can restore authoritative job and agent identity from
		// the engine snapshot during worker execution and deferred resume.
		$caller_snapshot         = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
		$job_snapshot            = $caller_snapshot;
		$job_snapshot['user_id'] = $ownership['user_id'];
		if ( $ownership['agent_id'] > 0 ) {
			$job_snapshot['agent_id']   = $ownership['agent_id'];
			$job_snapshot['agent_slug'] = $ownership['agent_slug'];
		} else {
			unset( $job_snapshot['agent_id'], $job_snapshot['agent_slug'] );
		}
		$engine_data['job'] = $job_snapshot;

		// Set dry_run_mode flag for preview execution
		if ( ! empty( $input['dry_run'] ) ) {
			$engine_data['dry_run_mode'] = true;
		}

		$request_fingerprint = $this->requestFingerprint( $engine_data, $timestamp );
		$create_args['request_fingerprint'] = $request_fingerprint;
		$engine_data['direct_request']       = array(
			'operation_key'  => $operation_key,
			'fingerprint'    => $request_fingerprint,
			'execution_type' => $timestamp && is_numeric( $timestamp ) && (int) $timestamp > time() ? 'delayed' : 'immediate',
			'timestamp'      => is_numeric( $timestamp ) ? (int) $timestamp : null,
			'dry_run'        => ! empty( $input['dry_run'] ),
		);
		if ( '' !== $operation_key ) {
			$create_args['idempotency_key'] = 'direct:' . hash( 'sha256', $ownership['user_id'] . ':' . $ownership['agent_id'] . ':' . $operation_key );
		}

		$create_args['engine_data'] = $engine_data;
		$creation                   = '' !== $operation_key
			? $this->db_jobs->create_or_get_job( $create_args )
			: $this->db_jobs->create_job( $create_args );
		$job_id                     = is_array( $creation ) ? (int) ( $creation['job_id'] ?? 0 ) : (int) $creation;

		if ( $job_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create job record',
			);
		}

		if ( is_array( $creation ) && ! empty( $creation['already_exists'] ) ) {
			$existing_fingerprint = (string) ( $creation['job']['request_fingerprint'] ?? '' );
			if ( '' === $existing_fingerprint || ! hash_equals( $existing_fingerprint, $request_fingerprint ) ) {
				return array(
					'success' => false,
					'error'   => 'operation_key has already been used with different workflow input.',
				);
			}
		}

		$job = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to load job record after creation',
			);
		}

		$existing_engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$request_meta         = is_array( $existing_engine_data['direct_request'] ?? null ) ? $existing_engine_data['direct_request'] : array();
		if ( JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
			return $this->executionResponse( $job_id, $workflow, $request_meta, true );
		}

		$existing_engine_data['job']           = is_array( $existing_engine_data['job'] ?? null ) ? $existing_engine_data['job'] : array();
		$existing_engine_data['job']['job_id'] = $job_id;
		if ( ! $this->db_jobs->store_engine_data( $job_id, $existing_engine_data ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to persist job execution data; replay may retry the operation.',
			);
		}

		$enqueue = ( new DirectJobEnqueuer( $this->db_jobs ) )->enqueue(
			$job_id,
			$first_step_id,
			is_numeric( $timestamp ) ? (int) $timestamp : null
		);
		if ( empty( $enqueue['success'] ) ) {
			return array(
				'success'    => false,
				'error'      => (string) ( $enqueue['error'] ?? 'enqueue_failed' ),
				'retryable'  => ! empty( $enqueue['retryable'] ),
				'enqueue_state' => (string) ( $enqueue['state'] ?? 'enqueue_failed' ),
			);
		}

		return $this->executionResponse( $job_id, $workflow, $request_meta, is_array( $creation ) && ! empty( $creation['already_exists'] ) );
	}

	/**
	 * Resolve authoritative ownership for a direct asynchronous job.
	 *
	 * @param array $initial_data   Caller-provided engine data.
	 * @param bool  $system_context Whether an internal scheduler initiated the job.
	 * @return array{user_id:int,calling_user_id:int,agent_id:int,agent_slug:string}|array{error:string}
	 */
	private function resolveOwnership( array $initial_data, bool $system_context ): array {
		$scope           = ExecutionScope::current( 'manage_flows' );
		$principal       = $scope->principal();
		$user_id         = $principal ? max( 0, (int) $principal->acting_user_id ) : max( 0, $scope->acting_user_id() );
		$calling_user_id = $user_id;
		$agent_id        = max( 0, (int) ( $scope->acting_agent_id() ?? 0 ) );
		$agent_slug      = '';

		$caller_snapshot = is_array( $initial_data['job'] ?? null ) ? $initial_data['job'] : array();
		$identity_context = array_filter(
			$agent_id > 0
				? array( 'agent_id' => $agent_id )
				: array(
					'agent_id'   => $caller_snapshot['agent_id'] ?? ( $initial_data['agent_id'] ?? null ),
					'agent_slug' => $caller_snapshot['agent_slug'] ?? ( $initial_data['agent_slug'] ?? null ),
				),
			static fn( $value ) => null !== $value && '' !== $value && 0 !== $value
		);

		if ( ! empty( $identity_context ) ) {
			try {
				$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $identity_context );
			} catch ( \InvalidArgumentException $e ) {
				return array( 'error' => $e->getMessage() );
			}

			if ( $agent_id <= 0 && ! $system_context && ! PermissionHelper::can_access_agent( $identity->agent_id ) ) {
				return array( 'error' => 'You do not have permission to execute work for this agent.' );
			}

			$agent_id   = $identity->agent_id;
			$agent_slug = $identity->agent_slug;
			if ( $system_context && $user_id <= 0 ) {
				$user_id = $identity->owner_id;
			}
		}

		if ( ! $system_context && $user_id <= 0 && $agent_id <= 0 ) {
			return array( 'error' => 'An authenticated acting caller is required for user-scoped workflow execution.' );
		}

		return array(
			'user_id'         => $user_id,
			'calling_user_id' => $calling_user_id,
			'agent_id'        => $agent_id,
			'agent_slug'      => $agent_slug,
		);
	}

	/**
	 * Hash the effective request input stored on an idempotent job.
	 *
	 * @param array $engine_data Effective engine data before request metadata.
	 * @param mixed $timestamp   Requested execution timestamp.
	 * @return string
	 */
	private function requestFingerprint( array $engine_data, $timestamp ): string {
		$payload = $this->canonicalize(
			array(
				'engine_data' => $engine_data,
				'timestamp'   => is_numeric( $timestamp ) ? (int) $timestamp : null,
			)
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Sort object keys recursively while retaining list order.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}

		return $value;
	}

	/**
	 * Recreate the original submission response without scheduling duplicate work.
	 *
	 * @param int   $job_id        Existing job ID.
	 * @param array $workflow      Validated workflow input.
	 * @param array $request_meta  Persisted direct request metadata.
	 * @param bool  $replayed      Whether this is an idempotent replay.
	 * @return array
	 */
	private function executionResponse( int $job_id, array $workflow, array $request_meta, bool $replayed ): array {
		$execution_type = 'delayed' === ( $request_meta['execution_type'] ?? '' ) ? 'delayed' : 'immediate';
		$is_dry_run     = ! empty( $request_meta['dry_run'] );
		$response       = array(
			'success'        => true,
			'execution_mode' => 'direct',
			'execution_type' => $execution_type,
			'job_id'         => $job_id,
			'step_count'     => count( $workflow['steps'] ?? array() ),
			'replayed'       => $replayed,
			'message'        => 'Existing workflow execution returned for operation_key.',
		);

		if ( $is_dry_run ) {
			$response['dry_run'] = true;
		}

		if ( 'delayed' === $execution_type && ! empty( $request_meta['timestamp'] ) ) {
			$response['timestamp']      = (int) $request_meta['timestamp'];
			$response['scheduled_time'] = wp_date( 'c', (int) $request_meta['timestamp'] );
		}

		return $response;
	}
}
