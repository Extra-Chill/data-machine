<?php
/**
 * Queue Ability
 *
 * Manages per-step queues attached to flow steps. Two parallel queues
 * are supported, each with its own payload shape:
 *
 *   - `prompt_queue`        — array<{prompt:string, added_at:string}>
 *                             Consumed by AI step (`AIStep::execute()`)
 *                             via {@see QueueableTrait::popFromQueueIfEmpty}.
 *                             Each entry is a plain user-message string.
 *
 *   - `config_patch_queue`  — array<{patch:array, added_at:string}>
 *                             Consumed by Fetch step
 *                             (`FetchStep::executeStep()`) via
 *                             {@see QueueableTrait::popQueuedConfigPatch}.
 *                             Each entry is a decoded object that gets
 *                             deep-merged into the handler's static
 *                             config before the fetch runs.
 *
 * Splitting these slots is #1292 — pre-split, both consumers shared a
 * single `prompt_queue` and ran string-vs-JSON detective work at read
 * time, with no validation at write time. Now each slot has one
 * payload shape, validated by JSON Schema directly, and writes that
 * target the wrong slot for a step type fail loudly.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.16.0
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Core\Database\Flows\Flows as DB_Flows;
use DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility;

defined( 'ABSPATH' ) || exit;

class QueueAbility {

	use FlowHelpers;

	/**
	 * Slot name for AI prompt queues. Used as both the storage key
	 * and the per-entry payload field name (so each entry is
	 * `{ prompt: string, added_at: string }`).
	 */
	const SLOT_PROMPT_QUEUE = 'prompt_queue';

	/**
	 * Slot name for fetch-step config-patch queues. Each entry is
	 * `{ patch: array, added_at: string }` — `patch` is a decoded
	 * object stored verbatim (no JSON-encoding-as-string).
	 */
	const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';

	/**
	 * Per-entry payload field name for prompt queues.
	 */
	const FIELD_PROMPT = 'prompt';

	/**
	 * Per-entry payload field name for config-patch queues.
	 */
	const FIELD_PATCH = 'patch';

	public function __construct() {
		$this->initDatabases();

		$this->registerAbilities();
	}

	/**
	 * Register all queue-related abilities.
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			// Prompt queue (AI consumer).
			$this->registerQueueAdd();
			$this->registerQueueList();
			$this->registerQueueClear();
			$this->registerQueueRemove();
			$this->registerQueueUpdate();
			$this->registerQueueMove();
			$this->registerQueueSettings();

			// Config patch queue (Fetch consumer).
			$this->registerConfigPatchAdd();
			$this->registerConfigPatchList();
			$this->registerConfigPatchClear();
			$this->registerConfigPatchRemove();
			$this->registerConfigPatchUpdate();
			$this->registerConfigPatchMove();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
				'description'         => __( 'Add a prompt to the AI step prompt queue.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'prompt' ),
					'properties' => array(
						'flow_id'         => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID to add prompt to', 'data-machine' ),
						),
						'flow_step_id'    => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID to add prompt to', 'data-machine' ),
						),
						'prompt'          => array(
							'type'        => 'string',
							'description' => __( 'Prompt text to queue', 'data-machine' ),
						),
						'skip_validation' => array(
							'type'        => 'boolean',
							'description' => __( 'Skip duplicate validation (default: false). Use only when intentionally re-adding a known prompt.', 'data-machine' ),
						),
						'context'         => array(
							'type'        => 'object',
							'description' => __( 'Domain-specific context for duplicate detection strategies (e.g., venue, startDate for events).', 'data-machine' ),
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
						'reason'       => array( 'type' => 'string' ),
						'match'        => array( 'type' => 'object' ),
						'source'       => array( 'type' => 'string' ),
						'strategy'     => array( 'type' => 'string' ),
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
				'description'         => __( 'List all prompts in the AI step prompt queue.', 'data-machine' ),
				'category'            => 'datamachine-flow',
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
				'description'         => __( 'Clear all prompts from the AI step prompt queue.', 'data-machine' ),
				'category'            => 'datamachine-flow',
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
				'category'            => 'datamachine-flow',
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
				'category'            => 'datamachine-flow',
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
				'category'            => 'datamachine-flow',
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
				'category'            => 'datamachine-flow',
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
	 * Register config-patch-add ability (Fetch consumer).
	 */
	private function registerConfigPatchAdd(): void {
		wp_register_ability(
			'datamachine/config-patch-add',
			array(
				'label'               => __( 'Add Config Patch to Queue', 'data-machine' ),
				'description'         => __( 'Add a config patch to the fetch step queue. The patch is deep-merged into the handler config when the step runs.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'patch' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID (must be a fetch step)', 'data-machine' ),
						),
						'patch'        => array(
							'type'        => 'object',
							'description' => __( 'Config patch object — deep-merged into the handler config at fetch time. Shape must mirror the handler\'s static config nesting.', 'data-machine' ),
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
				'execute_callback'    => array( $this, 'executeConfigPatchAdd' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register config-patch-list ability.
	 */
	private function registerConfigPatchList(): void {
		wp_register_ability(
			'datamachine/config-patch-list',
			array(
				'label'               => __( 'List Config Patch Queue', 'data-machine' ),
				'description'         => __( 'List all queued config patches for a fetch step.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
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
				'execute_callback'    => array( $this, 'executeConfigPatchList' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register config-patch-clear ability.
	 */
	private function registerConfigPatchClear(): void {
		wp_register_ability(
			'datamachine/config-patch-clear',
			array(
				'label'               => __( 'Clear Config Patch Queue', 'data-machine' ),
				'description'         => __( 'Clear all queued config patches for a fetch step.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id' ),
					'properties' => array(
						'flow_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID', 'data-machine' ),
						),
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID', 'data-machine' ),
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
				'execute_callback'    => array( $this, 'executeConfigPatchClear' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register config-patch-remove ability.
	 */
	private function registerConfigPatchRemove(): void {
		wp_register_ability(
			'datamachine/config-patch-remove',
			array(
				'label'               => __( 'Remove Config Patch from Queue', 'data-machine' ),
				'description'         => __( 'Remove a queued config patch by index.', 'data-machine' ),
				'category'            => 'datamachine-flow',
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
						'success'       => array( 'type' => 'boolean' ),
						'flow_id'       => array( 'type' => 'integer' ),
						'removed_patch' => array( 'type' => 'object' ),
						'queue_length'  => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeConfigPatchRemove' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register config-patch-update ability.
	 */
	private function registerConfigPatchUpdate(): void {
		wp_register_ability(
			'datamachine/config-patch-update',
			array(
				'label'               => __( 'Update Config Patch in Queue', 'data-machine' ),
				'description'         => __( 'Replace a queued config patch at a specific index.', 'data-machine' ),
				'category'            => 'datamachine-flow',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_id', 'flow_step_id', 'index', 'patch' ),
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
						'patch'        => array(
							'type'        => 'object',
							'description' => __( 'Replacement config patch object', 'data-machine' ),
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
				'execute_callback'    => array( $this, 'executeConfigPatchUpdate' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register config-patch-move ability.
	 */
	private function registerConfigPatchMove(): void {
		wp_register_ability(
			'datamachine/config-patch-move',
			array(
				'label'               => __( 'Move Config Patch in Queue', 'data-machine' ),
				'description'         => __( 'Reorder a queued config patch from one index to another.', 'data-machine' ),
				'category'            => 'datamachine-flow',
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
				'execute_callback'    => array( $this, 'executeConfigPatchMove' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Add a prompt to the AI step prompt queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id, prompt.
	 * @return array Result.
	 */
	public function executeQueueAdd( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$prompt       = $input['prompt'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		if ( empty( $prompt ) || ! is_string( $prompt ) ) {
			return array(
				'success' => false,
				'error'   => 'prompt is required and must be a non-empty string',
			);
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];
		$prompt       = sanitize_textarea_field( wp_unslash( $prompt ) );

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, self::SLOT_PROMPT_QUEUE );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config = $flow_lookup['flow_config'];
		$step_config = $flow_lookup['step_config'];
		$queue       = $step_config[ self::SLOT_PROMPT_QUEUE ];

		// Duplicate validation (unless explicitly skipped).
		$skip_validation = ! empty( $input['skip_validation'] );
		if ( ! $skip_validation ) {
			// Resolve post_type from the flow's publish step handler config.
			// Without this, the validator defaults to 'post' and misses duplicates
			// for custom post types (quizzes, recipes, events, etc.).
			$post_type = $input['post_type'] ?? $this->resolvePublishPostType( $flow_config );

			$dedup  = new DuplicateCheckAbility();
			$result = $dedup->executeCheckDuplicate( array(
				'title'        => $prompt,
				'post_type'    => $post_type,
				'scope'        => 'both',
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'context'      => $input['context'] ?? array(),
			) );

			if ( 'duplicate' === $result['verdict'] ) {
				return array(
					'success'  => false,
					'error'    => 'duplicate_rejected',
					'reason'   => $result['reason'] ?? '',
					'match'    => $result['match'] ?? array(),
					'source'   => $result['source'] ?? 'unknown',
					'strategy' => $result['strategy'] ?? '',
					'flow_id'  => $flow_id,
					'message'  => sprintf( 'Rejected: "%s" is a duplicate. %s', $prompt, $result['reason'] ?? '' ),
				);
			}
		}

		$queue[] = array(
			self::FIELD_PROMPT => $prompt,
			'added_at'         => gmdate( 'c' ),
		);

		$flow_config[ $flow_step_id ][ self::SLOT_PROMPT_QUEUE ] = $queue;

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
				'queue_length' => count( $queue ),
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'queue_length' => count( $queue ),
			'message'      => sprintf( 'Prompt added to queue. Queue now has %d item(s).', count( $queue ) ),
		);
	}

	/**
	 * List all prompts in the AI step prompt queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id.
	 * @return array Result.
	 */
	public function executeQueueList( array $input ): array {
		return $this->listQueueSlot( $input, self::SLOT_PROMPT_QUEUE );
	}

	/**
	 * Clear all prompts from the AI step prompt queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id.
	 * @return array Result.
	 */
	public function executeQueueClear( array $input ): array {
		return $this->clearQueueSlot( $input, self::SLOT_PROMPT_QUEUE );
	}

	/**
	 * Remove a specific prompt from the queue by index.
	 *
	 * @param array $input Input with flow_id, flow_step_id, index.
	 * @return array Result.
	 */
	public function executeQueueRemove( array $input ): array {
		return $this->removeQueueSlot( $input, self::SLOT_PROMPT_QUEUE, self::FIELD_PROMPT );
	}

	/**
	 * Update a prompt at a specific index in the queue.
	 *
	 * If the index is 0 and the queue is empty, creates a new item.
	 *
	 * @param array $input Input with flow_id, flow_step_id, index, prompt.
	 * @return array Result.
	 */
	public function executeQueueUpdate( array $input ): array {
		$value = $input['prompt'] ?? null;
		if ( ! is_string( $value ) ) {
			return array(
				'success' => false,
				'error'   => 'prompt is required and must be a string',
			);
		}
		$value = sanitize_textarea_field( wp_unslash( $value ) );

		return $this->updateQueueSlot( $input, self::SLOT_PROMPT_QUEUE, self::FIELD_PROMPT, $value );
	}

	/**
	 * Move a prompt from one position to another in the queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id, from_index, to_index.
	 * @return array Result.
	 */
	public function executeQueueMove( array $input ): array {
		return $this->moveQueueSlot( $input, self::SLOT_PROMPT_QUEUE );
	}

	/**
	 * Add a config patch to the fetch step config-patch queue.
	 *
	 * @param array $input Input with flow_id, flow_step_id, patch.
	 * @return array Result.
	 */
	public function executeConfigPatchAdd( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$patch        = $input['patch'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		if ( ! is_array( $patch ) ) {
			return array(
				'success' => false,
				'error'   => 'patch is required and must be an object',
			);
		}

		if ( empty( $patch ) ) {
			return array(
				'success' => false,
				'error'   => 'patch must be a non-empty object',
			);
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, self::SLOT_CONFIG_PATCH_QUEUE );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config = $flow_lookup['flow_config'];
		$step_config = $flow_lookup['step_config'];
		$queue       = $step_config[ self::SLOT_CONFIG_PATCH_QUEUE ];

		$queue[] = array(
			self::FIELD_PATCH => $patch,
			'added_at'        => gmdate( 'c' ),
		);

		$flow_config[ $flow_step_id ][ self::SLOT_CONFIG_PATCH_QUEUE ] = $queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update flow config patch queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Config patch added to queue',
			array(
				'flow_id'      => $flow_id,
				'queue_length' => count( $queue ),
				'patch_keys'   => array_keys( $patch ),
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'queue_length' => count( $queue ),
			'message'      => sprintf( 'Config patch added to queue. Queue now has %d item(s).', count( $queue ) ),
		);
	}

	/**
	 * List all config patches in the fetch queue.
	 */
	public function executeConfigPatchList( array $input ): array {
		return $this->listQueueSlot( $input, self::SLOT_CONFIG_PATCH_QUEUE );
	}

	/**
	 * Clear all config patches from the fetch queue.
	 */
	public function executeConfigPatchClear( array $input ): array {
		return $this->clearQueueSlot( $input, self::SLOT_CONFIG_PATCH_QUEUE );
	}

	/**
	 * Remove a specific config patch from the queue by index.
	 */
	public function executeConfigPatchRemove( array $input ): array {
		return $this->removeQueueSlot( $input, self::SLOT_CONFIG_PATCH_QUEUE, self::FIELD_PATCH );
	}

	/**
	 * Update a config patch at a specific index in the queue.
	 */
	public function executeConfigPatchUpdate( array $input ): array {
		$value = $input['patch'] ?? null;
		if ( ! is_array( $value ) ) {
			return array(
				'success' => false,
				'error'   => 'patch is required and must be an object',
			);
		}
		if ( empty( $value ) ) {
			return array(
				'success' => false,
				'error'   => 'patch must be a non-empty object',
			);
		}

		return $this->updateQueueSlot( $input, self::SLOT_CONFIG_PATCH_QUEUE, self::FIELD_PATCH, $value );
	}

	/**
	 * Move a config patch from one position to another in the queue.
	 */
	public function executeConfigPatchMove( array $input ): array {
		return $this->moveQueueSlot( $input, self::SLOT_CONFIG_PATCH_QUEUE );
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

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		if ( ! is_bool( $queue_enabled ) ) {
			return array(
				'success' => false,
				'error'   => 'queue_enabled is required and must be a boolean',
			);
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];

		// queue_enabled is a step-level toggle that applies to whichever
		// queue slot the step type consumes — no slot routing needed
		// here. Use SLOT_PROMPT_QUEUE for the existence check; the
		// step_config defaulting just ensures the row exists.
		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, self::SLOT_PROMPT_QUEUE );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config                                   = $flow_lookup['flow_config'];
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
	 * List items in a specific queue slot.
	 *
	 * Shared executor for `queue-list` and `config-patch-list`.
	 *
	 * @param array  $input Input with flow_id, flow_step_id.
	 * @param string $slot  One of self::SLOT_PROMPT_QUEUE | self::SLOT_CONFIG_PATCH_QUEUE.
	 * @return array Result with queue items.
	 */
	private function listQueueSlot( array $input, string $slot ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, $slot );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$step_config = $flow_lookup['step_config'];
		$queue       = $step_config[ $slot ];

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'flow_step_id'  => $flow_step_id,
			'queue'         => $queue,
			'count'         => count( $queue ),
			'queue_enabled' => $step_config['queue_enabled'],
		);
	}

	/**
	 * Clear all items from a specific queue slot.
	 *
	 * @param array  $input Input.
	 * @param string $slot  Slot name.
	 * @return array Result.
	 */
	private function clearQueueSlot( array $input, string $slot ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, $slot );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config   = $flow_lookup['flow_config'];
		$step_config   = $flow_lookup['step_config'];
		$cleared_count = count( $step_config[ $slot ] );

		$flow_config[ $flow_step_id ][ $slot ] = array();

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
				'slot'          => $slot,
				'cleared_count' => $cleared_count,
			)
		);

		return array(
			'success'       => true,
			'flow_id'       => $flow_id,
			'flow_step_id'  => $flow_step_id,
			'cleared_count' => $cleared_count,
			'message'       => sprintf( 'Cleared %d item(s) from queue.', $cleared_count ),
		);
	}

	/**
	 * Remove a specific item from a queue slot.
	 *
	 * Returns both `removed_prompt` (legacy field, set when slot is the
	 * prompt queue) and `removed_patch` (set when slot is the config
	 * patch queue) so existing prompt-queue callers see no shape change.
	 *
	 * @param array  $input       Input with flow_id, flow_step_id, index.
	 * @param string $slot        Slot name.
	 * @param string $field_name  Per-entry payload field (`prompt` or `patch`).
	 * @return array Result.
	 */
	private function removeQueueSlot( array $input, string $slot, string $field_name ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$index        = $input['index'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'index is required and must be a non-negative integer',
			);
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];
		$index        = (int) $index;

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, $slot );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config = $flow_lookup['flow_config'];
		$step_config = $flow_lookup['step_config'];
		$queue       = $step_config[ $slot ];

		if ( $index >= count( $queue ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Index %d is out of range. Queue has %d item(s).', $index, count( $queue ) ),
			);
		}

		$removed_item    = $queue[ $index ];
		$removed_payload = $removed_item[ $field_name ] ?? '';

		array_splice( $queue, $index, 1 );

		$flow_config[ $flow_step_id ][ $slot ] = $queue;

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to remove item from queue',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Item removed from queue',
			array(
				'flow_id'      => $flow_id,
				'slot'         => $slot,
				'index'        => $index,
				'queue_length' => count( $queue ),
			)
		);

		$result = array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'queue_length' => count( $queue ),
			'message'      => sprintf( 'Removed item at index %d. Queue now has %d item(s).', $index, count( $queue ) ),
		);

		// Surface payload under the slot-appropriate key.
		if ( self::FIELD_PATCH === $field_name ) {
			$result['removed_patch'] = is_array( $removed_payload ) ? $removed_payload : array();
		} else {
			$result['removed_prompt'] = is_string( $removed_payload ) ? $removed_payload : '';
		}

		return $result;
	}

	/**
	 * Update an item at a specific index in a queue slot.
	 *
	 * If the index is 0 and the queue is empty, creates a new item.
	 *
	 * @param array  $input      Input with flow_id, flow_step_id, index.
	 * @param string $slot       Slot name.
	 * @param string $field_name Per-entry payload field.
	 * @param mixed  $value      New payload value (already validated/sanitized by caller).
	 * @return array Result.
	 */
	private function updateQueueSlot( array $input, string $slot, string $field_name, $value ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$index        = $input['index'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
		}

		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return array(
				'success' => false,
				'error'   => 'index is required and must be a non-negative integer',
			);
		}

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];
		$index        = (int) $index;

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, $slot );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config = $flow_lookup['flow_config'];
		$step_config = $flow_lookup['step_config'];
		$queue       = $step_config[ $slot ];

		// Special case: if index is 0 and queue is empty, create a new item.
		if ( 0 === $index && empty( $queue ) ) {
			// If value is the empty version of its type, don't create anything.
			$is_empty = self::FIELD_PATCH === $field_name
				? ( ! is_array( $value ) || empty( $value ) )
				: '' === $value;

			if ( $is_empty ) {
				return array(
					'success'      => true,
					'flow_id'      => $flow_id,
					'index'        => $index,
					'queue_length' => 0,
					'message'      => 'No changes made (empty value, empty queue).',
				);
			}

			$queue[] = array(
				$field_name => $value,
				'added_at'  => gmdate( 'c' ),
			);
		} elseif ( $index >= count( $queue ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Index %d is out of range. Queue has %d item(s).', $index, count( $queue ) ),
			);
		} else {
			// Update existing item, preserving added_at.
			$queue[ $index ][ $field_name ] = $value;
		}

		$flow_config[ $flow_step_id ][ $slot ] = $queue;

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
				'slot'         => $slot,
				'index'        => $index,
				'queue_length' => count( $queue ),
			)
		);

		return array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_step_id' => $flow_step_id,
			'index'        => $index,
			'queue_length' => count( $queue ),
			'message'      => sprintf( 'Updated item at index %d. Queue has %d item(s).', $index, count( $queue ) ),
		);
	}

	/**
	 * Move an item from one position to another in a queue slot.
	 *
	 * @param array  $input Input with flow_id, flow_step_id, from_index, to_index.
	 * @param string $slot  Slot name.
	 * @return array Result.
	 */
	private function moveQueueSlot( array $input, string $slot ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;
		$from_index   = $input['from_index'] ?? null;
		$to_index     = $input['to_index'] ?? null;

		$validation = $this->validateFlowStepIds( $flow_id, $flow_step_id );
		if ( ! $validation['success'] ) {
			return $validation;
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

		$flow_id      = $validation['flow_id'];
		$flow_step_id = $validation['flow_step_id'];
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

		$flow_lookup = $this->loadFlowAndStepConfig( $flow_id, $flow_step_id, $slot );
		if ( ! $flow_lookup['success'] ) {
			return $flow_lookup;
		}

		$flow_config  = $flow_lookup['flow_config'];
		$step_config  = $flow_lookup['step_config'];
		$queue        = $step_config[ $slot ];
		$queue_length = count( $queue );

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

		// Extract the item and reinsert at new position.
		$item = $queue[ $from_index ];
		array_splice( $queue, $from_index, 1 );
		array_splice( $queue, $to_index, 0, array( $item ) );

		$flow_config[ $flow_step_id ][ $slot ] = $queue;

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
				'slot'         => $slot,
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
	 * Pop the first prompt from the queue (for engine use).
	 *
	 * Used by AIStep's queueable trait. For the fetch-step config-patch
	 * queue, see {@see popConfigPatchFromQueue()}.
	 *
	 * @param int      $flow_id      Flow ID.
	 * @param string   $flow_step_id Flow step ID.
	 * @param DB_Flows $db_flows     Database instance (avoids creating new instance each call).
	 * @return array|null The popped queue item or null if empty.
	 */
	public static function popFromQueue( int $flow_id, string $flow_step_id, ?DB_Flows $db_flows = null ): ?array {
		return self::popFromQueueSlot( $flow_id, $flow_step_id, self::SLOT_PROMPT_QUEUE, $db_flows );
	}

	/**
	 * Pop the first config patch from the fetch step config-patch queue.
	 *
	 * Sibling of {@see popFromQueue()} for FetchStep's QueueableTrait
	 * consumer. Returns the entry verbatim (no JSON-decode — the patch
	 * is stored as a decoded array).
	 *
	 * @param int      $flow_id      Flow ID.
	 * @param string   $flow_step_id Flow step ID.
	 * @param DB_Flows $db_flows     Database instance.
	 * @return array|null The popped queue item (`{ patch, added_at }`) or null if empty.
	 */
	public static function popConfigPatchFromQueue( int $flow_id, string $flow_step_id, ?DB_Flows $db_flows = null ): ?array {
		return self::popFromQueueSlot( $flow_id, $flow_step_id, self::SLOT_CONFIG_PATCH_QUEUE, $db_flows );
	}

	/**
	 * Pop the first item from a named queue slot.
	 *
	 * @param int      $flow_id      Flow ID.
	 * @param string   $flow_step_id Flow step ID.
	 * @param string   $slot         Slot name.
	 * @param DB_Flows $db_flows     Database instance.
	 * @return array|null The popped item or null if empty.
	 */
	private static function popFromQueueSlot( int $flow_id, string $flow_step_id, string $slot, ?DB_Flows $db_flows = null ): ?array {
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

		$step_config = $flow_config[ $flow_step_id ];
		$queue       = $step_config[ $slot ] ?? array();

		if ( empty( $queue ) ) {
			return null;
		}

		$popped_item = array_shift( $queue );

		$flow_config[ $flow_step_id ][ $slot ] = $queue;

		$db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		do_action(
			'datamachine_log',
			'info',
			'Item popped from queue',
			array(
				'flow_id'         => $flow_id,
				'slot'            => $slot,
				'remaining_count' => count( $queue ),
			)
		);

		return $popped_item;
	}

	/**
	 * Validate flow_id and flow_step_id input fields.
	 *
	 * @param mixed $flow_id      Raw flow_id input.
	 * @param mixed $flow_step_id Raw flow_step_id input.
	 * @return array{success: bool, error?: string, flow_id?: int, flow_step_id?: string}
	 */
	private function validateFlowStepIds( $flow_id, $flow_step_id ): array {
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

		return array(
			'success'      => true,
			'flow_id'      => (int) $flow_id,
			'flow_step_id' => sanitize_text_field( $flow_step_id ),
		);
	}

	/**
	 * Load flow + normalize step config for queue operations.
	 *
	 * Defaults the named slot to an empty array and `queue_enabled` to
	 * false when missing — same shape as the pre-split helper, but
	 * targets a specific queue slot rather than the implicit
	 * `prompt_queue`.
	 *
	 * @param int    $flow_id      Flow ID (already validated).
	 * @param string $flow_step_id Flow step ID (already validated).
	 * @param string $slot         Queue slot to default.
	 * @return array{success: bool, error?: string, flow_config?: array, step_config?: array}
	 */
	private function loadFlowAndStepConfig( int $flow_id, string $flow_step_id, string $slot ): array {
		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
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
		if ( ! isset( $step_config[ $slot ] ) || ! is_array( $step_config[ $slot ] ) ) {
			$step_config[ $slot ] = array();
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
	 * Resolve the post_type from the flow's publish step handler config.
	 *
	 * Scans all steps in the flow config for a publish step and extracts
	 * the post_type from its handler config. Falls back to 'post' if no
	 * publish step is found or no post_type is configured.
	 *
	 * @param array $flow_config The flow configuration array keyed by flow_step_id.
	 * @return string The resolved post type.
	 */
	private function resolvePublishPostType( array $flow_config ): string {
		foreach ( $flow_config as $step_config ) {
			if ( ! is_array( $step_config ) ) {
				continue;
			}
			if ( ( $step_config['step_type'] ?? '' ) !== 'publish' ) {
				continue;
			}
			$handler_configs = $step_config['handler_configs'] ?? array();
			foreach ( $handler_configs as $handler_config ) {
				if ( ! empty( $handler_config['post_type'] ) ) {
					return sanitize_text_field( $handler_config['post_type'] );
				}
			}
		}
		return 'post';
	}
}
