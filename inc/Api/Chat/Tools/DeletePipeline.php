<?php
/**
 * Delete Pipeline Tool
 *
 * Focused tool for deleting pipelines.
 * Uses PipelineAbilities API primitive for centralized logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class DeletePipeline extends BaseTool {

	public function __construct() {
		$this->registerTool( 'delete_pipeline', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Delete a pipeline and all its associated flows.',
			'parameters'  => array(
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'ID of the pipeline to delete',
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
		$pipeline_id = $parameters['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success'   => false,
				'error'     => 'pipeline_id is required and must be a positive integer',
				'tool_name' => 'delete_pipeline',
			);
		}

		$pipeline_id = (int) $pipeline_id;

		$ability = wp_get_ability( 'datamachine/delete-pipeline' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Delete pipeline ability not available',
				'tool_name' => 'delete_pipeline',
			);
		}
		$result = $ability->execute( array( 'pipeline_id' => $pipeline_id ) );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'delete_pipeline',
			);
		}

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Failed to delete pipeline',
				'tool_name' => 'delete_pipeline',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $result['pipeline_name'] ?? '',
				'deleted_flows' => $result['deleted_flows'] ?? 0,
				'message'       => $result['message'] ?? 'Pipeline and all associated flows deleted.',
			),
			'tool_name' => 'delete_pipeline',
		);
	}
}
