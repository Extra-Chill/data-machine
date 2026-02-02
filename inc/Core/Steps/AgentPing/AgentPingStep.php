<?php
/**
 * Agent Ping Step - POST pipeline context to webhook URLs.
 *
 * Sends full pipeline context to configured webhook URL.
 * Supports Discord webhooks with human-readable formatting.
 *
 * Configuration is at the flow step level via handler_config,
 * allowing different webhook URLs per flow.
 *
 * @package DataMachine\Core\Steps\AgentPing
 * @since 0.18.0
 */

namespace DataMachine\Core\Steps\AgentPing;

use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\QueueableTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentPingStep extends Step {

	use StepTypeRegistrationTrait;
	use QueueableTrait;

	/**
	 * Initialize Agent Ping step.
	 */
	public function __construct() {
		parent::__construct( 'agent_ping' );

		self::registerStepType(
			slug: 'agent_ping',
			label: 'Agent Ping',
			description: 'Send pipeline context to Discord, Slack, or custom webhook endpoints',
			class: self::class,
			position: 80,
			usesHandler: false,
			hasPipelineConfig: false,
			consumeAllPackets: false,
			stepSettings: array(
				'config_type' => 'handler',
				'modal_type'  => 'configure-step',
				'button_text' => 'Configure',
				'label'       => 'Agent Ping Configuration',
			),
			showSettingsDisplay: false
		);

		self::registerStepSettings();
	}

	/**
	 * Register Agent Ping settings class for UI display.
	 *
	 * Step types with usesHandler: false still need their settings
	 * registered for SettingsDisplayService to generate settings_display.
	 */
	private static function registerStepSettings(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter(
			'datamachine_handler_settings',
			function ( $all_settings, $handler_slug = null ) {
				if ( null === $handler_slug || 'agent_ping' === $handler_slug ) {
					$all_settings['agent_ping'] = new AgentPingSettings();
				}
				return $all_settings;
			},
			10,
			2
		);
	}

	/**
	 * Validate Agent Ping step configuration.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		$handler_config = $this->getHandlerConfig();
		$webhook_url    = $handler_config['webhook_url'] ?? '';

		if ( empty( trim( $webhook_url ) ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'agent_ping_url_missing',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'error_message' => 'Agent Ping step requires a webhook URL.',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Execute Agent Ping step logic.
	 *
	 * @return array
	 */
	protected function executeStep(): array {
		$handler_config = $this->getHandlerConfig();

		$webhook_url       = trim( $handler_config['webhook_url'] ?? '' );
		$configured_prompt = $handler_config['prompt'] ?? '';
		$data_packets      = $this->dataPackets;
		$queue_enabled     = (bool) ( $this->flow_step_config['queue_enabled'] ?? false );
		$prompt_queue      = $this->flow_step_config['prompt_queue'] ?? array();
		$queued_prompt     = $prompt_queue[0]['prompt'] ?? '';

		$from_queue = false;

		if ( $queue_enabled ) {
			$queue_result = $this->popFromQueueIfEmpty( '', true );
			$prompt       = $queue_result['value'];
			$from_queue   = $queue_result['from_queue'];
		} else {
			$prompt = $queued_prompt;
		}

		if ( empty( $prompt ) ) {
			$prompt = $configured_prompt;
		}

		// Execute the send-ping ability
		$ability = wp_get_ability( 'datamachine/send-ping' );

		if ( ! $ability ) {
			$result = array(
				'success' => false,
				'error'   => 'Ability datamachine/send-ping not registered',
			);
		} else {
			$result = $ability->execute(
				array(
					'webhook_url'  => $webhook_url,
					'prompt'       => $prompt,
					'from_queue'   => $from_queue,
					'data_packets' => $data_packets,
					'engine_data'  => $this->engine->all(),
					'flow_id'      => $this->flow_step_config['flow_id'] ?? null,
					'pipeline_id'  => $this->flow_step_config['pipeline_id'] ?? null,
					'job_id'       => $this->job_id,
				)
			);

			// Handle WP_Error from ability execution
			if ( is_wp_error( $result ) ) {
				$result = array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}
		}

		$success = $result['success'] ?? false;

		$result_packet = new DataPacket(
			array(
				'title' => 'Agent Ping Result',
				'body'  => $success ? 'Webhook notification sent successfully' : ( $result['error'] ?? 'Webhook notification failed' ),
			),
			array(
				'source_type'  => 'agent_ping',
				'flow_step_id' => $this->flow_step_id,
				'success'      => $success,
				'status_code'  => $result['status_code'] ?? null,
			),
			'agent_ping_result'
		);

			return $result_packet->addTo( $this->dataPackets );
	}
}
