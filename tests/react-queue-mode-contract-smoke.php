<?php
/**
 * Pure-PHP smoke test for the React queue UI ↔ REST contract (#1300).
 *
 * Run with: php tests/react-queue-mode-contract-smoke.php
 *
 * #1296 (closed #1291) collapsed `queue_enabled` (boolean) into a
 * `queue_mode` enum on every queueable step, and replaced the old
 * `PUT /flows/{id}/queue/settings { queue_enabled }` endpoint with
 * `PUT /flows/{id}/queue/mode { mode: "drain" | "loop" | "static" }`.
 * The PR explicitly carved React out of scope (#1300).
 *
 * #1300 cleans up the React UI to read/write the new shape. Three
 * regressions had to be eliminated:
 *
 *   1. `queue.js::useFlowQueue()` cast `response.data?.queue_enabled`
 *      to a boolean. Post-#1296 the response field is `queue_mode`
 *      (string); the boolean cast resolved to `false` for every step,
 *      every time, silently degrading the UI.
 *
 *   2. `api.js::updateFlowQueueSettings()` POSTed to the dead
 *      `/queue/settings` endpoint with `{ queue_enabled: bool }` body.
 *      The endpoint no longer exists; the new endpoint is `/queue/mode`
 *      with `{ mode }` body.
 *
 *   3. `FlowStepCard.jsx` read `flowStepConfig.queue_enabled` from a
 *      flow_config blob that no longer carries that key. The card
 *      rendered the queue surface as off forever.
 *
 * This smoke locks the contract by grepping the React source files
 * for the exact strings and shape the server side expects. It does
 * NOT load WordPress or evaluate JSX — it asserts on file contents.
 * Drift between React and PHP is caught at this boundary.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_react_contract( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

/**
 * Read a React source file relative to the worktree root.
 *
 * @param string $relative Path under `inc/Core/Admin/Pages/Pipelines/assets/react`.
 * @return string File contents.
 */
function read_react_file( string $relative ): string {
	$base = dirname( __DIR__ ) . '/inc/Core/Admin/Pages/Pipelines/assets/react';
	$path = $base . '/' . ltrim( $relative, '/' );
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Missing React source: {$path}\n" );
		exit( 2 );
	}
	return (string) file_get_contents( $path );
}

echo "=== React Queue Mode Contract Smoke (#1300) ===\n";

// ---------------------------------------------------------------
// SECTION 1: api.js — write-side endpoint shape.
// ---------------------------------------------------------------

echo "\n[api.js:1] updateFlowQueueMode export exists\n";
$api = read_react_file( 'utils/api.js' );
assert_react_contract(
	'updateFlowQueueMode is exported',
	false !== strpos( $api, 'export const updateFlowQueueMode' )
);

echo "\n[api.js:2] PUT URL is /queue/mode (not /queue/settings)\n";
assert_react_contract(
	'PUT URL targets /queue/mode',
	false !== strpos( $api, '/flows/${ flowId }/queue/mode' )
);
assert_react_contract(
	'legacy /queue/settings endpoint is gone',
	false === strpos( $api, '/queue/settings' )
);

echo "\n[api.js:3] Request body uses `mode`, not `queue_enabled`\n";
// The body literal must contain `mode,` (shorthand property) and NOT
// `queue_enabled`. Match on a tight slice that includes the body
// object so we don't false-positive against unrelated `mode` strings.
preg_match( '/queue\/mode`,\s*\{([^}]+)\}/s', $api, $body_match );
$body_block = $body_match[1] ?? '';
assert_react_contract(
	'request body contains `flow_step_id: flowStepId`',
	false !== strpos( $body_block, 'flow_step_id: flowStepId' )
);
assert_react_contract(
	'request body contains `mode` shorthand',
	(bool) preg_match( '/\bmode,?\s/', $body_block )
);
assert_react_contract(
	'request body does NOT contain queue_enabled',
	false === strpos( $body_block, 'queue_enabled' )
);

echo "\n[api.js:4] Legacy updateFlowQueueSettings export is gone\n";
assert_react_contract(
	'updateFlowQueueSettings export removed',
	false === strpos( $api, 'export const updateFlowQueueSettings' )
);

// ---------------------------------------------------------------
// SECTION 2: queries/queue.js — read-side normalization + mode mutation.
// ---------------------------------------------------------------

echo "\n[queue.js:1] useFlowQueue reads queue_mode (not queue_enabled)\n";
$queries = read_react_file( 'queries/queue.js' );
assert_react_contract(
	'queryFn reads response.data?.queue_mode',
	false !== strpos( $queries, 'response.data?.queue_mode' )
);
assert_react_contract(
	'queryFn does NOT read response.data?.queue_enabled',
	false === strpos( $queries, 'response.data?.queue_enabled' )
);

echo "\n[queue.js:2] queueMode is normalized to enum (default static)\n";
assert_react_contract(
	'normalizeQueueMode helper exists',
	false !== strpos( $queries, 'const normalizeQueueMode' )
);
assert_react_contract(
	'QUEUE_MODES enum lists drain/loop/static',
	(bool) preg_match(
		"/QUEUE_MODES\s*=\s*\[\s*'drain',\s*'loop',\s*'static'\s*\]/",
		$queries
	)
);
assert_react_contract(
	"normalize falls back to 'static' for unknown values",
	false !== strpos( $queries, "? value : 'static'" )
);

echo "\n[queue.js:3] useUpdateQueueMode hook exists, posts {mode}\n";
assert_react_contract(
	'useUpdateQueueMode export exists',
	false !== strpos( $queries, 'export const useUpdateQueueMode' )
);
assert_react_contract(
	'useUpdateQueueMode mutationFn destructures `mode`',
	(bool) preg_match(
		'/mutationFn:\s*\(\s*\{\s*flowId,\s*flowStepId,\s*mode\s*\}\s*\)\s*=>\s*updateFlowQueueMode/',
		$queries
	)
);

echo "\n[queue.js:4] Legacy useUpdateQueueSettings export is gone\n";
assert_react_contract(
	'useUpdateQueueSettings export removed',
	false === strpos( $queries, 'export const useUpdateQueueSettings' )
);

echo "\n[queue.js:5] queueEnabled identifier is gone from logic (only doc comment refs allowed)\n";
// The doc comment on useUpdateQueueMode intentionally references the
// legacy name for migration breadcrumbs. Allow exactly that one string;
// reject any code-level use of queueEnabled / queue_enabled.
$queue_enabled_hits   = substr_count( $queries, 'queueEnabled' );
$queue_enabled_legacy = substr_count(
	$queries,
	'`useUpdateQueueSettings` boolean toggle'
);
assert_react_contract(
	'queueEnabled identifier appears only in the migration doc comment',
	0 === $queue_enabled_hits && 1 === $queue_enabled_legacy
);
assert_react_contract(
	'queue_enabled snake_case identifier is gone',
	false === strpos( $queries, 'queue_enabled' )
);

// ---------------------------------------------------------------
// SECTION 3: FlowStepCard.jsx — read queue_mode off the flow_step_config blob.
// ---------------------------------------------------------------

echo "\n[FlowStepCard.jsx:1] Card reads flowStepConfig.queue_mode\n";
$card = read_react_file( 'components/flows/FlowStepCard.jsx' );
assert_react_contract(
	'card reads flowStepConfig.queue_mode',
	false !== strpos( $card, 'flowStepConfig.queue_mode' )
);
assert_react_contract(
	'card does NOT read flowStepConfig.queue_enabled',
	false === strpos( $card, 'flowStepConfig.queue_enabled' )
);

echo "\n[FlowStepCard.jsx:2] queueMode is normalized + queue surface gates on non-static\n";
assert_react_contract(
	'card normalizes via the drain/loop/static includes check',
	(bool) preg_match(
		"/\[\s*'drain',\s*'loop',\s*'static'\s*\]\.includes\(\s*rawQueueMode\s*\)/",
		$card
	)
);
assert_react_contract(
	"card defaults missing/invalid queue_mode to 'static'",
	false !== strpos( $card, ": 'static'" )
);
assert_react_contract(
	"queue surface visible when mode !== 'static' OR queue has items",
	false !== strpos( $card, "queueMode !== 'static' || queueHasItems" )
);

echo "\n[FlowStepCard.jsx:3] queueMode is passed down to QueueablePromptField\n";
assert_react_contract(
	'card passes queueMode prop',
	false !== strpos( $card, 'queueMode={ queueMode }' )
);
assert_react_contract(
	'card does NOT pass legacy queueEnabled prop',
	false === strpos( $card, 'queueEnabled=' )
);

// ---------------------------------------------------------------
// SECTION 4: QueueablePromptField.jsx — accepts queueMode prop.
// ---------------------------------------------------------------

echo "\n[QueueablePromptField.jsx:1] Component signature uses queueMode\n";
$field = read_react_file( 'components/flows/QueueablePromptField.jsx' );
assert_react_contract(
	"queueMode prop has 'static' default",
	(bool) preg_match( "/queueMode\s*=\s*'static'/", $field )
);
assert_react_contract(
	'legacy queueEnabled prop is gone',
	false === strpos( $field, 'queueEnabled' )
);

echo "\n[QueueablePromptField.jsx:2] shouldUseQueue gates on non-static OR items\n";
assert_react_contract(
	"shouldUseQueue includes queueMode !== 'static'",
	false !== strpos( $field, "queueMode !== 'static' || queueHasItems" )
);

// ---------------------------------------------------------------
// SECTION 5: FlowQueueModal.jsx — three-mode SelectControl wired to PUT /queue/mode.
// ---------------------------------------------------------------

echo "\n[FlowQueueModal.jsx:1] Modal imports useUpdateQueueMode, not useUpdateQueueSettings\n";
$modal = read_react_file( 'components/modals/FlowQueueModal.jsx' );
assert_react_contract(
	'modal imports useUpdateQueueMode',
	false !== strpos( $modal, 'useUpdateQueueMode' )
);
assert_react_contract(
	'modal does NOT import useUpdateQueueSettings',
	false === strpos( $modal, 'useUpdateQueueSettings' )
);

echo "\n[FlowQueueModal.jsx:2] Modal uses SelectControl with three modes\n";
assert_react_contract(
	'modal imports SelectControl',
	(bool) preg_match( '/SelectControl,\s*\n/', $modal )
);
assert_react_contract(
	'modal does NOT import CheckboxControl',
	false === strpos( $modal, 'CheckboxControl' )
);
assert_react_contract(
	"QUEUE_MODE_OPTIONS includes value: 'static'",
	false !== strpos( $modal, "value: 'static'" )
);
assert_react_contract(
	"QUEUE_MODE_OPTIONS includes value: 'drain'",
	false !== strpos( $modal, "value: 'drain'" )
);
assert_react_contract(
	"QUEUE_MODE_OPTIONS includes value: 'loop'",
	false !== strpos( $modal, "value: 'loop'" )
);

echo "\n[FlowQueueModal.jsx:3] Modal mutation passes `mode` (not queueEnabled)\n";
// Find the .mutate({ ... }) call inside handleQueueModeChange.
preg_match(
	'/updateModeMutation\.mutate\(\s*\{([^}]+)\}\s*\)/',
	$modal,
	$mutate_match
);
$mutate_block = $mutate_match[1] ?? '';
assert_react_contract(
	'mutate call contains `mode`',
	(bool) preg_match( '/\bmode,/', $mutate_block )
);
assert_react_contract(
	'mutate call does NOT contain queueEnabled',
	false === strpos( $mutate_block, 'queueEnabled' )
);

echo "\n[FlowQueueModal.jsx:4] State and effect are queueMode-shaped\n";
assert_react_contract(
	"useState defaults to 'static'",
	(bool) preg_match(
		"/useState\(\s*'static'\s*\)/",
		$modal
	)
);
assert_react_contract(
	'effect reads data?.queueMode',
	false !== strpos( $modal, 'data?.queueMode' )
);
assert_react_contract(
	"effect type-check is 'string', not 'boolean'",
	false !== strpos( $modal, "typeof data?.queueMode === 'string'" )
);

// ---------------------------------------------------------------
// SECTION 6: server contract — confirm the React shape matches the REST endpoint.
// ---------------------------------------------------------------

echo "\n[server-contract:1] FlowQueue.php REST surface still matches React\n";
$rest = (string) file_get_contents(
	dirname( __DIR__ ) . '/inc/Api/Flows/FlowQueue.php'
);
assert_react_contract(
	'PUT /flows/{id}/queue/mode endpoint is registered',
	false !== strpos( $rest, '/flows/(?P<flow_id>\d+)/queue/mode' )
);
assert_react_contract(
	"REST `mode` arg has enum [drain, loop, static]",
	(bool) preg_match(
		"/'enum'\s*=>\s*array\(\s*'drain',\s*'loop',\s*'static'\s*\)/",
		$rest
	)
);
assert_react_contract(
	"GET /queue response includes 'queue_mode' field",
	false !== strpos( $rest, "'queue_mode'   => \$result['queue_mode']" )
);
assert_react_contract(
	'legacy /queue/settings endpoint is gone server-side too',
	false === strpos( $rest, '/queue/settings' )
);

echo "\n";
if ( 0 === $failed ) {
	echo "=== react-queue-mode-contract-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== react-queue-mode-contract-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
