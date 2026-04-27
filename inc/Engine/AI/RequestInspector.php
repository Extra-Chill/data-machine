<?php
/**
 * AI request inspector.
 *
 * Reconstructs the provider request for a pipeline AI step without dispatching it.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachine\Core\FilesRepository\FileRetrieval;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Steps\AI\AIStep;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Engine\StepNavigator;

defined( 'ABSPATH' ) || exit;

class RequestInspector {

	/**
	 * Inspect the final provider request for a pipeline AI job.
	 *
	 * @param int         $job_id       Job ID.
	 * @param string|null $flow_step_id Optional flow step ID. Required when the flow has multiple AI steps.
	 * @return array Inspection result.
	 */
	public function inspectPipelineJob( int $job_id, ?string $flow_step_id = null ): array {
		$jobs = new Jobs();
		$job  = $jobs->get_job( $job_id );

		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$engine_snapshot = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		if ( empty( $engine_snapshot ) ) {
			$engine_snapshot = EngineData::retrieve( $job_id );
		}

		$engine_snapshot['job'] = array_merge(
			is_array( $engine_snapshot['job'] ?? null ) ? $engine_snapshot['job'] : array(),
			array(
				'job_id'      => $job_id,
				'flow_id'     => $job['flow_id'] ?? null,
				'pipeline_id' => $job['pipeline_id'] ?? null,
				'user_id'     => isset( $job['user_id'] ) ? (int) $job['user_id'] : 0,
				'agent_id'    => isset( $job['agent_id'] ) ? (int) $job['agent_id'] : 0,
			)
		);

		$engine       = new EngineData( $engine_snapshot, $job_id );
		$flow_step_id = $flow_step_id ? $flow_step_id : $this->resolveAiFlowStepId( $engine->getFlowConfig() );
		if ( '' === $flow_step_id ) {
			return array(
				'success' => false,
				'error'   => 'No AI flow step found. Pass --step=<flow_step_id>.',
			);
		}

		$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );
		if ( empty( $flow_step_config ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow step "%s" not found in job engine snapshot.', $flow_step_id ),
			);
		}
		if ( 'ai' !== ( $flow_step_config['step_type'] ?? '' ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow step "%s" is not an AI step.', $flow_step_id ),
			);
		}

		$pipeline_step_id = (string) ( $flow_step_config['pipeline_step_id'] ?? '' );
		if ( '' === $pipeline_step_id ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow step "%s" is missing pipeline_step_id.', $flow_step_id ),
			);
		}

		$data_packets = $this->retrieveDataPackets( $job_id, $engine );
		$messages     = $this->buildInitialMessages( $data_packets, $engine, $flow_step_config );
		$payload      = $this->buildPayload( $job_id, $flow_step_id, $pipeline_step_id, $data_packets, $engine, $job );

		$previous_step_config = $this->getAdjacentStepConfig( $engine, $flow_step_id, $payload, 'previous' );
		$next_step_config     = $this->getAdjacentStepConfig( $engine, $flow_step_id, $payload, 'next' );

		$pipeline_step_config = $engine->getPipelineStepConfig( $pipeline_step_id );
		$tool_categories      = $pipeline_step_config['tool_categories'] ?? $engine->get( 'pipeline_tool_categories' ) ?? array();
		$agent_id             = (int) ( $payload['agent_id'] ?? 0 );

		$resolver = new ToolPolicyResolver();
		$tools    = $resolver->resolve(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'agent_id'             => $agent_id,
				'previous_step_config' => $previous_step_config,
				'next_step_config'     => $next_step_config,
				'pipeline_step_id'     => $pipeline_step_id,
				'engine_data'          => $engine->all(),
				'categories'           => $tool_categories,
			)
		);

		$required_handler_slugs = array();
		foreach ( array( $previous_step_config, $next_step_config ) as $adjacent_config ) {
			if ( $adjacent_config ) {
				$required_handler_slugs = array_merge( $required_handler_slugs, FlowStepConfig::getRequiredHandlerSlugsForAi( $adjacent_config ) );
			}
		}
		if ( ! empty( $required_handler_slugs ) ) {
			$handler_tool_slugs = array_values( array_intersect( array_unique( $required_handler_slugs ), array_keys( $tools ) ) );
			if ( ! empty( $handler_tool_slugs ) ) {
				$payload['configured_handler_slugs'] = $handler_tool_slugs;
			}
		}

		$mode_model = PluginSettings::resolveModelForAgentMode( $agent_id, ToolPolicyResolver::MODE_PIPELINE );
		$provider   = (string) ( $mode_model['provider'] ?? '' );
		$model      = (string) ( $mode_model['model'] ?? '' );

		$assembled = RequestBuilder::assemble(
			$messages,
			$provider,
			$model,
			$tools,
			ToolPolicyResolver::MODE_PIPELINE,
			$payload
		);

		return array_merge(
			array(
				'success'      => true,
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
				'step_id'      => $pipeline_step_id,
				'provider'     => $provider,
				'model'        => $model,
				'mode'         => ToolPolicyResolver::MODE_PIPELINE,
			),
			$this->measure( $assembled, $data_packets, $messages )
		);
	}

	private function resolveAiFlowStepId( array $flow_config ): string {
		$ai_steps = array();
		foreach ( $flow_config as $step_id => $step_config ) {
			if ( is_array( $step_config ) && 'ai' === ( $step_config['step_type'] ?? '' ) ) {
				$ai_steps[] = (string) $step_id;
			}
		}

		return 1 === count( $ai_steps ) ? $ai_steps[0] : '';
	}

	private function retrieveDataPackets( int $job_id, EngineData $engine ): array {
		$job_context = $engine->getJobContext();
		$flow_id     = $job_context['flow_id'] ?? null;
		if ( null === $flow_id || 'direct' === $flow_id || (int) $flow_id <= 0 ) {
			return array();
		}

		$context = \datamachine_get_file_context( (int) $flow_id );
		return ( new FileRetrieval() )->retrieve_data_by_job_id( $job_id, $context );
	}

	private function buildInitialMessages( array $data_packets, EngineData $engine, array $flow_step_config ): array {
		$messages = array();

		if ( ! empty( $data_packets ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => wp_json_encode( array( 'data_packets' => AIStep::sanitizeDataPacketsForAi( $data_packets ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			);
		}

		$file_path = $engine->get( 'image_file_path' );
		if ( $file_path && file_exists( $file_path ) ) {
			$file_info  = wp_check_filetype( $file_path );
			$messages[] = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'file',
						'file_path' => $file_path,
						'mime_type' => $file_info['type'] ?? '',
					),
				),
			);
		}

		$user_message = $this->peekPromptQueueValue( $flow_step_config );
		if ( '' !== $user_message ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $user_message,
			);
		}

		return $messages;
	}

	private function peekPromptQueueValue( array $flow_step_config ): string {
		$queue = $flow_step_config['prompt_queue'] ?? array();
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return '';
		}

		return trim( (string) ( $queue[0]['prompt'] ?? '' ) );
	}

	private function buildPayload(
		int $job_id,
		string $flow_step_id,
		string $pipeline_step_id,
		array $data_packets,
		EngineData $engine,
		array $job
	): array {
		$job_snapshot = $engine->getJobContext();
		$user_id      = (int) ( $job_snapshot['user_id'] ?? ( $job['user_id'] ?? 0 ) );
		$agent_id     = (int) ( $job_snapshot['agent_id'] ?? ( $job['agent_id'] ?? 0 ) );

		return array(
			'job_id'             => $job_id,
			'flow_step_id'       => $flow_step_id,
			'step_id'            => $pipeline_step_id,
			'data'               => $data_packets,
			'engine'             => $engine,
			'user_id'            => $user_id,
			'agent_id'           => $agent_id,
			'pipeline_id'        => $job_snapshot['pipeline_id'] ?? ( $job['pipeline_id'] ?? null ),
			'flow_id'            => $job_snapshot['flow_id'] ?? ( $job['flow_id'] ?? null ),
			'persist_transcript' => false,
		);
	}

	private function getAdjacentStepConfig( EngineData $engine, string $flow_step_id, array $payload, string $direction ): ?array {
		$navigator   = new StepNavigator();
		$adjacent_id = 'previous' === $direction
			? $navigator->get_previous_flow_step_id( $flow_step_id, $payload )
			: $navigator->get_next_flow_step_id( $flow_step_id, $payload );

		return $adjacent_id ? $engine->getFlowStepConfig( $adjacent_id ) : null;
	}

	private function measure( array $assembled, array $data_packets, array $initial_messages ): array {
		$request          = $assembled['request'];
		$structured_tools = $assembled['structured_tools'];
		$messages         = $request['messages'] ?? array();
		$tools            = $request['tools'] ?? array();

		return array(
			'message_count'                   => count( $messages ),
			'initial_message_count'           => count( $initial_messages ),
			'total_request_json_bytes'        => self::jsonBytes( $request ),
			'messages_json_bytes'             => self::jsonBytes( $messages ),
			'tools_json_bytes'                => self::jsonBytes( $tools ),
			'conversation_user_message_bytes' => self::sumUserMessageBytes( $initial_messages ),
			'conversation_packet_json_bytes'  => self::jsonBytes( AIStep::sanitizeDataPacketsForAi( $data_packets ) ),
			'directives'                      => $assembled['directive_breakdown'],
			'tool_count'                      => count( $structured_tools ),
			'largest_tools'                   => $this->largestTools( $structured_tools ),
			'request'                         => $request,
		);
	}

	private function largestTools( array $tools ): array {
		$rows = array();
		foreach ( $tools as $name => $tool ) {
			$rows[] = array(
				'name'       => (string) $name,
				'json_bytes' => self::jsonBytes( $tool ),
			);
		}

		usort( $rows, fn( $a, $b ) => $b['json_bytes'] <=> $a['json_bytes'] );
		return array_slice( $rows, 0, 10 );
	}

	private static function jsonBytes( $value ): int {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	private static function sumUserMessageBytes( array $messages ): int {
		$total = 0;
		foreach ( $messages as $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			$content = $message['content'] ?? '';
			$total  += is_string( $content ) ? strlen( $content ) : self::jsonBytes( $content );
		}
		return $total;
	}
}
