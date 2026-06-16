<?php
/**
 * Pure-PHP smoke test for DailyMemoryTask conservation check.
 *
 * Run with: php tests/daily-memory-conservation-smoke.php
 *
 * Covers the conservation guardrail that blocks DailyMemoryTask from
 * committing a lossy MEMORY.md split. Before this fix the task only
 * validated that the persistent section was at least ~10% of the
 * original; an AI that emitted 20KB persistent + 335B archived from a
 * 55KB MEMORY.md silently lost ~35KB and the truncated file was
 * written. After this fix the task verifies that
 * persistent_size + archived_size ≈ original_size before writing.
 *
 * The lower-bound check is filterable via
 * `datamachine_daily_memory_conservation_threshold` (default 0.85). The
 * upper-bound expansion check is filterable via
 * `datamachine_daily_memory_max_combined_ratio` (default 1.10).
 *
 * The guard now delegates to Agents API conservation metadata while keeping
 * Data Machine-owned raw MEMORY.md byte accounting. This smoke exercises the
 * production planning helper instead of mirroring the arithmetic inline.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int $bytes ): string {
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['datamachine_daily_memory_conservation_smoke_filters'] = array();

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $hook, $callback ): void {
			$GLOBALS['datamachine_daily_memory_conservation_smoke_filters'][ $hook ] = $callback;
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$callback = $GLOBALS['datamachine_daily_memory_conservation_smoke_filters'][ $hook ] ?? null;
		return is_callable( $callback ) ? $callback( $value ) : $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op for tests
	}
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/FilesRepository/AgentMemory.php';
require_once __DIR__ . '/../inc/Engine/AI/NaturalCompletionPolicyInterface.php';
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SystemTask.php';
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/DailyMemoryTask.php';

/**
 * Evaluate the production daily-memory compaction plan for synthetic sizes.
 *
 * Returns ['committed' => bool, 'reason' => string] so test assertions can
 * verify both happy-path and reject-path behaviour.
 */
function evaluate_conservation(
	int $original_size,
	int $persistent_size,
	int $archived_size,
	float $threshold = 0.85,
	float $max_combined_ratio = 1.10
): array {
	add_filter(
		'datamachine_daily_memory_conservation_threshold',
		static function () use ( $threshold ): float {
			return $threshold;
		}
	);

	add_filter(
		'datamachine_daily_memory_max_combined_ratio',
		static function () use ( $max_combined_ratio ): float {
			return $max_combined_ratio;
		}
	);

	$task   = new DataMachine\Engine\AI\System\Tasks\DailyMemoryTask();
	$method = new ReflectionMethod( $task, 'planMemoryCompaction' );
	$plan   = $method->invoke(
		$task,
		str_repeat( 'o', $original_size ),
		"===PERSISTENT===\n" . str_repeat( 'p', $persistent_size ) . "\n===ARCHIVED===\n" . str_repeat( 'a', $archived_size ),
		'2026-05-01',
		123,
		'openai',
		'gpt-test'
	);

	if ( empty( $plan['success'] ) ) {
		return array(
			'committed' => false,
			'reason'    => $plan['message'] ?? 'failed',
			'plan'      => $plan,
		);
	}

	return array(
		'committed' => true,
		'reason'    => 'conservation ok',
		'plan'      => $plan,
	);
}

/**
 * Evaluate the daily-memory natural completion policy for synthetic output.
 */
function evaluate_completion_policy(
	int $original_size,
	int $persistent_size,
	int $archived_size
): array {
	$task   = new DataMachine\Engine\AI\System\Tasks\DailyMemoryTask();
	$method = new ReflectionMethod( $task, 'buildCleanupCompletionPolicy' );
	$policy = $method->invoke(
		$task,
		str_repeat( 'o', $original_size ),
		'2026-05-01',
		123,
		'openai',
		'gpt-test'
	);

	$decision = $policy->recordNaturalCompletion(
		array(),
		"===PERSISTENT===\n" . str_repeat( 'p', $persistent_size ) . "\n===ARCHIVED===\n" . str_repeat( 'a', $archived_size ),
		array(),
		1
	);

	return array(
		'complete' => $decision->isComplete(),
		'message'  => $decision->message(),
		'context'  => $decision->context(),
	);
}

$source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/DailyMemoryTask.php' );

$failures = array();
$passes   = 0;

function assert_committed( bool $expected, array $result, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $result['committed'] ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo "    expected committed: " . ( $expected ? 'true' : 'false' ) . "\n";
	echo "    actual:             " . ( $result['committed'] ? 'true' : 'false' ) . "\n";
	echo "    reason: {$result['reason']}\n";
}

echo "daily memory conservation smoke\n";
echo "-------------------------------\n";

assert_committed( true, array( 'committed' => str_contains( $source, 'WP_Agent_Compaction_Conservation::metadata' ), 'reason' => 'source check' ), 'production task uses Agents API conservation metadata', $failures, $passes );
assert_committed( true, array( 'committed' => str_contains( $source, 'WP_Agent_Compaction_Conservation::failed_closed' ), 'reason' => 'source check' ), 'production task uses Agents API fail-closed decision', $failures, $passes );

// Test 1: real-world reproducer from intelligence-chubes4 2026-04-25.
// 55KB original, 20KB persistent, 335B archived. Should reject.
echo "\n[1] reproducer from live failure (55KB → 20KB + 335B):\n";
$result = evaluate_conservation( 55 * 1024, 20 * 1024, 335 );
assert_committed( false, $result, 'live failure case is rejected', $failures, $passes );

// Test 2: legitimate compaction with full archive (e.g. 60KB → 20KB persistent + 35KB archived).
// Combined = 55KB ≈ 95% of 58KB original — passes 85% threshold.
echo "\n[2] healthy compaction (58KB → 20KB persistent + 35KB archived):\n";
$result = evaluate_conservation( 58 * 1024, 20 * 1024, 35 * 1024 );
assert_committed( true, $result, 'healthy compaction commits', $failures, $passes );
assert_committed( true, array( 'committed' => 'compacted' === ( $result['plan']['metadata']['status'] ?? '' ), 'reason' => 'metadata status' ), 'healthy compaction reports Agents API compaction metadata', $failures, $passes );
assert_committed( true, array( 'committed' => 'compaction_completed' === ( $result['plan']['events'][0]['type'] ?? '' ), 'reason' => 'event type' ), 'healthy compaction emits lifecycle event', $failures, $passes );

// Test 3: edge case — exactly at 85% threshold.
echo "\n[3] exactly at 85% threshold:\n";
$result = evaluate_conservation( 1000, 850, 0 );
assert_committed( true, $result, '850/1000 with threshold 0.85 commits', $failures, $passes );

// Test 4: just below threshold.
echo "\n[4] just below 85% threshold:\n";
$result = evaluate_conservation( 1000, 849, 0 );
assert_committed( false, $result, '849/1000 with threshold 0.85 rejects', $failures, $passes );

// Test 5: filter override to disable check.
echo "\n[5] threshold = 0 disables the check (existing behaviour):\n";
$result = evaluate_conservation( 55 * 1024, 20 * 1024, 335, 0.0 );
assert_committed( true, $result, 'threshold 0 lets old behaviour through', $failures, $passes );

// Test 6: filter override to a stricter threshold.
echo "\n[6] strict threshold (0.95) tightens the gate:\n";
$result = evaluate_conservation( 1000, 850, 100, 0.95 );
assert_committed( true, $result, '950/1000 ≥ 95% commits', $failures, $passes );

$result = evaluate_conservation( 1000, 850, 50, 0.95 );
assert_committed( false, $result, '900/1000 < 95% rejects under strict threshold', $failures, $passes );

// Test 7: zero archived (compaction with no archive). Persistent must
// stand on its own at >= threshold of original.
echo "\n[7] no archive section, persistent ≥ threshold:\n";
$result = evaluate_conservation( 1000, 900, 0 );
assert_committed( true, $result, '900/1000 commits without archive', $failures, $passes );

$result = evaluate_conservation( 1000, 800, 0 );
assert_committed( false, $result, '800/1000 rejects without archive', $failures, $passes );

// Test 8: no compaction at all (idempotent or no-op case). Persistent
// equals original, archive is empty. Must commit.
echo "\n[8] no-op case (persistent == original, archive empty):\n";
$result = evaluate_conservation( 5000, 5000, 0 );
assert_committed( true, $result, 'no-op compaction commits', $failures, $passes );

// Test 9: combined substantially exceeds original (AI duplicated content into
// both sections). This used to commit, yielding logs such as
// "9 KB -> 9 KB (2 KB archived)" while MEMORY.md stayed oversized.
echo "\n[9] combined > original (AI duplicated into both sections):\n";
$result = evaluate_conservation( 1000, 800, 600 );
assert_committed( false, $result, 'duplicate split rejects by default', $failures, $passes );

// Test 10: small expansion is allowed for headings, bullets, and formatting
// churn in otherwise valid model output.
echo "\n[10] small formatting expansion is allowed:\n";
$result = evaluate_conservation( 1000, 700, 375 );
assert_committed( true, $result, '7.5% expansion commits', $failures, $passes );

// Test 11: the expansion guard can be disabled independently for installs
// that intentionally tolerate larger rewritten output.
echo "\n[11] max combined ratio = 0 disables expansion check:\n";
$result = evaluate_conservation( 1000, 800, 600, 0.85, 0.0 );
assert_committed( true, $result, 'disabled expansion check lets duplicate split through', $failures, $passes );

// Test 12: system task conversation completion policy rejects a cleanup that
// remains over MEMORY.md's target size. This covers the live follow-up shape:
// 9301B original -> 9233B persistent + 643B archived should iterate, not commit.
echo "\n[12] daily-memory completion policy enforces target size:\n";
$policy_result = evaluate_completion_policy( 9301, 9233, 643 );
assert_committed( false, array( 'committed' => $policy_result['complete'], 'reason' => $policy_result['message'] ), 'oversized persistent output requests another turn', $failures, $passes );
assert_committed( true, array( 'committed' => ! empty( $policy_result['context']['continuation_message'] ), 'reason' => 'continuation context' ), 'oversized output includes continuation nudge', $failures, $passes );

$policy_result = evaluate_completion_policy( 9301, 7600, 1700 );
assert_committed( true, array( 'committed' => $policy_result['complete'], 'reason' => $policy_result['message'] ), 'under-target persistent output completes', $failures, $passes );

assert_committed( true, array( 'committed' => method_exists( $task ?? new DataMachine\Engine\AI\System\Tasks\DailyMemoryTask(), 'runAiConversation' ), 'reason' => 'system task helper' ), 'system tasks expose generic AI conversation loop helper', $failures, $passes );

echo "\n-------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
exit( 0 );
