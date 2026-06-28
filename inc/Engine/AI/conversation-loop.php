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
use AgentsAPI\AI\WP_Agent_Default_Provider_Turn_Adapter;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Null_Transcript_Persister;
use AgentsAPI\AI\WP_Agent_Provider_Turn_Request;
use AgentsAPI\AI\WP_Agent_Provider_Turn_Result;
use AgentsAPI\AI\WP_Agent_Runtime_Tool_Request;
use AgentsAPI\AI\WP_Agent_Runtime_Tool_Request_Store;
use AgentsAPI\AI\WP_Agent_Runtime_Tool_Result;
use AgentsAPI\AI\WP_Agent_Runtime_Tool_Lifecycle;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use DataMachine\Core\JobArtifacts;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Engine\AI\Tools\ToolExecutor;

defined( 'ABSPATH' ) || exit;

/**
 * Run a multi-turn AI conversation through the agents-api substrate.
 *
 * Builds a DM-specific provider-turn adapter (request building + wp-ai-client
 * dispatch) and delegates orchestration to WP_Agent_Conversation_Loop::run().
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
	$loop_payload = datamachine_payload_with_client_context_bindings(
		datamachine_payload_without_runtime_objects( $payload )
	);

	// Build the turns budget through DM's registry (site-config-aware ceiling
	// resolution). The upstream loop owns increment + exceeded checks.
	$turn_budget = IterationBudgetRegistry::create( 'conversation_turns', 0, $max_turns );
	$max_turns   = $turn_budget->ceiling();

	// DM-flavored mutable state accumulated by the turn runner. The substrate
	// surfaces turn_count, final_content, usage, and request_metadata on the
	// final result directly (see agents-api#136), so we only carry by-reference
	// the things substrate doesn't track for us.
	$last_tool_calls        = array();
	$all_tool_calls         = array();
	$tool_execution_results = array();
	$completion_nudges      = array();
	$turn_state             = new DataMachineProviderTurnState( $messages );
	$runtime_tool_pending   = false;
	$runtime_tool_requests  = array();

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

	// Build the DM-specific provider-turn adapter. Data Machine owns provider/model
	// resolution, request assembly, and wp-ai-client dispatch,
	// and natural-completion nudges; Agents API owns the turn request/result shape,
	// tool-call extraction, continuation, mediated tool execution, transcripts, and
	// stop conditions.
	$provider_turn_adapter = datamachine_build_provider_turn_adapter(
		$tools,
		$provider,
		$model,
		$mode,
		$modes,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$last_tool_calls,
		$all_tool_calls,
		$turn_state,
		$completion_policy,
		$completion_nudges
	);

	$tool_executor     = datamachine_build_loop_tool_executor( $tools, $loop_payload, $mode, $modes, $event_sink, $base_log_context );
	$pre_tool_mediator = datamachine_build_pre_tool_mediator(
		$tools,
		$loop_payload,
		$mode,
		$modes,
		$tool_runtime_rules,
		$event_sink,
		$base_log_context
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
		return ! empty( $result['tool_calls'] ) || ! empty( $result['continuation_messages'] );
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
			$provider_turn_adapter,
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
				'tool_executor'         => $tool_executor,
				'tool_declarations'     => $tools,
				'runtime_tool_store'    => datamachine_runtime_tool_request_store(),
				'provider_turn_adapter' => $provider_turn_adapter,
				'pre_tool_mediator'     => $pre_tool_mediator,
				'completion_policy'     => $completion_policy,
			)
		);
	} catch ( \RuntimeException $e ) {
		// The provider-turn adapter throws RuntimeException for wp-ai-client failures before
		// the substrate can return its accumulated result. Preserve the latest
		// known state from completed turns so failed job artifacts still explain
		// where the conversation stopped.
		$error_result          = array(
			'messages'               => $turn_state->latest_messages,
			'final_content'          => '',
			'turn_count'             => $turn_state->latest_turn_count,
			'tool_execution_results' => $tool_execution_results,
			'error'                  => $e->getMessage(),
			'usage'                  => array(),
			'request_metadata'       => $turn_state->last_request_metadata,
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
		$transcript_session_id = $transcript_persister->persist( $turn_state->latest_messages, $conversation_request, $error_result );
		if ( '' !== $transcript_session_id ) {
			$error_result['transcript_session_id'] = $transcript_session_id;
		}
		$error_result['runtime_provenance'] = RuntimeProvenance::fromConversationResult( $error_result, $loop_payload, $provider, $model, $modes );
		return $error_result;
	}

	// Normalize the substrate result and augment with DM-specific fields.
	try {
		$result                    = WP_Agent_Conversation_Result::normalize( $result );
		$completion_policy_stopped = false;
		foreach ( is_array( $result['events'] ?? null ) ? $result['events'] : array() as $event ) {
			if ( ! is_array( $event ) || ! in_array( (string) ( $event['type'] ?? '' ), array( 'completion_policy_stop', 'completion_policy_continue' ), true ) ) {
				continue;
			}

			if ( 'completion_policy_stop' === (string) ( $event['type'] ?? '' ) ) {
				$completion_policy_stopped = true;
			}

			$event_metadata = is_array( $event['metadata'] ?? null ) ? $event['metadata'] : array();
			if ( is_string( $event_metadata['message'] ?? null ) && '' !== $event_metadata['message'] ) {
				do_action( 'datamachine_log', 'debug', $event_metadata['message'], array_merge( $base_log_context, is_array( $event_metadata['context'] ?? null ) ? $event_metadata['context'] : array() ) );
			}
		}
		if ( isset( $result['failure'] ) && is_array( $result['failure'] ) && ! isset( $result['error'] ) ) {
			$result['error']         = (string) ( $result['failure']['message'] ?? 'Provider request failed.' );
			$result['error_code']    = (string) ( $result['failure']['code'] ?? 'provider_error' );
			$result['finish_reason'] = 'provider_error';
		}
		$tool_execution_results = datamachine_enrich_mediated_tool_results(
			is_array( $result['tool_execution_results'] ?? null ) ? $result['tool_execution_results'] : array(),
			$tools,
			$loop_payload
		);
		if ( ! empty( $tool_execution_results ) ) {
			$result['tool_execution_results'] = $tool_execution_results;
			datamachine_record_tool_results_to_engine_data( $loop_payload, $tool_execution_results );
			datamachine_persist_inflight_tool_summary( $loop_payload, $tool_execution_results );
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
		'completed'       => ! isset( $result['error'] ) && ! in_array( (string) ( $result['status'] ?? '' ), array( 'budget_exceeded', 'interrupted', 'failed' ), true ),
		'last_tool_calls' => $last_tool_calls,
		'tool_calls'      => $all_tool_calls,
	);
	if ( ! empty( $tool_execution_results ) ) {
		$datamachine_metadata['tool_execution_summary'] = datamachine_summarize_tool_execution_results( $tool_execution_results, false );
	}
	if ( 'interrupted' === ( $result['status'] ?? '' ) && isset( $result['interrupted'] ) ) {
		$datamachine_metadata['interrupted'] = $result['interrupted'];
	}
	$silent_max_turns_reached = ! empty( $last_tool_calls )
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
	if ( 'runtime_tool_pending' === (string) ( $result['status'] ?? '' ) || ! empty( $result['runtime_tool_pending'] ) ) {
		$runtime_tool_pending                                  = true;
		$runtime_tool_requests                                 = is_array( $result['runtime_tool_pending'] ?? null ) ? array( $result['runtime_tool_pending'] ) : $runtime_tool_requests;
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
		if ( ! $completion_policy_stopped && (int) ( $result['turn_count'] ?? 0 ) >= $turn_budget->ceiling() ) {
			$datamachine_metadata['completed']         = false;
			$datamachine_metadata['max_turns_reached'] = true;
			$datamachine_metadata['warning']           = sprintf(
				'Maximum conversation turns (%d) reached before completion policy was satisfied.',
				$turn_budget->ceiling()
			);
		}
	}
	if ( $assertions->hasAssertions() ) {
		$evaluation_context = $loop_payload;
		$typed_artifacts    = datamachine_normalize_typed_artifact_outputs( $result );
		if ( ! empty( $typed_artifacts ) ) {
			$evaluation_engine_data                               = is_array( $evaluation_context['engine_data'] ?? null ) ? $evaluation_context['engine_data'] : array();
			$evaluation_engine_data['outputs']                    = is_array( $evaluation_engine_data['outputs'] ?? null ) ? $evaluation_engine_data['outputs'] : array();
			$evaluation_engine_data['outputs']['typed_artifacts'] = array_replace_recursive(
				is_array( $evaluation_engine_data['outputs']['typed_artifacts'] ?? null ) ? $evaluation_engine_data['outputs']['typed_artifacts'] : array(),
				$typed_artifacts
			);
			$evaluation_context['engine_data']                    = $evaluation_engine_data;
		}

		$evaluation = $assertions->evaluate( $evaluation_context, $result['final_content'] ?? '' );
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
	if ( 'failed' === (string) ( $result['status'] ?? '' ) || isset( $result['error'] ) ) {
		$transcript_session_id = $transcript_persister->persist( is_array( $result['messages'] ?? null ) ? $result['messages'] : $turn_state->latest_messages, $conversation_request, $result );
		if ( '' !== $transcript_session_id ) {
			$result['transcript_session_id'] = $transcript_session_id;
		}
	}

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
 * Normalize typed artifact outputs from runtime result shapes into engine-data output shape.
 *
 * @param array $result Conversation result.
 * @return array<string,array<string,mixed>> Typed artifacts keyed by output key.
 */
function datamachine_normalize_typed_artifact_outputs( array $result ): array {
	$sources = array();
	if ( is_array( $result['outputs']['typed_artifacts'] ?? null ) ) {
		$sources[] = $result['outputs']['typed_artifacts'];
	}
	if ( is_array( $result['typed_artifacts'] ?? null ) ) {
		$sources[] = $result['typed_artifacts'];
	}
	foreach ( is_array( $result['tool_execution_results'] ?? null ) ? $result['tool_execution_results'] : array() as $tool_result ) {
		if ( ! is_array( $tool_result ) ) {
			continue;
		}

		if ( is_array( $tool_result['typed_artifacts'] ?? null ) ) {
			$sources[] = $tool_result['typed_artifacts'];
		}
		if ( is_array( $tool_result['outputs']['typed_artifacts'] ?? null ) ) {
			$sources[] = $tool_result['outputs']['typed_artifacts'];
		}
		if ( is_array( $tool_result['data']['typed_artifacts'] ?? null ) ) {
			$sources[] = $tool_result['data']['typed_artifacts'];
		}
		if ( is_array( $tool_result['result']['typed_artifacts'] ?? null ) ) {
			$sources[] = $tool_result['result']['typed_artifacts'];
		}
		if ( is_array( $tool_result['result']['data']['typed_artifacts'] ?? null ) ) {
			$sources[] = $tool_result['result']['data']['typed_artifacts'];
		}
	}

	$typed_artifacts = array();
	foreach ( $sources as $source ) {
		foreach ( $source as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$output_key = trim( (string) ( $entry['output_key'] ?? $entry['key'] ?? ( is_string( $key ) ? $key : '' ) ) );
			if ( '' === $output_key ) {
				continue;
			}

			$payload = $entry['payload'] ?? $entry['data'] ?? $entry['content'] ?? null;
			if ( null === $payload || '' === $payload || array() === $payload ) {
				continue;
			}

			$normalized = array( 'payload' => $payload );
			foreach ( array( 'schema', 'artifact' ) as $field ) {
				$value = trim( (string) ( $entry[ $field ] ?? '' ) );
				if ( '' !== $value ) {
					$normalized[ $field ] = $value;
				}
			}

			$typed_artifacts[ $output_key ] = $normalized;
		}
	}

	return $typed_artifacts;
}

/**
 * Build the Data Machine executor adapter used by the mediated Agents API loop.
 *
 * @param array<string,array<string,mixed>> $tools        Tool declarations keyed by name.
 * @param array<string,mixed>              $loop_payload Cleaned loop payload.
 * @param string                           $mode         Comma-separated execution mode label.
 * @param array<int,string>                $modes        Execution mode slugs.
 * @param LoopEventSinkInterface           $event_sink   Event sink.
 * @param array<string,mixed>              $base_log_context Base log context.
 */
function datamachine_build_loop_tool_executor( array $tools, array $loop_payload, string $mode, array $modes, LoopEventSinkInterface $event_sink, array $base_log_context ): WP_Agent_Tool_Executor {
	return new class( $tools, $loop_payload, $mode, $modes, $event_sink, $base_log_context ) implements WP_Agent_Tool_Executor {
		/** @var array<string,array<string,mixed>> */
		private array $tools;

		/** @var array<string,mixed> */
		private array $loop_payload;

		private string $mode;

		/** @var array<int,string> */
		private array $modes;

		private LoopEventSinkInterface $event_sink;

		/** @var array<string,mixed> */
		private array $base_log_context;

		/**
		 * @param array<string,array<string,mixed>> $tools        Tool declarations keyed by name.
		 * @param array<string,mixed>              $loop_payload Cleaned loop payload.
		 * @param string                           $mode         Comma-separated execution mode label.
		 * @param array<int,string>                $modes        Execution mode slugs.
		 * @param LoopEventSinkInterface           $event_sink   Event sink.
		 * @param array<string,mixed>              $base_log_context Base log context.
		 */
		public function __construct( array $tools, array $loop_payload, string $mode, array $modes, LoopEventSinkInterface $event_sink, array $base_log_context ) {
			$this->tools            = $tools;
			$this->loop_payload     = $loop_payload;
			$this->mode             = $mode;
			$this->modes            = $modes;
			$this->event_sink       = $event_sink;
			$this->base_log_context = $base_log_context;
		}

		/** @inheritDoc */
		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
			$tool_name      = (string) ( $tool_call['tool_name'] ?? $tool_call['name'] ?? '' );
			$parameters     = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
			$prior_results  = is_array( $context['prior_tool_results'] ?? null ) ? $context['prior_tool_results'] : array();
			$tool_payload   = datamachine_payload_with_inflight_run_artifacts( $this->loop_payload, $prior_results );
			$client_context = is_array( $tool_payload['client_context'] ?? null ) ? $tool_payload['client_context'] : array();

			if ( datamachine_is_external_runtime_tool( $tool_definition ) ) {
				$result = datamachine_fulfill_runtime_tool_call(
					array_merge(
						$tool_call,
						array(
							'name'       => $tool_name,
							'parameters' => $parameters,
						)
					),
					$tool_definition,
					$tool_payload,
					$this->mode,
					$this->modes,
					(int) ( $context['turn'] ?? 0 ),
					$this->event_sink,
					$this->base_log_context
				);
			} else {
				$result = ToolExecutor::executeTool(
					$tool_name,
					$parameters,
					$this->tools,
					$tool_payload,
					$this->mode,
					(int) ( $tool_payload['agent_id'] ?? 0 ),
					$client_context
				);
			}

			$metadata                              = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
			$metadata['datamachine']               = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
			$metadata['datamachine']['parameters'] = $parameters;
			$result['metadata']                    = $metadata;
			return $result;
		}
	};
}

/**
 * Build Data Machine's pre-tool mediator for duplicate, runtime-rule, and client-tool handling.
 *
 * @param array<string,array<string,mixed>> $tools              Tool declarations keyed by name.
 * @param array<string,mixed>              $loop_payload       Cleaned loop payload.
 * @param string                           $mode               Comma-separated execution mode label.
 * @param array<int,string>                $modes              Execution mode slugs.
 * @param DataMachineToolRuntimeRules      $tool_runtime_rules Tool runtime rules.
 * @param LoopEventSinkInterface           $event_sink         DM event sink.
 * @param array<string,mixed>              $base_log_context   Base log context.
 */
function datamachine_build_pre_tool_mediator( array $tools, array $loop_payload, string $mode, array $modes, DataMachineToolRuntimeRules $tool_runtime_rules, LoopEventSinkInterface $event_sink, array $base_log_context ): callable {
	return static function ( array $context ) use ( $tools, $loop_payload, $mode, $modes, $tool_runtime_rules, $event_sink, $base_log_context ): array {
		$tool_name       = (string) ( $context['tool_name'] ?? $context['name'] ?? '' );
		$tool_parameters = is_array( $context['parameters'] ?? null ) ? $context['parameters'] : array();
		$messages        = is_array( $context['messages'] ?? null ) ? $context['messages'] : array();
		$policy_messages = datamachine_messages_before_current_tool_call( $messages, (string) ( $context['tool_call_id'] ?? '' ) );
		$turn_count      = (int) ( $context['turn'] ?? 0 );
		$tool_def        = is_array( $tools[ $tool_name ] ?? null ) ? $tools[ $tool_name ] : null;

		$validation_result = ConversationManager::validateToolCall( $tool_name, $tool_parameters, $policy_messages, $tool_def );
		if ( ! empty( $validation_result['is_duplicate'] ) ) {
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

			return array(
				'action'   => 'reject',
				'error'    => ConversationManager::duplicateToolCallError( $tool_name, $mode ),
				'metadata' => array( 'error_type' => 'duplicate_tool_call' ),
			);
		}

		$runtime_rule_result = $tool_runtime_rules->evaluate( $tool_name, $policy_messages );
		if ( ! $runtime_rule_result['allowed'] ) {
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

			return array(
				'action'   => 'reject',
				'error'    => $runtime_rule_result['error'],
				'metadata' => array(
					'error_type' => 'tool_runtime_rule_rejected',
					'policy'     => $runtime_rule_result['context'],
				),
			);
		}

		if ( datamachine_is_external_runtime_tool( $tool_def ) ) {
			$raw_tool_call = is_array( $context['raw_tool_call'] ?? null ) ? $context['raw_tool_call'] : array();
			$tool_payload  = datamachine_payload_with_inflight_run_artifacts(
				$loop_payload,
				is_array( $context['prior_tool_results'] ?? null ) ? $context['prior_tool_results'] : array()
			);
			$tool_result   = datamachine_fulfill_runtime_tool_call(
				array_merge(
					$raw_tool_call,
					array(
						'name'       => $tool_name,
						'parameters' => $tool_parameters,
					)
				),
				$tool_def ?? array(),
				$tool_payload,
				$mode,
				$modes,
				$turn_count,
				$event_sink,
				$base_log_context
			);

			$tool_result['tool_name'] = is_string( $tool_result['tool_name'] ?? null ) && '' !== $tool_result['tool_name'] ? $tool_result['tool_name'] : $tool_name;
			if ( ! array_key_exists( 'result', $tool_result ) ) {
				$runtime_result_payload = $tool_result;
				unset( $runtime_result_payload['success'], $runtime_result_payload['tool_name'], $runtime_result_payload['metadata'], $runtime_result_payload['runtime'] );
				$tool_result['result'] = $runtime_result_payload;
			}
			if ( ! empty( $tool_result['pending'] ) ) {
				$tool_result['status']             = 'runtime_tool_pending';
				$tool_result['metadata']['status'] = 'runtime_tool_pending';
			}

			return array(
				'action'   => ! empty( $tool_result['pending'] ) ? 'pending' : 'replace_result',
				'result'   => $tool_result,
				'complete' => ! empty( $tool_result['pending'] ),
			);
		}

		return array( 'action' => 'proceed' );
	};
}

/**
 * Return transcript history before the current mediated tool-call message.
 *
 * Agents API calls the pre-tool mediator after appending the current synthetic
 * tool-call message. Data Machine duplicate/runtime policies reason about prior
 * history, so remove that current call before evaluating those policies.
 *
 * @param array<int,array<string,mixed>> $messages     Current mediated transcript.
 * @param string                         $tool_call_id Current tool call id.
 * @return array<int,array<string,mixed>> Transcript before current tool call.
 */
function datamachine_messages_before_current_tool_call( array $messages, string $tool_call_id ): array {
	if ( empty( $messages ) ) {
		return $messages;
	}

	$last_index = array_key_last( $messages );
	if ( null === $last_index || ! is_array( $messages[ $last_index ] ) ) {
		return $messages;
	}

	$last_message = $messages[ $last_index ];
	if ( 'tool_call' !== (string) ( $last_message['type'] ?? '' ) ) {
		return $messages;
	}

	$message_tool_call_id = (string) ( $last_message['metadata']['tool_call_id'] ?? '' );
	if ( '' !== $tool_call_id && '' !== $message_tool_call_id && $tool_call_id !== $message_tool_call_id ) {
		return $messages;
	}

	unset( $messages[ $last_index ] );
	return array_values( $messages );
}

/**
 * Add Data Machine trace/runtime metadata to upstream mediated tool results.
 *
 * @param array<int,array<string,mixed>>   $tool_results Tool results from Agents API mediation.
 * @param array<string,array<string,mixed>> $tools        Tool declarations keyed by name.
 * @param array<string,mixed>              $loop_payload Cleaned loop payload.
 * @return array<int,array<string,mixed>> Data Machine-enriched tool results.
 */
function datamachine_enrich_mediated_tool_results( array $tool_results, array $tools, array $loop_payload ): array {
	$enriched = array();
	foreach ( $tool_results as $tool_result_entry ) {
		if ( ! is_array( $tool_result_entry ) ) {
			continue;
		}

		$tool_name  = (string) ( $tool_result_entry['tool_name'] ?? $tool_result_entry['name'] ?? '' );
		$parameters = is_array( $tool_result_entry['parameters'] ?? null ) ? $tool_result_entry['parameters'] : array();
		$result     = is_array( $tool_result_entry['result'] ?? null ) ? $tool_result_entry['result'] : array();
		$metadata   = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
		if ( in_array( (string) ( $metadata['error_type'] ?? '' ), array( 'duplicate_tool_call', 'tool_runtime_rule_rejected' ), true ) ) {
			continue;
		}

		if ( ! empty( $result['success'] ) && is_array( $result['result'] ?? null ) ) {
			$result                      = array_merge( $result['result'], $result );
			$tool_result_entry['result'] = $result;
		}
		$tool_result_entry['tool_name'] = $tool_name;
		$turn_count                     = (int) ( $tool_result_entry['turn_count'] ?? 0 );
		$tool_def                       = is_array( $tools[ $tool_name ] ?? null ) ? $tools[ $tool_name ] : null;
		$started_at                     = microtime( true );
		$normalized_call                = array(
			'name'       => $tool_name,
			'parameters' => $parameters,
		);
		if ( isset( $tool_result_entry['tool_call_id'] ) ) {
			$normalized_call['id'] = (string) $tool_result_entry['tool_call_id'];
		}

		$tool_result_entry['is_handler_tool'] = is_array( $tool_def ) && isset( $tool_def['handler'] );
		$tool_result_entry['runtime']         = datamachine_tool_runtime_metadata( $tool_def, $result );
		$tool_result_entry['trace']           = datamachine_build_tool_trace( $tool_name, $normalized_call, $parameters, $result, $tool_def, $turn_count, $started_at, microtime( true ) );

		$enriched[] = $tool_result_entry;
	}

	unset( $loop_payload );
	return $enriched;
}

/**
 * Dispatch one DM provider turn through the Agents API default adapter.
 *
 * agents-api#370 shipped WP_Agent_Default_Provider_Turn_Adapter and agents-api#371
 * (PR #373) added its dispatch-provider seam, so DM now delegates the GENERIC
 * half of a provider turn to the substrate and keeps only the DM-specific half:
 *
 *  - GENERIC (owned by the default adapter): map the request into builder inputs,
 *    run the turn, then extract tool calls, assistant text, and token usage via
 *    the now-public WP_Agent_Provider_Turn_Result::{extract_tool_calls, result_text,
 *    result_usage} and assemble the normalized { content, tool_calls, usage,
 *    request_metadata } result. DM no longer re-implements any of this.
 *  - DM-SPECIFIC dispatch (injected via set_dispatch_provider): RequestBuilder::build()
 *    performs directive-ordered prompt assembly AND authenticated wp-ai-client
 *    dispatch (per-provider API-key auth, transport timeouts, response caching,
 *    model-config, multimodal/vision parts, oversized-request guarding). It returns
 *    the wp-ai-client GenerativeAiResult; the adapter owns the tail from there.
 *  - DM-SPECIFIC enrichments re-applied on the adapter's normalized output:
 *    provider-safe tool-name aliasing of the extracted tool calls, DM's full
 *    request metadata (the adapter only surfaces provider_id/model_id), and the
 *    response finish reason.
 *
 * @param array  $messages         Messages for this turn.
 * @param array  $tools            Available tools keyed by name.
 * @param string $provider         AI provider identifier.
 * @param string $model            AI model identifier.
 * @param array  $modes            Execution mode slugs.
 * @param array  $loop_payload     Cleaned loop payload.
 * @param DataMachineProviderTurnState $turn_state Per-turn state; updated with the
 *                                     dispatched turn's request metadata even when
 *                                     dispatch fails, preserving the failure-path
 *                                     diagnostics the caller's error result reports.
 * @return array{content:string,tool_calls:array,usage:array,request_metadata:array,request_metadata_pre_finish:array,finish_reason:?string}
 *               `request_metadata_pre_finish` is the metadata snapshot taken
 *               before the finish reason is appended, used for the
 *               `request_built` event to preserve the legacy event payload shape.
 * @throws \RuntimeException When the wp-ai-client request fails (caught by the upstream loop).
 */
function datamachine_dispatch_provider_turn(
	array $messages,
	array $tools,
	string $provider,
	string $model,
	array $modes,
	array $loop_payload,
	DataMachineProviderTurnState $turn_state
): array {
	// DM's full request metadata + the raw wp-ai-client result, captured out of
	// RequestBuilder::build() (by ref) inside the dispatch provider so they
	// survive back here for enrichment. The adapter's own request_metadata only
	// carries provider_id/model_id, and the finish reason is read off the raw
	// result DM produced.
	$request_metadata = array();
	$ai_result        = null;

	// Inject DM's authenticated dispatch into the substrate's default adapter.
	// The adapter owns the generic mapping and the generic tail (extract/text/
	// usage/normalize); the dispatch provider owns only request construction and
	// dispatch, returning a wp-ai-client GenerativeAiResult. RequestBuilder
	// populates $request_metadata even on failure, so capture it before any throw
	// to preserve failure-path diagnostics.
	$dispatch_provider = static function ( array $payload ) use (
		$messages,
		$tools,
		$provider,
		$model,
		$modes,
		$loop_payload,
		$turn_state,
		&$request_metadata,
		&$ai_result
	) {
		unset( $payload ); // DM builds its own authenticated request from the loop inputs.

		$response                          = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			$tools,
			$modes,
			$loop_payload,
			$request_metadata
		);
		$turn_state->last_request_metadata = $request_metadata;

		// Keep the raw result for DM's finish-reason read after the adapter tail.
		if ( ! ( $response instanceof \WP_Error ) ) {
			$ai_result = $response;
		}

		// Returning the WP_Error lets the adapter throw the RuntimeException the
		// upstream loop catches (identical to the bare-builder failure path).
		return $response;
	};

	$adapter = new WP_Agent_Default_Provider_Turn_Adapter( $provider, $model );
	$adapter->set_dispatch_provider( $dispatch_provider );

	// run_turn() resolves provider/model from the request first, so pass an empty
	// model map and let it fall back to the constructor provider/model above.
	$request = new WP_Agent_Provider_Turn_Request( $messages, $tools );

	// The adapter owns extract/text/usage/normalize; it returns
	// { content, tool_calls, usage, request_metadata{provider_id,model_id} }.
	$result = $adapter->run_turn( $request );

	// Re-apply the two DM-specific enrichments the generic tail does not cover:
	// provider-safe tool-name aliasing and DM's full request metadata.
	$tool_calls = datamachine_apply_provider_tool_name_aliases(
		is_array( $result['tool_calls'] ?? null ) ? $result['tool_calls'] : array(),
		$request_metadata
	);
	$ai_content = is_string( $result['content'] ?? null ) ? $result['content'] : '';
	$turn_usage = is_array( $result['usage'] ?? null ) ? $result['usage'] : array();

	// Snapshot DM's metadata as returned by RequestBuilder (before the finish
	// reason is appended) so the `request_built` event payload matches the
	// legacy behavior, which emitted the event prior to enrichment.
	$request_metadata_pre_finish = $request_metadata;

	$finish_reason = null !== $ai_result ? datamachine_ai_result_finish_reason( $ai_result ) : null;
	if ( null !== $finish_reason ) {
		$request_metadata['response']['finish_reason'] = $finish_reason;
		// Mirror the legacy behavior of re-capturing metadata once the finish
		// reason is appended, so the failure-path/error-result diagnostics carry
		// the same enriched metadata as the returned turn.
		$turn_state->last_request_metadata = $request_metadata;
	}

	return array(
		'content'                     => $ai_content,
		'tool_calls'                  => $tool_calls,
		'usage'                       => $turn_usage,
		'request_metadata'            => $request_metadata,
		'request_metadata_pre_finish' => $request_metadata_pre_finish,
		'finish_reason'               => $finish_reason,
	);
}

/**
 * Build the DM-specific provider-turn adapter callable.
 *
 * The adapter handles one provider turn: build request → dispatch via
 * wp-ai-client → extract tool calls. The upstream loop handles provider-turn
 * request/result normalization, continuation, mediated tool execution,
 * multi-turn sequencing, completion policy, and transcript persistence.
 *
 * Per-turn `usage` and `request_metadata` are returned in the adapter's
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
 * @param array                                     &$last_tool_calls    Mutable last turn's tool calls (DM-flavored shape).
 * @param array                                     &$all_tool_calls     Mutable all tool calls made during the run.
 * @param DataMachineProviderTurnState              $turn_state         Mutable per-turn state for the pre-substrate error path.
 * @param WP_Agent_Conversation_Completion_Policy   $completion_policy  Completion policy for natural completions.
 * @param array                                     &$completion_nudges  Mutable nudge diagnostics (DM-only).
 * @return callable Provider-turn adapter callable.
 */
function datamachine_build_provider_turn_adapter(
	array $tools,
	string $provider,
	string $model,
	string $mode,
	array $modes,
	array $loop_payload,
	LoopEventSinkInterface $event_sink,
	array $base_log_context,
	array &$last_tool_calls,
	array &$all_tool_calls,
	DataMachineProviderTurnState $turn_state,
	WP_Agent_Conversation_Completion_Policy $completion_policy,
	array &$completion_nudges
): callable {
	return static function ( $provider_turn_request, array $turn_context = array() ) use (
		$tools,
		$provider,
		$model,
		$mode,
		$modes,
		$loop_payload,
		$event_sink,
		$base_log_context,
		$completion_policy,
		$turn_state,
		&$last_tool_calls,
		&$all_tool_calls,
		&$completion_nudges
	): array {
		$messages = is_array( $provider_turn_request ) ? $provider_turn_request : array();
		if ( is_object( $provider_turn_request ) ) {
			if ( method_exists( $provider_turn_request, 'messages' ) ) {
				$messages = $provider_turn_request->messages();
			}
			if ( method_exists( $provider_turn_request, 'runtimeContext' ) ) {
				$request_context = $provider_turn_request->runtimeContext();
				if ( is_array( $request_context ) ) {
					$turn_context = array_merge( $request_context, $turn_context );
				}
			}
		}

		// The upstream loop provides the turn number via turn_context.
		$turn_count                    = (int) ( $turn_context['turn'] ?? 1 );
		$turn_state->latest_turn_count = $turn_count;
		$turn_state->latest_messages   = $messages;

		// Dispatch one DM provider turn: prompt assembly + authenticated
		// wp-ai-client dispatch + provider-safe tool-name aliasing + per-turn
		// usage. Throws RuntimeException on failure (caught by the upstream loop);
		// the dispatched-turn state captured above lets the caller rebuild a
		// best-effort error result. See datamachine_dispatch_provider_turn() for
		// the agents-api#370/#371 default-adapter adoption boundary.
		try {
			$turn = datamachine_dispatch_provider_turn(
				$messages,
				$tools,
				$provider,
				$model,
				$modes,
				$loop_payload,
				$turn_state
			);
		} catch ( \RuntimeException $e ) {
			datamachine_emit_loop_event(
				$event_sink,
				'request_built',
				array_merge(
					$base_log_context,
					array(
						'turn_count'       => $turn_count,
						'provider'         => $provider,
						'model'            => $model,
						'success'          => false,
						'request_metadata' => $turn_state->last_request_metadata,
					)
				)
			);

			do_action(
				'datamachine_log',
				'error',
				'datamachine_run_conversation: AI request failed',
				array_merge(
					$base_log_context,
					array(
						'turn_count' => $turn_count,
						'error'      => $e->getMessage(),
						'provider'   => $provider,
					)
				)
			);

			throw $e;
		}

		// On success the helper has already recorded request metadata (including
		// the resolved finish_reason) into turn state.
		$request_metadata = $turn['request_metadata'];
		$tool_calls       = $turn['tool_calls'];
		$ai_content       = $turn['content'];
		$turn_usage       = $turn['usage'];
		$finish_reason    = $turn['finish_reason'];

		datamachine_emit_loop_event(
			$event_sink,
			'request_built',
			array_merge(
				$base_log_context,
				array(
					'turn_count'       => $turn_count,
					'provider'         => $provider,
					'model'            => $model,
					'success'          => true,
					'request_metadata' => $turn['request_metadata_pre_finish'],
				)
			)
		);

		$last_tool_calls = $tool_calls;
		foreach ( $tool_calls as $tool_call ) {
			$all_tool_calls[] = $tool_call;
		}

		$messages_with_response = $messages;

		// Build the assistant turn used for observers and completion policy. The
		// provider-turn adapter returns this as `content`; Agents API appends it to
		// the canonical transcript.
		if ( ! empty( $ai_content ) ) {
			$messages_with_response[] = ConversationManager::buildConversationMessage(
				'assistant',
				$ai_content,
				array( 'type' => 'text' )
			);
			do_action( 'datamachine_ai_response_received', $mode, $messages_with_response, $loop_payload );
		}

		if ( ! empty( $tool_calls ) ) {
			foreach ( $tool_calls as $tool_call ) {
				$tool_name       = (string) ( $tool_call['name'] ?? '' );
				$tool_parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();

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
			}

			return array(
				'tool_calls'       => $tool_calls,
				'request_metadata' => $request_metadata,
				'usage'            => $turn_usage,
				'content'          => $ai_content,
			);
		} else {
			$natural_completion_decision = $completion_policy instanceof NaturalCompletionPolicyInterface
				? $completion_policy->recordNaturalCompletion(
					$messages_with_response,
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

			$continuation_messages = array();

			if ( ! $conversation_complete ) {
				$completion_nudge = (string) ( $natural_completion_decision->context()['continuation_message'] ?? '' );
				if ( '' !== $completion_nudge ) {
					$continuation_messages[] = ConversationManager::buildConversationMessage( 'user', $completion_nudge );
					datamachine_record_completion_nudge(
						$completion_nudges,
						$event_sink,
						$base_log_context,
						$mode,
						array_merge( $messages_with_response, $continuation_messages ),
						$loop_payload,
						$natural_completion_decision->context(),
						$turn_count
					);
				}
			}
		}

		return array(
			'content'               => $ai_content,
			'continuation_messages' => $continuation_messages,
			'request_metadata'      => $request_metadata,
			'usage'                 => $turn_usage,
			'provider_diagnostics'  => array_filter(
				array(
					'datamachine' => array_filter(
						array(
							'finish_reason'         => $finish_reason,
							'conversation_complete' => $conversation_complete,
							'completion_nudge'      => $completion_nudge,
						),
						static fn( $value ) => null !== $value && '' !== $value
					),
				),
				static fn( $value ) => ! empty( $value )
			),
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
		$deferred = datamachine_prepare_runtime_tool_pending_result( $request, $payload );
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
 * Allocate Data Machine job metadata and build a canonical pending runtime-tool result.
 *
 * Agents API owns durable lifecycle normalization/persistence; Data Machine only
 * preallocates the job-backed request id and host metadata needed by its store.
 *
 * @param array<string,mixed> $request Runtime tool request.
 * @param array<string,mixed> $payload Loop/tool payload.
 * @return array<string,mixed>|\WP_Error Pending tool result or allocation error.
 */
function datamachine_prepare_runtime_tool_pending_result( array $request, array $payload ): array|\WP_Error {
	$pending_request = datamachine_prepare_runtime_tool_request( $request, $payload );
	if ( $pending_request instanceof \WP_Error ) {
		return $pending_request;
	}

	return array(
		'success'              => false,
		'pending'              => true,
		'status'               => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
		'tool_name'            => $pending_request['tool_name'],
		'executor'             => 'client',
		'code'                 => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
		'error'                => 'Runtime tool request is pending client fulfillment.',
		'runtime'              => is_array( $pending_request['runtime'] ?? null ) ? $pending_request['runtime'] : array(),
		'runtime_tool_request' => $pending_request,
	);
}

/**
 * Allocate Data Machine job metadata for a canonical pending runtime-tool request.
 *
 * @param array<string,mixed> $request Runtime tool request.
 * @param array<string,mixed> $payload Loop/tool payload.
 * @return array<string,mixed>|\WP_Error Canonical request or allocation error.
 */
function datamachine_prepare_runtime_tool_request( array $request, array $payload ): array|\WP_Error {
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
	$created_at      = gmdate( 'c' );
	$expires_at      = gmdate( 'c', time() + $timeout_seconds );

	return WP_Agent_Runtime_Tool_Request::normalize(
		array(
			'request_id'   => $request_id,
			'tool_name'    => (string) ( $request['tool_name'] ?? '' ),
			'tool_call_id' => (string) ( $request['tool_call_id'] ?? $request['call_id'] ?? $request_id ),
			'parameters'   => is_array( $request['parameters'] ?? null ) ? $request['parameters'] : array(),
			'run_id'       => (string) ( $request['session_id'] ?? '' ),
			'timeout_at'   => $expires_at,
			'runtime'      => array(
				'executor' => 'client',
				'scope'    => 'run',
			),
			'metadata'     => array(
				'datamachine' => array(
					'job_id'             => (int) $job_id,
					'parent_job_id'      => max( 0, (int) ( $payload['job_id'] ?? $request['job_id'] ?? 0 ) ),
					'persistence_status' => 'pending',
					'session_id'         => (string) ( $request['session_id'] ?? '' ),
					'user_id'            => (int) ( $payload['user_id'] ?? 0 ),
					'agent_id'           => (int) ( $payload['agent_id'] ?? 0 ),
					'mode'               => (string) ( $request['mode'] ?? '' ),
					'modes'              => is_array( $request['modes'] ?? null ) ? $request['modes'] : array(),
					'turn_count'         => (int) ( $request['turn_count'] ?? 0 ),
					'created_at'         => $created_at,
					'expires_at'         => $expires_at,
					'timeout_seconds'    => $timeout_seconds,
				),
			),
		)
	);
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
	$pending_request = datamachine_prepare_runtime_tool_request( $request, $payload );
	if ( $pending_request instanceof \WP_Error ) {
		return $pending_request;
	}

	$pending_request = WP_Agent_Runtime_Tool_Lifecycle::create_pending_request(
		datamachine_runtime_tool_request_store(),
		$pending_request,
		array( 'payload' => $payload )
	);
	return array(
		'success'              => false,
		'pending'              => true,
		'status'               => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
		'tool_name'            => $pending_request['tool_name'],
		'executor'             => 'client',
		'code'                 => WP_Agent_Runtime_Tool_Request::STATUS_PENDING,
		'error'                => 'Runtime tool request is pending client fulfillment.',
		'runtime_tool_request' => $pending_request,
	);
}

/** Return Data Machine's Agents API runtime-tool request store adapter. */
function datamachine_runtime_tool_request_store(): WP_Agent_Runtime_Tool_Request_Store {
	static $store = null;

	if ( ! $store instanceof WP_Agent_Runtime_Tool_Request_Store ) {
		$store = new class() implements WP_Agent_Runtime_Tool_Request_Store {
			public function create( array $request ): void {
				$job_id = datamachine_runtime_tool_job_id_from_request( $request );
				if ( $job_id <= 0 ) {
					return;
				}

				$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
				$jobs_db->start_job( (int) $job_id, 'pending_runtime_tool' );
				$jobs_db->store_engine_data(
					$job_id,
					array(
						'task_type'              => 'runtime_tool_request',
						'runtime_tool_request'   => $request,
						'runtime_tool_run_state' => ( new RuntimeToolRunStateStore( $jobs_db ) )->create_from_request( $request ),
					)
				);
				datamachine_store_runtime_tool_request_on_session( $request );
				datamachine_schedule_runtime_tool_timeout( $request );
				do_action( 'datamachine_runtime_tool_request_deferred', $request, array() );
			}

			public function get( string $request_id ): ?array {
				$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
				if ( $job_id <= 0 ) {
					return null;
				}

				$engine_data = ( new \DataMachine\Core\Database\Jobs\Jobs() )->retrieve_engine_data( $job_id );
				$request     = is_array( $engine_data['runtime_tool_request'] ?? null )
					? datamachine_normalize_stored_runtime_tool_request( $engine_data['runtime_tool_request'] )
					: null;
				if ( empty( $request ) ) {
					return null;
				}

				return $request;
			}

			public function complete( string $request_id, array $result ): void {
				$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
				if ( $job_id <= 0 ) {
					return;
				}

				$jobs_db     = new \DataMachine\Core\Database\Jobs\Jobs();
				$engine_data = $jobs_db->retrieve_engine_data( $job_id );
				$request     = is_array( $engine_data['runtime_tool_request'] ?? null ) ? $engine_data['runtime_tool_request'] : array();
				if ( empty( $request ) ) {
					return;
				}

				$datamachine_metadata                       = datamachine_runtime_tool_datamachine_metadata( $request );
				$datamachine_metadata['persistence_status'] = ! empty( $result['success'] ) ? 'fulfilled' : 'failed';
				$datamachine_metadata['fulfilled_at']       = gmdate( 'c' );
				$datamachine_metadata['result']             = $result;
				$request['metadata']['datamachine']         = $datamachine_metadata;
				$engine_data['runtime_tool_request']        = $request;
				$engine_data['runtime_tool_run_state']      = ( new RuntimeToolRunStateStore( $jobs_db ) )->finalize(
					$job_id,
					array( 'result' => $result ),
					! empty( $result['metadata']['datamachine']['code'] ) && WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT === (string) $result['metadata']['datamachine']['code']
						? RuntimeToolRunStateStore::STATUS_TIMED_OUT
						: RuntimeToolRunStateStore::STATUS_FINALIZED
				);

				$jobs_db->store_engine_data( $job_id, $engine_data );
				$jobs_db->complete_job( $job_id, ! empty( $result['success'] ) ? 'completed' : 'failed' );
			}

			public function timeout( string $request_id ): void {
				$request = $this->get( $request_id );
				if ( null === $request ) {
					return;
				}

				$this->complete(
					$request_id,
					WP_Agent_Runtime_Tool_Result::normalize(
						array(
							'request_id' => $request_id,
							'tool_name'  => (string) ( $request['tool_name'] ?? '' ),
							'success'    => false,
							'error'      => 'Client runtime tool request timed out.',
							'metadata'   => array(
								'datamachine' => array(
									'code' => WP_Agent_Runtime_Tool_Request::STATUS_TIMEOUT,
								),
							),
						)
					)
				);
			}

			public function recent_pending( array $query = array() ): array {
				unset( $query );

				return array();
			}
		};
	}

	return $store;
}

/**
 * Store pending runtime tool request metadata on the owning chat session.
 *
 * @param array<string,mixed> $pending_request Pending request metadata.
 */
function datamachine_store_runtime_tool_request_on_session( array $pending_request ): void {
	$datamachine_metadata = datamachine_runtime_tool_datamachine_metadata( $pending_request );
	$session_id           = (string) ( $datamachine_metadata['session_id'] ?? '' );
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

/** Schedule the Data Machine timeout action for a pending runtime-tool request. */
function datamachine_schedule_runtime_tool_timeout( array $pending_request ): void {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}

	$request_id = (string) ( $pending_request['request_id'] ?? '' );
	if ( '' === $request_id ) {
		return;
	}

	$metadata        = datamachine_runtime_tool_datamachine_metadata( $pending_request );
	$timeout_seconds = max( 5, (int) ( $metadata['timeout_seconds'] ?? 300 ) );
	as_schedule_single_action( time() + $timeout_seconds, 'datamachine_runtime_tool_timeout', array( $request_id ), 'datamachine-runtime-tools' );
}

/**
 * Submit a client result for a deferred runtime tool request.
 *
 * @param string $request_id Runtime tool request id (`runtime_tool_<job_id>`).
 * @param mixed  $result     Client tool result.
 * @return array<string,mixed>|\WP_Error Submission result.
 */
function datamachine_submit_runtime_tool_result( string $request_id, $result ): array|\WP_Error {
	$store   = datamachine_runtime_tool_request_store();
	$request = $store->get( $request_id );
	if ( null === $request ) {
		return new \WP_Error( 'runtime_tool_request_invalid', 'Runtime tool request id is invalid.' );
	}

	$datamachine_metadata = datamachine_runtime_tool_datamachine_metadata( $request );
	if ( 'pending' !== (string) ( $datamachine_metadata['persistence_status'] ?? '' ) ) {
		return new \WP_Error( 'runtime_tool_request_not_pending', 'Runtime tool request is not pending.' );
	}

	try {
		$envelope = WP_Agent_Runtime_Tool_Lifecycle::submit_result(
			$store,
			datamachine_runtime_tool_submission_payload( $request_id, (string) ( $request['tool_name'] ?? '' ), $result ),
			__NAMESPACE__ . '\\datamachine_continue_runtime_tool_request',
			array( 'source' => 'submit' )
		);
	} catch ( \InvalidArgumentException $e ) {
		return new \WP_Error( 'runtime_tool_result_invalid', $e->getMessage() );
	}

	$continuation_result = is_array( $envelope['continuation_result'] ?? null ) ? $envelope['continuation_result'] : array();

	return array(
		'success'    => true,
		'request_id' => $request_id,
		'job_id'     => datamachine_runtime_tool_job_id_from_request_id( $request_id ),
		'scheduled'  => ! empty( $continuation_result['scheduled'] ),
	);
}

/**
 * Project a submitted runtime-tool result into Data Machine chat/session state and resume queue.
 *
 * @param array<string,mixed> $request          Canonical runtime-tool request.
 * @param array<string,mixed> $canonical_result Canonical runtime-tool result.
 * @param array<string,mixed> $context          Continuation context.
 * @return array<string,mixed> Resume scheduling result.
 */
function datamachine_continue_runtime_tool_request( array $request, array $canonical_result, array $context = array() ): array {
	unset( $context );

	$request_id           = (string) ( $request['request_id'] ?? '' );
	$datamachine_metadata = datamachine_runtime_tool_datamachine_metadata( $request );
	$tool_result          = datamachine_runtime_tool_result_for_transcript( $canonical_result );
	$session_id           = (string) ( $datamachine_metadata['session_id'] ?? '' );
	if ( '' === $session_id || ! class_exists( \DataMachine\Core\Database\Chat\ConversationStoreFactory::class ) ) {
		return array( 'scheduled' => false );
	}

	$chat_db = \DataMachine\Core\Database\Chat\ConversationStoreFactory::get();
	$session = $chat_db->get_session( $session_id );
	if ( ! is_array( $session ) ) {
		return array( 'scheduled' => false );
	}

	$messages   = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
	$messages[] = ConversationManager::formatToolResultMessage(
		(string) ( $request['tool_name'] ?? '' ),
		$tool_result,
		is_array( $request['parameters'] ?? null ) ? $request['parameters'] : array(),
		false,
		(int) ( $datamachine_metadata['turn_count'] ?? 0 )
	);

	$metadata = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
	if ( is_array( $metadata['runtime_tool_requests'] ?? null ) && isset( $metadata['runtime_tool_requests'][ $request_id ] ) ) {
		$session_request                        = $metadata['runtime_tool_requests'][ $request_id ];
		$request_metadata                       = datamachine_runtime_tool_datamachine_metadata( $session_request );
		$request_metadata['persistence_status'] = ! empty( $tool_result['success'] ) ? 'fulfilled' : 'failed';
		$request_metadata['fulfilled_at']       = gmdate( 'c' );
		$request_metadata['result']             = $canonical_result;

		$session_request['metadata']['datamachine']       = $request_metadata;
		$metadata['runtime_tool_requests'][ $request_id ] = $session_request;
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

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'datamachine_runtime_tool_resume', array( $request_id ), 'datamachine-runtime-tools' );
	}

	do_action( 'datamachine_runtime_tool_result_submitted', $request, $tool_result );

	return array(
		'scheduled'  => function_exists( 'as_enqueue_async_action' ),
		'request_id' => $request_id,
	);
}

/** Resume a deferred runtime tool conversation. */
function datamachine_resume_runtime_tool_request( string $request_id ): void {
	if ( ! class_exists( \DataMachine\Api\Chat\ChatOrchestrator::class ) ) {
		return;
	}

	$request = datamachine_runtime_tool_request_store()->get( $request_id );
	if ( null === $request ) {
		return;
	}
	$datamachine_metadata = datamachine_runtime_tool_datamachine_metadata( $request );
	if ( 'pending' === (string) ( $datamachine_metadata['persistence_status'] ?? '' ) ) {
		return;
	}

	$job_id = datamachine_runtime_tool_job_id_from_request_id( $request_id );
	if ( $job_id > 0 ) {
		( new RuntimeToolRunStateStore() )->resume(
			$job_id,
			array(
				'session_id' => (string) ( $datamachine_metadata['session_id'] ?? '' ),
				'user_id'    => (int) ( $datamachine_metadata['user_id'] ?? 0 ),
			)
		);
	}

	\DataMachine\Api\Chat\ChatOrchestrator::processContinue(
		(string) ( $datamachine_metadata['session_id'] ?? '' ),
		(int) ( $datamachine_metadata['user_id'] ?? 0 )
	);
}

/** Fail and resume an expired runtime tool request. */
function datamachine_timeout_runtime_tool_request( string $request_id ): void {
	$request = datamachine_runtime_tool_request_store()->get( $request_id );
	if ( null === $request ) {
		return;
	}

	$datamachine_metadata = datamachine_runtime_tool_datamachine_metadata( $request );
	if ( 'pending' !== (string) ( $datamachine_metadata['persistence_status'] ?? '' ) ) {
		return;
	}

	try {
		WP_Agent_Runtime_Tool_Lifecycle::timeout_request(
			datamachine_runtime_tool_request_store(),
			$request_id,
			__NAMESPACE__ . '\\datamachine_continue_runtime_tool_request',
			array( 'source' => 'timeout' )
		);
	} catch ( \InvalidArgumentException $e ) {
		unset( $e );
	}
}

/** @param array<string,mixed> $metadata Session metadata. */
function datamachine_session_has_pending_runtime_tools( array $metadata ): bool {
	foreach ( (array) ( $metadata['runtime_tool_requests'] ?? array() ) as $request ) {
		if ( is_array( $request ) && 'pending' === (string) ( datamachine_runtime_tool_datamachine_metadata( $request )['persistence_status'] ?? '' ) ) {
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

/** @param array<string,mixed> $request Canonical runtime tool request. */
function datamachine_runtime_tool_job_id_from_request( array $request ): int {
	$metadata = datamachine_runtime_tool_datamachine_metadata( $request );
	$job_id   = (int) ( $metadata['job_id'] ?? 0 );

	return $job_id > 0 ? $job_id : datamachine_runtime_tool_job_id_from_request_id( (string) ( $request['request_id'] ?? '' ) );
}

/**
 * Normalize a stored runtime-tool request through the Agents API contract.
 *
 * @param array<string,mixed> $request Stored request payload.
 * @return array<string,mixed>|null Canonical request or null when invalid.
 */
function datamachine_normalize_stored_runtime_tool_request( array $request ): ?array {
	try {
		return WP_Agent_Runtime_Tool_Request::normalize( $request );
	} catch ( \InvalidArgumentException $e ) {
		unset( $e );
		return null;
	}
}

/**
 * Read Data Machine-owned metadata from a canonical runtime-tool payload.
 *
 * @param array<string,mixed> $payload Canonical runtime-tool request or result.
 * @return array<string,mixed>
 */
function datamachine_runtime_tool_datamachine_metadata( array $payload ): array {
	$metadata = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
	if ( is_array( $metadata['datamachine'] ?? null ) ) {
		return $metadata['datamachine'];
	}

	return array();
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

/**
 * Convert a client-submitted runtime tool result into an Agents API lifecycle payload.
 *
 * @param string $request_id Canonical request id.
 * @param string $tool_name Tool name.
 * @param mixed  $result Raw client result.
 * @return array<string,mixed> Raw lifecycle result payload.
 */
function datamachine_runtime_tool_submission_payload( string $request_id, string $tool_name, $result ): array {
	$datamachine_metadata = array();

	if ( $result instanceof \WP_Error ) {
		$datamachine_metadata['code'] = $result->get_error_code();
		return array(
			'request_id' => $request_id,
			'tool_name'  => $tool_name,
			'success'    => false,
			'error'      => $result->get_error_message(),
			'metadata'   => array( 'datamachine' => $datamachine_metadata ),
		);
	}

	if ( null === $result ) {
		$datamachine_metadata['code'] = 'runtime_tool_unfulfilled';
		return array(
			'request_id' => $request_id,
			'tool_name'  => $tool_name,
			'success'    => false,
			'error'      => sprintf( 'Client runtime tool "%s" did not return a result.', $tool_name ),
			'metadata'   => array( 'datamachine' => $datamachine_metadata ),
		);
	}

	if ( is_array( $result ) ) {
		$success = array_key_exists( 'success', $result ) ? (bool) $result['success'] : true;
		$payload = $result['result'] ?? $result['data'] ?? $result;
		$error   = $result['error'] ?? 'Runtime tool execution failed.';
		if ( isset( $result['code'] ) && is_string( $result['code'] ) ) {
			$datamachine_metadata['code'] = $result['code'];
		}

		return array(
			'request_id' => $request_id,
			'tool_name'  => is_string( $result['tool_name'] ?? null ) ? $result['tool_name'] : $tool_name,
			'success'    => $success,
			'result'     => $payload,
			'error'      => $error,
			'metadata'   => array( 'datamachine' => $datamachine_metadata ),
		);
	}

	return array(
		'request_id' => $request_id,
		'tool_name'  => $tool_name,
		'success'    => true,
		'result'     => $result,
		'metadata'   => array( 'datamachine' => $datamachine_metadata ),
	);
}

/**
 * Convert a canonical async result into Data Machine's transcript tool result.
 *
 * @param array<string,mixed> $canonical_result Canonical runtime tool result.
 * @return array<string,mixed> Transcript-compatible tool result.
 */
function datamachine_runtime_tool_result_for_transcript( array $canonical_result ): array {
	$tool_result = array(
		'success'   => ! empty( $canonical_result['success'] ),
		'tool_name' => (string) ( $canonical_result['tool_name'] ?? '' ),
		'executor'  => 'client',
		'result'    => is_array( $canonical_result['result'] ?? null ) ? $canonical_result['result'] : ( $canonical_result['result'] ?? array() ),
	);

	if ( empty( $tool_result['success'] ) ) {
		$tool_result['error'] = (string) ( $canonical_result['error'] ?? 'Runtime tool execution failed.' );
		$metadata             = datamachine_runtime_tool_datamachine_metadata( $canonical_result );
		if ( isset( $metadata['code'] ) && is_string( $metadata['code'] ) ) {
			$tool_result['code'] = $metadata['code'];
		}
	}

	return $tool_result;
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
 * Record configured successful tool result fields into job engine_data.
 *
 * @param array<string,mixed>            $loop_payload           Loop payload.
 * @param array<int,array<string,mixed>> $tool_execution_results Tool execution results accumulated by the loop.
 */
function datamachine_record_tool_results_to_engine_data( array $loop_payload, array $tool_execution_results ): void {
	$job_id     = (int) ( $loop_payload['job_id'] ?? 0 );
	$recorders  = is_array( $loop_payload['tool_recorders'] ?? null ) ? $loop_payload['tool_recorders'] : array();
	$can_record = function_exists( '\datamachine_append_engine_state_event' ) || function_exists( '\datamachine_merge_engine_data' );
	if ( $job_id <= 0 || empty( $recorders ) || ! $can_record ) {
		return;
	}

	$engine_data = array();
	foreach ( $tool_execution_results as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$tool_name = (string) ( $entry['tool_name'] ?? $entry['name'] ?? '' );
		$result    = is_array( $entry['result'] ?? null ) ? $entry['result'] : array();
		if ( '' === $tool_name || empty( $result['success'] ) ) {
			continue;
		}

		foreach ( $recorders as $recorder ) {
			if ( ! is_array( $recorder ) || (string) ( $recorder['tool'] ?? '' ) !== $tool_name ) {
				continue;
			}

			$record = is_array( $recorder['record'] ?? null ) ? $recorder['record'] : array();
			$key    = sanitize_key( (string) ( $record['engine_key'] ?? '' ) );
			$fields = is_array( $record['fields'] ?? null ) ? $record['fields'] : array();
			if ( '' === $key || empty( $fields ) ) {
				continue;
			}

			$values = datamachine_tool_result_record_values( $entry, $fields );
			if ( ! empty( $values ) ) {
				$engine_data[ $key ] = array_merge( is_array( $engine_data[ $key ] ?? null ) ? $engine_data[ $key ] : array(), $values );
			}
		}
	}

	if ( ! empty( $engine_data ) && function_exists( '\datamachine_append_engine_state_event' ) ) {
		\datamachine_append_engine_state_event(
			$job_id,
			'tool_result_recorded',
			$engine_data,
			array( 'source' => 'conversation_loop' )
		);
		return;
	}

	if ( ! empty( $engine_data ) ) {
		\datamachine_merge_engine_data( $job_id, $engine_data );
	}
}

/**
 * @param array<string,mixed> $entry  Tool result entry.
 * @param array<string,mixed> $fields Field mapping config.
 * @return array<string,mixed>
 */
function datamachine_tool_result_record_values( array $entry, array $fields ): array {
	$values               = array();
	$result               = is_array( $entry['result'] ?? null ) ? $entry['result'] : $entry;
	$nested_result        = is_array( $result['result'] ?? null ) ? $result['result'] : array();
	$metadata             = is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array();
	$tool_result_data     = datamachine_first_tool_result_data_container(
		is_array( $entry['tool_result_data'] ?? null ) ? $entry['tool_result_data'] : array(),
		is_array( $result['tool_result_data'] ?? null ) ? $result['tool_result_data'] : array(),
		is_array( $metadata['tool_result_data'] ?? null ) ? $metadata['tool_result_data'] : array(),
		$nested_result
	);
	$tool_result_envelope = is_array( $entry['tool_result_envelope'] ?? null ) ? $entry['tool_result_envelope'] : ( is_array( $result['tool_result_envelope'] ?? null ) ? $result['tool_result_envelope'] : array() );
	$envelope_result      = is_array( $tool_result_envelope['result'] ?? null ) ? $tool_result_envelope['result'] : array();
	$source               = array(
		'data'                 => datamachine_first_tool_result_data( $result, $nested_result, $tool_result_data, $tool_result_envelope, $envelope_result ),
		'entry'                => $entry,
		'result'               => $result,
		'tool_result_data'     => $tool_result_data,
		'tool_result_envelope' => $tool_result_envelope,
		'metadata'             => $metadata,
	);

	foreach ( $fields as $field => $selector ) {
		$field = sanitize_key( (string) $field );
		if ( '' === $field ) {
			continue;
		}

		$value = datamachine_tool_result_record_value( $source, $selector );
		if ( null !== $value && '' !== $value && array() !== $value ) {
			$values[ $field ] = $value;
		}
	}

	return $values;
}

/**
 * @param array<string,mixed> ...$candidates Candidate tool result data containers.
 * @return array<string,mixed>
 */
function datamachine_first_tool_result_data_container( array ...$candidates ): array {
	foreach ( $candidates as $candidate ) {
		if ( ! empty( $candidate ) ) {
			return $candidate;
		}
	}

	return array();
}

/**
 * @param array<string,mixed> ...$candidates Candidate result containers.
 * @return array<string,mixed>
 */
function datamachine_first_tool_result_data( array ...$candidates ): array {
	foreach ( $candidates as $candidate ) {
		if ( is_array( $candidate['data'] ?? null ) && ! empty( $candidate['data'] ) ) {
			return $candidate['data'];
		}
	}

	return array();
}

/**
 * @param array<string,mixed> $source   Tool result source map.
 * @param mixed               $selector Field selector.
 * @return mixed
 */
function datamachine_tool_result_record_value( array $source, mixed $selector ): mixed {
	if ( is_scalar( $selector ) ) {
		return \DataMachine\Core\DataPath::value( $source, (string) $selector );
	}

	if ( ! is_array( $selector ) ) {
		return null;
	}

	$paths = is_array( $selector['paths'] ?? null ) ? $selector['paths'] : array();
	foreach ( $paths as $path ) {
		if ( ! is_scalar( $path ) ) {
			continue;
		}

		$value = \DataMachine\Core\DataPath::value( $source, (string) $path );
		if ( ! \DataMachine\Core\DataPath::hasValue( $value ) ) {
			continue;
		}

		if ( is_scalar( $value ) && is_scalar( $selector['strip_prefix'] ?? null ) ) {
			$prefix = (string) $selector['strip_prefix'];
			$value  = str_starts_with( (string) $value, $prefix ) ? substr( (string) $value, strlen( $prefix ) ) : $value;
		}

		return $value;
	}

	return null;
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
 * Map provider-safe function names back to Data Machine/Agents API logical names.
 *
 * @param array<int,array<string,mixed>> $tool_calls Tool calls extracted from the provider result.
 * @param array<string,mixed>            $request_metadata Request metadata emitted by RequestBuilder.
 * @return array<int,array<string,mixed>> Tool calls with logical names restored where aliases exist.
 */
function datamachine_apply_provider_tool_name_aliases( array $tool_calls, array $request_metadata ): array {
	$aliases = $request_metadata['tool_name_aliases']['provider_to_logical'] ?? array();
	if ( empty( $aliases ) || ! is_array( $aliases ) ) {
		return $tool_calls;
	}

	foreach ( $tool_calls as &$tool_call ) {
		$name = is_string( $tool_call['name'] ?? null ) ? $tool_call['name'] : '';
		if ( isset( $aliases[ $name ] ) && is_string( $aliases[ $name ] ) && '' !== $aliases[ $name ] ) {
			$tool_call['provider_name'] = $name;
			$tool_call['name']          = $aliases[ $name ];
		}
	}
	unset( $tool_call );

	return $tool_calls;
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
 * Mirror safe run context into client_context for declared tool bindings.
 *
 * Agents API only satisfies tool parameters through explicit
 * `client_context_bindings`, but Data Machine pipeline context historically
 * stores run identifiers at the top level of the loop payload. Keep the
 * binding source auditable by copying only the fields tools already declare as
 * context-bindable instead of matching arbitrary parameter names.
 *
 * @param array $payload Clean loop payload.
 * @return array Payload with context-bindable run fields in client_context.
 */
function datamachine_payload_with_client_context_bindings( array $payload ): array {
	$client_context = is_array( $payload['client_context'] ?? null ) ? $payload['client_context'] : array();

	foreach ( array( 'job_id', 'flow_step_id', 'step_id', 'user_id', 'agent_id', 'agent_slug' ) as $key ) {
		if ( array_key_exists( $key, $client_context ) || ! array_key_exists( $key, $payload ) ) {
			continue;
		}

		$value = $payload[ $key ];
		if ( null === $value || '' === $value || is_array( $value ) || is_object( $value ) ) {
			continue;
		}

		$client_context[ $key ] = $value;
	}

	if ( ! empty( $client_context ) ) {
		$payload['client_context'] = $client_context;
	}

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
