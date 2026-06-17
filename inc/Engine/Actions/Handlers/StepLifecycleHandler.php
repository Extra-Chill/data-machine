<?php
/**
 * Default handlers for generic step lifecycle hooks.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.146.2
 */

namespace DataMachine\Engine\Actions\Handlers;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

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
		$seed_data   = array();
		if ( ! empty( $packet_meta['item_identifier'] ) ) {
			$seed_data['item_identifier'] = $packet_meta['item_identifier'];
		}
		if ( ! empty( $packet_meta['source_type'] ) ) {
			$seed_data['source_type'] = $packet_meta['source_type'];
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
		$engine_data        = is_array( $engine_data ) ? $engine_data : \datamachine_get_engine_data( $job_id );
		$db_processed_items = new ProcessedItems();

		$db_processed_items->release_claims_for_job( $job_id );

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;
		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return;
		}

		$source_flow_step_id = self::resolveSourceIngestionFlowStepId( $engine_data );
		if ( empty( $source_flow_step_id ) ) {
			return;
		}

		$db_processed_items->release_claim( $source_flow_step_id, (string) $source_type, (string) $item_identifier );
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
