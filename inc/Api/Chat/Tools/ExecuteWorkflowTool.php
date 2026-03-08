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
		$this->registerTool( 'execute_workflow', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		$step_types_ability = wp_get_ability( 'datamachine/get-step-types' );
		$type_slugs         = array( 'fetch', 'ai', 'publish', 'update' );

		if ( $step_types_ability ) {
			$result = $step_types_ability->execute( array() );
			if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) && ! empty( $result['step_types'] ) ) {
				$type_slugs = array_keys( $result['step_types'] );
			}
		}

		$types_list = implode( '|', $type_slugs );

		$description = 'Execute an ephemeral workflow (not saved to database).

STEP FORMAT: {type: "' . $types_list . '", handler_slug, handler_config, user_message?, system_prompt?}

Use api_query GET /datamachine/v1/handlers/{slug} for handler_config fields.

EXAMPLE:
[
  {"type": "fetch", "handler_slug": "rss", "handler_config": {"feed_url": "..."}},
  {"type": "ai", "user_message": "Summarize for social media"},
  {"type": "publish", "handler_slug": "wordpress_publish", "handler_config": {"post_type": "post"}}
]';

		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $description,
			'parameters'  => array(
				'steps'   => array(
					'type'        => 'array',
					'required'    => true,
					'description' => 'Step objects: {type, handler_slug, handler_config}. AI steps: {type: "ai", user_message}.',
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Preview execution without creating posts. Returns what would be published instead of actually publishing.',
				),
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
