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

$fixture = $root . '/tests/fixtures/identity-store-doubles.php';
$source  = file_get_contents( $fixture );
$check( false !== $source, 'identity-store doubles fixture must be readable' );
$check( false !== $source && str_contains( $source, 'final class FailingProvisionAdapter' ), 'failing provision double must be fixture-owned' );
$check( false !== $source && str_contains( $source, 'final class CountingProvisionAdapter' ), 'counting provision double must be fixture-owned' );
$check( false !== $source && str_contains( $source, 'final class DuplicateLoserAgents' ), 'duplicate loser double must be fixture-owned' );

$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$dev_files = $composer['autoload-dev']['files'] ?? array();
$check( in_array( 'tests/fixtures/identity-store-doubles.php', $dev_files, true ), 'identity-store doubles must be explicitly autoloaded' );
$check( in_array( 'tests/fixtures/engine-data.php', $dev_files, true ), 'EngineData compatibility fixture must be explicitly autoloaded' );
$engine_fixture = file_get_contents( $root . '/tests/fixtures/engine-data.php' );
$check( false !== $engine_fixture && str_contains( $engine_fixture, 'class_alias' ), 'EngineData compatibility must remain fixture-owned' );

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
	$check( str_contains( $data, 'datamachine_activate_for_site()' ), sprintf( '%s cluster must activate its own tables', $name ) );
}

if ( $failures ) {
	fwrite( STDERR, "PHPUnit file-isolation smoke failed:\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "PHPUnit file-isolation smoke passed (%d clusters, explicit fixture ownership).\n", count( $clusters ) ) );
