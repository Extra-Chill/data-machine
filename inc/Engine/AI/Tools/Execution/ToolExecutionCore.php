<?php
/**
 * Data Machine tool execution adapter.
 *
 * Agents API owns product-neutral tool lookup, parameter assembly, required
 * parameter validation, and result normalization. This adapter owns only Data
 * Machine's concrete execution paths: WordPress Abilities and Data Machine
 * product-local class/method tools.
 *
 * @package DataMachine\Engine\AI\Tools\Execution
 */

namespace DataMachine\Engine\AI\Tools\Execution;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use DataMachine\Core\AbilityResult;
use DataMachine\Engine\AI\Tools\AbilityToolAdapter;

defined( 'ABSPATH' ) || exit;

class ToolExecutionCore implements WP_Agent_Tool_Executor {

	/**
	 * Execute a prepared Agents API tool call.
	 *
	 * @param array $tool_call       Normalized prepared tool call.
	 * @param array $tool_definition Tool declaration selected for the call.
	 * @param array $context         Host runtime context for this invocation.
	 * @return array Raw or normalized tool execution result.
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $context );

		$tool_name  = (string) ( $tool_call['tool_name'] ?? '' );
		$parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();

		if ( ! empty( $tool_definition['execution_ability'] ) || ! empty( $tool_definition['ability_map'] ) ) {
			return AbilityToolAdapter::execute( $tool_name, $parameters, $tool_definition );
		}

		if ( empty( $tool_definition['class'] ) && ( ! empty( $tool_definition['ability'] ) || ! empty( $tool_definition['abilities'] ) ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' declares ability permission metadata but does not declare 'execution_ability' or a class/method wrapper.", $tool_name ),
				'tool_name' => $tool_name,
				'metadata'  => array(
					'error_type' => 'ambiguous_tool_execution_contract',
				),
			);
		}

		return $this->executeClassMethodTool( $tool_name, $parameters, $tool_definition );
	}

	/**
	 * Execute a class/method tool definition.
	 *
	 * @param string $tool_name       Tool name.
	 * @param array  $parameters      Complete tool parameters.
	 * @param array  $tool_definition Tool definition.
	 * @return array Tool execution result.
	 */
	private function executeClassMethodTool( string $tool_name, array $parameters, array $tool_definition ): array {
		if ( empty( $tool_definition['class'] ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
				'tool_name' => $tool_name,
			);
		}

		$class_name = $tool_definition['class'];
		if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
			$class_label = is_scalar( $class_name ) ? (string) $class_name : '(invalid)';
			return array(
				'success'   => false,
				'error'     => "Tool class '{$class_label}' not found",
				'tool_name' => $tool_name,
			);
		}

		$method = $tool_definition['method'] ?? null;
		if ( ! is_string( $method ) || '' === $method || ! method_exists( $class_name, $method ) ) {
			$method_label = is_scalar( $method ) ? (string) $method : '(invalid)';
			return array(
				'success'   => false,
				'error'     => sprintf(
					"Tool '%s' definition is missing required 'method' key or method '%s' does not exist on class '%s'.",
					$tool_name,
					'' !== $method_label ? $method_label : '(none)',
					$class_name
				),
				'tool_name' => $tool_name,
			);
		}

		$tool_handler = new $class_name();
		return AbilityResult::normalize_tool_envelope( $tool_handler->$method( $parameters, $tool_definition ), $tool_name );
	}
}
