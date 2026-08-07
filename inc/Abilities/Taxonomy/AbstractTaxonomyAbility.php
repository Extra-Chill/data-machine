<?php
/**
 * Shared Taxonomy Ability scaffolding.
 *
 * @package DataMachine\Abilities\Taxonomy
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

abstract class AbstractTaxonomyAbility {

	public function __construct() {
		$this->registerAbility();
	}

	/**
	 * Ability name registered with the WordPress Abilities API.
	 *
	 * @return string
	 */
	abstract protected function getAbilityName(): string;

	/**
	 * Ability registration arguments.
	 *
	 * @return array
	 */
	abstract protected function getAbilityArgs(): array;

	/**
	 * Build a native Abilities API failure.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable error message.
	 * @param int    $status  HTTP status for REST presentation.
	 * @return \WP_Error
	 */
	protected function abilityError( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability( $this->getAbilityName(), $this->getAbilityArgs() );
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Check permission for taxonomy abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
