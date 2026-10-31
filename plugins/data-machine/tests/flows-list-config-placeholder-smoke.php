<?php
/**
 * Smoke test for the flows/pipelines list "config" placeholder fix (#2754).
 *
 * The bug: `wp datamachine flows --format=json` emitted the `config` field as
 * the literal table display placeholder "—" (em-dash) when a flow had no
 * distinguishing config summary. A display dash in structured (json/csv/yaml)
 * output is a silent data-integrity bug — it reads as "this flow has config
 * '—'" when the flow simply has none. `flows get <id> --format=json` already
 * returned the real flow_config, so only the LIST view JSON lied.
 *
 * The fix: the summary extractors return the honest value (an EMPTY string
 * when there is nothing to summarize). The "—" empty-cell placeholder is a
 * table-display concern and is applied ONLY when building table rows.
 *
 * This test asserts the placeholder decision the row builders make:
 *   - table  + empty summary  → "—"   (human empty-cell)
 *   - table  + real summary   → summary verbatim
 *   - json/csv/yaml/ids/count + empty summary → ""   (honest, never a dash)
 *   - json/csv/yaml + real summary → summary verbatim
 *
 * Run with: php tests/flows-list-config-placeholder-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond   Condition.
 * @param string $label  Test label.
 * @param string $detail Optional detail.
 */
function datamachine_assert( bool $cond, string $label, string $detail = '' ): void {
	global $failures;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failures;
	echo "  [FAIL] {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

/**
 * Mirror of the production row-builder placeholder decision.
 *
 * Both FlowsCommand::__invoke (config column) and PipelinesCommand::__invoke
 * (location column) compute the cell as:
 *   ( $is_table && '' === $summary ) ? '—' : $summary
 *
 * @param string $summary  Honest summary string from the extractor.
 * @param string $format   Output format.
 * @return string Cell value.
 */
function datamachine_list_cell( string $summary, string $format ): string {
	$is_table = 'table' === $format;
	return ( $is_table && '' === $summary ) ? '—' : $summary;
}

echo "\n[1] table + empty summary → em-dash placeholder (human display)\n";
datamachine_assert( '—' === datamachine_list_cell( '', 'table' ), 'empty config in table shows "—"' );

echo "\n[2] structured formats + empty summary → honest empty string (no dash)\n";
foreach ( array( 'json', 'csv', 'yaml', 'ids', 'count' ) as $fmt ) {
	datamachine_assert(
		'' === datamachine_list_cell( '', $fmt ),
		"empty config in {$fmt} is '' not '—'",
		"got: '" . datamachine_list_cell( '', $fmt ) . "'"
	);
}

echo "\n[3] real summary passes through verbatim in every format\n";
$real = 'city=Charleston | r=50';
foreach ( array( 'table', 'json', 'csv', 'yaml' ) as $fmt ) {
	datamachine_assert(
		$real === datamachine_list_cell( $real, $fmt ),
		"real config preserved in {$fmt}"
	);
}

echo "\n[4] the literal em-dash never appears in structured output for an empty cell\n";
$json_cell = datamachine_list_cell( '', 'json' );
datamachine_assert(
	false === strpos( $json_cell, '—' ),
	'json cell contains no em-dash',
	"got: '{$json_cell}'"
);

echo "\n[5] source guard: extractors no longer hardcode the '—' fallback\n";
$flows_src     = (string) file_get_contents( __DIR__ . '/../inc/Cli/Commands/Flows/FlowsCommand.php' );
$pipelines_src = (string) file_get_contents( __DIR__ . '/../inc/Cli/Commands/PipelinesCommand.php' );
datamachine_assert(
	false === strpos( $flows_src, "? \$summary : '—'" ),
	'FlowsCommand::extractConfigSummary dropped the inline "—" fallback'
);
datamachine_assert(
	false === strpos( $pipelines_src, "? \$summary : '—'" ),
	'PipelinesCommand::extractPipelineLocation dropped the inline "—" fallback'
);
datamachine_assert(
	false !== strpos( $flows_src, "( \$is_table && '' === \$config_summary ) ? '—'" ),
	'FlowsCommand applies the "—" placeholder only for table format'
);
datamachine_assert(
	false !== strpos( $pipelines_src, "( \$is_table && '' === \$location ) ? '—'" ),
	'PipelinesCommand applies the "—" placeholder only for table format'
);

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s) failed.\n";
	exit( 1 );
}
echo "OK: all assertions passed.\n";
exit( 0 );
