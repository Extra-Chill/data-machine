<?php
/**
 * System Health Check Tool
 *
 * Chat tool for running unified health diagnostics.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.24.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class SystemHealthCheck extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'system_health_check', array( $this, 'getToolDefinition' ) );
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
			'description' => 'Run unified health diagnostics for Data Machine and extensions. Returns status of various system components.',
			'parameters'  => array(
				'types'   => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Check types to run. Use "all" for all default checks, or specific type IDs. Omit for all checks.',
				),
				'options' => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Type-specific options (scope, limit, url, etc.)',
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
		$ability = wp_get_ability( 'datamachine/system-health-check' );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'datamachine/system-health-check ability not available',
				'tool_name' => 'system_health_check',
			);
		}

		$input = array();

		if ( isset( $parameters['types'] ) ) {
			$input['types'] = $parameters['types'];
		}
		if ( isset( $parameters['options'] ) ) {
			$input['options'] = $parameters['options'];
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Health check failed' );
			return $this->buildErrorResponse( $error, 'system_health_check' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'system_health_check',
		);
	}
}
