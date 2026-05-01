<?php
/**
 * Pure-PHP smoke for the Daily Memory deterministic overflow split.
 *
 * Run with: php tests/daily-memory-overflow-split-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$failures = array();
$passes   = 0;

function dm_overflow_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "FAIL: {$label}\n";
}

/**
 * Mirrors DailyMemoryTask::splitMemorySectionsForOverflow().
 *
 * @return array{persistent: string, archived: string, persistent_blocks: int, archived_blocks: int}
 */
function dm_overflow_split( string $content, int $target_size, string $date ): array {
	$blocks = preg_split( '/(?=^## .+$)/m', trim( $content ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $blocks ) || count( $blocks ) < 2 ) {
		return array(
			'persistent'        => $content,
			'archived'          => '',
			'persistent_blocks' => count( is_array( $blocks ) ? $blocks : array() ),
			'archived_blocks'   => 0,
		);
	}

	$persistent = array();
	$archived   = array();
	$pointer    = sprintf(
		"\n## Archived Memory Overflow\n\nOn %s, Daily Memory archived older MEMORY.md sections verbatim to `daily/%s`. Use daily memory search/read when those details are needed.\n",
		$date,
		str_replace( '-', '/', $date ) . '.md'
	);

	foreach ( $blocks as $index => $block ) {
		$block = trim( $block );
		if ( '' === $block ) {
			continue;
		}

		$candidate = implode( "\n\n", array_merge( $persistent, array( $block ) ) ) . $pointer;
		if ( 0 === $index || strlen( $candidate ) <= $target_size ) {
			$persistent[] = $block;
			continue;
		}

		$archived[] = $block;
	}

	if ( empty( $archived ) ) {
		return array(
			'persistent'        => $content,
			'archived'          => '',
			'persistent_blocks' => count( $persistent ),
			'archived_blocks'   => 0,
		);
	}

	return array(
		'persistent'        => rtrim( implode( "\n\n", $persistent ) . $pointer ) . "\n",
		'archived'          => implode( "\n\n", $archived ),
		'persistent_blocks' => count( $persistent ),
		'archived_blocks'   => count( $archived ),
	);
}

$source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/DailyMemoryTask.php' );
dm_overflow_assert( str_contains( $source, 'maybeHandleDeterministicOverflow' ), 'production task has deterministic overflow hook', $failures, $passes );
dm_overflow_assert( str_contains( $source, 'splitMemorySectionsForOverflow' ), 'production task has section-split helper', $failures, $passes );
dm_overflow_assert( str_contains( $source, 'datamachine_daily_memory_overflow_threshold' ), 'overflow threshold is filterable', $failures, $passes );
dm_overflow_assert( str_contains( $source, 'datamachine_daily_memory_overflow_target_size' ), 'overflow target size is filterable', $failures, $passes );

$content = "# Agent Memory\n\nIntro stays.\n\n";
for ( $i = 1; $i <= 8; $i++ ) {
	$content .= "## Section {$i}\n\n" . str_repeat( "Line {$i} persistent or session detail.\n", 12 ) . "\n";
}

$split = dm_overflow_split( $content, 1400, '2026-05-01' );
dm_overflow_assert( '' !== $split['archived'], 'oversized input produces archive content', $failures, $passes );
dm_overflow_assert( str_contains( $split['persistent'], 'Archived Memory Overflow' ), 'persistent output includes archive pointer', $failures, $passes );
dm_overflow_assert( str_contains( $split['persistent'], 'daily/2026/05/01.md' ), 'archive pointer names daily file path', $failures, $passes );
dm_overflow_assert( str_contains( $split['persistent'], '## Section 1' ), 'persistent output keeps early sections', $failures, $passes );
dm_overflow_assert( str_contains( $split['archived'], '## Section 8' ), 'archive output keeps later sections verbatim', $failures, $passes );
dm_overflow_assert( ! str_contains( $split['persistent'], '## Section 8' ), 'archived sections are removed from persistent output', $failures, $passes );
dm_overflow_assert( $split['persistent_blocks'] > 0, 'persistent block count reported', $failures, $passes );
dm_overflow_assert( $split['archived_blocks'] > 0, 'archived block count reported', $failures, $passes );

$small = dm_overflow_split( "## Only\n\nSmall file.\n", 1400, '2026-05-01' );
dm_overflow_assert( '' === $small['archived'], 'single-section small input does not split', $failures, $passes );

echo "\n{$passes} passed, " . count( $failures ) . " failed\n";
if ( ! empty( $failures ) ) {
	exit( 1 );
}
