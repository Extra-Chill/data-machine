<?php
/**
 * Pure-PHP smoke coverage for pipeline AI concurrency backpressure (#1820).
 *
 * Run with: php tests/ai-step-backpressure-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

$failed = 0;
$total  = 0;

function assert_ai_backpressure_smoke( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

$GLOBALS['datamachine_ai_backpressure_options'] = array();

function get_option( string $name, mixed $default = false ): mixed {
	return array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] )
		? $GLOBALS['datamachine_ai_backpressure_options'][ $name ]
		: $default;
}

function add_option( string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] ) ) {
		return false;
	}

	$GLOBALS['datamachine_ai_backpressure_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	$existed = array_key_exists( $name, $GLOBALS['datamachine_ai_backpressure_options'] );
	unset( $GLOBALS['datamachine_ai_backpressure_options'][ $name ] );
	return $existed;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $args );
	if ( 'datamachine_pipeline_ai_concurrency_limit' === $hook ) {
		return 1;
	}
	if ( 'datamachine_pipeline_ai_throttle_delay' === $hook ) {
		return 7;
	}
	return $value;
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
}

require_once __DIR__ . '/../inc/Core/NetworkSettings.php';
require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLease.php';
require_once __DIR__ . '/../inc/Engine/AI/PipelineAIConcurrencyLimiter.php';

use DataMachine\Engine\AI\PipelineAIConcurrencyLease;
use DataMachine\Engine\AI\PipelineAIConcurrencyLimiter;

echo "Case 1: first AI step acquires the single site slot\n";
$first = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 101, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'first acquire succeeds', true === $first['acquired'] );
assert_ai_backpressure_smoke( 'first acquire returns lease', ( $first['lease'] ?? null ) instanceof PipelineAIConcurrencyLease );

echo "Case 2: second concurrent AI step is throttled\n";
$second = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 102, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'second acquire is denied', false === $second['acquired'] );
assert_ai_backpressure_smoke( 'throttle reason is distinct', 'ai_concurrency_limit' === ( $second['reason'] ?? '' ) );
assert_ai_backpressure_smoke( 'throttle delay is filterable metadata', 7 === ( $second['delay'] ?? 0 ) );
assert_ai_backpressure_smoke( 'active count is reported', 1 === ( $second['active'] ?? 0 ) );

echo "Case 3: release happens on exception/finally paths\n";
try {
	throw new RuntimeException( 'synthetic failure after slot acquisition' );
} catch ( RuntimeException ) {
	// Mirrors AIStep's outer finally: release is independent of model outcome.
} finally {
	$first['lease']->release();
}

$after_release = PipelineAIConcurrencyLimiter::acquire( 'openai', array( 'job_id' => 103, 'flow_step_id' => 'ai-1' ) );
assert_ai_backpressure_smoke( 'slot can be acquired after release', true === $after_release['acquired'] );
$after_release['lease']->release();

echo "Case 4: production wiring preserves pipeline semantics\n";
$ai_src       = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';
$engine_src   = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';
$retry_src    = file_get_contents( __DIR__ . '/../inc/Core/JobRetryPolicy.php' ) ?: '';
$fetch_src    = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';
$upsert_files = glob( __DIR__ . '/../inc/Core/Steps/Upsert/*.php' ) ?: array();
$upsert_src   = implode( "\n", array_map( static fn( string $path ): string => file_get_contents( $path ) ?: '', $upsert_files ) );

assert_ai_backpressure_smoke( 'AIStep acquires before prompt queue consumption', strpos( $ai_src, 'PipelineAIConcurrencyLimiter::acquire' ) < strpos( $ai_src, 'consumeFromPromptQueue' ) );
assert_ai_backpressure_smoke( 'AIStep throttles by rescheduling same step', str_contains( $ai_src, 'deferForAIConcurrency' ) && str_contains( $ai_src, 'datamachine_execute_step' ) );
assert_ai_backpressure_smoke( 'executor treats AI throttle as routed pending work', str_contains( $engine_src, 'hasPendingAIConcurrencyThrottle' ) && str_contains( $engine_src, 'ai_concurrency_throttle' ) );
assert_ai_backpressure_smoke( 'fetch steps do not use AI limiter', ! str_contains( $fetch_src, 'PipelineAIConcurrencyLimiter' ) );
assert_ai_backpressure_smoke( 'upsert steps do not use AI limiter', ! str_contains( $upsert_src, 'PipelineAIConcurrencyLimiter' ) );
assert_ai_backpressure_smoke( 'existing transport retry classifier remains intact', str_contains( $retry_src, 'transport_connect_timeout' ) && str_contains( $retry_src, 'AI_TRANSPORT_BASE_DELAY' ) );
assert_ai_backpressure_smoke( 'throttle log includes requested metadata', str_contains( $ai_src, 'rescheduled_for_seconds' ) && str_contains( $ai_src, "'active'" ) && str_contains( $ai_src, "'limit'" ) );

echo "\nAI step backpressure smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
