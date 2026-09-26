<?php
/**
 * Delegated operation submission and reconciliation.
 *
 * @package DataMachine\Core\DelegatedOperations
 */

namespace DataMachine\Core\DelegatedOperations;

use DataMachine\Abilities\ExecutionScope;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\EngineData;
use DataMachine\Core\JobRetryPolicy;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\RunResult;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Core\Steps\WorkflowSpecValidator;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

final class DelegatedOperationService {

	private const IDEMPOTENCY_PREFIX   = 'delegated:';
	private const REFERENCE_PREFIX     = 'dop_';
	private const MAX_INPUT_BYTES      = 65536;
	private const MAX_PROJECTION_BYTES = 32768;

	private Jobs $jobs;
	private DelegatedOperationRegistry $registry;
	private static bool $hooks_registered = false;

	public function __construct( ?Jobs $jobs = null, ?DelegatedOperationRegistry $registry = null ) {
		$this->jobs     = $jobs ?? new Jobs();
		$this->registry = $registry ?? new DelegatedOperationRegistry();
		if ( ! self::$hooks_registered ) {
			add_filter( 'datamachine_job_terminal_core_callbacks', array( $this, 'registerTerminalCallback' ), 20, 3 );
			self::$hooks_registered = true;
		}
	}

	/** Submit an owner-registered operation. */
	public function submit( array $request ): array {
		$action_id    = trim( (string) ( $request['action'] ?? '' ) );
		$operation_id = trim( (string) ( $request['operation_id'] ?? '' ) );
		$raw_input    = is_array( $request['input'] ?? null ) ? $request['input'] : array();
		$timestamp    = isset( $request['timestamp'] ) && is_numeric( $request['timestamp'] ) ? max( 0, (int) $request['timestamp'] ) : null;
		if ( '' === $operation_id || strlen( $operation_id ) > 191 ) {
			return $this->failure( 'delegated_operation_id_invalid', __( 'A bounded operation_id is required.', 'data-machine' ) );
		}

		$actor = $this->actor();
		if ( 0 === $actor['user_id'] && 0 === $actor['agent_id'] ) {
			return $this->failure( 'delegated_actor_required', __( 'An authenticated initiating actor is required.', 'data-machine' ) );
		}

		$action = $this->registry->get( $action_id );
		if ( is_wp_error( $action ) ) {
			return $this->fromError( $action );
		}

		$idempotency_key = $this->idempotencyKey( $action_id, $operation_id );
		$existing_job    = $this->jobs->get_job_by_idempotency_key( $idempotency_key );
		$operation_ref   = '';
		if ( is_array( $existing_job ) ) {
			$existing_envelope  = is_array( $existing_job['operation_envelope'] ?? null ) ? $existing_job['operation_envelope'] : array();
			$existing_operation = is_array( $existing_envelope['delegated_operation'] ?? null ) ? $existing_envelope['delegated_operation'] : array();
			$stored_ref         = (string) ( $existing_operation['operation_ref'] ?? '' );
			if ( preg_match( '/^dop_[a-f0-9]{64}$/', $stored_ref ) ) {
				$operation_ref = $stored_ref;
			}
		}
		if ( '' === $operation_ref ) {
			try {
				$operation_ref = $this->reference( $action_id, $operation_id );
			} catch ( \Throwable ) {
				return $this->failure( 'delegated_receipt_secret_invalid', __( 'The delegated operation receipt secret is unavailable.', 'data-machine' ), true );
			}
		}
		$context    = $this->context( 'submit', $action_id, $operation_id, $operation_ref, $actor );
		$normalized = $this->invoke(
			$action['normalize_input'],
			array( $raw_input, $context ),
			'delegated_input_invalid',
			array(
				'callback' => 'normalize_input',
				'action'   => $action_id,
				'phase'    => 'submit',
			)
		);
		if ( is_wp_error( $normalized ) ) {
			return $this->fromError( $normalized );
		}
		if ( ! is_array( $normalized ) || ! $this->isBoundedJson( $normalized, self::MAX_INPUT_BYTES ) ) {
			return $this->failure( 'delegated_input_invalid', __( 'The action owner returned invalid normalized input.', 'data-machine' ) );
		}
		$context['input'] = $normalized;

		$authorized = $this->authorize( $action, $context );
		if ( is_wp_error( $authorized ) ) {
			return $this->fromError( $authorized );
		}

		$prepared = $this->invoke(
			$action['prepare'],
			array( $normalized, $context ),
			'delegated_action_prepare_failed',
			array(
				'callback' => 'prepare',
				'action'   => $action_id,
				'phase'    => 'submit',
			)
		);
		if ( is_wp_error( $prepared ) ) {
			return $this->fromError( $prepared );
		}
		$execution = $this->prepareExecution( $prepared );
		if ( is_wp_error( $execution ) ) {
			return $this->fromError( $execution );
		}

		$engine_data                    = is_array( $execution['initial_data'] ?? null ) ? $execution['initial_data'] : array();
		$engine_data['flow_config']     = $execution['configs']['flow_config'];
		$engine_data['pipeline_config'] = $execution['configs']['pipeline_config'];
		$engine_data['user_id']         = $execution['user_id'];
		$engine_data['calling_user_id'] = $actor['user_id'];
		$engine_data['job']             = is_array( $engine_data['job'] ?? null ) ? $engine_data['job'] : array();
		$engine_data['job']['user_id']  = $execution['user_id'];
		if ( $execution['agent_id'] > 0 ) {
			$engine_data['job']['agent_id']   = $execution['agent_id'];
			$engine_data['job']['agent_slug'] = $execution['agent_slug'];
		}
		$engine_data['delegated_operation'] = array(
			'action'          => $action_id,
			'version'         => $action['version'],
			'operation_id'    => $operation_id,
			'operation_ref'   => $operation_ref,
			'input'           => $normalized,
			'initiator'       => $actor,
			'execution_owner' => array(
				'user_id'    => $execution['user_id'],
				'agent_id'   => $execution['agent_id'],
				'agent_slug' => $execution['agent_slug'],
			),
			'timestamp'       => $timestamp,
		);
		$operation_envelope                 = array( 'delegated_operation' => $engine_data['delegated_operation'] );

		$fingerprint_payload = array(
			'action'          => $action_id,
			'version'         => $action['version'],
			'input'           => $normalized,
			'execution_owner' => $engine_data['delegated_operation']['execution_owner'],
			'workflow'        => $execution['workflow'],
			'initial_data'    => $execution['initial_data'],
			'timestamp'       => $timestamp,
		);
		$fingerprint         = hash( 'sha256', (string) wp_json_encode( $this->canonicalize( $fingerprint_payload ) ) );
		$create_args         = array(
			'pipeline_id'         => 'direct',
			'flow_id'             => 'direct',
			'source'              => 'delegated',
			'label'               => isset( $prepared['label'] ) ? (string) $prepared['label'] : $action_id,
			'user_id'             => $execution['user_id'],
			'idempotency_key'     => $idempotency_key,
			'operation_ref_hash'  => $this->receiptHash( $operation_ref ),
			'operation_envelope'  => $operation_envelope,
			'request_fingerprint' => $fingerprint,
			'operation_state'     => 'preparing',
			'operation_step_id'   => $execution['first_step_id'],
			'engine_data'         => $engine_data,
		);
		if ( $execution['agent_id'] > 0 ) {
			$create_args['agent_id'] = $execution['agent_id'];
		}

		$creation = $this->jobs->create_or_get_job( $create_args );
		if ( ! is_array( $creation ) || empty( $creation['job_id'] ) ) {
			return $this->failure( 'delegated_operation_create_failed', __( 'The delegated operation could not be created.', 'data-machine' ), true );
		}
		if ( ! empty( $creation['already_exists'] ) ) {
			$existing_fingerprint = (string) ( $creation['job']['request_fingerprint'] ?? '' );
			if ( '' === $existing_fingerprint || ! hash_equals( $existing_fingerprint, $fingerprint ) ) {
				return $this->failure( 'delegated_operation_conflict', __( 'The operation identity is already frozen with different input or policy.', 'data-machine' ) );
			}
		}

		$job_id = (int) $creation['job_id'];
		$job    = $this->jobs->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return $this->failure( 'delegated_operation_load_failed', __( 'The delegated operation could not be loaded.', 'data-machine' ), true );
		}
		$job_status    = (string) ( $job['status'] ?? '' );
		$stored_job_id = (int) ( $job['engine_data']['job']['job_id'] ?? 0 );
		if ( ! JobStatus::isStatusFinal( $job_status ) && 0 === $stored_job_id && ! $this->persistJobReference( $job_id ) ) {
			return $this->failure( 'delegated_operation_persist_failed', __( 'The delegated operation could not be persisted.', 'data-machine' ), true );
		}
		$job = $this->jobs->get_job( $job_id );
		if ( ! empty( $creation['already_exists'] ) ) {
			$stored_envelope  = is_array( $job['operation_envelope'] ?? null ) ? $job['operation_envelope'] : array();
			$stored_operation = is_array( $stored_envelope['delegated_operation'] ?? null ) ? $stored_envelope['delegated_operation'] : array();
			$stored_ref       = (string) ( $stored_operation['operation_ref'] ?? '' );
			if ( preg_match( '/^dop_[a-f0-9]{64}$/', $stored_ref ) ) {
				$context['operation_ref'] = $stored_ref;
			}
		}
		if ( ! JobStatus::isStatusFinal( $job_status ) && ! in_array( $job_status, array( JobStatus::PROCESSING, JobStatus::WAITING ), true ) && ! $this->executionAlreadyStarted( $job ) ) {
			if ( ! is_array( $job ) ) {
				return $this->failure( 'delegated_operation_persist_failed', __( 'The delegated operation could not be persisted.', 'data-machine' ), true );
			}
			$enqueue = ( new DirectJobEnqueuer( $this->jobs ) )->enqueue( $job_id, $execution['first_step_id'], $timestamp );
			if ( empty( $enqueue['success'] ) ) {
				return $this->failure( (string) ( $enqueue['error'] ?? 'delegated_enqueue_failed' ), __( 'The delegated operation is awaiting enqueue recovery.', 'data-machine' ), ! empty( $enqueue['retryable'] ) );
			}
			$job = $this->jobs->get_job( $job_id );
		}

		return $this->response( $job, $action, $context, ! empty( $creation['already_exists'] ) );
	}

	/** Reconcile one operation through its owner-controlled projection. */
	public function reconcile( array $request ): array {
		$resolved = $this->resolve( $request, 'reconcile' );
		return is_array( $resolved ) && isset( $resolved['error_result'] ) ? $resolved['error_result'] : $this->response( $resolved['job'], $resolved['action'], $resolved['context'], true );
	}

	/** Retry a failed operation without creating another job. */
	public function retry( array $request ): array {
		$resolved = $this->resolve( $request, 'retry' );
		if ( isset( $resolved['error_result'] ) ) {
			return $resolved['error_result'];
		}
		$job    = $resolved['job'];
		$job_id = (int) $job['job_id'];
		if ( ! JobStatus::isStatusFailure( (string) ( $job['status'] ?? '' ) ) ) {
			return $this->failure( 'delegated_operation_not_retryable', __( 'Only failed delegated operations may be retried.', 'data-machine' ) );
		}
		$engine  = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$step_id = JobRetryPolicy::resolveDirectResumeStepId( $engine );
		if ( ! is_callable( $resolved['action']['retry'] ?? null ) ) {
			return $this->failure( 'delegated_operation_retry_unsupported', __( 'The action owner has not registered safe retry reconciliation.', 'data-machine' ) );
		}
		$run_result = $this->terminalRunResult( $job );
		if ( is_wp_error( $run_result ) || ! str_starts_with( (string) $run_result['status'], JobStatus::FAILED ) ) {
			return $this->failure( 'delegated_run_result_invalid', __( 'The failed operation has no valid canonical run result.', 'data-machine' ) );
		}
		$retry_safe = $this->invoke(
			$resolved['action']['retry'],
			array( $run_result, $resolved['context'] ),
			'delegated_operation_retry_unsafe',
			array(
				'callback' => 'retry',
				'action'   => $resolved['context']['action'],
				'phase'    => 'retry',
			)
		);
		if ( true !== $retry_safe ) {
			return is_wp_error( $retry_safe )
				? $this->fromError( $retry_safe )
				: $this->failure( 'delegated_operation_retry_unsafe', __( 'The action owner could not prove this operation safe to retry.', 'data-machine' ) );
		}
		if ( '' === $step_id || ! $this->jobs->reopen_failed_job( $job_id ) ) {
			return $this->failure( 'delegated_operation_retry_failed', __( 'The delegated operation could not be reopened safely.', 'data-machine' ) );
		}
		$enqueue = ( new DirectJobEnqueuer( $this->jobs ) )->enqueue( $job_id, $step_id );
		if ( empty( $enqueue['success'] ) ) {
			$this->jobs->complete_job( $job_id, 'failed - delegated_retry_enqueue_failed' );
			return $this->failure( 'delegated_operation_retry_failed', __( 'The delegated operation retry could not be enqueued.', 'data-machine' ), ! empty( $enqueue['retryable'] ) );
		}
		RunMetrics::increment( $job_id, 'retried' );
		return $this->response( $this->jobs->get_job( $job_id ), $resolved['action'], $resolved['context'], true );
	}

	/** Cancel work that has not started, atomically fencing its generation. */
	public function cancel( array $request ): array {
		$resolved = $this->resolve( $request, 'cancel' );
		if ( isset( $resolved['error_result'] ) ) {
			return $resolved['error_result'];
		}
		$job        = $resolved['job'];
		$job_id     = (int) $job['job_id'];
		$step_id    = (string) ( $job['operation_step_id'] ?? '' );
		$generation = (int) ( $job['operation_generation'] ?? 0 );
		$token      = (string) ( $job['operation_claim_token'] ?? '' );
		$transition = $this->jobs->cancel_pending_direct_operation( $job_id );
		if ( empty( $transition['success'] ) ) {
			return $this->failure( 'delegated_operation_not_cancellable', __( 'Only submitted or delayed operations can be cancelled safely.', 'data-machine' ) );
		}
		if ( function_exists( 'as_unschedule_action' ) && '' !== $step_id && 0 < $generation && '' !== $token ) {
			as_unschedule_action(
				DirectJobEnqueuer::HOOK,
				array(
					'job_id'                => $job_id,
					'flow_step_id'          => $step_id,
					'operation_generation'  => $generation,
					'operation_claim_token' => $token,
				),
				DirectJobEnqueuer::GROUP
			);
		}
		return $this->response( $this->jobs->get_job( $job_id ), $resolved['action'], $resolved['context'], true );
	}

	/** Resolve, attest, and owner-authorize an existing operation. */
	private function resolve( array $request, string $phase ): array {
		$action_id     = trim( (string) ( $request['action'] ?? '' ) );
		$operation_ref = trim( (string) ( $request['operation_ref'] ?? '' ) );
		$action        = $this->registry->get( $action_id );
		if ( is_wp_error( $action ) ) {
			return array( 'error_result' => $this->fromError( $action ) );
		}
		if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_ref_invalid', __( 'The operation reference is invalid.', 'data-machine' ) ) );
		}
		$job = $this->jobs->get_job_by_operation_ref_hash( $this->receiptHash( $operation_ref ) );
		if ( ! is_array( $job ) ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_not_found', __( 'The delegated operation was not found.', 'data-machine' ) ) );
		}
		$envelope = is_array( $job['operation_envelope'] ?? null ) ? $job['operation_envelope'] : array();
		$stored   = is_array( $envelope['delegated_operation'] ?? null ) ? $envelope['delegated_operation'] : array();
		if ( (string) ( $stored['action'] ?? '' ) !== $action_id || (string) ( $stored['operation_ref'] ?? '' ) !== $operation_ref ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_not_found', __( 'The delegated operation was not found.', 'data-machine' ) ) );
		}
		$action = $this->registry->get( $action_id, (string) ( $stored['version'] ?? '' ) );
		if ( is_wp_error( $action ) ) {
			return array( 'error_result' => $this->fromError( $action ) );
		}

		$actor            = $this->actor();
		$context          = $this->context( $phase, $action_id, (string) ( $stored['operation_id'] ?? '' ), $operation_ref, $actor );
		$context['input'] = is_array( $stored['input'] ?? null ) ? $stored['input'] : array();
		$authorized       = $this->authorize( $action, $context );
		if ( is_wp_error( $authorized ) ) {
			return array( 'error_result' => $this->fromError( $authorized ) );
		}

		return compact( 'action', 'job', 'context' );
	}

	/** Validate the private execution descriptor returned by an action owner. */
	private function prepareExecution( $prepared ) {
		if ( ! is_array( $prepared ) || ! is_array( $prepared['workflow'] ?? null ) ) {
			return new \WP_Error( 'delegated_action_prepare_failed', __( 'The action owner returned no workflow.', 'data-machine' ) );
		}
		$validation = WorkflowSpecValidator::validate( $prepared['workflow'] );
		if ( empty( $validation['valid'] ) ) {
			do_action( 'datamachine_log', 'error', 'Delegated operation workflow validation failed', array( 'error' => (string) ( $validation['error'] ?? '' ) ) );
			return new \WP_Error( 'delegated_action_prepare_failed', __( 'The owner workflow is invalid.', 'data-machine' ) );
		}
		$configs = WorkflowConfigFactory::buildEphemeralConfigs( $prepared['workflow'] );
		try {
			$first_step_id = ExecutionPlan::from_flow_config( $configs['flow_config'] )->first_step_id();
		} catch ( \InvalidArgumentException ) {
			do_action( 'datamachine_log', 'error', 'Delegated operation workflow validation failed', array( 'error_code' => 'delegated_action_prepare_failed' ) );
			return new \WP_Error( 'delegated_action_prepare_failed', __( 'The owner workflow is invalid.', 'data-machine' ) );
		}
		if ( ! $first_step_id ) {
			return new \WP_Error( 'delegated_action_prepare_failed', __( 'The owner workflow has no executable step.', 'data-machine' ) );
		}

		$user_id    = max( 0, (int) ( $prepared['owner_user_id'] ?? 0 ) );
		$agent_id   = max( 0, (int) ( $prepared['agent_id'] ?? 0 ) );
		$agent_slug = trim( (string) ( $prepared['agent_slug'] ?? '' ) );
		if ( $agent_id > 0 || '' !== $agent_slug ) {
			try {
				$resolver = new AgentIdentityResolver();
				$identity = $resolver->resolve_agent_identity(
					array(
						'agent_id'   => $agent_id,
						'agent_slug' => $agent_slug,
					)
				);
				if ( $agent_id > 0 && '' !== $agent_slug ) {
					$by_id   = $resolver->resolve_agent_identity( $agent_id );
					$by_slug = $resolver->resolve_agent_identity( $agent_slug );
					if ( $by_id->agent_id !== $by_slug->agent_id ) {
						throw new \InvalidArgumentException( 'Agent identifiers disagree.' );
					}
				}
			} catch ( \InvalidArgumentException $exception ) {
				unset( $exception );
				do_action( 'datamachine_log', 'error', 'Delegated operation owner resolution failed', array( 'error_code' => 'delegated_execution_owner_invalid' ) );
				return new \WP_Error( 'delegated_execution_owner_invalid', __( 'The registered execution owner is invalid.', 'data-machine' ) );
			}
			if ( $user_id > 0 && $user_id !== $identity->owner_id ) {
				return new \WP_Error( 'delegated_execution_owner_invalid', __( 'The registered execution owner is inconsistent.', 'data-machine' ) );
			}
			$agent_id   = $identity->agent_id;
			$agent_slug = $identity->agent_slug;
			$user_id    = $identity->owner_id;
		}
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new \WP_Error( 'delegated_execution_owner_invalid', __( 'The action owner must resolve a stable execution owner.', 'data-machine' ) );
		}

		return array(
			'workflow'      => $prepared['workflow'],
			'initial_data'  => is_array( $prepared['initial_data'] ?? null ) ? $prepared['initial_data'] : array(),
			'configs'       => $configs,
			'first_step_id' => (string) $first_step_id,
			'user_id'       => $user_id,
			'agent_id'      => $agent_id,
			'agent_slug'    => $agent_slug,
		);
	}

	/** Build the bounded public response from canonical run truth. */
	private function response( ?array $job, array $action, array $context, bool $replayed ): array {
		if ( ! is_array( $job ) ) {
			return $this->failure( 'delegated_operation_load_failed', __( 'The delegated operation could not be loaded.', 'data-machine' ), true );
		}
		$status = $this->pendingStatus( $job );
		if ( JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
			$run_result = $this->terminalRunResult( $job );
			if ( is_wp_error( $run_result ) ) {
				return $this->failure( 'delegated_run_result_invalid', __( 'The operation has no valid canonical run result.', 'data-machine' ) );
			}
		} else {
			$run_result = RunResult::active( $status );
		}
		$projection = $this->invoke(
			$action['project'],
			array( $run_result, $context ),
			'delegated_projection_failed',
			array(
				'callback' => 'project',
				'action'   => $context['action'],
				'phase'    => $context['phase'],
			)
		);
		if ( is_wp_error( $projection ) || ! is_array( $projection ) || array_is_list( $projection ) || ! $this->isBoundedJson( $projection, self::MAX_PROJECTION_BYTES ) ) {
			$projection = array();
		}
		$status = $this->publicStatus( $job, $run_result, $projection );
		$result = array(
			'success'       => true,
			'operation_ref' => (string) $context['operation_ref'],
			'status'        => $status,
			'replayed'      => $replayed,
		);
		if ( array() !== $projection ) {
			$result['projection'] = $projection;
		}
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		if ( 'retrying' === $status ) {
			$retry_data      = is_array( $engine['retry'] ?? null ) ? $engine['retry'] : array();
			$defer_data      = is_array( $engine['ai_concurrency_throttle'] ?? null ) ? $engine['ai_concurrency_throttle'] : array();
			$result['retry'] = array_filter(
				array(
					'type'          => ! empty( $retry_data['next_retry_at'] ) ? 'retry' : 'ai_concurrency',
					'attempt'       => max( 0, (int) ( $retry_data['attempts'] ?? ( $defer_data['attempts'] ?? 0 ) ) ),
					'max_attempts'  => isset( $retry_data['max_attempts'] ) ? max( 0, (int) $retry_data['max_attempts'] ) : null,
					'next_retry_at' => isset( $retry_data['next_retry_at'] ) ? (string) $retry_data['next_retry_at'] : ( isset( $defer_data['next_retry_at'] ) ? (string) $defer_data['next_retry_at'] : null ),
				),
				static fn( $value ) => null !== $value
			);
		}
		return $result;
	}

	private function publicStatus( array $job, array $run_result, array $projection ): string {
		$status = (string) ( $job['status'] ?? '' );
		$base   = JobStatus::fromString( $status );
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		if ( JobStatus::PENDING === $status && ( ! empty( $engine['retry']['next_retry_at'] ) || 'deferred' === ( $engine['ai_concurrency_throttle']['state'] ?? '' ) ) ) {
			return 'retrying';
		}
		if ( ! $base->isFinal() ) {
			return in_array( $status, array( JobStatus::PROCESSING, JobStatus::WAITING ), true ) || ! empty( $job['operation_effects_begun_at'] ) ? 'executing' : 'submitted';
		}
		if ( $base->isFailure() ) {
			return 'failed';
		}
		if ( str_starts_with( $status, JobStatus::CANCELLED ) ) {
			return 'cancelled';
		}
		$canonical_status = strtolower( (string) ( $run_result['status'] ?? '' ) );
		$canonical_no_op  = in_array( $canonical_status, array( 'agent_skipped', 'skipped', JobStatus::COMPLETED_NO_ITEMS, 'no_items', 'no-op' ), true );
		$projected_zero   = isset( $projection['effect_count'] ) && is_int( $projection['effect_count'] ) && 0 === $projection['effect_count'];
		$canonical_count  = $run_result['outputs']['effect_count'] ?? null;
		$canonical_zero   = is_int( $canonical_count ) && 0 === $canonical_count;
		if ( $base->isAgentSkipped() || $base->isCompletedNoItems() || $canonical_no_op || $canonical_zero || $projected_zero ) {
			return 'no-op';
		}
		return 'executed';
	}

	private function authorize( array $action, array $context ) {
		$result = $this->invoke(
			$action['authorize'],
			array( $context ),
			'delegated_action_forbidden',
			array(
				'callback' => 'authorize',
				'action'   => $context['action'],
				'phase'    => $context['phase'],
			)
		);
		return true === $result ? true : ( is_wp_error( $result ) ? $result : new \WP_Error( 'delegated_action_forbidden', __( 'The action owner denied this operation.', 'data-machine' ) ) );
	}

	private function actor(): array {
		$scope     = ExecutionScope::current( 'manage_flows' );
		$principal = $scope->principal();
		return array(
			'user_id'  => max( 0, $principal ? (int) $principal->acting_user_id : $scope->acting_user_id() ),
			'agent_id' => max( 0, (int) ( $scope->acting_agent_id() ?? 0 ) ),
			'token_id' => max( 0, (int) ( $scope->acting_token_id() ?? 0 ) ),
		);
	}

	private function context( string $phase, string $action, string $operation_id, string $operation_ref, array $actor ): array {
		return array(
			'phase'         => $phase,
			'action'        => $action,
			'operation_id'  => $operation_id,
			'operation_ref' => $operation_ref,
			'actor'         => $actor,
		);
	}

	private function reference( string $action, string $operation_id ): string {
		return self::REFERENCE_PREFIX . $this->keyedHash( 'receipt', $action . "\0" . $operation_id );
	}

	private function idempotencyKey( string $action, string $operation_id ): string {
		return self::IDEMPOTENCY_PREFIX . hash( 'sha256', "delegated-idempotency\0" . $action . "\0" . $operation_id );
	}

	private function receiptHash( string $operation_ref ): string {
		return hash( 'sha256', $operation_ref );
	}

	private function keyedHash( string $purpose, string $payload ): string {
		return hash_hmac( 'sha256', 'delegated-' . $purpose . "\0" . $payload, $this->receiptSecret() );
	}

	/** Resolve the durable site secret used only for opaque operation receipts. */
	private function receiptSecret(): string {
		global $wpdb;
		$option = 'datamachine_delegated_operation_secret';
		$secret = get_option( $option );
		if ( is_string( $secret ) && preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			return $secret;
		}
		$generated = bin2hex( random_bytes( 32 ) );
		// phpcs:disable WordPress.DB.PreparedSQL -- INSERT IGNORE gives first initialization one durable winner.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $option, $generated, 'off' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$secret = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		// phpcs:enable WordPress.DB.PreparedSQL
		wp_cache_delete( $option, 'options' );
		if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			throw new \RuntimeException( 'Delegated operation receipt secret is unavailable.' );
		}
		return $secret;
	}

	private function invoke( callable $callback, array $arguments, string $error_code, array $log_context = array() ) {
		try {
			return $callback( ...$arguments );
		} catch ( \Throwable ) {
			do_action(
				'datamachine_log',
				'error',
				'Delegated operation owner callback failed',
				array_merge( $log_context, array( 'error_code' => $error_code ) )
			);
			return new \WP_Error( $error_code, __( 'The delegated action owner could not complete the request.', 'data-machine' ) );
		}
	}

	/** Persist the generated job reference without replacing concurrent engine state. */
	private function persistJobReference( int $job_id ): bool {
		$result = EngineData::mutate(
			$job_id,
			static function ( array $engine ) use ( $job_id ): array {
				$engine['job']           = is_array( $engine['job'] ?? null ) ? $engine['job'] : array();
				$engine['job']['job_id'] = $job_id;
				return $engine;
			},
			'delegated_operation_job_reference'
		);
		return ! empty( $result['success'] );
	}

	/** Whether a pending row belongs to work that already passed initial admission. */
	private function executionAlreadyStarted( array $job ): bool {
		if ( ! empty( $job['operation_effects_begun_at'] ) ) {
			return true;
		}
		$generation = max( 0, (int) ( $job['operation_generation'] ?? 0 ) );
		$token      = (string) ( $job['operation_claim_token'] ?? '' );
		return 'none' !== ( new DirectJobEnqueuer( $this->jobs ) )->liveGenerationExecution( (int) $job['job_id'], $generation, $token );
	}

	private function pendingStatus( array $job ): string {
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		if ( JobStatus::PENDING === (string) ( $job['status'] ?? '' ) && ( ! empty( $engine['retry']['next_retry_at'] ) || 'deferred' === ( $engine['ai_concurrency_throttle']['state'] ?? '' ) ) ) {
			return 'retrying';
		}
		return in_array( (string) ( $job['status'] ?? '' ), array( JobStatus::PROCESSING, JobStatus::WAITING ), true ) || ! empty( $job['operation_effects_begun_at'] ) ? 'executing' : 'submitted';
	}

	/** Resolve a validated canonical terminal result from the bounded envelope. */
	private function terminalRunResult( array $job ) {
		$envelope   = is_array( $job['operation_envelope'] ?? null ) ? $job['operation_envelope'] : array();
		$run_result = $envelope['run_result'] ?? null;
		return RunResult::validate( $run_result ) ? $run_result : new \WP_Error( 'delegated_run_result_invalid' );
	}

	/** Register durable terminal capture in the replayable core accounting stage. */
	public function registerTerminalCallback( array $callbacks, int $job_id, string $status ): array {
		unset( $job_id, $status );
		$callbacks['delegated_operation_envelope'] = array( $this, 'captureTerminalEnvelope' );
		return $callbacks;
	}

	/** Capture canonical terminal truth before short-window engine-data shedding. */
	public function captureTerminalEnvelope( int $job_id, string $status ): bool {
		unset( $status );
		$job = $this->jobs->get_job( $job_id );
		if ( ! is_array( $job ) || 'delegated' !== (string) ( $job['source'] ?? '' ) ) {
			return true;
		}
		$envelope = is_array( $job['operation_envelope'] ?? null ) ? $job['operation_envelope'] : array();
		$summary  = RunMetrics::fromJob( $job );
		$result   = RunResult::fromJobSummary( $job, $summary );
		if ( ! RunResult::validate( $result ) ) {
			return false;
		}
		$envelope['run_result'] = $result;
		return $this->jobs->store_operation_envelope( $job_id, $envelope );
	}

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

	private function isBoundedJson( array $value, int $max_bytes ): bool {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) && strlen( $encoded ) <= $max_bytes;
	}

	private function fromError( \WP_Error $error ): array {
		$data = $error->get_error_data();
		return $this->failure( (string) $error->get_error_code(), $error->get_error_message(), is_array( $data ) && ! empty( $data['retryable'] ) );
	}

	private function failure( string $code, string $message, bool $retryable = false ): array {
		$result = array(
			'success'    => false,
			'error_code' => sanitize_key( $code ),
			'error'      => $message,
		);
		if ( $retryable ) {
			$result['retryable'] = true;
		}
		return $result;
	}
}
