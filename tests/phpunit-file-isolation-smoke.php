<?php
/**
 * Deterministic canary for PHPUnit file isolation contracts.
 *
 * This intentionally runs without WordPress. It checks the source-level
 * ownership and lifecycle gates that must hold before Codebox can shard files.
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );

$failures = array();
$check    = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$run      = static function ( array $command ) use ( $root ): array {
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root );
	if ( ! is_resource( $process ) ) {
		return array( 1, '', 'Failed to start process.' );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array( proc_close( $process ), (string) $stdout, (string) $stderr );
};

$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$autoload_files = $composer['autoload']['files'] ?? array();
$dev_files = $composer['autoload-dev']['files'] ?? array();
$psr4      = $composer['autoload-dev']['psr-4'] ?? array();
$check( ! in_array( 'inc/Engine/AI/conversation-loop.php', $autoload_files, true ), 'WordPress-guarded conversation loop must not be eagerly loaded' );
$check( ! in_array( 'tests/fixtures/identity-store-doubles.php', $dev_files, true ), 'identity-store doubles must not be eagerly loaded' );
$check( 'tests/' === ( $psr4['DataMachine\\Tests\\'] ?? null ), 'test namespace must retain PSR-4 ownership' );

$plugin_bootstrap = (string) file_get_contents( $root . '/data-machine.php' );
$check( str_contains( $plugin_bootstrap, "require_once __DIR__ . '/inc/Engine/AI/conversation-loop.php';" ), 'WordPress plugin bootstrap must load the conversation loop explicitly' );

list( $autoload_status, $autoload_output ) = $run( array( PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo "autoload-ok\\n";' ) );
$check( 0 === $autoload_status && "autoload-ok\n" === $autoload_output, 'Composer autoload must complete outside WordPress' );
list( $phpunit_status, $phpunit_output ) = $run( array( $root . '/vendor/bin/phpunit', '--version' ) );
$check( 0 === $phpunit_status && str_starts_with( $phpunit_output, 'PHPUnit ' ), 'PHPUnit must start and report its version' );
list( $phpcs_status, $phpcs_output ) = $run( array( $root . '/vendor/bin/phpcs', '--version' ) );
$check( 0 === $phpcs_status && str_starts_with( $phpcs_output, 'PHP_CodeSniffer version ' ), 'PHPCS must start and report its version' );

$homeboy = json_decode( (string) file_get_contents( $root . '/homeboy.json' ), true );
$check( 'fail' === ( $homeboy['extensions']['wordpress']['settings']['phpunit_no_tests'] ?? null ), 'authoritative PHPUnit gate must fail closed on zero tests' );

$fixtures = array(
	'FailingProvisionAdapter'  => 'tests/Fixtures/FailingProvisionAdapter.php',
	'CountingProvisionAdapter' => 'tests/Fixtures/CountingProvisionAdapter.php',
	'DuplicateLoserAgents'     => 'tests/Fixtures/DuplicateLoserAgents.php',
);
foreach ( $fixtures as $class => $relative_path ) {
	$path = $root . '/' . $relative_path;
	$data = file_get_contents( $path );
	$check( false !== $data, "{$class} fixture must be readable" );
	$check( false !== $data && str_contains( $data, "final class {$class}" ), "{$class} fixture must be PSR-4 addressable" );
}

$phpunit = (string) file_get_contents( $root . '/phpunit.xml.dist' );
$check( str_contains( $phpunit, '<directory suffix="Test.php">tests</directory>' ), 'authoritative WordPress suite must discover Test.php files' );
$selected_test = $root . '/tests/Unit/Core/Identity/AgentIdentityStoreAdapterTest.php';
$check( is_file( $selected_test ), 'known identity test must be discoverable in the authoritative suite' );

$clusters = array(
	'agent context'       => 'tests/Unit/Abilities/AgentContextPropagationTest.php',
	'bulk config'          => 'tests/Unit/Cli/Commands/Flows/BulkConfigCommandTest.php',
	'direct ownership'    => 'tests/Unit/Abilities/Job/DirectJobOwnershipTest.php',
	'delegated operation' => 'tests/Unit/Abilities/Job/DelegatedOperationTest.php',
	'item claims'         => 'tests/Unit/Core/ItemClaimLifecycleTest.php',
	'job lifecycle'       => 'tests/Unit/Core/Database/JobLifecycleTransitionTest.php',
	'post reservations'   => 'tests/Unit/Core/Database/PostIdentityReservations/PostIdentityReservationsTest.php',
	'agent identity'      => 'tests/Unit/Core/Identity/AgentIdentityStoreAdapterTest.php',
	'default bootstrap'   => 'tests/Unit/Core/Agents/DefaultAgentBootstrapTest.php',
	'batch scheduler'     => 'tests/Unit/Abilities/Engine/PipelineBatchSchedulerTest.php',
	'publish opt-in'      => 'tests/Unit/Engine/AI/Tools/PipelinePublishOptInTest.php',
	'flow atomicity'      => 'tests/Unit/Abilities/FlowCreationAtomicityTest.php',
	'fail job'            => 'tests/Unit/Abilities/Job/FailJobAbilityTest.php',
	'item claim'          => 'tests/Unit/Core/ItemClaimLifecycleTest.php',
	'schedule failure'    => 'tests/Unit/Abilities/ScheduleMutationFailureTest.php',
	'pipeline config'     => 'tests/Unit/Abilities/PipelineConfigurationAbilitiesTest.php',
);

foreach ( $clusters as $name => $relative_path ) {
	$path = $root . '/' . $relative_path;
	$data = file_get_contents( $path );
	$check( false !== $data, sprintf( '%s cluster file must be readable', $name ) );
	if ( false === $data ) {
		continue;
	}
	$check( (bool) preg_match( '/function\s+set_up\s*\(/', $data ), sprintf( '%s cluster must define set_up()', $name ) );
	$check( (bool) preg_match( '/function\s+tear_down\s*\(/', $data ), sprintf( '%s cluster must define tear_down()', $name ) );
	$check( ! str_contains( $data, 'class FailingProvisionAdapter' ), sprintf( '%s file must not own shared doubles', $name ) );
	$check( ! str_contains( $data, 'class CountingProvisionAdapter' ), sprintf( '%s file must not own shared doubles', $name ) );
	$check( ! str_contains( $data, 'class DuplicateLoserAgents' ), sprintf( '%s file must not own shared doubles', $name ) );
	$check(
		str_contains( $data, 'datamachine_activate_for_site()' ) || str_contains( $data, 'datamachine_test_prepare_site()' ),
		sprintf( '%s cluster must activate its own tables', $name )
	);
}

if ( $failures ) {
	fwrite( STDERR, "PHPUnit file-isolation smoke failed:\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "PHPUnit file-isolation smoke passed (%d clusters, explicit fixture ownership).\n", count( $clusters ) ) );
