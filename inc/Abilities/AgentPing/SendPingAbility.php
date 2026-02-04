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

use DataMachine\Abilities\PermissionHelper;

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
					'description'         => __( 'Send pipeline context to webhook endpoints. Supports multiple URLs (one per line).', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'webhook_url' ),
						'properties' => array(
							'webhook_url'      => array(
								'type'        => array( 'string', 'array' ),
								'description' => __( 'URL(s) to POST data to. Accepts array or newline-separated string.', 'data-machine' ),
							),
							'prompt'           => array(
								'type'        => 'string',
								'description' => __( 'Optional instructions for the receiving agent', 'data-machine' ),
							),
							'data_packets'     => array(
								'type'        => 'array',
								'description' => __( 'Pipeline data packets to include in payload', 'data-machine' ),
							),
							'flow_id'          => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'Flow ID for context', 'data-machine' ),
							),
							'pipeline_id'      => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'Pipeline ID for context', 'data-machine' ),
							),
							'job_id'           => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for context', 'data-machine' ),
							),
							'engine_data'      => array(
								'type'        => 'object',
								'description' => __( 'Engine data including post_id, published_url, etc.', 'data-machine' ),
							),
							'from_queue'       => array(
								'type'        => 'boolean',
								'description' => __( 'Whether this job originated from the prompt queue', 'data-machine' ),
							),
							'auth_header_name' => array(
								'type'        => 'string',
								'description' => __( 'Optional header name for authentication (e.g., X-Agent-Token)', 'data-machine' ),
							),
							'auth_token'       => array(
								'type'        => 'string',
								'description' => __( 'Optional token to send in the auth header', 'data-machine' ),
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
	 * @since 0.20.4 Uses PermissionHelper for Action Scheduler compatibility.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute send ping ability.
	 *
	 * Supports multiple webhook URLs (one per line). Sends to all URLs
	 * and returns success only if all requests succeed.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function execute( array $input ): array {
		$webhook_urls_input = $input['webhook_url'] ?? '';
		$prompt             = $input['prompt'] ?? '';
		$data_packets       = $input['data_packets'] ?? array();
		$engine_data        = $input['engine_data'] ?? array();
		$flow_id            = $input['flow_id'] ?? null;
		$pipeline_id        = $input['pipeline_id'] ?? null;
		$job_id             = $input['job_id'] ?? null;
		$from_queue         = $input['from_queue'] ?? false;
		$auth_header_name   = $input['auth_header_name'] ?? '';
		$auth_token         = $input['auth_token'] ?? '';

		if ( empty( $webhook_urls_input ) ) {
			return array(
				'success' => false,
				'error'   => 'webhook_url is required',
			);
		}

		// Support both array (from url_list field) and string (legacy/newline-separated).
		if ( is_array( $webhook_urls_input ) ) {
			$webhook_urls = array_filter(
				array_map( 'trim', $webhook_urls_input ),
				fn( $url ) => ! empty( $url )
			);
		} else {
			$webhook_urls = array_filter(
				array_map( 'trim', preg_split( '/[\r\n]+/', trim( $webhook_urls_input ) ) ),
				fn( $url ) => ! empty( $url )
			);
		}

		if ( empty( $webhook_urls ) ) {
			return array(
				'success' => false,
				'error'   => 'No valid webhook URLs provided',
			);
		}

		$results     = array();
		$all_success = true;
		$errors      = array();

		foreach ( $webhook_urls as $webhook_url ) {
			$result = $this->sendToUrl(
				$webhook_url,
				$prompt,
				$data_packets,
				$engine_data,
				$flow_id,
				$pipeline_id,
				$job_id,
				$from_queue,
				$auth_header_name,
				$auth_token
			);

			$results[] = $result;

			if ( ! $result['success'] ) {
				$all_success = false;
				$errors[]    = $this->sanitizeUrlForLog( $webhook_url ) . ': ' . ( $result['error'] ?? 'Unknown error' );
			}
		}

		$url_count = count( $webhook_urls );

		if ( $all_success ) {
			return array(
				'success' => true,
				'message' => $url_count > 1
					? sprintf( 'Webhook notifications sent successfully to %d URLs', $url_count )
					: 'Webhook notification sent successfully',
				'results' => $results,
			);
		}

		return array(
			'success' => false,
			'error'   => implode( '; ', $errors ),
			'results' => $results,
		);
	}

	/**
	 * Send ping to a single webhook URL.
	 *
	 * @param string   $webhook_url Target URL.
	 * @param string   $prompt Optional instructions.
	 * @param array    $data_packets Pipeline data packets.
	 * @param array    $engine_data Engine data.
	 * @param mixed    $flow_id Flow ID.
	 * @param mixed    $pipeline_id Pipeline ID.
	 * @param int|null $job_id Job ID.
	 * @param bool     $from_queue Whether job originated from prompt queue.
	 * @param string   $auth_header_name Optional auth header name.
	 * @param string   $auth_token Optional auth token.
	 * @return array Result with success status.
	 */
	private function sendToUrl(
		string $webhook_url,
		string $prompt,
		array $data_packets,
		array $engine_data,
		$flow_id,
		$pipeline_id,
		?int $job_id,
		bool $from_queue,
		string $auth_header_name = '',
		string $auth_token = ''
	): array {
		$payload = $this->buildPayload( $webhook_url, $prompt, $data_packets, $engine_data, $flow_id, $pipeline_id, $job_id, $from_queue );

		// Build headers with optional auth.
		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $auth_header_name ) && ! empty( $auth_token ) ) {
			$headers[ $auth_header_name ] = $auth_token;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'headers' => $headers,
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
				'url'     => $this->sanitizeUrlForLog( $webhook_url ),
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
				'url'         => $this->sanitizeUrlForLog( $webhook_url ),
				'status_code' => $status_code,
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
			'url'         => $this->sanitizeUrlForLog( $webhook_url ),
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
	 * @param bool       $from_queue Whether job originated from prompt queue.
	 * @return array Payload for POST request.
	 */
	private function buildPayload( string $url, string $prompt, array $data_packets, array $engine_data, $flow_id, $pipeline_id, ?int $job_id, bool $from_queue = false ): array {
		if ( $this->isDiscordWebhook( $url ) ) {
			return $this->buildDiscordPayload( $prompt, $data_packets, $engine_data, $from_queue );
		}

		return array(
			'prompt'    => $prompt,
			'context'   => array(
				'data_packets' => $data_packets,
				'engine_data'  => $engine_data,
				'flow_id'      => $flow_id,
				'pipeline_id'  => $pipeline_id,
				'job_id'       => $job_id,
				'from_queue'   => $from_queue,
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
	 * @param bool   $from_queue Whether job originated from prompt queue.
	 * @return array Discord webhook payload.
	 */
	private function buildDiscordPayload( string $prompt, array $data_packets, array $engine_data = array(), bool $from_queue = false ): array {
		$first_packet = $data_packets[0] ?? array();
		$title        = $first_packet['content']['title'] ?? 'New content';
		$url          = $first_packet['metadata']['url'] ?? $first_packet['metadata']['permalink'] ?? '';

		$source  = $from_queue ? '📋' : '🤖';
		$content = "{$source} **{$title}**";
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
