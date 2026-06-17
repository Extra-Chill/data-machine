<?php
/**
 * Shared WP-CLI helper for executing registered abilities.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

use DataMachine\Core\AbilityResult;

defined( 'ABSPATH' ) || exit;

/**
 * Executes abilities through the Abilities API boundary for CLI commands.
 */
class AbilityRunner {

	/**
	 * Execute a registered ability and normalize the result for legacy CLI callers.
	 *
	 * @param string $ability_name Ability slug, e.g. datamachine/get-jobs.
	 * @param array  $input        Ability input.
	 * @return array Normalized ability result.
	 */
	public static function execute( string $ability_name, array $input = array() ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success' => false,
				'error'   => 'Abilities API is not available.',
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Ability not registered: %s', $ability_name ),
			);
		}

		return AbilityResult::normalize( $ability->execute( $input ) );
	}
}
