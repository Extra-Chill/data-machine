<?php
/**
 * Ability Permissions Helper
 *
 * Shared permission callbacks for Data Machine Events abilities.
 *
 * Permission shape:
 *   1. WP-CLI is always allowed (matches the existing admin-tool
 *      convention used by GeocodingAbilities, SettingsAbilities,
 *      VenueStatsAbilities).
 *   2. The capability the user must hold for the operation is
 *      filterable so platform consumers can elevate trust beyond the
 *      defaults (e.g. grant `edit_others_posts`-class privileges to a
 *      non-editor role via a custom capability they map themselves).
 *
 * Filters:
 *   - `datamachine_events_write_capability` — default
 *     `edit_others_posts`. Platforms can return their own capability
 *     to widen the trust pool for write operations.
 *   - `datamachine_events_read_capability` — default `edit_posts`.
 *     Same shape for the (currently unused) diagnostic read gate.
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
	 * Admins pass via `edit_others_posts` (granted to administrators
	 * and editors by default). WP-CLI always passes. Platform consumers
	 * can elevate additional roles by filtering the capability name.
	 *
	 * @return \Closure
	 */
	public static function canWrite(): \Closure {
		return static function () {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}

			/**
			 * Filter the capability required for write abilities.
			 *
			 * Returning a custom capability lets a platform consumer
			 * grant write access to a non-editor role without
			 * monkey-patching the permission callback.
			 *
			 * @param string $capability Default capability name.
			 */
			$capability = (string) apply_filters( 'datamachine_events_write_capability', 'edit_others_posts' );

			return current_user_can( $capability );
		};
	}

	/**
	 * Permission callback for diagnostic / admin-only read abilities
	 * that should still be gated but available to elevated roles.
	 *
	 * Currently unused by default — read-only abilities were
	 * intentionally left on their existing gate. Provided here so
	 * future read abilities have a consistent shape if they want the
	 * same elevation shape as the write gate.
	 *
	 * @return \Closure
	 */
	public static function canRead(): \Closure {
		return static function () {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}

			/**
			 * Filter the capability required for diagnostic read
			 * abilities. See `datamachine_events_write_capability` for
			 * the shape.
			 *
			 * @param string $capability Default capability name.
			 */
			$capability = (string) apply_filters( 'datamachine_events_read_capability', 'edit_posts' );

			return current_user_can( $capability );
		};
	}
}
