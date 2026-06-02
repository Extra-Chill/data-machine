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
 * @param array  $modes       Execution modes ('pipeline', 'chat', ...).
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
	array $modes,
	array $payload = array(),
	int $max_turns = PluginSettings::DEFAULT_MAX_TURNS,
	bool $single_turn = false
): array {
	$messages = WP_Agent_Message::normalize_many( $messages );
	$modes    = Tools\ToolPolicyResolver::normalizeModes( $modes );
	$mode     = implode( ',', $modes );

	// Resolve DM runtime collaborators from the payload.
	$event_sink           = datamachine_resolve_event_sink( $payload );
	$assertions           = datamachine_resolve_completion_assertions( $payload );
	$completion_policy    = datamachine_resolve_completion_policy( $modes, $payload, $assertions );
	$tool_runtime_rules   = datamachine_resolve_tool_runtime_rules( $payload );
	$transcript_persister = datamachine_resolve_transcript_persister( $payload );
	$transcript_lock      = $payload['transcript_lock'] ?? $payload['transcript_lock_store'] ?? null;
	$interrupt_source     = is_callable( $payload['interrupt_source'] ?? null ) ? $payload['interrupt_source'] : null;

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
	$last_tool_calls              = array();
	$all_tool_calls               = array();
	$tool_execution_results       = array();
	$completion_nudges            = array();
	$last_request_metadata        = array();
	$latest_messages              = $messages;
	$latest_turn_count            = 0;
	$latest_conversation_complete = false;
	$runtime_tool_pending         = false;
	$runtime_tool_requests        = array();

	// Base log context for consistent logging.
	$base_log_context = array_filter(
		array(
			'mode'         => $mode,
			'modes'        => $modes,
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

		$error_result                       = array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => 0,
			'tool_execution_results' => array(),
			'error'                  => $error_message,
			'error_code'             => 'completion_required_tool_unavailable',
			'usage'                  => array(),
			'request_metadata'       => array(),
			'status'                 => 'error',
		);
		$error_result                       = datamachine_with_conversation_metadata(
			$error_result,
			array(
				'completed'                       => false,
				'last_tool_calls'                 => array(),
				'tool_calls'                      => array(),
				'completion_assertions_required'  => $assertions->required(),
				'unavailable_required_tool_names' => $unavailable_required_tools,
				'available_tool_names'            => array_keys( $tools ),
			)
		);
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $modes );
		return $error_result;
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
		$modes,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$completion_policy,
		$tool_runtime_rules,
		$last_tool_calls,
		$all_tool_calls,
		$tool_execution_results,
		$completion_nudges,
		$last_request_metadata,
		$latest_messages,
		$latest_turn_count,
		$latest_conversation_complete,
		$runtime_tool_pending,
		$runtime_tool_requests
	);

	// Build should_continue callback. Budget enforcement is handled by
	// upstream via the budgets option — we only check DM-specific conditions.
	$should_continue = static function ( array $result, array $turn_context ) use ( $single_turn ): bool {
		unset( $turn_context ); // Required by upstream callable signature.
		if ( ! empty( $result['runtime_tool_pending'] ) ) {
			return false;
		}
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

	$conversation_request = new \AgentsAPI\AI\WP_Agent_Conversation_Request(
		$messages,
		array(),
		null,
		array_merge( $loop_payload, array(
			'mode'  => $mode,
			'modes' => $modes,
		) ),
		array(
			'provider'  => $provider,
			'model'     => $model,
			'wordpress' => WordPressWorkspaceScope::metadata(),
		),
		$max_turns,
		$single_turn,
		WordPressWorkspaceScope::current()
	);

	// Run through the upstream substrate loop.
	try {
		$result = WP_Agent_Conversation_Loop::run(
			$messages,
			$turn_runner,
			array(
				'max_turns'             => $max_turns,
				'budgets'               => array( $turn_budget ),
				'context'               => array_merge( $loop_payload, array(
					'mode'  => $mode,
					'modes' => $modes,
				) ),
				'request'               => $conversation_request,
				'should_continue'       => $should_continue,
				'transcript_persister'  => $transcript_persister,
				'transcript_lock'       => $transcript_lock,
				'transcript_session_id' => (string) ( $loop_payload['transcript_session_id'] ?? $loop_payload['session_id'] ?? '' ),
				'transcript_lock_ttl'   => (int) ( $payload['transcript_lock_ttl'] ?? 300 ),
				'interrupt_source'      => $interrupt_source,
				'on_event'              => $on_event,
			)
		);
	} catch ( \RuntimeException $e ) {
		// The turn runner throws RuntimeException for wp-ai-client failures before
		// the substrate can return its accumulated result. Preserve the latest
		// known state from completed turns so failed job artifacts still explain
		// where the conversation stopped.
		$error_result          = array(
			'messages'               => $latest_messages,
			'final_content'          => '',
			'turn_count'             => $latest_turn_count,
			'tool_execution_results' => $tool_execution_results,
			'error'                  => $e->getMessage(),
			'usage'                  => array(),
			'request_metadata'       => $last_request_metadata,
			'status'                 => 'error',
		);
		$error_result          = datamachine_with_conversation_metadata(
			$error_result,
			array(
				'completed'       => false,
				'last_tool_calls' => $last_tool_calls,
				'tool_calls'      => $all_tool_calls,
			)
		);
		$transcript_session_id = $transcript_persister->persist( $latest_messages, $conversation_request, $error_result );
		if ( '' !== $transcript_session_id ) {
			$error_result['transcript_session_id'] = $transcript_session_id;
		}
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $modes );
		return $error_result;
	}

	// Normalize the substrate result and augment with DM-specific fields.
	try {
		$result = WP_Agent_Conversation_Result::normalize( $result );
		if ( ! empty( $tool_execution_results ) ) {
			$result['tool_execution_results'] = $tool_execution_results;
		}
	} catch ( \InvalidArgumentException $e ) {
		$error_result                       = array(
			'messages'               => $messages,
			'final_content'          => '',
			'turn_count'             => 0,
			'tool_execution_results' => array(),
			'usage'                  => array(),
			'error'                  => $e->getMessage(),
		);
		$error_result                       = datamachine_with_conversation_metadata(
			$error_result,
			array(
				'completed'       => false,
				'last_tool_calls' => array(),
				'tool_calls'      => array(),
			)
		);
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $modes );
		return $error_result;
	}

	// Substrate now surfaces turn_count, final_content, usage, and
	// request_metadata directly on the result (agents-api#136). Keep DM-only
	// diagnostics namespaced so the top level remains the Agents API result.
	$datamachine_metadata = array(
		'completed'       => ! in_array( (string) ( $result['status'] ?? '' ), array( 'budget_exceeded', 'interrupted' ), true ),
		'last_tool_calls' => $last_tool_calls,
		'tool_calls'      => $all_tool_calls,
	);
	if ( ! empty( $tool_execution_results ) ) {
		$datamachine_metadata['tool_execution_summary'] = datamachine_summarize_tool_execution_results( $tool_execution_results, false );
	}
	if ( 'interrupted' === ( $result['status'] ?? '' ) && isset( $result['interrupted'] ) ) {
		$datamachine_metadata['interrupted'] = $result['interrupted'];
	}
	$silent_max_turns_reached = ! $latest_conversation_complete
		&& (int) ( $result['turn_count'] ?? 0 ) >= $turn_budget->ceiling()
		&& 'budget_exceeded' !== ( $result['status'] ?? '' );
	if ( $silent_max_turns_reached ) {
		$datamachine_metadata['completed']         = false;
		$datamachine_metadata['max_turns_reached'] = true;
		$datamachine_metadata['warning']           = sprintf(
			'Maximum conversation turns (%d) reached. Response may be incomplete.',
			$turn_budget->ceiling()
		);
	}
	if ( $runtime_tool_pending ) {
		$datamachine_metadata['completed']                     = false;
		$datamachine_metadata['runtime_tool_pending']          = true;
		$datamachine_metadata['runtime_tool_pending_requests'] = $runtime_tool_requests;
		$result['status']                                      = 'runtime_tool_pending';
	}
	if ( ! empty( $completion_nudges ) ) {
		$latest_nudge                                   = $completion_nudges[ count( $completion_nudges ) - 1 ];
		$datamachine_metadata['completion_nudge_count'] = count( $completion_nudges );
		$datamachine_metadata['completion_nudge']       = $latest_nudge['completion_nudge'] ?? '';
		$datamachine_metadata['completion_assertions_required']  = $latest_nudge['completion_assertions_required'] ?? array();
		$datamachine_metadata['completion_assertions_missing']   = $latest_nudge['completion_assertions_missing'] ?? array();
		$datamachine_metadata['completion_assertions_satisfied'] = $latest_nudge['completion_assertions_satisfied'] ?? array();
	}
	if ( $assertions->hasAssertions() ) {
		$evaluation = $assertions->evaluate( $loop_payload, $result['final_content'] ?? '' );
		$datamachine_metadata['completion_assertions_required']  = $assertions->required();
		$datamachine_metadata['completion_assertions_missing']   = $evaluation['missing'];
		$datamachine_metadata['completion_assertions_satisfied'] = $evaluation['satisfied'];
		$datamachine_metadata['completion_assertions_complete']  = ! empty( $evaluation['complete'] );
		if ( ! empty( $evaluation['complete'] ) && 'budget_exceeded' !== ( $result['status'] ?? '' ) ) {
			$datamachine_metadata['completed'] = true;
		}
	}
	// Map upstream budget_exceeded status to DM's max-turn diagnostics for chat
	// response shaping without adding legacy aliases to the canonical result.
	if ( 'budget_exceeded' === ( $result['status'] ?? '' ) && in_array( $result['budget'] ?? '', array( 'conversation_turns', 'turns' ), true ) ) {
		$datamachine_metadata['max_turns_reached'] = true;
		$datamachine_metadata['completed']         = false;
		$datamachine_metadata['warning']           = sprintf(
			'Maximum conversation turns (%d) reached. Response may be incomplete.',
			$turn_budget->ceiling()
		);
	}

	$result                       = datamachine_with_conversation_metadata( $result, $datamachine_metadata );
	$result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $result, $loop_payload, $provider, $model, $modes );

	return $result;
}

/**
 * Attach Data Machine-only conversation diagnostics under metadata.datamachine.
 *
 * @param array $result               Canonical Agents API conversation result.
 * @param array $datamachine_metadata Data Machine diagnostics and UI hints.
 * @return array Result with namespaced Data Machine metadata.
 */
function datamachine_with_conversation_metadata( array $result, array $datamachine_metadata ): array {
	foreach ( datamachine_conversation_metadata_top_level_keys() as $key ) {
		unset( $result[ $key ] );
	}

	$metadata                = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
	$metadata['datamachine'] = array_filter(
		$datamachine_metadata,
		static fn( $value ) => null !== $value
	);
	$result['metadata']      = $metadata;

	return $result;
}

/**
 * Top-level keys owned by Data Machine metadata, not the Agents API result.
 *
 * @return string[]
 */
function datamachine_conversation_metadata_top_level_keys(): array {
	return array(
		'completed',
		'last_tool_calls',
		'tool_calls',
		'runtime_tool_pending',
		'runtime_tool_pending_requests',
		'completion_nudge_count',
		'completion_nudge',
		'completion_assertions_required',
		'completion_assertions_missing',
		'completion_assertions_satisfied',
		'completion_assertions_complete',
		'max_turns_reached',
		'warning',
		'unavailable_required_tool_names',
		'available_tool_names',
	);
}

/**
 * Read Data Machine conversation diagnostics from a loop result.
 *
 * @param array $result Conversation result.
 * @return array<string,mixed>
 */
function datamachine_conversation_metadata( array $result ): array {
	$metadata = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
	return is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
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
 * @param string                                    $mode               Comma-separated execution mode label.
 * @param array                                     $modes              Execution mode slugs.
 * @param array                                     $loop_payload       Cleaned payload.
 * @param LoopEventSinkInterface                    $event_sink         DM event sink.
 * @param array                                     $base_log_context   Base log context.
 * @param WP_Agent_Conversation_Completion_Policy   $completion_policy  Completion policy.
 * @param DataMachineToolRuntimeRules               $tool_runtime_rules Tool runtime rules.
 * @param array                                     &$last_tool_calls    Mutable last turn's tool calls (DM-flavored shape).
 * @param array                                     &$all_tool_calls     Mutable all tool calls made during the run.
 * @param array                                     &$all_tool_results   Mutable all executed tool results (DM-flavored shape).
 * @param array                                     &$completion_nudges  Mutable nudge diagnostics (DM-only).
 * @return callable Turn runner closure.
 */
function datamachine_build_turn_runner(
	array $tools,
	string $provider,
	string $model,
	string $mode,
	array $modes,
	array $loop_payload,
	LoopEventSinkInterface $event_sink,
	array $base_log_context,
	WP_Agent_Conversation_Completion_Policy $completion_policy,
	DataMachineToolRuntimeRules $tool_runtime_rules,
	array &$last_tool_calls,
	array &$all_tool_calls,
	array &$all_tool_results,
	array &$completion_nudges,
	array &$last_request_metadata,
	array &$latest_messages,
	int &$latest_turn_count,
	bool &$latest_conversation_complete,
	bool &$runtime_tool_pending,
	array &$runtime_tool_requests
): callable {
	return static function ( array $messages, array $turn_context ) use (
		$tools,
		$provider,
		$model,
		$mode,
		$modes,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$completion_policy,
		$tool_runtime_rules,
		&$last_tool_calls,
		&$all_tool_calls,
		&$all_tool_results,
		&$completion_nudges,
		&$last_request_metadata,
		&$latest_messages,
		&$latest_turn_count,
		&$latest_conversation_complete,
		&$runtime_tool_pending,
		&$runtime_tool_requests
	): array {
		// The upstream loop provides the turn number via turn_context.
		$turn_count        = (int) ( $turn_context['turn'] ?? 1 );
		$latest_turn_count = $turn_count;
		$latest_messages   = $messages;

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
			$modes,
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
		foreach ( $tool_calls as $tool_call ) {
			$all_tool_calls[] = $tool_call;
		}

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
		$runtime_pending_turn   = false;
		if ( ! empty( $tool_calls ) ) {
			$completion_decision = \AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision::complete();
			foreach ( $tool_calls as $tool_call ) {
				$tool_name       = $tool_call['name'];
				$tool_parameters = $tool_call['parameters'];
				$tool_started_at = microtime( true );

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

				// Execute through DM's ToolExecutor (action policy, post tracking),
				// except for run-scoped client tools that must be fulfilled by the
				// active transport/client instead of PHP.
				$tool_payload = datamachine_payload_with_inflight_run_artifacts( $loop_payload, $tool_execution_results );

				if ( datamachine_is_external_runtime_tool( is_array( $tool_def ) ? $tool_def : null ) ) {
					$tool_result = datamachine_fulfill_runtime_tool_call(
						$tool_call,
						is_array( $tool_def ) ? $tool_def : array(),
						$tool_payload,
						$mode,
						$modes,
						$turn_count,
						$event_sink,
						$base_log_context
					);
				} else {
					$tool_result = ToolExecutor::executeTool(
						$tool_name,
						$tool_parameters,
						$tools,
						$tool_payload,
						$mode,
						(int) ( $tool_payload['agent_id'] ?? 0 ),
						is_array( $tool_payload['client_context'] ?? null ) ? $tool_payload['client_context'] : array()
					);
				}

				if ( ! empty( $tool_result['pending'] ) && is_array( $tool_result['runtime_tool_request'] ?? null ) ) {
					$tool_trace               = datamachine_build_tool_trace( $tool_name, $tool_call, $tool_parameters, $tool_result, is_array( $tool_def ) ? $tool_def : null, $turn_count, $tool_started_at, microtime( true ) );
					$runtime_tool_pending     = true;
					$runtime_pending_turn     = true;
					$conversation_complete    = true;
					$runtime_tool_requests[]  = $tool_result['runtime_tool_request'];
					$tool_execution_results[] = array(
						'tool_name'       => $tool_name,
						'result'          => $tool_result,
						'parameters'      => $tool_parameters,
						'is_handler_tool' => false,
						'runtime'         => datamachine_tool_runtime_metadata( is_array( $tool_def ) ? $tool_def : null, $tool_result ),
						'trace'           => $tool_trace,
						'turn_count'      => $turn_count,
					);
					$all_tool_results[]       = $tool_execution_results[ count( $tool_execution_results ) - 1 ];
					datamachine_persist_inflight_tool_summary( $loop_payload, $tool_execution_results );
					break;
				}

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

				$tool_trace               = datamachine_build_tool_trace( $tool_name, $tool_call, $tool_parameters, $tool_result, is_array( $tool_def ) ? $tool_def : null, $turn_count, $tool_started_at, microtime( true ) );
				$tool_execution_results[] = array(
					'tool_name'       => $tool_name,
					'result'          => $tool_result,
					'parameters'      => $tool_parameters,
					'is_handler_tool' => $is_handler_tool,
					'runtime'         => datamachine_tool_runtime_metadata( is_array( $tool_def ) ? $tool_def : null, $tool_result ),
					'trace'           => $tool_trace,
					'turn_count'      => $turn_count,
				);
				$all_tool_results[]       = $tool_execution_results[ count( $tool_execution_results ) - 1 ];
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

			if ( ! $runtime_pending_turn && '' !== $completion_nudge && datamachine_should_append_tool_completion_nudge( $tool_execution_results, $completion_decision->context() ) ) {
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

		$latest_messages              = $messages;
		$latest_conversation_complete = $conversation_complete;

		return array(
			'messages'                     => $messages,
			'tool_execution_results'       => $tool_execution_results,
			'request_metadata'             => $request_metadata,
			'usage'                        => $turn_usage,
			'finish_reason'                => $finish_reason,
			'conversation_complete'        => $conversation_complete,
			'completion_nudge'             => $completion_nudge,
			'duplicate_tool_call_rejected' => $duplicate_rejected,
			'tool_runtime_rule_rejected'   => $runtime_rule_rejected,
			'runtime_tool_pending'         => $runtime_pending_turn,
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
		if ( null !== $candidate ) {
			$reason = $candidate->getFinishReason();
			return $reason->value;
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

/**
 * Whether a tool declaration is fulfilled outside the PHP tool executor.
 *
 * @param array<string,mixed>|null $tool_definition Tool definition.
 */
function datamachine_is_external_runtime_tool( ?array $tool_definition ): bool {
	if ( null === $tool_definition ) {
		return false;
	}

	return 'client' === (string) ( $tool_definition['executor'] ?? '' ) || ! empty( $tool_definition['external_executor'] );
}

/**
 * Fulfill a model-called runtime tool via the active client/transport.
 *
 * The transport can provide a synchronous result through the
 * `datamachine_runtime_tool_result` filter, a `runtime_tool_callback` callable
 * in client_context, or a prefilled `runtime_tool_results` map keyed by call id
 * or tool name. Returning null means no client result arrived, so the run gets a
 * structured tool error instead of hanging or falling through to PHP execution.
 *
 * @param array<string,mixed>       $tool_call        Normalized model tool call.
 * @param array<string,mixed>       $tool_definition  Tool definition.
 * @param array<string,mixed>       $payload          Loop/tool payload.
 * @param string                    $mode             Comma-separated mode label.
 * @param array<int,string>         $modes            Normalized mode slugs.
 * @param int                       $turn_count       Current turn count.
 * @param LoopEventSinkInterface    $event_sink       Event sink.
 * @param array<string,mixed>       $base_log_context Base log context.
 * @return array<string,mixed> Structured tool result.
 */
function datamachine_fulfill_runtime_tool_call(
	array $tool_call,
	array $tool_definition,
	array $payload,
	string $mode,
	array $modes,
	int $turn_count,
	LoopEventSinkInterface $event_sink,
	array $base_log_context
): array {
	$tool_name      = (string) ( $tool_call['name'] ?? '' );
	$parameters     = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
	$client_context = is_array( $payload['client_context'] ?? null ) ? $payload['client_context'] : array();
	$call_id        = isset( $tool_call['id'] ) && is_scalar( $tool_call['id'] ) ? (string) $tool_call['id'] : '';
	$request        = array(
		'tool_name'      => $tool_name,
		'call_id'        => $call_id,
		'parameters'     => $parameters,
		'tool_def'       => $tool_definition,
		'turn_count'     => $turn_count,
		'mode'           => $mode,
		'modes'          => $modes,
		'agent_id'       => (int) ( $payload['agent_id'] ?? 0 ),
		'job_id'         => $payload['job_id'] ?? null,
		'session_id'     => $client_context['session_id'] ?? $payload['session_id'] ?? null,
		'client_context' => $client_context,
	);

	datamachine_emit_loop_event(
		$event_sink,
		'runtime_tool_call',
		array_merge(
			$base_log_context,
			array(
				'turn_count' => $turn_count,
				'tool_name'  => $tool_name,
				'call_id'    => $call_id,
			)
		)
	);
	do_action( 'datamachine_runtime_tool_call', $request, $payload );

	try {
		$result = apply_filters( 'datamachine_runtime_tool_result', null, $request, $payload );

		if ( null === $result && is_callable( $client_context['runtime_tool_callback'] ?? null ) ) {
			$result = call_user_func( $client_context['runtime_tool_callback'], $request, $payload );
		}

		if ( null === $result && is_array( $client_context['runtime_tool_results'] ?? null ) ) {
			$results = $client_context['runtime_tool_results'];
			if ( '' !== $call_id && array_key_exists( $call_id, $results ) ) {
				$result = $results[ $call_id ];
			} elseif ( array_key_exists( $tool_name, $results ) ) {
				$result = $results[ $tool_name ];
			}
		}
	} catch ( \Throwable $e ) {
		$result = new \WP_Error( 'runtime_tool_callback_failed', $e->getMessage() );
	}

	if ( null === $result && datamachine_should_defer_runtime_tool_call( $request, $payload ) ) {
		$deferred = datamachine_defer_runtime_tool_call( $request, $payload );
		if ( ! ( $deferred instanceof \WP_Error ) ) {
			return $deferred;
		}

		$result = $deferred;
	}

	$tool_result = datamachine_normalize_runtime_tool_result( $tool_name, $result );

	datamachine_emit_loop_event(
		$event_sink,
		'runtime_tool_result',
		array_merge(
			$base_log_context,
			array(
				'turn_count' => $turn_count,
				'tool_name'  => $tool_name,
				'call_id'    => $call_id,
				'success'    => ! empty( $tool_result['success'] ),
			)
		)
	);

	return $tool_result;
}

/**
 * Whether a runtime tool call should pause the run for async fulfillment.
 *
 * @param array<string,mixed> $request Runtime tool request.
 * @param array<string,mixed> $payload Loop/tool payload.
 */
function datamachine_should_defer_runtime_tool_call( array $request, array $payload ): bool {
	$client_context = is_array( $payload['client_context'] ?? null ) ? $payload['client_context'] : array();
	if ( empty( $client_context['runtime_tool_async'] ) ) {
		return false;
	}

	return '' !== (string) ( $request['session_id'] ?? '' );
}

/**
 * Persist a pending runtime tool request and schedule its timeout.
 *
 * @param array<string,mixed> $request Runtime tool request.
 * @param array<string,mixed> $payload Loop/tool payload.
 * @return array<string,mixed>|\WP_Error Pending tool result or persistence error.
 */
function datamachine_defer_runtime_tool_call( array $request, array $payload ): array|\WP_Error {
	$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
	$job_id  = $jobs_db->create_job(
		array(
			'pipeline_id' => null,
			'flow_id'     => null,
			'source'      => 'runtime_tool',
			'label'       => 'Runtime tool: ' . (string) ( $request['tool_name'] ?? '' ),
			'user_id'     => (int) ( $payload['user_id'] ?? 0 ),
			'agent_id'    => (int) ( $payload['agent_id'] ?? 0 ),
		)
	);

	if ( ! $job_id ) {
		return new \WP_Error( 'runtime_tool_request_not_persisted', 'Runtime tool request could not be persisted.' );
	}

	$request_id      = 'runtime_tool_' . (int) $job_id;
	$client_context  = is_array( $payload['client_context'] ?? null ) ? $payload['client_context'] : array();
	$timeout_seconds = max( 5, (int) ( $client_context['runtime_tool_timeout'] ?? 300 ) );
	$pending_request = array(
		'request_id'      => $request_id,
		'job_id'          => (int) $job_id,
		'status'          => 'pending',
		'tool_name'       => (string) ( $request['tool_name'] ?? '' ),
		'call_id'         => (string) ( $request['call_id'] ?? '' ),
		'parameters'      => is_array( $request['parameters'] ?? null ) ? $request['parameters'] : array(),
		'turn_count'      => (int) ( $request['turn_count'] ?? 0 ),
		'session_id'      => (string) ( $request['session_id'] ?? '' ),
		'user_id'         => (int) ( $payload['user_id'] ?? 0 ),
		'agent_id'        => (int) ( $payload['agent_id'] ?? 0 ),
		'mode'            => (string) ( $request['mode'] ?? '' ),
		'modes'           => is_array( $request['modes'] ?? null ) ? $request['modes'] : array(),
		'created_at'      => gmdate( 'c' ),
		'expires_at'      => gmdate( 'c', time() + $timeout_seconds ),
		'timeout_seconds' => $timeout_seconds,
	);

	$jobs_db->start_job( (int) $job_id, 'pending_runtime_tool' );
	$jobs_db->store_engine_data(
		(int) $job_id,
		array(
			'task_type'            => 'runtime_tool_request',
			'runtime_tool_request' => $pending_request,
		)
	);
	datamachine_store_runtime_tool_request_on_session( $pending_request );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + $timeout_seconds, 'datamachine_runtime_tool_timeout', array( $request_id ), 'datamachine-runtime-tools' );
	}

	do_action( 'datamachine_runtime_tool_request_deferred', $pending_request, $payload );

	return array(
		'success'              => false,
		'pending'              => true,
		'tool_name'            => $pending_request['tool_name'],
		'executor'             => 'client',
		'code'                 => 'runtime_tool_pending',
		'error'                => 'Runtime tool request is pending client fulfillment.',
		'runtime_tool_request' => $pending_request,
	);
}

/**
 * Store pending runtime tool request metadata on the owning chat session.
 *
 * @param array<string,mixed> $pending_request Pending request metadata.
 */
function datamachine_store_runtime_tool_request_on_session( array $pending_request ): void {
	$session_id = (string) ( $pending_request['session_id'] ?? '' );
	if ( '' === $session_id || ! class_exists( \DataMachine\Core\Database\Chat\ConversationStoreFactory::class ) ) {
		return;
	}

	$chat_db = \DataMachine\Core\Database\Chat\ConversationStoreFactory::get();
	$session = $chat_db->get_session( $session_id );
	if ( ! is_array( $session ) ) {
		return;
	}

	$metadata                          = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
	$metadata['runtime_tool_requests'] = is_array( $metadata['runtime_tool_requests'] ?? null )
		? $metadata['runtime_tool_requests']
		: array();
	$metadata['runtime_tool_requests'][ $pending_request['request_id'] ] = $pending_request;
	$metadata['has_pending_tools']                                       = true;
	$metadata['status']        = 'processing';
	$metadata['last_activity'] = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

	$chat_db->update_session(
		$session_id,
		is_array( $session['messages'] ?? null ) ? $session['messages'] : array(),
		$metadata,
		(string) ( $session['provider'] ?? '' ),
		(string) ( $session['model'] ?? '' )
	);
}

/**
 * Submit a client result for a deferred runtime tool request.
 *
 * @param string $request_id Runtime tool request id (`runtime_tool_<job_id>`).
 * @param mixed  $result     Client tool result.
 * @return array<string,mixed>|\WP_Error Submission result.
 */
function datamachine_submit_runtime_tool_result( string $request_id, $result ): array|\WP_Error {
	$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
	if ( $job_id <= 0 ) {
		return new \WP_Error( 'runtime_tool_request_invalid', 'Runtime tool request id is invalid.' );
	}

	$jobs_db     = new \DataMachine\Core\Database\Jobs\Jobs();
	$engine_data = $jobs_db->retrieve_engine_data( $job_id );
	$request     = is_array( $engine_data['runtime_tool_request'] ?? null ) ? $engine_data['runtime_tool_request'] : array();
	if ( empty( $request ) || 'pending' !== (string) ( $request['status'] ?? '' ) ) {
		return new \WP_Error( 'runtime_tool_request_not_pending', 'Runtime tool request is not pending.' );
	}

	$tool_result = datamachine_normalize_runtime_tool_result( (string) ( $request['tool_name'] ?? '' ), $result );
	$session_id  = (string) ( $request['session_id'] ?? '' );
	if ( '' === $session_id || ! class_exists( \DataMachine\Core\Database\Chat\ConversationStoreFactory::class ) ) {
		return new \WP_Error( 'runtime_tool_session_missing', 'Runtime tool request has no resumable chat session.' );
	}

	$chat_db = \DataMachine\Core\Database\Chat\ConversationStoreFactory::get();
	$session = $chat_db->get_session( $session_id );
	if ( ! is_array( $session ) ) {
		return new \WP_Error( 'runtime_tool_session_not_found', 'Runtime tool session was not found.' );
	}

	$messages   = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
	$messages[] = ConversationManager::formatToolResultMessage(
		(string) ( $request['tool_name'] ?? '' ),
		$tool_result,
		is_array( $request['parameters'] ?? null ) ? $request['parameters'] : array(),
		false,
		(int) ( $request['turn_count'] ?? 0 )
	);

	$metadata = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
	if ( is_array( $metadata['runtime_tool_requests'] ?? null ) && isset( $metadata['runtime_tool_requests'][ $request_id ] ) ) {
		$metadata['runtime_tool_requests'][ $request_id ]['status']       = ! empty( $tool_result['success'] ) ? 'fulfilled' : 'failed';
		$metadata['runtime_tool_requests'][ $request_id ]['fulfilled_at'] = gmdate( 'c' );
		$metadata['runtime_tool_requests'][ $request_id ]['result']       = $tool_result;
	}
	$metadata['runtime_tool_last_result'] = array(
		'request_id' => $request_id,
		'tool_name'  => (string) ( $request['tool_name'] ?? '' ),
		'success'    => ! empty( $tool_result['success'] ),
		'created_at' => gmdate( 'c' ),
	);
	$metadata['has_pending_tools']        = datamachine_session_has_pending_runtime_tools( $metadata );
	$metadata['status']                   = $metadata['has_pending_tools'] ? 'processing' : 'processing';
	$metadata['last_activity']            = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

	$chat_db->update_session(
		$session_id,
		$messages,
		$metadata,
		(string) ( $session['provider'] ?? '' ),
		(string) ( $session['model'] ?? '' )
	);

	$request['status']                   = ! empty( $tool_result['success'] ) ? 'fulfilled' : 'failed';
	$request['fulfilled_at']             = gmdate( 'c' );
	$request['result']                   = $tool_result;
	$engine_data['runtime_tool_request'] = $request;
	$jobs_db->store_engine_data( $job_id, $engine_data );
	$jobs_db->complete_job( $job_id, ! empty( $tool_result['success'] ) ? 'completed' : 'failed' );

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'datamachine_runtime_tool_resume', array( $request_id ), 'datamachine-runtime-tools' );
	}

	do_action( 'datamachine_runtime_tool_result_submitted', $request, $tool_result );

	return array(
		'success'    => true,
		'request_id' => $request_id,
		'job_id'     => $job_id,
		'scheduled'  => function_exists( 'as_enqueue_async_action' ),
	);
}

/** Resume a deferred runtime tool conversation. */
function datamachine_resume_runtime_tool_request( string $request_id ): void {
	$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
	if ( $job_id <= 0 || ! class_exists( \DataMachine\Api\Chat\ChatOrchestrator::class ) ) {
		return;
	}

	$engine_data = ( new \DataMachine\Core\Database\Jobs\Jobs() )->retrieve_engine_data( $job_id );
	$request     = is_array( $engine_data['runtime_tool_request'] ?? null ) ? $engine_data['runtime_tool_request'] : array();
	if ( empty( $request ) || 'pending' === (string) ( $request['status'] ?? '' ) ) {
		return;
	}

	\DataMachine\Api\Chat\ChatOrchestrator::processContinue(
		(string) ( $request['session_id'] ?? '' ),
		(int) ( $request['user_id'] ?? 0 )
	);
}

/** Fail and resume an expired runtime tool request. */
function datamachine_timeout_runtime_tool_request( string $request_id ): void {
	$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
	if ( $job_id <= 0 ) {
		return;
	}

	$engine_data = ( new \DataMachine\Core\Database\Jobs\Jobs() )->retrieve_engine_data( $job_id );
	$request     = is_array( $engine_data['runtime_tool_request'] ?? null ) ? $engine_data['runtime_tool_request'] : array();
	if ( empty( $request ) || 'pending' !== (string) ( $request['status'] ?? '' ) ) {
		return;
	}

	datamachine_submit_runtime_tool_result(
		$request_id,
		new \WP_Error( 'runtime_tool_timeout', 'Client runtime tool request timed out.' )
	);
}

/** @param array<string,mixed> $metadata Session metadata. */
function datamachine_session_has_pending_runtime_tools( array $metadata ): bool {
	foreach ( (array) ( $metadata['runtime_tool_requests'] ?? array() ) as $request ) {
		if ( is_array( $request ) && 'pending' === (string) ( $request['status'] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

function datamachine_runtime_tool_job_id_from_request_id( string $request_id ): int {
	if ( ! str_starts_with( $request_id, 'runtime_tool_' ) ) {
		return 0;
	}

	return max( 0, (int) substr( $request_id, strlen( 'runtime_tool_' ) ) );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'datamachine_runtime_tool_resume', __NAMESPACE__ . '\\datamachine_resume_runtime_tool_request' );
	add_action( 'datamachine_runtime_tool_timeout', __NAMESPACE__ . '\\datamachine_timeout_runtime_tool_request' );
}

/**
 * Normalize a transport-provided runtime tool result into DM's result shape.
 *
 * @param string $tool_name Tool name.
 * @param mixed  $result    Raw transport result.
 * @return array<string,mixed>
 */
function datamachine_normalize_runtime_tool_result( string $tool_name, $result ): array {
	if ( $result instanceof \WP_Error ) {
		return array(
			'success'   => false,
			'error'     => $result->get_error_message(),
			'code'      => $result->get_error_code(),
			'tool_name' => $tool_name,
			'executor'  => 'client',
		);
	}

	if ( null === $result ) {
		return array(
			'success'   => false,
			'error'     => sprintf( 'Client runtime tool "%s" did not return a result.', $tool_name ),
			'code'      => 'runtime_tool_unfulfilled',
			'tool_name' => $tool_name,
			'executor'  => 'client',
		);
	}

	if ( is_array( $result ) ) {
		$result['success']   = array_key_exists( 'success', $result ) ? (bool) $result['success'] : true;
		$result['tool_name'] = $result['tool_name'] ?? $tool_name;
		$result['executor']  = $result['executor'] ?? 'client';
		if ( array_key_exists( 'data', $result ) && ! array_key_exists( 'result', $result ) ) {
			$result['result'] = $result['data'];
		}
		return $result;
	}

	return array(
		'success'   => true,
		'tool_name' => $tool_name,
		'executor'  => 'client',
		'result'    => $result,
	);
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

	if ( empty( $tool_calls ) ) {
		foreach ( $candidates[0]->getMessage()->getParts() as $part ) {
			$text = $part->getText();
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				continue;
			}

			$tool_calls = array_merge( $tool_calls, datamachine_extract_xml_tool_calls( $text ), datamachine_extract_json_tool_calls( $text ), datamachine_extract_tag_tool_calls( $text ), datamachine_extract_named_text_tool_calls( $text ) );
		}
	}

	return datamachine_dedupe_tool_calls( $tool_calls );
}

/**
 * Remove exact duplicate tool calls produced by fallback text parsers.
 *
 * @param array<int, array{name:string,parameters:array,id:mixed}> $tool_calls Tool calls.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_dedupe_tool_calls( array $tool_calls ): array {
	$seen   = array();
	$result = array();
	foreach ( $tool_calls as $tool_call ) {
		$key = ( $tool_call['name'] ?? '' ) . ':' . wp_json_encode( $tool_call['parameters'] ?? array() );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$result[]     = $tool_call;
	}

	return $result;
}

/**
 * Extract XML-style tool calls emitted as plain text by some providers/models.
 *
 * @param string $text Text candidate content.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_extract_xml_tool_calls( string $text ): array {
	if ( ! str_contains( $text, '<function_calls>' ) || ! preg_match_all( '/<invoke\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/invoke>/is', $text, $matches, PREG_SET_ORDER ) ) {
		return array();
	}

	$tool_calls = array();
	foreach ( $matches as $index => $match ) {
		$name = sanitize_key( (string) $match[1] );
		if ( '' === $name ) {
			continue;
		}

		$parameters = array();
		if ( preg_match_all( '/<parameter\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/parameter>/is', (string) $match[2], $parameter_matches, PREG_SET_ORDER ) ) {
			foreach ( $parameter_matches as $parameter_match ) {
				$parameter_name = sanitize_key( (string) $parameter_match[1] );
				if ( '' === $parameter_name ) {
					continue;
				}

				$parameter_value               = function_exists( '\wp_strip_all_tags' )
					? \wp_strip_all_tags( (string) $parameter_match[2] )
					: (string) preg_replace( '/<[^>]*>/', '', (string) $parameter_match[2] );
				$parameters[ $parameter_name ] = html_entity_decode( trim( $parameter_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		$tool_calls[] = array(
			'name'       => $name,
			'parameters' => $parameters,
			'id'         => 'xml-tool-call-' . ( $index + 1 ),
		);
	}

	return $tool_calls;
}

/**
 * Extract named tool calls emitted as plain text by models that do not use the
 * provider's structured tool-call transport.
 *
 * @param string $text Text candidate content.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_extract_named_text_tool_calls( string $text ): array {
	$tool_calls = array();

	$patterns = array(
		'/<(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\s+(?P<attrs>[^<>]*?)\s*\/?>(?:\s*<\/\1>)?/s',
		'/\[(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\s+(?P<attrs>[^\]]*?)\](?:.*?\[\/\1\])?/s',
	);
	foreach ( $patterns as $pattern ) {
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match['name'] );
			$parameters = datamachine_parse_text_tool_attributes( (string) $match['attrs'] );
			if ( ! datamachine_is_plausible_text_tool_name( $name ) || empty( $parameters ) || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	if ( preg_match_all( '/<tool\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/tool>/is', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match[1] );
			$parameters = array();
			if ( preg_match_all( '/<param\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/param>/is', (string) $match[2], $parameter_matches, PREG_SET_ORDER ) ) {
				foreach ( $parameter_matches as $parameter_match ) {
					$parameter_name = sanitize_key( (string) $parameter_match[1] );
					if ( '' === $parameter_name ) {
						continue;
					}

					$parameter_value               = function_exists( '\wp_strip_all_tags' )
						? \wp_strip_all_tags( (string) $parameter_match[2] )
						: (string) preg_replace( '/<[^>]*>/', '', (string) $parameter_match[2] );
					$parameters[ $parameter_name ] = html_entity_decode( trim( $parameter_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}

			if ( ! datamachine_is_plausible_text_tool_name( $name ) || empty( $parameters ) || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	if ( preg_match_all( '/(?:^|\s)to=(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\s+(?P<json>\{.*?\})(?=\s|$)/s', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match['name'] );
			$parameters = json_decode( (string) $match['json'], true );
			if ( ! datamachine_is_plausible_text_tool_name( $name ) || ! is_array( $parameters ) || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	if ( preg_match_all( '/```(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\s*(?P<json>\{.*?\})\s*```/is', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match['name'] );
			$parameters = json_decode( (string) $match['json'], true );
			if ( ! datamachine_is_plausible_text_tool_name( $name ) || ! is_array( $parameters ) || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	if ( preg_match_all( '/(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\((?P<value>["\']?[^\)]*?["\']?)\)/', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match['name'] );
			$value      = trim( (string) $match['value'], " \t\n\r\0\x0B\"'" );
			$parameters = array( 'path' => html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( ! datamachine_is_plausible_text_tool_name( $name ) || '' === $value || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	if ( preg_match_all( '/(?:^|\s)(?P<name>[a-zA-Z][a-zA-Z0-9_\-]*)\s+(?P<attrs>(?:[a-zA-Z_][a-zA-Z0-9_\-]*=(?:"[^"]*"|\'[^\']*\'|\S+)\s*){2,})/s', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name       = sanitize_key( (string) $match['name'] );
			$parameters = datamachine_parse_text_tool_attributes( (string) $match['attrs'] );
			if ( ! datamachine_is_plausible_text_tool_name( $name ) || empty( $parameters ) || ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'text-tool-call-' . ( count( $tool_calls ) + 1 ),
			);
		}
	}

	return $tool_calls;
}

/**
 * Determine whether a bare text token looks like a tool name, not wrapper text.
 *
 * @param string $name Sanitized candidate tool name.
 * @return bool
 */
function datamachine_is_plausible_text_tool_name( string $name ): bool {
	if ( '' === $name || ! str_contains( $name, '_' ) ) {
		return false;
	}

	return ! in_array( $name, array( 'function_calls', 'tool_call' ), true );
}

/**
 * Avoid executing malformed partial calls extracted from prose-like text.
 *
 * @param string $name       Tool name.
 * @param array  $parameters Parsed parameters.
 * @return bool
 */
function datamachine_text_tool_parameters_complete( string $name, array $parameters ): bool {
	if ( str_starts_with( $name, 'workspace_' ) && empty( $parameters['path'] ) ) {
		return false;
	}

	if ( 'workspace_grep' === $name && empty( $parameters['pattern'] ) ) {
		return false;
	}

	if ( 'workspace_write' === $name && ! array_key_exists( 'content', $parameters ) ) {
		return false;
	}

	if ( 'workspace_edit' === $name ) {
		$has_old_new        = array_key_exists( 'old', $parameters ) && array_key_exists( 'new', $parameters );
		$has_old_string_new = array_key_exists( 'old_string', $parameters ) && array_key_exists( 'new_string', $parameters );
		$has_search_replace = array_key_exists( 'search', $parameters ) && array_key_exists( 'replace', $parameters );
		return $has_old_new || $has_old_string_new || $has_search_replace;
	}

	return true;
}

/**
 * Parse key=value attributes from text tool-call forms.
 *
 * @param string $attributes Attribute text.
 * @return array<string, string>
 */
function datamachine_parse_text_tool_attributes( string $attributes ): array {
	$parameters = array();
	if ( ! preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_\-]*)=("[^"]*"|\'[^\']*\'|[^\s>\]]+)/', $attributes, $matches, PREG_SET_ORDER ) ) {
		return $parameters;
	}

	foreach ( $matches as $match ) {
		$name = sanitize_key( (string) $match[1] );
		if ( '' === $name ) {
			continue;
		}

		$value = trim( (string) $match[2] );
		if ( ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) || ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) ) ) {
			$value = substr( $value, 1, -1 );
		}

		$parameters[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	return $parameters;
}

/**
 * Extract JSON tool calls emitted as text by some providers/models.
 *
 * @param string $text Text candidate content.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_extract_json_tool_calls( string $text ): array {
	$payloads = array();
	if ( str_contains( $text, '<tool_call>' ) && preg_match_all( '/<tool_call>\s*(.*?)\s*<\/tool_call>/is', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$payloads[] = (string) $match[1];
		}
	}

	if ( str_contains( $text, '```' ) && preg_match_all( '/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$payloads[] = (string) $match[1];
		}
	}

	if ( empty( $payloads ) ) {
		return array();
	}

	$tool_calls = array();
	foreach ( $payloads as $index => $raw_payload ) {
		$payload = json_decode( html_entity_decode( trim( $raw_payload ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
		if ( ! is_array( $payload ) ) {
			continue;
		}

		$calls = isset( $payload['tool_calls'] ) && is_array( $payload['tool_calls'] ) ? $payload['tool_calls'] : array( $payload );
		foreach ( $calls as $call_index => $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}

			$function   = isset( $call['function'] ) && is_array( $call['function'] ) ? $call['function'] : array();
			$name       = sanitize_key( (string) ( $function['name'] ?? ( $call['name'] ?? '' ) ) );
			$parameters = $function['arguments'] ?? ( $call['arguments'] ?? ( $call['parameters'] ?? array() ) );
			if ( '' === $name ) {
				continue;
			}

			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => is_array( $parameters ) ? $parameters : datamachine_normalize_function_args( $parameters ),
				'id'         => $call['id'] ?? ( 'json-tool-call-' . ( $index + 1 ) . '-' . ( $call_index + 1 ) ),
			);
		}
	}

	return $tool_calls;
}

/**
 * Extract tag-style tool calls emitted as plain text by some providers/models.
 *
 * Supports compact forms such as `<workspace_read path="README.md" />` and
 * `<tool name="workspace_read">{"path":"README.md"}</tool>`.
 *
 * @param string $text Text candidate content.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function datamachine_extract_tag_tool_calls( string $text ): array {
	$tool_calls = array();
	$index      = 0;

	if ( preg_match_all( '/<tool\s+name=["\']([^"\']+)["\']\s*>(.*?)<\/tool>/is', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name = sanitize_key( (string) $match[1] );
			if ( '' === $name ) {
				continue;
			}

			$body       = html_entity_decode( trim( (string) $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$parameters = datamachine_normalize_function_args( $body );
			if ( '' !== $body && empty( $parameters ) ) {
				continue;
			}
			if ( ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}
			++$index;
			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'tag-tool-call-' . $index,
			);
		}
	}

	if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9_-]*)\s+([^<>]*?)\/>/s', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name = sanitize_key( (string) $match[1] );
			if ( '' === $name || ! str_contains( $name, '_' ) ) {
				continue;
			}

			$parameters = array();
			if ( preg_match_all( '/([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*(["\'])(.*?)\2/s', (string) $match[2], $attribute_matches, PREG_SET_ORDER ) ) {
				foreach ( $attribute_matches as $attribute_match ) {
					$parameter_name = sanitize_key( (string) $attribute_match[1] );
					if ( '' === $parameter_name ) {
						continue;
					}
					$parameters[ $parameter_name ] = html_entity_decode( (string) $attribute_match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			if ( ! datamachine_text_tool_parameters_complete( $name, $parameters ) ) {
				continue;
			}

			++$index;
			$tool_calls[] = array(
				'name'       => $name,
				'parameters' => $parameters,
				'id'         => 'tag-tool-call-' . $index,
			);
		}
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
 * @param array  $modes   Execution modes.
 * @param array  $payload Loop payload.
 * @return WP_Agent_Conversation_Completion_Policy
 */
function datamachine_resolve_completion_policy( array $modes, array $payload, ?DataMachineCompletionAssertions $assertions = null ): WP_Agent_Conversation_Completion_Policy {
	$policy = $payload['completion_policy'] ?? null;
	if ( $policy instanceof WP_Agent_Conversation_Completion_Policy ) {
		return $policy;
	}

	$assertions = $assertions ?? datamachine_resolve_completion_assertions( $payload );

	$configured_handlers = $payload['configured_handler_slugs'] ?? array();
	$configured_handlers = is_array( $configured_handlers ) ? array_values( $configured_handlers ) : array();

	if ( ! empty( $configured_handlers ) || in_array( 'pipeline', $modes, true ) ) {
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
 * Build a generic, redacted trace entry for an executed tool call.
 *
 * The trace intentionally describes Data Machine execution mechanics only:
 * tool identity, bounded/redacted arguments, result status, output summary,
 * optional artifact references, timing, actor/source, and caller-supplied
 * execution metadata. Eval consumers can project this into their own schemas
 * without Data Machine adopting benchmark- or grader-specific semantics.
 *
 * @param string     $tool_name       Tool name.
 * @param array      $tool_call       Normalized model tool call.
 * @param array      $tool_parameters Tool arguments.
 * @param array      $tool_result     Tool result envelope.
 * @param array|null $tool_def        Tool definition.
 * @param int        $turn_count      Conversation turn count.
 * @param float      $started_at      Unix timestamp with microseconds.
 * @param float      $ended_at        Unix timestamp with microseconds.
 * @return array<string,mixed>
 */
function datamachine_build_tool_trace( string $tool_name, array $tool_call, array $tool_parameters, array $tool_result, ?array $tool_def, int $turn_count, float $started_at, float $ended_at ): array {
	$metadata           = datamachine_tool_trace_metadata( $tool_result );
	$redacted_arguments = datamachine_redact_tool_trace_value( $tool_parameters );
	$arguments_json     = wp_json_encode( $tool_parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$result_json        = wp_json_encode( $tool_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$duration_ms        = max( 0, (int) round( ( $ended_at - $started_at ) * 1000 ) );

	$trace = array(
		'schema_version'   => 1,
		'tool_name'        => sanitize_key( $tool_name ),
		'tool_call_id'     => isset( $tool_call['id'] ) ? sanitize_text_field( (string) $tool_call['id'] ) : null,
		'turn_count'       => $turn_count,
		'actor'            => datamachine_normalize_tool_trace_actor( $metadata['actor'] ?? ( $tool_def['trace_actor'] ?? 'agent' ) ),
		'source'           => sanitize_key( (string) ( $metadata['source'] ?? ( $tool_def['trace_source'] ?? 'data_machine' ) ) ),
		'status'           => datamachine_tool_trace_status( $tool_result ),
		'started_at'       => gmdate( 'c', (int) floor( $started_at ) ),
		'ended_at'         => gmdate( 'c', (int) floor( $ended_at ) ),
		'duration_ms'      => $duration_ms,
		'arguments_sha256' => hash( 'sha256', (string) $arguments_json ),
		'result_sha256'    => hash( 'sha256', (string) $result_json ),
		'output_summary'   => datamachine_tool_trace_output_summary( $tool_result ),
		'artifact_refs'    => datamachine_tool_trace_artifact_refs( $tool_result, $metadata ),
		'metadata'         => datamachine_redact_tool_trace_value( $metadata ),
	);

	$redacted_arguments_json = wp_json_encode( $redacted_arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( strlen( (string) $redacted_arguments_json ) <= 2000 ) {
		$trace['arguments_redacted'] = $redacted_arguments;
	} else {
		$trace['arguments_redacted'] = datamachine_bound_tool_trace_value( $redacted_arguments );
		$trace['arguments_omitted']  = 'redacted_arguments_too_large';
	}

	return array_filter(
		$trace,
		static fn( $value ) => null !== $value && '' !== $value && array() !== $value
	);
}

/**
 * Keep non-secret scalar tool arguments visible when full arguments are large.
 *
 * @param mixed $value Redacted trace value.
 * @return mixed Bounded trace value.
 */
function datamachine_bound_tool_trace_value( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		$bounded = array();
		$count   = 0;
		foreach ( $value as $key => $child ) {
			if ( $count >= 20 ) {
				$bounded['__truncated__'] = true;
				break;
			}

			$bounded[ $key ] = datamachine_bound_tool_trace_value( $child );
			++$count;
		}

		return $bounded;
	}

	if ( is_string( $value ) && strlen( $value ) > 240 ) {
		return substr( $value, 0, 237 ) . '...';
	}

	return $value;
}

/** @return array<string,mixed> */
function datamachine_tool_trace_metadata( array $tool_result ): array {
	$execution_metadata = is_array( $tool_result['execution_metadata'] ?? null ) ? $tool_result['execution_metadata'] : array();
	$trace_metadata     = is_array( $tool_result['trace_metadata'] ?? null ) ? $tool_result['trace_metadata'] : array();

	return array_merge( $execution_metadata, $trace_metadata );
}

function datamachine_normalize_tool_trace_actor( mixed $actor ): string {
	$actor = sanitize_key( (string) $actor );
	return in_array( $actor, array( 'agent', 'system', 'grader', 'user' ), true ) ? $actor : 'agent';
}

function datamachine_tool_trace_status( array $tool_result ): string {
	if ( ! empty( $tool_result['pending'] ) ) {
		return 'pending';
	}

	return true === ( $tool_result['success'] ?? false ) ? 'success' : 'failed';
}

function datamachine_tool_trace_output_summary( array $tool_result ): ?string {
	foreach ( array( 'message', 'summary', 'error' ) as $key ) {
		if ( isset( $tool_result[ $key ] ) && is_scalar( $tool_result[ $key ] ) ) {
			$summary = sanitize_text_field( (string) $tool_result[ $key ] );
			return strlen( $summary ) > 500 ? substr( $summary, 0, 497 ) . '...' : $summary;
		}
	}

	return null;
}

/** @return array<int|string,mixed> */
function datamachine_tool_trace_artifact_refs( array $tool_result, array $metadata ): array {
	foreach ( array( $metadata['artifact_refs'] ?? null, $tool_result['artifact_refs'] ?? null, $tool_result['artifacts'] ?? null ) as $refs ) {
		if ( is_array( $refs ) ) {
			return datamachine_redact_tool_trace_value( $refs );
		}
	}

	return array();
}

function datamachine_redact_tool_trace_value( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		$redacted = array();
		foreach ( $value as $key => $child ) {
			$key_string = (string) $key;
			if ( preg_match( '/(api[_-]?key|auth|bearer|cookie|credential|nonce|password|secret|signature|token)/i', $key_string ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}
			$redacted[ $key ] = datamachine_redact_tool_trace_value( $child );
		}

		return $redacted;
	}

	if ( is_string( $value ) ) {
		$redacted = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/\-]+=*/i', 'Bearer [redacted]', $value );
		$redacted = preg_replace( '/\b(api[_-]?key|token|secret|password)\b\s*[:=]\s*\S+/i', '$1: [redacted]', $redacted ?? $value );
		return $redacted ?? $value;
	}

	return $value;
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
		if ( is_array( $result['trace'] ?? null ) ) {
			$summary['trace'] = $result['trace'];
		}

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
			$scope               = is_array( $tool_result['scope'] ?? null ) ? $tool_result['scope'] : array();
			$summary['user_id']  = isset( $scope['user_id'] ) ? (int) $scope['user_id'] : ( isset( $parameters['user_id'] ) ? (int) $parameters['user_id'] : null );
			$summary['agent_id'] = isset( $scope['agent_id'] ) ? (int) $scope['agent_id'] : ( isset( $parameters['agent_id'] ) ? (int) $parameters['agent_id'] : null );
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
	unset( $payload['event_sink'], $payload['completion_policy'], $payload['transcript_persister'], $payload['transcript_lock'], $payload['transcript_lock_store'], $payload['interrupt_source'] );
	return $payload;
}

/**
 * Read the calling-user identity from an AI invocation payload.
 *
 * `calling_user_id` is the human user on whose behalf the agent is acting
 * during this invocation. It is intentionally distinct from `agent_id`
 * (the acting agent identity, which is the same across invocations for a
 * given agent) and from the pipeline `user_id` (which is the flow/job
 * owner — typically an admin who scheduled the work, not someone who is
 * "calling" the agent right now).
 *
 * Producer semantics:
 *   - Chat sessions set this to the chat caller's user ID.
 *   - Pipeline executions set this to 0 (no human caller — the agent runs
 *     against a scheduled job).
 *   - System tasks (title gen, summaries, alt text) set this to 0.
 *   - REST-initiated invocations set this to the authenticated bearer
 *     token's owner when present, else `get_current_user_id()`, else 0.
 *
 * Consumer pattern (tools/directives that resolve per-user OAuth):
 *
 *   ```php
 *   $calling_user_id = datamachine_get_calling_user_id( $payload );
 *   if ( $calling_user_id > 0 ) {
 *       $account = $provider->get_account_for_user( $calling_user_id );
 *   } else {
 *       // No human caller — fall back to site-wide or skip the call.
 *   }
 *   ```
 *
 * Returns 0 when the key is absent, non-numeric, or non-positive so
 * callers can safely guard `> 0` without re-validating.
 *
 * @since 0.123.0
 *
 * @param array $payload AI invocation payload.
 * @return int Non-negative user ID. 0 means "no human caller".
 */
function datamachine_get_calling_user_id( array $payload ): int {
	$raw = $payload['calling_user_id'] ?? 0;
	if ( ! is_numeric( $raw ) ) {
		return 0;
	}
	$user_id = (int) $raw;
	return $user_id > 0 ? $user_id : 0;
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
