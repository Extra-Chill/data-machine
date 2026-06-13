<?php
/**
 * Fixture WP-CLI command class for CliCommandIntrospector tests.
 *
 * Exercises the conventions the introspector must support:
 *  - methods with `@subcommand <name>` annotations (the extrachill-cli style),
 *  - a method with a docblock but no `@subcommand` (name derives from method),
 *  - a multi-paragraph docblock (only the first line is the short description),
 *  - a public method with NO docblock (skipped by default),
 *  - a static method and a magic method (never subcommands).
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Fixtures
 */

namespace DataMachine\Tests\Unit\Engine\AI\Fixtures;

class FixtureCommand {

	/**
	 * Discover venues in a city.
	 *
	 * ## OPTIONS
	 *
	 * <city>
	 * : The city to search.
	 *
	 * @subcommand discover
	 */
	public function discover( array $args, array $assoc_args ): void {
	}

	/**
	 * Qualify a venue URL for scraping.
	 *
	 * @subcommand qualify
	 */
	public function qualify( array $args, array $assoc_args ): void {
	}

	/**
	 * List everything without an explicit subcommand tag.
	 *
	 * The name should fall back to the method name.
	 */
	public function audit( array $args, array $assoc_args ): void {
	}

	public function undocumented( array $args, array $assoc_args ): void {
	}

	/**
	 * Not a subcommand because it is static.
	 *
	 * @subcommand should-not-appear
	 */
	public static function helper(): void {
	}

	/**
	 * Magic methods are never subcommands.
	 */
	public function __construct() {
	}
}
