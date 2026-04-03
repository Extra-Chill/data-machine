<?php

namespace DataMachineSocials\Handlers\Traits;

/**
 * Shared trait for the `get_account_details` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasGetAccountDetails {
	/**
	* Get stored Threads account details
	*
	* @return array|null Account details or null
	*/
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return null;
		}
		return $account;
	}
}
