<?php

namespace DataMachine\Core\Steps\AI;

use DataMachine\Core\DataPacket;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\QueueableTrait;
use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

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
		$pipeline_defaults    = PluginSettings::getAgentModel( 'pipeline' );
		$provider_name        = $pipeline_step_config['provider'] ?? $pipeline_defaults['provider'];
		if ( empty( $provider_name ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'ai_provider_missing',
				array(
					'flow_step_id'     => $this->flow_step_id,
					'pipeline_step_id' => $pipeline_step_id,
					'error_message'    => 'AI step requires provider configuration. Please configure an AI provider in step settings or set a default provider in plugin settings.',
					'solution'         => 'Configure AI provider in pipeline step settings or set default provider in Data Machine settings',
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
		$configured_message = trim( $this->flow_step_config['user_message'] ?? '' );
		$queue_enabled      = (bool) ( $this->flow_step_config['queue_enabled'] ?? false );
		$prompt_queue       = $this->flow_step_config['prompt_queue'] ?? array();
		$queued_prompt      = $prompt_queue[0]['prompt'] ?? '';

		if ( $queue_enabled ) {
			$queue_result = $this->popFromQueueIfEmpty( '', true );
			$user_message = $queue_result['value'];

			// Queue is enabled but empty — skip cleanly instead of failing.
			if ( empty( $user_message ) && empty( $configured_message ) ) {
				do_action(
					'datamachine_log',
					'info',
					'AI step skipped — queue enabled but empty, no configured message',
					array(
						'job_id'       => $this->job_id,
						'flow_step_id' => $this->flow_step_id,
					)
				);

				// Set status override so Engine completes with completed_no_items
				// instead of treating empty data packets as a failure.
				$this->engine->set( 'job_status', \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS );

				return $this->dataPackets;
			}
		} else {
			$user_message = $queued_prompt;
		}

		if ( empty( $user_message ) ) {
			$user_message = $configured_message;
		}

		// Vision image from engine data (single source of truth)
		$file_path    = null;
		$mime_type    = null;
		$engine_image = $this->engine->get( 'image_file_path' );
		if ( $engine_image && file_exists( $engine_image ) ) {
			$file_path = $engine_image;
			$file_info = wp_check_filetype( $engine_image );
			$mime_type = $file_info['type'] ?? '';
		}

		$messages = array();

		if ( ! empty( $this->dataPackets ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => wp_json_encode( array( 'data_packets' => $this->dataPackets ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			);
		}

		if ( $file_path && file_exists( $file_path ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'file',
						'file_path' => $file_path,
						'mime_type' => $mime_type ?? '',
					),
				),
			);
		}

		if ( ! empty( $user_message ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $user_message,
			);
		}

		$pipeline_step_id = $this->flow_step_config['pipeline_step_id'];

		$pipeline_step_config = $this->engine->getPipelineStepConfig( $pipeline_step_id );

		$max_turns = PluginSettings::get( 'max_turns', 12 );

		// Resolve user_id and agent_id from engine snapshot (set by RunFlowAbility).
		$job_snapshot = $this->engine->get( 'job' );
		$user_id      = (int) ( $job_snapshot['user_id'] ?? 0 );
		$agent_id     = (int) ( $job_snapshot['agent_id'] ?? 0 );

		$payload = array(
			'job_id'       => $this->job_id,
			'flow_step_id' => $this->flow_step_id,
			'step_id'      => $pipeline_step_id,
			'data'         => $this->dataPackets,
			'engine'       => $this->engine,
			'user_id'      => $user_id,
		);

		$navigator             = new \DataMachine\Engine\StepNavigator();
		$previous_flow_step_id = $navigator->get_previous_flow_step_id( $this->flow_step_id, $payload );

		$previous_step_config = $previous_flow_step_id ? $this->engine->getFlowStepConfig( $previous_flow_step_id ) : null;

		$next_flow_step_id = $navigator->get_next_flow_step_id( $this->flow_step_id, $payload );
		$next_step_config  = $next_flow_step_id ? $this->engine->getFlowStepConfig( $next_flow_step_id ) : null;

		// Collect handler slugs from adjacent steps for multi-handler tracking.
		$all_handler_slugs = array();
		foreach ( array( $previous_step_config, $next_step_config ) as $adj_step_config ) {
			if ( ! $adj_step_config ) {
				continue;
			}
			$handler_slugs     = $adj_step_config['handler_slugs'] ?? array();
			$all_handler_slugs = array_merge( $all_handler_slugs, $handler_slugs );
		}
		if ( ! empty( $all_handler_slugs ) ) {
			$payload['flow_step_config'] = array(
				'handler_slugs' => array_unique( $all_handler_slugs ),
			);
		}

		$engine_data     = $this->engine->all();
		$resolver        = new ToolPolicyResolver();
		$available_tools = $resolver->resolve( array(
			'context'              => ToolPolicyResolver::CONTEXT_PIPELINE,
			'agent_id'             => $agent_id,
			'previous_step_config' => $previous_step_config,
			'next_step_config'     => $next_step_config,
			'pipeline_step_id'     => $pipeline_step_id,
			'engine_data'          => $engine_data,
		) );

		$pipeline_agent_defaults = PluginSettings::getAgentModel( 'pipeline' );
		$provider_name           = $pipeline_step_config['provider'] ?? $pipeline_agent_defaults['provider'];

		// Execute conversation loop
		$loop        = new AIConversationLoop();
		$loop_result = $loop->execute(
			$messages,
			$available_tools,
			$provider_name,
			$pipeline_step_config['model'] ?? $pipeline_agent_defaults['model'],
			'pipeline',
			$payload,
			$max_turns
		);

		// Check for errors
		if ( isset( $loop_result['error'] ) ) {
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

		// Process loop results into data packets
		return self::processLoopResults( $loop_result, $this->dataPackets, $payload, $available_tools );
	}

	/**
	 * Process AI conversation loop results into data packets.
	 *
	 * Only emits actionable packets (handler completions, tool results) that
	 * downstream steps depend on. Conversation turns are tracked as metadata
	 * but not emitted as individual DataPackets — doing so causes the batch
	 * scheduler to fan them out as ghost child jobs.
	 *
	 * @param array $loop_result Results from AIConversationLoop
	 * @param array $dataPackets Current data packet array
	 * @param array $payload Step payload
	 * @param array $available_tools Tools available during conversation
	 * @return array Updated data packet array
	 */
	private static function processLoopResults( array $loop_result, array $dataPackets, array $payload, array $available_tools ): array {
		if ( ! isset( $payload['flow_step_id'] ) || empty( $payload['flow_step_id'] ) ) {
			throw new \InvalidArgumentException( 'Flow step ID is required in AI step payload' );
		}

		$flow_step_id           = $payload['flow_step_id'];
		$messages               = $loop_result['messages'] ?? array();
		$tool_execution_results = $loop_result['tool_execution_results'] ?? array();

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

		// Process tool execution results into data packets.
		// Only handler completions and tool results are emitted — these are
		// consumed by downstream steps (PublishStep, UpdateStep) via ToolResultFinder.
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

				$packet      = new DataPacket(
					array(
						'title' => 'Handler Tool Executed: ' . $tool_name,
						'body'  => 'Tool executed successfully by AI agent in ' . $result_turn_count . ' conversation turns',
					),
					array(
						'tool_name'         => $tool_name,
						'handler_tool'      => $tool_def['handler'] ?? null,
						'tool_parameters'   => $clean_tool_parameters,
						'handler_config'    => $handler_config,
						'source_type'       => $dataPackets[0]['metadata']['source_type'] ?? 'unknown',
						'flow_step_id'      => $flow_step_id,
						'conversation_turn' => $result_turn_count,
						'tool_result'       => $tool_result,
					),
					'ai_handler_complete'
				);
				$dataPackets = $packet->addTo( $dataPackets );

				$handler_completed = true;
			} else {
				// Non-handler tool or failed tool - add tool result data packet
				$success_message = ConversationManager::generateSuccessMessage( $tool_name, $tool_result, $tool_parameters );

				$packet      = new DataPacket(
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
						'source_type'     => $dataPackets[0]['metadata']['source_type'] ?? 'unknown',
					),
					'tool_result'
				);
				$dataPackets = $packet->addTo( $dataPackets );
			}
		}

		// If no handler completed and no tool results were added, emit a single
		// summary packet so the step doesn't appear to have produced nothing.
		if ( ! $handler_completed && count( $dataPackets ) === 0 && ! empty( $final_ai_content ) ) {
			$content_lines = explode( "\n", trim( $final_ai_content ), 2 );
			$ai_title      = ( strlen( $content_lines[0] ) <= 100 ) ? $content_lines[0] : 'AI Response';

			$packet      = new DataPacket(
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
			$dataPackets = $packet->addTo( $dataPackets );
		}

		return $dataPackets;
	}
}
