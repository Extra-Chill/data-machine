<?php
/**
 * Fetch Messages from Slack Ability
 *
 * Fetches recent messages from Slack channels using the bot token.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Abilities\Slack;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchMessagesSlackAbility {

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
				'datamachine/fetch-messages-slack',
				array(
					'label'            => __( 'Fetch Slack Messages', 'data-machine-business' ),
					'description'      => __( 'Fetch recent messages from a Slack channel', 'data-machine-business' ),
					'category'         => 'datamachine',
					'input_schema'     => array(
						'type'       => 'object',
						'required'   => array( 'channel' ),
						'properties' => array(
							'channel' => array(
								'type'        => 'string',
								'description' => __( 'Channel ID (e.g. C12345678)', 'data-machine-business' ),
							),
							'limit'  => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Maximum number of messages to fetch (1-1000)', 'data-machine-business' ),
							),
							'oldest' => array(
								'type'        => 'string',
								'description' => __( 'Only messages after this Unix timestamp', 'data-machine-business' ),
							),
							'latest' => array(
								'type'        => 'string',
								'description' => __( 'Only messages before this Unix timestamp', 'data-machine-business' ),
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

		if ( empty( $channel ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Channel ID is required.' );
			return array(
				'success' => false,
				'error'   => 'Channel ID is required',
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
			'limit'   => min( max( intval( $input['limit'] ?? 20 ), 1 ), 1000 ),
		);

		if ( ! empty( $input['oldest'] ) ) {
			$params['oldest'] = $input['oldest'];
		}

		if ( ! empty( $input['latest'] ) ) {
			$params['latest'] = $input['latest'];
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Fetching messages from Slack.',
			'data'    => array(
				'channel' => $channel,
				'limit'   => $params['limit'],
			),
		);

		$response = $auth_provider->api_request( 'conversations.history', $params, 'GET' );

		if ( is_wp_error( $response ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $response->get_error_message() );
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'logs'    => $logs,
			);
		}

		$messages  = $response['messages'] ?? array();
		$has_more  = $response['has_more'] ?? false;

		// Strip bot messages' bot_id and subtype for cleaner output, keep essential fields
		$clean_messages = array_map( function ( $msg ) {
			return array(
				'type'      => $msg['type'] ?? 'message',
				'user'      => $msg['user'] ?? '',
				'text'      => $msg['text'] ?? '',
				'ts'        => $msg['ts'] ?? '',
				'thread_ts' => $msg['thread_ts'] ?? null,
				'subtype'   => $msg['subtype'] ?? null,
			);
		}, $messages );

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Fetched messages from Slack.',
			'data'    => array(
				'count'    => count( $clean_messages ),
				'has_more' => $has_more,
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'messages' => $clean_messages,
				'has_more' => $has_more,
				'channel'  => $channel,
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
