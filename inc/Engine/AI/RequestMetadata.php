<?php
/**
 * Compact AI request metadata builder.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class RequestMetadata {

	/**
	 * Build compact request composition metadata.
	 *
	 * @param array  $request            Provider request array.
	 * @param array  $structured_tools   Structured tool definitions.
	 * @param array  $directive_metadata Directive output metadata from PromptBuilder.
	 * @param string $provider           Provider slug.
	 * @param string $model              Model name.
	 * @param string $mode               Execution mode.
	 * @return array<string,mixed>
	 */
	public static function build(
		array $request,
		array $structured_tools,
		array $directive_metadata,
		string $provider,
		string $model,
		string $mode
	): array {
		$messages_json = wp_json_encode( $request['messages'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$tools_json    = wp_json_encode( $structured_tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$request_json  = wp_json_encode( $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return array(
			'provider'            => $provider,
			'model'               => $model,
			'mode'                => $mode,
			'message_count'       => count( $request['messages'] ?? array() ),
			'request_json_bytes'  => strlen( (string) $request_json ),
			'messages_json_bytes' => strlen( (string) $messages_json ),
			'tools_json_bytes'    => strlen( (string) $tools_json ),
			'directives'          => $directive_metadata,
			'memory_files'        => self::extract_memory_files( $directive_metadata ),
			'tools'               => array(
				'count'      => count( $structured_tools ),
				'json_bytes' => strlen( (string) $tools_json ),
				'largest'    => self::largest_tools( $structured_tools ),
			),
		);
	}

	/**
	 * Emit a structured warning when request-size thresholds are crossed.
	 *
	 * @param array $metadata Request metadata.
	 * @param array $payload  Loop payload for job/step context.
	 * @return void
	 */
	public static function warn_if_oversized( array $metadata, array $payload ): void {
		$thresholds = self::warning_thresholds();
		$warnings   = array();

		self::maybe_add_warning( $warnings, 'request_json_bytes', $metadata['request_json_bytes'] ?? 0, $thresholds['request_json_bytes'] );
		self::maybe_add_warning( $warnings, 'messages_json_bytes', $metadata['messages_json_bytes'] ?? 0, $thresholds['messages_json_bytes'] );
		self::maybe_add_warning( $warnings, 'tools_json_bytes', $metadata['tools_json_bytes'] ?? 0, $thresholds['tools_json_bytes'] );

		$large_directives = array();
		foreach ( $metadata['directives'] ?? array() as $directive ) {
			if ( (int) ( $directive['json_bytes'] ?? 0 ) > $thresholds['directive_json_bytes'] ) {
				$large_directives[] = $directive;
			}
		}
		if ( ! empty( $large_directives ) ) {
			$warnings['directives'] = array(
				'threshold' => $thresholds['directive_json_bytes'],
				'largest'   => array_slice( $large_directives, 0, 5 ),
			);
		}

		$large_tools = array();
		foreach ( $metadata['tools']['largest'] ?? array() as $tool ) {
			if ( (int) ( $tool['json_bytes'] ?? 0 ) > $thresholds['tool_json_bytes'] ) {
				$large_tools[] = $tool;
			}
		}
		if ( ! empty( $large_tools ) ) {
			$warnings['tools'] = array(
				'threshold' => $thresholds['tool_json_bytes'],
				'largest'   => array_slice( $large_tools, 0, 5 ),
			);
		}

		if ( empty( $warnings ) ) {
			return;
		}

		$largest_directives = $metadata['directives'] ?? array();
		usort(
			$largest_directives,
			fn( $a, $b ) => ( $b['json_bytes'] ?? 0 ) <=> ( $a['json_bytes'] ?? 0 )
		);

		do_action(
			'datamachine_log',
			'warning',
			'AI request size guardrail warning',
			array_filter(
				array(
					'job_id'               => $payload['job_id'] ?? null,
					'flow_step_id'         => $payload['flow_step_id'] ?? null,
					'provider'             => $metadata['provider'] ?? null,
					'model'                => $metadata['model'] ?? null,
					'mode'                 => $metadata['mode'] ?? null,
					'request_json_bytes'   => $metadata['request_json_bytes'] ?? null,
					'messages_json_bytes' => $metadata['messages_json_bytes'] ?? null,
					'tools_json_bytes'     => $metadata['tools_json_bytes'] ?? null,
					'message_count'        => $metadata['message_count'] ?? null,
					'tool_count'           => $metadata['tools']['count'] ?? null,
					'largest_directives'   => array_slice( $largest_directives, 0, 5 ),
					'largest_tools'        => array_slice( $metadata['tools']['largest'] ?? array(), 0, 5 ),
					'largest_memory_files' => array_slice( $metadata['memory_files'] ?? array(), 0, 5 ),
					'warnings'             => $warnings,
				),
				fn( $value ) => null !== $value
			)
		);
	}

	/**
	 * Resolve filterable warning thresholds.
	 *
	 * Return 0 from a filter to disable that threshold.
	 *
	 * @return array<string,int>
	 */
	private static function warning_thresholds(): array {
		return array(
			'request_json_bytes'   => self::threshold( 'datamachine_ai_request_warning_bytes', 900000 ),
			'messages_json_bytes'  => self::threshold( 'datamachine_ai_messages_warning_bytes', 750000 ),
			'tools_json_bytes'     => self::threshold( 'datamachine_ai_tools_warning_bytes', 250000 ),
			'directive_json_bytes' => self::threshold( 'datamachine_ai_directive_warning_bytes', 150000 ),
			'tool_json_bytes'      => self::threshold( 'datamachine_ai_tool_warning_bytes', 100000 ),
		);
	}

	private static function threshold( string $filter, int $default ): int {
		$value = (int) apply_filters( $filter, $default );
		return max( 0, $value );
	}

	/**
	 * Add a scalar byte-threshold warning when exceeded.
	 *
	 * @param array  $warnings  Warning map mutated in place.
	 * @param string $field     Metadata field name.
	 * @param int    $value     Observed byte count.
	 * @param int    $threshold Warning threshold, or 0 to disable.
	 * @return void
	 */
	private static function maybe_add_warning( array &$warnings, string $field, int $value, int $threshold ): void {
		if ( $threshold > 0 && $value > $threshold ) {
			$warnings[ $field ] = array(
				'value'     => $value,
				'threshold' => $threshold,
			);
		}
	}

	/**
	 * Return the largest tool schemas by encoded size.
	 *
	 * @param array $structured_tools Structured tool definitions.
	 * @return array<int,array{name:string,json_bytes:int}>
	 */
	private static function largest_tools( array $structured_tools ): array {
		$tools = array();
		foreach ( $structured_tools as $name => $tool ) {
			$json    = wp_json_encode( $tool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$tools[] = array(
				'name'       => (string) ( $tool['name'] ?? $name ),
				'json_bytes' => strlen( (string) $json ),
			);
		}

		usort( $tools, fn( $a, $b ) => ( $b['json_bytes'] ?? 0 ) <=> ( $a['json_bytes'] ?? 0 ) );
		return array_slice( $tools, 0, 10 );
	}

	/**
	 * Flatten memory-file metadata from directive records.
	 *
	 * @param array $directive_metadata Directive metadata records.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_memory_files( array $directive_metadata ): array {
		$files = array();
		foreach ( $directive_metadata as $directive ) {
			foreach ( $directive['memory_files'] ?? array() as $file ) {
				$files[] = $file;
			}
		}

		usort( $files, fn( $a, $b ) => ( $b['bytes'] ?? 0 ) <=> ( $a['bytes'] ?? 0 ) );
		return $files;
	}
}
