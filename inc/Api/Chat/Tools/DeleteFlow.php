<?php
/**
 * Delete Flow Tool
 *
 * Focused tool for deleting flows.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class DeleteFlow extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_flow', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete a flow.',
			'parameters'  => array(
				'flow_id' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'ID of the flow to delete',
				),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/delete-flow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Delete flow ability not available',
				'tool_name' => 'delete_flow',
			);
		}

		$result = $ability->execute(
			array(
				'flow_id' => (int) ( $parameters['flow_id'] ?? 0 ),
			)
		);

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to delete flow' );
			return $this->buildErrorResponse( $error, 'delete_flow' );
		}

		return array(
			'success'   => true,
			'data'      => array(
				'flow_id' => $result['flow_id'],
				'message' => $result['message'] ?? 'Flow deleted.',
			),
			'tool_name' => 'delete_flow',
		);
	}
}
