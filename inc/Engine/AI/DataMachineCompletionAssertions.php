<?php
/**
 * Generic Data Machine completion assertions.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks generic completion signals across a conversation run.
 */
class DataMachineCompletionAssertions {

	/** @var array<int, string> */
	private array $required_engine_data_keys;

	/** @var array<int, string> */
	private array $required_tool_names;

	/** @var array<int, string> */
	private array $required_output_packet_types;

	/** @var array<int, string> */
	private array $executed_tool_names = array();

	/** @var array<int, string> */
	private array $available_output_packet_types = array();

	/**
	 * @param array $config Assertion config.
	 */
	public function __construct( array $config = array() ) {
		$this->required_engine_data_keys    = $this->sanitizeList( $config['required_engine_data_keys'] ?? array() );
		$this->required_tool_names          = $this->sanitizeList( $config['required_tool_names'] ?? array() );
		$this->required_output_packet_types = $this->sanitizeList( $config['required_output_packet_types'] ?? array() );
	}

	/**
	 * Whether this assertion set has any active requirements.
	 *
	 * @return bool
	 */
	public function hasAssertions(): bool {
		return ! empty( $this->required_engine_data_keys )
			|| ! empty( $this->required_tool_names )
			|| ! empty( $this->required_output_packet_types );
	}

	/**
	 * Record a tool result as a possible completion signal.
	 *
	 * @param string     $tool_name   Tool name.
	 * @param array|null $tool_def    Tool definition.
	 * @param array      $tool_result Tool result.
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result ): void {
		$tool_succeeded = true === ( $tool_result['success'] ?? false );

		if ( '' !== $tool_name && $tool_succeeded ) {
			$this->executed_tool_names[] = $tool_name;
		}

		$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );
		if ( $is_handler_tool && $tool_succeeded ) {
			$this->available_output_packet_types[] = 'ai_handler_complete';
			return;
		}

		$this->available_output_packet_types[] = 'tool_result';
	}

	/**
	 * Evaluate assertions at natural completion time.
	 *
	 * @param array  $runtime_context Caller-owned runtime context.
	 * @param string $assistant_text  Latest assistant text.
	 * @return array{complete: bool, missing: array<string, array<int, string>>, satisfied: array<string, array<int, string>>}
	 */
	public function evaluate( array $runtime_context, string $assistant_text = '' ): array {
		if ( ! $this->hasAssertions() ) {
			return array(
				'complete'  => true,
				'missing'   => array(),
				'satisfied' => array(),
			);
		}

		$output_packet_types = array_unique( $this->available_output_packet_types );
		if ( empty( $output_packet_types ) && '' !== trim( $assistant_text ) ) {
			$output_packet_types[] = 'ai_response';
		}

		$satisfied = array(
			'engine_data_keys'    => $this->satisfiedEngineDataKeys( $runtime_context ),
			'tool_names'          => array_values( array_intersect( $this->required_tool_names, array_unique( $this->executed_tool_names ) ) ),
			'output_packet_types' => array_values( array_intersect( $this->required_output_packet_types, $output_packet_types ) ),
		);

		$missing = array_filter(
			array(
				'engine_data_keys'    => array_values( array_diff( $this->required_engine_data_keys, $satisfied['engine_data_keys'] ) ),
				'tool_names'          => array_values( array_diff( $this->required_tool_names, $satisfied['tool_names'] ) ),
				'output_packet_types' => array_values( array_diff( $this->required_output_packet_types, $satisfied['output_packet_types'] ) ),
			)
		);

		return array(
			'complete'  => empty( $missing ),
			'missing'   => $missing,
			'satisfied' => array_filter( $satisfied ),
		);
	}

	/**
	 * Build a positive nudge for missing completion signals.
	 *
	 * @param array $missing Missing assertions grouped by type.
	 * @param array $messages Current conversation messages.
	 * @return string Nudge message.
	 */
	public static function buildNudge( array $missing, array $messages ): string {
		$parts = array();
		foreach ( $missing as $type => $values ) {
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}
			$label   = str_replace( '_', ' ', (string) $type );
			$parts[] = $label . ': ' . implode( ', ', array_map( 'strval', $values ) );
		}

		$goal = self::extractGoal( $messages );
		$nudge = 'Please continue. The work is close, but these completion signals are still missing: ' . implode( '; ', $parts ) . '.';
		if ( '' !== $goal ) {
			$nudge .= ' Original goal/context: ' . $goal;
		}
		$nudge .= ' Use the available tools as needed, then only finish once those signals are present.';

		return $nudge;
	}

	/**
	 * Return required assertion config for diagnostics.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function required(): array {
		return array_filter(
			array(
				'engine_data_keys'    => $this->required_engine_data_keys,
				'tool_names'          => $this->required_tool_names,
				'output_packet_types' => $this->required_output_packet_types,
			)
		);
	}

	/**
	 * @param mixed $value Raw list.
	 * @return array<int, string>
	 */
	private function sanitizeList( $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * @param array $runtime_context Caller-owned runtime context.
	 * @return array<int, string>
	 */
	private function satisfiedEngineDataKeys( array $runtime_context ): array {
		$engine = $runtime_context['engine'] ?? null;
		$data   = is_array( $runtime_context['engine_data'] ?? null ) ? $runtime_context['engine_data'] : array();

		if ( is_object( $engine ) && method_exists( $engine, 'all' ) ) {
			$engine_data = $engine->all();
			if ( is_array( $engine_data ) ) {
				$data = $engine_data;
			}
		}

		$satisfied = array();
		foreach ( $this->required_engine_data_keys as $key ) {
			if ( array_key_exists( $key, $data ) && null !== $data[ $key ] && '' !== $data[ $key ] && array() !== $data[ $key ] ) {
				$satisfied[] = $key;
				continue;
			}

			if ( is_object( $engine ) && method_exists( $engine, 'get' ) ) {
				$value = $engine->get( $key );
				if ( null !== $value && '' !== $value && array() !== $value ) {
					$satisfied[] = $key;
				}
			}
		}

		return array_values( array_unique( $satisfied ) );
	}

	/**
	 * @param array $messages Current messages.
	 * @return string Extracted goal/context.
	 */
	private static function extractGoal( array $messages ): string {
		foreach ( $messages as $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$content = $message['content'] ?? '';
			if ( is_array( $content ) ) {
				$content = wp_json_encode( $content );
			}

			$content = trim( (string) $content );
			if ( '' === $content ) {
				continue;
			}

			return strlen( $content ) > 300 ? substr( $content, 0, 297 ) . '...' : $content;
		}

		return '';
	}
}
