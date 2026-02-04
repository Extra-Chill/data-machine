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
 * - Standard user capability checks
 */
class PermissionHelper {

	/**
	 * Check if current context has admin-level permissions.
	 *
	 * Allows execution in:
	 * - WP-CLI context (command line)
	 * - Action Scheduler background processing (scheduled jobs)
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

		// Standard capability check for logged-in users.
		return current_user_can( 'manage_options' );
	}
}
