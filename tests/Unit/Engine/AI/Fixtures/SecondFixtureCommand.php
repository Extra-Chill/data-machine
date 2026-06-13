<?php
/**
 * Second fixture command for CliCommandIntrospector merge/map tests.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Fixtures
 */

namespace DataMachine\Tests\Unit\Engine\AI\Fixtures;

class SecondFixtureCommand {

	/**
	 * Geocode known venues.
	 *
	 * @subcommand geocode
	 */
	public function geocode( array $args, array $assoc_args ): void {
	}

	/**
	 * Add a new venue with a scraper flow.
	 *
	 * @subcommand add
	 */
	public function add( array $args, array $assoc_args ): void {
	}
}
