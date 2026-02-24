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
		// WP-CLI always allowed.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// Action Scheduler background processing context.
		// This is needed because async requests may not have user cookies.
		if ( doing_action( 'action_scheduler_run_queue' ) ) {
			return true;
		}

		// Pre-authenticated context (e.g., webhook trigger with valid Bearer token).
		if ( self::$authenticated_context ) {
			return true;
		}

		// Standard capability check for logged-in users.
		return current_user_can( 'manage_options' );
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
	public static function run_as_authenticated( callable $callback ) {
		self::$authenticated_context = true;

		try {
			$result = $callback();
		} finally {
			self::$authenticated_context = false;
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
}
