<?php
/**
 * Reorder Pipeline Steps Tool
 *
 * Focused tool for reordering steps within a pipeline.
 * Delegates to Abilities API for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ReorderPipelineSteps extends BaseTool {

	public function __construct() {
		$this->registerTool( 'reorder_pipeline_steps', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Reorder steps within a pipeline.',
			'parameters'  => array(
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'ID of the pipeline',
				),
				'step_order'  => array(
					'type'        => 'array',
					'required'    => true,
					'description' => 'Array of step order objects: [{pipeline_step_id: "...", execution_order: 0}, ...]',
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
		$ability = wp_get_ability( 'datamachine/reorder-pipeline-steps' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Reorder pipeline steps ability not available',
				'tool_name' => 'reorder_pipeline_steps',
			);
		}
		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'reorder_pipeline_steps',
			);
		}

		return array(
			'success'   => $result['success'],
			'data'      => $result['success'] ? $result : null,
			'error'     => $result['error'] ?? null,
			'tool_name' => 'reorder_pipeline_steps',
		);
	}
}
