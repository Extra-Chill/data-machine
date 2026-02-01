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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentPingStep extends Step {

	use StepTypeRegistrationTrait;

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
			)
		);
	}

	/**
	 * Validate Agent Ping step configuration.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		$handler_config = $this->getHandlerConfig();
		$trigger_mode   = $handler_config['trigger_mode'] ?? 'webhook';
		$webhook_url    = $handler_config['webhook_url'] ?? '';
		$prompt         = $handler_config['prompt'] ?? '';

		// For webhook mode, require webhook_url.
		if ( 'webhook' === $trigger_mode && empty( trim( $webhook_url ) ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'agent_ping_url_missing',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'error_message' => 'Agent Ping step requires a webhook URL in webhook mode.',
				)
			);
			return false;
		}

		// For openclaw mode, require prompt (the message to send).
		if ( 'openclaw' === $trigger_mode && empty( trim( $prompt ) ) ) {
			do_action(
				'datamachine_fail_job',
				$this->job_id,
				'agent_ping_message_missing',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'error_message' => 'Agent Ping step requires instructions/message in openclaw mode.',
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
		$trigger_mode   = $handler_config['trigger_mode'] ?? 'webhook';

		if ( 'openclaw' === $trigger_mode ) {
			return $this->executeOpenclawMode( $handler_config );
		}

		return $this->executeWebhookMode( $handler_config );
	}

	/**
	 * Execute webhook trigger mode.
	 *
	 * @param array $handler_config Step configuration.
	 * @return array Updated data packets.
	 */
	private function executeWebhookMode( array $handler_config ): array {
		$webhook_url  = trim( $handler_config['webhook_url'] ?? '' );
		$prompt       = $handler_config['prompt'] ?? '';
		$data_packets = $this->dataPackets;

		$payload = $this->buildPayload( $webhook_url, $prompt, $data_packets );

		$response = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		$success = false;
		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				'Agent Ping request failed',
				array(
					'url'   => $this->sanitizeUrlForLog( $webhook_url ),
					'error' => $response->get_error_message(),
				)
			);
		} else {
			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 200 && $status_code < 300 ) {
				$success = true;
				$this->log(
					'info',
					'Agent Ping sent successfully',
					array(
						'url'         => $this->sanitizeUrlForLog( $webhook_url ),
						'status_code' => $status_code,
					)
				);
			} else {
				$this->log(
					'warning',
					'Agent Ping received non-success response',
					array(
						'url'           => $this->sanitizeUrlForLog( $webhook_url ),
						'status_code'   => $status_code,
						'response_body' => wp_remote_retrieve_body( $response ),
					)
				);
			}
		}

		$result_packet = new DataPacket(
			array(
				'title' => 'Agent Ping Result',
				'body'  => $success ? 'Webhook notification sent successfully' : 'Webhook notification failed',
			),
			array(
				'source_type'   => 'agent_ping',
				'flow_step_id'  => $this->flow_step_id,
				'trigger_mode'  => 'webhook',
				'success'       => $success,
			),
			'agent_ping_result'
		);

		return $result_packet->addTo( $this->dataPackets );
	}

	/**
	 * Execute OpenClaw agent trigger mode.
	 *
	 * @param array $handler_config Step configuration.
	 * @return array Updated data packets.
	 */
	private function executeOpenclawMode( array $handler_config ): array {
		$prompt        = $handler_config['prompt'] ?? '';
		$channel       = $handler_config['channel'] ?? 'discord';
		$deliver       = $handler_config['deliver'] ?? true;
		$openclaw_path = $handler_config['openclaw_path'] ?? 'openclaw';

		// Build message with context from data packets.
		$message = $this->buildOpenclawMessage( $prompt, $this->dataPackets );

		// Execute via ability.
		$result = wp_execute_ability(
			'datamachine/trigger-agent',
			array(
				'message'       => $message,
				'channel'       => $channel,
				'deliver'       => $deliver,
				'openclaw_path' => $openclaw_path,
			)
		);

		$success = $result['success'] ?? false;

		$this->log(
			$success ? 'info' : 'error',
			$success ? 'OpenClaw agent triggered successfully' : 'Failed to trigger OpenClaw agent',
			array(
				'channel' => $channel,
				'deliver' => $deliver,
				'error'   => $result['error'] ?? null,
			)
		);

		$result_packet = new DataPacket(
			array(
				'title' => 'Agent Ping Result',
				'body'  => $success ? 'OpenClaw agent triggered successfully' : ( $result['error'] ?? 'Failed to trigger agent' ),
			),
			array(
				'source_type'  => 'agent_ping',
				'flow_step_id' => $this->flow_step_id,
				'trigger_mode' => 'openclaw',
				'success'      => $success,
			),
			'agent_ping_result'
		);

		return $result_packet->addTo( $this->dataPackets );
	}

	/**
	 * Build message for OpenClaw agent with context from data packets.
	 *
	 * @param string $prompt Base instructions/prompt.
	 * @param array  $data_packets Pipeline data packets.
	 * @return string Formatted message.
	 */
	private function buildOpenclawMessage( string $prompt, array $data_packets ): string {
		$message_parts = array();

		if ( ! empty( $prompt ) ) {
			$message_parts[] = $prompt;
		}

		// Add context from data packets if available.
		if ( ! empty( $data_packets ) ) {
			$context_parts = array();
			foreach ( $data_packets as $packet ) {
				$title = $packet['content']['title'] ?? '';
				$url   = $packet['metadata']['url'] ?? $packet['metadata']['permalink'] ?? '';

				if ( ! empty( $title ) ) {
					$context_parts[] = $title . ( ! empty( $url ) ? " ({$url})" : '' );
				}
			}

			if ( ! empty( $context_parts ) ) {
				$message_parts[] = "\n\nContext:\n" . implode( "\n", $context_parts );
			}
		}

		// Add flow/pipeline context.
		$flow_id     = $this->engine->get( 'flow_id' );
		$pipeline_id = $this->engine->get( 'pipeline_id' );

		if ( $flow_id || $pipeline_id ) {
			$message_parts[] = sprintf(
				"\n\n[Pipeline: %s, Flow: %s, Job: %s]",
				$pipeline_id ?? 'N/A',
				$flow_id ?? 'N/A',
				$this->job_id ?? 'N/A'
			);
		}

		return implode( '', $message_parts );
	}

	/**
	 * Build payload for webhook request.
	 *
	 * @param string $url Webhook URL
	 * @param string $prompt Optional instructions
	 * @param array  $data_packets Pipeline data packets
	 * @return array Payload for POST request
	 */
	private function buildPayload( string $url, string $prompt, array $data_packets ): array {
		if ( $this->isDiscordWebhook( $url ) ) {
			return $this->buildDiscordPayload( $prompt, $data_packets );
		}

		return array(
			'prompt'    => $prompt,
			'context'   => array(
				'data_packets' => $data_packets,
				'flow_id'      => $this->engine->get( 'flow_id' ),
				'pipeline_id'  => $this->engine->get( 'pipeline_id' ),
				'job_id'       => $this->job_id,
			),
			'timestamp' => gmdate( 'c' ),
		);
	}

	/**
	 * Build Discord-formatted payload.
	 *
	 * @param string $prompt Optional instructions
	 * @param array  $data_packets Pipeline data packets
	 * @return array Discord webhook payload
	 */
	private function buildDiscordPayload( string $prompt, array $data_packets ): array {
		$first_packet = $data_packets[0] ?? array();
		$title        = $first_packet['content']['title'] ?? 'New content';
		$url          = $first_packet['metadata']['url'] ?? $first_packet['metadata']['permalink'] ?? '';

		$content = "🤖 **{$title}**";
		if ( ! empty( $url ) ) {
			$content .= "\n{$url}";
		}
		if ( ! empty( $prompt ) ) {
			$content .= "\n\n{$prompt}";
		}

		return array( 'content' => $content );
	}

	/**
	 * Check if URL is a Discord webhook.
	 *
	 * @param string $url Webhook URL
	 * @return bool
	 */
	private function isDiscordWebhook( string $url ): bool {
		return str_contains( $url, 'discord.com/api/webhooks/' )
			|| str_contains( $url, 'discordapp.com/api/webhooks/' );
	}

	/**
	 * Sanitize URL for logging (mask tokens).
	 *
	 * @param string $url Full URL
	 * @return string URL with token masked
	 */
	private function sanitizeUrlForLog( string $url ): string {
		return preg_replace(
			'#(discord(?:app)?\.com/api/webhooks/\d+/)[^/\s]+#',
			'$1[MASKED]',
			$url
		);
	}
}
