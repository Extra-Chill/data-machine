<?php
/**
 * Data Machine conversation loop — direct substrate consumer.
 *
 * Builds a DM-specific turn runner and passes it to the upstream
 * WP_Agent_Conversation_Loop::run() with DM completion policy, transcript
 * persistence, and event emission wired as options.
 *
 * This file replaces the former 748-LOC AIConversationLoop class. Both
 * ChatOrchestrator and AIStep call datamachine_run_conversation() directly.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;
use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\AI\WP_Agent_Conversation_Result;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Null_Transcript_Persister;
use DataMachine\Core\JobArtifacts;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Engine\AI\Tools\ToolExecutor;

defined( 'ABSPATH' ) || exit;

/**
 * Run a multi-turn AI conversation through the agents-api substrate.
 *
 * Builds a DM-specific turn runner (request building + wp-ai-client dispatch +
 * tool execution) and delegates orchestration to WP_Agent_Conversation_Loop::run().
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
	$messages = WP_Agent_Message::normalize_many( $messages );

	// Resolve DM runtime collaborators from the payload.
	$event_sink           = datamachine_resolve_event_sink( $payload );
	$assertions           = datamachine_resolve_completion_assertions( $payload );
	$completion_policy    = datamachine_resolve_completion_policy( $mode, $payload, $assertions );
	$tool_runtime_rules   = datamachine_resolve_tool_runtime_rules( $payload );
	$transcript_persister = datamachine_resolve_transcript_persister( $payload );
	$transcript_lock      = $payload['transcript_lock'] ?? $payload['transcript_lock_store'] ?? null;

	// Strip runtime objects from the loop payload before passing to tools/requests.
	$loop_payload = datamachine_payload_without_runtime_objects( $payload );

	// Build the turns budget through DM's registry (site-config-aware ceiling
	// resolution). The upstream loop owns increment + exceeded checks.
	$turn_budget = IterationBudgetRegistry::create( 'conversation_turns', 0, $max_turns );
	$max_turns   = $turn_budget->ceiling();

	// DM-flavored mutable state accumulated by the turn runner. The substrate
	// surfaces turn_count, final_content, usage, and request_metadata on the
	// final result directly (see agents-api#136), so we only carry by-reference
	// the things substrate doesn't track for us.
	$last_tool_calls       = array();
	$completion_nudges     = array();
	$last_request_metadata = array();

	// Base log context for consistent logging.
	$base_log_context = array_filter(
		array(
			'mode'         => $mode,
			'job_id'       => $loop_payload['job_id'] ?? null,
			'flow_step_id' => $loop_payload['flow_step_id'] ?? null,
			'agent_slug'   => $loop_payload['agent_slug'] ?? null,
		),
		fn( $v ) => null !== $v
	);

	$unavailable_required_tools = $assertions->unavailableRequiredToolNames( $tools );
	if ( ! empty( $unavailable_required_tools ) ) {
		$error_message = sprintf(
			'Completion assertions require unavailable tool(s): %s. Update the AI step tool policy so every completion_assertions.required_tool_names entry is available to the model.',
			implode( ', ', $unavailable_required_tools )
		);

		datamachine_emit_loop_event(
			$event_sink,
			'completion_assertions_unavailable',
			array_merge(
				$base_log_context,
				array(
					'required_tool_names'             => $assertions->requiredToolNames(),
					'unavailable_required_tool_names' => $unavailable_required_tools,
					'available_tool_names'            => array_keys( $tools ),
					'error_message'                   => $error_message,
				)
			)
		);

		do_action(
			'datamachine_log',
			'error',
			'datamachine_run_conversation: Required completion assertion tool unavailable',
			array_merge(
				$base_log_context,
				array(
					'required_tool_names'             => $assertions->requiredToolNames(),
					'unavailable_required_tool_names' => $unavailable_required_tools,
					'available_tool_names'            => array_keys( $tools ),
				)
			)
		);

		return array(
			'messages'                        => $messages,
			'final_content'                   => '',
			'turn_count'                      => 0,
			'completed'                       => false,
			'last_tool_calls'                 => array(),
			'tool_execution_results'          => array(),
			'error'                           => $error_message,
			'error_code'                      => 'completion_required_tool_unavailable',
			'completion_assertions_required'  => $assertions->required(),
			'unavailable_required_tool_names' => $unavailable_required_tools,
			'available_tool_names'            => array_keys( $tools ),
			'usage'                           => array(),
			'request_metadata'                => array(),
			'status'                          => 'error',
			'runtime_provenance'              => RuntimeProvenance::fromConversationResult(
				array(
					'messages'         => $messages,
					'completed'        => false,
					'error'            => $error_message,
					'error_code'       => 'completion_required_tool_unavailable',
					'usage'            => array(),
					'request_metadata' => array(),
					'status'           => 'error',
				),
				$loop_payload,
				$provider,
				$model,
				$mode
			),
		);
	}

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
		$completion_policy,
		$tool_runtime_rules,
		$last_tool_calls,
		$completion_nudges,
		$last_request_metadata
	);

	// Build should_continue callback. Budget enforcement is handled by
	// upstream via the budgets option — we only check DM-specific conditions.
	$should_continue = static function ( array $result, array $turn_context ) use ( $single_turn ): bool {
		unset( $turn_context ); // Required by upstream callable signature.
		if ( $single_turn ) {
			return false;
		}
		// The DM turn runner sets conversation_complete when the completion
		// policy says stop, or when no tools were called (natural completion).
		if ( ! empty( $result['conversation_complete'] ) ) {
			return false;
		}
		return ! empty( $result['tool_execution_results'] ) || ! empty( $result['completion_nudge'] ) || ! empty( $result['duplicate_tool_call_rejected'] ) || ! empty( $result['tool_runtime_rule_rejected'] );
	};

	// Run through the upstream substrate loop.
	try {
		$result = WP_Agent_Conversation_Loop::run(
			$messages,
			$turn_runner,
			array(
				'max_turns'             => $max_turns,
				'budgets'               => array( $turn_budget ),
				'context'               => array_merge( $loop_payload, array( 'mode' => $mode ) ),
				'request'               => new \AgentsAPI\AI\WP_Agent_Conversation_Request(
					$messages,
					array(),
					null,
					array_merge( $loop_payload, array( 'mode' => $mode ) ),
					array(
						'provider'  => $provider,
						'model'     => $model,
						'wordpress' => WordPressWorkspaceScope::metadata(),
					),
					$max_turns,
					$single_turn,
					WordPressWorkspaceScope::current()
				),
				'should_continue'       => $should_continue,
				'transcript_persister'  => $transcript_persister,
				'transcript_lock'       => $transcript_lock,
				'transcript_session_id' => (string) ( $loop_payload['transcript_session_id'] ?? $loop_payload['session_id'] ?? '' ),
				'transcript_lock_ttl'   => (int) ( $payload['transcript_lock_ttl'] ?? 300 ),
				'on_event'              => $on_event,
			)
		);
	} catch ( \RuntimeException $e ) {
		// The turn runner throws RuntimeException for wp-ai-client failures.
		// We can't read the substrate's accumulated turn_count/usage/etc here
		// because the loop didn't return — surface what we know with empty
		// defaults for the substrate-tracked fields.
		$error_result                       = array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => 0,
			'completed'              => false,
			'last_tool_calls'        => $last_tool_calls,
			'tool_execution_results' => array(),
			'error'                  => $e->getMessage(),
			'usage'                  => array(),
			'request_metadata'       => $last_request_metadata,
			'status'                 => 'error',
		);
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $mode );
		return $error_result;
	}

	// Normalize the substrate result and augment with DM-specific fields.
	try {
		$result = WP_Agent_Conversation_Result::normalize( $result );
	} catch ( \InvalidArgumentException $e ) {
		$error_result                       = array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => 0,
			'completed'              => false,
			'last_tool_calls'        => array(),
			'tool_execution_results' => array(),
			'usage'                  => array(),
			'error'                  => $e->getMessage(),
		);
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $mode );
		return $error_result;
	}

	// Substrate now surfaces turn_count, final_content, usage, and
	// request_metadata directly on the result (agents-api#136). Augment with
	// DM-only fields that the substrate doesn't know about.
	$result['completed']       = 'budget_exceeded' !== ( $result['status'] ?? '' );
	$result['last_tool_calls'] = $last_tool_calls;
	if ( ! empty( $completion_nudges ) ) {
		$latest_nudge                              = $completion_nudges[ count( $completion_nudges ) - 1 ];
		$result['completion_nudge_count']          = count( $completion_nudges );
		$result['completion_nudge']                = $latest_nudge['completion_nudge'] ?? '';
		$result['completion_assertions_required']  = $latest_nudge['completion_assertions_required'] ?? array();
		$result['completion_assertions_missing']   = $latest_nudge['completion_assertions_missing'] ?? array();
		$result['completion_assertions_satisfied'] = $latest_nudge['completion_assertions_satisfied'] ?? array();
	}
	if ( $assertions->hasAssertions() ) {
		$evaluation                                = $assertions->evaluate( $loop_payload, $result['final_content'] ?? '' );
		$result['completion_assertions_required']  = $assertions->required();
		$result['completion_assertions_missing']   = $evaluation['missing'];
		$result['completion_assertions_satisfied'] = $evaluation['satisfied'];
		$result['completion_assertions_complete']  = ! empty( $evaluation['complete'] );
		if ( ! empty( $evaluation['complete'] ) && 'budget_exceeded' !== ( $result['status'] ?? '' ) ) {
			$result['completed'] = true;
		}
	}
	// Map upstream budget_exceeded status to DM's max_turns_reached flag
	// for backward compatibility with ChatOrchestrator response shaping.
	if ( 'budget_exceeded' === ( $result['status'] ?? '' ) && in_array( $result['budget'] ?? '', array( 'conversation_turns', 'turns' ), true ) ) {
		$result['max_turns_reached'] = true;
		$result['completed']         = false;
		$result['warning']           = sprintf(
			'Maximum conversation turns (%d) reached. Response may be incomplete.',
			$turn_budget->ceiling()
		);
	}

	$result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $result, $loop_payload, $provider, $model, $mode );

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
 * Per-turn `usage` and `request_metadata` are returned in the turn runner's
 * result array; the substrate accumulates and exposes them on the final
 * loop result (see agents-api#136), so callers don't need by-reference
 * accumulators for those fields.
 *
 * @param array                                     $tools              Available tools.
 * @param string                                    $provider           AI provider.
 * @param string                                    $model              AI model.
 * @param string                                    $mode               Execution mode.
 * @param array                                     $loop_payload       Cleaned payload.
 * @param LoopEventSinkInterface                    $event_sink         DM event sink.
 * @param array                                     $base_log_context   Base log context.
 * @param WP_Agent_Conversation_Completion_Policy   $completion_policy  Completion policy.
 * @param DataMachineToolRuntimeRules               $tool_runtime_rules Tool runtime rules.
 * @param array                                     &$last_tool_calls    Mutable last tool calls (DM-flavored shape).
 * @param array                                     &$completion_nudges  Mutable nudge diagnostics (DM-only).
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
	WP_Agent_Conversation_Completion_Policy $completion_policy,
	DataMachineToolRuntimeRules $tool_runtime_rules,
	array &$last_tool_calls,
	array &$completion_nudges,
	array &$last_request_metadata
): callable {
	return static function ( array $messages, array $turn_context ) use (
		$tools,
		$provider,
		$model,
		$mode,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$completion_policy,
		$tool_runtime_rules,
		&$last_tool_calls,
		&$completion_nudges,
		&$last_request_metadata
	): array {
		// The upstream loop provides the turn number via turn_context.
		$turn_count = (int) ( $turn_context['turn'] ?? 1 );

		// Build and dispatch AI request using centralized RequestBuilder.
		// Per-turn request metadata is captured locally and returned in the
		// turn result so the substrate can surface the latest one on the
		// final loop result.
		$request_metadata      = array();
		$ai_response           = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			$tools,
			$mode,
			$loop_payload,
			$request_metadata
		);
		$last_request_metadata = $request_metadata;

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
					'request_metadata' => $request_metadata,
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

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is caught by upstream loop and returned as structured array, never rendered as HTML.
			throw new \RuntimeException( $ai_response->get_error_message() );
		}

		/** @var \WordPress\AiClient\Results\DTO\GenerativeAiResult $ai_result */
		$ai_result       = $ai_response;
		$tool_calls      = datamachine_extract_tool_calls( $ai_result );
		$ai_content      = RequestBuilder::resultText( $ai_result );
		$last_tool_calls = $tool_calls;

		// Per-turn token usage. Substrate accumulates this across turns and
		// exposes the running total on the final loop result.
		$token_usage   = $ai_result->getTokenUsage();
		$turn_usage    = array(
			'prompt_tokens'     => $token_usage->getPromptTokens(),
			'completion_tokens' => $token_usage->getCompletionTokens(),
			'total_tokens'      => $token_usage->getTotalTokens(),
		);
		$finish_reason = datamachine_ai_result_finish_reason( $ai_result );
		if ( null !== $finish_reason ) {
			$request_metadata['response']['finish_reason'] = $finish_reason;
			$last_request_metadata                         = $request_metadata;
		}

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
		$completion_nudge       = '';
		$duplicate_rejected     = false;
		$runtime_rule_rejected  = false;
		if ( ! empty( $tool_calls ) ) {
			foreach ( $tool_calls as $tool_call ) {
				$tool_name       = $tool_call['name'];
				$tool_parameters = $tool_call['parameters'];

				if ( empty( $tool_name ) ) {
					do_action(
						'datamachine_log',
						'warning',
						'datamachine_run_conversation: Tool call missing name',
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
					'datamachine_run_conversation: Tool call',
					array_merge(
						$base_log_context,
						array(
							'turn'   => $turn_count,
							'tool'   => $tool_name,
							'params' => $tool_parameters,
						)
					)
				);

				$tool_def = $tools[ $tool_name ] ?? null;

				// Validate for duplicate tool calls.
				$validation_result = ConversationManager::validateToolCall( $tool_name, $tool_parameters, $messages, is_array( $tool_def ) ? $tool_def : null );
				if ( $validation_result['is_duplicate'] ) {
					$messages[]         = ConversationManager::generateDuplicateToolCallMessage( $tool_name, $turn_count, $mode );
					$duplicate_rejected = true;
					do_action(
						'datamachine_log',
						'info',
						'datamachine_run_conversation: Duplicate tool call prevented',
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

				$runtime_rule_result = $tool_runtime_rules->evaluate( $tool_name, $messages );
				if ( ! $runtime_rule_result['allowed'] ) {
					$tool_result           = array(
						'success' => false,
						'error'   => $runtime_rule_result['error'],
					);
					$messages[]            = ConversationManager::formatToolResultMessage( $tool_name, $tool_result, $tool_parameters, false, $turn_count );
					$runtime_rule_rejected = true;
					do_action(
						'datamachine_log',
						'info',
						'datamachine_run_conversation: Tool runtime rule rejected call',
						array_merge(
							$base_log_context,
							array(
								'turn_count' => $turn_count,
								'tool_name'  => $tool_name,
								'policy'     => $runtime_rule_result['context'],
							)
						)
					);
					continue;
				}

				// Add tool call message.
				$messages[] = ConversationManager::formatToolCallMessage( $tool_name, $tool_parameters, $turn_count );

				// Execute through DM's ToolExecutor (action policy, post tracking).
				$tool_payload = datamachine_payload_with_inflight_run_artifacts( $loop_payload, $tool_execution_results );

				$tool_result = ToolExecutor::executeTool(
					$tool_name,
					$tool_parameters,
					$tools,
					$tool_payload,
					$mode,
					(int) ( $tool_payload['agent_id'] ?? 0 ),
					is_array( $tool_payload['client_context'] ?? null ) ? $tool_payload['client_context'] : array()
				);

				do_action(
					'datamachine_log',
					'debug',
					'datamachine_run_conversation: Tool result',
					array_merge(
						$base_log_context,
						array(
							'turn'    => $turn_count,
							'tool'    => $tool_name,
							'success' => $tool_result['success'] ?? false,
						)
					)
				);

				$is_handler_tool = is_array( $tool_def ) && isset( $tool_def['handler'] );

				// Evaluate the DM completion policy.
				$completion_decision   = $completion_policy->recordToolResult(
					$tool_name,
					is_array( $tool_def ) ? $tool_def : null,
					$tool_result,
					array_merge(
						$turn_context,
						$loop_payload,
						array(
							'mode'            => $mode,
							'tool_parameters' => $tool_parameters,
						)
					),
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
					'runtime'         => datamachine_tool_runtime_metadata( is_array( $tool_def ) ? $tool_def : null, $tool_result ),
					'turn_count'      => $turn_count,
				);
				datamachine_persist_inflight_tool_summary( $loop_payload, $tool_execution_results );

				// Add tool result message.
				$messages[] = ConversationManager::formatToolResultMessage(
					$tool_name,
					$tool_result,
					$tool_parameters,
					$is_handler_tool,
					$turn_count
				);

				if ( ! $conversation_complete && '' === $completion_nudge ) {
					$completion_nudge = (string) ( $completion_decision->context()['continuation_message'] ?? '' );
				}

				// Break out of tool processing when conversation is complete.
				if ( $conversation_complete ) {
					break;
				}
			}

			if ( '' !== $completion_nudge && datamachine_should_append_tool_completion_nudge( $tool_execution_results, $completion_decision->context() ) ) {
				$messages[] = ConversationManager::buildConversationMessage( 'user', $completion_nudge );
				datamachine_record_completion_nudge(
					$completion_nudges,
					$event_sink,
					$base_log_context,
					$mode,
					$messages,
					$loop_payload,
					array_merge( $completion_decision->context(), array( 'continuation_message' => $completion_nudge ) ),
					$turn_count
				);
			}
		} else {
			$natural_completion_decision = $completion_policy instanceof NaturalCompletionPolicyInterface
				? $completion_policy->recordNaturalCompletion(
					$messages,
					$ai_content,
					array_merge( $turn_context, $loop_payload, array( 'mode' => $mode ) ),
					$turn_count
				)
				: \AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::complete();

			$conversation_complete = $natural_completion_decision->isComplete();
			$completion_nudge      = '';

			if ( '' !== $natural_completion_decision->message() ) {
				do_action(
					'datamachine_log',
					'debug',
					$natural_completion_decision->message(),
					array_merge( $base_log_context, $natural_completion_decision->context() )
				);
			}

			if ( ! $conversation_complete ) {
				$completion_nudge = (string) ( $natural_completion_decision->context()['continuation_message'] ?? '' );
				if ( '' !== $completion_nudge ) {
					$messages[] = ConversationManager::buildConversationMessage( 'user', $completion_nudge );
					datamachine_record_completion_nudge(
						$completion_nudges,
						$event_sink,
						$base_log_context,
						$mode,
						$messages,
						$loop_payload,
						$natural_completion_decision->context(),
						$turn_count
					);
				}
			}
		}

		return array(
			'messages'                     => $messages,
			'tool_execution_results'       => $tool_execution_results,
			'request_metadata'             => $request_metadata,
			'usage'                        => $turn_usage,
			'finish_reason'                => $finish_reason,
			'conversation_complete'        => $conversation_complete,
			'completion_nudge'             => $completion_nudge ?? '',
			'duplicate_tool_call_rejected' => $duplicate_rejected,
			'tool_runtime_rule_rejected'   => $runtime_rule_rejected,
		);
	};
}

/**
 * Best-effort finish reason extraction from wp-ai-client result DTOs.
 *
 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result AI result.
 * @return string|null
 */
function datamachine_ai_result_finish_reason( $result ): ?string {
	try {
		$candidates = $result->getCandidates();
		$candidate  = $candidates[0] ?? null;
		if ( is_object( $candidate ) && method_exists( $candidate, 'getFinishReason' ) ) {
			$reason = $candidate->getFinishReason();
			return is_scalar( $reason ) ? (string) $reason : null;
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	return null;
}

/**
 * Decide whether an assertion nudge is useful immediately after tool calls.
 *
 * Inspection-heavy agents can spend many turns reading files and runtime state.
 * Repeating the same missing-assertion nudge after every read wastes context and
 * pressures the model into completion mechanics too early. Keep immediate
 * nudges for turns that changed state, attempted completion, or made direct
 * assertion progress; natural completions still receive nudges elsewhere.
 *
 * @param array<int,array<string,mixed>> $tool_execution_results Tool results from the current turn.
 * @param array<string,mixed>            $decision_context       Completion decision context.
 */
function datamachine_should_append_tool_completion_nudge( array $tool_execution_results, array $decision_context ): bool {
	$progress_tools = array_merge(
		array_values( (array) ( $decision_context['satisfied']['tool_names'] ?? array() ) ),
		datamachine_completion_outcome_tool_names( (array) ( $decision_context['completion_assertions_required']['complete_when_any'] ?? array() ) )
	);

	foreach ( $tool_execution_results as $entry ) {
		$tool_name = (string) ( $entry['tool_name'] ?? '' );
		$runtime   = is_array( $entry['runtime'] ?? null ) ? $entry['runtime'] : array();
		if ( in_array( $tool_name, $progress_tools, true ) || 'progress' === ( $runtime['completion_signal'] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return Data Machine runtime metadata from a tool definition/result pair.
 *
 * Tool providers can declare top-level `runtime` metadata without requiring
 * Data Machine core to know extension-specific tool names.
 *
 * @param array<string,mixed>|null $tool_definition Tool definition.
 * @param array<string,mixed>      $tool_result     Tool execution result.
 * @return array<string,mixed>
 */
function datamachine_tool_runtime_metadata( ?array $tool_definition, array $tool_result = array() ): array {
	$definition_runtime = is_array( $tool_definition['runtime'] ?? null ) ? $tool_definition['runtime'] : array();
	$result_runtime     = is_array( $tool_result['runtime'] ?? null ) ? $tool_result['runtime'] : array();

	return array_merge( $definition_runtime, $result_runtime );
}

/** @return array<int,string> */
function datamachine_completion_outcome_tool_names( array $outcomes ): array {
	$tools = array();
	foreach ( $outcomes as $outcome ) {
		foreach ( (array) ( is_array( $outcome ) ? ( $outcome['tools'] ?? array() ) : array() ) as $tool ) {
			if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ) {
				$tools[] = $tool['name'];
			}
		}
	}

	return array_values( array_unique( $tools ) );
}

/**
 * Record completion nudge diagnostics for events, transcripts, and job probes.
 *
 * @param array                  $completion_nudges Mutable nudge diagnostics.
 * @param LoopEventSinkInterface $event_sink        Event sink.
 * @param array                  $base_log_context  Base log context.
 * @param string                 $mode              Runtime mode.
 * @param array                  $messages          Current conversation messages.
 * @param array                  $loop_payload      Loop payload.
 * @param array                  $decision_context  Completion decision context.
 * @param int                    $turn_count        Current turn count.
 */
function datamachine_record_completion_nudge(
	array &$completion_nudges,
	LoopEventSinkInterface $event_sink,
	array $base_log_context,
	string $mode,
	array $messages,
	array $loop_payload,
	array $decision_context,
	int $turn_count
): void {
	$diagnostic          = datamachine_completion_nudge_diagnostic( $decision_context, $turn_count, count( $completion_nudges ) + 1 );
	$completion_nudges[] = $diagnostic;

	$event_payload = array_merge(
		$base_log_context,
		$diagnostic,
		array(
			'mode'          => $mode,
			'message_count' => count( $messages ),
		)
	);

	datamachine_emit_loop_event( $event_sink, 'completion_nudge_added', $event_payload );
	do_action( 'datamachine_ai_completion_nudge_added', $mode, $messages, $loop_payload, $event_payload );

	$job_id = (int) ( $loop_payload['job_id'] ?? 0 );
	if ( $job_id > 0 && function_exists( '\datamachine_merge_engine_data' ) ) {
		\datamachine_merge_engine_data(
			$job_id,
			array(
				'completion_nudge_count'          => $diagnostic['completion_nudge_count'],
				'completion_nudge'                => $diagnostic['completion_nudge'],
				'completion_assertions_required'  => $diagnostic['completion_assertions_required'],
				'completion_assertions_missing'   => $diagnostic['completion_assertions_missing'],
				'completion_assertions_satisfied' => $diagnostic['completion_assertions_satisfied'],
				'completion_nudge_last_turn'      => $turn_count,
			)
		);
	}
}

/**
 * Persist a bounded tool summary during the loop for tools that open artifacts.
 *
 * Some artifact-aware tools are implemented outside the Data Machine tool
 * wrapper and fall back to job engine data. Keep that snapshot current before
 * the loop returns so a later PR tool can see prior daily-memory writes.
 *
 * @param array $loop_payload           Loop payload.
 * @param array $tool_execution_results Tool execution results accumulated by the loop.
 */
function datamachine_persist_inflight_tool_summary( array $loop_payload, array $tool_execution_results ): void {
	$job_id = (int) ( $loop_payload['job_id'] ?? 0 );
	if ( $job_id <= 0 || ! function_exists( '\datamachine_merge_engine_data' ) ) {
		return;
	}

	\datamachine_merge_engine_data(
		$job_id,
		array(
			'tool_execution_summary' => datamachine_summarize_tool_execution_results( $tool_execution_results, true ),
		)
	);
}

/**
 * Build a bounded completion nudge diagnostic payload.
 *
 * @param array $decision_context Completion decision context.
 * @param int   $turn_count       Current turn count.
 * @param int   $nudge_count      Nudge count.
 * @return array<string, mixed>
 */
function datamachine_completion_nudge_diagnostic( array $decision_context, int $turn_count, int $nudge_count ): array {
	$completion_nudge = (string) ( $decision_context['continuation_message'] ?? '' );
	if ( strlen( $completion_nudge ) > 2000 ) {
		$completion_nudge = substr( $completion_nudge, 0, 1997 ) . '...';
	}

	return array(
		'completion_nudge_count'          => $nudge_count,
		'completion_nudge'                => $completion_nudge,
		'completion_assertions_required'  => is_array( $decision_context['required'] ?? null ) ? $decision_context['required'] : array(),
		'completion_assertions_missing'   => is_array( $decision_context['missing'] ?? null ) ? $decision_context['missing'] : array(),
		'completion_assertions_satisfied' => is_array( $decision_context['satisfied'] ?? null ) ? $decision_context['satisfied'] : array(),
		'completion_nudge_turn'           => $turn_count,
	);
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
 * @return WP_Agent_Conversation_Completion_Policy
 */
function datamachine_resolve_completion_policy( string $mode, array $payload, ?DataMachineCompletionAssertions $assertions = null ): WP_Agent_Conversation_Completion_Policy {
	$policy = $payload['completion_policy'] ?? null;
	if ( $policy instanceof WP_Agent_Conversation_Completion_Policy ) {
		return $policy;
	}

	$assertions = $assertions ?? datamachine_resolve_completion_assertions( $payload );

	$configured_handlers = $payload['configured_handler_slugs'] ?? array();
	$configured_handlers = is_array( $configured_handlers ) ? array_values( $configured_handlers ) : array();

	if ( ! empty( $configured_handlers ) || 'pipeline' === $mode ) {
		return new DataMachineHandlerCompletionPolicy( $configured_handlers, $assertions );
	}

	return new DefaultAgentConversationCompletionPolicy( $assertions );
}

/**
 * Resolve generic completion assertions from loop payload.
 *
 * @param array $payload Loop payload.
 * @return DataMachineCompletionAssertions
 */
function datamachine_resolve_completion_assertions( array $payload ): DataMachineCompletionAssertions {
	$config = $payload['completion_assertions'] ?? array();
	return new DataMachineCompletionAssertions( is_array( $config ) ? $config : array() );
}

/**
 * Resolve generic tool runtime rules from loop payload.
 *
 * @param array $payload Loop payload.
 * @return DataMachineToolRuntimeRules
 */
function datamachine_resolve_tool_runtime_rules( array $payload ): DataMachineToolRuntimeRules {
	$config = $payload['tool_runtime_rules'] ?? array();
	return new DataMachineToolRuntimeRules( is_array( $config ) ? $config : array() );
}

/**
 * Resolve the runtime transcript persister from the payload.
 *
 * @param array $payload Loop payload.
 * @return WP_Agent_Transcript_Persister
 */
function datamachine_resolve_transcript_persister( array $payload ): WP_Agent_Transcript_Persister {
	$persister = $payload['transcript_persister'] ?? null;
	if ( $persister instanceof WP_Agent_Transcript_Persister ) {
		return $persister;
	}

	if ( ! empty( $payload['persist_transcript'] ) ) {
		return new DataMachinePipelineTranscriptPersister();
	}

	return new WP_Agent_Null_Transcript_Persister();
}

/**
 * Add an in-flight run artifact payload for tools that finalize a run.
 *
 * The AI step persists tool summaries after the conversation loop returns. A
 * PR-creation tool runs inside that same loop, so artifact-aware tools need a
 * snapshot of successful tool calls that already happened in the current loop.
 *
 * @param array $payload                Clean loop payload.
 * @param array $tool_execution_results Tool execution results accumulated so far.
 * @return array Payload with run_artifacts when they can be built.
 */
function datamachine_payload_with_inflight_run_artifacts( array $payload, array $tool_execution_results ): array {
	$job_id = (int) ( $payload['job_id'] ?? 0 );
	if ( $job_id <= 0 || empty( $tool_execution_results ) ) {
		return $payload;
	}

	$artifact_result = ( new JobArtifacts() )->get(
		$job_id,
		datamachine_summarize_tool_execution_results( $tool_execution_results, true )
	);
	if ( empty( $artifact_result['success'] ) || ! is_array( $artifact_result['artifacts'] ?? null ) ) {
		return $payload;
	}

	$payload['run_artifacts'] = $artifact_result['artifacts'];
	return $payload;
}

/**
 * Build a bounded, non-secret summary of in-flight tool calls.
 *
 * @param array $tool_execution_results Tool execution results accumulated by the loop.
 * @param bool  $include_content        Whether to keep daily memory write content for in-flight artifact export.
 * @return array<int, array<string, mixed>>
 */
function datamachine_summarize_tool_execution_results( array $tool_execution_results, bool $include_content = false ): array {
	$summaries = array();

	foreach ( $tool_execution_results as $result ) {
		if ( ! is_array( $result ) ) {
			continue;
		}

		$tool_name   = sanitize_key( (string) ( $result['tool_name'] ?? '' ) );
		$tool_result = is_array( $result['result'] ?? null ) ? $result['result'] : array();
		$parameters  = is_array( $result['parameters'] ?? null ) ? $result['parameters'] : array();
		if ( '' === $tool_name ) {
			continue;
		}

		$summary = array(
			'tool_name'  => $tool_name,
			'success'    => true === ( $tool_result['success'] ?? false ),
			'turn_count' => isset( $result['turn_count'] ) ? (int) $result['turn_count'] : null,
			'summary'    => isset( $tool_result['message'] ) ? sanitize_text_field( (string) $tool_result['message'] ) : null,
		);

		if ( 'agent_daily_memory' === $tool_name ) {
			$summary['user_id']  = isset( $parameters['user_id'] ) ? (int) $parameters['user_id'] : null;
			$summary['agent_id'] = isset( $parameters['agent_id'] ) ? (int) $parameters['agent_id'] : null;
			$summary['action']   = isset( $parameters['action'] ) ? sanitize_key( (string) $parameters['action'] ) : null;
			$summary['date']     = isset( $parameters['date'] ) ? sanitize_text_field( (string) $parameters['date'] ) : gmdate( 'Y-m-d' );
			$summary['mode']     = isset( $parameters['mode'] ) ? sanitize_key( (string) $parameters['mode'] ) : null;
			if ( $include_content && 'write' === $summary['action'] && isset( $parameters['content'] ) ) {
				$summary['content'] = (string) $parameters['content'];
			}
		}

		if ( 'agent_memory' === $tool_name ) {
			$summary['user_id']  = isset( $parameters['user_id'] ) ? (int) $parameters['user_id'] : null;
			$summary['agent_id'] = isset( $parameters['agent_id'] ) ? (int) $parameters['agent_id'] : null;
			$summary['action']   = isset( $parameters['action'] ) ? sanitize_key( (string) $parameters['action'] ) : null;
			$summary['file']     = isset( $parameters['file'] ) ? sanitize_file_name( (string) $parameters['file'] ) : 'MEMORY.md';
			$summary['section']  = isset( $parameters['section'] ) ? sanitize_text_field( (string) $parameters['section'] ) : null;
			$summary['mode']     = isset( $parameters['mode'] ) ? sanitize_key( (string) $parameters['mode'] ) : null;
			if ( $include_content && 'update' === $summary['action'] && isset( $parameters['content'] ) ) {
				$summary['content'] = (string) $parameters['content'];
			}
		}

		$summaries[] = array_filter(
			$summary,
			static fn( $value ) => null !== $value && '' !== $value
		);
	}

	return $summaries;
}

/**
 * Strip runtime-only objects from the payload before dispatching to tools/requests.
 *
 * @param array $payload Loop payload.
 * @return array Clean payload.
 */
function datamachine_payload_without_runtime_objects( array $payload ): array {
	unset( $payload['event_sink'], $payload['completion_policy'], $payload['transcript_persister'], $payload['transcript_lock'], $payload['transcript_lock_store'] );
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
			array(
				'event' => $event,
				'error' => $e->getMessage(),
			)
		);
	}
}
