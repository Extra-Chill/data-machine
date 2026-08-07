<?php
/**
 * Generic agent conversation loop facade.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Call;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequences multi-turn agent execution around caller-owned adapters.
 *
 * The loop owns neutral transcript normalization, optional compaction,
 * turn sequencing, result validation, stop-condition dispatch, optional
 * tool-call mediation, completion policy, transcript persistence, and
 * lifecycle event emission. Callers supply prompt assembly, provider/model
 * dispatch, and concrete tool execution through adapters.
 */
class WP_Agent_Conversation_Loop {

	/**
	 * Run a conversation loop.
	 *
	 * The turn runner receives `(array $messages, array $context)` and must return
	 * an array. Callers may also pass `provider_turn_adapter` in options; that
	 * adapter receives `WP_Agent_Provider_Turn_Request` and returns a normalized
	 * `WP_Agent_Provider_Turn_Result` shape while the loop keeps ownership of
	 * continuation and mediated tool execution. Provider-turn results may include
		 * `continuation_messages` to append caller-supplied follow-up messages before
		 * the next turn without taking over the whole runner. When tool mediation is
		 * enabled (`tool_executor` + `tool_declarations`), the turn runner or
		 * provider-turn adapter can return a `tool_calls` key (array of `{name,
		 * parameters}`) and the loop handles execution internally. Otherwise the turn
		 * runner must return an `WP_Agent_Conversation_Result`-compatible array as
		 * before.
	 *
	 * Supported options:
	 *
	 * - `max_turns` (int): Maximum turns to run. Defaults to 1.
	 * - `budgets` (WP_Agent_Iteration_Budget[]): Named iteration budgets for bounded execution.
	 * - `context` (array): Caller-owned context passed to adapters.
	 * - `should_continue` (callable|null): Caller-owned continuation policy.
	 *   Defaults to `null` in the caller-managed path (which causes the loop to break
	 *   after one turn unless the caller supplies a callback). When tool
	 *   mediation is enabled (`tool_executor` + `tool_declarations` provided),
	 *   defaults to a callable that returns `true` while the latest turn emitted
	 *   `tool_calls` — so the loop stops on natural completion (empty `tool_calls`)
	 *   and otherwise keeps going until `completion_policy` fires, `max_turns` is
	 *   reached, or a budget is exceeded. Callers can pass `'__return_true'` to
	 *   opt into the historical continue-always behavior.
	 * - `compaction_policy` (array|null): Optional compaction policy.
	 * - `summarizer` (callable|null): Optional compaction summarizer.
	 * - `provider_turn_adapter` (WP_Agent_Provider_Turn_Adapter|callable|null): Provider dispatch adapter.
	 * - `tool_executor` (WP_Agent_Tool_Executor|null): Tool execution adapter.
	 * - `tool_declarations` (array|null): Tool declarations keyed by name.
	 * - `pre_tool_mediator` (callable|null): Optional synchronous decision callback
	 *   invoked after a mediated tool call is prepared and before execution. Receives
	 *   one array context with transcript messages, raw/prepared tool call data,
	 *   declaration, turn context, and prior mediated results. Return
	 *   `{ action: 'proceed'|'reject'|'replace_result'|'pending', ... }`.
	 * - `post_tool_result_diagnostics` (callable|null): Optional synchronous callback
	 *   for host diagnostics after mediated execution. Receives an audit-oriented
	 *   context and returns metadata stored with the tool execution result.
	 * - `completion_policy` (WP_Agent_Conversation_Completion_Policy|null): Typed completion policy.
	 * - `spin_detector` (WP_Agent_Spin_Detector|null): Optional repeated tool-call detector.
	 * - `identical_failure_tracker` (WP_Agent_Identical_Failure_Tracker|null): Optional repeated failure nudger.
	 * - `tool_result_truncator` (WP_Agent_Tool_Result_Truncator|null): Optional mediated tool result truncator.
	 * - `interrupt_source` (callable|null): Optional source checked between turns. Returns a message array or null.
	 * - `transcript_lock` or `transcript_lock_store` (WP_Agent_Conversation_Lock|null): Optional transcript lock.
	 * - `transcript_session_id` (string): Session ID to lock when a lock store is provided.
	 * - `transcript_lock_ttl` (int): Lock TTL in seconds. Defaults to 300.
	 * - `transcript_persister` (WP_Agent_Transcript_Persister|null): Transcript persister.
	 * - `runtime_tool_request_store` (WP_Agent_Runtime_Tool_Request_Store|null): Optional durable store for pending runtime-tool requests.
	 * - `on_event` (callable|null): Caller-owned lifecycle event sink `fn(string $event, array $payload)`.
	 *
	 * @param array<int, array<string, mixed>> $messages    Initial transcript messages.
	 * @param callable|null                    $turn_runner Caller-owned turn adapter.
	 * @param array<string, mixed>             $options     Loop options.
	 * @return array<string, mixed> Normalized conversation result.
	 */
	public static function run( array $messages, ?callable $turn_runner = null, array $options = array() ): array {
		$runtime_overrides     = self::resolve_runtime_overrides( $options );
		$options               = self::apply_runtime_overrides_to_options( $options, $runtime_overrides );
		$max_turns             = self::max_turns( $options['max_turns'] ?? 1 );
		$context               = self::normalize_assoc_array( $options['context'] ?? array() );
		$tool_executor         = self::resolve_tool_executor( $options );
		$rejected_declarations = array();
		$tool_declarations     = self::resolve_tool_declarations( $options, $rejected_declarations );
		$should_continue       = self::resolve_should_continue( $options, $tool_executor, $tool_declarations );
		$completion_policy     = self::resolve_completion_policy( $options );
		$transcript_persister  = self::resolve_transcript_persister( $options );
		$runtime_tool_store    = self::resolve_runtime_tool_request_store( $options );
		$transcript_lock       = self::resolve_transcript_lock( $options );
		$on_event              = self::resolve_event_sink( $options );
		$spin_detector         = self::resolve_spin_detector( $options );
		$failure_tracker       = self::resolve_identical_failure_tracker( $options );
		$result_truncator      = self::resolve_tool_result_truncator( $options );
		$tool_call_gate        = self::resolve_tool_call_gate( $options );
		$pre_tool_mediator     = self::compose_tool_call_gate_mediator( $tool_call_gate, self::resolve_pre_tool_mediator( $options ) );
		$post_tool_diagnostics = self::resolve_post_tool_result_diagnostics( $options );
		$interrupt_source      = self::resolve_interrupt_source( $options );
		$request               = self::resolve_request( $messages, $options );
		$principal             = $request->principal();
		if ( $principal instanceof WP_Agent_Execution_Principal && ! isset( $context['principal'] ) ) {
			// Thread the resolved execution principal into the run context so it
			// reaches tool execution (turn context -> tool context -> executor).
			// Ability-backed tools that declare a required capability consult the
			// principal's capability ceiling before dispatching.
			$context['principal'] = $principal;
		}
		$lock_session_id       = self::resolve_lock_session_id( $options, $request );
		$run_id                = self::resolve_run_id( $options, $request );
		$lock_ttl              = self::resolve_lock_ttl( $options );
		$lock_token            = null;
		$budget_resolution     = self::resolve_budgets( $options, $max_turns );
		$budgets               = $budget_resolution['budgets'];
		$has_explicit_turns    = $budget_resolution['has_explicit_turns'];
		$turn_runner           = self::resolve_turn_runner( $turn_runner, $options, $tool_declarations, $run_id, $lock_session_id, $request, $budgets );
		$wall_clock_started_at = microtime( true );
		$wall_clock_initial    = isset( $budgets['wall_clock_seconds'] ) ? $budgets['wall_clock_seconds']->current() : 0;
		$mediation_enabled     = null !== $tool_executor && ! empty( $tool_declarations );
		self::emit_tool_declaration_diagnostics( $on_event, $rejected_declarations, $tool_declarations, $tool_executor );
		$messages = self::normalize_messages( $messages );
		if ( '' !== $run_id && '' !== $lock_session_id ) {
			WP_Agent_Chat_Run_Control::start_run( $run_id, $lock_session_id, array( 'source' => 'conversation_loop' ) );
		}
		$events                = array();
		$tool_results          = array();
		$tool_events           = self::normalize_array_list( array() );
		$tool_audit_events     = array();
		$conversation_complete = false;
		$exceeded_budget       = null;
		$stalled               = null;
		$approval_required     = null;
		$runtime_tool_pending  = null;
		$interrupted           = null;

		// Universal observability accumulators. Turn runners may report
		// `usage` (token counts) and `request_metadata` (last provider request
		// descriptor) in their per-turn return value; the loop accumulates
		// these and exposes them in the final result so consumers don't have
		// to track them out-of-band via mutable state.
		$turns_run            = 0;
		$total_usage          = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);
		$result_metadata      = array();
		$request_metadata     = array();
		$provider_diagnostics = array();

		if ( null !== $transcript_lock && '' !== $lock_session_id ) {
			$lock_token = $transcript_lock->acquire_session_lock( $lock_session_id, $lock_ttl );
			if ( null === $lock_token || '' === $lock_token ) {
				self::emit_event( $on_event, 'transcript_lock_contention', array(
					'session_id' => $lock_session_id,
				) );

				return self::normalize_conversation_result( array(
					'messages'               => $messages,
					'tool_execution_results' => array(),
					'events'                 => array(),
					'status'                 => 'transcript_lock_contention',
				) );
			}
		}

		try {
			for ( $turn = 1; $turn <= $max_turns; ++$turn ) {
				$wall_clock_exceeded = self::check_wall_clock_budget( $budgets, $wall_clock_started_at, $wall_clock_initial, $on_event );
				if ( null !== $wall_clock_exceeded ) {
					$exceeded_budget = $wall_clock_exceeded;
					break;
				}

				$turns_run            = $turn;
				$force_continue       = false;
				$turn_context         = $context;
				$turn_context['turn'] = $turn;
				$interrupt            = self::check_runtime_cancellation( $run_id, $lock_session_id, $turn_context, $on_event );
				if ( null !== $interrupt ) {
					$messages[]  = $interrupt['message'];
					$events[]    = self::interrupt_event( $interrupt );
					$interrupted = $interrupt['metadata'];
					break;
				}

				self::emit_event( $on_event, 'turn_started', array(
					'turn'          => $turn,
					'max_turns'     => $max_turns,
					'message_count' => count( $messages ),
				) );

				$compaction = self::maybe_compact( $messages, $options );
				$messages   = $compaction['messages'];
				$events     = array_merge( $events, $compaction['events'] );

				try {
					$result = call_user_func( $turn_runner, $messages, $turn_context );
				} catch ( \Throwable $error ) {
					self::emit_event( $on_event, 'failed', array(
						'turn'  => $turn,
						'error' => $error->getMessage(),
					) );

					$failure_result = self::failure_result(
						$messages,
						$tool_results,
						$tool_events,
						$tool_audit_events,
						$events,
						$error,
						$turn,
						$total_usage,
						$request_metadata
					);

					if ( '' !== $run_id && '' !== $lock_session_id ) {
						WP_Agent_Chat_Run_Control::finish_run( $run_id, WP_Agent_Chat_Run_Control::STATUS_FAILED );
					}

					self::persist_transcript( $transcript_persister, $messages, $options, $failure_result );
					return $failure_result;
				}

				if ( ! is_array( $result ) ) {
					$error = new \InvalidArgumentException( 'invalid_agent_conversation_loop: turn runner must return an array' );

					self::emit_event( $on_event, 'failed', array(
						'turn'  => $turn,
						'error' => $error->getMessage(),
					) );

					self::persist_transcript( $transcript_persister, $messages, $options, array(
						'messages'               => $messages,
						'tool_execution_results' => $tool_results,
						'tool_events'            => $tool_events,
						'tool_audit_events'      => $tool_audit_events,
						'events'                 => $events,
					) );

					throw $error;
				}

				$interrupt = self::check_runtime_cancellation( $run_id, $lock_session_id, $turn_context, $on_event );
				if ( null !== $interrupt ) {
					$messages[]  = $interrupt['message'];
					$events[]    = self::interrupt_event( $interrupt );
					$interrupted = $interrupt['metadata'];
					break;
				}

				if ( isset( $result['provider_diagnostics'] ) && is_array( $result['provider_diagnostics'] ) ) {
					$provider_diagnostics[] = self::normalize_assoc_array( $result['provider_diagnostics'] );
				}

				if ( isset( $result['failure'] ) && is_array( $result['failure'] ) ) {
					$failure = self::normalize_provider_turn_failure( $result['failure'], $turn );
					self::emit_event( $on_event, 'failed', array(
						'turn'  => $turn,
						'error' => $failure['message'],
					) );

					$failure_result = self::normalize_conversation_result( array(
						'messages'               => $messages,
						'tool_execution_results' => self::normalize_array_list( $tool_results ),
						'tool_events'            => self::normalize_array_list( $tool_events ),
						'tool_audit_events'      => self::normalize_array_list( $tool_audit_events ),
						'events'                 => self::normalize_array_list( $events ),
						'status'                 => 'failed',
						'completed'              => false,
						'turn_count'             => $turn,
						'final_content'          => self::extract_final_content( $messages ),
						'usage'                  => $total_usage,
						'request_metadata'       => $request_metadata,
						'provider_diagnostics'   => $provider_diagnostics,
						'failure'                => $failure,
					) );

					if ( '' !== $run_id && '' !== $lock_session_id ) {
						WP_Agent_Chat_Run_Control::finish_run( $run_id, WP_Agent_Chat_Run_Control::STATUS_FAILED );
					}

					self::persist_transcript( $transcript_persister, $messages, $options, $failure_result );
					return $failure_result;
				}

				// Accumulate optional observability fields from the turn runner.
				// `usage` is a per-turn token-count array that gets summed into
				// `$total_usage`. `request_metadata` is the most recent provider
				// request descriptor and overwrites on each turn — consumers
				// typically only care about the last one.
				if ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
					$total_usage = self::accumulate_usage( $total_usage, self::normalize_assoc_array( $result['usage'] ) );
				}
				if ( isset( $result['request_metadata'] ) && is_array( $result['request_metadata'] ) ) {
					$request_metadata = self::normalize_assoc_array( $result['request_metadata'] );
				}
				if ( isset( $result['metadata'] ) && is_array( $result['metadata'] ) ) {
					$result_metadata = array_merge( $result_metadata, self::normalize_assoc_array( $result['metadata'] ) );
				}

				// When mediation is enabled, the turn runner returns tool_calls
				// and the loop handles execution. Otherwise, the caller-managed path applies.
				if ( null !== $tool_executor && $mediation_enabled && isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) ) {
					$interrupt = self::check_runtime_cancellation( $run_id, $lock_session_id, $turn_context, $on_event );
					if ( null !== $interrupt ) {
						$messages[]  = $interrupt['message'];
						$events[]    = self::interrupt_event( $interrupt );
						$interrupted = $interrupt['metadata'];
						break;
					}

					$mediation_result = WP_Agent_Tool_Mediation_Runner::run(
						$messages,
						self::normalize_assoc_array( $result ),
						$tool_executor,
						$tool_declarations,
						array(
							'completion_policy'            => $completion_policy,
							'turn_context'                 => $turn_context,
							'turn'                         => $turn,
							'on_event'                     => $on_event,
							'budgets'                      => $budgets,
							'identical_failure_tracker'    => $failure_tracker,
							'tool_result_truncator'        => $result_truncator,
							'pre_tool_mediator'            => $pre_tool_mediator,
							'prior_tool_results'           => $tool_results,
							'post_tool_result_diagnostics' => $post_tool_diagnostics,
							'runtime_tool_request_store'   => $runtime_tool_store,
						)
					);

					$messages              = $mediation_result['messages'];
					$tool_results          = array_merge( $tool_results, $mediation_result['tool_execution_results'] );
					$tool_events           = array_merge( $tool_events, $mediation_result['tool_events'] );
					$tool_audit_events     = array_merge( $tool_audit_events, $mediation_result['tool_audit_events'] );
					$events                = array_merge( $events, $mediation_result['events'] );
					$conversation_complete = $mediation_result['conversation_complete'];
					$exceeded_budget       = $mediation_result['exceeded_budget'];
					$approval_required     = $mediation_result['approval_required'] ?? null;
					$runtime_tool_pending  = $mediation_result['runtime_tool_pending'] ?? null;
					$stalled               = self::check_spin_detector( $spin_detector, $mediation_result['spin_signatures'], $turn_context, $on_event );
					$interrupt             = self::check_runtime_cancellation( $run_id, $lock_session_id, $turn_context, $on_event );
					if ( null !== $interrupt ) {
						$messages[]  = $interrupt['message'];
						$events[]    = self::interrupt_event( $interrupt );
						$interrupted = $interrupt['metadata'];
						break;
					}

					// Deterministic completion gate. When the model stops calling
					// tools (natural completion) but a configured tool-call rule
					// still requires a commit tool, the loop refuses to finish and
					// re-prompts with a runtime-owned, model-visible reason. The
					// enforcement is the loop's -- the model cannot opt out of it.
					if (
						null !== $tool_call_gate
						&& $conversation_complete
						&& empty( $result['tool_calls'] )
						&& null === $exceeded_budget
						&& null === $stalled
						&& null === $approval_required
						&& null === $runtime_tool_pending
					) {
						$completion_gate = $tool_call_gate->evaluate_completion( $messages );
						if ( ! $completion_gate['allowed'] ) {
							$conversation_complete = false;
							$force_continue        = true;
							$messages[]            = WP_Agent_Message::text(
								'user',
								$completion_gate['reason'],
								array(
									'type'           => WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED,
									'tool_call_gate' => $completion_gate['context'],
								)
							);
							$events[] = array(
								'type'     => WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED,
								'metadata' => $completion_gate['context'],
							);
							self::emit_event( $on_event, WP_Agent_Tool_Call_Gate::EVENT_COMPLETION_BLOCKED, $completion_gate['context'] );
						}
					}
				} else {
					// Caller-managed path: turn runner handles everything internally.
					$result       = self::normalize_conversation_result( $result );
					$messages     = self::normalize_messages( is_array( $result['messages'] ?? null ) ? $result['messages'] : array() );
					$tool_results = array_merge( $tool_results, self::normalize_array_list( $result['tool_execution_results'] ) );
					if ( isset( $result['tool_audit_events'] ) && is_array( $result['tool_audit_events'] ) ) {
						$tool_audit_events = array_merge( $tool_audit_events, self::normalize_array_list( $result['tool_audit_events'] ) );
					}
					if ( isset( $result['tool_events'] ) && is_array( $result['tool_events'] ) ) {
						$tool_events = array_merge( $tool_events, $result['tool_events'] );
					}
					$events = array_merge( $events, self::normalize_events( $result['events'] ?? array() ) );
					if ( isset( $result['request_metadata'] ) && is_array( $result['request_metadata'] ) ) {
						$request_metadata = self::normalize_assoc_array( $result['request_metadata'] );
					}

					// Apply completion policy to tool results from the turn runner
					// when the loop owns policy but the turn runner handled execution.
					if ( null !== $completion_policy && isset( $result['tool_execution_results'] ) && is_array( $result['tool_execution_results'] ) ) {
						foreach ( $result['tool_execution_results'] as $tool_exec_result ) {
							if ( ! is_array( $tool_exec_result ) ) {
								continue;
							}
							$tool_name = is_string( $tool_exec_result['tool_name'] ?? null ) ? $tool_exec_result['tool_name'] : '';
							$tool_def  = '' !== $tool_name ? ( $tool_declarations[ $tool_name ] ?? null ) : null;
							$decision  = $completion_policy->recordToolResult(
								$tool_name,
								is_array( $tool_def ) ? $tool_def : null,
								self::normalize_assoc_array( $tool_exec_result ),
								$turn_context,
								$turn
							);
							if ( $decision->isComplete() ) {
								$conversation_complete = true;
								break;
							}
						}
					}
				}

				// Stop conditions: budget exceeded, completion policy, or caller should_continue.
				if ( null !== $exceeded_budget ) {
					break;
				}

				if ( null !== $stalled ) {
					break;
				}

				if ( null !== $runtime_tool_pending ) {
					break;
				}

				if ( $conversation_complete ) {
					break;
				}

				$interrupt = self::check_interrupt_source( $interrupt_source, $messages, $options, $turn_context, $on_event );
				if ( null === $interrupt ) {
					$interrupt = self::check_runtime_cancellation( $run_id, $lock_session_id, $turn_context, $on_event );
				}
				if ( null !== $interrupt ) {
					$messages[] = $interrupt['message'];
					$events[]   = self::interrupt_event( $interrupt );

					if ( 'cancel' === $interrupt['action'] ) {
						$interrupted = $interrupt['metadata'];
						break;
					}
				}

				// Increment the turns budget after a completed turn.
				// Synthesized turns budgets (from max_turns) break the loop silently
				// to preserve backwards compatibility. Explicit turns budgets signal
				// budget_exceeded so callers know the stop reason.
				$turns_exceeded = self::increment_budget( $budgets, 'turns', $has_explicit_turns ? $on_event : null );
				if ( null !== $turns_exceeded ) {
					if ( $has_explicit_turns ) {
						$exceeded_budget = $turns_exceeded;
					}
					break;
				}

				// A blocked completion gate forces another turn so the model can
				// satisfy the required commit tool, overriding the natural-completion
				// stop signal the turn runner would otherwise trigger.
				if ( ! $force_continue && ( ! is_callable( $should_continue ) || ! call_user_func( $should_continue, $result, $turn_context ) ) ) {
					break;
				}
			}

			$final_result_data = array(
				'messages'               => $messages,
				'tool_execution_results' => $tool_results,
				'tool_events'            => $tool_events,
				'tool_audit_events'      => $tool_audit_events,
				'events'                 => $events,
				'turn_count'             => $turns_run,
				'final_content'          => self::extract_final_content( $messages ),
				'metadata'               => $result_metadata,
				'usage'                  => $total_usage,
				'request_metadata'       => $request_metadata,
				'provider_diagnostics'   => $provider_diagnostics,
				'completed'              => true,
			);

			if ( null !== $exceeded_budget ) {
				$final_result_data['status']    = 'budget_exceeded';
				$final_result_data['budget']    = $exceeded_budget;
				$final_result_data['completed'] = false;
			}

			if ( null !== $stalled ) {
				$final_result_data['status']    = 'stalled';
				$final_result_data['stalled']   = $stalled;
				$final_result_data['completed'] = false;
			}

			if ( null !== $approval_required ) {
				$final_result_data['status']            = 'approval_required';
				$final_result_data['approval_required'] = $approval_required;
				$final_result_data['completed']         = false;
			}

			if ( null !== $runtime_tool_pending ) {
				$final_result_data['status']               = WP_Agent_Runtime_Tool_Request::STATUS_PENDING;
				$final_result_data['runtime_tool_pending'] = $runtime_tool_pending;
				$final_result_data['completed']            = false;
			}

			if ( null !== $interrupted ) {
				$final_result_data['status']      = 'interrupted';
				$final_result_data['interrupted'] = $interrupted;
				$final_result_data['completed']   = false;
			}

			$final_result = self::normalize_conversation_result( $final_result_data );

			if ( '' !== $run_id && '' !== $lock_session_id ) {
				WP_Agent_Chat_Run_Control::finish_run( $run_id, WP_Agent_Run_Outcome::run_control_status( $final_result ) );
			}

			self::persist_transcript( $transcript_persister, $messages, $options, $final_result );

			self::emit_event( $on_event, 'completed', array(
				'turn'          => $turns_run,
				'message_count' => count( $messages ),
				'tool_results'  => count( $tool_results ),
			) );

			return $final_result;
		} finally {
			if ( null !== $transcript_lock && null !== $lock_token && '' !== $lock_session_id ) {
				try {
					$transcript_lock->release_session_lock( $lock_session_id, $lock_token );
				} catch ( \Throwable $error ) {
					// Lock release failures must not change loop results.
					unset( $error );
				}
			}
		}
	}

	/**
	 * One-call conversation convenience that supplies the default adapter.
	 *
	 * Fulfills the {@see WP_Agent_Conversation_Runner} contract intent: a caller
	 * provides messages, tool declarations, and a provider/model, and the loop
	 * runs end-to-end through {@see WP_Agent_Default_Provider_Turn_Adapter}
	 * without the caller hand-building a turn runner.
	 *
	 * The adapter is supplied through the mediated path (as `provider_turn_adapter`),
	 * so when a `tool_executor` is provided the loop executes tools and assembles
	 * the canonical envelopes itself — the adapter never executes tools.
	 *
	 * Recognized `$options` keys (all optional):
	 *
	 * - `system_prompt` (string): Default system instruction for the adapter.
	 * - `temperature` (float) / `max_tokens` (int): Forwarded to the adapter.
	 * - `prompt_input_provider` (callable): Pluggable prompt-input strategy.
	 * - `tool_executor` (WP_Agent_Tool_Executor): Enables mediated tool execution.
	 * - `completion_policy`, `max_turns`, `context`, `should_continue`, and any
	 *   other {@see WP_Agent_Conversation_Loop::run()} option are passed through.
	 *
	 * @param array<int, array<string, mixed>>    $messages          Initial transcript messages.
	 * @param array<mixed>                        $tool_declarations Tool declarations keyed by name.
	 * @param string                              $provider_id       Provider identifier.
	 * @param string                              $model_id          Model identifier.
	 * @param array<string, mixed>                $options           Loop and adapter options.
	 * @return array<string, mixed> Normalized conversation result.
	 */
	public static function run_conversation( array $messages, array $tool_declarations, string $provider_id, string $model_id, array $options = array() ): array {
		$system_prompt = is_string( $options['system_prompt'] ?? null ) ? $options['system_prompt'] : '';

		$adapter_options = array();
		foreach ( array( 'temperature', 'max_tokens', 'prompt_input_provider' ) as $adapter_key ) {
			if ( array_key_exists( $adapter_key, $options ) ) {
				$adapter_options[ $adapter_key ] = $options[ $adapter_key ];
			}
		}

		$adapter = new WP_Agent_Default_Provider_Turn_Adapter( $provider_id, $model_id, $system_prompt, $adapter_options );

		$loop_options                          = $options;
		$loop_options['provider_turn_adapter'] = $adapter;
		$loop_options['tool_declarations']     = $tool_declarations;
		unset( $loop_options['temperature'], $loop_options['max_tokens'], $loop_options['prompt_input_provider'], $loop_options['system_prompt'] );

		if ( '' !== $system_prompt ) {
			$context                  = self::normalize_assoc_array( $loop_options['context'] ?? array() );
			$context['system_prompt'] = $context['system_prompt'] ?? $system_prompt;
			$loop_options['context']  = $context;
		}

		return self::run( $messages, null, $loop_options );
	}

	/**
	 * Mediate tool calls extracted from the turn runner result.
	 *
	 * Handles the tool-call → validate → execute → message assembly cycle.
	 *
	 * @param array<string, mixed>                     $result          Turn runner result with tool_calls.
	 * @param WP_Agent_Tool_Executor                   $executor        Tool executor adapter.
	 * @param array<string, array<string, mixed>>      $declarations    Tool declarations keyed by name.
	 * @param WP_Agent_Conversation_Completion_Policy|null $policy      Completion policy.
	 * @param array<string, mixed>                     $turn_context    Turn context.
	 * @param int                                      $turn            Current turn number.
	 * @param callable|null                            $on_event        Event sink.
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets         Named iteration budgets.
	 * @param WP_Agent_Identical_Failure_Tracker|null  $failure_tracker Optional identical-failure tracker.
	 * @param WP_Agent_Tool_Result_Truncator|null      $truncator       Optional tool result truncator.
	 * @param array<int, array<string, mixed>>         $prior_messages  Transcript before this mediated turn.
	 * @param callable|null                            $pre_tool_mediator Optional pre-tool mediator.
	 * @param array<int, array<string, mixed>>         $prior_tool_results Prior mediated tool results.
	 * @param callable|null                            $post_tool_diagnostics Optional post-result diagnostics callback.
	 * @param WP_Agent_Runtime_Tool_Request_Store|null $runtime_tool_store Optional durable runtime tool request store.
	 * @return array{messages: array<int, array<string, mixed>>, tool_execution_results: array<int, array<string, mixed>>, tool_events: array<int, array<string, mixed>>, tool_audit_events: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>, conversation_complete: bool, exceeded_budget: string|null, approval_required: array<string, mixed>|null, runtime_tool_pending: array<string, mixed>|null, spin_signatures: array<int, WP_Agent_Spin_Signature>}
	 */
	public static function mediate_tool_calls(
		array $result,
		WP_Agent_Tool_Executor $executor,
		array $declarations,
		?WP_Agent_Conversation_Completion_Policy $policy,
		array $turn_context,
		int $turn,
		?callable $on_event,
		array $budgets = array(),
		?WP_Agent_Identical_Failure_Tracker $failure_tracker = null,
		?WP_Agent_Tool_Result_Truncator $truncator = null,
		array $prior_messages = array(),
		?callable $pre_tool_mediator = null,
		array $prior_tool_results = array(),
		?callable $post_tool_diagnostics = null,
		?WP_Agent_Runtime_Tool_Request_Store $runtime_tool_store = null
	): array {
		$core = new WP_Agent_Tool_Execution_Core();

		// Fall back to the prior turn's messages when the turn runner omits
		// `messages` from its return — without this, mediation starts from an
		// empty list and silently drops history between rounds.
		$messages                 = isset( $result['messages'] ) && is_array( $result['messages'] )
			? self::normalize_messages( $result['messages'] )
			: $prior_messages;
		$tool_calls               = isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) ? $result['tool_calls'] : array();
		$tool_execution_results   = array();
		$tool_events              = array();
		$tool_audit_events        = array();
		$events                   = array();
		$spin_signatures          = array();
		$complete                 = false;
		$completion_stop_recorded = false;
		$exceeded_budget          = null;
		$approval_required        = null;
		$runtime_tool_pending     = null;

		// If the turn runner returned text content, add it as an assistant message.
		if ( isset( $result['content'] ) && is_string( $result['content'] ) && '' !== $result['content'] ) {
			$messages[] = WP_Agent_Message::text( 'assistant', $result['content'] );
		}

		foreach ( $tool_calls as $index => $raw_call ) {
			if ( ! is_array( $raw_call ) ) {
				continue;
			}

			$raw_call              = self::normalize_assoc_array( $raw_call );
			$tool_name             = $raw_call['name'] ?? $raw_call['tool_name'] ?? '';
			$parameters            = is_array( $raw_call['parameters'] ?? null ) ? $raw_call['parameters'] : array();
			$parameters_for_policy = self::normalize_assoc_array( $parameters );
			$sequence              = is_int( $index ) ? $index + 1 : count( $spin_signatures ) + 1;
			$tool_call_id          = self::resolve_tool_call_id( $raw_call, $turn, $sequence );

			if ( ! is_string( $tool_name ) || '' === $tool_name ) {
				continue;
			}

			$spin_signatures[] = new WP_Agent_Spin_Signature( $tool_name, $parameters_for_policy );

			// Prepare through WP_Agent_Tool_Execution_Core so callers can mediate
			// with the same normalized tool-call shape the executor would receive.
			$tool_context                 = $turn_context;
			$tool_context['tool_call_id'] = $tool_call_id;
			$prepared                     = $core->prepareWP_Agent_Tool_Call(
				$tool_name,
				$parameters,
				$declarations,
				$tool_context
			);
			$prepared_tool_call           = self::array_or_empty( $prepared['tool_call'] ?? null );
			$prepared_tool_def            = isset( $prepared['tool_def'] ) && is_array( $prepared['tool_def'] ) ? $prepared['tool_def'] : array();
			$tool_definition              = self::associative_array_or_null( $prepared['tool_def'] ?? ( $declarations[ $tool_name ] ?? null ) );
			$parameter_exposure           = self::tool_parameter_exposure( $parameters_for_policy, $tool_definition );

			self::emit_event( $on_event, 'tool_call', array_merge(
				array(
					'turn'         => $turn,
					'tool_name'    => $tool_name,
					'tool_call_id' => $tool_call_id,
				),
				$parameter_exposure
			) );
			$tool_events[] = self::tool_event( 'tool_call', $tool_name, $tool_call_id, $turn, array_merge( array( 'status' => 'called' ), $parameter_exposure ) );

			// Add tool-call message to transcript. The structured info lives in
			// metadata/payload, but generic transcripts receive only the redacted
			// parameter envelope; in-process mediators and executors still receive raw
			// parameters explicitly through their private context.
			$messages[]        = WP_Agent_Message::toolCall(
				'',
				$tool_name,
				$parameter_exposure['parameters'],
				$turn,
				array(
					'tool_call_id'        => $tool_call_id,
					'parameters_sha256'   => $parameter_exposure['parameters_sha256'],
					'parameters_redacted' => true,
				)
			);
			$mediator_complete = false;
			$mediation_context = array(
				'messages'               => $messages,
				'raw_tool_call'          => $raw_call,
				'prepared_tool_call'     => ! empty( $prepared['ready'] ) ? $prepared_tool_call : null,
				'tool_declaration'       => $tool_definition,
				'tool_name'              => $tool_name,
				'parameters'             => $parameters,
				'tool_call_id'           => $tool_call_id,
				'turn_context'           => $turn_context,
				'turn'                   => $turn,
				'prior_tool_results'     => array_merge( $prior_tool_results, $tool_execution_results ),
				'prior_mediated_results' => $tool_execution_results,
			);
			$mediator_decision = self::pre_tool_mediation_decision(
				$pre_tool_mediator,
				$mediation_context
			);

			if ( 'reject' === $mediator_decision['action'] ) {
				$exec_result       = $mediator_decision['result'];
				$mediator_complete = $mediator_decision['complete'];
			} elseif ( 'replace_result' === $mediator_decision['action'] ) {
				$exec_result       = $mediator_decision['result'];
				$mediator_complete = $mediator_decision['complete'];
			} elseif ( 'pending' === $mediator_decision['action'] ) {
				$exec_result       = $mediator_decision['result'];
				$mediator_complete = true;
			} elseif ( empty( $prepared['ready'] ) ) {
				unset( $prepared['ready'] );
				$exec_result = $prepared;
			} else {
				$exec_result = $core->executePreparedTool(
					$prepared_tool_call,
					$prepared_tool_def,
					$executor,
					$tool_context
				);
			}

			$pending_request = self::runtime_tool_pending_request( $exec_result, $tool_name, $tool_call_id, $parameters_for_policy, $turn_context );
			if ( null !== $pending_request ) {
				$pending_request['metadata'] = array_merge(
					self::normalize_assoc_array( $pending_request['metadata'] ?? array() ),
					array( 'turn_count' => $turn )
				);
				if ( null !== $runtime_tool_store ) {
					$pending_request = WP_Agent_Runtime_Tool_Lifecycle::create_pending_request(
						$runtime_tool_store,
						$pending_request,
						array(
							'turn'         => $turn,
							'tool_name'    => $tool_name,
							'tool_call_id' => $tool_call_id,
							'turn_context' => $turn_context,
						)
					);
				}
				$pending_request_json = self::json_encode_safe( $pending_request );
				$runtime_tool_pending = $pending_request;
				$messages[]           = WP_Agent_Message::toolResult(
					false !== $pending_request_json ? $pending_request_json : '',
					$tool_name,
					array(
						'success' => false,
						'status'  => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
						'result'  => $pending_request,
					),
					array( 'tool_call_id' => $tool_call_id )
				);

				self::emit_event( $on_event, WP_Agent_Runtime_Tool_Request::STATUS_PENDING, array(
					'turn'         => $turn,
					'tool_name'    => $tool_name,
					'tool_call_id' => $tool_call_id,
					'request_id'   => $pending_request['request_id'],
				) );
				$tool_events[] = self::tool_event(
					WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
					$tool_name,
					$tool_call_id,
					$turn,
					array(
						'status'     => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
						'request_id' => $pending_request['request_id'],
					)
				);
				$complete      = true;
				break;
			}

			// Detect an approval_required envelope returned by an ability via the
			// wp_pre_execute_ability bridge handler. The envelope replaces the
			// tool_result message and halts mediation so the caller can surface
			// the pending action and resume after the host records a decision.
			$ability_envelope = is_array( $exec_result['result'] ?? null ) ? self::normalize_assoc_array( $exec_result['result'] ) : null;
			if ( null !== $ability_envelope && WP_Agent_Message::TYPE_APPROVAL_REQUIRED === ( $ability_envelope['type'] ?? null ) ) {
				$approval_required = $ability_envelope;
				$messages[]        = $ability_envelope;
				self::emit_event( $on_event, 'approval_required', array(
					'turn'         => $turn,
					'tool_name'    => $tool_name,
					'tool_call_id' => $tool_call_id,
					'action_id'    => isset( $ability_envelope['payload'] ) && is_array( $ability_envelope['payload'] ) ? ( $ability_envelope['payload']['action_id'] ?? null ) : null,
				) );
				$complete = true;
				break;
			}

			$original_exec_result = $exec_result;
			$truncation           = self::maybe_truncate_tool_result( $truncator, $exec_result, $tool_name, self::normalize_assoc_array( $tool_context ) );
			$exec_result          = $truncation['result'];

			if ( $truncation['truncated'] ) {
				$payload = array_merge(
					$truncation['metadata'],
					array(
						'turn'            => $turn,
						'tool_name'       => $tool_name,
						'tool_call_id'    => $tool_call_id,
						'original_result' => $original_exec_result,
					)
				);

				self::emit_event( $on_event, 'tool_result_truncated', $payload );
				$stored_payload = $payload;
				unset( $stored_payload['original_result'] );

				$events[] = array(
					'type'     => 'tool_result_truncated',
					'metadata' => $stored_payload,
				);
			}

			self::emit_event( $on_event, 'tool_result', array(
				'turn'         => $turn,
				'tool_name'    => $tool_name,
				'tool_call_id' => $tool_call_id,
				'success'      => (bool) ( $exec_result['success'] ?? false ),
			) );
			$tool_events[] = self::tool_event(
				'tool_result',
				$tool_name,
				$tool_call_id,
				$turn,
				array(
					'status'   => ! empty( $exec_result['success'] ) ? 'success' : 'error',
					'success'  => (bool) ( $exec_result['success'] ?? false ),
					'rejected' => 'reject' === $mediator_decision['action'],
				)
			);

			// Build the tool_execution_results entry.
			$execution_result = array(
				'tool_name'           => $tool_name,
				'tool_call_id'        => $tool_call_id,
				'result'              => $exec_result,
				'parameters'          => $parameter_exposure['parameters'],
				'parameters_sha256'   => $parameter_exposure['parameters_sha256'],
				'parameters_redacted' => true,
				'turn_count'          => $turn,
			);

			$runtime = isset( $exec_result['runtime'] ) && is_array( $exec_result['runtime'] ) ? $exec_result['runtime'] : array();
			if ( ! empty( $runtime ) ) {
				$execution_result['runtime'] = $runtime;
			}

			$diagnostics = self::post_tool_result_diagnostics(
				$post_tool_diagnostics,
				array(
					'tool_name'         => $tool_name,
					'tool_call_id'      => $tool_call_id,
					'turn'              => $turn,
					'turn_context'      => $turn_context,
					'tool_declaration'  => $tool_definition,
					'result'            => $exec_result,
					'success'           => (bool) ( $exec_result['success'] ?? false ),
					'parameters_sha256' => $parameter_exposure['parameters_sha256'],
				)
			);
			if ( ! empty( $diagnostics ) ) {
				$diagnostics_metadata            = array_merge(
					array(
						'tool_name'    => $tool_name,
						'tool_call_id' => $tool_call_id,
						'turn'         => $turn,
					),
					$diagnostics
				);
				$execution_result['diagnostics'] = $diagnostics;
				$events[]                        = array(
					'type'     => 'tool_result_diagnostics',
					'metadata' => $diagnostics_metadata,
				);
				self::emit_event( $on_event, 'tool_result_diagnostics', $diagnostics_metadata );
			}

			$tool_execution_results[] = $execution_result;

			$tool_audit_events[] = self::tool_audit_event(
				$tool_name,
				$tool_call_id,
				$parameters_for_policy,
				$exec_result,
				$tool_definition,
				$turn_context,
				$turn
			);

			// Add tool-result message to transcript.
			$result_content = ( $exec_result['success'] ?? false )
				? self::json_encode_safe( $exec_result['result'] ?? array() )
				: ( $exec_result['error'] ?? 'Tool execution failed.' );

			$messages[] = WP_Agent_Message::toolResult(
				is_string( $result_content ) ? $result_content : '',
				$tool_name,
				$exec_result,
				array( 'tool_call_id' => $tool_call_id )
			);

			$nudge = self::check_identical_failure_tracker(
				$failure_tracker,
				$tool_name,
				$parameters_for_policy,
				$exec_result,
				$turn_context,
				$on_event
			);
			if ( null !== $nudge ) {
				$nudge_message = is_string( $nudge['message'] ?? null ) || is_array( $nudge['message'] ?? null ) ? $nudge['message'] : '';
				$messages[]    = WP_Agent_Message::text(
					'assistant',
					$nudge_message,
					array(
						'type'                        => 'identical_failure_nudge',
						'identical_failure_signature' => $nudge,
					)
				);
			}

			// Increment tool-call budgets: total and per-tool-name.
			$exceeded_budget = self::increment_budget( $budgets, 'tool_calls', $on_event );
			if ( null === $exceeded_budget ) {
				$exceeded_budget = self::increment_budget( $budgets, 'tool_calls_' . $tool_name, $on_event );
			}

			if ( null !== $exceeded_budget ) {
				$complete = true;
				break;
			}

			if ( $mediator_complete ) {
				$complete = true;
				break;
			}

			// Consult completion policy. A complete decision stops future model turns,
			// but the current provider turn may contain more tool calls that still
			// require paired tool results before the transcript can be replayed.
			// The policy is consulted for every same-turn tool result — even after a
			// prior call in the batch marked the conversation complete — so its
			// internal state stays consistent; the stop event is still recorded once.
			if ( null !== $policy ) {
				$decision = $policy->recordToolResult(
					$tool_name,
					$tool_definition,
					$exec_result,
					$turn_context,
					$turn
				);

				if ( $decision->isComplete() ) {
					$complete = true;
					if ( ! $completion_stop_recorded ) {
						$events[]                 = array(
							'type'     => 'completion_policy_stop',
							'metadata' => array(
								'tool_name' => $tool_name,
								'turn'      => $turn,
								'message'   => $decision->message(),
								'context'   => $decision->context(),
							),
						);
						$completion_stop_recorded = true;
					}
					continue;
				}

				// Once the batch is complete, an incomplete decision from a later
				// same-turn tool must not append a "keep going" continuation nudge —
				// the conversation is already ending.
				if ( $complete ) {
					continue;
				}

				$continuation = self::completion_policy_continuation( $decision, $tool_name, $turn );
				if ( null !== $continuation ) {
					$messages[]     = $continuation['message'];
					$events[]       = $continuation['event'];
					$event_metadata = is_array( $continuation['event']['metadata'] ?? null ) ? $continuation['event']['metadata'] : array();
					self::emit_event( $on_event, 'completion_policy_continue', $event_metadata );
				}
			}
		}

		$continuation_messages = self::normalize_messages(
			is_array( $result['continuation_messages'] ?? null ) ? $result['continuation_messages'] : array()
		);
		foreach ( $continuation_messages as $continuation_message ) {
			$continuation_role = is_string( $continuation_message['role'] ?? null ) ? $continuation_message['role'] : '';
			$messages[]        = $continuation_message;
			$events[]          = array(
				'type'     => 'continuation_message_added',
				'metadata' => array(
					'turn' => $turn,
					'role' => $continuation_role,
				),
			);
			self::emit_event( $on_event, 'continuation_message_added', array(
				'turn' => $turn,
				'role' => $continuation_role,
			) );
		}

		// No tool calls and no continuation messages = natural completion.
		if ( empty( $tool_calls ) ) {
			$complete = empty( $continuation_messages );
		}

		return array(
			'messages'               => $messages,
			'tool_execution_results' => $tool_execution_results,
			'tool_events'            => $tool_events,
			'tool_audit_events'      => $tool_audit_events,
			'events'                 => $events,
			'conversation_complete'  => $complete,
			'exceeded_budget'        => $exceeded_budget,
			'approval_required'      => $approval_required,
			'runtime_tool_pending'   => $runtime_tool_pending,
			'spin_signatures'        => $spin_signatures,
		);
	}

	/**
	 * Build a structured runtime failure result without forcing callers to rebuild loop state.
	 *
	 * @param array<int, array<string, mixed>> $messages          Current transcript messages.
	 * @param array<int, array<string, mixed>> $tool_results      Accumulated tool execution results.
	 * @param array<mixed>                     $tool_events       Accumulated canonical tool events.
	 * @param array<int, array<string, mixed>> $tool_audit_events Accumulated audit events.
	 * @param array<int, array<string, mixed>> $events            Accumulated loop events.
	 * @param \Throwable                       $error             Runtime/provider error.
	 * @param int                              $turn              Current turn.
	 * @param array<string, mixed>             $usage             Accumulated usage.
	 * @param array<string, mixed>             $request_metadata  Latest request metadata.
	 * @return array<string, mixed> Normalized conversation result.
	 */
	private static function failure_result( array $messages, array $tool_results, array $tool_events, array $tool_audit_events, array $events, \Throwable $error, int $turn, array $usage, array $request_metadata ): array {
		return self::normalize_conversation_result( array(
			'messages'               => $messages,
			'tool_execution_results' => self::normalize_array_list( $tool_results ),
			'tool_events'            => self::normalize_array_list( $tool_events ),
			'tool_audit_events'      => self::normalize_array_list( $tool_audit_events ),
			'events'                 => self::normalize_array_list( $events ),
			'status'                 => 'failed',
			'completed'              => false,
			'turn_count'             => $turn,
			'final_content'          => self::extract_final_content( $messages ),
			'usage'                  => $usage,
			'request_metadata'       => $request_metadata,
			'failure'                => array(
				'type'       => get_class( $error ),
				'message'    => $error->getMessage(),
				'code'       => (string) $error->getCode(),
				'turn_count' => $turn,
			),
		) );
	}

	/**
	 * Normalize a pending runtime-tool request embedded in a tool execution result.
	 *
	 * @param array<string, mixed> $exec_result  Normalized tool execution result.
	 * @param string               $tool_name    Tool name.
	 * @param string               $tool_call_id Tool call id.
	 * @param array<string, mixed> $parameters   Tool parameters.
	 * @param array<string, mixed> $context      Turn context.
	 * @return array<string, mixed>|null Pending request or null.
	 */
	private static function runtime_tool_pending_request( array $exec_result, string $tool_name, string $tool_call_id, array $parameters, array $context ): ?array {
		$metadata = self::normalize_assoc_array( $exec_result['metadata'] ?? array() );
		$status   = is_string( $exec_result['status'] ?? null )
			? $exec_result['status']
			: ( is_string( $metadata['status'] ?? null ) ? $metadata['status'] : '' );
		if ( WP_Agent_Runtime_Tool_Request::STATUS_PENDING !== $status ) {
			return null;
		}

		$request = self::normalize_assoc_array( $exec_result['runtime_tool_request'] ?? ( $exec_result['result'] ?? array() ) );
		$runtime = self::normalize_assoc_array( $exec_result['runtime'] ?? array() );

		return self::normalize_assoc_array( WP_Agent_Runtime_Tool_Request::normalize( array_merge(
			$request,
			array(
				'tool_name'    => $request['tool_name'] ?? $tool_name,
				'tool_call_id' => $request['tool_call_id'] ?? $tool_call_id,
				'parameters'   => $request['parameters'] ?? $parameters,
				'run_id'       => $request['run_id'] ?? ( is_string( $context['run_id'] ?? null ) ? $context['run_id'] : '' ),
				'timeout_at'   => $request['timeout_at'] ?? ( is_string( $context['runtime_tool_timeout_at'] ?? null ) ? $context['runtime_tool_timeout_at'] : '' ),
				'runtime'      => $request['runtime'] ?? $runtime,
				'metadata'     => $request['metadata'] ?? $metadata,
			)
		) ) );
	}

	/**
	 * Build one canonical tool history event.
	 *
	 * @param string               $type         Event type.
	 * @param string               $tool_name    Tool name.
	 * @param string               $tool_call_id Tool call id.
	 * @param int                  $turn         Turn number.
	 * @param array<string, mixed> $metadata     Event metadata.
	 * @return array<string, mixed> Tool event.
	 */
	private static function tool_event( string $type, string $tool_name, string $tool_call_id, int $turn, array $metadata = array() ): array {
		$event = array(
			'type'         => $type,
			'tool_name'    => $tool_name,
			'tool_call_id' => $tool_call_id,
			'turn_count'   => $turn,
			'metadata'     => $metadata,
		);

		if ( isset( $metadata['status'] ) && is_string( $metadata['status'] ) ) {
			$event['status'] = $metadata['status'];
		}

		return $event;
	}

	/**
	 * Apply the optional tool result truncator and normalize its return shape.
	 *
	 * @param WP_Agent_Tool_Result_Truncator|null $truncator Optional truncator.
	 * @param array<string, mixed>                $result    Tool execution result.
	 * @param string                              $tool_name Tool name.
	 * @param array<string, mixed>                $context   Tool context.
	 * @return array{result: array<string, mixed>, truncated: bool, metadata: array<string, mixed>}
	 */
	private static function maybe_truncate_tool_result( ?WP_Agent_Tool_Result_Truncator $truncator, array $result, string $tool_name, array $context ): array {
		if ( null === $truncator ) {
			return array(
				'result'    => $result,
				'truncated' => false,
				'metadata'  => array(),
			);
		}

		$truncated = $truncator->truncate_result( $result, $tool_name, $context );

		return array(
			'result'    => $truncated['result'],
			'truncated' => $truncated['truncated'],
			'metadata'  => $truncated['metadata'],
		);
	}

	/**
	 * Invoke and normalize the optional pre-tool mediation decision callback.
	 *
	 * The mediator is a synchronous, storage-free product policy seam. Invalid or
	 * throwing callbacks fall back to `proceed` so the default execution path is
	 * preserved unless the mediator explicitly returns a supported decision.
	 *
	 * @param callable|null       $mediator Optional pre-tool mediator.
	 * @param array<string,mixed> $context  Tool mediation context.
	 * @return array{action: string, result: array<string,mixed>, complete: bool}
	 */
	private static function pre_tool_mediation_decision( ?callable $mediator, array $context ): array {
		$proceed  = array(
			'action'   => 'proceed',
			'result'   => array(),
			'complete' => false,
		);
		$decision = $proceed;

		if ( null !== $mediator ) {
			try {
				$decision = self::normalize_pre_tool_mediation_decision( call_user_func( $mediator, $context ), $context, $proceed );
			} catch ( \Throwable $error ) {
				unset( $error );
				$decision = $proceed;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			try {
				$decision = self::normalize_pre_tool_mediation_decision(
					apply_filters( 'agents_api_pre_tool_call_decision', $decision, $context ),
					$context,
					$decision
				);
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		return $decision;
	}

	/**
	 * Normalize a pre-tool mediation decision from an option callback or filter.
	 *
	 * @param mixed                                                        $decision Raw decision.
	 * @param array<string,mixed>                                          $context  Tool mediation context.
	 * @param array{action: string, result: array<string,mixed>, complete: bool} $fallback Fallback normalized decision.
	 * @return array{action: string, result: array<string,mixed>, complete: bool}
	 */
	private static function normalize_pre_tool_mediation_decision( $decision, array $context, array $fallback ): array {
		if ( ! is_array( $decision ) ) {
			return $fallback;
		}

		$action = $decision['action'] ?? 'proceed';
		$action = is_string( $action ) ? strtolower( trim( $action ) ) : 'proceed';
		if ( ! in_array( $action, array( 'proceed', 'reject', 'replace_result', 'pending' ), true ) ) {
			return $fallback;
		}

		if ( 'proceed' === $action ) {
			return array(
				'action'   => 'proceed',
				'result'   => array(),
				'complete' => false,
			);
		}

		$tool_name = $context['tool_name'] ?? '';
		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			return $fallback;
		}

		if ( 'reject' === $action && isset( $decision['result'] ) && is_array( $decision['result'] ) ) {
			try {
				$result = WP_Agent_Tool_Result::normalize( $decision['result'] );
				return array(
					'action'   => 'reject',
					'result'   => $result,
					'complete' => ! empty( $decision['complete'] ),
				);
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		if ( 'reject' === $action ) {
			$metadata = self::associative_array_or_null( $decision['metadata'] ?? null ) ?? array();
			if ( ! isset( $metadata['error_type'] ) ) {
				$metadata['error_type'] = 'pre_tool_mediation_rejected';
			}
			$error   = is_string( $decision['error'] ?? null ) && '' !== trim( $decision['error'] ) ? trim( $decision['error'] ) : 'Tool call rejected by pre-tool mediator.';
			$runtime = self::associative_array_or_null( $decision['runtime'] ?? null ) ?? array();
			$result  = WP_Agent_Tool_Result::error(
				$tool_name,
				$error,
				$metadata,
				$runtime
			);
		} elseif ( 'pending' === $action ) {
			$runtime      = self::associative_array_or_null( $decision['runtime'] ?? null ) ?? array();
			$metadata     = self::associative_array_or_null( $decision['metadata'] ?? null ) ?? array();
			$tool_call_id = is_string( $context['tool_call_id'] ?? null ) ? $context['tool_call_id'] : '';
			$parameters   = is_array( $context['parameters'] ?? null ) ? $context['parameters'] : array();
			$turn_context = self::normalize_assoc_array( $context['turn_context'] ?? array() );
			$request      = self::associative_array_or_null( $decision['runtime_tool_request'] ?? ( $decision['request'] ?? ( $decision['result'] ?? null ) ) ) ?? array();

			try {
				$request = WP_Agent_Runtime_Tool_Request::normalize( array_merge(
					$request,
					array(
						'tool_name'    => $request['tool_name'] ?? $tool_name,
						'tool_call_id' => $request['tool_call_id'] ?? $tool_call_id,
						'parameters'   => $request['parameters'] ?? $parameters,
						'run_id'       => $request['run_id'] ?? ( is_string( $turn_context['run_id'] ?? null ) ? $turn_context['run_id'] : '' ),
						'timeout_at'   => $request['timeout_at'] ?? ( is_string( $turn_context['runtime_tool_timeout_at'] ?? null ) ? $turn_context['runtime_tool_timeout_at'] : '' ),
						'runtime'      => $request['runtime'] ?? $runtime,
						'metadata'     => $request['metadata'] ?? $metadata,
					)
				) );
			} catch ( \Throwable $error ) {
				unset( $error );
				return $fallback;
			}

			$metadata['status'] = WP_Agent_Runtime_Tool_Request::STATUS_PENDING;
			$result             = WP_Agent_Tool_Result::normalize( array(
				'success'              => false,
				'tool_name'            => $tool_name,
				'status'               => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
				'error'                => is_string( $decision['error'] ?? null ) && '' !== trim( $decision['error'] ) ? trim( $decision['error'] ) : 'Waiting for external runtime tool result.',
				'metadata'             => $metadata,
				'runtime'              => $runtime,
				'runtime_tool_request' => $request,
			) );
		} else {
			$raw_result = $decision['result'] ?? null;
			if ( ! is_array( $raw_result ) ) {
				return $fallback;
			}
			$raw_result['tool_name'] = is_string( $raw_result['tool_name'] ?? null ) && '' !== $raw_result['tool_name'] ? $raw_result['tool_name'] : $tool_name;
			try {
				$result = WP_Agent_Tool_Result::normalize( $raw_result );
			} catch ( \Throwable $error ) {
				unset( $error );
				return $fallback;
			}
		}

		return array(
			'action'   => $action,
			'result'   => $result,
			'complete' => ! empty( $decision['complete'] ),
		);
	}

	/**
	 * Collect optional host diagnostics for a mediated tool result.
	 *
	 * @param callable|null       $callback Optional diagnostics callback.
	 * @param array<string,mixed> $context  Audit-oriented diagnostics context.
	 * @return array<string,mixed> Diagnostics metadata.
	 */
	private static function post_tool_result_diagnostics( ?callable $callback, array $context ): array {
		$diagnostics = array();

		if ( null !== $callback ) {
			try {
				$value = call_user_func( $callback, $context );
				if ( is_array( $value ) ) {
					$diagnostics = self::normalize_assoc_array( $value );
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			try {
				$value = apply_filters( 'agents_api_mediated_tool_result_diagnostics', $diagnostics, $context );
				if ( is_array( $value ) ) {
					$diagnostics = self::normalize_assoc_array( $value );
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		return $diagnostics;
	}

	/**
	 * Build a continuation message/event for non-empty incomplete policy decisions.
	 *
	 * @param WP_Agent_Conversation_Completion_Decision $decision  Completion policy decision.
	 * @param string                                    $tool_name Tool name that produced the decision.
	 * @param int                                       $turn      Current turn number.
	 * @return array{message: array<string, mixed>, event: array<string, mixed>}|null Continuation payload or null.
	 */
	private static function completion_policy_continuation( WP_Agent_Conversation_Completion_Decision $decision, string $tool_name, int $turn ): ?array {
		$message = trim( $decision->message() );
		if ( '' === $message ) {
			return null;
		}

		$metadata = array(
			'tool_name' => $tool_name,
			'turn'      => $turn,
			'message'   => $message,
			'context'   => $decision->context(),
		);

		return array(
			'message' => WP_Agent_Message::text(
				'user',
				$message,
				array(
					'type'                      => 'completion_policy_continue',
					'completion_policy_context' => $metadata,
				)
			),
			'event'   => array(
				'type'     => 'completion_policy_continue',
				'metadata' => $metadata,
			),
		);
	}

	/**
	 * Check the optional interrupt source for a between-turn message.
	 *
	 * @param callable|null        $interrupt_source Optional interrupt source.
	 * @param array<int, array<string, mixed>> $messages         Current transcript messages.
	 * @param array<string, mixed> $options          Loop options.
	 * @param array<string, mixed> $context          Current turn context.
	 * @param callable|null        $on_event         Event sink.
	 * @return array{message: array<string, mixed>, metadata: array<string, mixed>, action: string}|null Interrupt payload.
	 */
	private static function check_interrupt_source( ?callable $interrupt_source, array $messages, array $options, array $context, ?callable $on_event ): ?array {
		if ( null === $interrupt_source ) {
			return null;
		}

		$request = self::interrupt_request( $messages, $options );
		$message = call_user_func( $interrupt_source, $request, $context );

		if ( null === $message ) {
			return null;
		}

		if ( ! is_array( $message ) ) {
			self::emit_event( $on_event, 'interrupt_ignored', array(
				'reason' => 'invalid_message',
				'turn'   => self::int_value( $context['turn'] ?? 0 ),
			) );

			return null;
		}

		return self::normalize_interrupt_message( self::normalize_assoc_array( $message ), $context, $on_event );
	}

	/**
	 * Check canonical chat run-control cancellation state.
	 *
	 * @param array<string,mixed> $context Current turn context.
	 * @return array{message: array<string, mixed>, metadata: array<string, mixed>, action: string}|null
	 */
	private static function check_runtime_cancellation( string $run_id, string $lock_session_id, array $context, ?callable $on_event ): ?array {
		if ( '' === $run_id ) {
			return null;
		}

		$interrupt_message = WP_Agent_Chat_Run_Control::cancellation_interrupt_for_run( $run_id, $lock_session_id );
		if ( null === $interrupt_message ) {
			return null;
		}

		return self::normalize_interrupt_message( $interrupt_message, $context, $on_event );
	}

	/**
	 * @param array{message: array<string, mixed>, metadata: array<string, mixed>, action: string} $interrupt Interrupt payload.
	 * @return array<string,mixed>
	 */
	private static function interrupt_event( array $interrupt ): array {
		return array(
			'type'     => 'interrupt_received',
			'metadata' => $interrupt['metadata'],
		);
	}

	/**
	 * Normalize and emit a received interrupt message.
	 *
	 * @param array<string,mixed>  $message  Interrupt message.
	 * @param array<string,mixed>  $context  Current turn context.
	 * @param callable|null        $on_event Event sink.
	 * @return array{message: array<string, mixed>, metadata: array<string, mixed>, action: string}
	 */
	private static function normalize_interrupt_message( array $message, array $context, ?callable $on_event ): array {
		$normalized         = WP_Agent_Message::normalize( $message );
		$message_metadata   = isset( $normalized['metadata'] ) && is_array( $normalized['metadata'] ) ? self::normalize_assoc_array( $normalized['metadata'] ) : array();
		$action             = self::normalize_interrupt_action( $message_metadata['interrupt_action'] ?? ( $message_metadata['action'] ?? 'message' ) );
		$interrupt_metadata = $message_metadata;
		unset( $interrupt_metadata['action'], $interrupt_metadata['interrupt_action'] );

		$metadata = array_merge( $interrupt_metadata, array(
			'turn'    => self::int_value( $context['turn'] ?? 0 ),
			'action'  => $action,
			'message' => $normalized,
		) );

		self::emit_event( $on_event, 'interrupt_received', $metadata );

		return array(
			'message'  => $normalized,
			'metadata' => $metadata,
			'action'   => $action,
		);
	}

	/**
	 * Build an interrupt-source request with the current transcript.
	 *
	 * @param array<int, array<string, mixed>> $messages Current transcript messages.
	 * @param array<string, mixed> $options  Loop options.
	 * @return WP_Agent_Conversation_Request
	 */
	private static function interrupt_request( array $messages, array $options ): WP_Agent_Conversation_Request {
		$request = $options['request'] ?? null;
		if ( $request instanceof WP_Agent_Conversation_Request ) {
			return new WP_Agent_Conversation_Request(
				$messages,
				$request->tools(),
				$request->principal(),
				$request->runtimeContext(),
				$request->metadata(),
				$request->maxTurns(),
				$request->singleTurn(),
				$request->workspace(),
				$request->runtimeOverrides()
			);
		}

		return self::resolve_request( $messages, $options );
	}

	/**
	 * Normalize interrupt action vocabulary.
	 *
	 * @param mixed $action Raw action.
	 * @return string Normalized action.
	 */
	private static function normalize_interrupt_action( $action ): string {
		if ( ! is_string( $action ) ) {
			return 'message';
		}

		$action = strtolower( trim( $action ) );
		return in_array( $action, array( 'cancel', 'redirect', 'message' ), true ) ? $action : 'message';
	}

	/**
	 * Check a spin detector against tool-call signatures from one mediated turn.
	 *
	 * @param WP_Agent_Spin_Detector|null $detector   Optional spin detector.
	 * @param WP_Agent_Spin_Signature[]   $signatures Tool-call signatures.
	 * @param array<string, mixed>        $context    Current turn context.
	 * @param callable|null               $on_event   Event sink.
	 * @return array<string, mixed>|null Stalled diagnostics when the detector fires.
	 */
	private static function check_spin_detector( ?WP_Agent_Spin_Detector $detector, array $signatures, array $context, ?callable $on_event ): ?array {
		if ( null === $detector ) {
			return null;
		}

		foreach ( $signatures as $signature ) {
			if ( $detector->record_signature( $signature, $context ) ) {
				$payload = array_merge(
					$signature->to_array(),
					array(
						'turn'         => self::int_value( $context['turn'] ?? 0 ),
						'repeat_count' => $detector->repeat_count(),
						'threshold'    => $detector->threshold(),
					)
				);

				self::emit_event( $on_event, 'loop_stalled', $payload );

				return $payload;
			}
		}

		return null;
	}

	/**
	 * Check a repeated failure tracker and return nudge metadata when it fires.
	 *
	 * @param WP_Agent_Identical_Failure_Tracker|null $tracker    Optional failure tracker.
	 * @param string                                  $tool_name  Tool name.
	 * @param array<string, mixed>                    $parameters Tool parameters.
	 * @param array<string, mixed>                    $result     Tool execution result.
	 * @param array<string, mixed>                    $context    Current turn context.
	 * @param callable|null                           $on_event   Event sink.
	 * @return array<string, mixed>|null Nudge metadata when the tracker fires.
	 */
	private static function check_identical_failure_tracker(
		?WP_Agent_Identical_Failure_Tracker $tracker,
		string $tool_name,
		array $parameters,
		array $result,
		array $context,
		?callable $on_event
	): ?array {
		if ( null === $tracker || ! empty( $result['success'] ) ) {
			return null;
		}

		$signature = new WP_Agent_Identical_Failure_Signature( $tool_name, $parameters, $result );
		$message   = $tracker->record_failure( $signature, $context );
		if ( null === $message || '' === trim( $message ) ) {
			return null;
		}

		$payload = array_merge(
			$signature->to_array(),
			array(
				'turn'         => self::int_value( $context['turn'] ?? 0 ),
				'repeat_count' => $tracker->repeat_count(),
				'threshold'    => $tracker->threshold(),
				'message'      => $message,
			)
		);

		self::emit_event( $on_event, 'identical_failure_nudged', $payload );

		return $payload;
	}

	/**
	 * Resolve a stable tool-call identifier for transcript pairing.
	 *
	 * @param array<string, mixed> $raw_call Raw tool call emitted by a turn runner.
	 * @param int   $turn Current turn number.
	 * @param int   $sequence Tool-call sequence in this turn.
	 * @return string
	 */
	private static function resolve_tool_call_id( array $raw_call, int $turn, int $sequence ): string {
		$id = $raw_call['id'] ?? $raw_call['tool_call_id'] ?? '';
		if ( is_string( $id ) && '' !== trim( $id ) ) {
			return trim( $id );
		}

		return sprintf( 'tool-call-%d-%d', max( 1, $turn ), max( 1, $sequence ) );
	}

	/**
	 * Persist the transcript through the persister if available.
	 *
	 * @param WP_Agent_Transcript_Persister|null $persister Transcript persister.
	 * @param array<int, array<string, mixed>>                          $messages  Final messages.
	 * @param array<string, mixed>                                      $options   Loop options.
	 * @param array<mixed>                                              $result    Loop result.
	 */
	private static function persist_transcript(
		?WP_Agent_Transcript_Persister $persister,
		array $messages,
		array $options,
		array $result
	): void {
		if ( null === $persister ) {
			return;
		}

		$request = self::resolve_request( $messages, $options );

		try {
			$persister->persist( $messages, $request, $result );
		} catch ( \Throwable $error ) {
			// Persister failures must not change loop results.
			unset( $error );
		}
	}

	/**
	 * Resolve the request object from options, or build a minimal one.
	 *
	 * @param array<int, array<string, mixed>> $messages Current messages.
	 * @param array<string, mixed> $options  Loop options.
	 * @return WP_Agent_Conversation_Request
	 */
	private static function resolve_request( array $messages, array $options ): WP_Agent_Conversation_Request {
		$request = $options['request'] ?? null;
		if ( $request instanceof WP_Agent_Conversation_Request ) {
			return $request;
		}

		$runtime_overrides = self::resolve_runtime_overrides( $options );

		return new WP_Agent_Conversation_Request(
			$messages,
			array(),
			null,
			self::normalize_assoc_array( $options['context'] ?? array() ),
			array(),
			self::max_turns( $options['max_turns'] ?? 1 ),
			false,
			null,
			$runtime_overrides
		);
	}

	/**
	 * Resolve runtime overrides from explicit options or an agent definition.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return \WP_Agent_Runtime_Overrides Runtime overrides.
	 */
	private static function resolve_runtime_overrides( array $options ): \WP_Agent_Runtime_Overrides {
		$overrides = $options['runtime_overrides'] ?? null;
		if ( $overrides instanceof \WP_Agent_Runtime_Overrides ) {
			return $overrides;
		}

		if ( is_array( $overrides ) ) {
			return new \WP_Agent_Runtime_Overrides( self::normalize_assoc_array( $overrides ) );
		}

		$agent = $options['agent'] ?? ( is_array( $options['context'] ?? null ) ? ( $options['context']['agent'] ?? null ) : null );
		return $agent instanceof \WP_Agent ? $agent->runtime_overrides() : new \WP_Agent_Runtime_Overrides();
	}

	/**
	 * Apply non-null runtime overrides to loop options.
	 *
	 * @param array<string, mixed>          $options   Loop options.
	 * @param \WP_Agent_Runtime_Overrides $overrides Runtime overrides.
	 * @return array<string, mixed> Loop options.
	 */
	private static function apply_runtime_overrides_to_options( array $options, \WP_Agent_Runtime_Overrides $overrides ): array {
		if ( null !== $overrides->max_iterations() ) {
			$options['max_turns'] = min( self::max_turns( $options['max_turns'] ?? $overrides->max_iterations() ), $overrides->max_iterations() );
		}

		$context = self::normalize_assoc_array( $options['context'] ?? array() );
		foreach ( $overrides->to_array() as $key => $value ) {
			if ( null !== $value && array() !== $value ) {
				$context[ $key ] = $value;
			}
		}
		$options['context'] = $context;

		return $options;
	}

	/**
	 * Resolve the caller turn runner or wrap a provider-turn adapter.
	 *
	 * @param callable|null                         $turn_runner       Caller-owned turn runner.
	 * @param array<string, mixed>                  $options           Loop options.
	 * @param array<string, array<string, mixed>>   $tool_declarations Tool declarations keyed by name.
	 * @param string                                $run_id            Run identifier.
	 * @param string                                $session_id        Session identifier.
	 * @param WP_Agent_Conversation_Request         $request           Conversation request.
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets        Resolved budgets.
	 * @return callable
	 */
	private static function resolve_turn_runner( ?callable $turn_runner, array $options, array $tool_declarations, string $run_id, string $session_id, WP_Agent_Conversation_Request $request, array $budgets ): callable {
		$provider_turn_adapter = $options['provider_turn_adapter'] ?? null;
		if ( null === $provider_turn_adapter ) {
			if ( null === $turn_runner ) {
				throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: a turn runner or provider_turn_adapter is required' );
			}

			return $turn_runner;
		}

		if ( $provider_turn_adapter instanceof WP_Agent_Provider_Turn_Adapter ) {
			$adapter = array( $provider_turn_adapter, 'run_turn' );
		} elseif ( is_callable( $provider_turn_adapter ) ) {
			$adapter = $provider_turn_adapter;
		} else {
			throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: provider_turn_adapter must implement WP_Agent_Provider_Turn_Adapter or be callable' );
		}
		$mediation_enabled = self::resolve_tool_executor( $options ) instanceof WP_Agent_Tool_Executor && ! empty( $tool_declarations );

		return static function ( array $messages, array $context ) use ( $adapter, $options, $tool_declarations, $run_id, $session_id, $request, $budgets, $mediation_enabled ): array {
			$context          = self::normalize_assoc_array( $context );
			$provider_request = new WP_Agent_Provider_Turn_Request(
				$messages,
				$tool_declarations,
				self::provider_turn_model_metadata( $context, $options ),
				self::provider_turn_runtime_metadata( $context, $options ),
				$context,
				self::provider_turn_budget_metadata( $budgets ),
				$run_id,
				$session_id,
				$request->metadata()
			);

			$raw_result = call_user_func( $adapter, $provider_request );
			if ( ! is_array( $raw_result ) ) {
				throw new \InvalidArgumentException( 'invalid_agent_provider_turn_result: adapter must return an array' );
			}

			$result = WP_Agent_Provider_Turn_Result::normalize( $raw_result );
			if ( isset( $result['message'] ) && is_array( $result['message'] ) && '' === $result['content'] ) {
				$result['content'] = is_string( $result['message']['content'] ?? null ) ? $result['message']['content'] : '';
			}

			if ( ! $mediation_enabled ) {
				$events = array();
				if ( isset( $result['message'] ) && is_array( $result['message'] ) ) {
					$messages[] = $result['message'];
				} else {
					$content = is_string( $result['content'] ?? null ) ? $result['content'] : '';
					if ( '' !== $content ) {
						$messages[] = WP_Agent_Message::text( 'assistant', $content );
					}
				}
				$continuation_messages = self::normalize_messages(
					is_array( $result['continuation_messages'] ?? null ) ? $result['continuation_messages'] : array()
				);
				foreach ( $continuation_messages as $continuation_message ) {
					$continuation_role = is_string( $continuation_message['role'] ?? null ) ? $continuation_message['role'] : '';
					$messages[]        = $continuation_message;
					$events[]          = array(
						'type'     => 'continuation_message_added',
						'metadata' => array(
							'role' => $continuation_role,
						),
					);
				}

				$result['tool_execution_results'] = array();
				$result['events']                 = $events;
			}

			$result['messages'] = $messages;
			return $result;
		};
	}

	/**
	 * Extract provider/model metadata for provider-turn adapters.
	 *
	 * @param array<string, mixed> $context Turn context.
	 * @param array<string, mixed> $options Loop options.
	 * @return array<string, mixed>
	 */
	private static function provider_turn_model_metadata( array $context, array $options ): array {
		$model = self::normalize_assoc_array( $options['model'] ?? array() );
		foreach ( array( 'provider_id', 'model_id', 'temperature' ) as $key ) {
			if ( array_key_exists( $key, $context ) && ! array_key_exists( $key, $model ) ) {
				$model[ $key ] = $context[ $key ];
			}
		}

		return $model;
	}

	/**
	 * Extract runtime metadata for provider-turn adapters.
	 *
	 * @param array<string, mixed> $context Turn context.
	 * @param array<string, mixed> $options Loop options.
	 * @return array<string, mixed>
	 */
	private static function provider_turn_runtime_metadata( array $context, array $options ): array {
		$runtime = self::normalize_assoc_array( $options['runtime'] ?? array() );
		foreach ( array( 'runtime_id', 'runtime_context', 'request_kind' ) as $key ) {
			if ( array_key_exists( $key, $context ) && ! array_key_exists( $key, $runtime ) ) {
				$runtime[ $key ] = $context[ $key ];
			}
		}

		return $runtime;
	}

	/**
	 * Snapshot budget counters for provider-turn adapters.
	 *
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets Resolved budgets.
	 * @return array<string, mixed>
	 */
	private static function provider_turn_budget_metadata( array $budgets ): array {
		$metadata = array();
		foreach ( $budgets as $name => $budget ) {
			$metadata[ $name ] = array(
				'current' => $budget->current(),
				'limit'   => $budget->ceiling(),
			);
		}

		return $metadata;
	}

	/**
	 * Surface tool declarations that were dropped during normalization.
	 *
	 * Invalid declarations are silently removed from the mediation list, which
	 * can flip mediation off entirely when every declaration is rejected — the
	 * model emits tool calls but nothing executes, with no indication why. This
	 * emits a `tool_declarations_rejected` event (with each declaration's name
	 * and validation reason) whenever any are dropped, plus a prominent
	 * `tool_mediation_disabled` event when declarations were supplied alongside a
	 * tool executor but all of them were rejected.
	 *
	 * @param callable|null                                    $on_event          Event sink.
	 * @param array<int, array{name: string, reason: string}>  $rejected          Rejected declarations with reasons.
	 * @param array<string, array<string, mixed>>              $tool_declarations Accepted declarations.
	 * @param WP_Agent_Tool_Executor|null                      $tool_executor     Resolved tool executor.
	 */
	private static function emit_tool_declaration_diagnostics( ?callable $on_event, array $rejected, array $tool_declarations, ?WP_Agent_Tool_Executor $tool_executor ): void {
		if ( empty( $rejected ) ) {
			return;
		}

		self::emit_event(
			$on_event,
			'tool_declarations_rejected',
			array(
				'rejected'       => array_values( $rejected ),
				'rejected_count' => count( $rejected ),
				'accepted_count' => count( $tool_declarations ),
			)
		);

		if ( empty( $tool_declarations ) && null !== $tool_executor ) {
			self::emit_event(
				$on_event,
				'tool_mediation_disabled',
				array(
					'reason'         => 'all_declarations_rejected',
					'rejected'       => array_values( $rejected ),
					'rejected_count' => count( $rejected ),
				)
			);
		}
	}

	/**
	 * Emit a lifecycle event through the caller sink and WordPress observers.
	 *
	 * The caller-owned `on_event` sink and the `agents_api_loop_event` action are
	 * observational surfaces. Event payloads are read-only snapshots for observers;
	 * observer failures are swallowed to prevent changing loop results.
	 *
	 * @param callable|null $on_event Event sink.
	 * @param string        $event    Event name.
	 * @param array<mixed>         $payload  Event payload.
	 */
	private static function emit_event( ?callable $on_event, string $event, array $payload = array() ): void {
		if ( null !== $on_event ) {
			try {
				call_user_func( $on_event, $event, $payload );
			} catch ( \Throwable $error ) {
				// Observer failures must not change loop results.
				unset( $error );
			}
		}

		if ( function_exists( 'do_action' ) ) {
			try {
				/**
				 * Fires when WP_Agent_Conversation_Loop emits a lifecycle event.
				 *
				 * Observers receive read-only event snapshots. Exceptions thrown by
				 * observers are swallowed and cannot change loop results.
				 *
				 * @param string $event   Event name.
				 * @param array<mixed>  $payload Event payload snapshot.
				 */
				do_action( 'agents_api_loop_event', $event, $payload );
			} catch ( \Throwable $error ) {
				// Observer failures must not change loop results.
				unset( $error );
			}
		}
	}

	/**
	 * Build a stable, safe audit entry for a mediated tool call.
	 *
	 * The legacy `tool_execution_results` field intentionally keeps raw
	 * parameters for existing callers. Audit events avoid raw parameter storage by
	 * default so transcripts can be used for replay attestation without leaking
	 * secrets into generic observers.
	 *
	 * @param string     $tool_name       Tool identifier.
	 * @param string     $tool_call_id    Provider or loop-assigned tool-call id.
	 * @param array<string, mixed>      $parameters      Runtime tool-call parameters.
	 * @param array<string, mixed>      $result          Normalized tool execution result.
	 * @param array<string, mixed>|null $tool_definition Tool declaration, when available.
	 * @param array<string, mixed>      $context         Turn context.
	 * @param int        $turn            Turn number.
	 * @return array<string, mixed> Audit event.
	 */
	private static function tool_audit_event( string $tool_name, string $tool_call_id, array $parameters, array $result, ?array $tool_definition, array $context, int $turn ): array {
		$parameter_exposure = self::tool_parameter_exposure( $parameters, $tool_definition, $tool_name, $context );
		$metadata           = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();
		$error_type         = isset( $metadata['error_type'] ) && is_string( $metadata['error_type'] ) ? $metadata['error_type'] : '';

		$audit_event = array(
			'schema_version'      => 1,
			'type'                => 'tool_call',
			'turn_count'          => $turn,
			'tool_name'           => $tool_name,
			'tool_call_id'        => $tool_call_id,
			'tool_source'         => is_array( $tool_definition ) && is_string( $tool_definition['source'] ?? null ) ? $tool_definition['source'] : '',
			'parameters_sha256'   => $parameter_exposure['parameters_sha256'],
			'parameters_redacted' => true,
			'success'             => (bool) ( $result['success'] ?? false ),
			'result_status'       => ! empty( $result['success'] ) ? 'success' : 'error',
			'result_sha256'       => self::stable_sha256( self::audit_result_summary( $result ) ),
		);

		if ( '' !== $error_type ) {
			$audit_event['error_type'] = $error_type;
		}

		return array_filter(
			$audit_event,
			static fn( $value ): bool => '' !== $value
		);
	}

	/**
	 * Redact tool parameters before hashing them for audit events.
	 *
	 * @param array<string, mixed>      $parameters      Raw tool-call parameters.
	 * @param string                    $tool_name       Tool identifier.
	 * @param array<string, mixed>|null $tool_definition Tool declaration, when available.
	 * @param array<string, mixed>      $context         Turn context.
	 * @return array<string, mixed> Redacted parameters.
	 */
	private static function redact_tool_audit_parameters( array $parameters, string $tool_name, ?array $tool_definition, array $context ): array {
		$redacted = WP_Agent_Tool_Parameters::redactedParameters( $parameters, is_array( $tool_definition ) ? $tool_definition : array() );

		if ( function_exists( 'apply_filters' ) ) {
			try {
				/**
				 * Filters parameters before Agents API hashes them into tool audit events.
				 *
				 * Callers can remove or normalize product-specific sensitive fields while
				 * keeping deterministic replay hashes. Returning a non-array falls back to
				 * the default redacted parameters.
				 *
				 * @param array<string, mixed>      $redacted        Default redacted parameters.
				 * @param array<string, mixed>      $parameters      Raw tool-call parameters.
				 * @param string                    $tool_name       Tool identifier.
				 * @param array<string, mixed>|null $tool_definition Tool declaration, when available.
				 * @param array<string, mixed>      $context         Turn context.
				 */
				$filtered = apply_filters( 'agents_api_tool_audit_parameters', $redacted, $parameters, $tool_name, $tool_definition, $context );
				$redacted = self::normalize_filtered_assoc_array( $filtered, $redacted );
			} catch ( \Throwable $error ) {
				// Audit redaction filters must not change loop results.
				unset( $error );
			}
		}

		return $redacted;
	}

	/**
	 * Build the generic observer-safe parameter envelope used by loop surfaces.
	 *
	 * @param array<string, mixed>      $parameters      Raw tool-call parameters.
	 * @param array<string, mixed>|null $tool_definition Tool declaration, when available.
	 * @param string                    $tool_name       Tool identifier for filters.
	 * @param array<string, mixed>      $context         Turn context for filters.
	 * @return array{parameters: array<string, mixed>, parameters_sha256: string, parameters_redacted: bool}
	 */
	private static function tool_parameter_exposure( array $parameters, ?array $tool_definition, string $tool_name = '', array $context = array() ): array {
		$safe_parameters = self::redact_tool_audit_parameters( $parameters, $tool_name, $tool_definition, $context );

		return array(
			'parameters'          => $safe_parameters,
			'parameters_sha256'   => self::stable_sha256( $safe_parameters ),
			'parameters_redacted' => true,
		);
	}

	/**
	 * Keep the audit result hash focused on normalized status, not raw payloads.
	 *
	 * @param array<string, mixed> $result Normalized tool result.
	 * @return array<string, mixed> Hashable result summary.
	 */
	private static function audit_result_summary( array $result ): array {
		$metadata = isset( $result['metadata'] ) && is_array( $result['metadata'] ) ? $result['metadata'] : array();

		$summary = array(
			'success'   => (bool) ( $result['success'] ?? false ),
			'tool_name' => is_string( $result['tool_name'] ?? null ) ? $result['tool_name'] : '',
			'metadata'  => $metadata,
		);

		if ( empty( $result['success'] ) ) {
			$summary['error_sha256'] = self::stable_sha256( is_string( $result['error'] ?? null ) ? $result['error'] : 'Tool execution failed.' );
		}

		return $summary;
	}

	/**
	 * Hash data after recursively sorting array keys for deterministic output.
	 *
	 * @param mixed $data Data to hash.
	 * @return string sha256-prefixed hash.
	 */
	private static function stable_sha256( $data ): string {
		$normalized = self::sort_for_hash( $data );
		$encoded    = self::json_encode_safe( $normalized );
		if ( false === $encoded ) {
			$encoded = '';
		}

		return 'sha256:' . hash( 'sha256', (string) $encoded );
	}

	/**
	 * Recursively sort associative arrays before hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function sort_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = self::sort_for_hash( $item );
		}

		if ( array() !== $normalized && array_keys( $normalized ) !== range( 0, count( $normalized ) - 1 ) ) {
			ksort( $normalized );
		}

		return $normalized;
	}

	/**
	 * Resolve the tool executor from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Tool_Executor|null
	 */
	private static function resolve_tool_executor( array $options ): ?WP_Agent_Tool_Executor {
		$executor = $options['tool_executor'] ?? null;
		return $executor instanceof WP_Agent_Tool_Executor ? $executor : null;
	}

	/**
	 * Resolve the should_continue callable for this run.
	 *
	 * When tool mediation is enabled, defaults to a callable that returns true
	 * only while the latest turn emitted `tool_calls` — so the loop stops on
	 * natural completion and `max_turns` + `completion_policy` + budgets remain
	 * upper bounds rather than the primary stop condition. In the caller-managed
	 * path (no mediation), preserves the historical break-after-1 behavior unless
	 * the caller supplies their own continuation policy.
	 *
	 * @param array<mixed>                       $options           Loop options.
	 * @param WP_Agent_Tool_Executor|null $tool_executor     Resolved tool executor.
	 * @param array<mixed>                       $tool_declarations Resolved tool declarations.
	 * @return callable|null
	 */
	private static function resolve_should_continue(
		array $options,
		?WP_Agent_Tool_Executor $tool_executor,
		array $tool_declarations
	) {
		if ( array_key_exists( 'should_continue', $options ) ) {
			$caller_supplied = $options['should_continue'];
			if ( is_callable( $caller_supplied ) ) {
				return $caller_supplied;
			}
			// Caller passed a non-callable value (e.g. null) — preserve the
			// break-after-1 behavior they explicitly opted into.
			return null;
		}

		// No caller-supplied policy. When mediation is enabled, default to
		// "stop when the turn runner emitted no tool_calls" so the loop exits
		// on natural completion instead of re-running until max_turns. The
		// caller can still pass `'should_continue' => '__return_true'` to opt
		// into the historical continue-always behavior, and budgets +
		// `completion_policy` + `max_turns` continue to act as stop conditions.
		$mediation_enabled = null !== $tool_executor && ! empty( $tool_declarations );
		if ( $mediation_enabled ) {
			return static function ( array $result ): bool {
				return ! empty( $result['tool_calls'] ?? array() );
			};
		}

		return null;
	}

	/**
	 * Resolve tool declarations from options.
	 *
	 * @param array<string, mixed>                            $options  Loop options.
	 * @param array<int, array{name: string, reason: string}> $rejected Out: rejected declarations with reasons.
	 * @return array<string, array<string, mixed>> Tool declarations keyed by name.
	 */
	private static function resolve_tool_declarations( array $options, array &$rejected = array() ): array {
		$declarations = $options['tool_declarations'] ?? null;
		return is_array( $declarations ) ? self::normalize_tool_declarations( $declarations, $rejected ) : array();
	}

	/**
	 * Resolve the completion policy from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Conversation_Completion_Policy|null
	 */
	private static function resolve_completion_policy( array $options ): ?WP_Agent_Conversation_Completion_Policy {
		$policy = $options['completion_policy'] ?? null;
		return $policy instanceof WP_Agent_Conversation_Completion_Policy ? $policy : null;
	}

	/**
	 * Resolve the spin detector from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Spin_Detector|null
	 */
	private static function resolve_spin_detector( array $options ): ?WP_Agent_Spin_Detector {
		$detector = $options['spin_detector'] ?? null;
		return $detector instanceof WP_Agent_Spin_Detector ? $detector : null;
	}

	/**
	 * Resolve the identical failure tracker from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Identical_Failure_Tracker|null
	 */
	private static function resolve_identical_failure_tracker( array $options ): ?WP_Agent_Identical_Failure_Tracker {
		$tracker = $options['identical_failure_tracker'] ?? null;
		return $tracker instanceof WP_Agent_Identical_Failure_Tracker ? $tracker : null;
	}

	/**
	 * Resolve the tool result truncator from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Tool_Result_Truncator|null
	 */
	private static function resolve_tool_result_truncator( array $options ): ?WP_Agent_Tool_Result_Truncator {
		$truncator = $options['tool_result_truncator'] ?? null;
		return $truncator instanceof WP_Agent_Tool_Result_Truncator ? $truncator : null;
	}

	/**
	 * Return an array only when every key is string-addressable metadata.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<string, mixed>|null
	 */
	private static function associative_array_or_null( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$associative = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				return null;
			}

			$associative[ $key ] = $item;
		}

		return $associative;
	}

	/**
	 * Return an array value or an empty array for non-array input.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<mixed>
	 */
	private static function array_or_empty( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Resolve the pre-tool mediator from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_pre_tool_mediator( array $options ): ?callable {
		$mediator = $options['pre_tool_mediator'] ?? null;
		return is_callable( $mediator ) ? $mediator : null;
	}

	/**
	 * Resolve the deterministic tool-call gate from declarative loop options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Tool_Call_Gate|null
	 */
	private static function resolve_tool_call_gate( array $options ): ?WP_Agent_Tool_Call_Gate {
		return WP_Agent_Tool_Call_Gate::from_config( $options['tool_call_rules'] ?? null );
	}

	/**
	 * Compose a pre-tool mediator that enforces the tool-call gate before any
	 * caller-supplied mediator runs.
	 *
	 * The gate runs first so a configured rule deterministically rejects a
	 * disallowed call regardless of what a downstream mediator would decide. When
	 * the gate allows the call, control passes to the inner mediator (or the
	 * default proceed) so existing mediation behavior is preserved untouched.
	 *
	 * @param WP_Agent_Tool_Call_Gate|null $gate  Resolved gate, or null when none configured.
	 * @param callable|null                $inner Caller-supplied pre-tool mediator.
	 * @return callable|null
	 */
	private static function compose_tool_call_gate_mediator( ?WP_Agent_Tool_Call_Gate $gate, ?callable $inner ): ?callable {
		if ( null === $gate ) {
			return $inner;
		}

		return static function ( array $context ) use ( $gate, $inner ) {
			$tool_name = is_string( $context['tool_name'] ?? null ) ? $context['tool_name'] : '';
			if ( '' !== $tool_name ) {
				$messages     = is_array( $context['messages'] ?? null ) ? $context['messages'] : array();
				$tool_call_id = is_string( $context['tool_call_id'] ?? null ) ? $context['tool_call_id'] : '';
				$prior        = WP_Agent_Tool_Call_Gate::messages_before_tool_call( $messages, $tool_call_id );
				$evaluation   = $gate->evaluate_call( $tool_name, $prior );
				if ( ! $evaluation['allowed'] ) {
					return array(
						'action'   => 'reject',
						'error'    => $evaluation['reason'],
						'metadata' => array(
							'error_type'     => WP_Agent_Tool_Call_Gate::ERROR_TYPE_CALL_REJECTED,
							'tool_call_gate' => $evaluation['context'],
						),
					);
				}
			}

			if ( null !== $inner ) {
				return call_user_func( $inner, $context );
			}

			return array( 'action' => 'proceed' );
		};
	}

	/**
	 * Resolve the post-tool result diagnostics callback from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_post_tool_result_diagnostics( array $options ): ?callable {
		$callback = $options['post_tool_result_diagnostics'] ?? null;
		return is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Resolve the interrupt source from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_interrupt_source( array $options ): ?callable {
		$source = $options['interrupt_source'] ?? null;
		return is_callable( $source ) ? $source : null;
	}

	/**
	 * Resolve the transcript persister from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Transcript_Persister|null
	 */
	private static function resolve_transcript_persister( array $options ): ?WP_Agent_Transcript_Persister {
		$persister = $options['transcript_persister'] ?? null;
		return $persister instanceof WP_Agent_Transcript_Persister ? $persister : null;
	}

	/**
	 * Resolve the durable runtime-tool request store from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Runtime_Tool_Request_Store|null
	 */
	private static function resolve_runtime_tool_request_store( array $options ): ?WP_Agent_Runtime_Tool_Request_Store {
		foreach ( array( 'runtime_tool_request_store', 'runtime_tool_store' ) as $key ) {
			$store = $options[ $key ] ?? null;
			if ( $store instanceof WP_Agent_Runtime_Tool_Request_Store ) {
				return $store;
			}
		}

		return null;
	}

	/**
	 * Resolve the transcript lock primitive from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return WP_Agent_Conversation_Lock|null
	 */
	private static function resolve_transcript_lock( array $options ): ?WP_Agent_Conversation_Lock {
		foreach ( array( 'transcript_lock', 'transcript_lock_store', 'transcript_store' ) as $key ) {
			$lock = $options[ $key ] ?? null;
			if ( $lock instanceof WP_Agent_Conversation_Lock ) {
				return $lock;
			}
		}

		return null;
	}

	/**
	 * Resolve the transcript session ID to lock.
	 *
	 * @param array<mixed>                    $options Loop options.
	 * @param WP_Agent_Conversation_Request $request Request object.
	 * @return string
	 */
	private static function resolve_lock_session_id( array $options, WP_Agent_Conversation_Request $request ): string {
		foreach ( array( 'transcript_session_id', 'session_id', 'transcript_id' ) as $key ) {
			$value = $options[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		$metadata = $request->metadata();
		foreach ( array( 'transcript_session_id', 'session_id', 'transcript_id' ) as $key ) {
			$value = $metadata[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Resolve the chat run ID used by generic run-control.
	 *
	 * @param array<mixed>                         $options Loop options.
	 * @param WP_Agent_Conversation_Request $request Request object.
	 * @return string
	 */
	private static function resolve_run_id( array $options, WP_Agent_Conversation_Request $request ): string {
		foreach ( array( 'run_id', 'chat_run_id' ) as $key ) {
			$value = $options[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		$metadata = $request->metadata();
		foreach ( array( 'run_id', 'chat_run_id' ) as $key ) {
			$value = $metadata[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Resolve transcript lock TTL.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return int TTL in seconds.
	 */
	private static function resolve_lock_ttl( array $options ): int {
		return max( 1, self::int_value( $options['transcript_lock_ttl'] ?? 300 ) );
	}

	/**
	 * Resolve the event sink from options.
	 *
	 * @param array<string, mixed> $options Loop options.
	 * @return callable|null
	 */
	private static function resolve_event_sink( array $options ): ?callable {
		$sink = $options['on_event'] ?? null;
		return is_callable( $sink ) ? $sink : null;
	}

	/**
	 * Resolve iteration budgets from options.
	 *
	 * Synthesizes a `turns` budget from `max_turns` when no explicit `turns`
	 * budget is provided, preserving backwards compatibility.
	 *
	 * @param array<string, mixed> $options   Loop options.
	 * @param int   $max_turns Resolved max turns value.
	 * @return array{budgets: array<string, WP_Agent_Iteration_Budget>, has_explicit_turns: bool}
	 */
	private static function resolve_budgets( array $options, int $max_turns ): array {
		$raw     = $options['budgets'] ?? array();
		$budgets = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $budget ) {
				if ( $budget instanceof WP_Agent_Iteration_Budget ) {
					$budgets[ $budget->name() ] = $budget;
				}
			}
		}

		$has_explicit_turns = isset( $budgets['turns'] );

		// Synthesize a turns budget from max_turns when none was explicitly provided.
		if ( ! $has_explicit_turns ) {
			$budgets['turns'] = new WP_Agent_Iteration_Budget( 'turns', $max_turns );
		}

		return array(
			'budgets'            => $budgets,
			'has_explicit_turns' => $has_explicit_turns,
		);
	}

	/**
	 * Increment a named budget and check for exceedance.
	 *
	 * When the budget is exceeded, emits a `budget_exceeded` event and returns
	 * the budget name. Returns null when the budget is not exceeded or does not exist.
	 *
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets  Named budgets.
	 * @param string                         $name     Budget name to increment.
	 * @param callable|null                  $on_event Event sink.
	 * @return string|null Exceeded budget name, or null.
	 */
	private static function increment_budget( array $budgets, string $name, ?callable $on_event ): ?string {
		if ( ! isset( $budgets[ $name ] ) ) {
			return null;
		}

		$budget = $budgets[ $name ];
		$budget->increment();

		if ( $budget->exceeded() ) {
			self::emit_event( $on_event, 'budget_exceeded', self::budget_event_payload( $budget ) );

			return $name;
		}

		return null;
	}

	/**
	 * Check the opt-in wall-clock budget before starting a turn.
	 *
	 * @param array<string, WP_Agent_Iteration_Budget> $budgets             Named budgets.
	 * @param float                                    $started_at          Loop start timestamp.
	 * @param int                                      $initial_elapsed_sec Existing elapsed seconds carried by the budget.
	 * @param callable|null                            $on_event            Event sink.
	 * @return string|null Exceeded budget name, or null.
	 */
	private static function check_wall_clock_budget( array $budgets, float $started_at, int $initial_elapsed_sec, ?callable $on_event ): ?string {
		$name = 'wall_clock_seconds';
		if ( ! isset( $budgets[ $name ] ) ) {
			return null;
		}

		$budget  = $budgets[ $name ];
		$elapsed = $initial_elapsed_sec + max( 0, (int) floor( microtime( true ) - $started_at ) );
		$budget->set_current( $elapsed );

		if ( $budget->exceeded() ) {
			self::emit_event( $on_event, 'budget_exceeded', self::budget_event_payload( $budget ) );
			return $name;
		}

		return null;
	}

	/**
	 * Build the standard budget-exceeded event payload.
	 *
	 * @param WP_Agent_Iteration_Budget $budget Exceeded budget.
	 * @return array<string, mixed>
	 */
	private static function budget_event_payload( WP_Agent_Iteration_Budget $budget ): array {
		$name = $budget->name();

		return array(
			'budget'    => $name,
			'dimension' => self::budget_dimension( $name ),
			'current'   => $budget->current(),
			'ceiling'   => $budget->ceiling(),
		);
	}

	/**
	 * Map budget names to generic dimensions for observers.
	 *
	 * @param string $name Budget name.
	 * @return string Dimension label.
	 */
	private static function budget_dimension( string $name ): string {
		if ( 'wall_clock_seconds' === $name ) {
			return 'wall_clock';
		}

		if ( 0 === strpos( $name, 'tool_calls' ) ) {
			return 'tool_calls';
		}

		return $name;
	}

	/**
	 * Apply optional transcript compaction through caller-owned summarization.
	 *
	 * @param array<int, array<string, mixed>> $messages Current messages.
	 * @param array<string, mixed> $options  Loop options.
	 * @return array{messages: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
	 */
	private static function maybe_compact( array $messages, array $options ): array {
		$policy     = $options['compaction_policy'] ?? null;
		$summarizer = $options['summarizer'] ?? null;

		if ( ! is_array( $policy ) || ! is_callable( $summarizer ) ) {
			return array(
				'messages' => $messages,
				'events'   => array(),
			);
		}

		$compaction = WP_Agent_Conversation_Compaction::compact( $messages, self::normalize_assoc_array( $policy ), $summarizer );
		return array(
			'messages' => $compaction['messages'],
			'events'   => self::normalize_events( $compaction['events'] ),
		);
	}

	/**
	 * Accumulate per-turn usage into the running total.
	 *
	 * Sums the canonical `prompt_tokens`/`completion_tokens`/`total_tokens`
	 * fields and preserves any provider-specific keys from the latest turn
	 * (e.g. `cache_creation_input_tokens`, `reasoning_tokens`) so consumers
	 * can read provider extensions without the loop having to know about
	 * each one. Numeric fields are summed; non-numeric fields are taken
	 * from the latest turn.
	 *
	 * @param array<string, mixed> $running Current accumulated usage.
	 * @param array<string, mixed> $turn    Per-turn usage.
	 * @return array<string, mixed> Accumulated usage.
	 */
	private static function accumulate_usage( array $running, array $turn ): array {
		foreach ( $turn as $key => $value ) {
			if ( is_int( $value ) || is_float( $value ) ) {
				$running[ $key ] = self::float_value( $running[ $key ] ?? 0 ) + (float) $value;
				if ( is_int( $value ) && (float) self::int_value( $running[ $key ] ) === self::float_value( $running[ $key ] ) ) {
					$running[ $key ] = (int) $running[ $key ];
				}
				continue;
			}
			$running[ $key ] = $value;
		}
		return $running;
	}

	/**
	 * Extract the text of the last assistant message from a transcript.
	 *
	 * Returns an empty string when no assistant message exists or the
	 * latest assistant message has no text content (e.g. tool-call-only
	 * turns at the tail).
	 *
	 * @param array<int, array<string, mixed>> $messages Normalized transcript messages.
	 * @return string Final assistant text content.
	 */
	private static function extract_final_content( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			if ( ( $message['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
			if ( WP_Agent_Message::TYPE_TOOL_CALL === ( $message['type'] ?? '' ) ) {
				continue;
			}
			$content = $message['content'] ?? '';
			if ( is_string( $content ) && '' !== $content ) {
				return $content;
			}
		}
		return '';
	}

	/**
	 * Normalize the max turn option.
	 *
	 * @param mixed $value Raw option.
	 * @return int
	 */
	private static function max_turns( $value ): int {
		return max( 1, self::int_value( $value ) );
	}

	/**
	 * Normalize caller-owned lifecycle events.
	 *
	 * @param mixed $events Raw events.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_events( $events ): array {
		if ( ! is_array( $events ) ) {
			throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: events must be an array' );
		}

		$normalized = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				throw new \InvalidArgumentException( 'invalid_agent_conversation_loop: event must be an array' );
			}
			$normalized[] = self::normalize_assoc_array( $event );
		}

		return $normalized;
	}

	/**
	 * Normalize messages while preserving the canonical list shape for static analysis.
	 *
	 * @param array<mixed> $messages Raw messages.
	 * @return array<int, array<string, mixed>> Normalized message list.
	 */
	private static function normalize_messages( array $messages ): array {
		return WP_Agent_Message::normalize_many( $messages );
	}

	/**
	 * Normalize a conversation result while preserving the public return shape.
	 *
	 * @param array<mixed> $result Raw conversation result.
	 * @return array<string, mixed> Normalized conversation result.
	 */
	private static function normalize_conversation_result( array $result ): array {
		return self::normalize_assoc_array( WP_Agent_Conversation_Result::normalize( $result ) );
	}

	/**
	 * Normalize provider-turn typed failure information.
	 *
	 * @param array<mixed> $failure Raw failure.
	 * @param int          $turn    Current turn.
	 * @return array<string, mixed>
	 */
	private static function normalize_provider_turn_failure( array $failure, int $turn ): array {
		$normalized = self::normalize_assoc_array( $failure );
		$type       = is_string( $normalized['type'] ?? null ) && '' !== $normalized['type'] ? $normalized['type'] : 'provider_turn_failure';
		$message    = is_string( $normalized['message'] ?? null ) && '' !== $normalized['message'] ? $normalized['message'] : 'Provider turn failed.';

		$normalized['type']       = $type;
		$normalized['message']    = $message;
		$normalized['turn_count'] = isset( $normalized['turn_count'] ) && is_int( $normalized['turn_count'] ) ? $normalized['turn_count'] : $turn;

		return $normalized;
	}

	/**
	 * Normalize arbitrary associative arrays to string-keyed arrays.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed> String-keyed array.
	 */
	private static function normalize_assoc_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a filter return, preserving the previous value for non-arrays.
	 *
	 * @param mixed                $value    Filtered value.
	 * @param array<string, mixed> $fallback Existing value to keep for invalid returns.
	 * @return array<string, mixed> String-keyed array.
	 */
	private static function normalize_filtered_assoc_array( $value, array $fallback ): array {
		return is_array( $value ) ? self::normalize_assoc_array( $value ) : $fallback;
	}

	/**
	 * Normalize a list of arrays to a typed list shape.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array<string, mixed>> List of string-keyed arrays.
	 */
	private static function normalize_array_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$normalized[] = self::normalize_assoc_array( $item );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize tool declarations to declarations keyed by tool name.
	 *
	 * Declarations that fail validation are dropped from the result and recorded
	 * in `$rejected` (with their declared name and validation reason) so callers
	 * can surface them instead of degrading silently.
	 *
	 * @param array<mixed>                                  $declarations Raw declarations.
	 * @param array<int, array{name: string, reason: string}> $rejected   Out: rejected declarations with reasons.
	 * @return array<string, array<string, mixed>> Tool declarations.
	 */
	private static function normalize_tool_declarations( array $declarations, array &$rejected = array() ): array {
		$normalized = array();
		foreach ( $declarations as $name => $declaration ) {
			$declared_name = is_string( $name ) && '' !== $name ? $name : '';

			if ( ! is_array( $declaration ) ) {
				$rejected[] = array(
					'name'   => $declared_name,
					'reason' => 'declaration must be an array',
				);
				continue;
			}

			$declaration = self::normalize_assoc_array( $declaration );
			if ( '' !== $declared_name && ! isset( $declaration['name'] ) ) {
				$declaration['name'] = $declared_name;
			}

			if ( '' === $declared_name && is_string( $declaration['name'] ?? null ) ) {
				$declared_name = $declaration['name'];
			}

			try {
				$tool = self::normalize_assoc_array( WP_Agent_Tool_Declaration::normalizeForConversationRequest( $declaration ) );
			} catch ( \InvalidArgumentException $error ) {
				$rejected[] = array(
					'name'   => $declared_name,
					'reason' => $error->getMessage(),
				);
				continue;
			}

			$tool_name = is_string( $tool['name'] ?? null ) ? $tool['name'] : '';
			if ( '' !== $tool_name ) {
				$normalized[ $tool_name ] = $tool;
			}
		}

		return $normalized;
	}

	/**
	 * Cast scalar-ish values to int without asking PHPStan to accept mixed casts.
	 *
	 * @param mixed $value Raw value.
	 * @return int Integer value.
	 */
	private static function int_value( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) || is_string( $value ) || is_bool( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Cast scalar-ish values to float without asking PHPStan to accept mixed casts.
	 *
	 * @param mixed $value Raw value.
	 * @return float Float value.
	 */
	private static function float_value( $value ): float {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (float) $value;
		}

		if ( is_string( $value ) || is_bool( $value ) ) {
			return (float) $value;
		}

		return 0.0;
	}

	/**
	 * Encode data to JSON with a pure-PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON or false on failure.
	 */
	private static function json_encode_safe( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
		return json_encode( $data );
	}
}
