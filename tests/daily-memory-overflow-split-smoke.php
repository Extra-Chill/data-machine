<?php
/**
 * Pure-PHP smoke for the Daily Memory deterministic overflow archive plan.
 *
 * Run with: php tests/daily-memory-overflow-split-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$failures = array();
$passes   = 0;

function datamachine_overflow_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "FAIL: {$label}\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $_hook, callable $_callback, int $_priority = 10, int $_accepted_args = 1 ): void {}
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SystemTask.php';
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/DailyMemoryTask.php';

$source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/DailyMemoryTask.php' );
datamachine_overflow_assert( str_contains( $source, 'maybeHandleDeterministicOverflow' ), 'production task has deterministic overflow hook', $failures, $passes );
datamachine_overflow_assert( ! str_contains( $source, 'splitMemorySectionsForOverflow' ), 'local section-split helper is removed', $failures, $passes );
datamachine_overflow_assert( str_contains( $source, 'WP_Agent_Markdown_Section_Compaction_Adapter::split_for_overflow' ), 'overflow decision is delegated to Agents API markdown compaction', $failures, $passes );
datamachine_overflow_assert( str_contains( $source, 'WP_Agent_Markdown_Section_Compaction_Adapter::parse' ), 'markdown projection uses Agents API adapter', $failures, $passes );
datamachine_overflow_assert( ! str_contains( $source, 'WP_Agent_Message::text' ), 'fake conversation-message projection is removed', $failures, $passes );
datamachine_overflow_assert( str_contains( $source, 'datamachine_daily_memory_overflow_threshold' ), 'overflow threshold is filterable', $failures, $passes );
datamachine_overflow_assert( str_contains( $source, 'datamachine_daily_memory_overflow_target_size' ), 'overflow target size is filterable', $failures, $passes );

$method = new ReflectionMethod( DataMachine\Engine\AI\System\Tasks\DailyMemoryTask::class, 'planMemoryOverflowArchive' );

$sections = array();
$content  = "# Agent Memory\n\nIntro stays.\n\n";
for ( $i = 1; $i <= 90; $i++ ) {
	$newline         = 0 === $i % 3 ? "\r\n" : "\n";
	$sections[ $i ]  = "## Section {$i}{$newline}{$newline}" . str_repeat( "Line {$i} persistent or session detail.{$newline}", 80 ) . $newline;
	$content        .= $sections[ $i ];
}

$split = $method->invoke( null, $content, 8192, '2026-05-01' );
datamachine_overflow_assert( '' !== $split['archived'], 'oversized input produces archive content', $failures, $passes );
datamachine_overflow_assert( str_contains( $split['persistent'], 'Archived Memory Overflow' ), 'persistent output includes archive pointer', $failures, $passes );
datamachine_overflow_assert( str_contains( $split['persistent'], 'daily/2026/05/01.md' ), 'archive pointer names daily file path', $failures, $passes );
datamachine_overflow_assert( str_contains( $split['persistent'], '## Section 1' ), 'persistent output keeps early sections', $failures, $passes );
datamachine_overflow_assert( strlen( $content ) > 250000, '~250KB live stress input shape is covered', $failures, $passes );
datamachine_overflow_assert( strlen( $split['persistent'] ) <= 8192, 'persistent output reduces to target size', $failures, $passes );
datamachine_overflow_assert( str_contains( $split['archived'], $sections[90] ), 'archive output keeps later sections verbatim', $failures, $passes );
datamachine_overflow_assert( ! str_contains( $split['persistent'], '## Section 90' ), 'archived tail sections are removed from persistent output', $failures, $passes );
datamachine_overflow_assert( $split['persistent_blocks'] > 0, 'persistent block count reported', $failures, $passes );
datamachine_overflow_assert( $split['archived_blocks'] > 0, 'archived block count reported', $failures, $passes );

$small = $method->invoke( null, "## Only\n\nSmall file.\n", 1400, '2026-05-01' );
datamachine_overflow_assert( '' === $small['archived'], 'single-section small input does not split', $failures, $passes );

echo "\n{$passes} passed, " . count( $failures ) . " failed\n";
if ( ! empty( $failures ) ) {
	exit( 1 );
}
