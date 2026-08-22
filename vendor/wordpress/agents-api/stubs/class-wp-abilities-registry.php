<?php
/**
 * PHPStan stub for the WordPress 7.1 Abilities API `WP_Abilities_Registry`.
 *
 * Not yet present in php-stubs/wordpress-stubs. Minimal signatures for type
 * resolution only.
 *
 * @see https://github.com/WordPress/abilities-api
 */

/**
 * Registry of abilities.
 */
class WP_Abilities_Registry {

	public static function get_instance(): self { return new self(); }

	/**
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	public function register( string $name, array $args = array() ): ?WP_Ability {
		return '' === $name ? null : new WP_Ability( $name, $args );
	}

	public function unregister( string $name ): bool {
		unset( $name );
		return false;
	}

	public function get_registered( string $name ): ?WP_Ability {
		return '' === $name ? null : new WP_Ability( $name );
	}

	/**
	 * @return array<string, WP_Ability>
	 */
	public function get_all_registered(): array { return array(); }
}
