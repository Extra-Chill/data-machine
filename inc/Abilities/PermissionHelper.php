<?php
/**
 * Permission helper for Data Machine abilities.
 *
 * @package DataMachine\Abilities
 * @since 0.20.4
 */

namespace DataMachine\Abilities;

/**
 * Helper class for ability permission checks.
 *
 * Centralizes permission logic to handle:
 * - WP-CLI commands
 * - Action Scheduler background processing
 * - Pre-authenticated contexts (webhook tokens, API keys)
 * - Standard user capability checks
 *
 * @see https://github.com/Extra-Chill/data-machine/issues/346
 */
class PermissionHelper {

	/**
	 * Data Machine capability map.
	 *
	 * @since 0.37.0
	 * @var array<string, string>
	 */
	private const CAPABILITY_MAP = array(
		'manage_agents'    => 'datamachine_manage_agents',
		'manage_flows'     => 'datamachine_manage_flows',
		'manage_settings'  => 'datamachine_manage_settings',
		'chat'             => 'datamachine_chat',
		'use_tools'        => 'datamachine_use_tools',
		'view_logs'        => 'datamachine_view_logs',
		'create_own_agent' => 'datamachine_create_own_agent',
	);

	/**
	 * Whether the current execution context has been pre-authenticated.
	 *
	 * When true, can_manage() returns true without checking WordPress
	 * capabilities. This allows callers that have already authenticated
	 * via alternative mechanisms (Bearer tokens, API keys, etc.) to
	 * execute abilities through the standard wp_get_ability() path
	 * instead of bypassing the Abilities API entirely.
	 *
	 * @since 0.31.0
	 *
	 * @var bool
	 */
	private static bool $authenticated_context = false;

	/**
	 * Acting user ID for pre-authenticated contexts.
	 *
	 * @since 0.37.0
	 * @var int
	 */
	private static int $authenticated_user_id = 0;

	/**
	 * Acting agent ID when authenticated via agent bearer token.
	 *
	 * @since 0.47.0
	 * @var int|null
	 */
	private static ?int $acting_agent_id = null;

	/**
	 * Owner user ID for the acting agent.
	 *
	 * @since 0.47.0
	 * @var int
	 */
	private static int $agent_owner_id = 0;

	/**
	 * Capability restrictions from the agent's bearer token.
	 * Null means unrestricted (all agent capabilities apply).
	 * Array means only these DM capabilities are allowed for this token.
	 *
	 * @since 0.47.0
	 * @var array|null
	 */
	private static ?array $agent_token_capabilities = null;

	/**
	 * Check if current context has admin-level permissions.
	 *
	 * Allows execution in:
	 * - WP-CLI context (command line)
	 * - Action Scheduler background processing (scheduled jobs)
	 * - Pre-authenticated context (set via run_as_authenticated())
	 * - Standard requests with logged-in admin user
	 *
	 * @since 0.20.4
	 *
	 * @return bool True if permission granted.
	 */
	public static function can_manage(): bool {
		return self::can( 'manage_flows' ) || self::can( 'manage_settings' ) || self::can( 'manage_agents' );
	}

	/**
	 * Check whether current context can perform an action.
	 *
	 * @since 0.37.0
	 *
	 * @param string $action Action key (manage_agents, manage_flows, manage_settings, chat, use_tools, view_logs).
	 * @return bool
	 */
	public static function can( string $action ): bool {
		// WP-CLI always allowed (filterable for testing).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( apply_filters( 'datamachine_cli_bypass_permissions', true ) ) {
				return true;
			}
		}

		// Action Scheduler background processing context.
		if ( doing_action( 'action_scheduler_run_queue' ) ) {
			return true;
		}

		// Agent context: token-based authentication with capability ceiling.
		if ( null !== self::$acting_agent_id ) {
			return self::agent_can( $action );
		}

		// Pre-authenticated context: evaluate acting user if provided.
		if ( self::$authenticated_context ) {
			if ( self::$authenticated_user_id > 0 ) {
				return self::user_can( self::$authenticated_user_id, $action );
			}

			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return self::user_can( get_current_user_id(), $action );
	}

	/**
	 * Get current acting user ID for permission-bounded execution.
	 *
	 * @since 0.37.0
	 *
	 * @return int
	 */
	public static function acting_user_id(): int {
		// Agent context: return the owner's user ID.
		if ( null !== self::$acting_agent_id && self::$agent_owner_id > 0 ) {
			return self::$agent_owner_id;
		}

		if ( self::$authenticated_context && self::$authenticated_user_id > 0 ) {
			return self::$authenticated_user_id;
		}

		return get_current_user_id();
	}

	/**
	 * Execute a callback within a pre-authenticated context.
	 *
	 * Sets the authenticated context flag before executing the callback,
	 * ensuring it is always reset afterward — even if an exception is thrown.
	 * This guarantees the elevated context never leaks beyond the callback scope.
	 *
	 * Usage:
	 *
	 *     $result = PermissionHelper::run_as_authenticated( function () use ( $input ) {
	 *         $ability = wp_get_ability( 'datamachine/execute-workflow' );
	 *         return $ability->execute( $input );
	 *     } );
	 *
	 * @since 0.31.0
	 *
	 * @param callable $callback The callback to execute in authenticated context.
	 * @return mixed The return value of the callback.
	 *
	 * @throws \Throwable Re-throws any exception from the callback after resetting context.
	 */
	public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ) {
		self::$authenticated_context = true;
		self::$authenticated_user_id = absint( $acting_user_id );

		try {
			$result = $callback();
		} finally {
			self::$authenticated_context = false;
			self::$authenticated_user_id = 0;
		}

		return $result;
	}

	/**
	 * Check whether the current context is pre-authenticated.
	 *
	 * Useful for logging and debugging to determine why a permission
	 * check passed.
	 *
	 * @since 0.31.0
	 *
	 * @return bool True if running in a pre-authenticated context.
	 */
	public static function is_authenticated_context(): bool {
		return self::$authenticated_context;
	}

	/**
	 * Set agent execution context.
	 *
	 * Called by AgentAuthMiddleware after resolving a bearer token.
	 * Sets the acting agent identity and optional capability restrictions.
	 *
	 * @since 0.47.0
	 *
	 * @param int        $agent_id     Agent ID.
	 * @param int        $owner_id     Agent owner's WordPress user ID.
	 * @param array|null $capabilities Token capability restrictions (null = unrestricted).
	 */
	public static function set_agent_context( int $agent_id, int $owner_id, ?array $capabilities = null ): void {
		self::$acting_agent_id          = $agent_id;
		self::$agent_owner_id           = $owner_id;
		self::$agent_token_capabilities = $capabilities;
	}

	/**
	 * Clear agent execution context.
	 *
	 * @since 0.47.0
	 */
	public static function clear_agent_context(): void {
		self::$acting_agent_id          = null;
		self::$agent_owner_id           = 0;
		self::$agent_token_capabilities = null;
	}

	/**
	 * Get the acting agent ID, if in agent context.
	 *
	 * @since 0.47.0
	 *
	 * @return int|null Agent ID or null if not in agent context.
	 */
	public static function get_acting_agent_id(): ?int {
		return self::$acting_agent_id;
	}

	/**
	 * Check if currently executing in an agent context.
	 *
	 * @since 0.47.0
	 *
	 * @return bool True if a bearer token resolved to an agent.
	 */
	public static function in_agent_context(): bool {
		return null !== self::$acting_agent_id;
	}

	/**
	 * Check if an agent can perform an action.
	 *
	 * Enforces the capability ceiling:
	 * 1. If token has capability restrictions, check the action is in the allowed list
	 * 2. Check the owner's WordPress capabilities (the ceiling — agent can never exceed)
	 *
	 * @since 0.47.0
	 *
	 * @param string $action Action key.
	 * @return bool
	 */
	private static function agent_can( string $action ): bool {
		// Check token-level capability restrictions.
		if ( null !== self::$agent_token_capabilities ) {
			$mapped_capability = self::CAPABILITY_MAP[ $action ] ?? null;

			if ( empty( $mapped_capability ) ) {
				return false;
			}

			// Token must explicitly include this capability.
			if ( ! in_array( $mapped_capability, self::$agent_token_capabilities, true ) ) {
				return false;
			}
		}

		// Ceiling check: owner must have this WordPress capability.
		if ( self::$agent_owner_id > 0 ) {
			return self::user_can( self::$agent_owner_id, $action );
		}

		return false;
	}

	/**
	 * Resolve user_id for scoped REST API queries.
	 *
	 * Determines whose data should be returned based on the request:
	 * - If `user_id` param is present and caller is admin → use that user_id
	 * - If caller is admin and no `user_id` param → return null (all users)
	 * - If caller is non-admin → always return their own user_id
	 *
	 * @since 0.40.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $action  Action key for admin check (default: 'manage_flows').
	 * @return int|null User ID to filter by, or null for all users (admin default).
	 */
	public static function resolve_scoped_user_id( \WP_REST_Request $request, string $action = 'manage_flows' ): ?int {
		$requested_user_id = $request->get_param( 'user_id' );
		$is_admin          = self::can( $action );

		// Admin with explicit user filter → scope to that user.
		if ( $is_admin && null !== $requested_user_id && '' !== $requested_user_id ) {
			return (int) $requested_user_id;
		}

		// Admin with no filter → all users.
		if ( $is_admin ) {
			return null;
		}

		// Non-admin → always their own data.
		return self::acting_user_id();
	}

	/**
	 * Check if the acting user owns a resource.
	 *
	 * Returns true if:
	 * - Resource has user_id 0 (single-agent mode, anyone can access)
	 * - Resource belongs to the acting user
	 * - Acting user is an admin (can manage any resource)
	 *
	 * @since 0.40.0
	 *
	 * @param int    $resource_user_id User ID on the resource record.
	 * @param string $action           Action key for admin check (default: 'manage_flows').
	 * @return bool True if the acting user can access this resource.
	 */
	public static function owns_resource( int $resource_user_id, string $action = 'manage_flows' ): bool {
		// Single-agent mode resources (user_id 0) are accessible to anyone with the capability.
		if ( 0 === $resource_user_id ) {
			return true;
		}

		// Admins can access any resource.
		if ( self::can( $action ) && ( self::is_authenticated_context() || current_user_can( 'manage_options' ) ) ) {
			return true;
		}

		// Owner check.
		return self::acting_user_id() === $resource_user_id;
	}

	/**
	 * Resolve agent_id for scoped REST API queries.
	 *
	 * Determines which agent's data should be returned based on the request:
	 * - If `agent_id` param is present and caller has access → use that agent_id
	 * - If caller is admin and no `agent_id` param → return null (all agents)
	 * - If caller is non-admin → resolve their accessible agent IDs
	 *
	 * @since 0.41.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $action  Action key for admin check (default: 'manage_flows').
	 * @return int|null Agent ID to filter by, or null for all agents (admin default).
	 */
	public static function resolve_scoped_agent_id( \WP_REST_Request $request, string $action = 'manage_flows' ): ?int {
		$requested_agent_id = $request->get_param( 'agent_id' );
		$is_admin           = self::can( $action );

		// Explicit agent_id parameter — use it if caller has access.
		if ( null !== $requested_agent_id && '' !== $requested_agent_id ) {
			$agent_id = (int) $requested_agent_id;

			// Admins can access any agent.
			if ( $is_admin ) {
				return $agent_id;
			}

			// Non-admin: verify they have access to this agent.
			if ( self::can_access_agent( $agent_id ) ) {
				return $agent_id;
			}

			// Fallback: try user_id-based scoping via resolve_scoped_user_id.
			return null;
		}

		// Admin with no filter → all agents.
		if ( $is_admin ) {
			return null;
		}

		// Non-admin with no explicit agent_id: resolve via owner_id lookup.
		$user_id     = self::acting_user_id();
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_by_owner_id( $user_id );

		if ( $agent ) {
			return (int) $agent['agent_id'];
		}

		// No agent found — return 0 which will match nothing (safe fallback).
		return 0;
	}

	/**
	 * Check if the acting user can access an agent.
	 *
	 * Returns true if:
	 * - User is an admin (manage_options or authenticated context)
	 * - User is the agent's owner
	 * - User has an explicit access grant via agent_access table
	 *
	 * @since 0.41.0
	 *
	 * @param int    $agent_id     Agent ID to check.
	 * @param string $minimum_role Minimum role required (default: 'viewer').
	 * @return bool True if the acting user can access this agent.
	 */
	public static function can_access_agent( int $agent_id, string $minimum_role = 'viewer' ): bool {
		// Admins can access any agent.
		if ( self::can( 'manage_agents' ) && ( self::is_authenticated_context() || current_user_can( 'manage_options' ) ) ) {
			return true;
		}

		$user_id = self::acting_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		// Check if user is the agent owner.
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( $agent && (int) $agent['owner_id'] === $user_id ) {
			return true;
		}

		// Check explicit access grants.
		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
		return $access_repo->user_can_access( $agent_id, $user_id, $minimum_role );
	}

	/**
	 * Check if the acting user owns a resource scoped by agent_id.
	 *
	 * Returns true if:
	 * - Resource has agent_id NULL (single-agent mode, anyone can access)
	 * - User can access the resource's agent
	 * - Fallback to user_id-based ownership check
	 *
	 * @since 0.41.0
	 *
	 * @param int|null $resource_agent_id Agent ID on the resource record (null = unscoped).
	 * @param int      $resource_user_id  User ID on the resource record.
	 * @param string   $action            Action key for admin check (default: 'manage_flows').
	 * @return bool True if the acting user can access this resource.
	 */
	public static function owns_agent_resource( ?int $resource_agent_id, int $resource_user_id, string $action = 'manage_flows' ): bool {
		// Unscoped resources (agent_id NULL) — fall back to user_id check.
		if ( null === $resource_agent_id ) {
			return self::owns_resource( $resource_user_id, $action );
		}

		// Agent-scoped — check agent access.
		return self::can_access_agent( $resource_agent_id );
	}

	/**
	 * Check capability for a specific user against Data Machine action.
	 *
	 * @since 0.37.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  Action key.
	 * @return bool
	 */
	private static function user_can( int $user_id, string $action ): bool {
		$mapped_capability = self::CAPABILITY_MAP[ $action ] ?? null;

		if ( empty( $mapped_capability ) ) {
			return false;
		}

		if ( user_can( $user_id, $mapped_capability ) ) {
			return true;
		}

		// Backward compatibility for legacy installs/roles.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return false;
	}
}
