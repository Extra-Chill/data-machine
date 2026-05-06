<?php
/**
 * Pure-PHP smoke test for generic job retry/backoff policy hooks (#1734).
 *
 * Run with: php tests/job-retry-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

$failed = 0;
$total  = 0;

function assert_retry_policy_smoke( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$args;
	return $value;
}

function do_action( string $hook, mixed ...$args ): void {
	$hook;
	$args;
}

function wp_rand( int $min, int $max ): int {
	$max;
	return $min;
}

require_once __DIR__ . '/../inc/Core/JobRetryPolicy.php';

$reflection          = new ReflectionClass( DataMachine\Core\JobRetryPolicy::class );
$extract_retry_after = $reflection->getMethod( 'extractRetryAfter' );
$normalize_delay     = $reflection->getMethod( 'normalizeDelay' );
$is_retryable        = $reflection->getMethod( 'isRetryableFailure' );
$resolve_delay       = $reflection->getMethod( 'resolveDelay' );
$classify_failure    = $reflection->getMethod( 'classifyFailure' );
$resolve_policy      = $reflection->getMethod( 'resolvePolicy' );

echo "Case 1: Retry-After values are normalized\n";
assert_retry_policy_smoke( 'numeric Retry-After is seconds', 90 === $extract_retry_after->invoke( null, array( 'retry_after' => '90' ) ) );
assert_retry_policy_smoke( 'header Retry-After is seconds', 45 === $extract_retry_after->invoke( null, array( 'headers' => array( 'Retry-After' => 45 ) ) ) );
assert_retry_policy_smoke( 'HTTP-date Retry-After normalizes to non-negative delay', null !== $normalize_delay->invoke( null, gmdate( 'D, d M Y H:i:s \G\M\T', time() + 120 ) ) );

echo "Case 2: Generic transient/provider failures are retryable by default\n";
assert_retry_policy_smoke( 'explicit retryable flag wins', true === $is_retryable->invoke( null, 'custom_failure', array( 'retryable' => true ) ) );
assert_retry_policy_smoke( 'Retry-After implies retryable', true === $is_retryable->invoke( null, 'provider_error', array( 'retry_after' => 10 ) ) );
assert_retry_policy_smoke( 'rate-limit text implies retryable', true === $is_retryable->invoke( null, 'ai_processing_failed', array( 'ai_error' => 'Provider returned 429 rate limit' ) ) );
assert_retry_policy_smoke( 'cURL 28 connect timeout text implies retryable', true === $is_retryable->invoke( null, 'ai_processing_failed', array( 'ai_error' => 'cURL error 28: Connection timed out after 15000 milliseconds' ) ) );
assert_retry_policy_smoke( 'validation-style failures are not retryable by default', false === $is_retryable->invoke( null, 'missing_flow_id_in_step_config', array() ) );

echo "Case 3: Backoff composes with Retry-After\n";
$delay = $resolve_delay->invoke(
	null,
	2,
	array(
		'base_delay'  => 30,
		'max_delay'   => 600,
		'backoff'     => 'exponential',
		'retry_after' => 300,
	),
	array()
);
assert_retry_policy_smoke( 'Retry-After is a floor over exponential backoff', 300 === $delay, 'delay was ' . $delay );

echo "Case 4: AI retry classification preserves provider backoff and shortens transport retries\n";
$transport_context = array( 'ai_error' => 'cURL error 28: Connection timed out after 15000 milliseconds' );
$rate_context      = array( 'ai_error' => 'Provider returned 429 rate limit' );
$generic_context   = array( 'ai_error' => 'Provider temporarily unavailable, try again later' );
$transport_policy  = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $transport_context, array(), array() );
$rate_policy       = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $rate_context, array(), array() );
$generic_policy    = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $generic_context, array(), array() );
$transport_delay   = $resolve_delay->invoke( null, 1, $transport_policy, array() );
$rate_delay        = $resolve_delay->invoke( null, 1, $rate_policy, array() );
$generic_delay     = $resolve_delay->invoke( null, 1, $generic_policy, array() );

assert_retry_policy_smoke( 'cURL 28 is classified as transport connect timeout', 'transport_connect_timeout' === $classify_failure->invoke( null, 'ai_processing_failed', $transport_context ) );
assert_retry_policy_smoke( 'cURL 28 uses short transport base delay', 15 === $transport_delay, 'delay was ' . $transport_delay );
assert_retry_policy_smoke( 'rate limit is classified separately', 'provider_rate_limit' === $rate_policy['retry_class'] );
assert_retry_policy_smoke( 'rate limit keeps default base delay', 60 === $rate_delay, 'delay was ' . $rate_delay );
assert_retry_policy_smoke( 'generic retryable AI failure keeps default base delay', 60 === $generic_delay, 'delay was ' . $generic_delay );

echo "Case 5: Production code exposes retry/backoff hooks and metadata\n";
$policy_src = file_get_contents( __DIR__ . '/../inc/Core/JobRetryPolicy.php' ) ?: '';
$fail_src   = file_get_contents( __DIR__ . '/../inc/Engine/Actions/Handlers/FailJobHandler.php' ) ?: '';
$ai_src     = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';

assert_retry_policy_smoke( 'policy exposes retryable-error hook', str_contains( $policy_src, 'datamachine_job_error_retryable' ) );
assert_retry_policy_smoke( 'policy exposes retry policy hook', str_contains( $policy_src, 'datamachine_job_retry_policy' ) );
assert_retry_policy_smoke( 'policy exposes provider/source throttle hook', str_contains( $policy_src, 'datamachine_job_retry_throttle_delay' ) );
assert_retry_policy_smoke( 'policy records retry metadata', str_contains( $policy_src, "'retry'" ) && str_contains( $policy_src, "'history'" ) );
assert_retry_policy_smoke( 'policy records retry classification metadata', str_contains( $policy_src, "'retry_class'" ) );
assert_retry_policy_smoke( 'policy records poison item isolation metadata', str_contains( $policy_src, "'poison_item'" ) );
assert_retry_policy_smoke( 'fail handler tries retry before final failure', strpos( $fail_src, 'maybeRetry' ) < strpos( $fail_src, 'complete_job' ) );
assert_retry_policy_smoke( 'AI failures pass retry and transport context', str_contains( $ai_src, "'retry_after'" ) && str_contains( $ai_src, "'headers'" ) && str_contains( $ai_src, "'transport_profile'" ) );

echo "\nJob retry policy smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
