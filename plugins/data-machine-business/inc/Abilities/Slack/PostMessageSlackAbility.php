<?php
/**
 * Post Message to Slack Ability
 *
 * Posts messages to Slack channels using the bot token.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Abilities\Slack;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class PostMessageSlackAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/post-message-slack',
				array(
					'label'            => __( 'Post Message to Slack', 'data-machine-business' ),
					'description'      => __( 'Post a message to a Slack channel or DM', 'data-machine-business' ),
					'category'         => 'datamachine',
					'input_schema'     => array(
						'type'       => 'object',
						'required'   => array( 'channel', 'text' ),
						'properties' => array(
							'channel' => array(
								'type'        => 'string',
								'description' => __( 'Channel ID (e.g. C12345678) or channel name (e.g. #general)', 'data-machine-business' ),
							),
							'text'    => array(
								'type'        => 'string',
								'description' => __( 'Message text (plain text or mrkdwn)', 'data-machine-business' ),
							),
							'blocks'  => array(
								'type'        => 'array',
								'description' => __( 'Slack Block Kit blocks for rich formatting', 'data-machine-business' ),
							),
							'thread_ts' => array(
								'type'        => 'string',
								'description' => __( 'Parent message timestamp to reply in a thread', 'data-machine-business' ),
							),
							'unfurl_links' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Whether to unfurl URL-based links', 'data-machine-business' ),
							),
							'metadata' => array(
								'type'        => 'string',
								'description' => __( 'Optional metadata to attach to the message', 'data-machine-business' ),
							),
						),
					),
					'output_schema'    => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'data'     => array( 'type' => 'object' ),
							'error'    => array( 'type' => 'string' ),
							'logs'     => array( 'type' => 'array' ),
						),
					),
					'execute_callback' => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'             => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$logs    = array();
		$channel = $input['channel'] ?? '';
		$text    = $input['text'] ?? '';

		if ( empty( $channel ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Channel is required.' );
			return array(
				'success' => false,
				'error'   => 'Channel is required',
				'logs'    => $logs,
			);
		}

		if ( empty( $text ) && empty( $input['blocks'] ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Message text or blocks are required.' );
			return array(
				'success' => false,
				'error'   => 'Message text or blocks are required',
				'logs'    => $logs,
			);
		}

		$auth_provider = $this->get_auth_provider();
		if ( ! $auth_provider ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Slack authentication not configured.' );
			return array(
				'success' => false,
				'error'   => 'Slack authentication not configured',
				'logs'    => $logs,
			);
		}

		$params = array(
			'channel' => $channel,
			'text'    => $text,
		);

		if ( ! empty( $input['blocks'] ) ) {
			$params['blocks'] = is_string( $input['blocks'] )
				? json_decode( $input['blocks'], true )
				: $input['blocks'];
		}

		if ( ! empty( $input['thread_ts'] ) ) {
			$params['thread_ts'] = $input['thread_ts'];
		}

		if ( isset( $input['unfurl_links'] ) ) {
			$params['unfurl_links'] = (bool) $input['unfurl_links'];
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Posting message to Slack.',
			'data'    => array(
				'channel' => $channel,
			),
		);

		$response = $auth_provider->api_request( 'chat.postMessage', $params );

		if ( is_wp_error( $response ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $response->get_error_message() );
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Message posted successfully.',
			'data'    => array(
				'ts'      => $response['ts'] ?? '',
				'channel' => $response['channel'] ?? $channel,
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'message_ts' => $response['ts'] ?? '',
				'channel'    => $response['channel'] ?? $channel,
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Get the Slack auth provider instance.
	 *
	 * @return \DataMachineBusiness\OAuth\Providers\SlackAuth|null
	 */
	private function get_auth_provider(): ?\DataMachineBusiness\OAuth\Providers\SlackAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		return $providers['slack'] ?? null;
	}
}
