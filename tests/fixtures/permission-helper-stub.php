<?php
/**
 * Permission helper stub for smoke tests.
 *
 * The Publish abilities call DataMachine\Abilities\PermissionHelper::can_manage()
 * inside their permission_callback. Tests that exercise execute() directly do
 * not hit the permission callback, but loading the ability class triggers
 * autoload of PermissionHelper if execute paths reference it. This stub
 * provides a permissive implementation for unit-style smoke tests.
 *
 * @package DataMachine\Tests\Fixtures
 */

namespace DataMachine\Abilities;

if ( ! class_exists( __NAMESPACE__ . '\\PermissionHelper' ) ) {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}
}
