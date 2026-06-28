<?php
/**
 * Execute Workflow Tool
 *
 * Chat tool for executing content automation workflows.
 * Passes workflow steps directly to the Execute API endpoint.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.3.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ExecuteWorkflowTool extends BaseTool {

	public function __construct() {
		$this->registerTool( 'execute_workflow', array( $this, 'getToolDefinition' ), array( 'pipeline_editor' ), array( 'ability' => 'datamachine/execute-workflow' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$step_types_ability = wp_get_ability( 'datamachine/get-step-types' );
		$type_slugs         = array( 'fetch', 'ai', 'publish', 'upsert' );

		if ( $step_types_ability ) {
			$result = $step_types_ability->execute( array() );
			if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) && ! empty( $result['step_types'] ) ) {
				$type_slugs = array_keys( $result['step_types'] );
			}
		}

		$types_list = implode( '|', $type_slugs );

		$description = 'Execute an ephemeral workflow (not saved to database).

STEP FORMAT: {step_type: "' . $types_list . '", handler_slugs?, handler_configs?, flow_step_settings?, user_message?, system_prompt?, agent_modes?}

Use api_query GET /datamachine/v1/handlers/{slug} for handler_configs fields.

EXAMPLE:
[
  {"step_type": "fetch", "handler_slugs": ["rss"], "handler_configs": {"rss": {"feed_url": "..."}}},
  {"step_type": "ai", "user_message": "Summarize for social media"},
  {"step_type": "publish", "handler_slugs": ["wordpress_publish"], "handler_configs": {"wordpress_publish": {"post_type": "post"}}}
]';

		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $description,
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'steps'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'Step objects: {step_type, handler_slugs, handler_configs}. AI steps: {step_type: "ai", user_message}.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Preview execution without creating posts. Returns what would be published instead of actually publishing.',
					),
				),
				'required'   => array( 'steps' ),
			),
		);
	}

	/**
	 * Execute the workflow.
	 *
	 * @param array $parameters Tool parameters containing steps
	 * @param array $tool_def Tool definition (unused)
	 * @return array Execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$steps   = $parameters['steps'] ?? array();
		$dry_run = $parameters['dry_run'] ?? false;

		if ( empty( $steps ) ) {
			return array(
				'success'   => false,
				'error'     => 'Workflow must contain at least one step',
				'tool_name' => 'execute_workflow',
			);
		}

		$ability = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Execute workflow ability not available',
				'tool_name' => 'execute_workflow',
			);
		}

		$input = array(
			'workflow' => array( 'steps' => $steps ),
		);

		if ( $dry_run ) {
			$input['dry_run'] = true;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'execute_workflow',
			);
		}

		if ( ! ( $result['success'] ?? false ) ) {
			do_action(
				'datamachine_log',
				'error',
				'ExecuteWorkflowTool: Execution failed',
				array(
					'error' => $result['error'] ?? 'Unknown error',
					'steps' => $steps,
				)
			);
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Execution failed',
				'tool_name' => 'execute_workflow',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'execute_workflow',
		);
	}
}
