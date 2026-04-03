<?php

namespace DataMachineSocials\Abilities\Traits;

use DataMachine\Abilities\PermissionHelper;

/**
 * Shared trait for the `checkPermission` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasCheckPermission {
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
