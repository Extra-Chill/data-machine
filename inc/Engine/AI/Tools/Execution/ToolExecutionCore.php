<?php
/**
 * Generic AI tool execution core.
 *
 * Handles the tool definition lookup, required-parameter validation, parameter
 * assembly, and direct ability/legacy tool execution. Data Machine product
 * decorators such as action staging and post-origin tracking stay in
 * ToolExecutor.
 *
 * @package DataMachine\Engine\AI\Tools\Execution
 */

namespace DataMachine\Engine\AI\Tools\Execution;

use DataMachine\Engine\AI\Tools\ToolParameters;

defined( 'ABSPATH' ) || exit;

class ToolExecutionCore {

	/**
	 * Prepare a tool call for direct execution.
	 *
	 * @param string $tool_name       Tool name to execute.
	 * @param array  $tool_parameters Parameters from AI.
	 * @param array  $available_tools Available tools array.
	 * @param array  $payload         Step payload.
	 * @return array Prepared invocation or normalized error result.
	 */
	public function prepareToolCall( string $tool_name, array $tool_parameters, array $available_tools, array $payload ): array {
		$tool_def = $available_tools[ $tool_name ] ?? null;
		if ( ! $tool_def ) {
			return array(
				'ready'     => false,
				'success'   => false,
				'error'     => "Tool '{$tool_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$validation = self::validateRequiredParameters( $tool_parameters, $tool_def );
		if ( ! $validation['valid'] ) {
			return array(
				'ready'     => false,
				'success'   => false,
				'error'     => sprintf(
					'%s requires the following parameters: %s. Please provide these parameters and try again.',
					ucwords( str_replace( '_', ' ', $tool_name ) ),
					implode( ', ', $validation['missing'] )
				),
				'tool_name' => $tool_name,
			);
		}

		return array(
			'ready'      => true,
			'tool_def'   => $tool_def,
			'parameters' => ToolParameters::buildParameters(
				$tool_parameters,
				$payload,
				$tool_def
			),
		);
	}

	/**
	 * Execute a prepared tool definition directly.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array  $parameters Complete tool parameters.
	 * @param array  $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	public function executePreparedTool( string $tool_name, array $parameters, array $tool_def ): array {
		if ( self::isAbilityOnlyTool( $tool_def ) ) {
			return $this->executeAbilityTool( $tool_name, $parameters, $tool_def );
		}

		return $this->executeLegacyTool( $tool_name, $parameters, $tool_def );
	}

	/**
	 * Execute tool with generic parameter validation and result normalization.
	 *
	 * @param string $tool_name       Tool name to execute.
	 * @param array  $tool_parameters Parameters from AI.
	 * @param array  $available_tools Available tools array.
	 * @param array  $payload         Step payload.
	 * @return array Tool execution result.
	 */
	public function executeTool( string $tool_name, array $tool_parameters, array $available_tools, array $payload ): array {
		$prepared = $this->prepareToolCall( $tool_name, $tool_parameters, $available_tools, $payload );
		if ( empty( $prepared['ready'] ) ) {
			unset( $prepared['ready'] );
			return $prepared;
		}

		return $this->executePreparedTool( $tool_name, $prepared['parameters'], $prepared['tool_def'] );
	}

	/**
	 * Validate that all required parameters are present.
	 *
	 * @param array $tool_parameters Parameters from AI.
	 * @param array $tool_def        Tool definition with parameter specs.
	 * @return array Validation result with 'valid', 'required', and 'missing' keys.
	 */
	public static function validateRequiredParameters( array $tool_parameters, array $tool_def ): array {
		$required = array();
		$missing  = array();

		$param_defs = $tool_def['parameters'] ?? array();

		foreach ( $param_defs as $param_name => $param_config ) {
			if ( ! is_array( $param_config ) ) {
				continue;
			}

			if ( ! empty( $param_config['required'] ) ) {
				$required[] = $param_name;

				if ( ! isset( $tool_parameters[ $param_name ] ) || '' === $tool_parameters[ $param_name ] ) {
					$missing[] = $param_name;
				}
			}
		}

		return array(
			'valid'    => empty( $missing ),
			'required' => $required,
			'missing'  => $missing,
		);
	}

	/**
	 * Whether a tool should execute directly through a linked WordPress Ability.
	 *
	 * @param array $tool_def Tool definition.
	 * @return bool
	 */
	private static function isAbilityOnlyTool( array $tool_def ): bool {
		return ! empty( $tool_def['ability'] )
			&& empty( $tool_def['class'] )
			&& empty( $tool_def['method'] );
	}

	/**
	 * Execute a tool through its linked WordPress Ability.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array  $parameters Complete tool parameters.
	 * @param array  $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	private function executeAbilityTool( string $tool_name, array $parameters, array $tool_def ): array {
		$ability_slug = (string) $tool_def['ability'];
		if ( ! class_exists( '\\WP_Abilities_Registry' ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references ability '%s', but the WordPress Abilities API is not available.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$registry = \WP_Abilities_Registry::get_instance();
		$ability  = $registry->get_registered( $ability_slug );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' references missing ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$permission = $ability->check_permissions( $parameters );
		if ( is_wp_error( $permission ) ) {
			return array(
				'success'   => false,
				'error'     => $permission->get_error_message(),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		if ( true !== $permission ) {
			return array(
				'success'   => false,
				'error'     => sprintf( "Tool '%s' is not permitted by ability '%s'.", $tool_name, $ability_slug ),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		$result = $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => $tool_name,
				'ability'   => $ability_slug,
			);
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'ability'   => $ability_slug,
			'result'    => $result,
		);
	}

	/**
	 * Execute a legacy class/method tool definition.
	 *
	 * @param string $tool_name  Tool name.
	 * @param array  $parameters Complete tool parameters.
	 * @param array  $tool_def   Tool definition.
	 * @return array Tool execution result.
	 */
	private function executeLegacyTool( string $tool_name, array $parameters, array $tool_def ): array {
		if ( ! isset( $tool_def['class'] ) || empty( $tool_def['class'] ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
				'tool_name' => $tool_name,
			);
		}

		$class_name = $tool_def['class'];
		if ( ! class_exists( $class_name ) ) {
			return array(
				'success'   => false,
				'error'     => "Tool class '{$class_name}' not found",
				'tool_name' => $tool_name,
			);
		}

		$method = $tool_def['method'] ?? null;
		if ( ! $method || ! method_exists( $class_name, $method ) ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					"Tool '%s' definition is missing required 'method' key or method '%s' does not exist on class '%s'.",
					$tool_name,
					$method ?? '(none)',
					$class_name
				),
				'tool_name' => $tool_name,
			);
		}

		$tool_handler = new $class_name();
		return $tool_handler->$method( $parameters, $tool_def );
	}
}
