<?php
/**
 * Post Message to Discord Ability
 *
 * Posts messages to Discord channels using the bot token.
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Abilities\Discord;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class PostMessageDiscordAbility {

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
				'datamachine/post-message-discord',
				array(
					'label'            => __( 'Post Message to Discord', 'data-machine-business' ),
					'description'      => __( 'Post a message to a Discord channel', 'data-machine-business' ),
					'category'         => 'datamachine',
					'input_schema'     => array(
						'type'       => 'object',
						'required'   => array( 'channel_id', 'content' ),
						'properties' => array(
							'channel_id' => array(
								'type'        => 'string',
								'description' => __( 'Discord channel ID (e.g. 123456789012345678)', 'data-machine-business' ),
							),
							'content'    => array(
								'type'        => 'string',
								'description' => __( 'Message text content', 'data-machine-business' ),
							),
							'embed'      => array(
								'type'        => 'object',
								'description' => __( 'Discord embed object for rich formatting', 'data-machine-business' ),
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
		$content    = $input['content'] ?? '';

		if ( empty( $channel_id ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Channel ID is required.' );
			return array(
				'success' => false,
				'error'   => 'Channel ID is required',
				'logs'    => $logs,
			);
		}

		if ( empty( $content ) && empty( $input['embed'] ) ) {
			$logs[] = array( 'level' => 'error', 'message' => 'Message content or embed is required.' );
			return array(
				'success' => false,
				'error'   => 'Message content or embed is required',
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

		$params = array();

		if ( ! empty( $content ) ) {
			$params['content'] = $content;
		}

		if ( ! empty( $input['embed'] ) ) {
			$params['embeds'] = array(
				is_string( $input['embed'] )
					? json_decode( $input['embed'], true )
					: $input['embed'],
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Posting message to Discord.',
			'data'    => array(
				'channel_id' => $channel_id,
			),
		);

		$endpoint = 'channels/' . $channel_id . '/messages';
		$response = $auth_provider->api_request( $endpoint, $params, 'POST' );

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
				'message_id' => $response['id'] ?? '',
				'channel_id' => $response['channel_id'] ?? $channel_id,
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'message_id' => $response['id'] ?? '',
				'channel_id' => $response['channel_id'] ?? $channel_id,
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
