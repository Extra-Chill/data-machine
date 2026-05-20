<?php
/**
 * Fetch Item Disposition Tool
 *
 * Handler tool that allows the pipeline agent to explicitly reject or defer
 * a fetched source item. Rejections mark the source as processed so it will
 * not be refetched; deferrals release the source claim so the item remains
 * eligible for a later retry.
 *
 * This provides a safety net when keyword exclusions or other filters
 * miss items that shouldn't be processed (e.g., non-music events).
 *
 * @package DataMachine\Core\Steps\Fetch\Tools
 * @since 0.9.7
 */

namespace DataMachine\Core\Steps\Fetch\Tools;

use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;

defined( 'ABSPATH' ) || exit;

class FetchItemDispositionTool {

	private const DISPOSITION_REJECT_SOURCE = 'reject_source';
	private const DISPOSITION_DEFER_ITEM    = 'defer_item';

	/**
	 * Handle the reject_source/defer_item tool call.
	 *
	 * @param array $parameters Tool parameters from AI (reason required).
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$disposition = $this->getDisposition( $tool_def );
		if ( self::DISPOSITION_DEFER_ITEM === $disposition ) {
			return $this->deferItem( $parameters, $tool_def );
		}

		return $this->rejectSource( $parameters, $tool_def );
	}

	/**
	 * Mark the current source item as rejected/processed.
	 *
	 * @param array $parameters Tool parameters from AI.
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	private function rejectSource( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		$reason    = trim( $parameters['reason'] ?? '' );
		$tool_name = self::DISPOSITION_REJECT_SOURCE;

		if ( empty( $reason ) ) {
			return array(
				'success'   => false,
				'error'     => 'reason parameter is required - explain why this source is being rejected',
				'tool_name' => $tool_name,
			);
		}

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( ! $job_id ) {
			return array(
				'success'   => false,
				'error'     => 'job_id is required for reject_source operations',
				'tool_name' => $tool_name,
			);
		}

		// Get engine data for item identification
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine ) {
			return array(
				'success'   => false,
				'error'     => 'Engine context not available',
				'tool_name' => $tool_name,
			);
		}

		// Get item identifier and source type from engine data (set by fetch handler)
		$item_identifier = $engine->get( 'item_identifier' );
		$source_type     = $engine->get( 'source_type' );
		$flow_step_id    = $this->resolveFetchFlowStepId( $engine ) ?? ( $parameters['flow_step_id'] ?? $engine->get( 'flow_step_id' ) );

		// Mark item as processed so it won't be refetched
		if ( $flow_step_id && $item_identifier && $source_type ) {
			do_action(
				'datamachine_mark_item_processed',
				$flow_step_id,
				$source_type,
				$item_identifier,
				$job_id
			);

			do_action(
				'datamachine_log',
				'info',
				'FetchItemDispositionTool: Source rejected and marked as processed',
				array(
					'job_id'          => $job_id,
					'flow_step_id'    => $flow_step_id,
					'item_identifier' => $item_identifier,
					'source_type'     => $source_type,
					'reason'          => $reason,
				)
			);
		} else {
			do_action(
				'datamachine_log',
				'warning',
				'FetchItemDispositionTool: Could not mark rejected source as processed - missing identifiers',
				array(
					'job_id'          => $job_id,
					'flow_step_id'    => $flow_step_id,
					'item_identifier' => $item_identifier,
					'source_type'     => $source_type,
					'reason'          => $reason,
				)
			);
		}

		// Set job status override for engine to use at completion
		$status = JobStatus::agentSkipped( 'source-rejected' );
		datamachine_merge_engine_data(
			$job_id,
			array(
				'job_status'       => $status->toString(),
				'source_rejection' => array(
					'reason'          => $reason,
					'flow_step_id'    => $flow_step_id,
					'item_identifier' => $item_identifier,
					'source_type'     => $source_type,
				),
			)
		);

		if ( $flow_step_id && class_exists( RunMetrics::class ) ) {
			RunMetrics::recordStepResult(
				$job_id,
				(string) $flow_step_id,
				array(
					'step_type'               => 'fetch',
					'result'                  => 'source_rejected',
					'packet_count'            => 0,
					'source_rejection_reason' => $reason,
					'source_type'             => $source_type,
					'item_identifier'         => $item_identifier,
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'FetchItemDispositionTool: Job status set to source rejected',
			array(
				'job_id' => $job_id,
				'status' => $status->toString(),
				'reason' => $reason,
			)
		);

		return array(
			'success'         => true,
			'message'         => "Source rejected: {$reason}",
			'status'          => $status->toString(),
			'item_identifier' => $item_identifier,
			'tool_name'       => $tool_name,
			'disposition'     => self::DISPOSITION_REJECT_SOURCE,
			'reason'          => $reason,
		);
	}

	/**
	 * Release the current source item claim without marking it processed.
	 *
	 * @param array $parameters Tool parameters from AI.
	 * @param array $tool_def Tool definition with handler_config.
	 * @return array Tool result with success status.
	 */
	private function deferItem( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		$reason    = trim( $parameters['reason'] ?? '' );
		$tool_name = self::DISPOSITION_DEFER_ITEM;

		if ( empty( $reason ) ) {
			return array(
				'success'   => false,
				'error'     => 'reason parameter is required - explain why this item cannot be safely completed now',
				'tool_name' => $tool_name,
			);
		}

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( ! $job_id ) {
			return array(
				'success'   => false,
				'error'     => 'job_id is required for defer_item operations',
				'tool_name' => $tool_name,
			);
		}

		$engine = $parameters['engine'] ?? null;
		if ( ! $engine ) {
			return array(
				'success'   => false,
				'error'     => 'Engine context not available',
				'tool_name' => $tool_name,
			);
		}

		$item_identifier = $engine->get( 'item_identifier' );
		$source_type     = $engine->get( 'source_type' );
		$flow_step_id    = $this->resolveFetchFlowStepId( $engine ) ?? ( $parameters['flow_step_id'] ?? $engine->get( 'flow_step_id' ) );
		$released        = null;

		if ( $flow_step_id && $item_identifier && $source_type ) {
			$released = ( new \DataMachine\Core\Database\ProcessedItems\ProcessedItems() )->release_claim( $flow_step_id, (string) $source_type, (string) $item_identifier );
		}

		$status = JobStatus::failed( 'item-deferred' );
		datamachine_merge_engine_data( $job_id, array( 'job_status' => $status->toString() ) );

		do_action(
			'datamachine_log',
			'info',
			'FetchItemDispositionTool: Item deferred and source claim released',
			array(
				'job_id'          => $job_id,
				'flow_step_id'    => $flow_step_id,
				'item_identifier' => $item_identifier,
				'source_type'     => $source_type,
				'reason'          => $reason,
				'released'        => $released,
				'status'          => $status->toString(),
			)
		);

		return array(
			'success'         => true,
			'message'         => "Item deferred for retry: {$reason}",
			'status'          => $status->toString(),
			'item_identifier' => $item_identifier,
			'tool_name'       => $tool_name,
			'disposition'     => self::DISPOSITION_DEFER_ITEM,
			'reason'          => $reason,
			'released'        => $released,
		);
	}

	/**
	 * Return the disposition encoded by the resolved tool definition.
	 *
	 * @param array $tool_def Tool definition.
	 * @return string
	 */
	private function getDisposition( array $tool_def ): string {
		$disposition = $tool_def['disposition'] ?? self::DISPOSITION_REJECT_SOURCE;

		return self::DISPOSITION_DEFER_ITEM === $disposition ? self::DISPOSITION_DEFER_ITEM : self::DISPOSITION_REJECT_SOURCE;
	}

	/**
	 * Resolve the source fetch/event_import step ID from engine flow config.
	 *
	 * @param object $engine Engine data wrapper.
	 * @return string|null Fetch step ID, or null when unavailable.
	 */
	private function resolveFetchFlowStepId( object $engine ): ?string {
		$flow_config = $engine->get( 'flow_config' );
		if ( ! is_array( $flow_config ) ) {
			return null;
		}

		foreach ( $flow_config as $step_id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( in_array( $config['step_type'] ?? '', array( 'fetch', 'event_import' ), true ) ) {
				return (string) $step_id;
			}
		}

		return null;
	}
}
