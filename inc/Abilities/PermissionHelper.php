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
		'manage_agents'   => 'datamachine_manage_agents',
		'manage_flows'    => 'datamachine_manage_flows',
		'manage_settings' => 'datamachine_manage_settings',
		'chat'            => 'datamachine_chat',
		'use_tools'       => 'datamachine_use_tools',
		'view_logs'       => 'datamachine_view_logs',
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
