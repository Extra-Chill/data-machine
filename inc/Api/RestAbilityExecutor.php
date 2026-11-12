<?php
/**
 * REST ability execution helper.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Executes abilities and applies a REST result spec at controller boundaries.
 */
class RestAbilityExecutor {

	/**
	 * Execute an ability and return the configured REST response.
	 *
	 * @param string|object   $ability Ability slug or object with execute().
	 * @param array           $input   Ability input.
	 * @param RestResultSpec  $spec    REST result presentation spec.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function execute( $ability, array $input, RestResultSpec $spec ) {
		if ( is_string( $ability ) ) {
			$ability = wp_get_ability( $ability );
		}

		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			return new \WP_Error( 'ability_not_found', __( 'Ability not found', 'data-machine' ), array( 'status' => 500 ) );
		}

		return $spec->response( $ability->execute( $input ) );
	}
}
