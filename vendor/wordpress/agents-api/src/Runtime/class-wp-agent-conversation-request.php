<?php
/**
 * Agent conversation runner request contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Neutral request object for conversation runner implementations.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are not rendered output.
class WP_Agent_Conversation_Request {

	public const DEFAULT_MAX_TURNS = 10;

	/** @var array<int, array<string, mixed>> Initial conversation messages. */
	private array $messages;

	/** @var array<int, array<string, mixed>> Runtime tool declarations available to the run. */
	private array $tools;

	/** @var WP_Agent_Execution_Principal|null Execution principal for the run. */
	private ?WP_Agent_Execution_Principal $principal;

	/** @var array<string, mixed> Caller-owned runtime context. */
	private array $runtime_context;

	/** @var array<string, mixed> Caller-owned metadata. */
	private array $metadata;

	/** @var int Maximum turn budget requested by caller. */
	private int $max_turns;

	/** @var bool Whether to stop after one orchestration turn. */
	private bool $single_turn;

	/** @var WP_Agent_Workspace_Scope|null Workspace scope for persistence/audit adapters. */
	private ?WP_Agent_Workspace_Scope $workspace;

	/** @var \WP_Agent_Runtime_Overrides Runtime overrides for the request. */
	private \WP_Agent_Runtime_Overrides $runtime_overrides;

	/**
	 * @param array<mixed>                         $messages        Initial conversation messages.
	 * @param array<mixed>                         $tools           Runtime tool declarations available to the run.
	 * @param WP_Agent_Execution_Principal|null  $principal       Execution principal for the run.
	 * @param array<string, mixed>          $runtime_context Caller-owned runtime context.
	 * @param array<string, mixed>          $metadata        Caller-owned metadata.
	 * @param int                           $max_turns       Maximum conversation turns.
	 * @param bool                          $single_turn     Single-turn orchestration flag.
	 * @param WP_Agent_Workspace_Scope|null $workspace         Workspace scope for persistence/audit adapters.
	 * @param \WP_Agent_Runtime_Overrides|null $runtime_overrides Runtime overrides for the request.
	 */
	public function __construct(
		array $messages,
		array $tools,
		?WP_Agent_Execution_Principal $principal = null,
		array $runtime_context = array(),
		array $metadata = array(),
		int $max_turns = self::DEFAULT_MAX_TURNS,
		bool $single_turn = false,
		?WP_Agent_Workspace_Scope $workspace = null,
		?\WP_Agent_Runtime_Overrides $runtime_overrides = null
	) {
		$this->messages          = WP_Agent_Message::normalize_many( $messages );
		$this->tools             = self::normalize_tools( $tools );
		$this->principal         = $principal;
		$this->runtime_overrides = $runtime_overrides ?? new \WP_Agent_Runtime_Overrides();
		$this->runtime_context   = self::normalize_json_array( $this->apply_runtime_overrides_to_context( $runtime_context ), 'runtime_context' );
		$this->metadata          = self::normalize_json_array( $metadata, 'metadata' );
		$this->max_turns         = max( 1, $max_turns );
		$this->single_turn       = $single_turn;
		$this->workspace         = $workspace;
	}

	/** @return array<int, array<string, mixed>> Initial conversation messages. */
	public function messages(): array {
		return $this->messages;
	}

	/** @return array<int, array<string, mixed>> Runtime tool declarations available to the run. */
	public function tools(): array {
		return $this->tools;
	}

	/** @return WP_Agent_Execution_Principal|null Execution principal for the run. */
	public function principal(): ?WP_Agent_Execution_Principal {
		return $this->principal;
	}

	/** @return array<string, mixed> Caller-owned runtime context. */
	public function runtimeContext(): array {
		return $this->runtime_context;
	}

	/** @return array<string, mixed> Caller-owned metadata. */
	public function metadata(): array {
		return $this->metadata;
	}

	/** @return int Maximum turn budget requested by caller. */
	public function maxTurns(): int {
		return $this->max_turns;
	}

	/** @return bool Whether to stop after one orchestration turn. */
	public function singleTurn(): bool {
		return $this->single_turn;
	}

	/** @return WP_Agent_Workspace_Scope|null Workspace scope for persistence/audit adapters. */
	public function workspace(): ?WP_Agent_Workspace_Scope {
		return $this->workspace;
	}

	/** @return \WP_Agent_Runtime_Overrides Runtime overrides for the request. */
	public function runtimeOverrides(): \WP_Agent_Runtime_Overrides {
		return $this->runtime_overrides;
	}

	/**
	 * Return a normalized array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'messages'          => $this->messages,
			'tools'             => $this->tools,
			'principal'         => $this->principal ? $this->principal->to_array() : null,
			'runtime_context'   => $this->runtime_context,
			'metadata'          => $this->metadata,
			'max_turns'         => $this->max_turns,
			'single_turn'       => $this->single_turn,
			'workspace'         => $this->workspace ? $this->workspace->to_array() : null,
			'runtime_overrides' => $this->runtime_overrides->to_array(),
		);
	}

	/**
	 * Add runner-facing overrides to runtime context.
	 *
	 * @param array<string, mixed> $runtime_context Caller runtime context.
	 * @return array<string, mixed> Runtime context with nullable overrides removed.
	 */
	private function apply_runtime_overrides_to_context( array $runtime_context ): array {
		$overrides = $this->runtime_overrides->to_array();
		foreach ( array( 'system_prompt', 'provider_id', 'model_id', 'temperature', 'max_iterations', 'tier_1_tools', 'greeting' ) as $key ) {
			$value = $overrides[ $key ] ?? null;
			if ( null !== $value && array() !== $value ) {
				$runtime_context[ $key ] = $value;
			}
		}

		return $runtime_context;
	}

	/**
	 * Normalize runtime tool declarations.
	 *
	 * @param array<mixed> $tools Runtime tool declarations.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_tools( array $tools ): array {
		$normalized = array();
		foreach ( $tools as $index => $tool ) {
			if ( ! is_array( $tool ) ) {
				throw self::invalid( 'tools[' . $index . ']', 'must be an array' );
			}

			try {
				$normalized[] = self::string_keyed_array( WP_Agent_Tool_Declaration::normalizeForConversationRequest( self::string_keyed_array( $tool ) ) );
			} catch ( \InvalidArgumentException $error ) {
				throw self::invalid( 'tools[' . $index . ']', $error->getMessage() );
			}
		}

		return $normalized;
	}

	/**
	 * Validate that a caller-owned array is JSON-serializable.
	 *
	 * @param array<mixed>  $value Raw array.
	 * @param string $path  Field path.
	 * @return array<string, mixed>
	 */
	private static function normalize_json_array( array $value, string $path ): array {
		if ( false === self::jsonEncode( $value ) ) {
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
	 * Encode JSON without throwing on older PHP configurations.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false
	 */
	private static function jsonEncode( $value ) {
		try {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return false;
		}
	}

	/**
	 * Build a machine-readable validation exception.
	 *
	 * @param string $path Field path.
	 * @param string $reason Failure reason.
	 * @return \InvalidArgumentException Validation exception.
	 */
	private static function invalid( string $path, string $reason ): \InvalidArgumentException {
		return new \InvalidArgumentException( 'invalid_agent_conversation_request: ' . $path . ' ' . $reason );
	}
}
