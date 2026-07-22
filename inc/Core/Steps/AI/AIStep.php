<?php

namespace DataMachine\Core\Steps\AI;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentIdentity;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\AI\ToolPolicy\PipelineToolPolicyArgs;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\QueueableTrait;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\DataPacketPromptProjector;
use DataMachine\Engine\AI\PipelineAIConcurrencyLease;
use DataMachine\Engine\AI\PipelineAIConcurrencyLimiter;
use DataMachine\Engine\AI\PipelineTranscriptPolicy;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolResultFinder;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

use function DataMachine\Engine\AI\datamachine_run_conversation;
use function DataMachine\Engine\AI\datamachine_conversation_metadata;
use function DataMachine\Engine\AI\datamachine_normalize_typed_artifact_outputs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-turn conversational AI agent with tool execution and completion detection.
 *
 * @package DataMachine
 */
class AIStep extends Step {

	use StepTypeRegistrationTrait;
	use QueueableTrait;

	/**
	 * Maximum age for ordinary AI concurrency contention before work is
	 * classified as stranded for operator review.
	 *
	 * A count is not a safe terminal bound because a healthy single-slot queue
	 * can legitimately contend many times. The absolute age bounds truly
	 * stranded work while ordinary contention remains pending (#2929).
	 *
	 * Filterable via `datamachine_ai_concurrency_max_defer_age`.
	 */
	private const AI_CONCURRENCY_MAX_DEFER_AGE = DAY_IN_SECONDS;

	/**
	 * Ceiling (seconds) for the exponential backoff applied between AI
	 * concurrency defers. Keeps a long-saturated queue from hammering the
	 * limiter on the base throttle delay for the full attempt budget.
	 */
	private const AI_CONCURRENCY_MAX_DEFER_DELAY = 600;

	/**
	 * Initialize AI step.
	 */
	public function __construct() {
		parent::__construct( 'ai' );

		self::registerStepType(
			slug: 'ai',
			label: 'AI Agent',
			description: 'Configure an intelligent agent with custom prompts and tools to process data through any LLM provider (OpenAI, Anthropic, Google, Grok, OpenRouter)',
			class_name: self::class,
			position: 20,
			usesHandler: false,
			hasPipelineConfig: true,
			consumeAllPackets: true,
			stepSettings: array(
				'config_type' => 'ai_configuration',
				'modal_type'  => 'configure-step',
				'button_text' => 'Configure',
				'label'       => 'AI Agent Configuration',
			)
		);
	}

	/**
	 * Validate AI step configuration requirements.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		if ( ! isset( $this->flow_step_config['pipeline_step_id'] ) || empty( $this->flow_step_config['pipeline_step_id'] ) ) {
			$this->log(
				'error',
				'Missing pipeline_step_id in AI step configuration',
				array(
					'flow_step_config' => $this->flow_step_config,
				)
			);
			return false;
		}

		$pipeline_step_id = $this->flow_step_config['pipeline_step_id'];

		$pipeline_step_config = $this->engine->getPipelineStepConfig( $pipeline_step_id );
		$job_snapshot         = $this->engine->get( 'job' );
		$job_snapshot         = is_array( $job_snapshot ) ? $job_snapshot : array();
		$agent_id             = $this->resolveAgentIdFromJobSnapshot( $job_snapshot );

		// Model/provider are resolved exclusively via the mode system
		// (agent mode_models → site → network → default). There are no
		// per-pipeline-step model/provider fields: the editor does not write
		// them (see Api\Pipelines\PipelineSteps) and AIStep does not read them.
		$execution_modes = self::resolveExecutionModes( $pipeline_step_config, $this->flow_step_config );
		$mode_model      = self::resolveModelForExecutionModes( $agent_id, $execution_modes, $job_snapshot );
		$provider_name   = $mode_model['provider'];
		if ( empty( $provider_name ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'ai_provider_missing',
				array(
					'flow_step_id'     => $this->flow_step_id,
					'pipeline_step_id' => $pipeline_step_id,
					'agent_modes'      => $execution_modes,
					'error_message'    => sprintf( 'AI step requires provider configuration for agent modes "%s". Set a default provider in Data Machine settings or configure mode_models.', implode( ',', $execution_modes ) ),
					'solution'         => sprintf( 'Set default_provider in Data Machine settings or configure mode_models for one of these modes: %s', implode( ', ', $execution_modes ) ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Execute AI step logic.
	 *
	 * @return array
	 */
	protected function executeStep(): array {

		// Pre-AI dedup gate: allow extensions to short-circuit the AI step
		// when the work has already been done (e.g., event already exists in
		// the database). This saves AI credits by skipping the conversation
		// entirely when identity fields in engine_data match an existing record.
		//
		// Filter returns null to proceed normally, or an array with:
		// 'skip'   => true
		// 'reason' => string (for logging)
		// 'status' => string (job status override, e.g. 'completed_no_items')
		$pre_check = apply_filters( 'datamachine_pre_ai_step_check', null, $this->engine, $this->flow_step_config, $this->job_id );

		if ( is_array( $pre_check ) && ! empty( $pre_check['skip'] ) ) {
			$status = $pre_check['status'] ?? \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS;
			$reason = $pre_check['reason'] ?? 'pre-AI check determined processing is unnecessary';

			$this->engine->set( 'job_status', $status );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array(
					'step_type'    => 'ai',
					'result'       => 'skipped',
					'packet_count' => 0,
					'reason'       => $reason,
					'status'       => $status,
				)
			);

			do_action(
				'datamachine_log',
				'info',
				'AIStep: Skipped by pre-AI check — ' . $reason,
				array(
					'job_id'       => $this->job_id,
					'flow_step_id' => $this->flow_step_id,
					'reason'       => $reason,
					'status'       => $status,
				)
			);

			return $this->dataPackets;
		}

		$pipeline_step_id = $this->flow_step_config['pipeline_step_id'];

		// Resolve agent identity from engine snapshot (set by RunFlowAbility).
		$job_snapshot = $this->engine->get( 'job' );
		$job_snapshot = is_array( $job_snapshot ) ? $job_snapshot : array();
		$identity     = $this->resolveAgentIdentityForExecution( $job_snapshot );
		if ( null === $identity ) {
			$this->failMissingAgentContext( $job_snapshot, (string) $pipeline_step_id );
			return array();
		}

		$agent_id   = $identity->agent_id;
		$agent_slug = $identity->agent_slug;
		$owner_id   = $identity->owner_id;

		$pipeline_step_config = $this->engine->getPipelineStepConfig( $pipeline_step_id );
		$execution_modes      = self::resolveExecutionModes( $pipeline_step_config, $this->flow_step_config );

		// Model/provider are resolved exclusively via the mode system. There is no
		// per-pipeline-step model override — pipeline_config carries only modes,
		// prompts, and tool policy, never a model/provider field.
		$mode_model    = self::resolveModelForExecutionModes( $agent_id, $execution_modes, $job_snapshot );
		$provider_name = $mode_model['provider'];
		$model_name    = $mode_model['model'];
		$mode_label    = implode( ',', $execution_modes );

		$lease_result = PipelineAIConcurrencyLimiter::acquire(
			$provider_name,
			array(
				'job_id'           => $this->job_id,
				'flow_step_id'     => $this->flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $job_snapshot['pipeline_id'] ?? null,
				'flow_id'          => $job_snapshot['flow_id'] ?? null,
				'mode'             => $mode_label,
			)
		);

		if ( empty( $lease_result['acquired'] ) ) {
			$this->deferForAIConcurrency( $provider_name, $lease_result );
			return array();
		}

		$this->resolveAIConcurrencyContention();
		$ai_concurrency_lease = $lease_result['lease'] ?? null;

		try {
			// AIStep reads a single prompt slot — `prompt_queue` — under one
			// of three access modes. The mode picks the access pattern and the
			// queue head is the only source of per-flow user-role content.
			$queue_mode   = $this->flow_step_config['queue_mode'] ?? 'static';
			$queue_result = $this->consumeFromPromptQueue( $queue_mode );
			$user_message = $queue_result['value'];

			if ( '' === $user_message ) {
				// Empty queue in drain or loop modes implies per-tick work
				// that can't proceed — short-circuit cleanly so the engine
				// completes with COMPLETED_NO_ITEMS rather than treating the
				// missing prompt as a failure.
				if ( in_array( $queue_mode, array( 'drain', 'loop' ), true ) ) {
					do_action(
						'datamachine_log',
						'info',
						'AI step skipped — queue mode requires per-tick prompt but queue is empty',
						array(
							'job_id'       => $this->job_id,
							'flow_step_id' => $this->flow_step_id,
							'queue_mode'   => $queue_mode,
						)
					);

					$this->engine->set( 'job_status', \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS );
					RunMetrics::recordStepResult(
						$this->job_id,
						$this->flow_step_id,
						array(
							'step_type'    => 'ai',
							'result'       => 'completed_no_items',
							'packet_count' => 0,
							'reason'       => 'empty_queue',
							'queue_mode'   => $queue_mode,
						)
					);

					return $this->dataPackets;
				}

				// Static mode + empty queue: no flow-level user message,
				// but the pipeline system_prompt and any data packets still
				// drive the conversation. Fall through with $user_message=''.
			}

			$file_path    = null;
			$mime_type    = null;
			$engine_image = $this->engine->get( 'image_file_path' );
			if ( $engine_image && file_exists( $engine_image ) ) {
				$file_path = $engine_image;
				$file_info = wp_check_filetype( $engine_image );
				$mime_type = is_string( $file_info['type'] ) ? $file_info['type'] : '';
			}

			$packet_projection_context = array(
				'job_id'           => $this->job_id,
				'pipeline_id'      => $job_snapshot['pipeline_id'] ?? null,
				'flow_id'          => $job_snapshot['flow_id'] ?? null,
				'flow_step_id'     => $this->flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
			);

			$messages            = array();
			$prompt_data_packets = $this->runtimeInputPacketsForPrompt( $this->dataPackets );

			if ( ! empty( $prompt_data_packets ) ) {
				$data_packet_content = wp_json_encode( array( 'data_packets' => DataPacketPromptProjector::project( $prompt_data_packets, $packet_projection_context ) ), JSON_UNESCAPED_UNICODE );
				$messages[]          = ConversationManager::buildConversationMessage(
					'user',
					false === $data_packet_content ? '' : $data_packet_content
				);
			}

			if ( $file_path && file_exists( $file_path ) ) {
				$messages[] = ConversationManager::buildConversationMessage(
					'user',
					array(
						array(
							'type'      => 'file',
							'file_path' => $file_path,
							'mime_type' => $mime_type,
						),
					)
				);
			}

			if ( ! empty( $user_message ) ) {
				$messages[] = ConversationManager::buildConversationMessage( 'user', $user_message );
			}

			$max_turns                   = PluginSettings::get( 'max_turns', PluginSettings::DEFAULT_MAX_TURNS );
			$transcript_consent_decision = PipelineTranscriptPolicy::decision( $this->engine );
			$persist_transcript          = method_exists( $transcript_consent_decision, 'is_allowed' ) && (bool) call_user_func( array( $transcript_consent_decision, 'is_allowed' ) );
			$transcript_consent_payload  = method_exists( $transcript_consent_decision, 'to_array' ) ? (array) call_user_func( array( $transcript_consent_decision, 'to_array' ) ) : array();

			$payload = array(
				'job_id'                      => $this->job_id,
				'flow_step_id'                => $this->flow_step_id,
				'step_id'                     => $pipeline_step_id,
				'data'                        => $this->dataPackets,
				'engine'                      => $this->engine,
				'engine_data'                 => $this->engine->all(),
				'user_id'                     => $owner_id,
				// Pipeline executions have no human caller — the agent is acting on a
				// scheduled job, not on behalf of a person. Per-user OAuth resolution
				// reads this field; setting it to 0 prevents accidentally picking up
				// per-user credentials of the flow owner.
				'calling_user_id'             => 0,
				'agent_id'                    => $agent_id,
				'agent_slug'                  => $agent_slug,
				'agent_modes'                 => $execution_modes,
				'pipeline_id'                 => $job_snapshot['pipeline_id'] ?? null,
				'flow_id'                     => $job_snapshot['flow_id'] ?? null,
				'persist_transcript'          => $persist_transcript,
				'transcript_consent_decision' => $transcript_consent_payload,
			);

			$navigator             = new \DataMachine\Engine\StepNavigator();
			$previous_flow_step_id = $navigator->get_previous_flow_step_id( $this->flow_step_id, $payload );

			$previous_step_config = $previous_flow_step_id ? $this->engine->getFlowStepConfig( $previous_flow_step_id ) : null;

			$next_flow_step_id = $navigator->get_next_flow_step_id( $this->flow_step_id, $payload );
			$next_step_config  = $next_flow_step_id ? $this->engine->getFlowStepConfig( $next_flow_step_id ) : null;

			// Collect required handler slugs from adjacent steps for completion tracking.
			$required_handler_slugs = FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi( $previous_step_config, $next_step_config );

			$engine_data = $this->engine->all();

			$completion_assertions = self::mergeCompletionAssertions(
				is_array( $pipeline_step_config['completion_assertions'] ?? null ) ? $pipeline_step_config['completion_assertions'] : array(),
				is_array( $this->flow_step_config['completion_assertions'] ?? null ) ? $this->flow_step_config['completion_assertions'] : array()
			);
			if ( ! empty( $completion_assertions ) ) {
				$payload['completion_assertions'] = $completion_assertions;
			}

			$tool_runtime_rules = self::mergeListConfigField(
				is_array( $pipeline_step_config['tool_runtime_rules'] ?? null ) ? $pipeline_step_config['tool_runtime_rules'] : array(),
				is_array( $this->flow_step_config['tool_runtime_rules'] ?? null ) ? $this->flow_step_config['tool_runtime_rules'] : array()
			);
			if ( ! empty( $tool_runtime_rules ) ) {
				$payload['tool_runtime_rules'] = $tool_runtime_rules;
			}

			$tool_recorders = self::mergeListConfigField(
				is_array( $pipeline_step_config['tool_recorders'] ?? null ) ? $pipeline_step_config['tool_recorders'] : array(),
				is_array( $this->flow_step_config['tool_recorders'] ?? null ) ? $this->flow_step_config['tool_recorders'] : array()
			);
			if ( ! empty( $tool_recorders ) ) {
				$payload['tool_recorders'] = $tool_recorders;
			}

			// Tool categories can be specified at the pipeline step level or pipeline level.
			// This allows pipelines to declare which ability categories are relevant,
			// reducing tool bloat by excluding irrelevant tools from the AI context.
			$tool_categories = $pipeline_step_config['tool_categories']
				?? $this->engine->get( 'pipeline_tool_categories' )
				?? array();

			$resolver                 = new ToolPolicyResolver();
			$tool_resolution_args     = array_merge(
				array(
					'modes'                => $execution_modes,
					'agent_id'             => $agent_id,
					'agent_slug'           => $agent_slug,
					'previous_step_config' => $previous_step_config,
					'next_step_config'     => $next_step_config,
					'pipeline_step_id'     => $pipeline_step_id,
					'engine_data'          => $engine_data,
					'ability_tools'        => is_array( $job_snapshot['ability_tools'] ?? null ) ? $job_snapshot['ability_tools'] : array(),
					'host_tool_policy'     => is_array( $job_snapshot['host_tool_policy'] ?? null ) ? $job_snapshot['host_tool_policy'] : array(),
					'categories'           => $tool_categories,
				),
				PipelineToolPolicyArgs::fromConfigs( $this->flow_step_config, $pipeline_step_config )
			);
			$tool_resolution          = $resolver->resolveWithEvidence(
				$tool_resolution_args,
				self::completionAssertionRequiredToolNames( $completion_assertions ),
				FlowStepConfig::getEnabledTools( $this->flow_step_config )
			);
			$available_tools          = $tool_resolution['tools'];
			$tool_resolution_evidence = $tool_resolution['evidence'];
			self::persistToolResolutionEvidence( $this->job_id, $tool_resolution_evidence );

			// Required adjacent handler tools are flow plumbing, not optional research
			// tools. If a publish/upsert handler required by the flow shape cannot be
			// exposed to the model, fail before the model call instead of silently
			// narrowing completion tracking to the visible subset.
			if ( ! empty( $required_handler_slugs ) ) {
				$missing_handler_slugs = FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools );
				if ( ! empty( $missing_handler_slugs ) ) {
					do_action(
						'datamachine_fail_job',
						$this->job_id,
						'required_handler_tool_unavailable',
						array(
							'flow_step_id'                 => $this->flow_step_id,
							'pipeline_step_id'             => $pipeline_step_id,
							'required_handler_slugs'       => $required_handler_slugs,
							'missing_required_handler_slugs' => $missing_handler_slugs,
							'available_handler_tool_slugs' => FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools ),
							'tool_resolution_evidence'     => $tool_resolution_evidence,
							'error_message'                => 'AI step requires adjacent handler tools that are not available to the model.',
						)
					);

					return array();
				}

				$ai_tool_handler_slugs = FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools );

				if ( ! empty( $ai_tool_handler_slugs ) ) {
					$payload['configured_handler_slugs'] = $ai_tool_handler_slugs;
				}
			}

			// Establish agent execution context before firing the conversation loop.
			//
			// Pipelines run inside the Action Scheduler queue where no WordPress user
			// is set — get_current_user_id() returns 0 and any ability invoked as a
			// tool call (wp_insert_post, wiki writes, taxonomy edits, etc.) either
			// falls through to a "first administrator" fallback or relies on the
			// blanket action_scheduler_run_queue bypass in PermissionHelper::can().
			//
			// When the job is owned by an agent, we resolve that agent's owner user
			// and run the loop as that user, mirroring how AgentAuthMiddleware does
			// this for REST bearer-token requests and ChatOrchestrator does it for
			// in-browser chat. Tools fired inside the loop then execute with the
			// correct identity, post_author resolves to the agent owner, and
			// per-agent capability ceilings are enforced in pipelines the same way
			// they are in REST.
			$previous_user_id = get_current_user_id();
			wp_set_current_user( $owner_id );
			PermissionHelper::set_agent_context( $agent_id, $owner_id );

			try {
				// Execute conversation loop via agents-api substrate.
				$loop_result = datamachine_run_conversation(
					$messages,
					$available_tools,
					$provider_name,
					$model_name,
					$execution_modes,
					$payload,
					$max_turns
				);
			} finally {
				PermissionHelper::clear_agent_context();
				if ( $previous_user_id !== $owner_id ) {
					wp_set_current_user( $previous_user_id );
				}
			}

			// Check for errors
			if ( isset( $loop_result['error'] ) ) {
				$request_metadata = is_array( $loop_result['request_metadata'] ?? null ) ? $loop_result['request_metadata'] : array();
				$failure_reason   = 'completion_required_tool_unavailable' === ( $loop_result['error_code'] ?? '' )
					? 'completion_required_tool_unavailable'
					: 'ai_processing_failed';
				// Record the transcript on the failure path too so operators
				// can `wp datamachine jobs transcript <id>` and see exactly
				// what the model received before the AI request died. This
				// mirrors the same merge pattern used for the success path.
				$transcript_session_id = $loop_result['transcript_session_id'] ?? '';
				if ( '' !== $transcript_session_id && $this->job_id > 0 ) {
					datamachine_merge_engine_data(
						$this->job_id,
						array( 'transcript_session_id' => $transcript_session_id )
					);
				}

				RunMetrics::recordStepResult(
					$this->job_id,
					$this->flow_step_id,
					array(
						'step_type'    => 'ai',
						'result'       => 'failed',
						'provider_id'  => $provider_name,
						'model_id'     => $model_name,
						'tool_ids'     => array_keys( $available_tools ),
						'packet_count' => 0,
						'reason'       => $failure_reason,
						'error_code'   => $loop_result['error_code'] ?? null,
					)
				);

				do_action(
					'datamachine_fail_job',
					$this->job_id,
					$failure_reason,
					array(
						'flow_step_id'                    => $this->flow_step_id,
						'ai_error'                        => $loop_result['error'],
						'error_code'                      => $loop_result['error_code'] ?? null,
						'unavailable_required_tool_names' => is_array( $loop_result['unavailable_required_tool_names'] ?? null ) ? $loop_result['unavailable_required_tool_names'] : array(),
						'tool_resolution_evidence'        => $tool_resolution_evidence,
						'ai_provider'                     => $provider_name,
						'request_metadata'                => $request_metadata,
						'transport_profile'               => is_array( $request_metadata['transport'] ?? null ) ? $request_metadata['transport'] : array(),
						'retry_after'                     => $loop_result['retry_after'] ?? null,
						'retry_after_seconds'             => $loop_result['retry_after_seconds'] ?? null,
						'headers'                         => is_array( $loop_result['headers'] ?? null ) ? $loop_result['headers'] : array(),
					)
				);
				return array();
			}

			// Store token usage in job engine_data via merge to avoid clobbering
			// data written by handler tools during the conversation loop (e.g.
			// event_id, event_url, post_id written via datamachine_merge_engine_data).
			$usage = $loop_result['usage'] ?? array();
			if ( ! empty( $usage ) && $this->job_id > 0 && ( $usage['total_tokens'] ?? 0 ) > 0 ) {
				$usage_engine_data = array( 'token_usage' => $usage );
				if ( isset( $loop_result['runtime_provenance'] ) && is_array( $loop_result['runtime_provenance'] ) ) {
					$usage_engine_data['runtime_provenance'] = $loop_result['runtime_provenance'];
				}
				datamachine_merge_engine_data( $this->job_id, $usage_engine_data );
			}

			// Store the transcript session ID when the AI loop persisted one.
			// Same merge pattern as token_usage so handler-tool-written keys
			// (event_id, post_id, etc.) are preserved.
			$transcript_session_id = $loop_result['transcript_session_id'] ?? '';
			if ( '' !== $transcript_session_id && $this->job_id > 0 ) {
				datamachine_merge_engine_data(
					$this->job_id,
					array( 'transcript_session_id' => $transcript_session_id )
				);
			}

			$loop_metadata = datamachine_conversation_metadata( $loop_result );

			if ( $this->job_id > 0 ) {
				$artifact_engine_data                             = datamachine_get_engine_data( $this->job_id );
				$artifact_engine_data['tool_execution_summary']   = self::summarizeToolExecutions( $loop_result );
				$artifact_engine_data['tool_resolution_evidence'] = $tool_resolution_evidence;
				if ( isset( $loop_result['runtime_provenance'] ) && is_array( $loop_result['runtime_provenance'] ) ) {
					$artifact_engine_data['runtime_provenance'] = $loop_result['runtime_provenance'];
				}

				$typed_artifacts = datamachine_normalize_typed_artifact_outputs( $loop_result );
				if ( ! empty( $typed_artifacts ) ) {
					$artifact_engine_data['outputs']                    = is_array( $artifact_engine_data['outputs'] ?? null ) ? $artifact_engine_data['outputs'] : array();
					$artifact_engine_data['outputs']['typed_artifacts'] = array_replace_recursive(
						is_array( $artifact_engine_data['outputs']['typed_artifacts'] ?? null ) ? $artifact_engine_data['outputs']['typed_artifacts'] : array(),
						$typed_artifacts
					);
				}

				foreach ( array( 'completion_assertions_required', 'completion_assertions_missing', 'completion_assertions_satisfied' ) as $assertion_key ) {
					if ( isset( $loop_metadata[ $assertion_key ] ) && array() !== $loop_metadata[ $assertion_key ] ) {
						$artifact_engine_data[ $assertion_key ] = $loop_metadata[ $assertion_key ];
					}
				}

				datamachine_set_engine_data( $this->job_id, $artifact_engine_data );
			}

			$missing_assertion_failure = self::missingCompletionAssertionsFailure( $loop_result, $loop_metadata );
			if ( null !== $missing_assertion_failure ) {
				RunMetrics::recordStepResult(
					$this->job_id,
					$this->flow_step_id,
					array(
						'step_type'         => 'ai',
						'result'            => 'failed',
						'provider_id'       => $provider_name,
						'model_id'          => $model_name,
						'tool_ids'          => array_keys( $available_tools ),
						'handler_slugs'     => $required_handler_slugs,
						'packet_count'      => 0,
						'diagnostic_reason' => 'ai_completion_assertions_missing',
					)
				);
				do_action(
					'datamachine_fail_job',
					$this->job_id,
					'completion_assertions_missing',
					array_merge(
						array( 'flow_step_id' => $this->flow_step_id ),
						$missing_assertion_failure
					)
				);
				return array();
			}

			// Process loop results into data packets
			$processed_packets = self::processLoopResults( $loop_result, $this->dataPackets, $payload, $available_tools );
			$diagnostic_reason = self::outputDiagnosticReason( $loop_result, $required_handler_slugs, $processed_packets );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array(
					'step_type'         => 'ai',
					'result'            => empty( $processed_packets ) ? 'no_content' : 'completed',
					'provider_id'       => $provider_name,
					'model_id'          => $model_name,
					'tool_ids'          => array_keys( $available_tools ),
					'handler_slugs'     => $required_handler_slugs,
					'packet_count'      => count( $processed_packets ),
					'diagnostic_reason' => $diagnostic_reason,
				)
			);

			return $processed_packets;
		} finally {
			if ( $ai_concurrency_lease instanceof PipelineAIConcurrencyLease ) {
				$ai_concurrency_lease->release();
			}
		}
	}

	/**
	 * Reschedule this AI step because provider/site concurrency is saturated.
	 *
	 * @param string $provider_name Provider slug.
	 * @param array  $lease_result  Limiter result.
	 */
	private function deferForAIConcurrency( string $provider_name, array $lease_result ): void {
		$existing_throttle = $this->engine instanceof \DataMachine\Core\EngineData
			? $this->engine->get( 'ai_concurrency_throttle' )
			: null;
		$existing_throttle = is_array( $existing_throttle ) ? $existing_throttle : array();

		$prior_attempts = 0;
		if ( (string) ( $existing_throttle['flow_step_id'] ?? '' ) === $this->flow_step_id ) {
			$prior_attempts = (int) ( $existing_throttle['attempts'] ?? 0 );
		}

		$max_defer_age = max(
			60,
			(int) apply_filters(
				'datamachine_ai_concurrency_max_defer_age',
				self::AI_CONCURRENCY_MAX_DEFER_AGE,
				$provider_name,
				$this->job_id
			)
		);

		$now             = time();
		$contention      = AIConcurrencyBackpressure::nextState( $existing_throttle, $this->flow_step_id, $now, $max_defer_age );
		$attempt         = (int) $contention['attempts'];
		$contention_data = array_merge(
			$contention,
			array(
				'reason'       => 'ai_concurrency_limit',
				'provider'     => $provider_name,
				'flow_step_id' => $this->flow_step_id,
				'limit'        => (int) ( $lease_result['limit'] ?? 0 ),
				'active'       => (int) ( $lease_result['active'] ?? 0 ),
			)
		);

		if ( 'stranded' === $contention['state'] ) {
			$contention_data['terminal_reason'] = 'ai_concurrency_stranded';
			$contention_data['next_retry_at']   = null;
			datamachine_merge_engine_data( $this->job_id, array( 'ai_concurrency_throttle' => $contention_data ) );
			( new Jobs() )->complete_job( $this->job_id, 'cancelled - ai_concurrency_stranded' );

			do_action(
				'datamachine_log',
				'warning',
				'Pipeline AI step exceeded maximum contention age; job requires operator review',
				array(
					'job_id'                => $this->job_id,
					'flow_step_id'          => $this->flow_step_id,
					'provider'              => $provider_name,
					'attempts'              => $attempt,
					'defer_age_seconds'     => (int) $contention['defer_age_seconds'],
					'max_defer_age_seconds' => $max_defer_age,
					'limit'                 => (int) ( $lease_result['limit'] ?? 0 ),
					'active'                => (int) ( $lease_result['active'] ?? 0 ),
				)
			);
			return;
		}

		// Exponential backoff between defers, capped, so a long-saturated queue
		// backs off instead of polling the limiter on the base delay forever.
		$base_delay    = max( 1, (int) ( $lease_result['delay'] ?? 10 ) );
		$delay_seconds = AIConcurrencyBackpressure::delaySeconds(
			$base_delay,
			$prior_attempts,
			self::AI_CONCURRENCY_MAX_DEFER_DELAY
		);
		$timestamp     = $now + $delay_seconds;
		$action_args   = array(
			'job_id'                => $this->job_id,
			'flow_step_id'          => $this->flow_step_id,
			'operation_generation'  => 0,
			'operation_claim_token' => '',
		);

		$job = ( new \DataMachine\Core\Database\Jobs\Jobs() )->get_job( $this->job_id );
		if ( 'direct' === (string) ( $job['flow_id'] ?? '' ) && (int) ( $job['operation_generation'] ?? 0 ) > 0 ) {
			$action_args['operation_generation']  = (int) $job['operation_generation'];
			$action_args['operation_claim_token'] = (string) ( $job['operation_claim_token'] ?? '' );
		}
		$source_generation = max( 0, (int) $this->engine->get( '_runtime_ai_resume_generation', 0 ) );
		$claim             = AIConcurrencyBackpressure::claimNextGeneration(
			$this->job_id,
			$this->flow_step_id,
			$source_generation,
			$now
		);
		if ( empty( $claim['success'] ) || empty( $claim['owned'] ) ) {
			( new Jobs() )->update_job_status( $this->job_id, 'pending' );
			return;
		}

		$resume_generation                   = (int) $claim['generation'];
		$action_args['ai_resume_generation'] = $resume_generation;
		unset( $contention_data['action_id'] );
		$contention_data['resume_generation'] = $resume_generation;

		$schedule_result = AIConcurrencyBackpressure::scheduleContinuation( $timestamp, $action_args );
		$action_id       = (int) $schedule_result['action_id'];

		if ( empty( $schedule_result['success'] ) || $action_id <= 0 ) {
			$contention_data['state']           = 'stranded';
			$contention_data['terminal_reason'] = 'ai_concurrency_defer_schedule_failed';
			$contention_data['next_retry_at']   = null;
			datamachine_merge_engine_data( $this->job_id, array( 'ai_concurrency_throttle' => $contention_data ) );
			( new Jobs() )->complete_job( $this->job_id, 'cancelled - ai_concurrency_defer_schedule_failed' );
			return;
		}
		AIConcurrencyBackpressure::recordScheduledAction(
			$this->job_id,
			$this->flow_step_id,
			$resume_generation,
			(string) $claim['token'],
			$action_id
		);

		( new Jobs() )->update_job_status( $this->job_id, 'pending' );

		datamachine_merge_engine_data(
			$this->job_id,
			array(
				'ai_concurrency_throttle' => array_merge(
					$contention_data,
					array(
						'next_retry_at'           => gmdate( 'c', $timestamp ),
						'rescheduled_for_seconds' => $delay_seconds,
						'action_id'               => $action_id,
					)
				),
			)
		);

		do_action(
			'datamachine_log',
			'warning',
			'Pipeline AI step deferred by concurrency limit',
			array(
				'job_id'                  => $this->job_id,
				'flow_step_id'            => $this->flow_step_id,
				'provider'                => $provider_name,
				'reason'                  => 'ai_concurrency_limit',
				'attempts'                => $attempt,
				'defer_age_seconds'       => (int) $contention['defer_age_seconds'],
				'max_defer_age_seconds'   => $max_defer_age,
				'limit'                   => (int) ( $lease_result['limit'] ?? 0 ),
				'active'                  => (int) ( $lease_result['active'] ?? 0 ),
				'rescheduled_for_seconds' => $delay_seconds,
				'action_id'               => $action_id,
			)
		);
	}

	/** Archive resolved contention and remove the active throttle marker. */
	private function resolveAIConcurrencyContention(): void {
		$result = \DataMachine\Core\EngineData::mutate(
			$this->job_id,
			function ( array $engine ): array {
				$throttle = is_array( $engine['ai_concurrency_throttle'] ?? null ) ? $engine['ai_concurrency_throttle'] : array();
				if ( empty( $throttle ) || (string) ( $throttle['flow_step_id'] ?? '' ) !== $this->flow_step_id ) {
					return $engine;
				}

				$history                          = is_array( $engine['ai_concurrency_history'] ?? null ) ? $engine['ai_concurrency_history'] : array();
				$history[]                        = AIConcurrencyBackpressure::resolvedState( $throttle, time() );
				$engine['ai_concurrency_history'] = array_slice( $history, -20 );
				unset( $engine['ai_concurrency_throttle'] );
				unset( $engine['ai_concurrency_resume_ownership'] );

				return $engine;
			},
			'ai_concurrency_resolved'
		);

		if ( empty( $result['success'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'AI concurrency contention resolved but history could not be persisted',
				array(
					'job_id'       => $this->job_id,
					'flow_step_id' => $this->flow_step_id,
				)
			);
		}
	}

	/**
	 * Project data packets before serializing them to AI.
	 *
	 * Kept as a compatibility wrapper for older tests/call sites. Canonical
	 * packets remain unchanged for runtime and storage use.
	 *
	 * @param array $data_packets Original data packets.
	 * @return array Projected copy safe for AI serialization.
	 */
	public static function sanitizeDataPacketsForAi( array $data_packets ): array {
		return DataPacketPromptProjector::project( $data_packets );
	}

	/**
	 * Add runtime-package caller input to the AI-visible packet list.
	 *
	 * Runtime-package workflows can start with caller-provided artifacts without a
	 * preceding fetch step. Those values live in engine_data for deterministic
	 * handlers, but AI prompt construction reads data packets. Expose the runtime
	 * input as a prompt-only packet so agents can consume upstream artifacts while
	 * leaving canonical pipeline packets unchanged.
	 *
	 * @param array $data_packets Canonical data packets.
	 * @return array Prompt-facing data packets.
	 */
	private function runtimeInputPacketsForPrompt( array $data_packets ): array {
		$runtime_input = array();
		foreach ( array( 'artifacts', 'concept_packet', 'design_packet', 'static_site_candidate', 'import_validation_result', 'visual_parity_artifact', 'finding_packet_set' ) as $key ) {
			if ( array_key_exists( $key, $this->engine_data ) ) {
				$runtime_input[ $key ] = $this->engine_data[ $key ];
			}
		}

		if ( empty( $runtime_input ) ) {
			return $data_packets;
		}

		$packet = new DataPacket(
			array(
				'runtime_input' => $runtime_input,
			),
			array(
				'source_type'  => 'runtime_package_input',
				'source_label' => 'Runtime package input',
			),
			'runtime_input'
		);

		return $packet->addTo( $data_packets );
	}

	/**
	 * Merge pipeline-level and flow-level generic completion assertions.
	 *
	 * @param array $pipeline_assertions Pipeline step assertions.
	 * @param array $flow_assertions     Flow step assertions.
	 * @return array<string, mixed> Merged assertions.
	 */
	private static function mergeCompletionAssertions( array $pipeline_assertions, array $flow_assertions ): array {
		$merged = array();
		foreach ( array( 'required_engine_data_keys', 'required_tool_names', 'required_output_packet_types', 'required_artifact_outputs' ) as $key ) {
			$values = array_merge(
				self::normalizeCompletionAssertionValues( $pipeline_assertions[ $key ] ?? array() ),
				self::normalizeCompletionAssertionValues( $flow_assertions[ $key ] ?? array() )
			);
			if ( ! empty( $values ) ) {
				$merged[ $key ] = 'required_artifact_outputs' === $key ? array_values( $values ) : array_values( array_unique( $values ) );
			}
		}

		$minimum_successful_tool_counts = array_merge(
			self::normalizeCompletionAssertionCountMap( $pipeline_assertions['minimum_successful_tool_counts'] ?? array() ),
			self::normalizeCompletionAssertionCountMap( $flow_assertions['minimum_successful_tool_counts'] ?? array() )
		);
		if ( ! empty( $minimum_successful_tool_counts ) ) {
			$merged['minimum_successful_tool_counts'] = $minimum_successful_tool_counts;
		}

		$complete_when_any = array_merge(
			is_array( $pipeline_assertions['complete_when_any'] ?? null ) ? $pipeline_assertions['complete_when_any'] : array(),
			is_array( $flow_assertions['complete_when_any'] ?? null ) ? $flow_assertions['complete_when_any'] : array()
		);
		if ( ! empty( $complete_when_any ) ) {
			$merged['complete_when_any'] = array_values( $complete_when_any );
		}

		return $merged;
	}

	/**
	 * Extract required tool names from merged completion assertions.
	 *
	 * @param array $completion_assertions Merged assertion config.
	 * @return array<int,string>
	 */
	private static function completionAssertionRequiredToolNames( array $completion_assertions ): array {
		return self::normalizeCompletionAssertionList( $completion_assertions['required_tool_names'] ?? array() );
	}

	/**
	 * Persist required-tool resolution evidence where job artifacts can surface it.
	 *
	 * @param int   $job_id   Job ID.
	 * @param array $evidence Tool resolution evidence.
	 * @return void
	 */
	private static function persistToolResolutionEvidence( int $job_id, array $evidence ): void {
		if ( $job_id <= 0 || empty( $evidence ) || ! function_exists( 'datamachine_merge_engine_data' ) ) {
			return;
		}

		datamachine_merge_engine_data(
			$job_id,
			array(
				'tool_resolution_evidence' => $evidence,
			)
		);
	}

	/**
	 * @param mixed $value Raw assertion list.
	 * @return array<int, string>
	 */
	private static function normalizeCompletionAssertionList( $value ): array {
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

		return $items;
	}

	/**
	 * Normalize scalar assertion values while preserving structured assertion entries.
	 *
	 * @param mixed $value Raw assertion values.
	 * @return array<int, mixed>
	 */
	private static function normalizeCompletionAssertionValues( $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$items[] = $item;
				continue;
			}

			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * @param mixed $value Raw assertion count map.
	 * @return array<string, int>
	 */
	private static function normalizeCompletionAssertionCountMap( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
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
	 * Resolve the agent execution modes for this AI step.
	 *
	 * @param array $pipeline_step_config Pipeline step config.
	 * @param array $flow_step_config Flow step config.
	 * @return array<int,string> Agent mode slugs.
	 */
	private static function resolveExecutionModes( array $pipeline_step_config, array $flow_step_config ): array {
		foreach ( array( $flow_step_config['agent_modes'] ?? null, $pipeline_step_config['agent_modes'] ?? null ) as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate ) ) {
				return ToolPolicyResolver::normalizeModes( $candidate );
			}
		}

		return array( ToolPolicyResolver::MODE_PIPELINE );
	}

	/**
	 * Resolve the first configured model for an active mode set.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $modes    Active mode slugs.
	 * @return array{provider:string,model:string}
	 */
	private static function resolveModelForExecutionModes( int $agent_id, array $modes, array $job_snapshot = array() ): array {
		$job_model = self::resolveModelFromJobSnapshot( $job_snapshot, $modes );
		if ( '' !== $job_model['provider'] && '' !== $job_model['model'] ) {
			return $job_model;
		}

		return PluginSettings::resolveModelForAgentModes( $agent_id, $modes, ToolPolicyResolver::MODE_PIPELINE );
	}

	/**
	 * Resolve run-scoped model config from the job snapshot.
	 *
	 * @param array $job_snapshot Portable job snapshot from engine data.
	 * @param array $modes Active mode slugs.
	 * @return array{provider:string,model:string}
	 */
	private static function resolveModelFromJobSnapshot( array $job_snapshot, array $modes ): array {
		$mode_models = is_array( $job_snapshot['mode_models'] ?? null ) ? $job_snapshot['mode_models'] : array();
		$modes       = array_values( array_unique( array_map( 'sanitize_key', $modes ) ) );

		foreach ( $modes as $mode ) {
			$mode_config = is_array( $mode_models[ $mode ] ?? null ) ? $mode_models[ $mode ] : array();
			$provider    = sanitize_text_field( (string) ( $mode_config['provider'] ?? '' ) );
			$model       = sanitize_text_field( (string) ( $mode_config['model'] ?? '' ) );
			if ( '' !== $provider && '' !== $model ) {
				return array(
					'provider' => $provider,
					'model'    => $model,
				);
			}
		}

		$pipeline_config = is_array( $mode_models[ ToolPolicyResolver::MODE_PIPELINE ] ?? null ) ? $mode_models[ ToolPolicyResolver::MODE_PIPELINE ] : array();
		$provider        = sanitize_text_field( (string) ( $pipeline_config['provider'] ?? '' ) );
		$model           = sanitize_text_field( (string) ( $pipeline_config['model'] ?? '' ) );
		if ( '' !== $provider && '' !== $model ) {
			return array(
				'provider' => $provider,
				'model'    => $model,
			);
		}

		$provider = sanitize_text_field( (string) ( $job_snapshot['default_provider'] ?? '' ) );
		$model    = sanitize_text_field( (string) ( $job_snapshot['default_model'] ?? '' ) );
		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Resolve agent ID from a portable job snapshot.
	 */
	private function resolveAgentIdFromJobSnapshot( array $job_snapshot ): int {
		if ( empty( $job_snapshot['agent_slug'] ) && empty( $job_snapshot['agent_id'] ) ) {
			return 0;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_identity( $job_snapshot )->agent_id;
		} catch ( \InvalidArgumentException $e ) {
			return (int) ( $job_snapshot['agent_id'] ?? 0 );
		}
	}

	/**
	 * Resolve the agent identity required for queued AI execution.
	 *
	 * @param array $job_snapshot Portable job snapshot from engine data.
	 * @return AgentIdentity|null Resolved identity, or null when the job is agent-less/invalid.
	 */
	private function resolveAgentIdentityForExecution( array $job_snapshot ): ?AgentIdentity {
		if ( empty( $job_snapshot['agent_slug'] ) && empty( $job_snapshot['agent_id'] ) ) {
			return null;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_identity( $job_snapshot );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Fail queued AI execution when no real agent owner can be resolved.
	 *
	 * @param array  $job_snapshot     Portable job snapshot from engine data.
	 * @param string $pipeline_step_id Pipeline step currently executing.
	 */
	private function failMissingAgentContext( array $job_snapshot, string $pipeline_step_id ): void {
		RunMetrics::recordStepResult(
			$this->job_id,
			$this->flow_step_id,
			array(
				'step_type'    => 'ai',
				'result'       => 'failed',
				'packet_count' => 0,
				'reason'       => 'ai_agent_context_required',
			)
		);

		do_action(
			'datamachine_fail_job',
			$this->job_id,
			'ai_agent_context_required',
			array(
				'job_id'           => $this->job_id,
				'flow_id'          => (int) ( $job_snapshot['flow_id'] ?? 0 ),
				'pipeline_id'      => (int) ( $job_snapshot['pipeline_id'] ?? 0 ),
				'flow_step_id'     => $this->flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'error_message'    => 'Queued AI execution requires a valid agent_id or agent_slug with an owner user. Reassign unowned flows/pipelines before running this step.',
				'solution'         => 'Inspect with wp datamachine pipelines orphans and wp datamachine flows orphans, then reassign with --where-null.',
			)
		);
	}

	/**
	 * Merge pipeline-level and flow-level list config fields.
	 *
	 * @param array $pipeline_values Pipeline step values.
	 * @param array $flow_values     Flow step values.
	 * @return array<int,mixed> Merged list values.
	 */
	private static function mergeListConfigField( array $pipeline_values, array $flow_values ): array {
		return array_values( array_merge( $pipeline_values, $flow_values ) );
	}

	/**
	 * Build a bounded, non-secret summary of tool calls for job artifacts.
	 *
	 * @param array $loop_result Conversation loop result.
	 * @return array<int, array<string, mixed>>
	 */
	private static function summarizeToolExecutions( array $loop_result ): array {
		$results = is_array( $loop_result['tool_execution_results'] ?? null ) ? $loop_result['tool_execution_results'] : array();
		return function_exists( 'DataMachine\Engine\AI\datamachine_summarize_tool_execution_results' )
			? \DataMachine\Engine\AI\datamachine_summarize_tool_execution_results( $results )
			: array();
	}

	/**
	 * Process AI conversation loop results into data packets.
	 *
	 * Only emits actionable packets (handler completions, tool results) that
	 * downstream steps depend on. Input DataPackets from previous steps are
	 * NOT carried forward — they already served their purpose as AI conversation
	 * input, and including them causes the batch scheduler to fan them out as
	 * ghost child jobs that fail at the next step.
	 *
	 * @param array $loop_result Results from AIConversationLoop
	 * @param array $inputDataPackets Input data packets (used for metadata extraction only)
	 * @param array $payload Step payload
	 * @param array $available_tools Tools available during conversation
	 * @return array Output data packets (tool results only, not input packets)
	 */
	private static function processLoopResults( array $loop_result, array $inputDataPackets, array $payload, array $available_tools ): array {
		if ( ! isset( $payload['flow_step_id'] ) || empty( $payload['flow_step_id'] ) ) {
			throw new \InvalidArgumentException( 'Flow step ID is required in AI step payload' );
		}

		$flow_step_id           = $payload['flow_step_id'];
		$messages               = $loop_result['messages'] ?? array();
		$tool_execution_results = $loop_result['tool_execution_results'] ?? array();
		$loop_metadata          = datamachine_conversation_metadata( $loop_result );
		$assertions_satisfied   = ! empty( $loop_metadata['completion_assertions_satisfied'] ) && empty( $loop_metadata['completion_assertions_missing'] );

		// Start with an empty output array — input packets are NOT carried forward.
		$outputPackets = array();

		// Count conversation turns for metadata (not emitted as packets).
		$turn_count        = 0;
		$handler_completed = false;
		$final_ai_content  = '';

		foreach ( $messages as $message ) {
			if ( 'assistant' === ( $message['role'] ?? '' ) ) {
				++$turn_count;
				$content = $message['content'] ?? '';
				if ( ! empty( $content ) ) {
					$final_ai_content = $content;
				}
			}
		}

		// Process tool execution results into output packets.
		// Only handler completions and tool results are emitted — these are
		// consumed by downstream steps (PublishStep, UpsertStep) via ToolResultFinder.
		// Input DataPackets are NOT included — they cause ghost child jobs.
		$input_source_type = $inputDataPackets[0]['metadata']['source_type'] ?? 'unknown';

		foreach ( $tool_execution_results as $tool_execution_result ) {
			$tool_name         = $tool_execution_result['tool_name'] ?? '';
			$tool_result       = $tool_execution_result['result'] ?? array();
			$tool_parameters   = $tool_execution_result['parameters'] ?? array();
			$is_handler_tool   = $tool_execution_result['is_handler_tool'] ?? false;
			$result_turn_count = $tool_execution_result['turn_count'] ?? $turn_count;

			if ( empty( $tool_name ) ) {
				continue;
			}

			$tool_def = $available_tools[ $tool_name ] ?? null;

			$projected_tool_result_data = ToolResultFinder::projectEnvelopeData( $tool_result );

			if ( $is_handler_tool && ( $tool_result['success'] ?? false ) ) {
				// Handler tool succeeded - mark completion
				$clean_tool_parameters = $tool_parameters;
				$handler_config        = $tool_def['handler_config'] ?? array();

				$handler_key = $tool_def['handler'] ?? $tool_name;
				if ( isset( $clean_tool_parameters[ $handler_key ] ) ) {
					unset( $clean_tool_parameters[ $handler_key ] );
				}

				$packet        = new DataPacket(
					array(
						'title' => 'Handler Tool Executed: ' . $tool_name,
						'body'  => 'Tool executed successfully by AI agent in ' . $result_turn_count . ' conversation turns',
					),
					array(
						'tool_name'              => $tool_name,
						'handler_tool'           => $tool_def['handler'] ?? null,
						'tool_parameters'        => $clean_tool_parameters,
						'handler_config'         => $handler_config,
						'source_type'            => $input_source_type,
						'flow_step_id'           => $flow_step_id,
						'conversation_turn'      => $result_turn_count,
						'tool_result_envelope'   => $tool_result,
						'tool_result_data'       => $projected_tool_result_data,
						'step_execution_success' => true,
					),
					'ai_handler_complete'
				);
				$outputPackets = $packet->addTo( $outputPackets );

				$handler_completed = true;
			} else {
				// Non-handler tool or failed tool - add tool result data packet
				$success_message = ConversationManager::generateSuccessMessage( $tool_name, $tool_result, $tool_parameters );
				$tool_success    = $tool_result['success'] ?? false;

				$packet        = new DataPacket(
					array(
						'title' => ucwords( str_replace( '_', ' ', $tool_name ) ) . ' Result',
						'body'  => $success_message,
					),
					array(
						'tool_name'              => $tool_name,
						'handler_tool'           => $tool_def['handler'] ?? null,
						'tool_parameters'        => $tool_parameters,
						'tool_success'           => $tool_success,
						'tool_failure_non_fatal' => false === (bool) $tool_success && $assertions_satisfied,
						'tool_result_envelope'   => $tool_result,
						'tool_result_data'       => $projected_tool_result_data,
						'source_type'            => $input_source_type,
					),
					'tool_result'
				);
				$outputPackets = $packet->addTo( $outputPackets );
			}
		}

		// If no handler completed and no tool results were added, emit a single
		// summary packet so the step doesn't appear to have produced nothing.
		if ( ! $handler_completed && count( $outputPackets ) === 0 && ! empty( $final_ai_content ) ) {
			$content_lines = explode( "\n", trim( $final_ai_content ), 2 );
			$ai_title      = ( strlen( $content_lines[0] ) <= 100 ) ? $content_lines[0] : 'AI Response';

			$packet        = new DataPacket(
				array(
					'title' => $ai_title,
					'body'  => $final_ai_content,
				),
				array(
					'source_type'            => 'ai_response',
					'flow_step_id'           => $flow_step_id,
					'conversation_turn'      => $turn_count,
					'step_execution_success' => false,
					'failure_reason'         => 'ai_response_without_tool_result',
				),
				'ai_response'
			);
			$outputPackets = $packet->addTo( $outputPackets );
		}

		$loop_metadata = datamachine_conversation_metadata( $loop_result );
		if ( count( $outputPackets ) === 0 && true === ( $loop_metadata['completion_assertions_complete'] ?? false ) ) {
			$packet        = new DataPacket(
				array(
					'title' => 'AI Completion Assertions Satisfied',
					'body'  => 'The AI step satisfied its configured completion assertions.',
				),
				array(
					'source_type'                     => 'ai_completion_assertions',
					'flow_step_id'                    => $flow_step_id,
					'conversation_turn'               => $turn_count,
					'completion_assertions_satisfied' => is_array( $loop_metadata['completion_assertions_satisfied'] ?? null ) ? $loop_metadata['completion_assertions_satisfied'] : array(),
					'completion_assertions_missing'   => is_array( $loop_metadata['completion_assertions_missing'] ?? null ) ? $loop_metadata['completion_assertions_missing'] : array(),
					'step_execution_success'          => true,
				),
				'ai_completion_assertions'
			);
			$outputPackets = $packet->addTo( $outputPackets );
		}

		return $outputPackets;
	}

	/**
	 * Explain why AI step output cannot satisfy downstream packet requirements.
	 *
	 * @param array             $loop_result             Conversation loop result.
	 * @param array<int,string> $required_handler_slugs  Handler slugs required by downstream steps.
	 * @param array             $processed_packets       Data packets emitted by the AI step.
	 * @return string
	 */
	private static function outputDiagnosticReason( array $loop_result, array $required_handler_slugs, array $processed_packets ): string {
		if ( ! empty( $required_handler_slugs ) && ! self::hasHandlerCompletePacket( $processed_packets ) ) {
			return self::emptyOutputDiagnosticReason( $loop_result, $required_handler_slugs );
		}

		if ( empty( $processed_packets ) ) {
			return self::emptyOutputDiagnosticReason( $loop_result, $required_handler_slugs );
		}

		return '';
	}

	/**
	 * Check whether processed output includes a handler completion packet.
	 *
	 * @param array $processed_packets Data packets emitted by the AI step.
	 * @return bool
	 */
	private static function hasHandlerCompletePacket( array $processed_packets ): bool {
		foreach ( $processed_packets as $packet ) {
			if ( 'ai_handler_complete' === ( $packet['type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explain why an AI step output cannot satisfy downstream handler requirements.
	 *
	 * @param array             $loop_result             Conversation loop result.
	 * @param array<int,string> $required_handler_slugs  Handler slugs required by downstream steps.
	 * @return string
	 */
	private static function emptyOutputDiagnosticReason( array $loop_result, array $required_handler_slugs ): string {
		$tool_results      = is_array( $loop_result['tool_execution_results'] ?? null ) ? $loop_result['tool_execution_results'] : array();
		$messages          = is_array( $loop_result['messages'] ?? null ) ? $loop_result['messages'] : array();
		$handler_attempted = false;
		$handler_succeeded = false;
		$assistant_content = '';

		foreach ( $tool_results as $tool_result_data ) {
			if ( empty( $tool_result_data['is_handler_tool'] ) ) {
				continue;
			}

			$handler_attempted = true;
			$tool_result       = is_array( $tool_result_data['result'] ?? null ) ? $tool_result_data['result'] : array();
			if ( ! empty( $tool_result['success'] ) ) {
				$handler_succeeded = true;
			}
		}

		foreach ( $messages as $message ) {
			if ( 'assistant' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$content = trim( (string) ( $message['content'] ?? '' ) );
			if ( '' !== $content ) {
				$assistant_content = $content;
			}
		}

		if ( ! empty( $required_handler_slugs ) && ! $handler_attempted ) {
			return 'ai_required_handler_not_called';
		}

		if ( $handler_attempted && ! $handler_succeeded ) {
			return 'ai_handler_tool_failed';
		}

		if ( empty( $tool_results ) && '' === $assistant_content ) {
			return 'ai_empty_response';
		}

		return 'ai_empty_packet';
	}

	/**
	 * Build a structured failure payload when an AI loop exhausts without
	 * satisfying configured completion assertions.
	 *
	 * @param array $loop_result Conversation loop result.
	 * @return array<string,mixed>|null Failure payload, or null when assertions are complete/not configured.
	 */
	private static function missingCompletionAssertionsFailure( array $loop_result, array $loop_metadata ): ?array {
		unset( $loop_result );
		$missing = is_array( $loop_metadata['completion_assertions_missing'] ?? null ) ? $loop_metadata['completion_assertions_missing'] : array();
		if ( empty( $missing ) || true === ( $loop_metadata['completion_assertions_complete'] ?? false ) ) {
			return null;
		}

		return array(
			'reason'                          => 'completion_assertions_missing',
			'completion_assertions_missing'   => $missing,
			'completion_assertions_satisfied' => is_array( $loop_metadata['completion_assertions_satisfied'] ?? null ) ? $loop_metadata['completion_assertions_satisfied'] : array(),
			'completion_assertions_required'  => is_array( $loop_metadata['completion_assertions_required'] ?? null ) ? $loop_metadata['completion_assertions_required'] : array(),
		);
	}
}
