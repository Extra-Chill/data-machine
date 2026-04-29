<?php
/**
 * AI conversation runner request contract.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Neutral request object for conversation runner implementations.
 */
class AIConversationRequest {

	/** @var array Initial conversation messages. */
	private array $messages;

	/** @var array Available AI tools. */
	private array $tools;

	/** @var array Provider/model configuration. */
	private array $model_config;

	/** @var string Execution mode. */
	private string $mode;

	/** @var int Maximum turn budget requested by caller. */
	private int $max_turns;

	/** @var bool Whether to stop after one model turn. */
	private bool $single_turn;

	/** @var LoopEventSinkInterface Event sink carried by the request. */
	private LoopEventSinkInterface $event_sink;

	/** @var array Original loop payload. */
	private array $payload;

	/** @var array Data Machine adapter context. */
	private array $adapter_context;

	/**
	 * Build a request from the legacy AIConversationLoop::run() argument list.
	 *
	 * @param array  $messages    Initial conversation messages.
	 * @param array  $tools       Available tools for AI.
	 * @param string $provider    AI provider identifier.
	 * @param string $model       AI model identifier.
	 * @param string $mode        Execution mode.
	 * @param array  $payload     Step payload / loop context.
	 * @param int    $max_turns   Maximum conversation turns.
	 * @param bool   $single_turn Single-turn mode flag.
	 * @return self Request object.
	 */
	public static function fromRunArgs(
		array $messages,
		array $tools,
		string $provider,
		string $model,
		string $mode,
		array $payload = array(),
		int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	): self {
		return new self(
			$messages,
			$tools,
			array(
				'provider' => $provider,
				'model'    => $model,
			),
			$mode,
			$payload,
			$max_turns,
			$single_turn
		);
	}

	/**
	 * @param array $messages     Initial conversation messages.
	 * @param array $tools        Available tools for AI.
	 * @param array $model_config Provider/model configuration.
	 * @param string $mode        Execution mode.
	 * @param array $payload      Step payload / loop context.
	 * @param int   $max_turns    Maximum conversation turns.
	 * @param bool  $single_turn  Single-turn mode flag.
	 */
	public function __construct(
		array $messages,
		array $tools,
		array $model_config,
		string $mode,
		array $payload = array(),
		int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	) {
		$this->messages        = $messages;
		$this->tools           = $tools;
		$this->model_config    = $model_config;
		$this->mode            = $mode;
		$this->payload         = $payload;
		$this->max_turns       = $max_turns;
		$this->single_turn     = $single_turn;
		$this->event_sink      = self::resolveEventSink( $payload );
		$this->adapter_context = self::buildAdapterContext( $payload );
	}

	/** @return array Initial conversation messages. */
	public function messages(): array {
		return $this->messages;
	}

	/** @return array Available AI tools. */
	public function tools(): array {
		return $this->tools;
	}

	/** @return array Provider/model configuration. */
	public function modelConfig(): array {
		return $this->model_config;
	}

	/** @return string Provider identifier. */
	public function provider(): string {
		return (string) ( $this->model_config['provider'] ?? '' );
	}

	/** @return string Model identifier. */
	public function model(): string {
		return (string) ( $this->model_config['model'] ?? '' );
	}

	/** @return string Execution mode. */
	public function mode(): string {
		return $this->mode;
	}

	/** @return int Maximum turn budget requested by caller. */
	public function maxTurns(): int {
		return $this->max_turns;
	}

	/** @return bool Whether to stop after one model turn. */
	public function singleTurn(): bool {
		return $this->single_turn;
	}

	/** @return LoopEventSinkInterface Event sink carried by the request. */
	public function eventSink(): LoopEventSinkInterface {
		return $this->event_sink;
	}

	/** @return array Original loop payload. */
	public function payload(): array {
		return $this->payload;
	}

	/** @return array Data Machine adapter context. */
	public function adapterContext(): array {
		return $this->adapter_context;
	}

	/**
	 * Return the legacy AIConversationLoop::execute() argument list.
	 *
	 * @return array Legacy arguments.
	 */
	public function toLegacyArgs(): array {
		return array(
			$this->messages,
			$this->tools,
			$this->provider(),
			$this->model(),
			$this->mode,
			$this->payload,
			$this->max_turns,
			$this->single_turn,
		);
	}

	/**
	 * Resolve observer sink from payload without exposing payload shape to runners.
	 *
	 * @param array $payload Loop payload.
	 * @return LoopEventSinkInterface Event sink.
	 */
	private static function resolveEventSink( array $payload ): LoopEventSinkInterface {
		$sink = $payload['event_sink'] ?? null;

		if ( $sink instanceof LoopEventSinkInterface ) {
			return $sink;
		}

		return new NullLoopEventSink();
	}

	/**
	 * Extract Data Machine-specific adapter context from the legacy payload.
	 *
	 * @param array $payload Loop payload.
	 * @return array Adapter context.
	 */
	private static function buildAdapterContext( array $payload ): array {
		return array(
			'job_id'                   => $payload['job_id'] ?? null,
			'flow_step_id'             => $payload['flow_step_id'] ?? null,
			'pipeline_id'              => $payload['pipeline_id'] ?? null,
			'flow_id'                  => $payload['flow_id'] ?? null,
			'configured_handler_slugs' => $payload['configured_handler_slugs'] ?? array(),
			'persist_transcript'       => $payload['persist_transcript'] ?? false,
			'engine'                   => $payload['engine'] ?? null,
		);
	}
}
