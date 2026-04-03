<?php
/**
 * Discord authentication provider.
 *
 * Uses Bot Token authentication rather than OAuth2 user flow.
 * The bot token is long-lived and managed in the Discord Developer Portal.
 *
 * @package DataMachineBusiness
 * @subpackage OAuth\Providers
 * @since 0.3.0
 */

namespace DataMachineBusiness\OAuth\Providers;

use DataMachine\Core\HttpClient;
use DataMachine\Core\OAuth\BaseAuthProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscordAuth extends BaseAuthProvider {

	public function __construct() {
		parent::__construct( 'discord' );
	}

	/**
	 * Get configuration fields for Discord authentication.
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'bot_token' => array(
				'label'       => __( 'Bot Token', 'data-machine-business' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Discord Bot Token from the Developer Portal. Go to your application → Bot → Token.', 'data-machine-business' ),
			),
		);
	}

	/**
	 * Check if Discord is properly configured (bot token is set).
	 *
	 * @return bool True if bot token exists
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['bot_token'] );
	}

	/**
	 * Check if Discord is authenticated (bot token configured and validated).
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account ) &&
			is_array( $account ) &&
			! empty( $account['bot_id'] );
	}

	/**
	 * Get the bot token from config.
	 *
	 * @return string Bot token or empty string
	 */
	public function get_bot_token(): string {
		$config = $this->get_config();
		return $config['bot_token'] ?? '';
	}

	/**
	 * Validate the bot token by calling GET /users/@me.
	 *
	 * @return array|\WP_Error Account data on success, WP_Error on failure
	 */
	public function validate_token() {
		$token = $this->get_bot_token();

		if ( empty( $token ) ) {
			return new \WP_Error( 'discord_missing_token', __( 'Discord bot token is not configured.', 'data-machine-business' ) );
		}

		$result = HttpClient::get(
			'https://discord.com/api/v10/users/@me',
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $token,
					'Content-Type'  => 'application/json',
				),
				'context' => 'Discord Auth',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'discord_connection_error', __( 'Failed to connect to Discord API.', 'data-machine-business' ) );
		}

		$body = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'discord_invalid_response', __( 'Invalid response from Discord API.', 'data-machine-business' ) );
		}

		if ( ! empty( $body['code'] ) || ! empty( $body['message'] ) ) {
			$error = $body['message'] ?? 'unknown_error';
			return new \WP_Error( 'discord_auth_failed', sprintf( __( 'Discord authentication failed: %s', 'data-machine-business' ), $error ) );
		}

		$account_data = array(
			'bot_id'      => $body['id'] ?? '',
			'bot_name'    => $body['username'] ?? '',
			'discriminator' => $body['discriminator'] ?? '',
			'verified_at' => time(),
		);

		$this->save_account( $account_data );

		do_action(
			'datamachine_log',
			'info',
			'Discord: Bot token validated successfully.',
			array(
				'bot' => $account_data['bot_name'],
			)
		);

		return $account_data;
	}

	/**
	 * Make an authenticated API request to Discord.
	 *
	 * @param string $endpoint     API endpoint (e.g., 'channels/123/messages')
	 * @param array  $params       Request parameters (body for POST, query for GET)
	 * @param string $http_method  HTTP method (GET or POST)
	 * @return array|\WP_Error Decoded response body or WP_Error
	 */
	public function api_request( string $endpoint, array $params = array(), string $http_method = 'GET' ) {
		$token = $this->get_bot_token();

		if ( empty( $token ) ) {
			return new \WP_Error( 'discord_missing_token', __( 'Discord bot token is not configured.', 'data-machine-business' ) );
		}

		$url     = 'https://discord.com/api/v10/' . $endpoint;
		$headers = array(
			'Authorization' => 'Bot ' . $token,
			'Content-Type'  => 'application/json',
		);

		if ( $http_method === 'POST' ) {
			$args = array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $params ),
				'context' => 'Discord API: POST /' . $endpoint,
			);
			$result = HttpClient::post( $url, $args );
		} else {
			if ( ! empty( $params ) ) {
				$url .= '?' . http_build_query( $params );
			}
			$args = array(
				'timeout' => 30,
				'headers' => $headers,
				'context' => 'Discord API: GET /' . $endpoint,
			);
			$result = HttpClient::get( $url, $args );
		}

		if ( ! $result['success'] ) {
			return new \WP_Error( 'discord_api_error', sprintf( __( 'Discord API request failed: %s', 'data-machine-business' ), $result['error'] ?? 'Unknown error' ) );
		}

		$body = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'discord_invalid_response', __( 'Invalid JSON response from Discord API.', 'data-machine-business' ) );
		}

		// Discord returns error objects with 'code' and 'message' fields
		if ( ! empty( $body['code'] ) && ! empty( $body['message'] ) ) {
			$error_message = $body['message'];

			// Rate limit handling — Discord returns 429 with retry_after
			if ( 429 === intval( $body['code'] ) && ! empty( $body['retry_after'] ) ) {
				$error_message .= ' (retry after ' . $body['retry_after'] . 'ms)';
			}

			return new \WP_Error( 'discord_api_' . $body['code'], $error_message );
		}

		return $body;
	}

	/**
	 * Get stored Discord account details.
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['bot_id'] ) ) {
			return null;
		}

		return array(
			'bot_name' => $account['bot_name'] ?? '',
		);
	}

	/**
	 * Remove stored Discord account details.
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
