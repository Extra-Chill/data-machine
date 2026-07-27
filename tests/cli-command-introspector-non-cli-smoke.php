<?php
/**
 * Non-CLI smoke test for WP-CLI command source introspection.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Engine\AI\CliCommandIntrospector;
use DataMachine\Tests\Unit\Engine\AI\Fixtures\WpCliOnlyFixtureCommand;

if ( class_exists( 'WP_CLI_Command', false ) ) {
	fwrite( STDERR, "FAIL: WP_CLI_Command must be absent in the non-CLI smoke process\n" );
	exit( 1 );
}

$subcommands = CliCommandIntrospector::describe_class( WpCliOnlyFixtureCommand::class );

if (
	array(
		array(
			'name'        => 'inspect',
			'description' => 'Inspect command metadata without loading WP-CLI inheritance.',
		),
	) !== $subcommands
) {
	fwrite( STDERR, "FAIL: source metadata was not parsed from the unloaded command\n" );
	exit( 1 );
}

if ( class_exists( WpCliOnlyFixtureCommand::class, false ) ) {
	fwrite( STDERR, "FAIL: introspection autoloaded the WP-CLI-only command\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: non-CLI introspection reads command metadata without autoloading WP-CLI inheritance\n" );
