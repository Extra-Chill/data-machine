<?php
/**
 * Ability registration stub for pure-PHP smoke tests.
 *
 * The Abilities API runtime never boots in the smoke harness, so
 * `AbilityRegistration::on_abilities_api_init()` becomes a no-op and
 * ability classes can be constructed without WordPress.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities;

if ( ! class_exists( AbilityRegistration::class ) ) {
	/**
	 * No-op registration harness for smoke tests.
	 */
	class AbilityRegistration {
		/**
		 * Register abilities lazily; nothing to do in the smoke harness.
		 *
		 * @param callable $register_callback Registration callback.
		 * @return void
		 */
		public static function on_abilities_api_init( callable $register_callback ): void {
			unset( $register_callback );
		}
	}
}
