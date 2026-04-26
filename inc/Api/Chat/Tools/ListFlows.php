<?php
/**
 * List Flows Tool
 *
 * Chat tool for listing flows with optional filtering by pipeline ID or handler slug.
 * Wraps the datamachine/get-flows ability.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class ListFlows extends BaseTool {

	public function __construct() {
		$this->registerTool( 'list_flows', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'ability' => 'datamachine/get-flows' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'List flows with optional filtering by pipeline ID or handler slug. Supports pagination.',
			'parameters'  => array(
				'pipeline_id'  => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter flows by pipeline ID',
				),
				'handler_slug' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter flows using this handler slug (any step that uses this handler)',
				),
				'per_page'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of flows per page (default: 20, max: 100)',
				),
				'offset'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Offset for pagination (default: 0)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'datamachine/get-flows ability not found',
				'tool_name' => 'list_flows',
			);
		}

		$result = $ability->execute( $parameters );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to list flows' );
			return $this->buildErrorResponse( $error, 'list_flows' );
		}

		$response = array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_flows',
		);

		$flows       = $result['flows'] ?? array();
		$pipeline_id = $parameters['pipeline_id'] ?? null;

		if ( empty( $flows ) && ! empty( $pipeline_id ) ) {
			$response['guidance'] = array(
				'status'    => 'empty_result',
				'next_step' => 'Pipeline has no flows. Create a flow to configure workflow sources.',
				'tool_hint' => 'create_flow',
				'example'   => array(
					'tool'       => 'create_flow',
					'parameters' => array(
						'pipeline_id' => (int) $pipeline_id,
						'flow_name'   => 'New Flow',
					),
				),
			);
		}

		return $response;
	}
}
