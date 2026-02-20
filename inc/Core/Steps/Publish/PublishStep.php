<?php

namespace DataMachine\Core\Steps\Publish;

use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Engine\AI\Tools\ToolResultFinder;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Data publishing step for Data Machine pipelines.
 *
 * @package DataMachine
 */
class PublishStep extends Step {


	use StepTypeRegistrationTrait;

	/**
	 * Initialize publish step.
	 */
	public function __construct() {
		parent::__construct('publish');

		self::registerStepType(
			slug: 'publish',
			label: 'Publish',
			description: 'Publish content to external platforms',
			class: self::class,
			position: 30,
			usesHandler: true,
			hasPipelineConfig: false
		);
	}

	/**
	 * Validate that at least one handler is configured.
	 * Supports both singular handler_slug and plural handler_slugs.
	 *
	 * @return bool True if configuration is valid, false otherwise
	 */
	protected function validateStepConfiguration(): bool {
		$handlers = $this->getHandlerSlugs();
		if ( empty($handlers) ) {
			$this->logConfigurationError(
				'Step requires at least one handler configuration',
				array( 'available_flow_step_config' => array_keys($this->flow_step_config) )
			);
			return false;
		}
		return true;
	}

	/**
	 * Execute publish step logic.
	 * Supports both single and multi-handler configurations.
	 *
	 * @return array
	 */
	protected function executeStep(): array {
		$handler_slugs = $this->getHandlerSlugs();

		if ( empty($handler_slugs) ) {
			$this->log('error', 'No handlers configured for publish step');
			return array();
		}

		// Single handler: preserve existing behavior exactly
		if ( count($handler_slugs) === 1 ) {
			$handler           = $handler_slugs[0];
			$tool_result_entry = ToolResultFinder::findHandlerResult($this->dataPackets, $handler, $this->flow_step_id);
			if ( $tool_result_entry ) {
				$this->log(
					'info',
					'AI successfully used handler tool',
					array(
						'handler'     => $handler,
						'tool_result' => $tool_result_entry['metadata']['tool_name'] ?? 'unknown',
					)
				);
				return $this->create_publish_entry_from_tool_result($tool_result_entry, $this->dataPackets, $handler, $this->flow_step_id);
			}
			return array();
		}

		// Multi-handler: find and process all matching results
		$all_results = ToolResultFinder::findAllHandlerResults($this->dataPackets, $handler_slugs, $this->flow_step_id);

		if ( empty($all_results) ) {
			return array();
		}

		$updatedPackets = $this->dataPackets;
		foreach ( $all_results as $tool_result_entry ) {
			$handler = $tool_result_entry['metadata']['handler_tool'] ?? 'unknown';
			$this->log(
				'info',
				'AI successfully used handler tool',
				array(
					'handler'     => $handler,
					'tool_result' => $tool_result_entry['metadata']['tool_name'] ?? 'unknown',
				)
			);
			$updatedPackets = $this->create_publish_entry_from_tool_result($tool_result_entry, $updatedPackets, $handler, $this->flow_step_id);
		}

		$this->log(
			'info',
			'Multi-handler publish complete',
			array(
				'handlers_configured' => count($handler_slugs),
				'handlers_executed'   => count($all_results),
			)
		);

		return $updatedPackets;
	}

	/**
	 * Create publish data packet from AI tool execution result.
	 *
	 * @param  array  $tool_result_entry Tool execution result entry
	 * @param  array  $dataPackets       Current data packet array
	 * @param  string $handler           Handler name
	 * @param  string $flow_step_id      Flow step identifier
	 * @return array Publish data packet
	 */
	private function create_publish_entry_from_tool_result( array $tool_result_entry, array $dataPackets, string $handler, string $flow_step_id ): array {
		$tool_result_data = $tool_result_entry['metadata']['tool_result'] ?? array();
		$entry_type       = $tool_result_entry['type'] ?? '';

		if ( empty($tool_result_data) ) {
			$this->log(
				'warning',
				'Tool result entry found but tool_result_data is empty',
				array(
					'handler'       => $handler,
					'entry_type'    => $entry_type,
					'metadata_keys' => array_keys($tool_result_entry['metadata'] ?? array()),
				)
			);
		}

		$executed_via = ( 'ai_handler_complete' === $entry_type ) ? 'ai_conversation_tool' : 'ai_tool_call';
		$title_suffix = ( 'ai_handler_complete' === $entry_type ) ? '(via AI Conversation)' : '(via AI Tool)';

		$packet = new DataPacket(
			array(
				'title' => 'Publish Complete ' . $title_suffix,
				'body'  => wp_json_encode($tool_result_data, JSON_PRETTY_PRINT),
			),
			array(
				'handler_used'        => $handler,
				'publish_success'     => true,
				'executed_via'        => $executed_via,
				'flow_step_id'        => $flow_step_id,
				'source_type'         => $tool_result_entry['metadata']['source_type'] ?? 'unknown',
				'tool_execution_data' => $tool_result_data,
				'original_entry_type' => $entry_type,
				'result'              => $tool_result_data,
			),
			'publish'
		);

		return $packet->addTo($dataPackets);
	}
}
