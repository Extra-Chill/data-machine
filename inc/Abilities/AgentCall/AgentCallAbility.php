<?php
/**
 * Agent Call Ability.
 *
 * Calls an agent target through a structured target/input/delivery contract.
 * The first supported target is webhook fire-and-forget delivery.
 *
 * @package DataMachine\Abilities\AgentCall
 */

namespace DataMachine\Abilities\AgentCall;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class AgentCallAbility {

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/agent-call',
				array(
					'label'               => __( 'Agent Call', 'data-machine' ),
					'description'         => __( 'Call an agent target through a structured invocation contract. Supports webhook fire-and-forget delivery.', 'data-machine' ),
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'target', 'delivery' ),
						'properties' => array(
							'target'   => array(
								'type'       => 'object',
								'required'   => array( 'type', 'id' ),
								'properties' => array(
									'type' => array(
										'type'        => 'string',
										'enum'        => array( 'webhook' ),
										'description' => __( 'Target transport type. Only webhook is supported now.', 'data-machine' ),
									),
									'id'   => array(
										'type'        => array( 'string', 'array' ),
										'description' => __( 'Target identifier. For webhook targets this is one URL, an array of URLs, or newline-separated URLs.', 'data-machine' ),
									),
									'auth' => array(
										'type'        => 'object',
										'description' => __( 'Optional transport authentication metadata.', 'data-machine' ),
									),
								),
							),
							'input'    => array(
								'type'       => 'object',
								'properties' => array(
									'task'     => array( 'type' => 'string' ),
									'messages' => array( 'type' => 'array' ),
									'context'  => array( 'type' => 'object' ),
								),
							),
							'delivery' => array(
								'type'       => 'object',
								'required'   => array( 'mode' ),
								'properties' => array(
									'mode'     => array(
										'type'        => 'string',
										'enum'        => array( 'fire_and_forget' ),
										'description' => __( 'Delivery mode. Only fire_and_forget is supported now.', 'data-machine' ),
									),
									'timeout'  => array( 'type' => 'integer' ),
									'reply_to' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'status'        => array( 'type' => 'string' ),
							'output'        => array( 'type' => 'object' ),
							'messages'      => array( 'type' => 'array' ),
							'remote_run_id' => array( 'type' => 'string' ),
							'resume_token'  => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Check permission for ability execution.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute an agent call.
	 *
	 * @param array $input Canonical target/input/delivery payload.
	 * @return array Agent-call result envelope.
	 */
	public function execute( array $input ): array {
		$target   = is_array( $input['target'] ?? null ) ? $input['target'] : array();
		$delivery = is_array( $input['delivery'] ?? null ) ? $input['delivery'] : array();

		if ( 'webhook' !== ( $target['type'] ?? '' ) ) {
			return $this->failure( 'Unsupported agent_call target type: ' . (string) ( $target['type'] ?? '' ) );
		}

		if ( 'fire_and_forget' !== ( $delivery['mode'] ?? '' ) ) {
			return $this->failure( 'Unsupported agent_call delivery mode: ' . (string) ( $delivery['mode'] ?? '' ) );
		}

		return $this->executeWebhookFireAndForget( $input );
	}

	/**
	 * Execute webhook fire-and-forget delivery.
	 *
	 * @param array $input Canonical call input.
	 * @return array Agent-call result envelope.
	 */
	private function executeWebhookFireAndForget( array $input ): array {
		$target   = $input['target'];
		$call     = is_array( $input['input'] ?? null ) ? $input['input'] : array();
		$delivery = $input['delivery'];

		$webhook_urls = $this->normalizeWebhookUrls( $target['id'] ?? '' );
		if ( empty( $webhook_urls ) ) {
			return $this->failure( 'target.id is required for webhook agent_call targets' );
		}

		$context          = is_array( $call['context'] ?? null ) ? $call['context'] : array();
		$transport_auth   = is_array( $target['auth'] ?? null ) ? $target['auth'] : array();
		$auth_header_name = (string) ( $transport_auth['header_name'] ?? '' );
		$auth_token       = (string) ( $transport_auth['token'] ?? '' );
		$timeout          = max( 1, (int) ( $delivery['timeout'] ?? 30 ) );
		$results          = array();
		$errors           = array();

		foreach ( $webhook_urls as $webhook_url ) {
			$result    = $this->sendWebhook(
				$webhook_url,
				(string) ( $call['task'] ?? '' ),
				$context,
				(string) ( $delivery['reply_to'] ?? '' ),
				$auth_header_name,
				$auth_token,
				$timeout
			);
			$results[] = $result;

			if ( ! $result['success'] ) {
				$errors[] = $this->sanitizeUrlForLog( $webhook_url ) . ': ' . ( $result['error'] ?? 'Unknown error' );
			}
		}

		if ( empty( $errors ) ) {
			return array(
				'status'        => 'pending',
				'output'        => array( 'results' => $results ),
				'messages'      => array(),
				'remote_run_id' => '',
				'resume_token'  => '',
			);
		}

		return $this->failure( implode( '; ', $errors ), array( 'results' => $results ) );
	}

	/**
	 * Normalize one URL, newline-separated URLs, or URL arrays.
	 *
	 * @param mixed $webhook_urls_input Target ID value.
	 * @return array<int, string>
	 */
	private function normalizeWebhookUrls( $webhook_urls_input ): array {
		if ( is_array( $webhook_urls_input ) ) {
			return array_values( array_filter( array_map( 'trim', $webhook_urls_input ) ) );
		}

		return array_values( array_filter(
			array_map( 'trim', preg_split( '/[\r\n]+/', trim( (string) $webhook_urls_input ) ) )
		) );
	}

	/**
	 * Send one webhook call.
	 *
	 * @return array Webhook result.
	 */
	private function sendWebhook( string $url, string $task, array $context, string $reply_to, string $auth_header_name, string $auth_token, int $timeout ): array {
		$payload = $this->buildWebhookPayload( $url, $task, $context, $reply_to );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $auth_header_name && '' !== $auth_token ) {
			$headers[ $auth_header_name ] = $auth_token;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			do_action( 'datamachine_log', 'error', 'Agent call webhook request failed', array( 'url' => $this->sanitizeUrlForLog( $url ), 'error' => $response->get_error_message() ) );
			return array( 'success' => false, 'url' => $this->sanitizeUrlForLog( $url ), 'error' => $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			do_action( 'datamachine_log', 'info', 'Agent call webhook sent successfully', array( 'url' => $this->sanitizeUrlForLog( $url ), 'status_code' => $status_code ) );
			return array( 'success' => true, 'url' => $this->sanitizeUrlForLog( $url ), 'status_code' => $status_code );
		}

		do_action( 'datamachine_log', 'warning', 'Agent call webhook received non-success response', array( 'url' => $this->sanitizeUrlForLog( $url ), 'status_code' => $status_code, 'response_body' => wp_remote_retrieve_body( $response ) ) );
		return array( 'success' => false, 'url' => $this->sanitizeUrlForLog( $url ), 'status_code' => $status_code, 'error' => 'Webhook returned non-success status code: ' . $status_code );
	}

	/**
	 * Build webhook payload.
	 */
	private function buildWebhookPayload( string $url, string $task, array $context, string $reply_to ): array {
		if ( $this->isDiscordWebhook( $url ) ) {
			$first_packet = $context['data_packets'][0] ?? array();
			$title        = $first_packet['content']['title'] ?? 'New content';
			$packet_url   = $first_packet['metadata']['url'] ?? $first_packet['metadata']['permalink'] ?? '';
			$source       = ! empty( $context['from_queue'] ) ? '📋' : '🤖';
			$content      = "{$source} **{$title}**";
			if ( ! empty( $packet_url ) ) {
				$content .= "\n{$packet_url}";
			}
			if ( '' !== $task ) {
				$content .= "\n\n{$task}";
			}

			return array( 'content' => $content );
		}

		$engine_data = is_array( $context['engine_data'] ?? null ) ? $context['engine_data'] : array();
		$payload     = array(
			'task'      => $task,
			'messages'  => array(),
			'context'   => array_merge(
				$context,
				array(
					'post_id'       => $engine_data['post_id'] ?? null,
					'post_type'     => $engine_data['post_type'] ?? null,
					'published_url' => $engine_data['published_url'] ?? null,
					'site_url'      => site_url(),
					'wp_path'       => ABSPATH,
				)
			),
			'timestamp' => gmdate( 'c' ),
		);

		if ( '' !== $reply_to ) {
			$payload['reply_to'] = $reply_to;
		}

		return $payload;
	}

	private function isDiscordWebhook( string $url ): bool {
		return str_contains( $url, 'discord.com/api/webhooks/' )
			|| str_contains( $url, 'discordapp.com/api/webhooks/' );
	}

	private function sanitizeUrlForLog( string $url ): string {
		return preg_replace( '#(discord(?:app)?\.com/api/webhooks/\d+/)[^/\s]+#', '$1[MASKED]', $url );
	}

	private function failure( string $error, array $output = array() ): array {
		return array(
			'status'        => 'failed',
			'output'        => $output,
			'messages'      => array(),
			'remote_run_id' => '',
			'resume_token'  => '',
			'error'         => $error,
		);
	}
}
