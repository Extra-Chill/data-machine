<?php
/**
 * Data Machine conversation loop — direct substrate consumer.
 *
 * Builds a DM-specific turn runner and passes it to the upstream
 * AgentConversationLoop::run() with DM completion policy, transcript
 * persistence, and event emission wired as options.
 *
 * This file replaces the former 748-LOC AIConversationLoop class. Both
 * ChatOrchestrator and AIStep call datamachine_run_conversation() directly.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\AgentConversationCompletionPolicyInterface;
use AgentsAPI\AI\AgentConversationLoop;
use AgentsAPI\AI\AgentConversationResult;
use AgentsAPI\AI\AgentConversationTranscriptPersisterInterface;
use AgentsAPI\AI\AgentMessageEnvelope;
use AgentsAPI\AI\NullAgentConversationTranscriptPersister;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Tools\ToolExecutor;

defined( 'ABSPATH' ) || exit;

/**
 * Run a multi-turn AI conversation through the agents-api substrate.
 *
 * Builds a DM-specific turn runner (request building + wp-ai-client dispatch +
 * tool execution) and delegates orchestration to AgentConversationLoop::run().
 *
 * @param array  $messages    Initial conversation messages.
 * @param array  $tools       Available tools keyed by tool name.
 * @param string $provider    AI provider identifier.
 * @param string $model       AI model identifier.
 * @param string $mode        Execution mode ('pipeline', 'chat', ...).
 * @param array  $payload     Step payload / loop context.
 * @param int    $max_turns   Maximum conversation turns.
 * @param bool   $single_turn Execute exactly one turn and return.
 * @return array Normalized conversation result.
 */
function datamachine_run_conversation(
	array $messages,
	array $tools,
	string $provider,
	string $model,
	string $mode,
	array $payload = array(),
	int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
	bool $single_turn = false
): array {
	$messages = AgentMessageEnvelope::normalize_many( $messages );

	// Resolve DM runtime collaborators from the payload.
	$event_sink           = datamachine_resolve_event_sink( $payload );
	$completion_policy    = datamachine_resolve_completion_policy( $mode, $payload );
	$transcript_persister = datamachine_resolve_transcript_persister( $payload );

	// Strip runtime objects from the loop payload before passing to tools/requests.
	$loop_payload = datamachine_payload_without_runtime_objects( $payload );

	// Clamp max_turns via the IterationBudget system.
	$turn_budget = IterationBudgetRegistry::create( 'conversation_turns', 0, $max_turns );
	$max_turns   = $turn_budget->ceiling();

	// Mutable state accumulated across turns by the turn runner.
	$last_request_metadata = array();
	$total_usage           = array(
		'prompt_tokens'     => 0,
		'completion_tokens' => 0,
		'total_tokens'      => 0,
	);

	// Base log context for consistent logging.
	$base_log_context = array_filter(
		array(
			'mode'         => $mode,
			'job_id'       => $loop_payload['job_id'] ?? null,
			'flow_step_id' => $loop_payload['flow_step_id'] ?? null,
		),
		fn( $v ) => null !== $v
	);

	// Bridge DM's LoopEventSinkInterface to upstream's on_event callable.
	$on_event = static function ( string $event, array $event_payload ) use ( $event_sink, $base_log_context ): void {
		datamachine_emit_loop_event( $event_sink, $event, array_merge( $base_log_context, $event_payload ) );
	};

	// Build the DM-specific turn runner. The completion policy is evaluated
	// inside the turn runner (not by upstream) because DM's tool execution
	// results have a different shape than what the upstream loop expects and
	// the policy needs access to DM-specific tool_def metadata.
	$turn_runner = datamachine_build_turn_runner(
		$tools,
		$provider,
		$model,
		$mode,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$turn_budget,
		$completion_policy,
		$last_request_metadata,
		$total_usage
	);

	// Build should_continue callback.
	$should_continue = static function ( array $result, array $turn_context ) use ( $single_turn, $turn_budget ): bool {
		if ( $single_turn ) {
			return false;
		}
		if ( $turn_budget->exceeded() ) {
			return false;
		}
		// The DM turn runner sets conversation_complete when the completion
		// policy says stop, or when no tools were called (natural completion).
		if ( ! empty( $result['conversation_complete'] ) ) {
			return false;
		}
		return ! empty( $result['tool_execution_results'] );
	};

	// Run through the upstream substrate loop.
	try {
		$result = AgentConversationLoop::run(
			$messages,
			$turn_runner,
			array(
				'max_turns'            => $max_turns,
				'context'              => array_merge( $loop_payload, array( 'mode' => $mode ) ),
				'should_continue'      => $should_continue,
				'transcript_persister' => $transcript_persister,
				'on_event'             => $on_event,
			)
		);
	} catch ( \RuntimeException $e ) {
		// The turn runner throws RuntimeException for wp-ai-client failures.
		return array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => $turn_budget->current(),
			'completed'              => false,
			'last_tool_calls'        => array(),
			'tool_execution_results' => array(),
			'error'                  => $e->getMessage(),
			'usage'                  => $total_usage,
			'request_metadata'       => $last_request_metadata,
		);
	}

	// Normalize the substrate result and augment with DM-specific fields.
	try {
		$result = AgentConversationResult::normalize( $result );
	} catch ( \InvalidArgumentException $e ) {
		return array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => 0,
			'completed'              => false,
			'last_tool_calls'        => array(),
			'tool_execution_results' => array(),
			'usage'                  => array(),
			'error'                  => $e->getMessage(),
		);
	}

	$result['usage']            = $total_usage;
	$result['request_metadata'] = $last_request_metadata;

	return $result;
}

/**
 * Build the DM-specific turn runner closure.
 *
 * The turn runner handles one provider turn: build request → dispatch via
 * wp-ai-client → extract tool calls → execute tools → format messages.
 * The upstream loop handles multi-turn sequencing, completion policy, and
 * transcript persistence.
 *
 * @param array                                           $tools                  Available tools.
 * @param string                                          $provider               AI provider.
 * @param string                                          $model                  AI model.
 * @param string                                          $mode                   Execution mode.
 * @param array                                           $loop_payload           Cleaned payload.
 * @param LoopEventSinkInterface                          $event_sink             DM event sink.
 * @param array                                           $base_log_context       Base log context.
 * @param \DataMachine\Engine\AI\IterationBudget          $turn_budget            Turn budget.
 * @param AgentConversationCompletionPolicyInterface       $completion_policy      Completion policy.
 * @param array                                           &$last_request_metadata Mutable request metadata.
 * @param array                                           &$total_usage           Mutable usage accumulator.
 * @return callable Turn runner closure.
 */
function datamachine_build_turn_runner(
	array $tools,
	string $provider,
	string $model,
	string $mode,
	array $loop_payload,
	LoopEventSinkInterface $event_sink,
	array $base_log_context,
	$turn_budget,
	AgentConversationCompletionPolicyInterface $completion_policy,
	array &$last_request_metadata,
	array &$total_usage
): callable {
	return static function ( array $messages, array $turn_context ) use (
		$tools,
		$provider,
		$model,
		$mode,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$turn_budget,
		$completion_policy,
		&$last_request_metadata,
		&$total_usage
	): array {
		$turn_budget->increment();
		$turn_count = $turn_budget->current();

		// Build and dispatch AI request using centralized RequestBuilder.
		$ai_response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			$tools,
			$mode,
			$loop_payload,
			$last_request_metadata
		);

		datamachine_emit_loop_event(
			$event_sink,
			'request_built',
			array_merge(
				$base_log_context,
				array(
					'turn_count'       => $turn_count,
					'provider'         => $provider,
					'model'            => $model,
					'success'          => ! is_wp_error( $ai_response ),
					'request_metadata' => $last_request_metadata,
				)
			)
		);

		// Handle AI request failure — throw so the upstream loop catches.
		if ( $ai_response instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'error',
				'datamachine_run_conversation: AI request failed',
				array_merge(
					$base_log_context,
					array(
						'turn_count' => $turn_count,
						'error'      => $ai_response->get_error_message(),
						'provider'   => $provider,
					)
				)
			);

			throw new \RuntimeException( $ai_response->get_error_message() );
		}

		/** @var \WordPress\AiClient\Results\DTO\GenerativeAiResult $ai_result */
		$ai_result  = $ai_response;
		$tool_calls = datamachine_extract_tool_calls( $ai_result );
		$ai_content = RequestBuilder::resultText( $ai_result );

		// Accumulate token usage.
		$token_usage                       = $ai_result->getTokenUsage();
		$total_usage['prompt_tokens']     += $token_usage->getPromptTokens();
		$total_usage['completion_tokens'] += $token_usage->getCompletionTokens();
		$total_usage['total_tokens']      += $token_usage->getTotalTokens();

		// Add AI message to conversation if it has content.
		if ( ! empty( $ai_content ) ) {
			$messages[] = ConversationManager::buildConversationMessage(
				'assistant',
				$ai_content,
				array( 'type' => 'text' )
			);
			do_action( 'datamachine_ai_response_received', $mode, $messages, $loop_payload );
		}

		// Process tool calls.
		$tool_execution_results = array();
		$conversation_complete  = false;
		if ( ! empty( $tool_calls ) ) {
			foreach ( $tool_calls as $tool_call ) {
				$tool_name       = $tool_call['name'];
				$tool_parameters = $tool_call['parameters'];

				if ( empty( $tool_name ) ) {
					do_action(
						'datamachine_log',
						'warning',
						'datamachine_run_conversation: Tool call missing name',
						array_merge( $base_log_context, array( 'turn_count' => $turn_count, 'tool_call' => $tool_call ) )
					);
					continue;
				}

				do_action(
					'datamachine_log',
					'debug',
					'datamachine_run_conversation: Tool call',
					array_merge( $base_log_context, array( 'turn' => $turn_count, 'tool' => $tool_name, 'params' => $tool_parameters ) )
				);

				// Validate for duplicate tool calls.
				$validation_result = ConversationManager::validateToolCall( $tool_name, $tool_parameters, $messages );
				if ( $validation_result['is_duplicate'] ) {
					$messages[] = ConversationManager::generateDuplicateToolCallMessage( $tool_name, $turn_count, $mode );
					do_action(
						'datamachine_log',
						'info',
						'datamachine_run_conversation: Duplicate tool call prevented',
						array_merge( $base_log_context, array( 'turn_count' => $turn_count, 'tool_name' => $tool_name ) )
					);
					continue;
				}

				// Add tool call message.
				$messages[] = ConversationManager::formatToolCallMessage( $tool_name, $tool_parameters, $turn_count );

				// Execute through DM's ToolExecutor (action policy, post tracking).
				$tool_result = ToolExecutor::executeTool(
					$tool_name,
					$tool_parameters,
					$tools,
					$loop_payload,
					$mode,
					(int) ( $loop_payload['agent_id'] ?? 0 ),
					is_array( $loop_payload['client_context'] ?? null ) ? $loop_payload['client_context'] : array()
				);

				do_action(
					'datamachine_log',
					'debug',
					'datamachine_run_conversation: Tool result',
					array_merge( $base_log_context, array( 'turn' => $turn_count, 'tool' => $tool_name, 'success' => $tool_result['success'] ?? false ) )
				);

				$tool_def        = $tools[ $tool_name ] ?? null;
				$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );

				// Evaluate the DM completion policy.
				$completion_decision = $completion_policy->recordToolResult(
					$tool_name,
					is_array( $tool_def ) ? $tool_def : null,
					$tool_result,
					array_merge( $turn_context, array( 'mode' => $mode ) ),
					$turn_count
				);
				$conversation_complete = $completion_decision->isComplete();
				if ( '' !== $completion_decision->message() ) {
					do_action(
						'datamachine_log',
						'debug',
						$completion_decision->message(),
						array_merge( $base_log_context, $completion_decision->context() )
					);
				}

				$tool_execution_results[] = array(
					'tool_name'       => $tool_name,
					'result'          => $tool_result,
					'parameters'      => $tool_parameters,
					'is_handler_tool' => $is_handler_tool,
					'turn_count'      => $turn_count,
				);

				// Add tool result message.
				$messages[] = ConversationManager::formatToolResultMessage(
					$tool_name,
					$tool_result,
					$tool_parameters,
					$is_handler_tool,
					$turn_count
				);

				// Break out of tool processing when conversation is complete.
				if ( $conversation_complete ) {
					break;
				}
			}
		} else {
			$conversation_complete = true;
		}

		return array(
			'messages'               => $messages,
			'tool_execution_results' => $tool_execution_results,
			'conversation_complete'  => $conversation_complete,
		);
	};
}

/**
 * Extract tool calls from a wp-ai-client GenerativeAiResult.
 *
 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result wp-ai-client result.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_extract_tool_calls( $result ): array {
	$tool_calls = array();
	$candidates = $result->getCandidates();
	if ( empty( $candidates ) ) {
		return $tool_calls;
	}

	foreach ( $candidates[0]->getMessage()->getParts() as $part ) {
		$function_call = $part->getFunctionCall();
		if ( null === $function_call ) {
			continue;
		}

		$tool_calls[] = array(
			'name'       => (string) $function_call->getName(),
			'parameters' => datamachine_normalize_function_args( $function_call->getArgs() ),
			'id'         => $function_call->getId(),
		);
	}

	return $tool_calls;
}

/**
 * Coerce wp-ai-client function call args into tool parameters.
 *
 * @param mixed $args Args from FunctionCall::getArgs().
 * @return array
 */
function datamachine_normalize_function_args( $args ): array {
	if ( is_array( $args ) ) {
		return $args;
	}
	if ( is_string( $args ) && '' !== $args ) {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	if ( is_object( $args ) ) {
		return (array) $args;
	}
	return array();
}

/**
 * Resolve the DM event sink from the payload.
 *
 * @param array $payload Loop payload.
 * @return LoopEventSinkInterface
 */
function datamachine_resolve_event_sink( array $payload ): LoopEventSinkInterface {
	$sink = $payload['event_sink'] ?? null;
	return $sink instanceof LoopEventSinkInterface ? $sink : new NullLoopEventSink();
}

/**
 * Resolve the runtime completion policy from mode and payload.
 *
 * @param string $mode    Execution mode.
 * @param array  $payload Loop payload.
 * @return AgentConversationCompletionPolicyInterface
 */
function datamachine_resolve_completion_policy( string $mode, array $payload ): AgentConversationCompletionPolicyInterface {
	$policy = $payload['completion_policy'] ?? null;
	if ( $policy instanceof AgentConversationCompletionPolicyInterface ) {
		return $policy;
	}

	$configured_handlers = $payload['configured_handler_slugs'] ?? array();
	$configured_handlers = is_array( $configured_handlers ) ? array_values( $configured_handlers ) : array();

	if ( ! empty( $configured_handlers ) || 'pipeline' === $mode ) {
		return new DataMachineHandlerCompletionPolicy( $configured_handlers );
	}

	return new DefaultAgentConversationCompletionPolicy();
}

/**
 * Resolve the runtime transcript persister from the payload.
 *
 * @param array $payload Loop payload.
 * @return AgentConversationTranscriptPersisterInterface
 */
function datamachine_resolve_transcript_persister( array $payload ): AgentConversationTranscriptPersisterInterface {
	$persister = $payload['transcript_persister'] ?? null;
	if ( $persister instanceof AgentConversationTranscriptPersisterInterface ) {
		return $persister;
	}

	if ( ! empty( $payload['persist_transcript'] ) ) {
		return new DataMachinePipelineTranscriptPersister();
	}

	return new NullAgentConversationTranscriptPersister();
}

/**
 * Strip runtime-only objects from the payload before dispatching to tools/requests.
 *
 * @param array $payload Loop payload.
 * @return array Clean payload.
 */
function datamachine_payload_without_runtime_objects( array $payload ): array {
	unset( $payload['event_sink'], $payload['completion_policy'], $payload['transcript_persister'] );
	return $payload;
}

/**
 * Emit a loop event without letting observer failures change loop results.
 *
 * @param LoopEventSinkInterface $sink    Event sink.
 * @param string                 $event   Event name.
 * @param array                  $payload Event payload.
 */
function datamachine_emit_loop_event( LoopEventSinkInterface $sink, string $event, array $payload = array() ): void {
	try {
		$sink->emit( $event, $payload );
	} catch ( \Throwable $e ) {
		do_action(
			'datamachine_log',
			'warning',
			'datamachine_run_conversation: Event sink failed',
			array( 'event' => $event, 'error' => $e->getMessage() )
		);
	}
}
