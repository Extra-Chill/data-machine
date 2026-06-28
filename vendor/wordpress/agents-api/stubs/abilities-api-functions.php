<?php
/**
 * PHPStan function stubs for the WordPress 7.1 Abilities API.
 *
 * Companion to abilities-api.php (which carries the class stubs). Kept in a
 * separate file because phpcs requires a file to contain either OO structures
 * or function declarations, not both.
 *
 * @see https://github.com/WordPress/abilities-api
 */

/**
 * @param string               $name Ability name.
 * @param array<string, mixed> $args Ability arguments.
 */
function wp_register_ability( string $name, array $args = array() ): void {}

function wp_get_ability( string $name ): ?WP_Ability {
	return '' === $name ? null : new WP_Ability( $name );
}

function wp_has_ability( string $name ): bool {
	unset( $name );
	return false;
}

function wp_unregister_ability( string $name ): bool {
	unset( $name );
	return false;
}

/**
 * @return array<string, WP_Ability>
 */
function wp_get_abilities(): array { return array(); }

/**
 * @param string               $slug Category slug.
 * @param array<string, mixed> $args Category arguments.
 */
function wp_register_ability_category( string $slug, array $args = array() ): void {}

function wp_has_ability_category( string $slug ): bool {
	unset( $slug );
	return false;
}
