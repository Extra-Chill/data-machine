<?php
/**
 * Static smoke coverage for Data Machine pending-action approval adapter surfaces.
 *
 * This pins the #1741 boundary without duplicating the behavior tests owned by
 * #1742-#1745: Agents API owns generic approval primitives, while Data Machine
 * owns concrete storage, routes, abilities, handlers, CLI, and upgrade paths.
 *
 * Run with: php tests/pending-actions-agents-api-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "pending-actions-agents-api-contract-smoke\n";

function datamachine_pending_actions_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

function datamachine_pending_actions_source( string $path ): string {
	$contents = file_get_contents( dirname( __DIR__ ) . '/' . $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}

	return $contents;
}

$store_source       = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionStore.php' );
$adapter_source     = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionStoreAdapter.php' );
$resolver_adapter   = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionResolverAdapter.php' );
$resolver_source    = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/ResolvePendingActionAbility.php' );
$inspection_source  = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionInspectionAbility.php' );
$cli_bootstrap      = datamachine_pending_actions_source( 'inc/Cli/Bootstrap.php' );
$cli_command_source = datamachine_pending_actions_source( 'inc/Cli/Commands/PendingActionsCommand.php' );
$plugin_source      = datamachine_pending_actions_source( 'data-machine.php' );
$runtime_source     = datamachine_pending_actions_source( 'inc/migrations/runtime.php' );
$action_policy      = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/ActionPolicyResolver.php' );

$expected_agents_api_approval_primitives = array(
	'AgentsAPI\\AI\\Approvals\\PendingActionStoreInterface'    => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/PendingActionStoreInterface.php',
		'type' => 'interface PendingActionStoreInterface',
	),
	'AgentsAPI\\AI\\Approvals\\PendingActionResolverInterface' => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/PendingActionResolverInterface.php',
		'type' => 'interface PendingActionResolverInterface',
	),
	'AgentsAPI\\AI\\Approvals\\PendingActionHandlerInterface'  => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/PendingActionHandlerInterface.php',
		'type' => 'interface PendingActionHandlerInterface',
	),
	'AgentsAPI\\AI\\Approvals\\ApprovalDecision'               => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/ApprovalDecision.php',
		'type' => 'final class ApprovalDecision',
	),
	'AgentsAPI\\AI\\Tools\\ActionPolicy'                       => array(
		'path' => 'vendor/automattic/agents-api/src/Tools/ActionPolicy.php',
		'type' => 'final class ActionPolicy',
	),
);

echo "\n[1] Data Machine adapts to merged Agents API contracts:\n";
foreach ( $expected_agents_api_approval_primitives as $class_name => $primitive ) {
	$primitive_source = datamachine_pending_actions_source( $primitive['path'] );
	datamachine_pending_actions_assert( str_contains( $primitive_source, $primitive['type'] ), $class_name . ' is available from the installed Agents API dependency', $failures, $passes );
}
datamachine_pending_actions_assert( str_contains( $adapter_source, 'implements PendingActionStoreInterface' ), 'store adapter implements Agents API PendingActionStoreInterface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'implements PendingActionResolverInterface' ), 'resolver adapter implements Agents API PendingActionResolverInterface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $action_policy, 'AgentsAPI\\AI\\Tools\\ActionPolicy::normalize' ), 'ActionPolicyResolver feature-detects Agents API ActionPolicy vocabulary', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'ApprovalDecision' ), 'resolver adapter consumes Agents API ApprovalDecision vocabulary', $failures, $passes );

echo "\n[2] Durable storage preserves legacy lookup while retaining audit rows:\n";
datamachine_pending_actions_assert( str_contains( $store_source, 'CREATE TABLE {$table_name}' ), 'PendingActionStore creates a durable table', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'datamachine_pending_actions' ), 'durable table uses Data Machine pending-actions table name', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function get( string $action_id, bool $include_resolved = false )' ), 'legacy get defaults to live-pending rows only', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function inspect( string $action_id ): ?array' ), 'inspect surface can fetch resolved audit rows', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'record_resolution( $action_id, PendingActionStore::STATUS_ACCEPTED' ), 'accepted resolutions are retained instead of transient-deleted', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'record_resolution( $action_id, PendingActionStore::STATUS_REJECTED' ), 'rejected resolutions are retained instead of transient-deleted', $failures, $passes );

echo "\n[3] List/get/summary surfaces are registered for agents and operators:\n";
foreach ( array( 'datamachine/list-pending-actions', 'datamachine/get-pending-action', 'datamachine/summarize-pending-actions' ) as $ability ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $ability ), $ability . ' ability is registered', $failures, $passes );
}
foreach ( array( '/actions', '/actions/(?P<action_id>', '/actions/summary' ) as $route ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $route ), $route . ' REST route is registered', $failures, $passes );
}
datamachine_pending_actions_assert( str_contains( $cli_bootstrap, 'datamachine pending-actions' ), 'pending-actions CLI command is registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $cli_command_source, 'public function list' ) && str_contains( $cli_command_source, 'public function get' ) && str_contains( $cli_command_source, 'public function summary' ), 'CLI exposes list/get/summary subcommands', $failures, $passes );

echo "\n[4] Existing resolver and handler compatibility remains the canonical resolution path:\n";
datamachine_pending_actions_assert( str_contains( $resolver_source, 'datamachine_pending_action_handlers' ), 'legacy pending action handler filter remains in resolver', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'PendingActionHandlerInterface' ), 'Agents API handler contract is bridged through the existing handler filter', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingActionAbility();' ), 'existing resolve ability remains registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingAction();' ), 'existing chat resolver tool remains registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $runtime_source, 'datamachine_migrate_pending_actions_table' ), 'upgrade path creates pending-action table on deployed installs', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending action Agents API contract smoke passed ({$passes} assertions).\n";
