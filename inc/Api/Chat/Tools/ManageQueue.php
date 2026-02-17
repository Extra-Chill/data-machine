<?php
/**
 * Manage Queue Tool
 *
 * Chat tool for managing prompt queues on flow steps.
 * Supports add, list, clear, remove, update, move, and settings actions.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.24.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ManageQueue extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'manage_queue', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Get tool definition.
	 *
	 * @since 0.24.0
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildDescription(),
			'parameters'  => array(
				'action'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "add", "list", "clear", "remove", "update", "move", or "settings"',
				),
				'flow_id'       => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Flow ID',
				),
				'flow_step_id'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Flow step ID',
				),
				'prompt'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Prompt text (for add and update actions)',
				),
				'index'         => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Queue index, 0-based (for remove and update actions)',
				),
				'from_index'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Source index for move action (0-based)',
				),
				'to_index'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Destination index for move action (0-based)',
				),
				'queue_enabled' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Whether queue pop is enabled (for settings action)',
				),
			),
		);
	}

	/**
	 * Build tool description.
	 *
	 * @since 0.24.0
	 * @return string Tool description.
	 */
	private function buildDescription(): string {
		return 'Manage prompt queues for flow steps.

ACTIONS:
- add: Add a prompt to the queue (requires prompt)
- list: List all prompts in the queue
- clear: Clear all prompts from the queue
- remove: Remove a prompt by index (requires index)
- update: Update a prompt at a specific index (requires index and prompt)
- move: Move a prompt from one position to another (requires from_index and to_index)
- settings: Update queue settings (requires queue_enabled)

All actions require flow_id and flow_step_id.';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 0.24.0
	 * @param array $parameters Tool call parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		$ability_map = array(
			'add'      => 'datamachine/queue-add',
			'list'     => 'datamachine/queue-list',
			'clear'    => 'datamachine/queue-clear',
			'remove'   => 'datamachine/queue-remove',
			'update'   => 'datamachine/queue-update',
			'move'     => 'datamachine/queue-move',
			'settings' => 'datamachine/queue-settings',
		);

		if ( ! isset( $ability_map[ $action ] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Invalid action. Use "add", "list", "clear", "remove", "update", "move", or "settings"',
				'tool_name' => 'manage_queue',
			);
		}

		$ability = wp_get_ability( $ability_map[ $action ] );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( '%s ability not available', $ability_map[ $action ] ),
				'tool_name' => 'manage_queue',
			);
		}

		$input = $this->buildInput( $action, $parameters );

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, sprintf( 'Failed to %s queue', $action ) );
			return $this->buildErrorResponse( $error, 'manage_queue' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_queue',
		);
	}

	/**
	 * Build input array for the ability based on action.
	 *
	 * @since 0.24.0
	 * @param string $action     The action being performed.
	 * @param array  $parameters Raw tool parameters.
	 * @return array Input for the ability.
	 */
	private function buildInput( string $action, array $parameters ): array {
		$input = array(
			'flow_id'      => $parameters['flow_id'] ?? null,
			'flow_step_id' => $parameters['flow_step_id'] ?? null,
		);

		switch ( $action ) {
			case 'add':
				$input['prompt'] = $parameters['prompt'] ?? '';
				break;

			case 'remove':
				$input['index'] = $parameters['index'] ?? null;
				break;

			case 'update':
				$input['index']  = $parameters['index'] ?? null;
				$input['prompt'] = $parameters['prompt'] ?? '';
				break;

			case 'move':
				$input['from_index'] = $parameters['from_index'] ?? null;
				$input['to_index']   = $parameters['to_index'] ?? null;
				break;

			case 'settings':
				$input['queue_enabled'] = $parameters['queue_enabled'] ?? null;
				break;
		}

		return $input;
	}
}
