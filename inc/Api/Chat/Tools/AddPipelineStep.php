<?php
/**
 * Add Pipeline Step Tool
 *
 * Focused tool for adding steps to existing pipelines.
 * Delegates to Abilities API for core logic.
 * Automatically syncs the new step to all flows on the pipeline.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class AddPipelineStep extends BaseTool {

	public function __construct() {
		$this->registerTool( 'add_pipeline_step', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	private static function getValidStepTypes(): array {
		$step_type_abilities = new StepTypeAbilities();
		return array_keys( $step_type_abilities->getAllStepTypes() );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$valid_types = self::getValidStepTypes();
		$types_list  = ! empty( $valid_types ) ? implode( ', ', $valid_types ) : 'fetch, ai, publish, update';
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Add a step to a pipeline. Automatically syncs to all flows on that pipeline.',
			'parameters'  => array(
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Pipeline ID to add the step to',
				),
				'step_type'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => "Type of step: {$types_list}",
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/add-pipeline-step' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Add pipeline step ability not available',
				'tool_name' => 'add_pipeline_step',
			);
		}
		$result = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'add_pipeline_step',
			);
		}

		return array(
			'success'   => $result['success'],
			'data'      => $result['success'] ? $result : null,
			'error'     => $result['error'] ?? null,
			'tool_name' => 'add_pipeline_step',
		);
	}
}
