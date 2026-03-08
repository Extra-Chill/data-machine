<?php
/**
 * Delete Pipeline Step Tool
 *
 * Focused tool for removing steps from pipelines.
 * Delegates to Abilities API for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class DeletePipelineStep extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_pipeline_step', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Remove a step from a pipeline. This removes the step from all flows on the pipeline.',
			'parameters'  => array(
				'pipeline_id'      => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'ID of the pipeline containing the step',
				),
				'pipeline_step_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'ID of the pipeline step to remove',
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
		$ability = wp_get_ability( 'datamachine/delete-pipeline-step' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Delete pipeline step ability not available',
				'tool_name' => 'delete_pipeline_step',
			);
		}
		$result = $ability->execute( $parameters );

		return array(
			'success'   => $result['success'],
			'data'      => $result['success'] ? $result : null,
			'error'     => $result['error'] ?? null,
			'tool_name' => 'delete_pipeline_step',
		);
	}
}
