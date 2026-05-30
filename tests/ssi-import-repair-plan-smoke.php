<?php
/**
 * Pure-PHP smoke test for SSI import repair-plan task.
 *
 * Run with: php tests/ssi-import-repair-plan-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SystemTask.php';
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SSIImportRepairPlanTask.php';

use DataMachine\Engine\AI\System\Tasks\SSIImportRepairPlanTask;

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition, string $detail = '' ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}" . ( '' !== $detail ? " - {$detail}" : '' ) . "\n";
};

$root = sys_get_temp_dir() . '/datamachine-ssi-repair-plan-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/templates', 0777, true );
file_put_contents( $root . '/index.html', '<main><iframe src="https://example.com/widget"></iframe><a href="missing.html">Missing</a></main>' );
file_put_contents( $root . '/templates/front-page.html', '<!-- wp:html --><aside class="widget-card"></aside><!-- /wp:html -->' );

echo "=== ssi-import-repair-plan-smoke ===\n";

$report = array(
	'version'          => 1,
	'entry_file'       => $root . '/index.html',
	'source_documents' => array(
		'unresolved_links'      => array(
			array(
				'source' => 'index.html',
				'href'   => 'missing.html',
			),
		),
		'unresolved_link_count' => 1,
	),
	'diagnostics'      => array(
		array(
			'type'                => 'unsupported_html_fallback',
			'source'              => 'main:index.html',
			'selector'            => 'iframe#store-widget.embedded.checkout',
			'block_name'          => 'core/html',
			'converter'           => 'html-to-blocks-converter',
			'stage'               => 'html_to_blocks',
			'reason'              => 'no_transform',
			'source_html_preview' => '<iframe id="store-widget" class="embedded checkout" src="https://example.com/widget"></iframe>',
		),
		array(
			'type'       => 'core_html_block',
			'source'     => 'templates/front-page.html',
			'selector'   => 'aside.widget-card',
			'block_name' => 'core/html',
			'stage'      => 'generated_theme_block_analysis',
			'reason'     => 'generated_document_contains_core_html',
		),
		array(
			'type'        => 'local_asset_not_materialized',
			'source_path' => 'index.html',
			'href'        => 'assets/missing.svg',
		),
		array(
			'type'   => 'possible_text_loss',
			'source' => 'index.html',
		),
		array(
			'type'   => 'conversion_failed',
			'source' => '../outside.html',
		),
	),
	'proposed_edits'   => array(
		array(
			'operation' => 'update',
			'path'      => 'index.html',
		),
		array(
			'operation' => 'delete',
			'path'      => 'index.html',
		),
		array(
			'operation' => 'update',
			'path'      => '../wp-config.php',
		),
	),
);

$result = SSIImportRepairPlanTask::buildRepairPlan(
	array(
		'source_tree_path' => $root,
		'import_report'     => json_encode( $report ),
		'context'           => array( 'caller' => 'codebox' ),
		'max_actions'       => 20,
	)
);

$assert( 'repair plan succeeds', true === ( $result['success'] ?? false ) );
$plan    = $result['repair_plan'] ?? array();
$summary = $result['summary'] ?? array();
$actions = $plan['actions'] ?? array();

$categories = array_values( array_unique( array_map( static fn ( array $action ): string => (string) ( $action['category'] ?? '' ), $actions ) ) );
sort( $categories, SORT_STRING );

$assert( 'plan remains plan-only', 'plan_only' === ( $plan['mode'] ?? '' ) );
$assert( 'host mutation summary is false', false === ( $summary['mutated_host'] ?? null ) );
$assert( 'changed files stay empty', array() === ( $summary['changed_files'] ?? null ) && array() === ( $plan['changed_files'] ?? null ) );
$assert( 'fallback diagnostics become repair actions', in_array( 'fallback_block', $categories, true ), implode( ', ', $categories ) );
$assert( 'asset diagnostics become repair actions', in_array( 'unresolved_asset', $categories, true ), implode( ', ', $categories ) );
$assert( 'broken links become repair actions', in_array( 'broken_link', $categories, true ), implode( ', ', $categories ) );
$assert( 'conversion issues become repair actions', in_array( 'conversion_issue', $categories, true ), implode( ', ', $categories ) );

$refused = array_values( array_filter( $actions, static fn ( array $action ): bool => 'refused' === ( $action['status'] ?? '' ) ) );
$assert( 'out-of-root diagnostic is refused', count( array_filter( $refused, static fn ( array $action ): bool => 'refuse_out_of_root_file_edit' === ( $action['action'] ?? '' ) ) ) >= 1 );
$assert( 'destructive requested edit is refused', count( array_filter( $refused, static fn ( array $action ): bool => 'destructive_operation' === ( $action['safety']['reason'] ?? '' ) ) ) >= 1 );
$assert( 'out-of-root requested edit is refused', count( array_filter( $refused, static fn ( array $action ): bool => 'out_of_root' === ( $action['safety']['reason'] ?? '' ) ) ) >= 1 );
$assert( 'safe requested edit remains planned', count( array_filter( $actions, static fn ( array $action ): bool => 'validate_sandbox_file_update' === ( $action['action'] ?? '' ) && 'planned' === ( $action['status'] ?? '' ) ) ) >= 1 );

$missing_root = SSIImportRepairPlanTask::buildRepairPlan(
	array(
		'source_tree_path' => $root . '/missing',
		'import_report'     => $report,
	)
);
$assert( 'missing source tree fails early', false === ( $missing_root['success'] ?? true ) );

@unlink( $root . '/templates/front-page.html' );
@rmdir( $root . '/templates' );
@unlink( $root . '/index.html' );
@rmdir( $root );

if ( $failures > 0 ) {
	echo "\n=== ssi-import-repair-plan-smoke: {$failures}/{$total} FAIL ===\n";
	exit( 1 );
}

echo "\n=== ssi-import-repair-plan-smoke: ALL PASS ({$total}) ===\n";
