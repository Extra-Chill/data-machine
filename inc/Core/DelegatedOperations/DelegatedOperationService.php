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
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Core\Steps\WorkflowSpecValidator;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

final class DelegatedOperationService {

	private const IDEMPOTENCY_PREFIX = 'delegated:';
	private const REFERENCE_PREFIX   = 'dop_';
	private const MAX_INPUT_BYTES    = 65536;
	private const MAX_PROJECTION_BYTES = 32768;

	private Jobs $jobs;
	private DelegatedOperationRegistry $registry;

	public function __construct( ?Jobs $jobs = null, ?DelegatedOperationRegistry $registry = null ) {
		$this->jobs     = $jobs ?? new Jobs();
		$this->registry = $registry ?? new DelegatedOperationRegistry();
	}

	/** Submit an owner-registered operation. */
	public function submit( array $request ): array {
		$action_id   = trim( (string) ( $request['action'] ?? '' ) );
		$operation_id = trim( (string) ( $request['operation_id'] ?? '' ) );
		$raw_input   = is_array( $request['input'] ?? null ) ? $request['input'] : array();
		$timestamp   = isset( $request['timestamp'] ) && is_numeric( $request['timestamp'] ) ? max( 0, (int) $request['timestamp'] ) : null;
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

		$operation_ref = $this->reference( $action_id, $operation_id );
		$context       = $this->context( 'submit', $action_id, $operation_id, $operation_ref, $actor );
		$normalized    = $this->invoke( $action['normalize_input'], array( $raw_input, $context ), 'delegated_input_invalid' );
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

		$prepared = $this->invoke( $action['prepare'], array( $normalized, $context ), 'delegated_action_prepare_failed' );
		if ( is_wp_error( $prepared ) ) {
			return $this->fromError( $prepared );
		}
		$execution = $this->prepareExecution( $prepared );
		if ( is_wp_error( $execution ) ) {
			return $this->fromError( $execution );
		}

		$engine_data = is_array( $execution['initial_data'] ?? null ) ? $execution['initial_data'] : array();
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
			'action'       => $action_id,
			'version'      => $action['version'],
			'operation_id' => $operation_id,
			'operation_ref' => $operation_ref,
			'input'        => $normalized,
			'initiator'    => $actor,
			'execution_owner' => array(
				'user_id'    => $execution['user_id'],
				'agent_id'   => $execution['agent_id'],
				'agent_slug' => $execution['agent_slug'],
			),
			'timestamp'    => $timestamp,
		);

		$fingerprint_payload = array(
			'action'          => $action_id,
			'version'         => $action['version'],
			'input'           => $normalized,
			'execution_owner' => $engine_data['delegated_operation']['execution_owner'],
			'workflow'        => $execution['workflow'],
			'initial_data'    => $execution['initial_data'],
			'timestamp'       => $timestamp,
		);
		$fingerprint = hash( 'sha256', (string) wp_json_encode( $this->canonicalize( $fingerprint_payload ) ) );
		$create_args = array(
			'pipeline_id'        => 'direct',
			'flow_id'            => 'direct',
			'source'             => 'delegated',
			'label'              => isset( $prepared['label'] ) ? (string) $prepared['label'] : $action_id,
			'user_id'            => $execution['user_id'],
			'idempotency_key'    => $this->idempotencyKey( $operation_ref ),
			'request_fingerprint' => $fingerprint,
			'operation_state'    => 'preparing',
			'operation_step_id'  => $execution['first_step_id'],
			'engine_data'        => $engine_data,
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
		$job_status = (string) ( $job['status'] ?? '' );
		if ( ! JobStatus::isStatusFinal( $job_status ) && ! in_array( $job_status, array( JobStatus::PROCESSING, JobStatus::WAITING ), true ) ) {
			if ( ! empty( $creation['created'] ) && ! $this->persistJobReference( $job_id ) ) {
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
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$step_id = JobRetryPolicy::resolveDirectResumeStepId( $engine );
		if ( ! is_callable( $resolved['action']['retry'] ?? null ) ) {
			return $this->failure( 'delegated_operation_retry_unsupported', __( 'The action owner has not registered safe retry reconciliation.', 'data-machine' ) );
		}
		$summary    = RunMetrics::fromJob( $job );
		$run_result = is_array( $summary['run_result'] ?? null ) ? $summary['run_result'] : array();
		$retry_safe = $this->invoke( $resolved['action']['retry'], array( $run_result, $resolved['context'] ), 'delegated_operation_retry_unsafe' );
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
		$job       = $resolved['job'];
		$job_id    = (int) $job['job_id'];
		$step_id   = (string) ( $job['operation_step_id'] ?? '' );
		$generation = (int) ( $job['operation_generation'] ?? 0 );
		$token     = (string) ( $job['operation_claim_token'] ?? '' );
		$transition = $this->jobs->cancel_pending_direct_operation( $job_id );
		if ( empty( $transition['success'] ) ) {
			return $this->failure( 'delegated_operation_not_cancellable', __( 'Only submitted or delayed operations can be cancelled safely.', 'data-machine' ) );
		}
		if ( function_exists( 'as_unschedule_action' ) && '' !== $step_id && $generation > 0 && '' !== $token ) {
			as_unschedule_action(
				'datamachine_execute_step',
				array(
					'job_id'                 => $job_id,
					'flow_step_id'           => $step_id,
					'operation_generation'   => $generation,
					'operation_claim_token'  => $token,
				),
				'data-machine'
			);
		}
		return $this->response( $this->jobs->get_job( $job_id ), $resolved['action'], $resolved['context'], true );
	}

	/** Resolve, attest, and owner-authorize an existing operation. */
	private function resolve( array $request, string $phase ): array {
		$action_id    = trim( (string) ( $request['action'] ?? '' ) );
		$operation_ref = trim( (string) ( $request['operation_ref'] ?? '' ) );
		$action       = $this->registry->get( $action_id );
		if ( is_wp_error( $action ) ) {
			return array( 'error_result' => $this->fromError( $action ) );
		}
		if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_ref_invalid', __( 'The operation reference is invalid.', 'data-machine' ) ) );
		}
		$job = $this->jobs->get_job_by_idempotency_key( $this->idempotencyKey( $operation_ref ) );
		if ( ! is_array( $job ) ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_not_found', __( 'The delegated operation was not found.', 'data-machine' ) ) );
		}
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$stored = is_array( $engine['delegated_operation'] ?? null ) ? $engine['delegated_operation'] : array();
		if ( $action_id !== (string) ( $stored['action'] ?? '' ) || $operation_ref !== (string) ( $stored['operation_ref'] ?? '' ) ) {
			return array( 'error_result' => $this->failure( 'delegated_operation_not_found', __( 'The delegated operation was not found.', 'data-machine' ) ) );
		}
		if ( (string) $action['version'] !== (string) ( $stored['version'] ?? '' ) ) {
			return array( 'error_result' => $this->failure( 'delegated_action_version_conflict', __( 'The operation requires its frozen action contract version.', 'data-machine' ) ) );
		}

		$actor   = $this->actor();
		$context = $this->context( $phase, $action_id, (string) ( $stored['operation_id'] ?? '' ), $operation_ref, $actor );
		$context['input'] = is_array( $stored['input'] ?? null ) ? $stored['input'] : array();
		$authorized = $this->authorize( $action, $context );
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
		} catch ( \InvalidArgumentException $exception ) {
			do_action( 'datamachine_log', 'error', 'Delegated operation workflow validation failed', array( 'exception' => $exception->getMessage() ) );
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
				$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( array( 'agent_id' => $agent_id, 'agent_slug' => $agent_slug ) );
			} catch ( \InvalidArgumentException $exception ) {
				do_action( 'datamachine_log', 'error', 'Delegated operation owner resolution failed', array( 'exception' => $exception->getMessage() ) );
				return new \WP_Error( 'delegated_execution_owner_invalid', __( 'The registered execution owner is invalid.', 'data-machine' ) );
			}
			$agent_id   = $identity->agent_id;
			$agent_slug = $identity->agent_slug;
			$user_id    = $identity->owner_id;
		}
		if ( $user_id <= 0 ) {
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
		$summary    = RunMetrics::fromJob( $job );
		$run_result = JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) && is_array( $summary['run_result'] ?? null ) ? $summary['run_result'] : array();
		$projection = $this->invoke( $action['project'], array( $run_result, $context ), 'delegated_projection_failed' );
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
		if ( 'retrying' === $status && is_array( $engine['retry'] ?? null ) ) {
			$result['retry'] = array_filter(
				array(
					'attempt'       => max( 0, (int) ( $engine['retry']['attempt'] ?? 0 ) ),
					'max_attempts'  => max( 0, (int) ( $engine['retry']['max_attempts'] ?? 0 ) ),
					'next_retry_at' => isset( $engine['retry']['next_retry_at'] ) ? (string) $engine['retry']['next_retry_at'] : null,
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
		if ( JobStatus::PENDING === $status && ! empty( $engine['retry']['next_retry_at'] ) ) {
			return 'retrying';
		}
		if ( ! $base->isFinal() ) {
			return in_array( $status, array( JobStatus::PROCESSING, JobStatus::WAITING ), true ) ? 'executing' : 'submitted';
		}
		if ( $base->isFailure() ) {
			return 'failed';
		}
		if ( str_starts_with( $status, JobStatus::CANCELLED ) ) {
			return 'cancelled';
		}
		$canonical_status = strtolower( (string) ( $run_result['status'] ?? '' ) );
		$canonical_no_op = in_array( $canonical_status, array( 'agent_skipped', 'skipped', 'completed_no_items', 'no_items', 'no-op' ), true );
		$projected_zero  = isset( $projection['effect_count'] ) && is_int( $projection['effect_count'] ) && 0 === $projection['effect_count'];
		$canonical_count = $run_result['outputs']['effect_count'] ?? null;
		$canonical_zero  = is_int( $canonical_count ) && 0 === $canonical_count;
		if ( $base->isAgentSkipped() || $base->isCompletedNoItems() || $canonical_no_op || $canonical_zero || $projected_zero ) {
			return 'no-op';
		}
		return 'executed';
	}

	private function authorize( array $action, array $context ) {
		$result = $this->invoke( $action['authorize'], array( $context ), 'delegated_action_forbidden' );
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
		return compact( 'phase', 'action', 'operation_id', 'operation_ref', 'actor' );
	}

	private function reference( string $action, string $operation_id ): string {
		return self::REFERENCE_PREFIX . hash( 'sha256', $action . "\0" . $operation_id );
	}

	private function idempotencyKey( string $operation_ref ): string {
		return self::IDEMPOTENCY_PREFIX . substr( $operation_ref, strlen( self::REFERENCE_PREFIX ) );
	}

	private function invoke( callable $callback, array $arguments, string $error_code ) {
		try {
			return $callback( ...$arguments );
		} catch ( \Throwable $exception ) {
			do_action(
				'datamachine_log',
				'error',
				'Delegated operation owner callback failed',
				array(
					'error_code' => $error_code,
					'exception'  => $exception->getMessage(),
				)
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
