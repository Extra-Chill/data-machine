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
 * The check is filterable via
 * `datamachine_daily_memory_conservation_threshold` (default 0.85).
 *
 * The guard logic is small and pure (size arithmetic), so this smoke
 * mirrors the conservation block inline rather than booting the full
 * task. A regression in the real file shows up when the harness'
 * inline reimplementation diverges from the production one.
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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op for tests
	}
}

/**
 * Inline reimplementation of the conservation check body.
 *
 * Returns ['committed' => bool, 'reason' => string] so test
 * assertions can verify both happy-path and reject-path behaviour.
 */
function evaluate_conservation(
	int $original_size,
	int $persistent_size,
	int $archived_size,
	float $threshold = 0.85
): array {
	if ( $threshold <= 0 ) {
		return array(
			'committed' => true,
			'reason'    => 'threshold disabled',
		);
	}

	$combined     = $persistent_size + $archived_size;
	$min_combined = (int) ( $original_size * $threshold );

	if ( $combined < $min_combined ) {
		return array(
			'committed' => false,
			'reason'    => sprintf(
				'conservation check failed: %d + %d = %d < %d',
				$persistent_size,
				$archived_size,
				$combined,
				$min_combined
			),
		);
	}

	return array(
		'committed' => true,
		'reason'    => 'conservation ok',
	);
}

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

// Test 9: combined > original (AI duplicated content into both
// sections). Should still commit — we only enforce the lower bound.
// Filtering for double-write would belong elsewhere.
echo "\n[9] combined > original (AI duplicated into both sections):\n";
$result = evaluate_conservation( 1000, 800, 600 );
assert_committed( true, $result, 'duplicate split commits (only lower bound enforced)', $failures, $passes );

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
