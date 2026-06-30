<?php
/**
 * Pure-PHP smoke test for paused flow manual execution semantics.
 *
 * Run with: php tests/run-flow-paused-manual-smoke.php
 *
 * @package DataMachine\Tests
 */

$run_flow_file = __DIR__ . '/../inc/Abilities/Engine/RunFlowAbility.php';
$engine_file   = __DIR__ . '/../inc/Engine/Actions/Engine.php';
$run_flow_src  = file_get_contents( $run_flow_file ) ?: '';
$engine_src    = file_get_contents( $engine_file ) ?: '';

$assertions = 0;

function assert_run_flow_paused_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;

	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_run_flow_paused_contains( string $needle, string $haystack, string $message ): void {
	assert_run_flow_paused_true( false !== strpos( $haystack, $needle ), $message );
}

assert_run_flow_paused_contains( "'respect_paused' => array(", $run_flow_src, 'run-flow ability exposes scheduler safety input' );
assert_run_flow_paused_contains( '$respect_paused    = true === ( $input[\'respect_paused\'] ?? false );', $run_flow_src, 'run-flow defaults paused guard off for direct/manual execution' );
assert_run_flow_paused_contains( 'if ( $respect_paused && ! \\DataMachine\\Core\\Database\\Flows\\Flows::is_flow_enabled( $scheduling_config ) )', $run_flow_src, 'paused guard only runs when scheduler safety flag is set' );
assert_run_flow_paused_contains( "'respect_paused' => true,", $engine_src, 'Action Scheduler hook bridge preserves paused-flow safety' );

echo "OK ({$assertions} assertions)\n";
