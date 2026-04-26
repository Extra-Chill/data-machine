<?php
/**
 * Pure-PHP smoke test for `wp datamachine fetch test` (#1156).
 *
 * Run with: php tests/fetch-test-cli-smoke.php
 *
 * The handler dry-run runner already exists as `wp datamachine test`.
 * This smoke pins the CLI alias and companion handler discovery surface
 * requested by #1156 without booting WordPress.
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

$files = array(
	'bootstrap' => file_get_contents( $root . '/inc/Cli/Bootstrap.php' ),
	'test'      => file_get_contents( $root . '/inc/Cli/Commands/TestCommand.php' ),
	'handlers'  => file_get_contents( $root . '/inc/Cli/Commands/HandlersCommand.php' ),
);

$failed = 0;
$total  = 0;

function assert_fetch_cli( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
}

echo "=== fetch-test-cli-smoke ===\n";

assert_fetch_cli(
	'Bootstrap registers datamachine fetch test alias',
	str_contains( $files['bootstrap'], "WP_CLI::add_command( 'datamachine fetch test', Commands\\TestCommand::class );" )
);

assert_fetch_cli(
	'TestCommand accepts --handler before positional fallback',
	str_contains( $files['test'], "\$handler_slug = \$assoc_args['handler'] ?? ( \$args[0] ?? null );" )
);

assert_fetch_cli(
	'TestCommand documents fetch test alias example',
	str_contains( $files['test'], 'wp datamachine fetch test --handler=ticketmaster' )
);

assert_fetch_cli(
	'HandlersCommand documents --handler option',
	str_contains( $files['handlers'], '[--handler=<slug>]' )
);

assert_fetch_cli(
	'HandlersCommand passes handler_slug to HandlerAbilities',
	str_contains( $files['handlers'], "'handler_slug' => \$handler_slug" )
);

assert_fetch_cli(
	'HandlersCommand enriches single-handler output with settings_class',
	str_contains( $files['handlers'], "['settings_class'] = \$settings_class ? get_class( \$settings_class ) : '';" )
);

assert_fetch_cli(
	'HandlersCommand shows settings_class only for filtered handler table',
	str_contains( $files['handlers'], "? array( 'slug', 'label', 'step_type', 'settings_class' )" )
);

if ( $failed > 0 ) {
	echo "\nfetch-test-cli-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nfetch-test-cli-smoke passed: {$total} assertions.\n";
