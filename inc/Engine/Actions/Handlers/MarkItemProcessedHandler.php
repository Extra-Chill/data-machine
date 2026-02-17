<?php
/**
 * Handler for the datamachine_mark_item_processed action.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

/**
 * Marks a source item as processed for a given flow step and job.
 */
class MarkItemProcessedHandler {

	/**
	 * Handle the mark-item-processed action.
	 *
	 * @param string $flow_step_id    Flow step identifier.
	 * @param string $source_type     Source type slug.
	 * @param string $item_identifier Unique item identifier.
	 * @param int    $job_id          Associated job ID.
	 * @return bool|null True on success, null on validation failure.
	 */
	public static function handle( $flow_step_id, $source_type, $item_identifier, $job_id ) {
		$job_id = (int) $job_id;

		if ( ! isset( $flow_step_id ) || ! isset( $source_type ) || ! isset( $item_identifier ) ) {
			do_action(
				'datamachine_log',
				'error',
				'datamachine_mark_item_processed called with missing required parameters',
				array(
					'flow_step_id'       => $flow_step_id,
					'source_type'        => $source_type,
					'item_identifier'    => substr( $item_identifier ?? '', 0, 50 ) . '...',
					'job_id'             => $job_id,
					'parameter_provided' => func_num_args() >= 4,
				)
			);
			return;
		}

		if ( empty( $job_id ) || ! is_numeric( $job_id ) || $job_id <= 0 ) {
			do_action(
				'datamachine_log',
				'error',
				'datamachine_mark_item_processed called without valid job_id',
				array(
					'flow_step_id'       => $flow_step_id,
					'source_type'        => $source_type,
					'item_identifier'    => substr( $item_identifier, 0, 50 ) . '...',
					'job_id'             => $job_id,
					'job_id_type'        => gettype( $job_id ),
					'parameter_provided' => func_num_args() >= 4,
				)
			);
			return;
		}

		$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();

		return $db_processed_items->add_processed_item( $flow_step_id, $source_type, $item_identifier, $job_id );
	}
}
