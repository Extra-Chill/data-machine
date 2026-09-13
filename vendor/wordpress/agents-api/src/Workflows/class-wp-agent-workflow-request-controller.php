<?php
/**
 * Request-triggered, reconnectable controller for a single workflow operation.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Workflows;

use AgentsAPI\AI\WP_Agent_Exclusive_Run_Control_Store;
use AgentsAPI\AI\WP_Agent_Run_Control;
use AgentsAPI\AI\WP_Agent_Run_Control_Store_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Owns durable request idempotency and one foreground advance lane per operation.
 *
 * The controller deliberately knows nothing about the consumer's job, user, or
 * delivery channel. Consumers supply an opaque operation id and optional terminal
 * callbacks; workflow execution remains the runner/awaiter responsibility.
 */
final class WP_Agent_Workflow_Request_Controller {

	public const SCHEMA = 'agents-api/workflow-request-controller/v1';
	private const DEFAULT_ADVANCE_TIME_LIMIT_MS = 5000;
	private const DEFAULT_ADVANCE_ACTION_LIMIT = 25;
	private const TERMINAL_DELIVERY_CLAIM_VERSION = 2;
	private const MAX_TERMINAL_EVIDENCE_BYTES = 65536;

	/** @var callable|null */
	private $terminal_action;
	/** @var callable|null */
	private $terminal_cleanup;
	/** @var callable */
	private $clock;

	public function __construct(
		private WP_Agent_Workflow_Runner $runner,
		private WP_Agent_Workflow_Run_Recorder $recorder,
		private WP_Agent_Workflow_Run_Awaiter $awaiter,
		private string $store_key = 'agents_workflow_request_controller',
		?callable $terminal_action = null,
		?callable $terminal_cleanup = null,
		?callable $clock = null
	) {
		$this->terminal_action  = $terminal_action;
		$this->terminal_cleanup = $terminal_cleanup;
		$this->clock            = $clock ?? static fn(): int => time();
	}

	/**
	 * Reserve an operation and make one bounded foreground advance attempt.
	 * Repeated starts for the same operation never create a second workflow run.
	 *
	 * @param array<string,mixed> $inputs Workflow inputs.
	 * @param array<string,mixed> $options Runner options plus bounded `await`,
	 *                                     `lease_seconds`, and `worker_id` options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( string $operation_id, WP_Agent_Workflow_Spec $spec, array $inputs = array(), array $options = array() ): array|\WP_Error {
		$operation_id = trim( $operation_id );
		if ( '' === $operation_id ) {
			return new \WP_Error( 'agents_workflow_operation_required', 'operation_id must be a non-empty string.' );
		}

		try {
			$this->reserve( $operation_id, $spec, $inputs, $options );
		} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
			unset( $error );
			return $this->storage_unavailable( 'start', 'reserve' );
		}
		return $this->advance_operation( $operation_id, $options, 'start' );
	}

	/**
	 * Reconnect to an existing operation. No workflow spec or consumer state is
	 * required because the durable reservation contains the replayable start data.
	 *
	 * @param array<string,mixed> $options Await and lease options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function reconnect( string $operation_id, array $options = array() ): array|\WP_Error {
		return $this->advance_operation( trim( $operation_id ), $options, 'reconnect' );
	}

	/**
	 * Public status operation; `get()` remains the raw durable-record accessor.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_status( string $operation_id ): array|\WP_Error {
		$operation_id = trim( $operation_id );
		try {
			$entry = $this->get( $operation_id );
		} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
			unset( $error );
			return $this->storage_unavailable( 'get_status', 'read' );
		}
		if ( null === $entry ) {
			return new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' );
		}
		return $this->response( $operation_id, $entry, $this->lease_is_active( $entry ) );
	}

	/**
	 * Request cancellation and record its terminal disposition without waiting for a
	 * future worker to observe the request. Repeating cancel is therefore harmless.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel( string $operation_id ): array|\WP_Error {
		$operation_id = trim( $operation_id );
		$phase        = 'read';
		try {
			$entry = $this->get( $operation_id );
			if ( '' === $operation_id || null === $entry ) {
				return new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' );
			}

			$run_id = $this->string_value( $entry['run_id'] ?? '' );
			$phase  = 'request_cancel';
			WP_Agent_Run_Control::request_cancel( WP_Agent_Workflow_Runner::RUN_CONTROL_STORE, $run_id );
			$result = $this->recorder->find( $run_id );
			if ( null !== $result && ! $this->is_terminal( $result ) ) {
				$result = $result->with( array(
					'status'   => WP_Agent_Workflow_Run_Result::STATUS_CANCELLED,
					'error'    => array( 'code' => 'cancel_requested', 'message' => 'Workflow operation cancellation was requested.' ),
					'ended_at' => $this->int_value( ( $this->clock )() ),
				) );
				$this->recorder->update( $result );
			}
			if ( null === $result ) {
				$phase = 'read_status';
				$entry = $this->get( $operation_id );
				return null === $entry ? new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' ) : $this->response( $operation_id, $entry, $this->lease_is_active( $entry ) );
			}
			$phase = 'record_terminal';
			$entry = $this->record_terminal( $operation_id, $result );
			$this->cleanup_operation_actions( $run_id );
			$phase = 'deliver_terminal';
			$this->deliver_terminal_once( $operation_id, $entry, $result );
			$phase = 'read_terminal';
			return $this->response( $operation_id, $this->get( $operation_id ) ?? $entry, false );
		} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
			unset( $error );
			return $this->storage_unavailable( 'cancel', $phase );
		}
	}

	/** @return array<string,mixed>|null */
	public function get( string $operation_id ): ?array {
		$state = WP_Agent_Run_Control::state( $this->store_key );
		$entry = $state['runs'][ trim( $operation_id ) ] ?? null;
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * @param array<string,mixed> $inputs
	 * @param array<string,mixed> $options
	 */
	private function reserve( string $operation_id, WP_Agent_Workflow_Spec $spec, array $inputs, array $options ): void {
		WP_Agent_Run_Control::mutate_state(
			$this->store_key,
			function ( array $state ) use ( $operation_id, $spec, $inputs, $options ): array {
				if ( isset( $state['runs'][ $operation_id ] ) ) {
					return array( 'state' => $state, 'result' => null );
				}
				$run_id = 'workflow_request_' . substr( hash( 'sha256', $this->store_key . "\0" . $operation_id ), 0, 32 );
				$state['runs'][ $operation_id ] = array(
					'run_id'      => $run_id,
					'spec'        => $spec->to_array(),
					'inputs'      => $inputs,
					'options'     => $this->runner_options( $options ),
					'terminal'    => false,
					'disposition' => 'pending',
					'lease'       => array(),
				);
				return array( 'state' => $state, 'result' => null );
			}
		);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>|\WP_Error
	 */
	public function advance( string $operation_id, array $options = array() ): array|\WP_Error {
		return $this->advance_operation( $operation_id, $options, 'advance' );
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>|\WP_Error
	 */
	private function advance_operation( string $operation_id, array $options, string $operation ): array|\WP_Error {
		if ( '' === $operation_id ) {
			return new \WP_Error( 'agents_workflow_operation_required', 'operation_id must be a non-empty string.' );
		}
		$phase = 'claim_lease';
		try {
			$await_options = $this->bounded_await_options( $options );
			$lease_seconds = max( $this->int_value( $options['lease_seconds'] ?? 0 ), (int) ceil( $this->int_value( $await_options['time_limit_ms'] ) / 1000 ) + 2, 1 );
			$lease = $this->claim_lease( $operation_id, $lease_seconds, $this->string_value( $options['worker_id'] ?? '' ) );
			if ( null === $lease ) {
				$phase = 'read_status';
				$entry = $this->get( $operation_id );
				if ( null === $entry ) {
					return new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' );
				}
				if ( ! empty( $entry['terminal'] ) ) {
					$result = $this->recorder->find( $this->string_value( $entry['run_id'] ?? '' ) );
					if ( null !== $result ) {
						if ( 'delivered' !== ( $entry['disposition'] ?? '' ) ) {
							$this->cleanup_operation_actions( $result->get_run_id() );
						}
						$phase = 'deliver_terminal';
						$this->deliver_terminal_once( $operation_id, $entry, $result );
						$phase = 'read_terminal';
						$entry = $this->get( $operation_id ) ?? $entry;
					}
					return $this->response( $operation_id, $entry, false );
				}
				return $this->response( $operation_id, $entry, true );
			}

			$primary_error = null;
			try {
				$phase = 'read_operation';
				$entry = $this->get( $operation_id );
				if ( null === $entry ) {
					return new \WP_Error( 'agents_workflow_operation_not_found', 'No workflow operation was found for the requested operation_id.' );
				}
				$result = $this->recorder->find( $this->string_value( $entry['run_id'] ?? '' ) );
				if ( null === $result ) {
					$spec = WP_Agent_Workflow_Spec::from_array( is_array( $entry['spec'] ?? null ) ? $entry['spec'] : array() );
					if ( is_wp_error( $spec ) ) {
						return $spec;
					}
					$run_options           = is_array( $entry['options'] ?? null ) ? $entry['options'] : array();
					$run_options['run_id'] = $this->string_value( $entry['run_id'] ?? '' );
					$phase                  = 'run';
					$result                 = $this->runner->run( $spec, is_array( $entry['inputs'] ?? null ) ? $entry['inputs'] : array(), $run_options );
				}

				if ( $result->is_suspended() ) {
					$phase = 'renew_lease';
					$this->renew_lease( $operation_id, $lease, $lease_seconds );
					$phase   = 'await';
					$awaited = $this->awaiter->await( $result->get_run_id(), $this->recorder, $await_options );
					if ( is_wp_error( $awaited ) ) {
						return $awaited;
					}
					$result = $this->recorder->find( $result->get_run_id() ) ?? $result;
				}

				$phase = 'record_terminal';
				$entry = $this->record_terminal( $operation_id, $result, $lease );
				if ( ! empty( $entry['terminal'] ) ) {
					$this->cleanup_operation_actions( $result->get_run_id() );
					$phase = 'deliver_terminal';
					$this->deliver_terminal_once( $operation_id, $entry, $result );
					$phase = 'read_terminal';
					$entry = $this->get( $operation_id ) ?? $entry;
				}
				$stored_lease = $this->array_value( $entry['lease'] ?? array() );
				$rejected     = $lease !== $this->string_value( $stored_lease['token'] ?? '' ) && $this->lease_is_active( $entry );
				return $this->response( $operation_id, $entry, $rejected );
			} catch ( \Throwable $error ) {
				$primary_error = $error;
				throw $error;
			} finally {
				$active_phase = $phase;
				$phase        = 'release_lease';
				try {
					$this->release_lease( $operation_id, $lease );
				} catch ( \Throwable $release_error ) {
					if ( null === $primary_error ) {
						throw $release_error;
					}
				}
				$phase = $active_phase;
			}
		} catch ( WP_Agent_Run_Control_Store_Exception $error ) {
			unset( $error );
			return $this->storage_unavailable( $operation, $phase );
		}
	}

	/** @return string|null */
	private function claim_lease( string $operation_id, int $seconds, string $worker_id ): ?string {
		$result = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $seconds, $worker_id ) {
			$entry = $state['runs'][ $operation_id ] ?? null;
			if ( ! is_array( $entry ) || ! empty( $entry['terminal'] ) ) {
				return array( 'state' => $state, 'result' => null );
			}
			$now = $this->int_value( ( $this->clock )() );
			$lease = $this->array_value( $entry['lease'] ?? array() );
			if ( $this->int_value( $lease['expires_at'] ?? 0 ) > $now ) {
				return array( 'state' => $state, 'result' => null );
			}
			$token          = bin2hex( random_bytes( 12 ) );
			$entry['lease'] = array( 'token' => $token, 'worker_id' => '' !== $worker_id ? $worker_id : $token, 'expires_at' => $now + $seconds );
			$state['runs'][ $operation_id ] = $entry;
			return array( 'state' => $state, 'result' => $token );
		} );
		return is_string( $result ) ? $result : null;
	}

	private function renew_lease( string $operation_id, string $token, int $seconds ): void {
		WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $token, $seconds ) {
			$entry = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
			$lease = $this->array_value( $entry['lease'] ?? array() );
			if ( $token === $this->string_value( $lease['token'] ?? '' ) && empty( $entry['terminal'] ) ) {
				$lease['expires_at'] = $this->int_value( ( $this->clock )() ) + $seconds;
				$entry['lease'] = $lease;
				$state['runs'][ $operation_id ] = $entry;
			}
			return array( 'state' => $state, 'result' => null );
		} );
	}

	/** @return array<string,mixed> */
	private function record_terminal( string $operation_id, WP_Agent_Workflow_Run_Result $result, ?string $lease_token = null ): array {
		$stored = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $result, $lease_token ) {
			$entry = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
			if ( null !== $lease_token && $lease_token !== $this->string_value( $this->array_value( $entry['lease'] ?? array() )['token'] ?? '' ) ) {
				return array( 'state' => $state, 'result' => $entry );
			}
			if ( $this->is_terminal( $result ) && empty( $entry['terminal'] ) ) {
				$entry['terminal']        = true;
				$entry['terminal_status'] = $result->get_status();
				$entry['result']          = $this->bounded_terminal_evidence( $result );
				$entry['lease']           = array();
				$entry['terminal_at']     = $this->int_value( ( $this->clock )() );
			}
			$state['runs'][ $operation_id ] = $entry;
			return array( 'state' => $state, 'result' => $entry );
		} );
		return $this->array_value( $stored );
	}

	/** @param array<string,mixed> $entry */
	private function deliver_terminal_once( string $operation_id, array $entry, WP_Agent_Workflow_Run_Result $result ): void {
		if ( null === $this->terminal_action && null === $this->terminal_cleanup ) {
			WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id ) {
				$stored = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
				if ( in_array( $stored['disposition'] ?? '', array( 'pending', 'callback_failed' ), true ) ) {
					$stored['disposition']            = 'delivered';
					$stored['terminal_cleanup']       = true;
					$stored['lease']                  = array();
					$state['runs'][ $operation_id ]   = $stored;
				}
				return array( 'state' => $state, 'result' => null );
			} );
			return;
		}

		$store = WP_Agent_Run_Control::store();
		if ( ! $store instanceof WP_Agent_Exclusive_Run_Control_Store ) {
			throw new \RuntimeException( 'Terminal callbacks require a run-control store with exclusive claim support.' );
		}
		$disposition = $this->string_value( $entry['disposition'] ?? '' );
		if ( 'delivering' === $disposition && self::TERMINAL_DELIVERY_CLAIM_VERSION !== $this->int_value( $entry['delivery_claim_version'] ?? 0 ) ) {
			// A pre-upgrade worker may still be executing, and its fixed lease cannot
			// prove process death. Avoid an unsafe automatic replay.
			return;
		}

		$store->execute_claimed( $this->store_key . "\0terminal\0" . $operation_id, function () use ( $operation_id, $entry, $result ): void {
			$token   = bin2hex( random_bytes( 12 ) );
			$claimed = WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $token ) {
				$stored      = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
				$disposition = $this->string_value( $stored['disposition'] ?? '' );
				$recoverable = 'delivering' === $disposition && self::TERMINAL_DELIVERY_CLAIM_VERSION === $this->int_value( $stored['delivery_claim_version'] ?? 0 );
				if ( ! in_array( $disposition, array( 'pending', 'callback_failed' ), true ) && ! $recoverable ) {
					return array( 'state' => $state, 'result' => false );
				}
				$stored['disposition']            = 'delivering';
				$stored['delivery_token']         = $token;
				$stored['delivery_claim_version'] = self::TERMINAL_DELIVERY_CLAIM_VERSION;
				$stored['terminal_cleanup']       = true;
				$stored['lease']                  = array();
				$state['runs'][ $operation_id ]   = $stored;
				return array( 'state' => $state, 'result' => $token );
			} );
			if ( $token !== $claimed ) {
				return;
			}

			$delivered = true;
			try {
				if ( null !== $this->terminal_action ) {
					call_user_func( $this->terminal_action, $operation_id, $result, $entry );
				}
			} catch ( \Throwable $error ) {
				$delivered = false;
				unset( $error );
			} finally {
				// Callback consumers must tolerate retry after an interrupted delivery.
				if ( null !== $this->terminal_cleanup ) {
					try {
						call_user_func( $this->terminal_cleanup, $operation_id, $result, $entry );
					} catch ( \Throwable $error ) {
						$delivered = false;
						unset( $error );
					}
				}
			}
			WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $delivered, $token ) {
				$stored = $this->array_value( $state['runs'][ $operation_id ] ?? array() );
				if ( 'delivering' === ( $stored['disposition'] ?? '' ) && $token === ( $stored['delivery_token'] ?? '' ) ) {
					$stored['disposition'] = $delivered ? 'delivered' : 'callback_failed';
					unset( $stored['delivery_token'], $stored['delivery_claim_version'] );
					$state['runs'][ $operation_id ] = $stored;
				}
				return array( 'state' => $state, 'result' => null );
			} );
		} );
	}

	private function release_lease( string $operation_id, string $token ): void {
		WP_Agent_Run_Control::mutate_state( $this->store_key, function ( array $state ) use ( $operation_id, $token ) {
			$entry = $state['runs'][ $operation_id ] ?? null;
			if ( is_array( $entry ) && $token === $this->string_value( $this->array_value( $entry['lease'] ?? array() )['token'] ?? '' ) ) {
				$entry['lease'] = array();
				$state['runs'][ $operation_id ] = $entry;
			}
			return array( 'state' => $state, 'result' => null );
		} );
	}

	/** Remove only this run's AS branch/reconcile/resume actions; no shared group is touched. */
	private function cleanup_operation_actions( string $run_id ): void {
		if ( '' === $run_id || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		$group = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run( $run_id );
		foreach ( WP_Agent_Workflow_Scoped_Drain::default_hooks() as $hook ) {
			as_unschedule_all_actions( $hook, null, $group );
		}
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private function response( string $operation_id, array $entry, bool $busy ): array {
		$terminal = ! empty( $entry['terminal'] );
		return array(
			'schema'        => self::SCHEMA,
			'operation_id'  => $operation_id,
			'run_id'        => $this->string_value( $entry['run_id'] ?? '' ),
			'terminal'      => $terminal,
			'reconnectable' => ! $terminal,
			'busy'          => $busy,
			'status'        => $terminal ? $this->string_value( $entry['terminal_status'] ?? '' ) : 'running',
			'result'        => $terminal ? ( $entry['result'] ?? null ) : null,
		);
	}

	private function storage_unavailable( string $operation, string $phase ): \WP_Error {
		return new \WP_Error(
			'agents_workflow_run_control_unavailable',
			'Workflow run-control storage is temporarily unavailable. Retry the operation.',
			array(
				'status'    => 503,
				'retryable' => true,
				'operation' => $operation,
				'phase'     => $phase,
			)
		);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function runner_options( array $options ): array {
		unset( $options['await'], $options['lease_seconds'], $options['worker_id'] );
		return $options;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function bounded_await_options( array $options ): array {
		$await = $this->array_value( $options['await'] ?? array() );
		$await['time_limit_ms'] = max( 1, min( self::DEFAULT_ADVANCE_TIME_LIMIT_MS, $this->int_value( $await['time_limit_ms'] ?? self::DEFAULT_ADVANCE_TIME_LIMIT_MS ) ) );
		$await['limit'] = max( 1, min( self::DEFAULT_ADVANCE_ACTION_LIMIT, $this->int_value( $await['limit'] ?? self::DEFAULT_ADVANCE_ACTION_LIMIT ) ) );
		return $await;
	}

	/** @param array<string,mixed> $entry */
	private function lease_is_active( array $entry ): bool {
		$lease = $this->array_value( $entry['lease'] ?? array() );
		return $this->int_value( $lease['expires_at'] ?? 0 ) > $this->int_value( ( $this->clock )() );
	}

	/** @return array<string,mixed> */
	private function bounded_terminal_evidence( WP_Agent_Workflow_Run_Result $result ): array {
		$evidence = $result->to_run_result_envelope()->to_array();
		$encoded  = json_encode( $evidence );
		if ( false === $encoded || strlen( $encoded ) <= self::MAX_TERMINAL_EVIDENCE_BYTES ) {
			return $evidence;
		}

		// Keep terminal status, error, and reference evidence; large replay/output data
		// remains with the recorder rather than making request-control state unbounded.
		$evidence['outputs']  = array( 'truncated' => true );
		$evidence['replay']   = array();
		$evidence['metadata'] = array( 'terminal_evidence_truncated' => true );
		$evidence['logs']     = array_slice( is_array( $evidence['logs'] ?? null ) ? $evidence['logs'] : array(), 0, 16 );
		return $evidence;
	}

	private function is_terminal( WP_Agent_Workflow_Run_Result $result ): bool {
		return in_array( $result->get_status(), array( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, WP_Agent_Workflow_Run_Result::STATUS_FAILED, WP_Agent_Workflow_Run_Result::STATUS_SKIPPED, WP_Agent_Workflow_Run_Result::STATUS_CANCELLED ), true );
	}

	/** @return array<string,mixed> */
	private function array_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return WP_Agent_Run_Control::string_keyed_array( $value );
	}

	private function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}

	private function int_value( mixed $value ): int {
		return is_int( $value ) || is_float( $value ) || is_string( $value ) || is_bool( $value ) ? (int) $value : 0;
	}
}
