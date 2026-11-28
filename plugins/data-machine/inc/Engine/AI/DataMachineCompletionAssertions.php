<?php
/**
 * Generic Data Machine completion assertions.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\DataPath;
use DataMachine\Core\OutputContract;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks generic completion signals across a conversation run.
 */
class DataMachineCompletionAssertions {

	/** @var array<int, string> */
	private array $required_engine_data_keys;

	/** @var array<int, string> */
	private array $required_tool_names;

	/** @var array<string, int> */
	private array $minimum_successful_tool_counts;

	/** @var array<int, string> */
	private array $required_output_packet_types;

	/** @var array<int, array{output_key: string, schema: string, artifact: string}> */
	private array $required_artifact_outputs;

	/** @var array<int, array{name: string, tools: array<int, array{name: string, required_output: array<int, string>, required_parameters: array<string, mixed>, min_successful_calls: int}>}> */
	private array $complete_when_any;

	/** @var array<int, string> */
	private array $executed_tool_names = array();

	/** @var array<string, array<int, array>> */
	private array $successful_tool_results = array();

	/** @var array<int, string> */
	private array $available_output_packet_types = array();

	/**
	 * @param array $config Assertion config.
	 */
	public function __construct( array $config = array() ) {
		$this->required_engine_data_keys      = $this->sanitizeList( $config['required_engine_data_keys'] ?? array() );
		$this->required_tool_names            = $this->sanitizeList( $config['required_tool_names'] ?? array() );
		$this->minimum_successful_tool_counts = $this->sanitizeToolCountMap( $config['minimum_successful_tool_counts'] ?? array() );
		$this->required_output_packet_types   = $this->sanitizeList( $config['required_output_packet_types'] ?? array() );
		$this->required_artifact_outputs      = OutputContract::normalizeRequiredArtifactOutputs( $config['required_artifact_outputs'] ?? array() );
		$this->complete_when_any              = $this->sanitizeOutcomeAssertions( $config['complete_when_any'] ?? array() );
	}

	/**
	 * Whether this assertion set has any active requirements.
	 *
	 * @return bool
	 */
	public function hasAssertions(): bool {
		return ! empty( $this->required_engine_data_keys )
			|| ! empty( $this->required_tool_names )
			|| ! empty( $this->minimum_successful_tool_counts )
			|| ! empty( $this->required_output_packet_types )
			|| ! empty( $this->required_artifact_outputs )
			|| ! empty( $this->complete_when_any );
	}

	/**
	 * Record a tool result as a possible completion signal.
	 *
	 * @param string     $tool_name       Tool name.
	 * @param array|null $tool_def        Tool definition.
	 * @param array      $tool_result     Tool result.
	 * @param array      $tool_parameters Tool parameters.
	 */
	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $tool_parameters = array() ): void {
		$tool_succeeded = true === ( $tool_result['success'] ?? false ) || in_array( (string) ( $tool_result['status'] ?? '' ), array( 'success', 'succeeded', 'completed' ), true );

		if ( '' !== $tool_name && $tool_succeeded ) {
			$tool_result['_parameters']                    = $tool_parameters;
			$this->executed_tool_names[]                   = $tool_name;
			$this->successful_tool_results[ $tool_name ][] = $tool_result;

			foreach ( $this->toolAliases( $tool_name, $tool_def ) as $alias ) {
				$this->executed_tool_names[]               = $alias;
				$this->successful_tool_results[ $alias ][] = $tool_result;
			}
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

		$outcome_evaluation = $this->evaluateOutcomeAssertions();

		$satisfied = array(
			'engine_data_keys'    => $this->satisfiedEngineDataKeys( $runtime_context ),
			'tool_names'          => array_values( array_intersect( $this->required_tool_names, array_unique( $this->executed_tool_names ) ) ),
			'tool_counts'         => $this->satisfiedToolCounts(),
			'output_packet_types' => array_values( array_intersect( $this->required_output_packet_types, $output_packet_types ) ),
			'artifact_outputs'    => $this->satisfiedArtifactOutputKeys( $runtime_context ),
			'complete_when_any'   => $outcome_evaluation['satisfied'],
		);

		$missing = array_filter(
			array(
				'engine_data_keys'    => array_values( array_diff( $this->required_engine_data_keys, $satisfied['engine_data_keys'] ) ),
				'tool_names'          => array_values( array_diff( $this->required_tool_names, $satisfied['tool_names'] ) ),
				'tool_counts'         => $this->missingToolCounts(),
				'output_packet_types' => array_values( array_diff( $this->required_output_packet_types, $satisfied['output_packet_types'] ) ),
				'artifact_outputs'    => array_values( array_diff( $this->requiredArtifactOutputKeys(), $satisfied['artifact_outputs'] ) ),
				'complete_when_any'   => $outcome_evaluation['missing'],
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
		unset( $missing ); // Raw assertion diagnostics stay in metadata, not model-facing text.

		$goal  = self::extractGoal( $messages );
		$nudge = 'Please continue. The task is not complete yet. Use the available tools to inspect or update the project as needed, then finish once the requested outcome is in place.';
		if ( '' !== $goal ) {
			$nudge .= ' Original request: ' . $goal;
		}

		return $nudge;
	}

	/**
	 * Return required assertion config for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function required(): array {
		return array_filter(
			array(
				'engine_data_keys'    => $this->required_engine_data_keys,
				'tool_names'          => $this->required_tool_names,
				'tool_counts'         => $this->requiredToolCounts(),
				'output_packet_types' => $this->required_output_packet_types,
				'artifact_outputs'    => $this->required_artifact_outputs,
				'complete_when_any'   => $this->complete_when_any,
			)
		);
	}

	/**
	 * Return required tool names.
	 *
	 * @return array<int, string>
	 */
	public function requiredToolNames(): array {
		return $this->required_tool_names;
	}

	/**
	 * Return required tools that are not available to the model.
	 *
	 * @param array $tools Available tools keyed by tool name.
	 * @return array<int, string>
	 */
	public function unavailableRequiredToolNames( array $tools ): array {
		$required_tool_names = $this->required_tool_names;
		if ( ! empty( $this->complete_when_any ) && ! $this->hasAvailableOutcomePath( $tools ) ) {
			$required_tool_names = array_merge( $required_tool_names, $this->outcomeToolNames() );
		}

		if ( empty( $required_tool_names ) ) {
			return array();
		}

		return array_values( array_diff( array_values( array_unique( $required_tool_names ) ), self::availableToolNames( $tools ) ) );
	}

	/**
	 * Return logical and runtime/provider-safe names available for completion assertions.
	 *
	 * @param array $tools Available tools keyed by logical tool name.
	 * @return array<int, string>
	 */
	private static function availableToolNames( array $tools ): array {
		$names = array();
		foreach ( $tools as $tool_name => $tool_def ) {
			$logical_name = (string) $tool_name;
			if ( '' !== $logical_name ) {
				$names[] = $logical_name;
			}

			if ( is_array( $tool_def ) ) {
				foreach ( self::toolAliases( $logical_name, $tool_def ) as $alias ) {
					$names[] = $alias;
				}
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Return assertion aliases for one tool definition.
	 *
	 * @param string     $tool_name Logical tool name.
	 * @param array|null $tool_def Tool definition.
	 * @return array<int, string>
	 */
	private static function toolAliases( string $tool_name, ?array $tool_def ): array {
		if ( ! is_array( $tool_def ) ) {
			return array();
		}

		$aliases = array();
		foreach ( array( 'name', 'runtime_tool_id' ) as $key ) {
			$value = is_string( $tool_def[ $key ] ?? null ) ? trim( (string) $tool_def[ $key ] ) : '';
			if ( '' !== $value && $value !== $tool_name ) {
				$aliases[] = $value;
			}
		}

		return array_values( array_unique( $aliases ) );
	}

	/**
	 * @return array{satisfied: array<int, string>, missing: array<int, string>}
	 */
	private function evaluateOutcomeAssertions(): array {
		if ( empty( $this->complete_when_any ) ) {
			return array(
				'satisfied' => array(),
				'missing'   => array(),
			);
		}

		$missing = array();
		foreach ( $this->complete_when_any as $index => $outcome ) {
			$outcome_missing = $this->missingOutcomeRequirements( $outcome );
			if ( empty( $outcome_missing ) ) {
				return array(
					'satisfied' => array( $outcome['name'] ),
					'missing'   => array(),
				);
			}

			$missing[] = $outcome['name'] . ': ' . implode( ', ', $outcome_missing );
		}

		return array(
			'satisfied' => array(),
			'missing'   => $missing,
		);
	}

	/**
	 * @param array{name: string, tools: array<int, array{name: string, required_output: array<int, string>, required_parameters: array<string, mixed>, min_successful_calls: int}>} $outcome Outcome assertion.
	 * @return array<int, string>
	 */
	private function missingOutcomeRequirements( array $outcome ): array {
		$missing = array();
		foreach ( $outcome['tools'] as $tool ) {
			$name             = $tool['name'];
			$matching_results = $this->matchingToolResults( $tool );
			if ( empty( $matching_results ) ) {
				$missing[] = $name;
				continue;
			}
			if ( count( $matching_results ) < $tool['min_successful_calls'] ) {
				$missing[] = $name . ' x' . $tool['min_successful_calls'];
				continue;
			}

			foreach ( $tool['required_output'] as $field ) {
				if ( ! $this->resultsHaveOutputField( $matching_results, $field ) ) {
					$missing[] = $name . '.' . $field;
				}
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * @param array{name: string, required_parameters: array<string, mixed>} $tool Tool assertion.
	 * @return array<int, array>
	 */
	private function matchingToolResults( array $tool ): array {
		$results = $this->successful_tool_results[ $tool['name'] ] ?? array();
		if ( empty( $tool['required_parameters'] ) ) {
			return $results;
		}

		return array_values(
			array_filter(
				$results,
				fn( array $result ): bool => $this->parametersMatch( is_array( $result['_parameters'] ?? null ) ? $result['_parameters'] : array(), $tool['required_parameters'] )
			)
		);
	}

	private function parametersMatch( array $parameters, array $required_parameters ): bool {
		foreach ( $required_parameters as $path => $expected ) {
			if ( ! DataPath::hasPathValue( $parameters, (string) $path, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	private function resultsHaveOutputField( array $results, string $field ): bool {
		foreach ( $results as $result ) {
			if ( DataPath::hasPresentPath( $result, $field ) ) {
				return true;
			}

			if ( is_array( $result['data'] ?? null ) && DataPath::hasPresentPath( $result['data'], $field ) ) {
				return true;
			}

			if ( is_array( $result['result'] ?? null ) && DataPath::hasPresentPath( $result['result'], $field ) ) {
				return true;
			}
		}

		return false;
	}

	private function hasAvailableOutcomePath( array $tools ): bool {
		foreach ( $this->complete_when_any as $outcome ) {
			$names = array_map( static fn( array $tool ): string => $tool['name'], $outcome['tools'] );
			if ( empty( array_diff( $names, self::availableToolNames( $tools ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private function requiredToolCounts(): array {
		$counts = array();
		foreach ( $this->minimum_successful_tool_counts as $tool_name => $minimum_count ) {
			$counts[] = $tool_name . '>=' . $minimum_count;
		}

		return $counts;
	}

	/**
	 * @return array<int, string>
	 */
	private function satisfiedToolCounts(): array {
		$satisfied = array();
		foreach ( $this->minimum_successful_tool_counts as $tool_name => $minimum_count ) {
			$actual_count = count( $this->successful_tool_results[ $tool_name ] ?? array() );
			if ( $actual_count >= $minimum_count ) {
				$satisfied[] = $tool_name . '>=' . $minimum_count;
			}
		}

		return $satisfied;
	}

	/**
	 * @return array<int, string>
	 */
	private function missingToolCounts(): array {
		$missing = array();
		foreach ( $this->minimum_successful_tool_counts as $tool_name => $minimum_count ) {
			$actual_count = count( $this->successful_tool_results[ $tool_name ] ?? array() );
			if ( $actual_count < $minimum_count ) {
				$missing[] = $tool_name . ': ' . $actual_count . '/' . $minimum_count;
			}
		}

		return $missing;
	}

	/**
	 * @return array<int, string>
	 */
	private function outcomeToolNames(): array {
		$names = array();
		foreach ( $this->complete_when_any as $outcome ) {
			foreach ( $outcome['tools'] as $tool ) {
				$names[] = $tool['name'];
			}
		}

		return array_values( array_unique( $names ) );
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
	 * @param mixed $value Raw tool count map.
	 * @return array<string, int>
	 */
	private function sanitizeToolCountMap( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$counts = array();
		foreach ( $value as $tool_name => $minimum_count ) {
			$tool_name     = trim( (string) $tool_name );
			$minimum_count = (int) $minimum_count;
			if ( '' !== $tool_name && $minimum_count > 0 ) {
				$counts[ $tool_name ] = $minimum_count;
			}
		}

		return $counts;
	}

	/**
	 * @param mixed $value Raw outcome assertions.
	 * @return array<int, array{name: string, tools: array<int, array{name: string, required_output: array<int, string>, required_parameters: array<string, mixed>, min_successful_calls: int}>}>
	 */
	private function sanitizeOutcomeAssertions( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$outcomes = array();
		foreach ( $value as $index => $outcome ) {
			if ( ! is_array( $outcome ) || ! is_array( $outcome['tools'] ?? null ) ) {
				continue;
			}

			$tools = array();
			foreach ( $outcome['tools'] as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}

				$name = trim( (string) ( $tool['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}

				$tools[] = array(
					'name'                 => $name,
					'required_output'      => $this->sanitizeList( $tool['required_output'] ?? array() ),
					'required_parameters'  => $this->sanitizeRequiredParameters( $tool['required_parameters'] ?? array() ),
					'min_successful_calls' => max( 1, (int) ( $tool['min_successful_calls'] ?? 1 ) ),
				);
			}

			if ( ! empty( $tools ) ) {
				$outcomes[] = array(
					'name'  => $this->sanitizeOutcomeName( $outcome['name'] ?? 'outcome_' . ( (int) $index + 1 ) ),
					'tools' => $tools,
				);
			}
		}

		return $outcomes;
	}

	private function sanitizeOutcomeName( mixed $value ): string {
		$name = strtolower( trim( (string) $value ) );
		$name = preg_replace( '/[^a-z0-9_\-]+/', '_', $name );
		$name = trim( is_string( $name ) ? $name : '', '_' );
		return '' !== $name ? $name : 'outcome';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sanitizeRequiredParameters( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return array();
		}

		$parameters = array();
		foreach ( $value as $path => $expected ) {
			$path = trim( (string) $path );
			if ( '' !== $path ) {
				$parameters[ $path ] = $expected;
			}
		}

		return $parameters;
	}

	/** @return array<int, string> */
	private function requiredArtifactOutputKeys(): array {
		return OutputContract::requiredArtifactOutputKeys( $this->required_artifact_outputs );
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
			if ( array_key_exists( $key, $data ) && DataPath::hasValue( $data[ $key ] ) ) {
				$satisfied[] = $key;
				continue;
			}

			if ( is_object( $engine ) && method_exists( $engine, 'get' ) ) {
				$value = $engine->get( $key );
				if ( DataPath::hasValue( $value ) ) {
					$satisfied[] = $key;
				}
			}
		}

		return array_values( array_unique( $satisfied ) );
	}

	/**
	 * @param array $runtime_context Caller-owned runtime context.
	 * @return array<int, string>
	 */
	private function satisfiedArtifactOutputKeys( array $runtime_context ): array {
		$engine = $runtime_context['engine'] ?? null;
		$data   = is_array( $runtime_context['engine_data'] ?? null ) ? $runtime_context['engine_data'] : array();

		if ( is_object( $engine ) && method_exists( $engine, 'all' ) ) {
			$engine_data = $engine->all();
			if ( is_array( $engine_data ) ) {
				$data = array_replace_recursive( $data, $engine_data );
			}
		}

		$typed_artifacts = array();
		foreach ( array( $data['outputs']['typed_artifacts'] ?? null, $runtime_context['outputs']['typed_artifacts'] ?? null ) as $artifact_outputs ) {
			if ( is_array( $artifact_outputs ) ) {
				$typed_artifacts = array_replace_recursive( $typed_artifacts, $artifact_outputs );
			}
		}
		foreach ( $this->successful_tool_results as $tool_results ) {
			foreach ( is_array( $tool_results ) ? $tool_results : array() as $tool_result ) {
				if ( ! is_array( $tool_result ) ) {
					continue;
				}

				$artifact_outputs = $tool_result['outputs']['typed_artifacts'] ?? null;
				if ( is_array( $artifact_outputs ) ) {
					$typed_artifacts = array_replace_recursive( $typed_artifacts, $artifact_outputs );
				}
			}
		}
		$satisfied = array();
		foreach ( $this->required_artifact_outputs as $required_output ) {
			if ( ! OutputContract::artifactOutputSatisfied( $required_output, $typed_artifacts ) ) {
				continue;
			}

			$satisfied[] = $required_output['output_key'];
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
