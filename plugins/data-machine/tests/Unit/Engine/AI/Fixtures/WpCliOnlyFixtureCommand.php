<?php
/**
 * WP-CLI-only fixture that must never load in a non-CLI test process.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Fixtures
 */

namespace DataMachine\Tests\Unit\Engine\AI\Fixtures;

class WpCliOnlyFixtureCommand extends \WP_CLI_Command {

	/**
	 * Inspect command metadata without loading WP-CLI inheritance.
	 *
	 * @subcommand inspect
	 */
	public function inspect( array $args, array $assoc_args ): void {
	}
}
