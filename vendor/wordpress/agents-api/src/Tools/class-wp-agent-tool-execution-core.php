<?php
/**
 * Generic tool-call mediation core.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and mediates product-neutral tool calls.
 */
class WP_Agent_Tool_Execution_Core {

	public const EXECUTOR_CLIENT   = WP_Agent_Tool_Declaration::EXECUTOR_CLIENT;

	/**
	 * Prepare a tool call for a caller-supplied execution adapter.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array<mixed>  $tool_parameters Runtime tool-call parameters.
	 * @param array<mixed>  $available_tools Tool declarations keyed by name.
	 * @param array<mixed>  $context         Host runtime context for this invocation.
	 * @return array<string, mixed> Prepared call or normalized error result.
	 */
	public function prepareWP_Agent_Tool_Call( string $tool_name, array $tool_parameters, array $available_tools, array $context = array() ): array {
		$provider_tool_name = $tool_name;
		$tool_name          = WP_Agent_Tool_Declaration::canonicalNameForProviderToolName( $tool_name, $available_tools );
		$tool_definition = $available_tools[ $tool_name ] ?? null;
		if ( ! is_array( $tool_definition ) ) {
			return array_merge(
				array( 'ready' => false ),
				WP_Agent_Tool_Result::error( $provider_tool_name, "Tool '{$provider_tool_name}' not found", array( 'error_type' => 'tool_not_found' ) )
			);
		}

		$runtime = WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $tool_definition['runtime'] ?? array() );

		$parameters = WP_Agent_Tool_Parameters::buildParameters( $tool_parameters, $context, $tool_definition );
		$validation = WP_Agent_Tool_Parameters::validateRequiredParameters( $parameters, $tool_definition );
		if ( ! $validation['valid'] ) {
			return array_merge(
				array( 'ready' => false ),
				WP_Agent_Tool_Result::error(
					$tool_name,
					sprintf( 'Tool "%s" requires the following parameters: %s.', $tool_name, implode( ', ', $validation['missing'] ) ),
					array(
						'error_type'         => 'missing_required_parameters',
						'missing_parameters' => $validation['missing'],
					),
					$runtime
				)
			);
		}

		$tool_call = array(
			'tool_name'  => $tool_name,
			'parameters' => $parameters,
			'metadata'   => array(
				'source'             => $tool_definition['source'] ?? WP_Agent_Tool_Declaration::sourceFromName( $tool_name ),
				'provider_tool_name' => $provider_tool_name,
			),
		);

		$tool_call_id = $context['tool_call_id'] ?? '';
		if ( is_string( $tool_call_id ) && '' !== trim( $tool_call_id ) ) {
			$tool_call['id'] = trim( $tool_call_id );
		}

		return array(
			'ready'      => true,
			'tool_call'  => WP_Agent_Tool_Call::normalize( $tool_call ),
			'tool_def'   => $tool_definition,
		);
	}

	/**
	 * Execute a prepared tool call through a caller-supplied adapter.
	 *
	 * @param array<mixed>  $tool_call       Prepared tool call.
	 * @param array<mixed>  $tool_definition Normalized tool declaration.
	 * @param WP_Agent_Tool_Executor $executor Host runtime execution adapter.
	 * @param array<mixed>  $context         Host runtime context for this invocation.
	 * @return array<string, mixed> Normalized execution result.
	 */
	public function executePreparedTool( array $tool_call, array $tool_definition, WP_Agent_Tool_Executor $executor, array $context = array() ): array {
		$tool_call = WP_Agent_Tool_Call::normalize( $tool_call );
		try {
			$result = $executor->executeWP_Agent_Tool_Call( $tool_call, $tool_definition, $context );
		} catch ( \Throwable $throwable ) {
			$tool_name = is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '';
			return WP_Agent_Tool_Result::error(
				$tool_name,
				$throwable->getMessage(),
				array( 'error_type' => 'executor_exception' ),
				WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $tool_definition['runtime'] ?? array() )
			);
		}

		$runtime = array_merge(
			WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $tool_definition['runtime'] ?? array() ),
			WP_Agent_Tool_Declaration::normalizeRuntimeMetadata( $result['runtime'] ?? array() )
		);

		if ( ! array_key_exists( 'success', $result ) ) {
			$result = array(
				'success'   => true,
				'tool_name' => is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '',
				'result'    => $result,
			);
		}

		$result['tool_name'] = is_string( $result['tool_name'] ?? null ) ? $result['tool_name'] : ( is_string( $tool_call['tool_name'] ?? null ) ? $tool_call['tool_name'] : '' );
		if ( ! empty( $runtime ) ) {
			$result['runtime'] = $runtime;
		}

		return WP_Agent_Tool_Result::normalize( $result );
	}

	/**
	 * Prepare and execute a tool call through a caller-supplied adapter.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param array<mixed>  $tool_parameters Runtime tool-call parameters.
	 * @param array<mixed>  $available_tools Tool declarations keyed by name.
	 * @param WP_Agent_Tool_Executor $executor Host runtime execution adapter.
	 * @param array<mixed>  $context         Host runtime context for this invocation.
	 * @return array<string, mixed> Normalized execution result.
	 */
	public function executeTool( string $tool_name, array $tool_parameters, array $available_tools, WP_Agent_Tool_Executor $executor, array $context = array() ): array {
		$prepared = $this->prepareWP_Agent_Tool_Call( $tool_name, $tool_parameters, $available_tools, $context );
		if ( empty( $prepared['ready'] ) ) {
			unset( $prepared['ready'] );
			return $prepared;
		}

		$tool_call = $prepared['tool_call'] ?? null;
		$tool_def  = $prepared['tool_def'] ?? null;
		if ( ! is_array( $tool_call ) || ! is_array( $tool_def ) ) {
			return WP_Agent_Tool_Result::error( $tool_name, 'Prepared tool call is invalid.', array( 'error_type' => 'invalid_prepared_tool_call' ) );
		}

		return $this->executePreparedTool( $tool_call, $tool_def, $executor, $context );
	}
}
