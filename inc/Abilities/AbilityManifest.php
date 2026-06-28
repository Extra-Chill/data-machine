<?php
/**
 * Lightweight ability manifest registration.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers cheap ability schemas before the full runtime gate opens.
 */
class AbilityManifest {

	/**
	 * Register declared ability classes.
	 *
	 * Each declaration loads only the ability schema/callback owner. Execute
	 * callbacks remain responsible for lazily activating heavier runtime services.
	 *
	 * @param array<int, array{file:string,class:string,method?:string}> $declarations Ability class declarations.
	 * @return void
	 */
	public static function register( array $declarations ): void {
		foreach ( $declarations as $declaration ) {
			if ( ! isset( $declaration['file'], $declaration['class'] ) ) {
				continue;
			}

			require_once $declaration['file'];

			$class  = $declaration['class'];
			$method = $declaration['method'] ?? 'ensure_registered';

			if ( is_callable( array( $class, $method ) ) ) {
				$class::$method();
				continue;
			}

			new $class();
		}
	}
}
