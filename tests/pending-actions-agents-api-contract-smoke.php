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
	'AgentsAPI\\AI\\Approvals\\PendingAction'                  => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/PendingAction.php',
		'type' => 'class PendingAction',
	),
	'AgentsAPI\\AI\\Approvals\\PendingActionStatus'            => array(
		'path' => 'vendor/automattic/agents-api/src/Approvals/PendingActionStatus.php',
		'type' => 'final class PendingActionStatus',
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
datamachine_pending_actions_assert( str_contains( $store_source, 'use AgentsAPI\\AI\\Approvals\\PendingActionStoreInterface;' ), 'concrete store consumes Agents API PendingActionStoreInterface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function adapter(): PendingActionStoreInterface' ), 'concrete store exposes the Agents API store contract', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'implements PendingActionStoreInterface' ), 'store adapter implements Agents API PendingActionStoreInterface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'store( PendingAction $action )' ), 'store adapter accepts Agents API PendingAction records', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'record_resolution( string $action_id, ApprovalDecision $decision, string $resolver' ), 'store adapter records Agents API resolution audit metadata', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'implements PendingActionResolverInterface' ), 'resolver adapter implements Agents API PendingActionResolverInterface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $action_policy, 'use AgentsAPI\\AI\\Tools\\ActionPolicy;' ) && str_contains( $action_policy, 'ActionPolicy::normalize' ), 'ActionPolicyResolver consumes Agents API ActionPolicy vocabulary', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'ApprovalDecision' ), 'resolver adapter consumes Agents API ApprovalDecision vocabulary', $failures, $passes );

echo "\n[2] Durable storage preserves legacy lookup while retaining audit rows:\n";
datamachine_pending_actions_assert( str_contains( $store_source, 'CREATE TABLE {$table_name}' ), 'PendingActionStore creates a durable table', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'datamachine_pending_actions' ), 'durable table uses Data Machine pending-actions table name', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function get( string $action_id, bool $include_resolved = false )' ), 'legacy get defaults to live-pending rows only', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function inspect( string $action_id ): ?array' ), 'inspect surface can fetch resolved audit rows', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'PendingActionStatus::normalize' ), 'lifecycle vocabulary is delegated to Agents API PendingActionStatus', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'record_resolution( $action_id, PendingActionStatus::ACCEPTED' ), 'accepted resolutions are retained instead of transient-deleted', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'record_resolution( $action_id, PendingActionStatus::REJECTED' ), 'rejected resolutions are retained instead of transient-deleted', $failures, $passes );

echo "\n[3] List/get/summary surfaces are registered for agents and operators:\n";
foreach ( array( 'datamachine/list-pending-actions', 'datamachine/get-pending-action', 'datamachine/summarize-pending-actions' ) as $ability ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $ability ), $ability . ' ability is registered', $failures, $passes );
}
foreach ( array( '/actions', '/actions/(?P<action_id>', '/actions/summary' ) as $route ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $route ), $route . ' REST route is registered', $failures, $passes );
}
datamachine_pending_actions_assert( str_contains( $cli_bootstrap, 'datamachine pending-actions' ), 'pending-actions CLI command is registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $cli_command_source, 'public function list' ) && str_contains( $cli_command_source, 'public function get' ) && str_contains( $cli_command_source, 'public function summary' ), 'CLI exposes list/get/summary subcommands', $failures, $passes );

echo "\n[4] Existing resolver and Agents API handler contracts remain the canonical resolution path:\n";
datamachine_pending_actions_assert( str_contains( $resolver_source, 'datamachine_pending_action_handlers' ), 'legacy pending action handler filter remains in resolver', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'can_resolve_pending_action' ) && str_contains( $resolver_source, 'handle_pending_action( $pending_action, $decision' ), 'Agents API handler permission and apply contracts are used through the existing handler filter', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingActionAbility();' ), 'existing resolve ability remains registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingAction();' ), 'existing chat resolver tool remains registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $runtime_source, 'datamachine_migrate_pending_actions_table' ), 'upgrade path creates pending-action table on deployed installs', $failures, $passes );

echo "\n[5] Store contract adapter preserves transient fallback behavior:\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['datamachine_pending_actions_transients'] = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['datamachine_pending_actions_transients'][ $key ] = array(
			'value'      => $value,
			'expiration' => $expiration,
			'expires'    => $expiration > 0 ? time() + $expiration : 0,
		);

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$transient = $GLOBALS['datamachine_pending_actions_transients'][ $key ] ?? null;
		if ( ! is_array( $transient ) ) {
			return false;
		}

		if ( $transient['expires'] > 0 && $transient['expires'] <= time() ) {
			unset( $GLOBALS['datamachine_pending_actions_transients'][ $key ] );
			return false;
		}

		return $transient['value'];
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['datamachine_pending_actions_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $_hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $_hook_name, $callback, int $_priority = 10, int $_accepted_args = 1 ): bool {
		unset( $callback );
		return true;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '11111111-2222-4333-8444-555555555555';
	}
}

require_once dirname( __DIR__ ) . '/vendor/automattic/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStoreAdapter.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';

$store     = \DataMachine\Engine\AI\Actions\PendingActionStore::adapter();
$action_id = \DataMachine\Engine\AI\Actions\PendingActionStore::generate_id();
$action    = \AgentsAPI\AI\Approvals\PendingAction::from_array(
	array(
		'action_id'   => $action_id,
		'kind'        => 'contract_smoke',
		'summary'     => 'Contract smoke',
		'preview'     => array( 'ok' => true ),
		'apply_input' => array( 'value' => 1 ),
		'creator'     => 'user:123',
		'agent'       => 'agent:456',
		'created_at'  => gmdate( 'c' ),
		'expires_at'  => gmdate( 'c', time() + 10 ),
		'metadata'    => array(
			'datamachine' => array(
				'created_by' => 123,
				'agent_id'   => 456,
			),
		),
	)
);

datamachine_pending_actions_assert( $store instanceof \AgentsAPI\AI\Approvals\PendingActionStoreInterface, 'PendingActionStore adapter is an Agents API store', $failures, $passes );
datamachine_pending_actions_assert( str_starts_with( $action_id, 'act_' ), 'legacy generate_id returns namespaced action IDs', $failures, $passes );
datamachine_pending_actions_assert( $store->store( $action ), 'contract store writes PendingAction through transient fallback', $failures, $passes );

$stored = $store->get( $action_id );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\PendingAction && 'contract_smoke' === $stored->get_kind(), 'contract get reads the stored PendingAction', $failures, $passes );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\PendingAction && $action_id === $stored->get_action_id(), 'contract store preserves action ID in payload', $failures, $passes );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\PendingAction && null !== $stored->get_expires_at(), 'transient fallback preserves expiration audit data', $failures, $passes );
datamachine_pending_actions_assert( $store->delete( $action_id ), 'contract delete removes transient fallback payload', $failures, $passes );
datamachine_pending_actions_assert( null === $store->get( $action_id ), 'contract get returns null after delete', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending action Agents API contract smoke passed ({$passes} assertions).\n";
