<?php
/**
 * Default handlers for generic step lifecycle hooks.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.146.2
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\Database\TransactionScope;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\ChildJobRecoveryPolicy;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PacketEngineData;
use DataMachine\Core\RunMetrics;

/**
 * Keeps source-ingestion processed-item behavior behind lifecycle hooks.
 */
class StepLifecycleHandler {

	/** @var array<int,int> Processed metrics deferred until terminal commit. */
	private static array $pending_processed_metrics = array();

	/**
	 * Seed lifecycle context before an inline continuation.
	 *
	 * @param int   $job_id           Job ID.
	 * @param array $flow_step_config Current flow step configuration.
	 * @param array $routed_packets   Packets routed to the next step.
	 */
	public static function handleInlineContinuation( int $job_id, array $flow_step_config, array $routed_packets ): bool {
		if ( self::isSourceIngestionStep( (string) ( $flow_step_config['step_type'] ?? '' ) ) ) {
			$existing = ProcessedItems::disposition_claims( \datamachine_get_engine_data( $job_id ) );
			$routed   = array();
			foreach ( $routed_packets as $packet ) {
				$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
				$routed   = array_replace( $routed, ProcessedItems::disposition_claims( $metadata ) );
			}
			if ( array_keys( $existing ) === array_keys( $routed ) ) {
				return true;
			}
			return self::reconcileStepOutput( $job_id, $flow_step_config, $routed_packets, true )['success'];
		}
		return true;
	}

	/**
	 * Reconcile engine-owned claims against exact packet dispositions.
	 *
	 * @return array{success:bool,handled:bool,retained:int,completed:int,released:int,omitted:int,explicit:int}
	 */
	public static function reconcileStepOutput( int $job_id, array $flow_step_config, array $packets, bool $step_success, int $recovery_generation = 0, string $recovery_claim_token = '' ): array {
		$result = array(
			'success'   => true,
			'handled'   => false,
			'retained'  => 0,
			'completed' => 0,
			'released'  => 0,
			'omitted'   => 0,
			'explicit'  => 0,
			'stale'     => false,
		);
		if ( $job_id <= 0 ) {
			return $result;
		}

		$step_type = (string) ( $flow_step_config['step_type'] ?? '' );
		if ( self::isSourceIngestionStep( $step_type ) ) {
			$current   = array();
			$seed_data = array();
			foreach ( $packets as $packet ) {
				$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
				if ( ProcessedItems::has_claim_metadata( $metadata ) && ! ProcessedItems::has_valid_claim_metadata( $metadata ) ) {
					$result['success'] = false;
					return $result;
				}
				$current = array_replace( $current, ProcessedItems::disposition_claims( $metadata ) );
				if ( empty( $seed_data ) && is_array( $metadata['_engine_data'] ?? null ) ) {
					$seed_data = PacketEngineData::sanitize( $metadata['_engine_data'], $job_id );
				}
				if ( ! isset( $seed_data['item_identifier'] ) && ! empty( $metadata['item_identifier'] ) ) {
					$seed_data['item_identifier'] = $metadata['item_identifier'];
				}
				if ( ! isset( $seed_data['source_type'] ) && ! empty( $metadata['source_type'] ) ) {
					$seed_data['source_type'] = $metadata['source_type'];
				}
			}
			if ( empty( $current ) ) {
				return $result;
			}
			$result['handled']  = true;
			$result['retained'] = count( $current );
			return self::commitReconciliation( $job_id, $flow_step_config, $current, array(), $seed_data, $result, $recovery_generation, $recovery_claim_token );
		}

		$engine_data = \datamachine_get_engine_data( $job_id );
		if ( ProcessedItems::has_claim_metadata( $engine_data ) && ! ProcessedItems::has_valid_claim_metadata( $engine_data ) ) {
			$result['success'] = false;
			return $result;
		}
		$current = ProcessedItems::disposition_claims( $engine_data );
		if ( empty( $current ) ) {
			return $result;
		}
		$result['handled'] = true;
		$resolved          = array();
		if ( $step_success ) {
			foreach ( $packets as $packet ) {
				$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
				if ( ProcessedItems::has_claim_metadata( $metadata ) && ! ProcessedItems::has_valid_claim_metadata( $metadata ) ) {
					$result['success'] = false;
					return $result;
				}
				$packet_claims = ProcessedItems::disposition_claims( $metadata );
				if ( empty( $packet_claims ) ) {
					continue;
				}
				if ( 1 !== count( $packet_claims ) ) {
					$result['success'] = false;
					return $result;
				}
				$disposition_id = (string) array_key_first( $packet_claims );
				$supplied_id    = (string) ( $metadata[ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] ?? $metadata['disposition_id'] ?? '' );
				if ( ( '' !== $supplied_id && ! hash_equals( $disposition_id, $supplied_id ) ) || ! isset( $current[ $disposition_id ] ) ) {
					$result['success'] = false;
					return $result;
				}
				$disposition = (string) ( $metadata['packet_disposition'] ?? 'succeeded' );
				if ( ! isset( $resolved[ $disposition_id ] ) || 'succeeded' !== $resolved[ $disposition_id ] ) {
					$resolved[ $disposition_id ] = $disposition;
				}
			}
		}

		$result = self::commitReconciliation( $job_id, $flow_step_config, array(), $resolved, array(), $result, $recovery_generation, $recovery_claim_token );
		if ( ! $result['success'] ) {
			return $result;
		}
		$evidence = $result['evidence'];
		$omitted  = $evidence['omitted_ids'];
		if ( ! empty( $omitted ) ) {
			$flow_step_id = (string) ( $flow_step_config['flow_step_id'] ?? $flow_step_config['step_id'] ?? '' );
			if ( '' !== $flow_step_id ) {
				RunMetrics::recordStepResult( $job_id, $flow_step_id, array( 'packet_disposition' => $evidence ) );
			}
			do_action(
				'datamachine_log',
				'warning',
				'Pipeline step omitted claimed packets; claims released for retry.',
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'omitted_ids'  => array_values( $omitted ),
				)
			);
		}

		unset( $result['evidence'] );
		return $result;
	}

	/** Apply every claim mutation and the exact engine replacement in one locked transaction. */
	private static function commitReconciliation( int $job_id, array $flow_step_config, array $source_claims, array $resolved, array $seed_data, array $result, int $recovery_generation, string $recovery_claim_token ): array {
		$max_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_attempts; ++$attempt ) {
			$attempt_result = self::commitReconciliationAttempt( $job_id, $flow_step_config, $source_claims, $resolved, $seed_data, $result, $recovery_generation, $recovery_claim_token );
			$retryable      = ! empty( $attempt_result['_retryable'] );
			unset( $attempt_result['_retryable'] );
			if ( ! $retryable || $attempt >= $max_attempts ) {
				return $attempt_result;
			}
			usleep( wp_rand( 5000, 25000 ) );
		}

		$result['success'] = false;
		return $result;
	}

	/** Execute one complete reconciliation transaction attempt. */
	private static function commitReconciliationAttempt( int $job_id, array $flow_step_config, array $source_claims, array $resolved, array $seed_data, array $result, int $recovery_generation, string $recovery_claim_token ): array {
		global $wpdb;
		$jobs       = new Jobs();
		$jobs_table = $jobs->get_table_name();
		$scope      = self::beginTransaction();
		if ( null === $scope ) {
			$result['success']    = false;
			$result['_retryable'] = $jobs->has_retryable_transaction_error();
			return $result;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier and job ID are prepared.
		$job_row = $wpdb->get_row( $wpdb->prepare( 'SELECT job_id, engine_data FROM %i WHERE job_id = %d FOR UPDATE', $jobs_table, $job_id ), ARRAY_A );
		if ( ! is_array( $job_row ) ) {
			self::rollbackTransaction( $scope );
			$result['success'] = false;
			return $result;
		}
		$encoded = $job_row['engine_data'] ?? null;
		$engine  = is_string( $encoded ) ? json_decode( $encoded, true ) : $encoded;
		$engine  = is_array( $engine ) ? $engine : array();
		if ( $recovery_generation > 0 && ! ChildJobRecoveryPolicy::recoveryExecutionMatches( $engine, $recovery_generation, $recovery_claim_token ) ) {
			self::rollbackTransaction( $scope );
			$result['success'] = false;
			$result['stale']   = true;
			return $result;
		}

		$current = empty( $source_claims ) ? ProcessedItems::disposition_claims( $engine ) : $source_claims;
		ksort( $current, SORT_STRING );
		$processed = new ProcessedItems();
		foreach ( $current as $claim ) {
			if ( ! $processed->lock_owned_claim_in_transaction( $claim ) ) {
				return self::rollbackReconciliationFailure( $scope, $jobs, $job_id, $result );
			}
		}
		$retained    = array();
		$completed   = array();
		$released    = array();
		$omitted     = array();
		$index       = 0;
		$is_transfer = in_array( 'transferred', $resolved, true );
		foreach ( $current as $disposition_id => $claim ) {
			$default     = ( empty( $resolved ) && ! empty( $source_claims ) ) || $is_transfer ? 'succeeded' : '';
			$disposition = $resolved[ $disposition_id ] ?? $default;
			if ( 'succeeded' === $disposition ) {
				$retained[ $disposition_id ] = $claim;
				continue;
			}
			if ( 'transferred' === $disposition ) {
				$released[] = $disposition_id;
				continue;
			}
			$allowed = apply_filters( 'datamachine_packet_reconciliation_claim_mutation', true, $index, $disposition_id, $disposition, $job_id );
			if ( ! $allowed ) {
				self::rollbackTransaction( $scope );
				wp_cache_delete( $job_id, 'datamachine_engine_data' );
				$result['success']    = false;
				$result['_retryable'] = false;
				return $result;
			}
			++$index;
			if ( 'reject_source' === $disposition ) {
				if ( ! self::completeClaim( $claim, $job_id, true ) ) {
					return self::rollbackReconciliationFailure( $scope, $jobs, $job_id, $result );
				}
				$completed[] = $disposition_id;
				continue;
			}
			if ( 1 !== self::releaseClaim( $claim ) ) {
				return self::rollbackReconciliationFailure( $scope, $jobs, $job_id, $result );
			}
			$released[] = $disposition_id;
			if ( '' === $disposition ) {
				$omitted[] = $disposition_id;
			}
		}

		$evidence = self::claimEvidence( $flow_step_config, array_keys( $retained ), $completed, $released, $omitted );
		$engine   = array_replace_recursive( $engine, $seed_data );
		if ( isset( $seed_data['packet_fanout_transfer'] ) ) {
			$engine['packet_fanout_transfer'] = $seed_data['packet_fanout_transfer'];
		}
		unset( $engine[ ProcessedItems::CLAIM_METADATA_KEY ], $engine[ ProcessedItems::CLAIMS_METADATA_KEY ] );
		if ( ! empty( $retained ) ) {
			$engine[ ProcessedItems::CLAIMS_METADATA_KEY ] = array_values( $retained );
		}
		$history                               = is_array( $engine['packet_disposition_evidence'] ?? null ) ? $engine['packet_disposition_evidence'] : array();
		$history[]                             = $evidence;
		$engine['packet_disposition_evidence'] = array_slice( $history, -20 );
		$engine                                = self::compactPacketRuntimeState( $engine, array_keys( $retained ) );
		$persist                               = apply_filters( 'datamachine_packet_reconciliation_engine_persist', true, $job_id, $engine );
		if ( ! $persist || ! $jobs->store_engine_data_in_transaction( $job_id, $engine ) ) {
			$retryable = $persist && $jobs->has_retryable_transaction_error();
			return self::rollbackReconciliationFailure( $scope, $jobs, $job_id, $result, $retryable );
		}
		if ( ! self::commitTransaction( $scope ) ) {
			return self::rollbackReconciliationFailure( $scope, $jobs, $job_id, $result );
		}
		$jobs->publish_committed_engine_data( $job_id, $engine );
		$result['retained']   = count( $retained );
		$result['completed']  = count( $completed );
		$result['released']   = count( $released );
		$result['omitted']    = count( $omitted );
		$result['explicit']   = count( $resolved );
		$result['evidence']   = $evidence;
		$result['_retryable'] = false;
		return $result;
	}

	/** Roll back a failed claim mutation and preserve retryability. */
	private static function rollbackReconciliationFailure( TransactionScope $scope, Jobs $jobs, int $job_id, array $result, ?bool $retryable = null ): array {
		global $wpdb;
		$retryable ??= $jobs->has_retryable_transaction_error();
		self::rollbackTransaction( $scope );
		wp_cache_delete( $job_id, 'datamachine_engine_data' );
		$result['success']    = false;
		$result['_retryable'] = $retryable;
		return $result;
	}

	/** Renew every retained claim while a waiting or retryable execution is parked. */
	public static function renewParkedClaims( int $job_id, int $recovery_generation = 0, string $recovery_claim_token = '' ): array {
		global $wpdb;
		$result     = array(
			'success' => false,
			'stale'   => false,
			'renewed' => 0,
		);
		$jobs       = new Jobs();
		$jobs_table = $jobs->get_table_name();
		$scope      = $job_id > 0 ? self::beginTransaction() : null;
		if ( null === $scope ) {
			return $result;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier and job ID are prepared.
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT status, engine_data FROM %i WHERE job_id = %d FOR UPDATE', $jobs_table, $job_id ), ARRAY_A );
		if ( ! is_array( $job ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}
		$encoded = $job['engine_data'] ?? null;
		$engine  = is_string( $encoded ) ? json_decode( $encoded, true ) : $encoded;
		$engine  = is_array( $engine ) ? $engine : array();
		if ( JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}
		if ( $recovery_generation > 0 && ! ChildJobRecoveryPolicy::recoveryExecutionMatches( $engine, $recovery_generation, $recovery_claim_token ) ) {
			self::rollbackTransaction( $scope );
			$result['stale'] = true;
			return $result;
		}
		if ( ProcessedItems::has_claim_metadata( $engine ) && ! ProcessedItems::has_valid_claim_metadata( $engine ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}

		$claims = ProcessedItems::disposition_claims( $engine );
		ksort( $claims, SORT_STRING );
		$processed = new ProcessedItems();
		foreach ( $claims as $claim ) {
			if ( ! $processed->renew_owned_claim_in_transaction( $claim, $job_id ) ) {
				self::rollbackTransaction( $scope );
				return $result;
			}
			++$result['renewed'];
		}
		if ( ! self::commitTransaction( $scope ) ) {
			self::rollbackTransaction( $scope );
			$result['renewed'] = 0;
			return $result;
		}
		$result['success'] = true;
		return $result;
	}

	/** Remove claims transferred to fanout packets from parent terminal ownership. */
	public static function transferClaimsToFanout( int $job_id, array $packets, int $recovery_generation = 0, string $recovery_claim_token = '' ): array {
		$claims = array();
		foreach ( $packets as $packet ) {
			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			if ( ProcessedItems::has_claim_metadata( $metadata ) && ! ProcessedItems::has_valid_claim_metadata( $metadata ) ) {
				return array(
					'success' => false,
					'stale'   => false,
				);
			}
			$packet_claims = ProcessedItems::disposition_claims( $metadata );
			$claims = array_replace( $claims, $packet_claims );
		}
		if ( empty( $claims ) ) {
			return array(
				'success' => true,
				'stale'   => false,
			);
		}
		$transfer_id           = bin2hex( random_bytes( 16 ) );
		$result                = self::commitReconciliation(
			$job_id,
			array( 'step_type' => 'fanout_transfer' ),
			$claims,
			array_fill_keys( array_keys( $claims ), 'transferred' ),
			array(
				'packet_fanout_transfer' => array(
					'transfer_id'         => $transfer_id,
					'state'               => 'prepared',
					'prepared_at'         => gmdate( 'c' ),
					'recovery_generation' => $recovery_generation,
					'claims'              => $claims,
				),
			),
			array(
				'success'   => true,
				'handled'   => true,
				'retained'  => 0,
				'completed' => 0,
				'released'  => 0,
				'omitted'   => 0,
				'explicit'  => 0,
				'stale'     => false,
			),
			$recovery_generation,
			$recovery_claim_token
		);
		$result['transfer_id'] = $transfer_id;
		return $result;
	}

	/** Mark a prepared transfer adopted only after BatchItems and batch state are durable. */
	public static function adoptPreparedFanoutTransfer( int $job_id, string $transfer_id, int $recovery_generation = 0, string $recovery_claim_token = '' ): bool {
		return ! empty( self::mutateFanoutTransfer( $job_id, $transfer_id, 'adopt', $recovery_generation, $recovery_claim_token )['success'] );
	}

	/** Mark pipeline claim transfer adopted in the same CAS that adopts batch state. */
	public static function filterBatchAdoptionState( array $engine, string $context, string $worklist_checksum ): array {
		$transfer = is_array( $engine['packet_fanout_transfer'] ?? null ) ? $engine['packet_fanout_transfer'] : array();
		if ( 'pipeline' !== $context || 'prepared' !== (string) ( $transfer['state'] ?? '' ) ) {
			return $engine;
		}
		$engine['packet_fanout_transfer']['state']             = 'adopted';
		$engine['packet_fanout_transfer']['worklist_checksum'] = $worklist_checksum;
		$engine['packet_fanout_transfer']['adopted_at']        = gmdate( 'c' );
		return $engine;
	}

	/** Restore parent ownership when an adopted worklist cannot obtain an initial action. */
	public static function filterBatchAdoptionRollbackState( array $engine, string $context, string $worklist_checksum ): array {
		$transfer = is_array( $engine['packet_fanout_transfer'] ?? null ) ? $engine['packet_fanout_transfer'] : array();
		if ( 'pipeline' !== $context
			|| 'adopted' !== (string) ( $transfer['state'] ?? '' )
			|| ! hash_equals( $worklist_checksum, (string) ( $transfer['worklist_checksum'] ?? '' ) ) ) {
			return $engine;
		}
		$claims = is_array( $transfer['claims'] ?? null ) ? $transfer['claims'] : array();
		if ( empty( $claims ) ) {
			return $engine;
		}
		$current = ProcessedItems::disposition_claims( $engine );
		$current = array_replace( $current, $claims );
		unset( $engine[ ProcessedItems::CLAIM_METADATA_KEY ], $engine['packet_fanout_transfer'] );
		$engine[ ProcessedItems::CLAIMS_METADATA_KEY ] = array_values( $current );
		return $engine;
	}

	/** Restore prepared fanout claims only when no durable batch adopted them. */
	public static function restorePreparedFanoutTransfer( int $job_id, string $transfer_id, int $recovery_generation = 0, string $recovery_claim_token = '' ): bool {
		return ! empty( self::mutateFanoutTransfer( $job_id, $transfer_id, 'restore', $recovery_generation, $recovery_claim_token )['success'] );
	}

	/** Clear a transfer marker only after durable adoption has been established. */
	public static function finalizePreparedFanoutTransfer( int $job_id, string $transfer_id, int $recovery_generation = 0, string $recovery_claim_token = '' ): bool {
		return ! empty( self::mutateFanoutTransfer( $job_id, $transfer_id, 'finalize', $recovery_generation, $recovery_claim_token )['success'] );
	}

	/** Recover a stale marker from the current locked snapshot. */
	public static function recoverPreparedFanoutTransfer( int $job_id, int $recovery_generation = 0, string $recovery_claim_token = '' ): array {
		return self::mutateFanoutTransfer( $job_id, '', 'recover', $recovery_generation, $recovery_claim_token );
	}

	/** Transactionally fence fanout adoption, restoration, finalization, and recovery. */
	private static function mutateFanoutTransfer( int $job_id, string $transfer_id, string $action, int $recovery_generation, string $recovery_claim_token ): array {
		global $wpdb;
		$result     = array(
			'success'  => false,
			'stale'    => false,
			'adopted'  => false,
			'restored' => false,
			'handled'  => false,
		);
		$jobs       = new Jobs();
		$jobs_table = $jobs->get_table_name();
		$scope      = $job_id > 0 ? self::beginTransaction() : null;
		if ( null === $scope ) {
			return $result;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier and job ID are prepared.
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT status, engine_data FROM %i WHERE job_id = %d FOR UPDATE', $jobs_table, $job_id ), ARRAY_A );
		if ( ! is_array( $job ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}
		$encoded = $job['engine_data'] ?? null;
		$engine  = is_string( $encoded ) ? json_decode( $encoded, true ) : $encoded;
		$engine  = is_array( $engine ) ? $engine : array();
		if ( JobStatus::isStatusFinal( (string) ( $job['status'] ?? '' ) ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}
		if ( $recovery_generation > 0 && ! ChildJobRecoveryPolicy::recoveryExecutionMatches( $engine, $recovery_generation, $recovery_claim_token ) ) {
			self::rollbackTransaction( $scope );
			$result['stale'] = true;
			return $result;
		}

		$transfer   = is_array( $engine['packet_fanout_transfer'] ?? null ) ? $engine['packet_fanout_transfer'] : array();
		$current_id = (string) ( $transfer['transfer_id'] ?? '' );
		if ( '' === $current_id ) {
			self::rollbackTransaction( $scope );
			$result['success'] = 'recover' === $action;
			return $result;
		}
		if ( '' !== $transfer_id && ! hash_equals( $transfer_id, $current_id ) ) {
			self::rollbackTransaction( $scope );
			return $result;
		}
		$result['handled'] = true;
		$durably_adopted   = 'adopted' === (string) ( $transfer['state'] ?? '' ) || is_array( $engine['batch_state'] ?? null );
		$effective_action  = 'recover' === $action ? ( $durably_adopted ? 'finalize' : 'restore' ) : $action;

		if ( 'adopt' === $effective_action ) {
			if ( ! $durably_adopted ) {
				self::rollbackTransaction( $scope );
				return $result;
			}
			$engine['packet_fanout_transfer']['state']      = 'adopted';
			$engine['packet_fanout_transfer']['adopted_at'] = gmdate( 'c' );
			$result['adopted']                              = true;
		} elseif ( 'finalize' === $effective_action ) {
			if ( ! $durably_adopted ) {
				self::rollbackTransaction( $scope );
				return $result;
			}
			unset( $engine['packet_fanout_transfer'] );
			$result['adopted'] = true;
		} elseif ( 'restore' === $effective_action ) {
			if ( 'prepared' !== (string) ( $transfer['state'] ?? '' ) || $durably_adopted ) {
				self::rollbackTransaction( $scope );
				return $result;
			}
			$claims = is_array( $transfer['claims'] ?? null ) ? $transfer['claims'] : array();
			if ( empty( $claims ) ) {
				self::rollbackTransaction( $scope );
				return $result;
			}
			ksort( $claims, SORT_STRING );
			$processed = new ProcessedItems();
			foreach ( $claims as $claim ) {
				if ( ! is_array( $claim ) || ! $processed->renew_owned_claim_in_transaction( $claim, $job_id ) ) {
					self::rollbackTransaction( $scope );
					return $result;
				}
			}
			$current = ProcessedItems::disposition_claims( $engine );
			$current = array_replace( $current, $claims );
			unset( $engine[ ProcessedItems::CLAIM_METADATA_KEY ], $engine['packet_fanout_transfer'] );
			$engine[ ProcessedItems::CLAIMS_METADATA_KEY ] = array_values( $current );
			$result['restored']                            = true;
		} else {
			self::rollbackTransaction( $scope );
			return $result;
		}

		if ( ! $jobs->store_engine_data_in_transaction( $job_id, $engine ) || ! self::commitTransaction( $scope ) ) {
			self::rollbackTransaction( $scope );
			wp_cache_delete( $job_id, 'datamachine_engine_data' );
			return array_merge( $result, array( 'success' => false ) );
		}
		$jobs->publish_committed_engine_data( $job_id, $engine );
		$result['success'] = true;
		return $result;
	}

	/** Transaction commands require an uncached connection-level database boundary. */
	private static function beginTransaction(): ?TransactionScope {
		global $wpdb;
		return TransactionScope::begin( $wpdb );
	}

	/** Commit the reconciliation transaction at its connection-level boundary. */
	private static function commitTransaction( TransactionScope $scope ): bool {
		return $scope->commit();
	}

	/** Roll back the reconciliation transaction at its connection-level boundary. */
	private static function rollbackTransaction( TransactionScope $scope ): void {
		$scope->rollback();
	}

	/** Build bounded structured evidence containing safe identities only. */
	private static function claimEvidence( array $flow_step_config, array $retained, array $completed, array $released, array $omitted ): array {
		return array(
			'schema_version' => 'datamachine.packet_disposition.v1',
			'flow_step_id'   => (string) ( $flow_step_config['flow_step_id'] ?? $flow_step_config['step_id'] ?? '' ),
			'step_type'      => (string) ( $flow_step_config['step_type'] ?? '' ),
			'retained_ids'   => array_values( $retained ),
			'completed_ids'  => array_values( $completed ),
			'released_ids'   => array_values( $released ),
			'omitted_ids'    => array_values( $omitted ),
		);
	}

	/** Remove packet-scoped records as their claim identities leave the job. */
	private static function compactPacketRuntimeState( array $engine, array $active_ids ): array {
		$active = array_fill_keys( $active_ids, true );
		foreach ( array( 'packet_dispositions' ) as $key ) {
			$records        = is_array( $engine[ $key ] ?? null ) ? $engine[ $key ] : array();
			$engine[ $key ] = array_intersect_key( $records, $active );
			if ( empty( $engine[ $key ] ) ) {
				unset( $engine[ $key ] );
			}
		}
		foreach ( array( 'packet_tool_executions', 'successful_packet_tool_executions' ) as $key ) {
			$tools = is_array( $engine[ $key ] ?? null ) ? $engine[ $key ] : array();
			foreach ( $tools as $tool_name => $records ) {
				$records = is_array( $records ) ? array_intersect_key( $records, $active ) : array();
				if ( empty( $records ) ) {
					unset( $tools[ $tool_name ] );
				} else {
					$tools[ $tool_name ] = $records;
				}
			}
			if ( empty( $tools ) ) {
				unset( $engine[ $key ] );
			} else {
				$engine[ $key ] = $tools;
			}
		}
		return $engine;
	}

	/** Strip completed packet ownership and reservation state from terminal snapshots. */
	public static function filterTerminalEngineData( array $engine, int $job_id, string $status ): array {
		unset( $job_id, $status );
		unset(
			$engine[ ProcessedItems::CLAIM_METADATA_KEY ],
			$engine[ ProcessedItems::CLAIMS_METADATA_KEY ],
			$engine['packet_dispositions'],
			$engine['packet_tool_executions'],
			$engine['successful_packet_tool_executions'],
			$engine['packet_fanout_transfer']
		);
		return $engine;
	}

	/**
	 * Mark a completed job's source item as processed.
	 *
	 * @param int        $job_id      Completed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 * @param bool       $within_transaction Whether the caller owns the terminal transaction.
	 * @return bool Whether all owned descriptor and legacy claims completed.
	 */
	public static function handleCompleted( int $job_id, ?array $engine_data = null, bool $within_transaction = false ): bool {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		$completed   = 0;
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				if ( ! self::completeClaim( $claim, $job_id, $within_transaction ) ) {
					unset( self::$pending_processed_metrics[ $job_id ] );
					return false;
				}
				++$completed;
			}
		}

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
		}

		$source_flow_step_id = self::resolveSourceIngestionFlowStepId( $engine_data );
		if ( empty( $source_flow_step_id ) ) {
			return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
		}

		$legacy_completed = ( new ProcessedItems() )->complete_claim_for_job(
			$source_flow_step_id,
			(string) $source_type,
			(string) $item_identifier,
			$job_id
		);
		if ( false === $legacy_completed ) {
			unset( self::$pending_processed_metrics[ $job_id ] );
			return false;
		}
		$completed += $legacy_completed;

		return self::recordCompletedMetrics( $job_id, $completed, $within_transaction );
	}

	/**
	 * Release source-item claims when a job fails.
	 *
	 * @param int        $job_id      Failed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 * @return bool Whether every descriptor and legacy claim was released.
	 */
	public static function handleFailed( int $job_id, ?array $engine_data = null ): bool {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claims      = self::claimsFromEngine( $engine_data );
		$processed   = new ProcessedItems();
		if ( ! empty( $claims ) ) {
			foreach ( $claims as $claim ) {
				if ( false === self::releaseClaim( $claim, $processed ) ) {
					return false;
				}
			}
		}
		if ( ! empty( $engine_data['batch'] ) && BatchScheduler::STORAGE_VERSION === (int) ( $engine_data['batch_storage_version'] ?? 0 ) ) {
			return true;
		}

		// Pre-descriptor and partially migrated jobs still own claims by job_id.
		// Reacquisition replaces job_id, so this cannot release a newer worker's row.
		return false !== $processed->release_claims_for_job( $job_id );
	}

	/**
	 * Apply claim lifecycle for every terminal job transition.
	 *
	 * @param int    $job_id Terminal job ID.
	 * @param string $status Final job status.
	 */
	public static function handleTerminal( int $job_id, string $status ): void {
		unset( $status );
		unset( self::$pending_processed_metrics[ $job_id ] );
	}

	/** Clear request-local completion state after a database rollback. */
	public static function handleTerminalRollback( int $job_id ): void {
		unset( self::$pending_processed_metrics[ $job_id ] );
	}

	/** Persist transaction-derived accounting before the terminal commit. */
	public static function filterTerminalAccountingContext( array $context, int $job_id, string $status ): array {
		$completed = self::$pending_processed_metrics[ $job_id ] ?? 0;
		unset( self::$pending_processed_metrics[ $job_id ] );
		$context['processed_claim_count'] = JobStatus::isStatusSuccess( $status ) ? max( 0, $completed ) : 0;
		return $context;
	}

	/**
	 * Complete owned claims before a successful terminal status is persisted.
	 *
	 * @param string $status Requested terminal status.
	 * @param int    $job_id Job ID.
	 * @param array  $job    Current job row.
	 * @return string|\WP_Error Original status or a rollback signal.
	 */
	public static function filterTerminalStatus( string $status, int $job_id, array $job ): string|\WP_Error {
		$engine_data = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		if ( ProcessedItems::has_claim_metadata( $engine_data ) && ! ProcessedItems::has_valid_claim_metadata( $engine_data ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Terminal transition blocked by malformed packet claim metadata.',
				array(
					'job_id' => $job_id,
					'status' => $status,
				)
			);
			return new \WP_Error(
				'item_claim_metadata_invalid',
				'Explicit packet claim metadata is malformed.',
				array( 'status' => JobStatus::failed( 'item_claim_metadata_invalid' )->toString() )
			);
		}
		$prepared = JobStatus::isStatusSuccess( $status )
			? self::handleCompleted( $job_id, $engine_data, true )
			: self::handleFailed( $job_id, $engine_data );
		if ( $prepared ) {
			return $status;
		}

		$reason = JobStatus::isStatusSuccess( $status )
			? 'item_claim_completion_failed'
			: 'item_claim_release_failed';
		return new \WP_Error(
			$reason,
			'Item claim transition failed inside terminal ownership boundary.',
			array( 'status' => JobStatus::failed( $reason )->toString() )
		);
	}

	/**
	 * Release claims attached to batch items that will not be scheduled.
	 *
	 * @param array  $items         Discarded batch items.
	 * @param int    $parent_job_id Parent job ID.
	 * @param string $context       Batch consumer context.
	 * @param array  $cleanup_contexts Sidecar cleanup contexts captured before storage.
	 */
	public static function handleDiscardedPackets( array $items, int $parent_job_id, string $context, array $cleanup_contexts = array() ): void {
		unset( $parent_job_id, $context );
		foreach ( $items as $index => $item ) {
			$item   = DataPacketStore::hydrate_packet_collections_in_value( $item );
			$claims = array_merge(
				self::collectClaimsInValue( $item ),
				self::collectClaimsInValue( $cleanup_contexts[ $index ] ?? array() )
			);
			foreach ( $claims as $claim ) {
				self::releaseClaim( $claim );
			}
		}
	}

	/**
	 * Capture claim descriptors before batch content-addressing.
	 *
	 * @param array $context Existing cleanup context.
	 * @param mixed $item    Batch item before storage.
	 * @return array Cleanup context.
	 */
	public static function captureBatchItemCleanupContext( array $context, mixed $item ): array {
		$context[ ProcessedItems::CLAIMS_METADATA_KEY ] = self::collectClaimsInValue( $item );
		return $context;
	}

	/**
	 * Run an optional registered completion handler and transition the claim.
	 *
	 * @param array $claim  Validated claim descriptor.
	 * @param int   $job_id Completing job ID.
	 * @param bool  $within_transaction Whether the caller owns the terminal transaction.
	 * @return bool Whether the descriptor claim and its callback completed.
	 */
	private static function completeClaim( array $claim, int $job_id, bool $within_transaction = false ): bool {
		$processed  = new ProcessedItems();
		$completion = is_array( $claim['completion'] ?? null ) ? $claim['completion'] : array();
		$handler_id = is_string( $completion['handler'] ?? null ) ? $completion['handler'] : '';
		$payload    = is_array( $completion['payload'] ?? null ) ? $completion['payload'] : array();
		$callback   = null;

		if ( '' !== $handler_id ) {
			$handlers = apply_filters( 'datamachine_item_claim_completion_handlers', array() );
			$handler  = $handlers[ $handler_id ] ?? null;
			if ( ! is_callable( $handler ) ) {
				return false;
			}

			$callback = static fn(): bool => true === call_user_func( $handler, $payload, $job_id, $claim );
		}

		$retain_processed = true;
		if ( isset( $completion['retain_processed'] ) && false === $completion['retain_processed'] ) {
			$retain_processed = false;
		}
		$method = $within_transaction ? 'complete_owned_claim_in_transaction' : 'complete_owned_claim';
		$owned  = $processed->{$method}(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token'],
			$job_id,
			$callback,
			$retain_processed
		);
		if ( ! $owned ) {
			return false;
		}
		return true;
	}

	/**
	 * Persist metrics now or defer them until the outer transaction commits.
	 */
	private static function recordCompletedMetrics( int $job_id, int $completed, bool $within_transaction ): bool {
		if ( $within_transaction ) {
			self::$pending_processed_metrics[ $job_id ] = $completed;
		} elseif ( 0 < $completed ) {
			RunMetrics::increment( $job_id, 'processed', $completed );
		}

		return true;
	}

	/**
	 * Release one validated claim descriptor.
	 *
	 * @param array               $claim     Validated claim descriptor.
	 * @param ProcessedItems|null $processed Shared repository instance.
	 * @return int|false Number of released rows, or false on error.
	 */
	private static function releaseClaim( array $claim, ?ProcessedItems $processed = null ): int|false {
		$processed = $processed ?? new ProcessedItems();
		return $processed->release_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token']
		);
	}

	/**
	 * Read claim descriptors from engine data.
	 *
	 * @param array $engine_data Job engine snapshot.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function claimsFromEngine( array $engine_data ): array {
		return self::normalizeClaims( $engine_data );
	}

	/**
	 * Read singular and collection claim metadata from one container.
	 *
	 * @param array $container Engine data or packet metadata.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function normalizeClaims( array $container ): array {
		$claims = array();
		$single = self::normalizeClaim( $container[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null );
		if ( null !== $single ) {
			$claims[] = $single;
		}

		$collection = is_array( $container[ ProcessedItems::CLAIMS_METADATA_KEY ] ?? null )
			? $container[ ProcessedItems::CLAIMS_METADATA_KEY ]
			: array();
		foreach ( $collection as $candidate ) {
			$claim = self::normalizeClaim( $candidate );
			if ( null !== $claim ) {
				$claims[] = $claim;
			}
		}

		return self::uniqueClaims( $claims );
	}

	/**
	 * Deduplicate descriptors by ownership token.
	 *
	 * @param array<int,array<string,mixed>> $claims Claim descriptors.
	 * @return array<int,array<string,mixed>> Unique descriptors.
	 */
	private static function uniqueClaims( array $claims ): array {
		$unique = array();
		foreach ( $claims as $claim ) {
			$unique[ $claim['ownership_token'] ] = $claim;
		}
		return array_values( $unique );
	}

	/**
	 * Recursively collect claim metadata from packets or sidecar context.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<int,array<string,mixed>> Validated descriptors.
	 */
	private static function collectClaimsInValue( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$claims = self::normalizeClaims( $value );
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$claims = array_merge( $claims, self::collectClaimsInValue( $child ) );
			}
		}
		return self::uniqueClaims( $claims );
	}

	/**
	 * Validate a claim lifecycle descriptor.
	 *
	 * @param mixed $claim Candidate descriptor.
	 * @return array|null Validated descriptor, or null.
	 */
	private static function normalizeClaim( mixed $claim ): ?array {
		if ( ! is_array( $claim ) || false === ( $claim['persisted'] ?? true ) ) {
			return null;
		}

		foreach ( array( 'identity_scope', 'source_type', 'item_identifier', 'ownership_token' ) as $key ) {
			if ( ! is_string( $claim[ $key ] ?? null ) || '' === $claim[ $key ] ) {
				return null;
			}
		}
		$derived_id = ProcessedItems::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
		if ( isset( $claim['disposition_id'] ) && ( ! is_string( $claim['disposition_id'] ) || ! hash_equals( $derived_id, $claim['disposition_id'] ) ) ) {
			return null;
		}
		$claim['disposition_id'] = $derived_id;

		return $claim;
	}

	/**
	 * Resolve the source-ingestion step ID from a job engine snapshot.
	 *
	 * @param array $engine_data Job engine data.
	 * @return string|null Source ingestion flow step ID, or null when unavailable.
	 */
	public static function resolveSourceIngestionFlowStepId( array $engine_data ): ?string {
		$flow_config = $engine_data['flow_config'] ?? array();
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		foreach ( $flow_config as $step_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$step_type = $config['step_type'] ?? '';
			if ( self::isSourceIngestionStep( (string) $step_type ) ) {
				return (string) $step_id;
			}
		}

		return null;
	}

	/**
	 * Determine whether a step owns source-ingestion dedupe lifecycle behavior.
	 *
	 * @param string $step_type Step type.
	 * @return bool
	 */
	private static function isSourceIngestionStep( string $step_type ): bool {
		return in_array( $step_type, array( 'fetch', 'event_import' ), true );
	}
}
