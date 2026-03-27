<?php
/**
 * Skip Item Tool
 *
 * Handler tool that allows the pipeline agent to explicitly skip an item
 * that doesn't meet processing criteria. Marks the item as processed
 * (so it won't be refetched) and sets the job status to agent_skipped.
 *
 * This provides a safety net when keyword exclusions or other filters
 * miss items that shouldn't be processed (e.g., non-music events).
 *
 * @package DataMachine\Core\Steps\Fetch\Tools
 * @since 0.9.7
 */

namespace DataMachine\Core\Steps\Fetch\Tools;

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class SkipItemTool {

	/**
	 * Handle the skip_item tool call.
	 *
	 * Marks the current item as processed and sets the job status
	 * to agent_skipped with the provided reason.
	 *
	 * @param array $parameters Tool parameters from AI (reason required)
	 * @param array $tool_def Tool definition with handler_config
	 * @return array Tool result with success status
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$reason = trim( $parameters['reason'] ?? '' );

		if ( empty( $reason ) ) {
			return array(
				'success'   => false,
				'error'     => 'reason parameter is required - explain why this item is being skipped',
				'tool_name' => 'skip_item',
			);
		}

		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( ! $job_id ) {
			return array(
				'success'   => false,
				'error'     => 'job_id is required for skip_item operations',
				'tool_name' => 'skip_item',
			);
		}

		// Get engine data for item identification
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine ) {
			return array(
				'success'   => false,
				'error'     => 'Engine context not available',
				'tool_name' => 'skip_item',
			);
		}

		// Get item identifier and source type from engine data (set by fetch handler)
		$item_identifier = $engine->get( 'item_identifier' );
		$source_type     = $engine->get( 'source_type' );
		$flow_step_id    = $parameters['flow_step_id'] ?? $engine->get( 'flow_step_id' );

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
				'SkipItemTool: Item marked as processed (skipped)',
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
				'SkipItemTool: Could not mark item as processed - missing identifiers',
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
		$status = JobStatus::agentSkipped( $reason );
		datamachine_merge_engine_data( $job_id, array( 'job_status' => $status->toString() ) );

		do_action(
			'datamachine_log',
			'info',
			'SkipItemTool: Job status set to agent_skipped',
			array(
				'job_id' => $job_id,
				'status' => $status->toString(),
				'reason' => $reason,
			)
		);

		return array(
			'success'         => true,
			'message'         => "Item skipped: {$reason}",
			'status'          => $status->toString(),
			'item_identifier' => $item_identifier,
			'tool_name'       => 'skip_item',
		);
	}
}
