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
use DataMachine\Engine\AI\IterationBudgetRegistry;

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

		// Parse cross-site caller context from A2A headers (no-op for non-A2A requests).
		$request      = self::current_rest_request();
		$inbound_ctx  = $request !== null
			? CallerContext::fromRequest( $request )
			: new CallerContext();

		// Enforce chain_depth budget on the incoming call. Depth >= ceiling
		// means this call is the Nth+1 hop in a chain that has already
		// exhausted its budget — reject before running any work.
		$depth_budget = IterationBudgetRegistry::create( 'chain_depth', $inbound_ctx->chainDepth() );

		if ( $depth_budget->exceeded() ) {
			do_action(
				'datamachine_log',
				'warning',
				'Agent auth: chain depth exceeded',
				array_merge(
					array(
						'agent_id'   => $agent_id,
						'agent_slug' => $agent['agent_slug'],
						'budget'     => $depth_budget->name(),
						'ceiling'    => $depth_budget->ceiling(),
						'current'    => $depth_budget->current(),
					),
					$inbound_ctx->toLogContext()
				)
			);

			return new \WP_Error(
				'datamachine_chain_depth_exceeded',
				sprintf(
					/* translators: %d: max chain depth */
					__( 'A2A chain depth (%d) exceeded. Refusing to extend the chain further.', 'data-machine' ),
					$depth_budget->ceiling()
				),
				array(
					'status'      => 429,
					'retry_after' => 60,
					'chain_id'    => $inbound_ctx->chainId(),
					'chain_depth' => $inbound_ctx->chainDepth(),
					'ceiling'     => $depth_budget->ceiling(),
				)
			);
		}

		// Set WordPress current user to the owner.
		// This ensures all WordPress capability checks use the owner's role
		// (the ceiling — the agent can never exceed the owner's capabilities).
		wp_set_current_user( $owner_id );

		// Set agent execution context in PermissionHelper.
		// This adds the agent_id scoping layer and optional capability restrictions.
		$token_capabilities = $token_record['capabilities'] ?? null;
		PermissionHelper::set_agent_context( $agent_id, $owner_id, $token_capabilities, $token_id );

		// Expose the caller context for downstream code (ChatOrchestrator,
		// abilities, logging) that wants to know who's calling and where
		// in the chain this request lives.
		PermissionHelper::set_caller_context( $inbound_ctx );

		do_action(
			'datamachine_log',
			'debug',
			'Agent auth: token authenticated',
			array_merge(
				array(
					'agent_id'             => $agent_id,
					'agent_slug'           => $agent['agent_slug'],
					'owner_id'             => $owner_id,
					'token_id'             => $token_id,
					'token_label'          => $token_record['label'] ?? '',
					'has_cap_restrictions' => null !== $token_capabilities,
				),
				$inbound_ctx->toLogContext()
			)
		);

		return true;
	}

	/**
	 * Best-effort access to the current REST request for header parsing.
	 *
	 * The `rest_authentication_errors` filter runs before the dispatcher
	 * assigns the request to a handler, so WP_REST_Request isn't directly
	 * available here. Fall back to synthesizing one from $_SERVER so
	 * CallerContext can resolve headers consistently via the same API
	 * it uses in tests.
	 *
	 * @return \WP_REST_Request|null
	 */
	private static function current_rest_request(): ?\WP_REST_Request {
		if ( ! class_exists( '\\WP_REST_Request' ) ) {
			return null;
		}

		$request = new \WP_REST_Request();

		foreach ( $_SERVER as $key => $value ) {
			if ( ! is_string( $key ) || strpos( $key, 'HTTP_' ) !== 0 ) {
				continue;
			}
			$header_name = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
			$request->set_header( $header_name, (string) $value );
		}

		return $request;
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
