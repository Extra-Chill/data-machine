<?php
/**
 * Send Ping Tool
 *
 * Chat tool for sending pings to webhook endpoints.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.24.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class SendPing extends BaseTool {

	public function __construct() {
		$this->registerTool( 'send_ping', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'ability' => 'datamachine/agent-call' ) );
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
			'description' => 'Send a ping to one or more webhook URLs. Useful for triggering external agents or notifying services.',
			'parameters'  => array(
				'webhook_url' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'URL(s) to POST data to. Accepts a single URL or newline-separated string of URLs.',
				),
				'prompt'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional instructions for the receiving agent',
				),
				'flow_id'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Flow ID for context',
				),
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Pipeline ID for context',
				),
			),
		);
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
		$ability = wp_get_ability( 'datamachine/agent-call' );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'datamachine/agent-call ability not available',
				'tool_name' => 'send_ping',
			);
		}

		$input = array(
			'target'   => array(
				'type' => 'webhook',
				'id'   => $parameters['webhook_url'] ?? '',
			),
			'input'    => array(
				'task'    => $parameters['prompt'] ?? '',
				'context' => array(),
			),
			'delivery' => array(
				'mode' => 'fire_and_forget',
			),
		);

		if ( isset( $parameters['flow_id'] ) ) {
			$input['input']['context']['flow_id'] = $parameters['flow_id'];
		}
		if ( isset( $parameters['pipeline_id'] ) ) {
			$input['input']['context']['pipeline_id'] = $parameters['pipeline_id'];
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to send ping' );
			return $this->buildErrorResponse( $error, 'send_ping' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'send_ping',
		);
	}
}
