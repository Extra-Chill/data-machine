<?php

namespace DataMachineSocials\Handlers\Traits;

/**
 * Shared trait for the `remove_account` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasRemoveAccount {
	/**
	 * Remove stored Reddit account details
	 *
	 * @return bool Success status
	 */
	public function remove_account(): bool {
		return $this->clear_account();
	}
}
