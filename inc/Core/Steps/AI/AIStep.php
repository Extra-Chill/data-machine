<?php

namespace DataMachine\Core\Steps\AI;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\DataPacket;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\AI\ToolPolicy\PipelineToolPolicyArgs;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\QueueableTrait;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\PipelineTranscriptPolicy;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

use function DataMachine\Engine\AI\datamachine_run_conversation;

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
		$agent_id             = (int) ( $job_snapshot['agent_id'] ?? 0 );

		// Model/provider resolved exclusively via mode system (agent → site → network).
		// Pipeline-level model/provider fields are ignored — mode_models is the authority.
		$mode_model    = PluginSettings::resolveModelForAgentMode( $agent_id, 'pipeline' );
		$provider_name = $mode_model['provider'];
		if ( empty( $provider_name ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'ai_provider_missing',
				array(
					'flow_step_id'     => $this->flow_step_id,
					'pipeline_step_id' => $pipeline_step_id,
					'error_message'    => 'AI step requires provider configuration. Set a default provider in Data Machine settings or configure mode_models.',
					'solution'         => 'Set default_provider in Data Machine settings or configure mode_models for the pipeline mode',
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

		// AIStep reads a single prompt slot — `prompt_queue` — under one
		// of three access modes (#1291). Pre-collapse this branched on
		// `queue_enabled` plus a `user_message` fallback; post-collapse
		// the mode picks the access pattern and the queue head is the
		// only source of per-flow user-role content. Migration #1291
		// rewrites legacy `user_message` into a 1-entry static queue so
		// no runtime fallback shim is needed here.
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

				return $this->dataPackets;
			}

			// Static mode + empty queue: no flow-level user message,
			// but the pipeline system_prompt and any data packets still
			// drive the conversation. Fall through with $user_message=''.
		}

		// Vision image from engine data (single source of truth)
		$file_path    = null;
		$mime_type    = null;
		$engine_image = $this->engine->get( 'image_file_path' );
		if ( $engine_image && file_exists( $engine_image ) ) {
			$file_path = $engine_image;
			$file_info = wp_check_filetype( $engine_image );
			$mime_type = is_string( $file_info['type'] ) ? $file_info['type'] : '';
		}

		$messages = array();

		if ( ! empty( $this->dataPackets ) ) {
			$data_packet_content = wp_json_encode( array( 'data_packets' => self::sanitizeDataPacketsForAi( $this->dataPackets ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
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

		$pipeline_step_id = $this->flow_step_config['pipeline_step_id'];

		$pipeline_step_config = $this->engine->getPipelineStepConfig( $pipeline_step_id );

		$max_turns = PluginSettings::get( 'max_turns', PluginSettings::DEFAULT_MAX_TURNS );

		// Resolve user_id and agent_id from engine snapshot (set by RunFlowAbility).
		$job_snapshot = $this->engine->get( 'job' );
		$agent_id     = (int) ( $job_snapshot['agent_id'] ?? 0 );
		$user_id      = (int) ( $job_snapshot['user_id'] ?? 0 );

		// Resolve transcript persistence policy once per AI step invocation.
		// Resolution order: flow > pipeline > site option (default false).
		// The boolean is threaded through $payload so the loop doesn't need
		// to repeat the lookup every turn.
		$persist_transcript = PipelineTranscriptPolicy::shouldPersist( $this->engine );

		$payload = array(
			'job_id'             => $this->job_id,
			'flow_step_id'       => $this->flow_step_id,
			'step_id'            => $pipeline_step_id,
			'data'               => $this->dataPackets,
			'engine'             => $this->engine,
			'user_id'            => $user_id,
			'agent_id'           => $agent_id,
			'pipeline_id'        => $job_snapshot['pipeline_id'] ?? null,
			'flow_id'            => $job_snapshot['flow_id'] ?? null,
			'persist_transcript' => $persist_transcript,
		);

		$navigator             = new \DataMachine\Engine\StepNavigator();
		$previous_flow_step_id = $navigator->get_previous_flow_step_id( $this->flow_step_id, $payload );

		$previous_step_config = $previous_flow_step_id ? $this->engine->getFlowStepConfig( $previous_flow_step_id ) : null;

		$next_flow_step_id = $navigator->get_next_flow_step_id( $this->flow_step_id, $payload );
		$next_step_config  = $next_flow_step_id ? $this->engine->getFlowStepConfig( $next_flow_step_id ) : null;

		// Collect required handler slugs from adjacent steps for completion tracking.
		$required_handler_slugs = FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi( $previous_step_config, $next_step_config );

		$engine_data = $this->engine->all();

		// Tool categories can be specified at the pipeline step level or pipeline level.
		// This allows pipelines to declare which ability categories are relevant,
		// reducing tool bloat by excluding irrelevant tools from the AI context.
		$tool_categories = $pipeline_step_config['tool_categories']
			?? $this->engine->get( 'pipeline_tool_categories' )
			?? array();

		$resolver        = new ToolPolicyResolver();
		$available_tools = $resolver->resolve(
			array_merge(
				array(
					'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
					'agent_id'             => $agent_id,
					'previous_step_config' => $previous_step_config,
					'next_step_config'     => $next_step_config,
					'pipeline_step_id'     => $pipeline_step_id,
					'engine_data'          => $engine_data,
					'categories'           => $tool_categories,
				),
				PipelineToolPolicyArgs::fromConfigs( $this->flow_step_config, $pipeline_step_config )
			)
		);

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
						'flow_step_id'                   => $this->flow_step_id,
						'pipeline_step_id'               => $pipeline_step_id,
						'required_handler_slugs'         => $required_handler_slugs,
						'missing_required_handler_slugs' => $missing_handler_slugs,
						'available_handler_tool_slugs'   => FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools ),
						'error_message'                  => 'AI step requires adjacent handler tools that are not available to the model.',
					)
				);

				return array();
			}

			$ai_tool_handler_slugs = FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools );

			if ( ! empty( $ai_tool_handler_slugs ) ) {
				$payload['configured_handler_slugs'] = $ai_tool_handler_slugs;
			}
		}

		// Model/provider resolved exclusively via mode system — pipeline config is ignored.
		$mode_model    = PluginSettings::resolveModelForAgentMode( $agent_id, 'pipeline' );
		$provider_name = $mode_model['provider'];
		$model_name    = $mode_model['model'];

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
		$owner_id = 0;
		if ( $agent_id > 0 ) {
			$agents_repo  = new Agents();
			$agent_record = $agents_repo->get_agent( $agent_id );
			if ( $agent_record ) {
				$owner_id = (int) ( $agent_record['owner_id'] ?? 0 );
			}
		}
		if ( $owner_id <= 0 && $user_id > 0 ) {
			// Legacy / agent-less flows: fall back to the flow's user_id.
			$owner_id = $user_id;
		}

		$previous_user_id = get_current_user_id();
		$context_set      = false;
		if ( $owner_id > 0 ) {
			wp_set_current_user( $owner_id );
			if ( $agent_id > 0 ) {
				PermissionHelper::set_agent_context( $agent_id, $owner_id );
				$context_set = true;
			}
		}

		try {
			// Execute conversation loop via agents-api substrate.
			$loop_result = datamachine_run_conversation(
				$messages,
				$available_tools,
				$provider_name,
				$model_name,
				ToolPolicyResolver::MODE_PIPELINE,
				$payload,
				$max_turns
			);
		} finally {
			if ( $context_set ) {
				PermissionHelper::clear_agent_context();
			}
			if ( $owner_id > 0 && $previous_user_id !== $owner_id ) {
				wp_set_current_user( $previous_user_id );
			}
		}

		// Check for errors
		if ( isset( $loop_result['error'] ) ) {
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

			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'ai_processing_failed',
				array(
					'flow_step_id' => $this->flow_step_id,
					'ai_error'     => $loop_result['error'],
					'ai_provider'  => $provider_name,
				)
			);
			return array();
		}

		// Store token usage in job engine_data via merge to avoid clobbering
		// data written by handler tools during the conversation loop (e.g.
		// event_id, event_url, post_id written via datamachine_merge_engine_data).
		$usage = $loop_result['usage'] ?? array();
		if ( ! empty( $usage ) && $this->job_id > 0 && ( $usage['total_tokens'] ?? 0 ) > 0 ) {
			datamachine_merge_engine_data( $this->job_id, array( 'token_usage' => $usage ) );
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

		// Process loop results into data packets
		return self::processLoopResults( $loop_result, $this->dataPackets, $payload, $available_tools );
	}

	/**
	 * Remove local-only file paths before serializing data packets to AI.
	 *
	 * Fetch handlers may include file_info.file_path so downstream runtime steps
	 * can attach images or access files. That internal path should not be exposed
	 * in the AI-visible JSON payload because models can copy it into generated
	 * content. The original packets remain unchanged for runtime use.
	 *
	 * @param array $data_packets Original data packets.
	 * @return array Sanitized copy safe for AI serialization.
	 */
	public static function sanitizeDataPacketsForAi( array $data_packets ): array {
		$sanitized_packets = array();

		foreach ( $data_packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				$sanitized_packets[] = $packet;
				continue;
			}

			$sanitized_packet = $packet;

			if ( isset( $sanitized_packet['data'] ) && is_array( $sanitized_packet['data'] ) ) {
				$sanitized_packet['data'] = self::sanitizePacketDataForAi( $sanitized_packet['data'] );
			}

			$sanitized_packets[] = $sanitized_packet;
		}

		return $sanitized_packets;
	}

	/**
	 * Remove internal file path fields from packet data.
	 *
	 * @param array $packet_data Packet data array.
	 * @return array Sanitized packet data.
	 */
	private static function sanitizePacketDataForAi( array $packet_data ): array {
		if ( ! isset( $packet_data['file_info'] ) || ! is_array( $packet_data['file_info'] ) ) {
			return $packet_data;
		}

		$sanitized_file_info = $packet_data['file_info'];
		unset( $sanitized_file_info['file_path'] );

		if ( empty( $sanitized_file_info ) ) {
			unset( $packet_data['file_info'] );
			return $packet_data;
		}

		$packet_data['file_info'] = $sanitized_file_info;
		return $packet_data;
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

		foreach ( $tool_execution_results as $tool_result_data ) {
			$tool_name         = $tool_result_data['tool_name'] ?? '';
			$tool_result       = $tool_result_data['result'] ?? array();
			$tool_parameters   = $tool_result_data['parameters'] ?? array();
			$is_handler_tool   = $tool_result_data['is_handler_tool'] ?? false;
			$result_turn_count = $tool_result_data['turn_count'] ?? $turn_count;

			if ( empty( $tool_name ) ) {
				continue;
			}

			$tool_def = $available_tools[ $tool_name ] ?? null;

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
						'tool_name'         => $tool_name,
						'handler_tool'      => $tool_def['handler'] ?? null,
						'tool_parameters'   => $clean_tool_parameters,
						'handler_config'    => $handler_config,
						'source_type'       => $input_source_type,
						'flow_step_id'      => $flow_step_id,
						'conversation_turn' => $result_turn_count,
						'tool_result'       => $tool_result,
					),
					'ai_handler_complete'
				);
				$outputPackets = $packet->addTo( $outputPackets );

				$handler_completed = true;
			} else {
				// Non-handler tool or failed tool - add tool result data packet
				$success_message = ConversationManager::generateSuccessMessage( $tool_name, $tool_result, $tool_parameters );

				$packet        = new DataPacket(
					array(
						'title' => ucwords( str_replace( '_', ' ', $tool_name ) ) . ' Result',
						'body'  => $success_message,
					),
					array(
						'tool_name'       => $tool_name,
						'handler_tool'    => $tool_def['handler'] ?? null,
						'tool_parameters' => $tool_parameters,
						'tool_success'    => $tool_result['success'] ?? false,
						'tool_result'     => $tool_result['data'] ?? array(),
						'source_type'     => $input_source_type,
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
					'source_type'       => 'ai_response',
					'flow_step_id'      => $flow_step_id,
					'conversation_turn' => $turn_count,
				),
				'ai_response'
			);
			$outputPackets = $packet->addTo( $outputPackets );
		}

		return $outputPackets;
	}
}
