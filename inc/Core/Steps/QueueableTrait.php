<?php
/**
 * Trait for steps that can consume prompts/tasks from the flow queue.
 *
 * Provides shared queue pop functionality that can be used by any step type
 * that needs to pull work items from the prompt queue.
 *
 * @package DataMachine\Core\Steps
 * @since 0.19.0
 */

namespace DataMachine\Core\Steps;

use DataMachine\Abilities\Flow\QueueAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queueable trait for steps that consume from prompt queue.
 *
 * Usage:
 *   class MyStep extends Step {
 *       use QueueableTrait;
 *
 *       protected function executeStep(): array {
 *           $task = $this->popFromQueueIfEmpty( $this->getConfigValue( 'prompt' ) );
 *           // Use $task...
 *       }
 *   }
 */
trait QueueableTrait {

	/**
	 * Pop from queue if the provided value is empty.
	 *
	 * @param string $current_value The current value (e.g., user_message or prompt).
	 * @return array{value: string, from_queue: bool, added_at: string|null} Result with value and source info.
	 */
	protected function popFromQueueIfEmpty( string $current_value ): array {
		// If we already have a value, use it
		if ( ! empty( $current_value ) ) {
			return array(
				'value'      => $current_value,
				'from_queue' => false,
				'added_at'   => null,
			);
		}

		// Try to pop from queue
		$job_context = $this->engine->getJobContext();
		$flow_id     = $job_context['flow_id'] ?? null;

		if ( ! $flow_id ) {
			return array(
				'value'      => '',
				'from_queue' => false,
				'added_at'   => null,
			);
		}

		$queued_item = QueueAbility::popFromQueue( (int) $flow_id );

		if ( $queued_item && ! empty( $queued_item['prompt'] ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Using prompt from queue',
				array(
					'flow_id'   => $flow_id,
					'step_type' => $this->step_type ?? 'unknown',
					'added_at'  => $queued_item['added_at'] ?? '',
				)
			);

			return array(
				'value'      => $queued_item['prompt'],
				'from_queue' => true,
				'added_at'   => $queued_item['added_at'] ?? null,
			);
		}

		return array(
			'value'      => '',
			'from_queue' => false,
			'added_at'   => null,
		);
	}
}
