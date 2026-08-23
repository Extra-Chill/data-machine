<?php
/**
 * Canonical authorization policy for social publishing.
 *
 * @package DataMachineSocials
 */

namespace DataMachineSocials;

use DataMachine\Abilities\ExecutionScope;

defined( 'ABSPATH' ) || exit;

final class PublishAuthorization {

	/** Determine whether the current execution context may publish socially. */
	public static function can_publish(): bool {
		$scope = ExecutionScope::current( 'use_tools' );

		// Preserve Data Machine's trusted non-user execution semantics and agent ceilings.
		if (
			( defined( 'WP_CLI' ) && WP_CLI && $scope->can_action() )
			|| ( doing_action( 'action_scheduler_run_queue' ) && $scope->can_action() )
			|| $scope->is_authenticated_context()
			|| $scope->is_agent_context()
		) {
			return $scope->can_action();
		}

		$user_id = $scope->acting_user_id();
		$allowed = $user_id > 0 && user_can( $user_id, 'publish_posts' );

		/**
		 * Filter whether the acting user may publish to social accounts.
		 *
		 * @param bool   $allowed Whether the base capability check passed.
		 * @param string $action  The Socials action being gated.
		 * @param int    $user_id The acting WordPress user ID.
		 */
		return (bool) apply_filters( 'datamachine_socials_user_can', $allowed, 'publish', $user_id );
	}
}
