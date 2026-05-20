<?php
/**
 * Ability Permissions Helper
 *
 * Shared permission callbacks for Data Machine Events abilities.
 *
 * Team-member contributors (extrachill_team=1 user meta) are trusted to use
 * write abilities even when their WP role does not grant manage_options /
 * edit_others_posts. The Roadie chat layer already treats them as authorized
 * via extrachill_roadie_team_access_bridge; this brings every underlying
 * ability into agreement.
 *
 * Permission shape (Option A from issue #288):
 *   1. If ec_is_team_member() returns true → allow.
 *   2. Else fall back to a capability check appropriate to the operation.
 *
 * WP-CLI is always allowed (matches the existing admin-tool convention used
 * by GeocodingAbilities, SettingsAbilities, VenueStatsAbilities).
 *
 * ec_is_team_member() is shipped by extrachill-users. Wrap with
 * function_exists() so this plugin still loads in contexts where that plugin
 * is absent (tests, isolated CLI runs).
 *
 * @package DataMachineEvents\Abilities
 * @since 0.39.0
 */

namespace DataMachineEvents\Abilities;

defined( 'ABSPATH' ) || exit;

class AbilityPermissions {

	/**
	 * Permission callback for write abilities (event/venue mutations,
	 * batch fixes, merges, geocoding sweeps, meta resyncs, etc).
	 *
	 * Admins pass via edit_others_posts (granted to administrators and
	 * editors by default). Team members pass via the override. WP-CLI
	 * always passes.
	 *
	 * @return \Closure
	 */
	public static function canWrite(): \Closure {
		return static function () {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}

			if ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() ) {
				return true;
			}

			return current_user_can( 'edit_others_posts' );
		};
	}

	/**
	 * Permission callback for diagnostic / admin-only read abilities
	 * that should still be gated but available to team members.
	 *
	 * Currently unused by default — read-only abilities in the audit
	 * (issue #288) were intentionally left on their existing gate. Provided
	 * here so future read abilities have a consistent shape if they want
	 * the same team-member override without opening up to all readers.
	 *
	 * @return \Closure
	 */
	public static function canRead(): \Closure {
		return static function () {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}

			if ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() ) {
				return true;
			}

			return current_user_can( 'edit_posts' );
		};
	}
}
