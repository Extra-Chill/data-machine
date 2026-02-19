<?php
/**
 * Delete File Tool
 *
 * Focused tool for deleting uploaded files.
 * Delegates to FileAbilities for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class DeleteFile extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'delete_file', array( $this, 'getToolDefinition' ) );
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
			'description' => 'Delete an uploaded file. Requires flow_step_id to identify the file scope.',
			'parameters'  => array(
				'filename'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Name of the file to delete',
				),
				'flow_step_id' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Flow step ID for flow-level files (e.g., "1-2" for pipeline 1, flow 2)',
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
		$filename     = $parameters['filename'] ?? null;
		$flow_step_id = $parameters['flow_step_id'] ?? null;

		if ( empty( $filename ) ) {
			return array(
				'success'   => false,
				'error'     => 'filename is required',
				'tool_name' => 'delete_file',
			);
		}

		$ability = wp_get_ability( 'datamachine/delete-file' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Delete file ability not available',
				'tool_name' => 'delete_file',
			);
		}

		$input = array(
			'filename' => sanitize_file_name( $filename ),
		);

		if ( $flow_step_id ) {
			$input['flow_step_id'] = sanitize_text_field( $flow_step_id );
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Failed to delete file',
				'tool_name' => 'delete_file',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'filename' => $input['filename'],
				'scope'    => $result['scope'],
				'message'  => $result['message'],
			),
			'tool_name' => 'delete_file',
		);
	}
}
