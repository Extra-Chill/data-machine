<?php
/**
 * Fetch Messages from Discord Ability
 *
 * Fetches recent messages from Discord channels using the bot token.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Abilities\Discord;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchMessagesDiscordAbility {

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
				'datamachine/fetch-messages-discord',
				array(
					'label'            => __( 'Fetch Discord Messages', 'data-machine-business' ),
					'description'      => __( 'Fetch recent messages from a Discord channel', 'data-machine-business' ),
					'category'         => 'datamachine',
					'input_schema'     => array(
						'type'       => 'object',
						'required'   => array( 'channel_id' ),
						'properties' => array(
							'channel_id' => array(
								'type'        => 'string',
								'description' => __( 'Discord channel ID (e.g. 123456789012345678)', 'data-machine-business' ),
							),
							'limit'      => array(
								'type'        => 'integer',
								'default'     => 50,
								'description' => __( 'Maximum number of messages to fetch (1-100)', 'data-machine-business' ),
							),
							'before'     => array(
								'type'        => 'string',
								'description' => __( 'Fetch messages before this message ID (for pagination)', 'data-machine-business' ),
							),
							'after'      => array(
								'type'        => 'string',
								'description' => __( 'Fetch messages after this message ID (for pagination)', 'data-machine-business' ),
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
		$logs       = array();
		$channel_id = $input['channel_id'] ?? '';

		if ( empty( $channel_id ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Channel ID is required.' );
			return array(
				'success' => false,
				'error'   => 'Channel ID is required',
				'logs'    => $logs,
			);
		}

		$auth_provider = $this->get_auth_provider();
		if ( ! $auth_provider ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Discord authentication not configured.' );
			return array(
				'success' => false,
				'error'   => 'Discord authentication not configured',
				'logs'    => $logs,
			);
		}

		$params = array(
			'limit' => min( max( intval( $input['limit'] ?? 50 ), 1 ), 100 ),
		);

		if ( ! empty( $input['before'] ) ) {
			$params['before'] = $input['before'];
		}

		if ( ! empty( $input['after'] ) ) {
			$params['after'] = $input['after'];
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Fetching messages from Discord.',
			'data'    => array(
				'channel_id' => $channel_id,
				'limit'      => $params['limit'],
			),
		);

		$endpoint = 'channels/' . $channel_id . '/messages';
		$response = $auth_provider->api_request( $endpoint, $params, 'GET' );

		if ( is_wp_error( $response ) ) {
			$logs[] = array( 'level' => 'error', 'message' => $response->get_error_message() );
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'logs'    => $logs,
			);
		}

		// Discord returns messages as an array (newest first)
		$messages = is_array( $response ) ? $response : array();

		// Strip to essential fields for cleaner output
		$clean_messages = array_map( function ( $msg ) {
			return array(
				'id'        => $msg['id'] ?? '',
				'channel_id' => $msg['channel_id'] ?? '',
				'author'    => array(
					'id'       => $msg['author']['id'] ?? '',
					'username' => $msg['author']['username'] ?? '',
				),
				'content'   => $msg['content'] ?? '',
				'timestamp' => $msg['timestamp'] ?? '',
				'type'      => $msg['type'] ?? 0,
			);
		}, $messages );

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Fetched messages from Discord.',
			'data'    => array(
				'count' => count( $clean_messages ),
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'messages'   => $clean_messages,
				'channel_id' => $channel_id,
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Get the Discord auth provider instance.
	 *
	 * @return \DataMachineBusiness\OAuth\Providers\DiscordAuth|null
	 */
	private function get_auth_provider(): ?\DataMachineBusiness\OAuth\Providers\DiscordAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		return $providers['discord'] ?? null;
	}
}
