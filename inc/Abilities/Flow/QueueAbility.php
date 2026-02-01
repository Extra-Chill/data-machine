<?php
/**
 * Queue Ability
 *
 * Manages prompt queues for flows. Prompts are stored in flow_config
 * and processed sequentially by AI steps.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.16.0
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Core\Database\Flows\Flows as DB_Flows;

defined( 'ABSPATH' ) || exit;

class QueueAbility {

	use FlowHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbilities();
	}

	/**
	 * Register all queue-related abilities.
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerQueueAdd();
			$this->registerQueueList();
			$this->registerQueueClear();
			$this->registerQueueRemove();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register queue-add ability.
	 */
	private function registerQueueAdd(): void {
		wp_register_ability(
			'datamachine/queue-add',
			array(
				'label'               => __( 'Add to Queue', 'data-machine' ),
				'description'         => __( 'Add a prompt to the flow queue.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'prompt' ),
					'properties' => array(
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to add prompt to', 'data-machine' ),
						),
						'prompt'  => array(
							'type'        => 'string',
							'description' => __( 'Prompt text to queue', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'flow_id'      => array( 'type' => 'integer' ),
						'queue_length' => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueAdd' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue-list ability.
	 */
	private function registerQueueList(): void {
		wp_register_ability(
			'datamachine/queue-list',
			array(
				'label'               => __( 'List Queue', 'data-machine' ),
				'description'         => __( 'List all prompts in the flow queue.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id' ),
					'properties' => array(
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to list queue for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'flow_id' => array( 'type' => 'integer' ),
						'queue'   => array( 'type' => 'array' ),
						'count'   => array( 'type' => 'integer' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueList' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue-clear ability.
	 */
	private function registerQueueClear(): void {
		wp_register_ability(
			'datamachine/queue-clear',
			array(
				'label'               => __( 'Clear Queue', 'data-machine' ),
				'description'         => __( 'Clear all prompts from the flow queue.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id' ),
					'properties' => array(
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to clear queue for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'flow_id'         => array( 'type' => 'integer' ),
						'cleared_count'   => array( 'type' => 'integer' ),
						'message'         => array( 'type' => 'string' ),
						'error'           => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueClear' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue-remove ability.
	 */
	private function registerQueueRemove(): void {
		wp_register_ability(
			'datamachine/queue-remove',
			array(
				'label'               => __( 'Remove from Queue', 'data-machine' ),
				'description'         => __( 'Remove a specific prompt from the queue by index.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'index' ),
					'properties' => array(
						'flow_id' => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'index'   => array(
							'type'        => 'integer',
							'description' => __( 'Queue index to remove (0-based)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'flow_id'        => array( 'type' => 'integer' ),
						'removed_prompt' => array( 'type' => 'string' ),
						'queue_length'   => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
						'error'          => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueRemove' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Add a prompt to the flow queue.
	 *
	 * @param array $input Input with flow_id and prompt.
	 * @return array Result.
	 */
	public function executeQueueAdd( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;
		$prompt  = $input['prompt'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $prompt ) || ! is_string( $prompt ) ) {
			return array(
				'success' => false,
				'error'   => 'prompt is required and must be a non-empty string',
			);
		}

		$flow_id = (int) $flow_id;
		$prompt  = sanitize_textarea_field( wp_unslash( $prompt ) );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$flow_config   = $flow['flow_config'] ?? array();
		$prompt_queue  = $flow_config['prompt_queue'] ?? array();

		$prompt_queue[] = array(
			'prompt'   => $prompt,
			'added_at' => gmdate( 'c' ),
		);

		$flow_config['prompt_queue'] = $prompt_queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Prompt added to queue',
			array(
				'flow_id'      => $flow_id,
				'queue_length' => count( $prompt_queue ),
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'queue_length' => count( $prompt_queue ),
			'message'      => sprintf( 'Prompt added to queue. Queue now has %d item(s).', count( $prompt_queue ) ),
		);
	}

	/**
	 * List all prompts in the flow queue.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result with queue items.
	 */
	public function executeQueueList( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		$flow_id = (int) $flow_id;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$flow_config  = $flow['flow_config'] ?? array();
		$prompt_queue = $flow_config['prompt_queue'] ?? array();

		return array(
			'success' => true,
			'flow_id' => $flow_id,
			'queue'   => $prompt_queue,
			'count'   => count( $prompt_queue ),
		);
	}

	/**
	 * Clear all prompts from the flow queue.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result.
	 */
	public function executeQueueClear( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		$flow_id = (int) $flow_id;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$flow_config   = $flow['flow_config'] ?? array();
		$cleared_count = count( $flow_config['prompt_queue'] ?? array() );

		$flow_config['prompt_queue'] = array();

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to clear queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Queue cleared',
			array(
				'flow_id'       => $flow_id,
				'cleared_count' => $cleared_count,
			)
		);

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'cleared_count' => $cleared_count,
			'message'       => sprintf( 'Cleared %d prompt(s) from queue.', $cleared_count ),
		);
	}

	/**
	 * Remove a specific prompt from the queue by index.
	 *
	 * @param array $input Input with flow_id and index.
	 * @return array Result.
	 */
	public function executeQueueRemove( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;
		$index   = $input['index'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'index is required and must be a non-negative integer',
			);
		}

		$flow_id = (int) $flow_id;
		$index   = (int) $index;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$flow_config  = $flow['flow_config'] ?? array();
		$prompt_queue = $flow_config['prompt_queue'] ?? array();

		if ( $index >= count( $prompt_queue ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Index %d is out of range. Queue has %d item(s).', $index, count( $prompt_queue ) ),
			);
		}

		$removed_item   = $prompt_queue[ $index ];
		$removed_prompt = $removed_item['prompt'] ?? '';

		array_splice( $prompt_queue, $index, 1 );

		$flow_config['prompt_queue'] = $prompt_queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to remove prompt from queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Prompt removed from queue',
			array(
				'flow_id'      => $flow_id,
				'index'        => $index,
				'queue_length' => count( $prompt_queue ),
			)
		);

		return array(
			'success'        => true,
			'flow_id'        => $flow_id,
			'removed_prompt' => $removed_prompt,
			'queue_length'   => count( $prompt_queue ),
			'message'        => sprintf( 'Removed prompt at index %d. Queue now has %d item(s).', $index, count( $prompt_queue ) ),
		);
	}

	/**
	 * Pop the first prompt from the queue (for engine use).
	 *
	 * @param int      $flow_id  Flow ID.
	 * @param DB_Flows $db_flows Database instance (avoids creating new instance each call).
	 * @return array|null The popped queue item or null if empty.
	 */
	public static function popFromQueue( int $flow_id, ?DB_Flows $db_flows = null ): ?array {
		if ( null === $db_flows ) {
			$db_flows = new DB_Flows();
		}

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return null;
		}

		$flow_config  = $flow['flow_config'] ?? array();
		$prompt_queue = $flow_config['prompt_queue'] ?? array();

		if ( empty( $prompt_queue ) ) {
			return null;
		}

		$popped_item = array_shift( $prompt_queue );

		$flow_config['prompt_queue'] = $prompt_queue;

		$db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		do_action(
			'datamachine_log',
			'info',
			'Prompt popped from queue',
			array(
				'flow_id'          => $flow_id,
				'remaining_count'  => count( $prompt_queue ),
			)
		);

		return $popped_item;
	}
}
