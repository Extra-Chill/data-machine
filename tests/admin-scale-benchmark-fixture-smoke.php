<?php
/**
 * Pure-PHP smoke test for the admin scale benchmark fixture shape.
 *
 * Run with: php tests/admin-scale-benchmark-fixture-smoke.php
 */

$fixture = file_get_contents( __DIR__ . '/../bench/fixtures/admin-scale.php' ) ?: '';
$readme  = file_get_contents( __DIR__ . '/../bench/fixtures/README.md' ) ?: '';

$failures = array();
$passes   = 0;

$assert = static function ( string $name, bool $passed ) use ( &$failures, &$passes ): void {
	if ( ! $passed ) {
		$failures[] = $name;
		return;
	}

	$passes++;
};

echo "admin-scale-benchmark-fixture-smoke\n";

$assert( 'fixture is eval-file documented', str_contains( $fixture, 'wp eval-file bench/fixtures/admin-scale.php' ) );
$assert( 'fixture uses Data Machine repositories', str_contains( $fixture, 'new Pipelines()' ) && str_contains( $fixture, 'new Flows()' ) );
$assert( 'fixture avoids direct table writes', ! str_contains( $fixture, '$wpdb->insert' ) && ! str_contains( $fixture, '$wpdb->query' ) );
$assert( 'fixture is not a registered WP-CLI command', ! str_contains( $fixture, 'WP_CLI::add_command' ) );
$assert( 'fixture supports setup', str_contains( $fixture, "'setup'" ) );
$assert( 'fixture supports cleanup', str_contains( $fixture, "'cleanup'" ) );
$assert( 'fixture documents Homeboy Rigs usage', str_contains( $readme, 'Homeboy Rigs' ) );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " admin scale benchmark fixture assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo ' - ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nAll {$passes} admin scale benchmark fixture assertions passed.\n";
