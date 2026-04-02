<?php
/**
 * Authenticate Handler Tool
 *
 * Chat tool for managing authentication flows via natural language.
 * Allows listing status, configuring credentials, and retrieving OAuth URLs.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.6.1
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

/**
 * Authenticate Handler Tool
 */
class AuthenticateHandler extends BaseTool {

	public function __construct() {
		$this->registerTool( 'authenticate_handler', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildDescription(),
			'parameters'  => array(
				'action'       => array(
					'type'        => 'string',
					'required'    => true,
					'enum'        => array( 'list', 'status', 'configure', 'get_oauth_url', 'disconnect' ),
					'description' => 'Action to perform: list (all statuses), status (specific handler), configure (save credentials), get_oauth_url (for OAuth), disconnect (clear auth)',
				),
				'handler_slug' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Handler identifier (required for all actions except list)',
				),
				'credentials'  => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Credentials object for configure action. For OAuth: {client_id, client_secret}. For simple auth: handler-specific fields.',
				),
			),
		);
	}

	/**
	 * Build tool description.
	 *
	 * @return string Tool description
	 */
	private function buildDescription(): string {
		return 'Manage authentication for handlers.
ACTIONS:
- list: List all handlers requiring authentication and their status.
- status: Get detailed status and configuration requirements for a specific handler.
- configure: Save credentials (OAuth keys or simple auth user/pass). SECURITY WARNING: Credentials provided here are visible in chat logs.
- get_oauth_url: Get the authorization URL for OAuth providers (Twitter, Facebook, Google, etc.).
- disconnect: Remove authentication and credentials for a handler.';
	}

	/**
	 * Execute tool logic.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def   Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action       = $parameters['action'] ?? '';
		$handler_slug = $parameters['handler_slug'] ?? '';

		if ( empty( $action ) ) {
			return $this->error( 'Action parameter is required' );
		}

		// List action doesn't require handler_slug
		if ( 'list' === $action ) {
			return $this->handleList();
		}

		if ( empty( $handler_slug ) ) {
			return $this->error( 'Handler slug is required for this action' );
		}

		switch ( $action ) {
			case 'status':
				return $this->handleStatus( $handler_slug );
			case 'configure':
				$credentials = $parameters['credentials'] ?? array();
				if ( empty( $credentials ) ) {
					return $this->error( 'Credentials object is required for configuration' );
				}
				return $this->handleConfigure( $handler_slug, $credentials );
			case 'get_oauth_url':
				return $this->handleGetOAuthUrl( $handler_slug );
			case 'disconnect':
				return $this->handleDisconnect( $handler_slug );
			default:
				return $this->error( "Invalid action: $action" );
		}
	}

	/**
	 * List all handlers requiring auth.
	 */
	private function handleList(): array {
		$handlers_ability = wp_get_ability( 'datamachine/get-handlers' );
		if ( ! $handlers_ability ) {
			return $this->error( 'Get handlers ability not available' );
		}

		$handlers_result = $handlers_ability->execute( array() );
		if ( ! ( $handlers_result['success'] ?? false ) ) {
			return $this->error( $handlers_result['error'] ?? 'Failed to get handlers' );
		}

		$all_handlers = $handlers_result['handlers'] ?? array();
		$result       = array();

		$auth_status_ability = wp_get_ability( 'datamachine/get-auth-status' );

		foreach ( $all_handlers as $slug => $handler ) {
			if ( empty( $handler['requires_auth'] ) ) {
				continue;
			}

			$is_authenticated = false;
			$auth_type        = 'unknown';

			if ( $auth_status_ability ) {
				$status_result = $auth_status_ability->execute( array( 'handler_slug' => $slug ) );
				if ( $status_result['success'] ?? false ) {
					$is_authenticated = ! empty( $status_result['oauth_url'] ) || ( $status_result['authenticated'] ?? false );
					$auth_type        = ! empty( $status_result['oauth_url'] ) ? 'oauth' : 'simple';
				}
			}

			$info = array(
				'slug'             => $slug,
				'name'             => $handler['label'] ?? $slug,
				'auth_type'        => $auth_type,
				'is_authenticated' => $is_authenticated,
			);

			$result[] = $info;
		}

		return $this->success( array( 'handlers' => $result ) );
	}

	/**
	 * Get detailed status for a handler.
	 */
	private function handleStatus( string $slug ): array {
		$ability = wp_get_ability( 'datamachine/get-auth-status' );
		if ( ! $ability ) {
			return $this->error( 'Get auth status ability not available' );
		}

		$result = $ability->execute( array( 'handler_slug' => $slug ) );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return $this->error( $result['error'] ?? "Auth provider not found for: $slug" );
		}

		$response = array(
			'slug'             => $slug,
			'handler_slug'     => $result['handler_slug'] ?? $slug,
			'requires_auth'    => $result['requires_auth'] ?? true,
			'is_authenticated' => ! empty( $result['oauth_url'] ) || ( $result['authenticated'] ?? false ),
		);

		if ( ! empty( $result['oauth_url'] ) ) {
			$response['oauth_url']    = $result['oauth_url'];
			$response['instructions'] = $result['instructions'] ?? '';
		}

		if ( ! empty( $result['message'] ) ) {
			$response['message'] = $result['message'];
		}

		return $this->success( $response );
	}

	/**
	 * Configure credentials.
	 */
	private function handleConfigure( string $slug, array $credentials ): array {
		$ability = wp_get_ability( 'datamachine/save-auth-config' );
		if ( ! $ability ) {
			return $this->error( 'Save auth config ability not available' );
		}

		$result = $ability->execute(
			array(
				'handler_slug' => $slug,
				'config'       => $credentials,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return $this->error( $result['error'] ?? 'Failed to save configuration.' );
		}

		return $this->success(
			array(
				'message'   => $result['message'] ?? 'Configuration saved successfully.',
				'next_step' => "Use 'get_oauth_url' to authorize if this is an OAuth provider.",
			)
		);
	}

	/**
	 * Get OAuth URL.
	 */
	private function handleGetOAuthUrl( string $slug ): array {
		$ability = wp_get_ability( 'datamachine/get-auth-status' );
		if ( ! $ability ) {
			return $this->error( 'Get auth status ability not available' );
		}

		$result = $ability->execute( array( 'handler_slug' => $slug ) );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return $this->error( $result['error'] ?? "Auth provider not found for: $slug" );
		}

		if ( empty( $result['oauth_url'] ) ) {
			return $this->error( 'Handler does not support OAuth or credentials not configured.' );
		}

		return $this->success(
			array(
				'oauth_url'    => $result['oauth_url'],
				'instructions' => $result['instructions'] ?? 'Visit this URL to authorize. You will be redirected back to Data Machine.',
			)
		);
	}

	/**
	 * Disconnect account.
	 */
	private function handleDisconnect( string $slug ): array {
		$ability = wp_get_ability( 'datamachine/disconnect-auth' );
		if ( ! $ability ) {
			return $this->error( 'Disconnect auth ability not available' );
		}

		$result = $ability->execute( array( 'handler_slug' => $slug ) );

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		if ( ! ( $result['success'] ?? false ) ) {
			return $this->error( $result['error'] ?? 'Failed to disconnect or not supported.' );
		}

		return $this->success( array( 'message' => $result['message'] ?? 'Disconnected successfully.' ) );
	}

	private function success( array $data ): array {
		return array(
			'success'   => true,
			'tool_name' => 'authenticate_handler',
		) + $data;
	}

	private function error( string $msg ): array {
		return array(
			'success'   => false,
			'error'     => $msg,
			'tool_name' => 'authenticate_handler',
		);
	}
}
