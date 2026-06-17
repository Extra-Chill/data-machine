<?php
/**
 * Pure-PHP smoke test for the generic runtime task ability contract.
 *
 * Run with: php tests/runtime-task-ability-contract-smoke.php
 */

$ability_file = __DIR__ . '/../inc/Abilities/Runtime/RuntimeTaskAbility.php';
$bootstrap    = __DIR__ . '/../data-machine.php';
$source       = file_get_contents( $ability_file ) ?: '';
$root_source  = file_get_contents( $bootstrap ) ?: '';
$failures     = array();
$passes       = 0;

$assert_contains = static function ( string $needle, string $haystack, string $label ) use ( &$failures, &$passes ): void {
	if ( str_contains( $haystack, $needle ) ) {
		++$passes;
		return;
	}

	$failures[] = "FAIL: {$label}";
};

$assert_not_contains = static function ( string $needle, string $haystack, string $label ) use ( &$failures, &$passes ): void {
	if ( ! str_contains( $haystack, $needle ) ) {
		++$passes;
		return;
	}

	$failures[] = "FAIL: {$label}";
};

$assert_contains( "wp_register_ability(\n\t\t\t\t'datamachine/run-runtime-task'", $source, 'runtime task ability is registered' );
$assert_contains( "'required'   => array( 'ability' )", $source, 'ability name is required input' );
$assert_contains( "apply_filters( 'datamachine_runtime_task_execute'", $source, 'external runner filter seam exists' );
$assert_contains( 'wp_get_ability( $request[\'ability\'] )', $source, 'fallback dispatch uses WordPress abilities' );
$assert_contains( "datamachine/runtime-task-result/v1", $source, 'normalized result schema is declared' );
$assert_contains( "'status'      => 'failed'", $source, 'failures return normalized result envelopes' );
$assert_contains( "'timed_out'", $source, 'timeout status is normalized' );
$assert_not_contains( 'proc_open', $source, 'runtime task ability does not execute local host processes' );
$assert_not_contains( 'shell_exec', $source, 'runtime task ability does not execute shell commands' );
$assert_contains( "inc/Abilities/Runtime/RuntimeTaskAbility.php", $root_source, 'runtime task ability file is loaded' );
$assert_contains( 'new \\DataMachine\\Abilities\\Runtime\\RuntimeTaskAbility()', $root_source, 'runtime task ability is instantiated' );

if ( $failures ) {
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "Runtime task ability contract smoke complete: {$passes} assertions, 0 failures.\n";
