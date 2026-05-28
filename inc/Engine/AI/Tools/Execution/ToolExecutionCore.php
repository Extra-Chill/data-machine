<?php
/**
 * Data Machine tool execution adapter.
 *
 * Agents API owns product-neutral tool lookup, parameter assembly, required
 * parameter validation, and result normalization. This adapter owns only Data
 * Machine's concrete execution paths: WordPress Abilities and the legacy
 * class/method handler contract.
 *
 * @package DataMachine\Engine\AI\Tools\Execution
 */

namespace DataMachine\Engine\AI\Tools\Execution;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use DataMachine\Core\AbilityResult;

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

		if ( self::isAbilityOnlyTool( $tool_definition ) ) {
			return $this->executeAbilityTool( $tool_name, $parameters, $tool_definition );
		}

		return $this->executeClassMethodTool( $tool_name, $parameters, $tool_definition );
	}

	/**
	 * Whether a tool should execute directly through a linked WordPress Ability.
	 *
	 * @param array $tool_definition Tool definition.
	 * @return bool
	 */
	private static function isAbilityOnlyTool( array $tool_definition ): bool {
		return ! empty( $tool_definition['ability'] )
			&& empty( $tool_definition['class'] )
			&& empty( $tool_definition['method'] );
	}

	/**
	 * Execute a tool through its linked WordPress Ability.
	 *
	 * @param string $tool_name       Tool name.
	 * @param array  $parameters      Complete tool parameters.
	 * @param array  $tool_definition Tool definition.
	 * @return array Tool execution result.
	 */
	private function executeAbilityTool( string $tool_name, array $parameters, array $tool_definition ): array {
		$ability_slug = (string) $tool_definition['ability'];
		if ( ! class_exists( '\\WP_Abilities_Registry' ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references ability '%s', but the WordPress Abilities API is not available.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'metadata'  => array( 'ability' => $ability_slug ),
			);
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( method_exists( $registry, 'is_registered' ) && ! $registry->is_registered( $ability_slug ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'metadata'  => array( 'ability' => $ability_slug ),
			);
		}

		$ability = $registry->get_registered( $ability_slug );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'metadata'  => array( 'ability' => $ability_slug ),
			);
		}

		$permission = $ability->check_permissions( $parameters );
		if ( is_wp_error( $permission ) ) {
			return array(
				'success'   => false,
				'error'     => $permission->get_error_message(),
				'tool_name' => $tool_name,
				'metadata'  => array( 'ability' => $ability_slug ),
			);
		}

		if ( true !== $permission ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' is not permitted by ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'metadata'  => array( 'ability' => $ability_slug ),
			);
		}

		return $this->normalizeDataMachineResult(
			AbilityResult::normalize_tool_result( $ability->execute( $parameters ), $tool_name, $ability_slug ),
			$tool_name,
			array( 'ability' => $ability_slug )
		);
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
		return $this->normalizeDataMachineResult( $tool_handler->$method( $parameters, $tool_definition ), $tool_name );
	}

	/**
	 * Put legacy Data Machine handler output into the Agents API result envelope.
	 *
	 * @param array  $result    Raw handler result.
	 * @param string $tool_name Tool name.
	 * @param array  $metadata  Additional metadata.
	 * @return array Tool execution result.
	 */
	private function normalizeDataMachineResult( array $result, string $tool_name, array $metadata = array() ): array {
		$result['tool_name'] = is_string( $result['tool_name'] ?? null ) && '' !== $result['tool_name'] ? $result['tool_name'] : $tool_name;
		if ( ! isset( $result['success'] ) ) {
			$result['success'] = true;
		}

		if ( ! empty( $metadata ) ) {
			$result['metadata'] = array_merge( $metadata, is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array() );
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		if ( ! array_key_exists( 'result', $result ) ) {
			if ( array_key_exists( 'data', $result ) ) {
				$result['result'] = $result['data'];
			} else {
				$payload = $result;
				unset( $payload['success'], $payload['tool_name'], $payload['metadata'], $payload['runtime'] );
				$result['result'] = $payload;
			}
		}

		return $result;
	}
}
