<?php
/**
 * Stable runtime provenance for AI provider calls.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class RuntimeProvenance {

	/**
	 * Build a redacted provenance object for benchmark/eval replay artifacts.
	 *
	 * @param array  $result  Normalized conversation result.
	 * @param array  $payload Data Machine loop payload.
	 * @param string $provider Provider identifier.
	 * @param string $model Model identifier.
	 * @param array  $modes Execution modes.
	 * @return array<string,mixed>
	 */
	public static function fromConversationResult( array $result, array $payload, string $provider, string $model, array $modes ): array {
		$request_metadata = is_array( $result['request_metadata'] ?? null ) ? $result['request_metadata'] : array();
		$usage            = is_array( $result['usage'] ?? null ) ? $result['usage'] : array();
		$error_message    = isset( $result['error'] ) ? (string) $result['error'] : '';
		$metadata         = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$datamachine      = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();

		$provenance = array(
			'schema_version'  => 1,
			'provider'        => array_filter(
				array(
					'id'        => (string) ( $request_metadata['provider'] ?? $provider ),
					'source'    => 'wp-ai-client',
					'transport' => is_array( $request_metadata['transport'] ?? null ) ? 'wp-ai-client-http' : 'wp-ai-client',
				),
				static fn( $value ) => null !== $value && '' !== $value
			),
			'model'           => array_filter(
				array(
					'id'            => (string) ( $request_metadata['model'] ?? $model ),
					'config'        => is_array( $request_metadata['model_config'] ?? null ) ? $request_metadata['model_config'] : array(),
					'config_source' => (string) ( $request_metadata['model_config_source'] ?? 'default' ),
				),
				static fn( $value ) => array() !== $value && null !== $value && '' !== $value
			),
			'mode'            => implode( ',', $modes ),
			'modes'           => $modes,
			'identifiers'     => self::identifiers( $payload ),
			'input'           => array_filter(
				array(
					'prompt_sha256' => $request_metadata['prompt_sha256'] ?? null,
					'input_sha256'  => $request_metadata['input_sha256'] ?? null,
					'message_count' => $request_metadata['message_count'] ?? null,
				),
				static fn( $value ) => null !== $value && '' !== $value
			),
			'tools'           => array_filter(
				array(
					'policy_sha256' => $request_metadata['tool_policy_sha256'] ?? null,
					'count'         => $request_metadata['tools']['count'] ?? null,
					'policy_inputs' => self::tool_policy_inputs( $payload ),
					'names'         => self::tool_names( $result ),
				),
				static fn( $value ) => array() !== $value && null !== $value && '' !== $value
			),
			'usage'           => $usage,
			'attempts'        => array(
				'retry_count'    => 0,
				'fallback_count' => 0,
				'retries'        => array(),
				'fallbacks'      => array(),
			),
			'status'          => array_filter(
				array(
					'status'        => (string) ( $result['status'] ?? ( isset( $result['error'] ) ? 'error' : 'completed' ) ),
					'completed'     => (bool) ( $datamachine['completed'] ?? false ),
					'finish_reason' => self::finish_reason( $result ),
					'cancelled'     => 'cancelled' === ( $result['status'] ?? '' ),
					'timed_out'     => 'timeout' === ( $result['status'] ?? '' ),
				),
				static fn( $value ) => null !== $value && '' !== $value
			),
			'provider_errors' => '' !== $error_message ? array(
				array_filter(
					array(
						'code'    => isset( $result['error_code'] ) ? (string) $result['error_code'] : null,
						'message' => $error_message,
					),
					static fn( $value ) => null !== $value && '' !== $value
				),
			) : array(),
			'request'         => self::request_shape( $request_metadata ),
		);

		if ( isset( $result['tool_audit_events'] ) && is_array( $result['tool_audit_events'] ) ) {
			$provenance['tool_audit_events'] = $result['tool_audit_events'];
		}

		$tool_trace = self::tool_trace( $result );
		if ( ! empty( $tool_trace ) ) {
			$provenance['tool_trace'] = $tool_trace;
		}

		return $provenance;
	}

	/** @return array<string,mixed> */
	private static function identifiers( array $payload ): array {
		$keys        = array( 'agent_id', 'agent_slug', 'agent_modes', 'pipeline_id', 'flow_id', 'flow_step_id', 'step_id', 'job_id', 'session_id', 'transcript_session_id' );
		$identifiers = array();
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ] ) && '' !== $payload[ $key ] ) {
				$identifiers[ $key ] = $payload[ $key ];
			}
		}

		return $identifiers;
	}

	/** @return array<int,string> */
	private static function tool_names( array $result ): array {
		$names = array();
		foreach ( $result['tool_execution_results'] ?? array() as $tool_result ) {
			if ( is_array( $tool_result ) && isset( $tool_result['tool_name'] ) && is_string( $tool_result['tool_name'] ) ) {
				$names[] = $tool_result['tool_name'];
			}
		}

		return array_values( array_unique( $names ) );
	}

	/** @return array<string,mixed> */
	private static function tool_policy_inputs( array $payload ): array {
		$inputs = array(
			'mode'                     => isset( $payload['agent_modes'] ) && is_array( $payload['agent_modes'] ) ? implode( ',', $payload['agent_modes'] ) : null,
			'modes'                    => is_array( $payload['agent_modes'] ?? null ) ? array_values( $payload['agent_modes'] ) : null,
			'agent_id'                 => $payload['agent_id'] ?? null,
			'agent_slug'               => $payload['agent_slug'] ?? null,
			'pipeline_step_id'         => $payload['step_id'] ?? null,
			'configured_handler_slugs' => is_array( $payload['configured_handler_slugs'] ?? null ) ? array_values( $payload['configured_handler_slugs'] ) : null,
			'tool_runtime_rules'       => is_array( $payload['tool_runtime_rules'] ?? null ) ? $payload['tool_runtime_rules'] : null,
		);

		return array_filter(
			$inputs,
			static fn( $value ) => null !== $value && '' !== $value && array() !== $value
		);
	}

	private static function finish_reason( array $result ): ?string {
		if ( isset( $result['finish_reason'] ) && is_string( $result['finish_reason'] ) ) {
			return $result['finish_reason'];
		}
		$request_metadata = is_array( $result['request_metadata'] ?? null ) ? $result['request_metadata'] : array();
		if ( isset( $request_metadata['response']['finish_reason'] ) && is_string( $request_metadata['response']['finish_reason'] ) ) {
			return $request_metadata['response']['finish_reason'];
		}
		$metadata    = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		$datamachine = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		if ( ! empty( $datamachine['max_turns_reached'] ) || 'budget_exceeded' === ( $result['status'] ?? '' ) ) {
			return 'max_turns';
		}
		if ( 'interrupted' === ( $result['status'] ?? '' ) ) {
			return 'interrupted';
		}
		if ( isset( $result['error'] ) ) {
			return 'provider_error';
		}
		return ! empty( $datamachine['completed'] ) ? 'stop' : null;
	}

	/** @return array<string,mixed> */
	private static function request_shape( array $request_metadata ): array {
		return array_filter(
			array(
				'request_json_bytes'  => $request_metadata['request_json_bytes'] ?? null,
				'messages_json_bytes' => $request_metadata['messages_json_bytes'] ?? null,
				'tools_json_bytes'    => $request_metadata['tools_json_bytes'] ?? null,
				'transport'           => is_array( $request_metadata['transport'] ?? null ) ? $request_metadata['transport'] : null,
			),
			static fn( $value ) => null !== $value && array() !== $value
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function tool_trace( array $result ): array {
		$trace = array();
		foreach ( $result['tool_execution_results'] ?? array() as $tool_result ) {
			if ( is_array( $tool_result ) && is_array( $tool_result['trace'] ?? null ) ) {
				$trace[] = $tool_result['trace'];
			}
		}

		return $trace;
	}
}
