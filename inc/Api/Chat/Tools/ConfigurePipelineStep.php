<?php
/**
 * Configure Pipeline Step Tool
 *
 * Tool for configuring pipeline-level AI step settings including
 * system prompt, provider, model, and enabled tools.
 * Delegates to Abilities API for core logic.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ConfigurePipelineStep extends BaseTool {

	public function __construct() {
		$this->registerTool( 'configure_pipeline_step', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Configure pipeline-level AI step settings including system prompt, provider, model, and enabled tools. Use this for AI steps after creating a pipeline. For flow-level settings (handler, handler_config, user_message), use configure_flow_steps instead.',
			'parameters'  => array(
				'pipeline_step_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Pipeline step ID to configure (e.g., "123_uuid4")',
				),
				'system_prompt'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'System prompt for the AI step - defines the AI persona and instructions',
				),
				'provider'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'AI provider slug (e.g., "anthropic", "openai")',
				),
				'model'            => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'AI model identifier (e.g., "claude-sonnet-4", "gpt-4o")',
				),
				'disabled_tools'   => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of tool slugs to disable for this AI step',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/update-pipeline-step' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Update pipeline step ability not available',
				'tool_name' => 'configure_pipeline_step',
			);
		}

		$result = $ability->execute( $parameters );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to configure pipeline step' );
			return $this->buildErrorResponse( $error, 'configure_pipeline_step' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'configure_pipeline_step',
		);
	}
}
