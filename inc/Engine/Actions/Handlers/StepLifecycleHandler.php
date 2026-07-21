<?php
/**
 * Default handlers for generic step lifecycle hooks.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.146.2
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PacketEngineData;
use DataMachine\Core\RunMetrics;

/**
 * Keeps source-ingestion processed-item behavior behind lifecycle hooks.
 */
class StepLifecycleHandler {

	/**
	 * Seed lifecycle context before an inline continuation.
	 *
	 * @param int   $job_id           Job ID.
	 * @param array $flow_step_config Current flow step configuration.
	 * @param array $routed_packets   Packets routed to the next step.
	 */
	public static function handleInlineContinuation( int $job_id, array $flow_step_config, array $routed_packets ): void {
		$step_type = $flow_step_config['step_type'] ?? '';
		if ( ! self::isSourceIngestionStep( (string) $step_type ) || empty( $routed_packets ) ) {
			return;
		}

		$packet_meta = $routed_packets[0]['metadata'] ?? array();
		$seed_data   = is_array( $packet_meta['_engine_data'] ?? null )
			? PacketEngineData::sanitize( $packet_meta['_engine_data'], $job_id )
			: array();
		if ( ! empty( $packet_meta['item_identifier'] ) ) {
			$seed_data['item_identifier'] = $packet_meta['item_identifier'];
		}
		if ( ! empty( $packet_meta['source_type'] ) ) {
			$seed_data['source_type'] = $packet_meta['source_type'];
		}
		if ( is_array( $packet_meta[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null ) ) {
			$seed_data[ ProcessedItems::CLAIM_METADATA_KEY ] = $packet_meta[ ProcessedItems::CLAIM_METADATA_KEY ];
		}
		if ( ! empty( $seed_data ) ) {
			\datamachine_merge_engine_data( $job_id, $seed_data );
		}
	}

	/**
	 * Mark a completed job's source item as processed.
	 *
	 * @param int        $job_id      Completed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 */
	public static function handleCompleted( int $job_id, ?array $engine_data = null ): void {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claim       = self::claimFromEngine( $engine_data );
		if ( null !== $claim ) {
			self::completeClaim( $claim, $job_id );
			return;
		}

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return;
		}

		$source_flow_step_id = self::resolveSourceIngestionFlowStepId( $engine_data );
		if ( empty( $source_flow_step_id ) ) {
			return;
		}

		\do_action(
			'datamachine_mark_item_processed',
			$source_flow_step_id,
			$source_type,
			$item_identifier,
			$job_id
		);

		\do_action(
			'datamachine_log',
			'debug',
			'Deferred mark-as-processed on pipeline completion',
			array(
				'job_id'             => $job_id,
				'item_identifier'    => $item_identifier,
				'source_type'        => $source_type,
				'fetch_flow_step_id' => $source_flow_step_id,
			)
		);
	}

	/**
	 * Release source-item claims when a job fails.
	 *
	 * @param int        $job_id      Failed job ID.
	 * @param array|null $engine_data Optional engine data snapshot.
	 */
	public static function handleFailed( int $job_id, ?array $engine_data = null ): void {
		$engine_data = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$claim       = self::claimFromEngine( $engine_data );
		if ( null !== $claim ) {
			self::releaseClaim( $claim );
		}
	}

	/**
	 * Apply claim lifecycle for every terminal job transition.
	 *
	 * @param int    $job_id Terminal job ID.
	 * @param string $status Final job status.
	 */
	public static function handleTerminal( int $job_id, string $status ): void {
		$engine_data = \datamachine_get_engine_data( $job_id );
		if ( JobStatus::isStatusSuccess( $status ) ) {
			\do_action( 'datamachine_step_lifecycle_completed', $job_id, $engine_data );
			return;
		}

		\do_action( 'datamachine_step_lifecycle_failed', $job_id, $engine_data );
	}

	/**
	 * Release claims attached to batch items that will not be scheduled.
	 *
	 * @param array  $items         Discarded batch items.
	 * @param int    $parent_job_id Parent job ID.
	 * @param string $context       Batch consumer context.
	 */
	public static function handleDiscardedPackets( array $items, int $parent_job_id, string $context ): void {
		unset( $parent_job_id, $context );
		foreach ( $items as $item ) {
			$item     = DataPacketStore::hydrate_packet_collections_in_value( $item );
			$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
			$claim    = self::normalizeClaim( $metadata[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null );
			if ( null !== $claim ) {
				self::releaseClaim( $claim );
			}
		}
	}

	/**
	 * Apply optional tracked-item state and complete an owned claim.
	 *
	 * TrackedItems validates ownership in the same SQL statement as its upsert.
	 * A stale worker therefore cannot update a replacement owner's revision.
	 *
	 * @param array $claim  Validated claim descriptor.
	 * @param int   $job_id Completing job ID.
	 */
	private static function completeClaim( array $claim, int $job_id ): void {
		$processed    = new ProcessedItems();
		$completion   = is_array( $claim['completion'] ?? null ) ? $claim['completion'] : array();
		$tracked_item = is_array( $completion['tracked_item'] ?? null ) ? $completion['tracked_item'] : null;
		if ( null !== $tracked_item ) {
			$tracked_item['last_job_id'] = $job_id;
			if ( null === ( new TrackedItems() )->upsert_owned( $tracked_item, $claim ) ) {
				$processed->release_owned_claim( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'], $claim['ownership_token'] );
				return;
			}
		}

		$owned = $processed->complete_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token'],
			$job_id
		);
		if ( ! $owned ) {
			return;
		}
		RunMetrics::increment( $job_id, 'processed' );

		if ( isset( $completion['keep_processed'] ) && false === $completion['keep_processed'] ) {
			$processed->release_owned_claim( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'], $claim['ownership_token'], true );
		}
	}

	/**
	 * Release one validated claim descriptor.
	 *
	 * @param array $claim Validated claim descriptor.
	 */
	private static function releaseClaim( array $claim ): void {
		( new ProcessedItems() )->release_owned_claim(
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['ownership_token']
		);
	}

	/**
	 * Read a claim descriptor from engine data.
	 *
	 * @param array $engine_data Job engine snapshot.
	 * @return array|null Validated descriptor, or null.
	 */
	private static function claimFromEngine( array $engine_data ): ?array {
		return self::normalizeClaim( $engine_data[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null );
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
