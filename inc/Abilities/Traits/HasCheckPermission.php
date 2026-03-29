<?php

namespace DataMachine\Abilities\Traits;

use DataMachine\Abilities\PermissionHelper;

/**
 * Shared trait for the `checkPermission` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasCheckPermission {
	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
