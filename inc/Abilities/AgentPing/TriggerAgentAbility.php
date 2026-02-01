<?php
/**
 * Trigger Agent Ability
 *
 * Triggers the OpenClaw agent via CLI command.
 * Runs asynchronously to avoid blocking pipeline execution.
 *
 * @package DataMachine\Abilities\AgentPing
 * @since 0.18.0
 */

namespace DataMachine\Abilities\AgentPing;

defined( 'ABSPATH' ) || exit;

class TriggerAgentAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/trigger-agent',
				array(
					'label'               => __( 'Trigger Agent', 'data-machine' ),
					'description'         => __( 'Trigger OpenClaw agent via CLI command.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'message' ),
						'properties' => array(
							'message'       => array(
								'type'        => 'string',
								'description' => __( 'Message to send to the agent', 'data-machine' ),
							),
							'channel'       => array(
								'type'        => 'string',
								'default'     => 'discord',
								'description' => __( 'Delivery channel (e.g., discord, slack)', 'data-machine' ),
							),
							'deliver'       => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to deliver the response to the channel', 'data-machine' ),
							),
							'openclaw_path' => array(
								'type'        => 'string',
								'default'     => 'openclaw',
								'description' => __( 'Path to the openclaw CLI binary', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
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
	 * Execute trigger agent ability.
	 *
	 * Runs the openclaw CLI command in the background to avoid blocking.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success status.
	 */
	public function execute( array $input ): array {
		$message       = $input['message'] ?? '';
		$channel       = $input['channel'] ?? 'discord';
		$deliver       = $input['deliver'] ?? true;
		$openclaw_path = $input['openclaw_path'] ?? 'openclaw';

		if ( empty( trim( $message ) ) ) {
			return array(
				'success' => false,
				'error'   => 'message is required',
			);
		}

		// Build the command.
		$command = $this->buildCommand( $openclaw_path, $channel, $message, $deliver );

		// Execute asynchronously (backgrounded).
		$result = $this->executeAsync( $command );

		if ( $result['success'] ) {
			do_action(
				'datamachine_log',
				'info',
				'OpenClaw agent triggered successfully',
				array(
					'channel' => $channel,
					'deliver' => $deliver,
				)
			);

			return array(
				'success' => true,
				'message' => 'Agent triggered successfully',
			);
		}

		do_action(
			'datamachine_log',
			'error',
			'Failed to trigger OpenClaw agent',
			array(
				'channel' => $channel,
				'error'   => $result['error'] ?? 'Unknown error',
			)
		);

		return array(
			'success' => false,
			'error'   => $result['error'] ?? 'Failed to execute command',
		);
	}

	/**
	 * Build the openclaw CLI command.
	 *
	 * @param string $openclaw_path Path to openclaw binary.
	 * @param string $channel Delivery channel.
	 * @param string $message Message to send.
	 * @param bool   $deliver Whether to deliver response.
	 * @return string Shell command.
	 */
	private function buildCommand( string $openclaw_path, string $channel, string $message, bool $deliver ): string {
		$escaped_path    = escapeshellcmd( $openclaw_path );
		$escaped_channel = escapeshellarg( $channel );
		$escaped_message = escapeshellarg( $message );

		$command = "{$escaped_path} agent --channel {$escaped_channel} --message {$escaped_message}";

		if ( $deliver ) {
			$command .= ' --deliver';
		}

		return $command;
	}

	/**
	 * Execute command asynchronously (backgrounded).
	 *
	 * @param string $command Command to execute.
	 * @return array Result with success status.
	 */
	private function executeAsync( string $command ): array {
		// Redirect output and run in background.
		$async_command = $command . ' > /dev/null 2>&1 &';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $async_command, $output, $return_code );

		// Since we're running async, return_code 0 means the background process started.
		// We can't know if the actual command succeeded.
		if ( 0 === $return_code ) {
			return array( 'success' => true );
		}

		return array(
			'success' => false,
			'error'   => 'Failed to start background process (exit code: ' . $return_code . ')',
		);
	}
}
