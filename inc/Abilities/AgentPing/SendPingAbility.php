<?php
/**
 * Send Ping Ability
 *
 * Sends pipeline context to webhook endpoints (Discord, Slack, custom).
 * Supports Discord-specific formatting when URL contains discord.com.
 *
 * @package DataMachine\Abilities\AgentPing
 * @since 0.18.0
 */

namespace DataMachine\Abilities\AgentPing;

defined( 'ABSPATH' ) || exit;

class SendPingAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/send-ping',
				array(
					'label'               => __( 'Send Ping', 'data-machine' ),
					'description'         => __( 'Send pipeline context to a webhook endpoint (Discord, Slack, custom).', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'webhook_url' ),
						'properties' => array(
							'webhook_url'  => array(
								'type'        => 'string',
								'description' => __( 'URL to POST data to (Discord, Slack, custom endpoint)', 'data-machine' ),
							),
							'prompt'       => array(
								'type'        => 'string',
								'description' => __( 'Optional instructions for the receiving agent', 'data-machine' ),
							),
							'data_packets' => array(
								'type'        => 'array',
								'description' => __( 'Pipeline data packets to include in payload', 'data-machine' ),
							),
							'flow_id'      => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'Flow ID for context', 'data-machine' ),
							),
							'pipeline_id'  => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'Pipeline ID for context', 'data-machine' ),
							),
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for context', 'data-machine' ),
							),
							'engine_data'  => array(
								'type'        => 'object',
								'description' => __( 'Engine data including post_id, published_url, etc.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'status_code' => array( 'type' => 'integer' ),
							'message'     => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Check permission for ability execution.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute send ping ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function execute( array $input ): array {
		$webhook_url  = trim( $input['webhook_url'] ?? '' );
		$prompt       = $input['prompt'] ?? '';
		$data_packets = $input['data_packets'] ?? array();
		$engine_data  = $input['engine_data'] ?? array();
		$flow_id      = $input['flow_id'] ?? null;
		$pipeline_id  = $input['pipeline_id'] ?? null;
		$job_id       = $input['job_id'] ?? null;

		if ( empty( $webhook_url ) ) {
			return array(
				'success' => false,
				'error'   => 'webhook_url is required',
			);
		}

		$payload = $this->buildPayload( $webhook_url, $prompt, $data_packets, $engine_data, $flow_id, $pipeline_id, $job_id );

		$response = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Agent Ping request failed',
				array(
					'url'   => $this->sanitizeUrlForLog( $webhook_url ),
					'error' => $response->get_error_message(),
				)
			);

			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			do_action(
				'datamachine_log',
				'info',
				'Agent Ping sent successfully',
				array(
					'url'         => $this->sanitizeUrlForLog( $webhook_url ),
					'status_code' => $status_code,
				)
			);

			return array(
				'success'     => true,
				'status_code' => $status_code,
				'message'     => 'Webhook notification sent successfully',
			);
		}

		do_action(
			'datamachine_log',
			'warning',
			'Agent Ping received non-success response',
			array(
				'url'           => $this->sanitizeUrlForLog( $webhook_url ),
				'status_code'   => $status_code,
				'response_body' => wp_remote_retrieve_body( $response ),
			)
		);

		return array(
			'success'     => false,
			'status_code' => $status_code,
			'error'       => 'Webhook returned non-success status code: ' . $status_code,
		);
	}

	/**
	 * Build payload for webhook request.
	 *
	 * @param string     $url Webhook URL.
	 * @param string     $prompt Optional instructions.
	 * @param array      $data_packets Pipeline data packets.
	 * @param array      $engine_data Engine data (post_id, published_url, etc.).
	 * @param mixed      $flow_id Flow ID.
	 * @param mixed      $pipeline_id Pipeline ID.
	 * @param int|null   $job_id Job ID.
	 * @return array Payload for POST request.
	 */
	private function buildPayload( string $url, string $prompt, array $data_packets, array $engine_data, $flow_id, $pipeline_id, ?int $job_id ): array {
		if ( $this->isDiscordWebhook( $url ) ) {
			return $this->buildDiscordPayload( $prompt, $data_packets, $engine_data );
		}

		return array(
			'prompt'    => $prompt,
			'context'   => array(
				'data_packets' => $data_packets,
				'engine_data'  => $engine_data,
				'flow_id'      => $flow_id,
				'pipeline_id'  => $pipeline_id,
				'job_id'       => $job_id,
			),
			'timestamp' => gmdate( 'c' ),
		);
	}

	/**
	 * Build Discord-formatted payload.
	 *
	 * @param string $prompt Optional instructions.
	 * @param array  $data_packets Pipeline data packets.
	 * @param array  $engine_data Engine data (for future use).
	 * @return array Discord webhook payload.
	 */
	private function buildDiscordPayload( string $prompt, array $data_packets, array $engine_data = array() ): array {
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
	 * @param string $url Webhook URL.
	 * @return bool
	 */
	private function isDiscordWebhook( string $url ): bool {
		return str_contains( $url, 'discord.com/api/webhooks/' )
			|| str_contains( $url, 'discordapp.com/api/webhooks/' );
	}

	/**
	 * Sanitize URL for logging (mask tokens).
	 *
	 * @param string $url Full URL.
	 * @return string URL with token masked.
	 */
	private function sanitizeUrlForLog( string $url ): string {
		return preg_replace(
			'#(discord(?:app)?\.com/api/webhooks/\d+/)[^/\s]+#',
			'$1[MASKED]',
			$url
		);
	}
}
