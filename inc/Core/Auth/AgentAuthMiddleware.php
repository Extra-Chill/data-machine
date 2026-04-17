<?php
/**
 * Agent Authentication Middleware
 *
 * REST API authentication handler that resolves Data Machine agent bearer
 * tokens into agent execution contexts. This is the service-to-service auth
 * layer — analogous to GitHub Personal Access Tokens or Anthropic API keys.
 *
 * Flow:
 * 1. Check Authorization header for Bearer datamachine_* prefix
 * 2. Hash token → lookup in datamachine_agent_tokens table
 * 3. Validate: token not expired, agent active, owner active
 * 4. Set WordPress current user to the agent's owner
 * 5. Set agent context in PermissionHelper (agent_id + capability ceiling)
 *
 * Only intercepts tokens with the datamachine_ prefix — all other auth
 * mechanisms (WordPress Application Passwords, cookie auth, other plugins)
 * pass through unmodified.
 *
 * @package DataMachine\Core\Auth
 * @since 0.47.0
 */

namespace DataMachine\Core\Auth;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentAuthMiddleware {

	/**
	 * Token prefix that identifies Data Machine agent tokens.
	 */
	const TOKEN_PREFIX = 'datamachine_';

	/**
	 * Register the authentication filter.
	 */
	public function __construct() {
		add_filter( 'rest_authentication_errors', array( $this, 'authenticate' ), 90 );
	}

	/**
	 * Authenticate incoming REST requests with Data Machine agent tokens.
	 *
	 * Hooks into rest_authentication_errors filter. Returns:
	 * - null: not our token, pass through to other auth handlers
	 * - true: successfully authenticated as an agent
	 * - WP_Error: our token but invalid (expired, agent inactive, etc.)
	 *
	 * @param \WP_Error|null|true $result Existing auth result from other handlers.
	 * @return \WP_Error|null|true
	 */
	public function authenticate( $result ) {
		// If another handler already authenticated or errored, don't interfere.
		if ( null !== $result ) {
			return $result;
		}

		// Extract bearer token from Authorization header.
		$raw_token = $this->extract_bearer_token();

		if ( null === $raw_token ) {
			return null; // No Authorization header or not Bearer — pass through.
		}

		// Only handle datamachine_ prefixed tokens.
		if ( ! str_starts_with( $raw_token, self::TOKEN_PREFIX ) ) {
			return null; // Not our token — pass through to WordPress/other auth.
		}

		// Resolve token hash against database.
		$tokens_repo  = new AgentTokens();
		$token_record = $tokens_repo->resolve_token( $raw_token );

		if ( ! $token_record ) {
			do_action(
				'datamachine_log',
				'warning',
				'Agent auth: invalid or expired token presented',
				array( 'token_prefix' => substr( $raw_token, 0, 20 ) . '...' )
			);

			return new \WP_Error(
				'datamachine_invalid_token',
				__( 'Invalid or expired agent token.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		$agent_id = (int) $token_record['agent_id'];
		$token_id = (int) $token_record['token_id'];

		// Verify agent exists.
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new \WP_Error(
				'datamachine_agent_not_found',
				__( 'Agent associated with this token no longer exists.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		$owner_id = (int) $agent['owner_id'];

		// Verify owner user still exists and is active.
		$owner = get_user_by( 'id', $owner_id );

		if ( ! $owner ) {
			return new \WP_Error(
				'datamachine_owner_not_found',
				__( 'Agent owner account no longer exists.', 'data-machine' ),
				array( 'status' => 401 )
			);
		}

		// Track token usage.
		$tokens_repo->touch_last_used( $token_id );

		// Set WordPress current user to the owner.
		// This ensures all WordPress capability checks use the owner's role
		// (the ceiling — the agent can never exceed the owner's capabilities).
		wp_set_current_user( $owner_id );

		// Set agent execution context in PermissionHelper.
		// This adds the agent_id scoping layer and optional capability restrictions.
		$token_capabilities = $token_record['capabilities'] ?? null;
		PermissionHelper::set_agent_context( $agent_id, $owner_id, $token_capabilities, $token_id );

		do_action(
			'datamachine_log',
			'debug',
			'Agent auth: token authenticated',
			array(
				'agent_id'             => $agent_id,
				'agent_slug'           => $agent['agent_slug'],
				'owner_id'             => $owner_id,
				'token_id'             => $token_id,
				'token_label'          => $token_record['label'] ?? '',
				'has_cap_restrictions' => null !== $token_capabilities,
			)
		);

		return true;
	}

	/**
	 * Extract bearer token from the Authorization header.
	 *
	 * @return string|null Raw token string or null if not present.
	 */
	private function extract_bearer_token(): ?string {
		// Try PHP globals first (most reliable).
		$auth_header = null;

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// Some hosts strip Authorization and set REDIRECT_HTTP_AUTHORIZATION.
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					$auth_header = sanitize_text_field( $value );
					break;
				}
			}
		}

		if ( empty( $auth_header ) ) {
			return null;
		}

		// Extract token from "Bearer <token>" format.
		if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
			$token = substr( $auth_header, 7 );
			return ! empty( $token ) ? $token : null;
		}

		return null;
	}
}
