<?php
/**
 * Data Machine ability-scope permission lifecycle filter.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Applies Data Machine agent-token ability scopes to direct WP_Ability execution.
 */
final class AbilityScopePermissionFilter {

	/**
	 * Register the core ability permission lifecycle filter.
	 */
	public static function register(): void {
		add_filter( 'wp_ability_permission_result', array( self::class, 'filter_permission_result' ), 10, 4 );
	}

	/**
	 * Deny direct ability execution when the active agent-token scope excludes it.
	 *
	 * @param bool|\WP_Error $permission   Permission result returned by the ability.
	 * @param string         $ability_name Ability slug.
	 * @param mixed          $input        Input passed to the ability permission check.
	 * @param object         $ability      Ability instance.
	 * @return bool|\WP_Error Permission result.
	 */
	public static function filter_permission_result( $permission, string $ability_name, $input, $ability ) {
		unset( $input );

		if ( is_wp_error( $permission ) || true !== $permission ) {
			return $permission;
		}

		if ( ! str_starts_with( $ability_name, 'datamachine/' ) ) {
			return $permission;
		}

		$category = is_object( $ability ) && method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		if ( PermissionHelper::can_use_ability( $ability_name, $category ) ) {
			return $permission;
		}

		return new \WP_Error(
			'datamachine_ability_scope_denied',
			__( 'The active agent token is not allowed to execute this Data Machine ability.', 'data-machine' ),
			array(
				'ability' => $ability_name,
				'status'  => 403,
			)
		);
	}
}
