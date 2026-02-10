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
			$this->registerQueueUpdate();
			$this->registerQueueMove();
			$this->registerQueueSettings();
			$this->registerQueueValidate();
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
					'required'   => array( 'flow_id', 'flow_step_id', 'prompt' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to add prompt to', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID to add prompt to', 'data-machine' ),
						),
						'prompt'       => array(
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
						'flow_step_id' => array( 'type' => 'string' ),
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
					'required'   => array( 'flow_id', 'flow_step_id' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to list queue for', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID to list queue for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'flow_id'       => array( 'type' => 'integer' ),
						'flow_step_id'  => array( 'type' => 'string' ),
						'queue'         => array( 'type' => 'array' ),
						'count'         => array( 'type' => 'integer' ),
						'queue_enabled' => array( 'type' => 'boolean' ),
						'error'         => array( 'type' => 'string' ),
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
					'required'   => array( 'flow_id', 'flow_step_id' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to clear queue for', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID to clear queue for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'flow_id'       => array( 'type' => 'integer' ),
						'flow_step_id'  => array( 'type' => 'string' ),
						'cleared_count' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
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
					'required'   => array( 'flow_id', 'flow_step_id', 'index' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
						'index'        => array(
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
	 * Register queue-update ability.
	 */
	private function registerQueueUpdate(): void {
		wp_register_ability(
			'datamachine/queue-update',
			array(
				'label'               => __( 'Update Queue Item', 'data-machine' ),
				'description'         => __( 'Update a prompt at a specific index in the flow queue.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'index', 'prompt' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
						'index'        => array(
							'type'        => 'integer',
							'description' => __( 'Queue index to update (0-based)', 'data-machine' ),
						),
						'prompt'       => array(
							'type'        => 'string',
							'description' => __( 'New prompt text', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'flow_id'      => array( 'type' => 'integer' ),
						'flow_step_id' => array( 'type' => 'string' ),
						'index'        => array( 'type' => 'integer' ),
						'queue_length' => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueUpdate' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue-move ability.
	 */
	private function registerQueueMove(): void {
		wp_register_ability(
			'datamachine/queue-move',
			array(
				'label'               => __( 'Move Queue Item', 'data-machine' ),
				'description'         => __( 'Move a prompt from one position to another in the queue.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'from_index', 'to_index' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
						'from_index'   => array(
							'type'        => 'integer',
							'description' => __( 'Current index of item to move (0-based)', 'data-machine' ),
						),
						'to_index'     => array(
							'type'        => 'integer',
							'description' => __( 'Target index to move item to (0-based)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'flow_id'      => array( 'type' => 'integer' ),
						'flow_step_id' => array( 'type' => 'string' ),
						'from_index'   => array( 'type' => 'integer' ),
						'to_index'     => array( 'type' => 'integer' ),
						'queue_length' => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueMove' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue settings ability.
	 */
	private function registerQueueSettings(): void {
		wp_register_ability(
			'datamachine/queue-settings',
			array(
				'label'               => __( 'Update Queue Settings', 'data-machine' ),
				'description'         => __( 'Update queue settings for a flow step.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'queue_enabled' ),
					'properties' => array(
						'flow_id'       => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id'  => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
						),
						'queue_enabled' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether queue pop is enabled for this step', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'flow_id'       => array( 'type' => 'integer' ),
						'flow_step_id'  => array( 'type' => 'string' ),
						'queue_enabled' => array( 'type' => 'boolean' ),
						'message'       => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueSettings' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register queue-validate ability.
	 */
	private function registerQueueValidate(): void {
		wp_register_ability(
			'datamachine/queue-validate',
			array(
				'label'               => __( 'Validate Queue', 'data-machine' ),
				'description'         => __( 'Check queue items against existing posts and remove duplicates.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to validate queue for', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID to validate queue for', 'data-machine' ),
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'description' => __( 'If true, report duplicates without removing them', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'flow_id'            => array( 'type' => 'integer' ),
						'flow_step_id'       => array( 'type' => 'string' ),
						'total_checked'      => array( 'type' => 'integer' ),
						'duplicates_found'   => array( 'type' => 'integer' ),
						'duplicates_removed' => array( 'type' => 'integer' ),
						'duplicates'         => array( 'type' => 'array' ),
						'message'            => array( 'type' => 'string' ),
						'error'              => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQueueValidate' ),
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
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$prompt       = $input['prompt'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		if ( empty( $prompt ) || ! is_string( $prompt ) ) {
			return array(
				'success' => false,
				'error'   => 'prompt is required and must be a non-empty string',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );
		$prompt       = sanitize_textarea_field( wp_unslash( $prompt ) );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config  = $validation['flow_config'];
		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];

		// Check for duplicates in existing queue.
		foreach ( $prompt_queue as $queued_item ) {
			if ( strcasecmp( $queued_item['prompt'], $prompt ) === 0 ) {
				return array(
					'success' => false,
					'error'   => 'Duplicate: This prompt is already in the queue.',
				);
			}
		}

		// Check for similar existing posts.
		$existing_post = $this->findSimilarExistingPost( $prompt );
		if ( $existing_post ) {
			return array(
				'success'       => false,
				'error'         => sprintf( 'Duplicate: Similar post already exists: "%s" (ID %d)', $existing_post['title'], $existing_post['id'] ),
				'existing_post' => $existing_post,
			);
		}

		$prompt_queue[] = array(
			'prompt'   => $prompt,
			'added_at' => gmdate( 'c' ),
		);

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

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
			'flow_step_id' => $flow_step_id,
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
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'flow_step_id'  => $flow_step_id,
			'queue'         => $prompt_queue,
			'count'         => count( $prompt_queue ),
			'queue_enabled' => $step_config['queue_enabled'],
		);
	}

	/**
	 * Clear all prompts from the flow queue.
	 *
	 * @param array $input Input with flow_id.
	 * @return array Result.
	 */
	public function executeQueueClear( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config   = $validation['flow_config'];
		$step_config   = $validation['step_config'];
		$cleared_count = count( $step_config['prompt_queue'] );

		$flow_config[ $flow_step_id ]['prompt_queue'] = array();

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
			'flow_step_id'  => $flow_step_id,
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
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$index        = $input['index'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'index is required and must be a non-negative integer',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );
		$index        = (int) $index;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config  = $validation['flow_config'];
		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];

		if ( $index >= count( $prompt_queue ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Index %d is out of range. Queue has %d item(s).', $index, count( $prompt_queue ) ),
			);
		}

		$removed_item   = $prompt_queue[ $index ];
		$removed_prompt = $removed_item['prompt'] ?? '';

		array_splice( $prompt_queue, $index, 1 );

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

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
			'flow_step_id'   => $flow_step_id,
			'removed_prompt' => $removed_prompt,
			'queue_length'   => count( $prompt_queue ),
			'message'        => sprintf( 'Removed prompt at index %d. Queue now has %d item(s).', $index, count( $prompt_queue ) ),
		);
	}

	/**
	 * Update a prompt at a specific index in the queue.
	 *
	 * If the index is 0 and the queue is empty, creates a new item.
	 *
	 * @param array $input Input with flow_id, index, and prompt.
	 * @return array Result.
	 */
	public function executeQueueUpdate( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$index        = $input['index'] ?? null;
		$prompt       = $input['prompt'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'index is required and must be a non-negative integer',
			);
		}

		if ( ! is_string( $prompt ) ) {
			return array(
				'success' => false,
				'error'   => 'prompt is required and must be a string',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );
		$index        = (int) $index;
		$prompt       = sanitize_textarea_field( wp_unslash( $prompt ) );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config  = $validation['flow_config'];
		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];

		// Special case: if index is 0 and queue is empty, create a new item
		if ( 0 === $index && empty( $prompt_queue ) ) {
			// If prompt is empty, don't create anything
			if ( '' === $prompt ) {
				return array(
					'success'      => true,
					'flow_id'      => $flow_id,
					'index'        => $index,
					'queue_length' => 0,
					'message'      => 'No changes made (empty prompt, empty queue).',
				);
			}

			$prompt_queue[] = array(
				'prompt'   => $prompt,
				'added_at' => gmdate( 'c' ),
			);
		} elseif ( $index >= count( $prompt_queue ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Index %d is out of range. Queue has %d item(s).', $index, count( $prompt_queue ) ),
			);
		} else {
			// Update existing item, preserving added_at
			$prompt_queue[ $index ]['prompt'] = $prompt;
		}

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Queue item updated',
			array(
				'flow_id'      => $flow_id,
				'index'        => $index,
				'queue_length' => count( $prompt_queue ),
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'index'        => $index,
			'queue_length' => count( $prompt_queue ),
			'message'      => sprintf( 'Updated prompt at index %d. Queue has %d item(s).', $index, count( $prompt_queue ) ),
		);
	}

	/**
	 * Move a prompt from one position to another in the queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id, from_index, and to_index.
	 * @return array Result.
	 */
	public function executeQueueMove( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$from_index   = $input['from_index'] ?? null;
		$to_index     = $input['to_index'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		if ( ! is_numeric( $from_index ) || (int) $from_index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'from_index is required and must be a non-negative integer',
			);
		}

		if ( ! is_numeric( $to_index ) || (int) $to_index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'to_index is required and must be a non-negative integer',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );
		$from_index   = (int) $from_index;
		$to_index     = (int) $to_index;

		if ( $from_index === $to_index ) {
			return array(
				'success'      => true,
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'from_index'   => $from_index,
				'to_index'     => $to_index,
				'queue_length' => 0,
				'message'      => 'No move needed (same position).',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config  = $validation['flow_config'];
		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];
		$queue_length = count( $prompt_queue );

		if ( $from_index >= $queue_length ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'from_index %d is out of range. Queue has %d item(s).', $from_index, $queue_length ),
			);
		}

		if ( $to_index >= $queue_length ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'to_index %d is out of range. Queue has %d item(s).', $to_index, $queue_length ),
			);
		}

		// Extract the item and reinsert at new position
		$item = $prompt_queue[ $from_index ];
		array_splice( $prompt_queue, $from_index, 1 );
		array_splice( $prompt_queue, $to_index, 0, array( $item ) );

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to move queue item',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Queue item moved',
			array(
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'from_index'   => $from_index,
				'to_index'     => $to_index,
				'queue_length' => $queue_length,
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'from_index'   => $from_index,
			'to_index'     => $to_index,
			'queue_length' => $queue_length,
			'message'      => sprintf( 'Moved item from index %d to %d.', $from_index, $to_index ),
		);
	}

	/**
	 * Update queue settings for a flow step.
	 *
	 * @param array $input Input with flow_id, flow_step_id, and queue_enabled.
	 * @return array Result.
	 */
	public function executeQueueSettings( array $input ): array {
		$flow_id       = $input['flow_id'] ?? null;
		$flow_step_id  = $input['flow_step_id'] ?? null;
		$queue_enabled = $input['queue_enabled'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		if ( ! is_bool( $queue_enabled ) ) {
			return array(
				'success' => false,
				'error'   => 'queue_enabled is required and must be a boolean',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config                                   = $validation['flow_config'];
		$flow_config[ $flow_step_id ]['queue_enabled'] = $queue_enabled;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update queue settings',
			);
		}

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'flow_step_id'  => $flow_step_id,
			'queue_enabled' => $queue_enabled,
			'message'       => 'Queue settings updated successfully',
		);
	}

	/**
	 * Validate queue items against existing posts and remove duplicates.
	 *
	 * @param array $input Input with flow_id, flow_step_id, and optional dry_run.
	 * @return array Result with duplicates found.
	 */
	public function executeQueueValidate( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$dry_run      = $input['dry_run'] ?? false;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required and must be a string',
			);
		}

		$flow_id      = (int) $flow_id;
		$flow_step_id = sanitize_text_field( $flow_step_id );

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$validation = $this->getStepConfigForQueue( $flow, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_config  = $validation['flow_config'];
		$step_config  = $validation['step_config'];
		$prompt_queue = $step_config['prompt_queue'];

		$total_checked     = count( $prompt_queue );
		$duplicates        = array();
		$indices_to_remove = array();

		// Check each queue item against existing posts.
		foreach ( $prompt_queue as $index => $item ) {
			$prompt        = $item['prompt'] ?? '';
			$existing_post = $this->findSimilarExistingPost( $prompt );

			if ( $existing_post ) {
				$duplicates[]        = array(
					'index'               => $index,
					'prompt'              => $prompt,
					'existing_post_id'    => $existing_post['id'],
					'existing_post_title' => $existing_post['title'],
				);
				$indices_to_remove[] = $index;
			}
		}

		$duplicates_found   = count( $duplicates );
		$duplicates_removed = 0;

		// Remove duplicates if not dry run.
		if ( ! $dry_run && ! empty( $indices_to_remove ) ) {
			// Remove in reverse order to preserve indices.
			rsort( $indices_to_remove );
			foreach ( $indices_to_remove as $index ) {
				array_splice( $prompt_queue, $index, 1 );
			}

			$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

			$success = $this->db_flows->update_flow(
				$flow_id,
				array( 'flow_config' => $flow_config )
			);

			if ( $success ) {
				$duplicates_removed = $duplicates_found;
			}

			do_action(
				'datamachine_log',
				'info',
				'Queue validated and duplicates removed',
				array(
					'flow_id'            => $flow_id,
					'duplicates_removed' => $duplicates_removed,
					'remaining_count'    => count( $prompt_queue ),
				)
			);
		}

		$action  = $dry_run ? 'would remove' : 'removed';
		$message = sprintf(
			'Validated %d item(s). Found %d duplicate(s), %s %d.',
			$total_checked,
			$duplicates_found,
			$action,
			$dry_run ? $duplicates_found : $duplicates_removed
		);

		return array(
			'success'            => true,
			'flow_id'            => $flow_id,
			'flow_step_id'       => $flow_step_id,
			'total_checked'      => $total_checked,
			'duplicates_found'   => $duplicates_found,
			'duplicates_removed' => $duplicates_removed,
			'duplicates'         => $duplicates,
			'dry_run'            => $dry_run,
			'message'            => $message,
		);
	}

	/**
	 * Pop the first prompt from the queue (for engine use).
	 *
	 * @param int      $flow_id  Flow ID.
	 * @param DB_Flows $db_flows Database instance (avoids creating new instance each call).
	 * @return array|null The popped queue item or null if empty.
	 */
	public static function popFromQueue( int $flow_id, string $flow_step_id, ?DB_Flows $db_flows = null ): ?array {
		if ( null === $db_flows ) {
			$db_flows = new DB_Flows();
		}

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return null;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$step_config  = $flow_config[ $flow_step_id ];
		$prompt_queue = $step_config['prompt_queue'] ?? array();

		if ( empty( $prompt_queue ) ) {
			return null;
		}

		$popped_item = array_shift( $prompt_queue );

		$flow_config[ $flow_step_id ]['prompt_queue'] = $prompt_queue;

		$db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		do_action(
			'datamachine_log',
			'info',
			'Prompt popped from queue',
			array(
				'flow_id'         => $flow_id,
				'remaining_count' => count( $prompt_queue ),
			)
		);

		return $popped_item;
	}

	/**
	 * Normalize flow step queue config for queue operations.
	 *
	 * @param array  $flow Flow record.
	 * @param string $flow_step_id Flow step ID.
	 * @return array Result with flow_config and step_config or error.
	 */
	private function getStepConfigForQueue( array $flow, string $flow_step_id ): array {
		$flow_id = (int) ( $flow['flow_id'] ?? 0 );
		$parts   = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts || empty( $parts['flow_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid flow_step_id format',
			);
		}
		if ( (int) $parts['flow_id'] !== $flow_id ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id does not belong to this flow',
			);
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return array(
				'success' => false,
				'error'   => 'Flow step not found in flow config',
			);
		}

		$step_config = $flow_config[ $flow_step_id ];
		if ( ! isset( $step_config['prompt_queue'] ) || ! is_array( $step_config['prompt_queue'] ) ) {
			$step_config['prompt_queue'] = array();
		}
		if ( ! isset( $step_config['queue_enabled'] ) || ! is_bool( $step_config['queue_enabled'] ) ) {
			$step_config['queue_enabled'] = false;
		}
		$flow_config[ $flow_step_id ] = $step_config;

		return array(
			'success'     => true,
			'flow_config' => $flow_config,
			'step_config' => $step_config,
		);
	}

	/**
	 * Find an existing post with a similar title to the prompt.
	 *
	 * Extracts core topic from common prompt patterns and searches for matching posts.
	 *
	 * @param string $prompt The prompt to check for duplicates.
	 * @return array|null Array with 'id' and 'title' if found, null otherwise.
	 */
	private function findSimilarExistingPost( string $prompt ): ?array {
		global $wpdb;

		// Extract core topic by removing common prefixes/suffixes.
		$core_topic = $prompt;
		$core_topic = preg_replace( '/^(the\s+)?(spiritual\s+meaning\s+of\s+)/i', '', $core_topic );
		$core_topic = preg_replace( '/\s+(spiritual\s+meaning|symbolism|symbol|meaning).*$/i', '', $core_topic );
		$core_topic = preg_replace( '/^(why\s+(do|are|is)\s+)/i', '', $core_topic );
		$core_topic = preg_replace( '/^(what\s+(do|does|is|are)\s+)/i', '', $core_topic );
		$core_topic = preg_replace( '/^(how\s+(do|does|to)\s+)/i', '', $core_topic );
		$core_topic = trim( $core_topic );

		// Skip if core topic is too short.
		if ( strlen( $core_topic ) < 3 ) {
			return null;
		}

		// Search for exact prompt match first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exact_match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = 'post'
				AND post_status = 'publish'
				AND post_title = %s
				LIMIT 1",
				$prompt
			)
		);

		if ( $exact_match ) {
			return array(
				'id'    => (int) $exact_match->ID,
				'title' => $exact_match->post_title,
			);
		}

		// Search for core topic in title.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$similar_match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = 'post'
				AND post_status = 'publish'
				AND LOWER(post_title) LIKE %s
				LIMIT 1",
				'%' . $wpdb->esc_like( strtolower( $core_topic ) ) . '%'
			)
		);

		if ( $similar_match ) {
			return array(
				'id'    => (int) $similar_match->ID,
				'title' => $similar_match->post_title,
			);
		}

		return null;
	}
}
