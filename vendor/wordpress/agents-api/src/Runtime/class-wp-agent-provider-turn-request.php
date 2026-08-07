<?php
/**
 * Provider-turn request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters;

defined( 'ABSPATH' ) || exit;

/**
 * Storage-neutral request object passed to provider-turn adapters.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Provider_Turn_Request {

	/** @var array<int, array<string, mixed>> Canonical transcript messages. */
	private array $messages;

	/** @var array<string, array<string, mixed>> Tool declarations keyed by name. */
	private array $tool_declarations;

	/** @var array<string, mixed> Provider/model metadata. */
	private array $model;

	/** @var array<string, mixed> Runtime-owned metadata. */
	private array $runtime;

	/** @var array<string, mixed> Turn context. */
	private array $context;

	/** @var array<string, mixed> Budget state. */
	private array $budgets;

	/** @var string Run identifier. */
	private string $run_id;

	/** @var string Session identifier. */
	private string $session_id;

	/** @var array<string, mixed> Caller-owned metadata. */
	private array $metadata;

	/**
	 * @param array<mixed>         $messages          Canonical transcript messages.
	 * @param array<mixed>         $tool_declarations Tool declarations keyed by name.
	 * @param array<string, mixed> $model             Provider/model metadata.
	 * @param array<string, mixed> $runtime           Runtime-owned metadata.
	 * @param array<string, mixed> $context           Turn context.
	 * @param array<string, mixed> $budgets           Budget state.
	 * @param string               $run_id            Run identifier.
	 * @param string               $session_id        Session identifier.
	 * @param array<string, mixed> $metadata          Caller-owned metadata.
	 */
	public function __construct( array $messages, array $tool_declarations = array(), array $model = array(), array $runtime = array(), array $context = array(), array $budgets = array(), string $run_id = '', string $session_id = '', array $metadata = array() ) {
		$this->messages          = WP_Agent_Message::normalize_many( $messages );
		$this->tool_declarations = self::normalize_tool_declarations( $tool_declarations );
		$this->model             = self::normalize_json_array( $model, 'model' );
		$this->runtime           = self::normalize_json_array( $runtime, 'runtime' );
		$this->context           = self::normalize_json_array( $context, 'context' );
		$this->budgets           = self::normalize_json_array( $budgets, 'budgets' );
		$this->run_id            = $run_id;
		$this->session_id        = $session_id;
		$this->metadata          = self::normalize_json_array( $metadata, 'metadata' );
	}

	/** @return array<int, array<string, mixed>> Canonical transcript messages. */
	public function messages(): array {
		return $this->messages;
	}

	/** @return array<string, array<string, mixed>> Tool declarations keyed by name. */
	public function toolDeclarations(): array {
		return $this->tool_declarations;
	}

	/** @return array<string, mixed> Provider/model metadata. */
	public function model(): array {
		return $this->model;
	}

	/** @return array<string, mixed> Runtime-owned metadata. */
	public function runtime(): array {
		return $this->runtime;
	}

	/** @return array<string, mixed> Turn context. */
	public function context(): array {
		return $this->context;
	}

	/** @return array<string, mixed> Budget state. */
	public function budgets(): array {
		return $this->budgets;
	}

	/** @return string Run identifier. */
	public function runId(): string {
		return $this->run_id;
	}

	/** @return string Session identifier. */
	public function sessionId(): string {
		return $this->session_id;
	}

	/** @return array<string, mixed> Caller-owned metadata. */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Return a normalized array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'messages'          => $this->messages,
			'tool_declarations' => $this->tool_declarations,
			'model'             => $this->model,
			'runtime'           => $this->runtime,
			'context'           => $this->context,
			'budgets'           => $this->budgets,
			'run_id'            => $this->run_id,
			'session_id'        => $this->session_id,
			'metadata'          => $this->metadata,
		);
	}

	/**
	 * Normalize tool declarations keyed by tool name.
	 *
	 * @param array<mixed> $tool_declarations Tool declarations.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_tool_declarations( array $tool_declarations ): array {
		$normalized = array();
		foreach ( $tool_declarations as $key => $declaration ) {
			if ( ! is_array( $declaration ) ) {
				throw self::invalid( 'tool_declarations.' . (string) $key, 'must be an array' );
			}

			$declaration = self::string_keyed_array( $declaration );
			if ( is_string( $key ) && '' !== $key && ! isset( $declaration['name'] ) ) {
				$declaration['name'] = $key;
			}

			try {
				$tool = self::string_keyed_array( WP_Agent_Tool_Declaration::normalizeForConversationRequest( $declaration ) );
			} catch ( \InvalidArgumentException $error ) {
				throw self::invalid( 'tool_declarations.' . (string) $key, $error->getMessage() );
			}

			$name = is_string( $tool['name'] ?? null ) ? $tool['name'] : '';
			if ( '' === $name ) {
				throw self::invalid( 'tool_declarations.' . (string) $key . '.name', 'must be a non-empty string' );
			}

			$tool['parameters'] = WP_Agent_Tool_Parameters::modelParameterSchema( $tool );

			$normalized[ $name ] = $tool;
		}

		return $normalized;
	}

	/**
	 * Validate that an associative array is JSON serializable.
	 *
	 * @param array<mixed> $value Raw value.
	 * @param string       $path  Field path.
	 * @return array<string, mixed>
	 */
	private static function normalize_json_array( array $value, string $path ): array {
		if ( false === wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) {
			throw self::invalid( $path, 'must be JSON serializable' );
		}

		return self::string_keyed_array( $value );
	}

	/**
	 * Normalize an associative array to string keys.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string, mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_provider_turn_request: ' . $path . ' ' . $reason );
	}
}
