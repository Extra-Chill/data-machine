<?php
/**
 * Slack authentication provider.
 *
 * Uses Bot Token authentication (xoxb-...) rather than OAuth2 user flow.
 * The bot token is long-lived and revoked manually via Slack app settings.
 *
 * @package DataMachineBusiness
 * @subpackage OAuth\Providers
 * @since 0.2.0
 */

namespace DataMachineBusiness\OAuth\Providers;

use DataMachine\Core\HttpClient;
use DataMachine\Core\OAuth\BaseAuthProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlackAuth extends BaseAuthProvider {

	public function __construct() {
		parent::__construct( 'slack' );
	}

	/**
	 * Get configuration fields for Slack authentication.
	 *
	 * @return array Configuration field definitions
	 */
	public function get_config_fields(): array {
		return array(
			'bot_token' => array(
				'label'       => __( 'Bot OAuth Token', 'data-machine-business' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Slack Bot OAuth Token (starts with xoxb-). Create a Slack App, add bot token scopes, and install to workspace.', 'data-machine-business' ),
			),
		);
	}

	/**
	 * Check if Slack is properly configured (bot token is set).
	 *
	 * @return bool True if bot token exists
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config['bot_token'] ) && str_starts_with( $config['bot_token'], 'xoxb-' );
	}

	/**
	 * Check if Slack is authenticated (bot token configured and validated).
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		return ! empty( $account ) &&
			is_array( $account ) &&
			! empty( $account['bot_id'] ) &&
			! empty( $account['team_id'] );
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
	 * Validate the bot token by calling auth.test.
	 *
	 * @return array|\WP_Error Account data on success, WP_Error on failure
	 */
	public function validate_token() {
		$token = $this->get_bot_token();

		if ( empty( $token ) ) {
			return new \WP_Error( 'slack_missing_token', __( 'Slack bot token is not configured.', 'data-machine-business' ) );
		}

		$result = HttpClient::post(
			'https://slack.com/api/auth.test',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(),
				'context' => 'Slack Auth',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'slack_connection_error', __( 'Failed to connect to Slack API.', 'data-machine-business' ) );
		}

		$body = json_decode( $result['data'], true );

		if ( empty( $body['ok'] ) ) {
			$error = $body['error'] ?? 'unknown_error';
			return new \WP_Error( 'slack_auth_failed', sprintf( __( 'Slack authentication failed: %s', 'data-machine-business' ), $error ) );
		}

		$account_data = array(
			'bot_id'     => $body['user_id'] ?? '',
			'bot_name'   => $body['user'] ?? '',
			'team_id'    => $body['team_id'] ?? '',
			'team_name'  => $body['team'] ?? '',
			'url'        => $body['url'] ?? '',
			'verified_at' => time(),
		);

		$this->save_account( $account_data );

		do_action(
			'datamachine_log',
			'info',
			'Slack: Bot token validated successfully.',
			array(
				'team' => $account_data['team_name'],
				'bot'  => $account_data['bot_name'],
			)
		);

		return $account_data;
	}

	/**
	 * Make an authenticated API request to Slack.
	 *
	 * @param string $method  API method (e.g., 'chat.postMessage')
	 * @param array  $params  Request parameters
	 * @param string $http_method HTTP method (GET or POST)
	 * @return array|\WP_Error Decoded response body or WP_Error
	 */
	public function api_request( string $method, array $params = array(), string $http_method = 'POST' ) {
		$token = $this->get_bot_token();

		if ( empty( $token ) ) {
			return new \WP_Error( 'slack_missing_token', __( 'Slack bot token is not configured.', 'data-machine-business' ) );
		}

		$url      = 'https://slack.com/api/' . $method;
		$headers  = array(
			'Authorization' => 'Bearer ' . $token,
		);
		$args     = array(
			'timeout' => 30,
			'headers' => $http_method === 'POST'
				? array_merge( $headers, array( 'Content-Type' => 'application/json; charset=utf-8' ) )
				: $headers,
			'context' => 'Slack API: ' . $method,
		);

		if ( $http_method === 'POST' ) {
			$args['body'] = wp_json_encode( $params );
			$result       = HttpClient::post( $url, $args );
		} else {
			if ( ! empty( $params ) ) {
				$url .= '?' . http_build_query( $params );
			}
			$result = HttpClient::get( $url, $args );
		}

		if ( ! $result['success'] ) {
			return new \WP_Error( 'slack_api_error', sprintf( __( 'Slack API request failed: %s', 'data-machine-business' ), $result['error'] ?? 'Unknown error' ) );
		}

		$body = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'slack_invalid_response', __( 'Invalid JSON response from Slack API.', 'data-machine-business' ) );
		}

		if ( empty( $body['ok'] ) ) {
			$error        = $body['error'] ?? 'unknown_error';
			$error_message = $body['error'] ?? 'Unknown error';

			// ratelimited includes a retry-after header
			if ( 'ratelimited' === $error && ! empty( $body['response_metadata']['retryAfter'] ) ) {
				$error_message .= ' (retry after ' . $body['response_metadata']['retryAfter'] . 's)';
			}

			return new \WP_Error( 'slack_api_' . $error, $error_message );
		}

		return $body;
	}

	/**
	 * Get stored Slack account details.
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['bot_id'] ) ) {
			return null;
		}

		return array(
			'bot_name'  => $account['bot_name'] ?? '',
			'team_name' => $account['team_name'] ?? '',
			'url'       => $account['url'] ?? '',
		);
	}

	/**
	 * Remove stored Slack account details.
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
