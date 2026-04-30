<?php
/**
 * AI Conversation Loop
 *
 * Centralized tool execution loop for AI agents.
 * Handles multi-turn conversations with tool execution and result feedback.
 *
 * @package DataMachine\Engine\AI
 * @since 0.2.0
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\AgentConversationResult;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\IterationBudgetRegistry;
use DataMachine\Engine\AI\Tools\ToolExecutor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Conversation Loop Class
 *
 * Executes multi-turn AI conversations with automatic tool execution.
 * Used by both Pipeline AI and Chat API for consistent tool handling.
 */
class AIConversationLoop {

	/**
	 * Run a conversation, optionally delegating to an external runtime adapter.
	 *
	 * This is the canonical entry point every caller should use instead of
	 * instantiating AIConversationLoop directly. It exposes a single filter
	 * (`agents_api_conversation_runner`) that lets a consumer short-circuit the
	 * built-in loop with an alternative runtime while receiving the exact
	 * same argument list and returning the exact same result shape. If the
	 * filter returns null (the default), the built-in loop runs unchanged.
	 *
	 * Adapters MUST return an array matching {@see self::execute()}'s
	 * documented return shape.
	 *
	 * @param array  $messages      Initial conversation messages.
	 * @param array  $tools         Available tools for AI.
	 * @param string $provider      AI provider identifier.
	 * @param string $model         AI model identifier.
	 * @param string $mode       Execution mode ('pipeline', 'chat', ...).
	 * @param array  $payload       Step payload / loop context.
	 * @param int    $max_turns     Maximum conversation turns.
	 * @param bool   $single_turn   Execute exactly one turn and return.
	 * @return array Result array matching self::execute() shape.
	 */
	public static function run(
		array $messages,
		array $tools,
		string $provider,
		string $model,
		string $mode,
		array $payload = array(),
		int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	): array {
		$request = AgentConversationRequest::fromRunArgs(
			$messages,
			$tools,
			$provider,
			$model,
			$mode,
			$payload,
			$max_turns,
			$single_turn
		);

		/**
		 * Filter: allow a consumer to replace Data Machine's built-in
		 * conversation loop with an alternative runtime.
		 *
		 * Return an array matching {@see AIConversationLoop::execute()}'s
		 * documented return shape to short-circuit the built-in loop.
		 * Return null (the default) to let the built-in loop run.
		 *
		 * Data Machine makes no assumptions about the consumer's runtime —
		 * the filter is the only contract. Adapters are responsible for
		 * executing tools, managing turns, and producing the expected
		 * result shape.
		 *
		 * @since next
		 *
		 * @param array|null $result       Null to run the built-in loop, or an
		 *                                 AIConversationLoop::execute() return array.
		 * @param array      $messages     Initial conversation messages.
		 * @param array      $tools        Available tools for AI.
		 * @param string     $provider     AI provider identifier.
		 * @param string     $model        AI model identifier.
		 * @param string     $mode      Execution mode.
		 * @param array      $payload      Step payload / loop context.
		 * @param int        $max_turns    Maximum conversation turns.
		 * @param bool       $single_turn  Single-turn mode flag.
		 */
		$filter_args = array(
			'agents_api_conversation_runner',
			null,
			$messages,
			$tools,
			$provider,
			$model,
			$mode,
			$payload,
			$max_turns,
			$single_turn,
		);
		$result      = call_user_func_array( 'apply_filters', $filter_args );

		if ( is_array( $result ) ) {
			return self::normalizeResultForRun( $result, $messages );
		}

		$result = ( new BuiltInAgentConversationRunner() )->run( $request );

		return self::normalizeResultForRun( $result, $messages );
	}

	/**
	 * Normalize a loop result, returning a caller-consumable error on contract drift.
	 *
	 * @param array $result            Raw loop result.
	 * @param array $fallback_messages Initial messages for validation failures.
	 * @return array Normalized result or error result.
	 */
	private static function normalizeResultForRun( array $result, array $fallback_messages ): array {
		try {
			return AgentConversationResult::normalize( $result );
		} catch ( \InvalidArgumentException $e ) {
			return array(
				'messages'               => $fallback_messages,
				'final_content'          => '',
				'turn_count'             => 0,
				'completed'              => false,
				'last_tool_calls'        => array(),
				'tool_execution_results' => array(),
				'usage'                  => array(),
				'error'                  => $e->getMessage(),
			);
		}
	}

	/**
	 * Execute conversation loop
	 *
	 * @param array  $messages      Initial conversation messages
	 * @param array  $tools          Available tools for AI
	 * @param string $provider       AI provider (openai, anthropic, etc.)
	 * @param string $model          AI model identifier
	 * @param string $mode        Execution mode: 'pipeline' or 'chat'
	 * @param array  $payload        Step payload (job_id, flow_step_id, data, flow_step_config)
	 * @param int    $max_turns      Maximum conversation turns (default 25)
	 * @param bool   $single_turn    Execute exactly one turn and return (default false)
	 * @return array {
	 *     @type array  $messages        Final conversation state
	 *     @type string $final_content   Last AI text response
	 *     @type int    $turn_count      Number of turns executed
	 *     @type bool   $completed       Whether loop finished naturally (no tool calls)
	 *     @type array  $last_tool_calls Last set of tool calls (if any)
	 *     @type array  $usage           Accumulated token usage {prompt_tokens, completion_tokens, total_tokens}
	 * }
	 */
	public function execute(
		array $messages,
		array $tools,
		string $provider,
		string $model,
		string $mode,
		array $payload = array(),
		int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
		bool $single_turn = false
	): array {
		// Bound the conversation with the shared IterationBudget primitive.
		// Ceiling resolution + clamp lives in IterationBudgetRegistry; the
		// caller-supplied $max_turns is an override that still gets clamped
		// to the registered bounds (currently [1, 50]). $turn_count mirrors
		// the budget's current value so the rest of this method keeps its
		// existing local-int semantics for log payloads and message formatting.
		$turn_budget            = IterationBudgetRegistry::create( 'conversation_turns', 0, $max_turns );
		$max_turns              = $turn_budget->ceiling();
		$conversation_complete  = false;
		$turn_count             = 0;
		$final_content          = '';
		$last_tool_calls        = array();
		$tool_execution_results = array();
		$last_request_metadata  = array();
		$event_sink             = self::resolveEventSink( $payload );
		$completion_policy      = self::resolveCompletionPolicy( $mode, $payload );
		$transcript_persister   = self::resolveTranscriptPersister( $payload );
		$loop_payload           = self::payloadWithoutRuntimeObjects( $payload );

		// Accumulate token usage across all turns.
		$total_usage = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);

		// Build base log metadata from payload for consistent logging.
		$base_log_context = array_filter(
			array(
				'mode'         => $mode,
				'job_id'       => $loop_payload['job_id'] ?? null,
				'flow_step_id' => $loop_payload['flow_step_id'] ?? null,
			),
			fn( $v ) => null !== $v
		);

		do {
			$turn_budget->increment();
			$turn_count = $turn_budget->current();

			self::emitLoopEvent(
				$event_sink,
				'turn_started',
				array_merge(
					$base_log_context,
					array(
						'turn_count'    => $turn_count,
						'provider'      => $provider,
						'model'         => $model,
						'message_count' => count( $messages ),
						'tool_count'    => count( $tools ),
					)
				)
			);

			// Build AI request using centralized RequestBuilder
			$ai_response           = RequestBuilder::build(
				$messages,
				$provider,
				$model,
				$tools,
				$mode,
				$loop_payload
			);
			$last_request_metadata = is_array( $ai_response['request_metadata'] ?? null ) ? $ai_response['request_metadata'] : array();
			self::emitLoopEvent(
				$event_sink,
				'request_built',
				array_merge(
					$base_log_context,
					array(
						'turn_count'       => $turn_count,
						'provider'         => $provider,
						'model'            => $model,
						'success'          => (bool) ( $ai_response['success'] ?? false ),
						'request_metadata' => $last_request_metadata,
					)
				)
			);

			// Handle AI request failure
			if ( ! $ai_response['success'] ) {
				do_action(
					'datamachine_log',
					'error',
					'AIConversationLoop: AI request failed',
					array_merge(
						$base_log_context,
						array(
							'turn_count' => $turn_count,
							'error'      => $ai_response['error'] ?? 'Unknown error',
							'provider'   => $ai_response['provider'] ?? 'Unknown',
						)
					)
				);

				$failure_result = array(
					'messages'         => $messages,
					'final_content'    => '',
					'turn_count'       => $turn_count,
					'completed'        => false,
					'last_tool_calls'  => array(),
					'error'            => $ai_response['error'] ?? 'AI request failed',
					'usage'            => $total_usage,
					'request_metadata' => $last_request_metadata,
				);

				// Persist transcript on the error path too — this is exactly
				// the scenario the feature exists for. Failing silently here
				// would defeat the debugging value.
				$transcript_session_id = $transcript_persister->persist(
					$messages,
					$provider,
					$model,
					$loop_payload,
					$failure_result
				);
				if ( '' !== $transcript_session_id ) {
					$failure_result['transcript_session_id'] = $transcript_session_id;
				}

				self::emitLoopEvent(
					$event_sink,
					'failed',
					array_merge(
						$base_log_context,
						array(
							'turn_count' => $turn_count,
							'provider'   => $ai_response['provider'] ?? $provider,
							'error'      => $failure_result['error'],
						)
					)
				);

				return $failure_result;
			}

			$tool_calls = $ai_response['data']['tool_calls'] ?? array();
			$ai_content = $ai_response['data']['content'] ?? '';

			// Accumulate token usage from this turn.
			$turn_usage = $ai_response['data']['usage'] ?? array();
			if ( ! empty( $turn_usage ) ) {
				$total_usage['prompt_tokens']     += (int) ( $turn_usage['prompt_tokens'] ?? 0 );
				$total_usage['completion_tokens'] += (int) ( $turn_usage['completion_tokens'] ?? 0 );
				$total_usage['total_tokens']      += (int) ( $turn_usage['total_tokens'] ?? 0 );
			}

			// Store final content from this turn
			if ( ! empty( $ai_content ) ) {
				$final_content = $ai_content;
			}

			// Add AI message to conversation if it has content
			if ( ! empty( $ai_content ) ) {
				$ai_message = ConversationManager::buildConversationMessage( 'assistant', $ai_content, array( 'type' => 'text' ) );
				$messages[] = $ai_message;

				// Fire hook for AI response events (used for system operations like title generation)
				do_action( 'datamachine_ai_response_received', $mode, $messages, $loop_payload );
			}

			// Process tool calls
			if ( ! empty( $tool_calls ) ) {
				$last_tool_calls = $tool_calls;

				foreach ( $tool_calls as $tool_call ) {
					$tool_name       = $tool_call['name'] ?? '';
					$tool_parameters = $tool_call['parameters'] ?? array();

					if ( empty( $tool_name ) ) {
						do_action(
							'datamachine_log',
							'warning',
							'AIConversationLoop: Tool call missing name',
							array_merge(
								$base_log_context,
								array(
									'turn_count' => $turn_count,
									'tool_call'  => $tool_call,
								)
							)
						);
						continue;
					}

					do_action(
						'datamachine_log',
						'debug',
						'AIConversationLoop: Tool call',
						array_merge(
							$base_log_context,
							array(
								'turn'   => $turn_count,
								'tool'   => $tool_name,
								'params' => $tool_parameters,
							)
						)
					);
					self::emitLoopEvent(
						$event_sink,
						'tool_call',
						array_merge(
							$base_log_context,
							array(
								'turn_count' => $turn_count,
								'tool_name'  => $tool_name,
								'parameters' => $tool_parameters,
							)
						)
					);

					// Validate for duplicate tool calls
					$validation_result = ConversationManager::validateToolCall(
						$tool_name,
						$tool_parameters,
						$messages
					);

					if ( $validation_result['is_duplicate'] ) {
						$correction_message = ConversationManager::generateDuplicateToolCallMessage( $tool_name, $turn_count );
						$messages[]         = $correction_message;

						do_action(
							'datamachine_log',
							'info',
							'AIConversationLoop: Duplicate tool call prevented',
							array_merge(
								$base_log_context,
								array(
									'turn_count' => $turn_count,
									'tool_name'  => $tool_name,
								)
							)
						);

						continue;
					}

					// Add tool call message to conversation
					$tool_call_message = ConversationManager::formatToolCallMessage(
						$tool_name,
						$tool_parameters,
						$turn_count
					);
					$messages[]        = $tool_call_message;

					// Execute the tool. Pass mode + agent_id + client_context
					// so ActionPolicyResolver can apply per-agent and per-mode
					// policy (preview/forbidden/direct) before the handler fires.
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
						'AIConversationLoop: Tool result',
						array_merge(
							$base_log_context,
							array(
								'turn'    => $turn_count,
								'tool'    => $tool_name,
								'success' => $tool_result['success'] ?? false,
							)
						)
					);
					self::emitLoopEvent(
						$event_sink,
						'tool_result',
						array_merge(
							$base_log_context,
							array(
								'turn_count' => $turn_count,
								'tool_name'  => $tool_name,
								'success'    => (bool) ( $tool_result['success'] ?? false ),
								'result'     => $tool_result,
							)
						)
					);

					// Determine if this is a handler tool
					$tool_def        = $tools[ $tool_name ] ?? null;
					$is_handler_tool = $tool_def && isset( $tool_def['handler'] );

					$completion_decision   = $completion_policy->recordToolResult(
						$tool_name,
						is_array( $tool_def ) ? $tool_def : null,
						$tool_result,
						$mode,
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

					// Store tool execution result separately for data packet processing
					$tool_execution_results[] = array(
						'tool_name'       => $tool_name,
						'result'          => $tool_result,
						'parameters'      => $tool_parameters,
						'is_handler_tool' => $is_handler_tool,
						'turn_count'      => $turn_count,
					);

					// Add tool result message to conversation (properly formatted for AI)
					$tool_result_message = ConversationManager::formatToolResultMessage(
						$tool_name,
						$tool_result,
						$tool_parameters,
						$is_handler_tool,
						$turn_count
					);
					$messages[]          = $tool_result_message;

					// Break out of the foreach when conversation is complete.
					// This is multi-handler safe: $conversation_complete only becomes true
					// when ALL configured handlers have fired (or legacy single-handler mode).
					// Without this break, remaining tool calls in the same AI response batch
					// would still execute, wasting credits on duplicate/unnecessary calls.
					if ( $conversation_complete ) {
						break;
					}
				}
			} else {
				// No tool calls = conversation complete
				$conversation_complete = true;
			}

			// Single-turn mode: break after first turn regardless of tool calls
			if ( $single_turn ) {
				break;
			}
		} while ( ! $conversation_complete && ! $turn_budget->exceeded() );

		// Log if max turns reached
		if ( $turn_budget->exceeded() && ! $conversation_complete ) {
			do_action(
				'datamachine_log',
				'warning',
				'AIConversationLoop: Max turns reached',
				array_merge(
					$base_log_context,
					array(
						'budget'               => $turn_budget->name(),
						'ceiling'              => $turn_budget->ceiling(),
						'current'              => $turn_budget->current(),
						'still_had_tool_calls' => ! empty( $last_tool_calls ),
					)
				)
			);
		}

		// In single-turn mode, completed reflects whether there are pending tools
		$is_completed = $single_turn
			? ( $conversation_complete && empty( $last_tool_calls ) )
			: $conversation_complete;

		$result = array(
			'messages'               => $messages,
			'final_content'          => $final_content,
			'turn_count'             => $turn_count,
			'completed'              => $is_completed,
			'last_tool_calls'        => $last_tool_calls,
			'tool_execution_results' => $tool_execution_results,
			'has_pending_tools'      => ! empty( $last_tool_calls ) && ! $conversation_complete,
			'usage'                  => $total_usage,
			'request_metadata'       => $last_request_metadata,
		);

		if ( $turn_budget->exceeded() && ! $conversation_complete ) {
			$result['warning'] = 'Maximum conversation turns (' . $max_turns . ') reached. Response may be incomplete.';
		}

		// Add max_turns_reached flag for single-turn mode
		if ( $single_turn && $turn_budget->exceeded() ) {
			$result['max_turns_reached'] = true;
		}

		$transcript_session_id = $transcript_persister->persist(
			$messages,
			$provider,
			$model,
			$loop_payload,
			$result
		);
		if ( '' !== $transcript_session_id ) {
			$result['transcript_session_id'] = $transcript_session_id;
		}

		self::emitLoopEvent(
			$event_sink,
			'completed',
			array_merge(
				$base_log_context,
				array(
					'turn_count'        => $turn_count,
					'completed'         => $is_completed,
					'has_pending_tools' => ! empty( $last_tool_calls ) && ! $conversation_complete,
					'usage'             => $total_usage,
				)
			)
		);

		return $result;
	}

	/**
	 * Resolve the event sink carried by the loop payload.
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
	 * Remove runtime-only payload values before dispatching requests or tools.
	 *
	 * @param array $payload Loop payload.
	 * @return array Payload without runtime collaborator objects.
	 */
	private static function payloadWithoutRuntimeObjects( array $payload ): array {
		unset( $payload['event_sink'] );
		unset( $payload['completion_policy'] );
		unset( $payload['transcript_persister'] );
		return $payload;
	}

	/**
	 * Resolve the runtime completion policy carried by the adapter payload.
	 *
	 * @param string $mode    Execution mode.
	 * @param array  $payload Loop payload.
	 * @return AgentConversationCompletionPolicyInterface Completion policy.
	 */
	private static function resolveCompletionPolicy( string $mode, array $payload ): AgentConversationCompletionPolicyInterface {
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
	 * Resolve the runtime transcript persister carried by the adapter payload.
	 *
	 * @param array $payload Loop payload.
	 * @return AgentConversationTranscriptPersisterInterface Transcript persister.
	 */
	private static function resolveTranscriptPersister( array $payload ): AgentConversationTranscriptPersisterInterface {
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
	 * Emit a loop event without letting observer failures change loop results.
	 *
	 * @param LoopEventSinkInterface $sink    Event sink.
	 * @param string                 $event   Event name.
	 * @param array                  $payload Event payload.
	 */
	private static function emitLoopEvent( LoopEventSinkInterface $sink, string $event, array $payload = array() ): void {
		try {
			$sink->emit( $event, $payload );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'AIConversationLoop: Event sink failed',
				array(
					'event' => $event,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
