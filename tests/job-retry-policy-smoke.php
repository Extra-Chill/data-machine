<?php
/**
 * Pure-PHP smoke test for generic job retry/backoff policy hooks (#1734).
 *
 * Run with: php tests/job-retry-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );
define( 'WPINC', 'wp-includes' );

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

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    	$args;
    	return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook, mixed ...$args ): void {
    	$hook;
    	$args;
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( int $min, int $max ): int {
    	$max;
    	return $min;
    }
}

$merged_engine_data = array();
function datamachine_merge_engine_data( int $job_id, array $data ): void {
	global $merged_engine_data;
	$job_id;
	$merged_engine_data = $data;
}

require_once __DIR__ . '/../inc/Engine/ExecutionPlan.php';
require_once __DIR__ . '/../inc/Core/JobRetryPolicy.php';

$reflection          = new ReflectionClass( DataMachine\Core\JobRetryPolicy::class );
$extract_retry_after = $reflection->getMethod( 'extractRetryAfter' );
$normalize_delay     = $reflection->getMethod( 'normalizeDelay' );
$is_retryable        = $reflection->getMethod( 'isRetryableFailure' );
$resolve_delay       = $reflection->getMethod( 'resolveDelay' );
$classify_failure    = $reflection->getMethod( 'classifyFailure' );
$resolve_policy      = $reflection->getMethod( 'resolvePolicy' );
$record_poison_item  = $reflection->getMethod( 'recordPoisonItem' );
$resolve_ephemeral   = $reflection->getMethod( 'resolveEphemeralFlowStepId' );
$resolve_resume      = $reflection->getMethod( 'resolveResumeStepId' );
$step_completed      = $reflection->getMethod( 'stepCompletedSuccessfully' );

echo "Case 1: Retry-After values are normalized\n";
assert_retry_policy_smoke( 'numeric Retry-After is seconds', 90 === $extract_retry_after->invoke( null, array( 'retry_after' => '90' ) ) );
assert_retry_policy_smoke( 'header Retry-After is seconds', 45 === $extract_retry_after->invoke( null, array( 'headers' => array( 'Retry-After' => 45 ) ) ) );
assert_retry_policy_smoke( 'HTTP-date Retry-After normalizes to non-negative delay', null !== $normalize_delay->invoke( null, gmdate( 'D, d M Y H:i:s \G\M\T', time() + 120 ) ) );

echo "Case 2: Generic transient/provider failures are retryable by default\n";
assert_retry_policy_smoke( 'explicit retryable flag wins', true === $is_retryable->invoke( null, 'custom_failure', array( 'retryable' => true ) ) );
assert_retry_policy_smoke( 'Retry-After implies retryable', true === $is_retryable->invoke( null, 'provider_error', array( 'retry_after' => 10 ) ) );
assert_retry_policy_smoke( 'rate-limit text implies retryable', true === $is_retryable->invoke( null, 'ai_processing_failed', array( 'ai_error' => 'Provider returned 429 rate limit' ) ) );
assert_retry_policy_smoke( 'cURL 28 connect timeout text implies retryable', true === $is_retryable->invoke( null, 'ai_processing_failed', array( 'ai_error' => 'cURL error 28: Connection timed out after 15000 milliseconds' ) ) );
assert_retry_policy_smoke( 'cURL 52 empty reply text implies retryable', true === $is_retryable->invoke( null, 'ai_processing_failed', array( 'ai_error' => 'Network error occurred while sending request: cURL error 52: Empty reply from server' ) ) );
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
$empty_reply_context = array( 'ai_error' => 'Network error occurred while sending request: cURL error 52: Empty reply from server' );
$rate_context      = array( 'ai_error' => 'Provider returned 429 rate limit' );
$generic_context   = array( 'ai_error' => 'Provider temporarily unavailable, try again later' );
$transport_policy  = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $transport_context, array(), array() );
$empty_reply_policy = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $empty_reply_context, array(), array() );
$rate_policy       = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $rate_context, array(), array() );
$generic_policy    = $resolve_policy->invoke( null, 123, 'ai_processing_failed', $generic_context, array(), array() );
$transport_delay   = $resolve_delay->invoke( null, 1, $transport_policy, array() );
$empty_reply_delay = $resolve_delay->invoke( null, 1, $empty_reply_policy, array() );
$rate_delay        = $resolve_delay->invoke( null, 1, $rate_policy, array() );
$generic_delay     = $resolve_delay->invoke( null, 1, $generic_policy, array() );

assert_retry_policy_smoke( 'cURL 28 is classified as transport connect timeout', 'transport_connect_timeout' === $classify_failure->invoke( null, 'ai_processing_failed', $transport_context ) );
assert_retry_policy_smoke( 'cURL 28 uses short transport base delay', 15 === $transport_delay, 'delay was ' . $transport_delay );
assert_retry_policy_smoke( 'cURL 52 is classified as transport network', 'transport_network' === $classify_failure->invoke( null, 'ai_processing_failed', $empty_reply_context ) );
assert_retry_policy_smoke( 'cURL 52 uses short transport base delay', 15 === $empty_reply_delay, 'delay was ' . $empty_reply_delay );
assert_retry_policy_smoke( 'rate limit is classified separately', 'provider_rate_limit' === $rate_policy['retry_class'] );
assert_retry_policy_smoke( 'rate limit keeps default base delay', 60 === $rate_delay, 'delay was ' . $rate_delay );
assert_retry_policy_smoke( 'generic retryable AI failure keeps default base delay', 60 === $generic_delay, 'delay was ' . $generic_delay );

echo "Case 5: Transport retry exhaustion does not poison source items\n";
$merged_engine_data = array();
$record_poison_item->invoke(
	null,
	123,
	'ai_processing_failed',
	array(
		'flow_step_id' => 'step-1',
		'ai_error'     => 'Network error occurred while sending request: cURL error 28: Connection timed out after 15000 milliseconds',
	),
	array(
		'item_identifier' => 'source-item-1',
		'source_type'     => 'mcp',
	),
	3,
	3
);
assert_retry_policy_smoke( 'exhausted transport failures mark retry exhausted', true === ( $merged_engine_data['retry']['exhausted'] ?? false ) );
assert_retry_policy_smoke( 'exhausted transport failures do not isolate source item', ! isset( $merged_engine_data['poison_item'] ) );

echo "Case 6: Production code exposes retry/backoff hooks and metadata\n";
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
assert_retry_policy_smoke( 'fail handler persists structured diagnostics', str_contains( $fail_src, "'error_diagnostics'" ) && str_contains( $fail_src, "\$context_data['diagnostics']" ) );
assert_retry_policy_smoke( 'AI failures pass retry and transport context', str_contains( $ai_src, "'retry_after'" ) && str_contains( $ai_src, "'headers'" ) && str_contains( $ai_src, "'transport_profile'" ) );

echo "Case 7: Direct/system tasks resolve an ephemeral flow_step_id so they are retryable\n";
// A direct task (e.g. daily_memory_generation) runs through a single ephemeral
// step under engine_data['flow_config']; its flow_step_id is what
// datamachine_execute_step needs to re-run the same job on retry.
$direct_engine_data = array(
	'flow_config' => array(
		'ephemeral_step_0' => array(
			'flow_step_id' => 'ephemeral_step_0',
			'step_type'    => 'system_task',
		),
	),
);
assert_retry_policy_smoke(
	'ephemeral single-step workflow resolves its flow_step_id',
	'ephemeral_step_0' === $resolve_ephemeral->invoke( null, $direct_engine_data )
);

// Falls back to the array key when the step omits an explicit flow_step_id.
$keyed_engine_data = array(
	'flow_config' => array(
		'ephemeral_step_0' => array( 'step_type' => 'system_task' ),
	),
);
assert_retry_policy_smoke(
	'ephemeral step without explicit id falls back to its config key',
	'ephemeral_step_0' === $resolve_ephemeral->invoke( null, $keyed_engine_data )
);

// Non-resumable multi-step ephemeral workflows are not blindly resumed (the
// current #2810 fail-fast guard — unchanged).
$multi_engine_data = array(
	'flow_config' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'execution_order' => 1 ),
	),
);
assert_retry_policy_smoke(
	'non-resumable multi-step ephemeral workflow does not resolve a single step',
	'' === $resolve_ephemeral->invoke( null, $multi_engine_data )
);

// No flow_config (e.g. a genuine pipeline job) yields empty so the normal
// $context_data['flow_step_id'] path is used instead.
assert_retry_policy_smoke(
	'absent flow_config yields empty (defers to context flow_step_id)',
	'' === $resolve_ephemeral->invoke( null, array() )
);

echo "Case 8: Resumable multi-step ephemeral workflows resume from the first incomplete step (#2811)\n";

// A resumable multi-step workflow where step 0 completed but step 1 has not:
// the resume point is step 1, so step 0's already-applied effects are skipped.
$resumable_partial = array(
	'resumable'    => true,
	'flow_config'  => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'execution_order' => 1 ),
		'ephemeral_step_2' => array( 'flow_step_id' => 'ephemeral_step_2', 'execution_order' => 2 ),
	),
	'step_results' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'step_success' => true, 'result' => 'inline_continuation' ),
	),
);
assert_retry_policy_smoke(
	'resumable workflow resumes at the first step without a completed result',
	'ephemeral_step_1' === $resolve_ephemeral->invoke( null, $resumable_partial )
);
assert_retry_policy_smoke(
	'resolveResumeStepId returns the first incomplete step directly',
	'ephemeral_step_1' === $resolve_resume->invoke( null, $resumable_partial['flow_config'], $resumable_partial )
);

// execution_order — not array insertion order — drives the resume point.
$resumable_reordered = array(
	'resumable'    => true,
	'flow_config'  => array(
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'execution_order' => 1 ),
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
	),
	'step_results' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'step_success' => true ),
	),
);
assert_retry_policy_smoke(
	'resume point follows execution_order, not array order',
	'ephemeral_step_1' === $resolve_ephemeral->invoke( null, $resumable_reordered )
);

// A failed mid-flow step is the resume point even when a later step somehow
// recorded success — the first incomplete step in order wins.
$resumable_failed_mid = array(
	'resumable'    => true,
	'flow_config'  => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'execution_order' => 1 ),
	),
	'step_results' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'step_success' => false, 'result' => 'failed' ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'step_success' => true ),
	),
);
assert_retry_policy_smoke(
	'a failed step is the resume point even with later success recorded',
	'ephemeral_step_0' === $resolve_ephemeral->invoke( null, $resumable_failed_mid )
);

// Every step already completed: nothing to resume (not a partial-execution
// case) so resolution is empty and the workflow fails normally.
$resumable_all_done = array(
	'resumable'    => true,
	'flow_config'  => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'execution_order' => 1 ),
	),
	'step_results' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'step_success' => true ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1', 'step_success' => true ),
	),
);
assert_retry_policy_smoke(
	'fully-completed resumable workflow resolves no resume step',
	'' === $resolve_ephemeral->invoke( null, $resumable_all_done )
);

// Opt-in gate: the SAME partial multi-step snapshot WITHOUT the resumable flag
// still fails fast (no resume) — proving the default is non-resumable.
$non_resumable_partial            = $resumable_partial;
$non_resumable_partial['resumable'] = false;
assert_retry_policy_smoke(
	'partial multi-step workflow without resumable flag still fails fast',
	'' === $resolve_ephemeral->invoke( null, $non_resumable_partial )
);
unset( $non_resumable_partial['resumable'] );
assert_retry_policy_smoke(
	'partial multi-step workflow with absent resumable flag still fails fast',
	'' === $resolve_ephemeral->invoke( null, $non_resumable_partial )
);

// The single-step path from #2810 is unchanged regardless of the resumable
// flag — a single step is always its own resume point.
$single_with_flag = array(
	'resumable'   => true,
	'flow_config' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0', 'execution_order' => 0 ),
	),
);
assert_retry_policy_smoke(
	'single-step path is unchanged even when resumable flag is set',
	'ephemeral_step_0' === $resolve_ephemeral->invoke( null, $single_with_flag )
);

// step completion classification: step_success boolean is authoritative;
// terminal-success result strings count as completed; failed/absent do not.
assert_retry_policy_smoke( 'step_success=true counts as completed', true === $step_completed->invoke( null, array( 'step_success' => true ) ) );
assert_retry_policy_smoke( 'step_success=false counts as incomplete', false === $step_completed->invoke( null, array( 'step_success' => false, 'result' => 'failed' ) ) );
assert_retry_policy_smoke( 'completed result string counts as completed', true === $step_completed->invoke( null, array( 'result' => 'completed' ) ) );
assert_retry_policy_smoke( 'inline_continuation result counts as completed', true === $step_completed->invoke( null, array( 'result' => 'inline_continuation' ) ) );
assert_retry_policy_smoke( 'absent step result counts as incomplete', false === $step_completed->invoke( null, null ) );

// An invalid execution order (missing execution_order) cannot be safely
// resumed — fall back to fail-fast rather than guess.
$resumable_bad_order = array(
	'resumable'   => true,
	'flow_config' => array(
		'ephemeral_step_0' => array( 'flow_step_id' => 'ephemeral_step_0' ),
		'ephemeral_step_1' => array( 'flow_step_id' => 'ephemeral_step_1' ),
	),
);
assert_retry_policy_smoke(
	'resumable workflow with invalid execution order fails fast',
	'' === $resolve_ephemeral->invoke( null, $resumable_bad_order )
);

echo "\nJob retry policy smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
