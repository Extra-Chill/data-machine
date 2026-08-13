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

if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

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
$observers_source   = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionObservers.php' );
$wp_observer_source = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/WordPressActionDispatchObserver.php' );
$signer_source      = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php' );
$adapter_source     = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionStoreAdapter.php' );
$resolver_adapter   = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionResolverAdapter.php' );
$resolver_source    = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/ResolvePendingActionAbility.php' );
$inspection_source  = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/PendingActionInspectionAbility.php' );
$cli_bootstrap      = datamachine_pending_actions_source( 'inc/Cli/CommandRegistry.php' );
$cli_command_source = datamachine_pending_actions_source( 'inc/Cli/Commands/PendingActionsCommand.php' );
$plugin_source      = datamachine_pending_actions_source( 'data-machine.php' );
$runtime_source     = datamachine_pending_actions_source( 'inc/migrations/runtime.php' );
$action_policy      = datamachine_pending_actions_source( 'inc/Engine/AI/Actions/ActionPolicyResolver.php' );

$expected_agents_api_approval_primitives = array(
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Store'    => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action-store.php',
		'type' => 'interface WP_Agent_Pending_Action_Store',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Resolver' => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action-resolver.php',
		'type' => 'interface WP_Agent_Pending_Action_Resolver',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Handler'  => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action-handler.php',
		'type' => 'interface WP_Agent_Pending_Action_Handler',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Observer' => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action-observer.php',
		'type' => 'interface WP_Agent_Pending_Action_Observer',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action'                  => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action.php',
		'type' => 'class WP_Agent_Pending_Action',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Status'            => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-pending-action-status.php',
		'type' => 'final class WP_Agent_Pending_Action_Status',
	),
	'AgentsAPI\\AI\\Approvals\\WP_Agent_Approval_Decision'               => array(
		'path' => 'vendor/wordpress/agents-api/src/Approvals/class-wp-agent-approval-decision.php',
		'type' => 'final class WP_Agent_Approval_Decision',
	),
	'AgentsAPI\\AI\\Tools\\WP_Agent_Action_Policy'                       => array(
		'path' => 'vendor/wordpress/agents-api/src/Tools/class-wp-agent-action-policy.php',
		'type' => 'final class WP_Agent_Action_Policy',
	),
);

echo "\n[1] Data Machine adapts to merged Agents API contracts:\n";
foreach ( $expected_agents_api_approval_primitives as $class_name => $primitive ) {
	$primitive_source = datamachine_pending_actions_source( $primitive['path'] );
	datamachine_pending_actions_assert( str_contains( $primitive_source, $primitive['type'] ), $class_name . ' is available from the installed Agents API dependency', $failures, $passes );
}
datamachine_pending_actions_assert( str_contains( $store_source, 'use AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Store;' ), 'concrete store consumes Agents API WP_Agent_Pending_Action_Store', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function adapter(): WP_Agent_Pending_Action_Store' ), 'concrete store exposes the Agents API store contract', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'implements WP_Agent_Pending_Action_Store' ), 'store adapter implements Agents API WP_Agent_Pending_Action_Store', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'store( WP_Agent_Pending_Action $action )' ), 'store adapter accepts Agents API WP_Agent_Pending_Action records', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $adapter_source, 'record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver' ), 'store adapter records Agents API resolution audit metadata', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'implements WP_Agent_Pending_Action_Resolver' ), 'resolver adapter implements Agents API WP_Agent_Pending_Action_Resolver', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'resolve_with_datamachine_handlers' ), 'resolver adapter targets Data Machine concrete handler implementation without calling the deprecated alias', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $action_policy, 'use AgentsAPI\\AI\\Tools\\WP_Agent_Action_Policy;' ) && str_contains( $action_policy, 'WP_Agent_Action_Policy::normalize' ), 'ActionPolicyResolver consumes Agents API WP_Agent_Action_Policy vocabulary', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_adapter, 'WP_Agent_Approval_Decision' ), 'resolver adapter consumes Agents API WP_Agent_Approval_Decision vocabulary', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $observers_source, 'WP_Agent_Pending_Action_Observer' ), 'observer registry consumes Agents API WP_Agent_Pending_Action_Observer contract', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $wp_observer_source, 'datamachine_pending_action_stored' ) && str_contains( $wp_observer_source, 'datamachine_pending_action_resolved' ) && str_contains( $wp_observer_source, 'datamachine_pending_action_expired' ), 'WordPress observer adapter exposes stored/resolved/expired actions', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $wp_observer_source, 'do_action(' . PHP_EOL . "\t\t\t'datamachine_pending_action_resolved'," . PHP_EOL . "\t\t\t\$action," . PHP_EOL . "\t\t\t\$decision," . PHP_EOL . "\t\t\t\$resolver" ), 'resolved WordPress hook emits canonical Agents API action, decision, and resolver arguments', $failures, $passes );
datamachine_pending_actions_assert( ! str_contains( $wp_observer_source, 'legacy_payload' ) && ! str_contains( $wp_observer_source, 'preview_data' ), 'resolved WordPress hook no longer builds legacy Data Machine payloads', $failures, $passes );

echo "\n[2] Durable storage preserves legacy lookup while retaining audit rows:\n";
datamachine_pending_actions_assert( str_contains( $store_source, 'CREATE TABLE {$table_name}' ), 'PendingActionStore creates a durable table', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'datamachine_pending_actions' ), 'durable table uses Data Machine pending-actions table name', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function get( string $action_id, bool $include_resolved = false )' ), 'legacy get defaults to live-pending rows only', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'public static function inspect( string $action_id ): ?array' ), 'inspect surface can fetch resolved audit rows', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'WP_Agent_Pending_Action_Status::normalize' ), 'lifecycle vocabulary is delegated to Agents API WP_Agent_Pending_Action_Status', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'complete_claim( $action_id, (string) $claimed[\'receipt_nonce\'], WP_Agent_Pending_Action_Status::ACCEPTED' ), 'accepted claims are retained as audited terminal rows', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'complete_claim( $action_id, (string) $claimed[\'receipt_nonce\'], WP_Agent_Pending_Action_Status::REJECTED' ), 'rejected claims are retained as audited terminal rows', $failures, $passes );

echo "\n[3] List/get/summary surfaces are registered for agents and operators:\n";
foreach ( array( 'datamachine/list-pending-actions', 'datamachine/get-pending-action', 'datamachine/summarize-pending-actions' ) as $ability ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $ability ), $ability . ' ability is registered', $failures, $passes );
}
foreach ( array( 'agents_list_pending_actions', 'agents_get_pending_action', 'agents_summary_pending_actions' ) as $canonical_callback ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $canonical_callback ), 'Data Machine pending-action alias delegates to Agents API ' . $canonical_callback, $failures, $passes );
}
foreach ( array( '/actions', '/actions/(?P<action_id>', '/actions/summary' ) as $route ) {
	datamachine_pending_actions_assert( str_contains( $inspection_source, $route ), $route . ' REST route is registered', $failures, $passes );
}
datamachine_pending_actions_assert( str_contains( $cli_bootstrap, 'datamachine pending-actions' ), 'pending-actions CLI command is registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $cli_command_source, 'public function list' ) && str_contains( $cli_command_source, 'public function get' ) && str_contains( $cli_command_source, 'public function summary' ), 'CLI exposes list/get/summary subcommands', $failures, $passes );
foreach ( array( 'agents_list_pending_actions', 'agents_get_pending_action', 'agents_summary_pending_actions' ) as $canonical_callback ) {
	datamachine_pending_actions_assert( str_contains( $cli_command_source, $canonical_callback ), 'CLI pending-actions command delegates to Agents API ' . $canonical_callback, $failures, $passes );
}
datamachine_pending_actions_assert( ! str_contains( $store_source, 'public static function summary( array $filters = array() ): array {' . PHP_EOL . "\t\t" . '$rows = self::list' ), 'summary aggregation does not use the paginated list surface', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'SELECT status, kind, agent_id, context FROM %%i WHERE %s' ), 'summary aggregation queries all matching rows for accurate filtered totals', $failures, $passes );

echo "\n[4] Existing resolver and Agents API handler contracts remain the canonical resolution path:\n";
datamachine_pending_actions_assert( str_contains( $resolver_source, 'datamachine_pending_action_handlers' ), 'legacy pending action handler filter remains in resolver', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'can_resolve_pending_action' ) && str_contains( $resolver_source, 'handle_pending_action( $pending_action, $decision' ), 'Agents API handler permission and apply contracts are used through the existing handler filter', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, 'Deprecated compatibility alias for agents/resolve-pending-action' ) && str_contains( $resolver_source, 'agents_resolve_pending_action' ), 'Data Machine resolve ability is a deprecated alias for canonical Agents API resolution', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingActionAbility();' ), 'existing resolve alias remains registered for compatibility', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\ResolvePendingAction();' ), 'existing chat resolver tool remains registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $resolver_source, "'replacement' => 'agents/resolve-pending-action'" ), 'deprecated alias metadata names the canonical replacement', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'agents_pending_action_permission' ) || str_contains( $runtime_source, 'agents_pending_action_permission' ) || str_contains( datamachine_pending_actions_source( 'inc/bootstrap.php' ), 'agents_pending_action_permission' ), 'Data Machine grants canonical pending-action abilities through its chat permission policy', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'PendingActionObservers::register' ) && str_contains( $plugin_source, 'WordPressActionDispatchObserver' ), 'default WordPress pending-action observer is registered during bootstrap', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'vendor/wordpress/agents-api/agents-api.php' ), 'default WordPress pending-action observer registers after Agents API dependency loading', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $plugin_source, 'new \\DataMachine\\Engine\\AI\\Actions\\SignPendingActionResolutionAbility();' ), 'signed pending-action resolution ability is registered', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $signer_source, 'datamachine/sign-pending-action-resolution' ) && str_contains( $signer_source, '/actions/resolve-by-token' ), 'signed pending-action resolution exposes ability and public token route', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $signer_source, 'hash_hmac' ) && str_contains( $signer_source, 'datamachine_pending_action_resolution_secret' ), 'signed pending-action resolution uses a stored HMAC secret', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $runtime_source, 'datamachine_migrate_pending_actions_table' ), 'upgrade path creates pending-action table on deployed installs', $failures, $passes );

echo "\n[5] Store contract adapter uses the explicit test transient fallback seam:\n";

datamachine_pending_actions_assert( str_contains( $store_source, 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ), 'transient fallback requires an explicit opt-in constant', $failures, $passes );
datamachine_pending_actions_assert( str_contains( $store_source, 'warn_database_unavailable' ), 'missing durable storage reports a clear unavailable-store warning', $failures, $passes );

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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 123;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $_user_id, string $_capability ): bool {
		return false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $_code = '', string $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $_hook_name, $value ) {
		if ( 'wp_agent_pending_action_store' === $_hook_name && isset( $GLOBALS['datamachine_pending_action_store_override'] ) ) {
			return $GLOBALS['datamachine_pending_action_store_override'];
		}

		if ( 'wp_agent_pending_action_store' === $_hook_name && class_exists( '\DataMachine\Engine\AI\Actions\PendingActionStore' ) ) {
			return \DataMachine\Engine\AI\Actions\PendingActionStore::adapter();
		}

		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $_hook_name, $callback, int $_priority = 10, int $_accepted_args = 1 ): bool {
		unset( $callback );
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['datamachine_pending_action_observer_events'][] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '11111111-2222-4333-8444-555555555555';
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		// no-op stub for standalone smoke runs.
	}
}


require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionObservers.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/WordPressActionDispatchObserver.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStoreAdapter.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionInspectionAbility.php';

\DataMachine\Engine\AI\Actions\PendingActionObservers::reset();
\DataMachine\Engine\AI\Actions\PendingActionObservers::register( new \DataMachine\Engine\AI\Actions\WordPressActionDispatchObserver() );

$store     = \DataMachine\Engine\AI\Actions\PendingActionStore::adapter();
$action_id = \DataMachine\Engine\AI\Actions\PendingActionStore::generate_id();
$action    = \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action::from_array(
	array(
		'action_id'   => $action_id,
		'kind'        => 'contract_smoke',
		'summary'     => 'Contract smoke',
		'preview'     => array( 'ok' => true ),
		'apply_input' => array( 'value' => 1 ),
		'workspace'   => \DataMachine\Core\Workspace\WordPressWorkspaceScope::current()->to_array(),
		'creator'     => 'user:123',
		'agent'       => 'agent:456',
		'created_at'  => gmdate( 'c' ),
		'expires_at'  => gmdate( 'c', time() + 10 ),
		'metadata'    => array(
			'datamachine' => array(
				'created_by' => 123,
				'agent_id'   => 456,
				'context'    => array( 'session_id' => 'session_contract_smoke' ),
			),
		),
	)
);

datamachine_pending_actions_assert( $store instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store, 'PendingActionStore adapter is an Agents API store', $failures, $passes );
datamachine_pending_actions_assert( str_starts_with( $action_id, 'act_' ), 'legacy generate_id returns namespaced action IDs', $failures, $passes );
datamachine_pending_actions_assert( $store->store( $action ), 'contract store writes WP_Agent_Pending_Action through transient fallback', $failures, $passes );
datamachine_pending_actions_assert( 'datamachine_pending_action_stored' === ( $GLOBALS['datamachine_pending_action_observer_events'][0]['hook'] ?? null ), 'stored observer action fires after contract store write', $failures, $passes );

$stored = $store->get( $action_id );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && 'contract_smoke' === $stored->get_kind(), 'contract get reads the stored WP_Agent_Pending_Action', $failures, $passes );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && $action_id === $stored->get_action_id(), 'contract store preserves action ID in payload', $failures, $passes );
datamachine_pending_actions_assert( $stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && null !== $stored->get_expires_at(), 'transient fallback preserves expiration audit data', $failures, $passes );

$GLOBALS['datamachine_pending_action_store_override'] = new class( $stored ) implements \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store {
	public function __construct( private \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action $action ) {}
	public function store( \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action $action ): bool { unset( $action ); return true; }
	public function get( string $action_id, bool $include_resolved = false ): ?\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action { unset( $include_resolved ); return $action_id === $this->action->get_action_id() ? $this->action : null; }
	public function list( array $filters = array() ): array { unset( $filters ); return array( $this->action ); }
	public function summary( array $filters = array() ): array { unset( $filters ); return array( 'total' => 1 ); }
	public function record_resolution( string $action_id, \AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool { unset( $action_id, $decision, $resolver, $result, $error, $metadata ); return true; }
	public function expire( ?string $before = null ): int { unset( $before ); return 0; }
	public function delete( string $action_id ): bool { unset( $action_id ); return true; }
};

$alias_list = \DataMachine\Engine\AI\Actions\PendingActionInspectionAbility::list_actions( array( 'kind' => 'contract_smoke' ) );
$alias_list_action = $alias_list['actions'][0] ?? array();
datamachine_pending_actions_assert( true === ( $alias_list['success'] ?? false ), 'Data Machine list alias succeeds through Agents API store contract', $failures, $passes );
datamachine_pending_actions_assert( isset( $alias_list_action['preview'] ) && array( 'ok' => true ) === $alias_list_action['preview'], 'Data Machine list alias returns canonical preview field', $failures, $passes );
datamachine_pending_actions_assert( 'agent:456' === ( $alias_list_action['agent'] ?? null ), 'Data Machine list alias returns canonical agent field', $failures, $passes );
datamachine_pending_actions_assert( 'user:123' === ( $alias_list_action['creator'] ?? null ), 'Data Machine list alias returns canonical creator field', $failures, $passes );
datamachine_pending_actions_assert( 'session_contract_smoke' === ( $alias_list_action['metadata']['datamachine']['context']['session_id'] ?? null ), 'Data Machine list alias keeps Data Machine context in canonical metadata', $failures, $passes );
datamachine_pending_actions_assert( isset( $alias_list_action['created_at'] ) && is_string( $alias_list_action['created_at'] ), 'Data Machine list alias returns canonical created_at field', $failures, $passes );
datamachine_pending_actions_assert( ! array_key_exists( 'preview_data', $alias_list_action ), 'Data Machine list alias no longer exposes legacy preview_data field', $failures, $passes );

$alias_get = \DataMachine\Engine\AI\Actions\PendingActionInspectionAbility::get_action( array( 'action_id' => $action_id ) );
datamachine_pending_actions_assert( true === ( $alias_get['success'] ?? false ), 'Data Machine get alias succeeds through Agents API store contract', $failures, $passes );
datamachine_pending_actions_assert( isset( $alias_get['action']['preview'] ) && array( 'ok' => true ) === $alias_get['action']['preview'], 'Data Machine get alias returns canonical preview field', $failures, $passes );
datamachine_pending_actions_assert( 'agent:456' === ( $alias_get['action']['agent'] ?? null ), 'Data Machine get alias returns canonical agent field', $failures, $passes );
datamachine_pending_actions_assert( 'user:123' === ( $alias_get['action']['creator'] ?? null ), 'Data Machine get alias returns canonical creator field', $failures, $passes );
datamachine_pending_actions_assert( 'session_contract_smoke' === ( $alias_get['action']['metadata']['datamachine']['context']['session_id'] ?? null ), 'Data Machine get alias keeps Data Machine context in canonical metadata', $failures, $passes );
datamachine_pending_actions_assert( isset( $alias_get['action']['expires_at'] ) && is_string( $alias_get['action']['expires_at'] ), 'Data Machine get alias returns canonical expires_at field', $failures, $passes );
datamachine_pending_actions_assert( ! array_key_exists( 'preview_data', $alias_get['action'] ), 'Data Machine get alias no longer exposes legacy preview_data field', $failures, $passes );

$legacy_action_id = 'act_legacy_smoke';
$product_payload  = array(
	'action_id'    => $legacy_action_id,
	'kind'         => 'legacy_smoke',
	'summary'      => 'Legacy smoke',
	'preview_data' => array( 'legacy' => true ),
	'apply_input'  => array( 'legacy_value' => 2 ),
	'created_at'   => time(),
	'expires_at'   => time() + 10,
);

datamachine_pending_actions_assert( \DataMachine\Engine\AI\Actions\PendingActionStore::store( $legacy_action_id, $product_payload ), 'Data Machine payload arrays read through the product payload API', $failures, $passes );

$legacy_stored = $store->get( $legacy_action_id );
datamachine_pending_actions_assert( $legacy_stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action, 'legacy Data Machine payload arrays normalize to WP_Agent_Pending_Action value objects', $failures, $passes );
datamachine_pending_actions_assert( $legacy_stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && 'legacy_smoke' === $legacy_stored->get_kind(), 'value-object conversion preserves Data Machine handler kind', $failures, $passes );
datamachine_pending_actions_assert( $legacy_stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && array( 'legacy' => true ) === $legacy_stored->get_preview(), 'value-object conversion preserves preview payload', $failures, $passes );
datamachine_pending_actions_assert( $legacy_stored instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action && array( 'legacy_value' => 2 ) === $legacy_stored->get_apply_input(), 'value-object conversion preserves apply input', $failures, $passes );
datamachine_pending_actions_assert( $store->delete( $legacy_action_id ), 'contract delete removes legacy transient fallback payload', $failures, $passes );

$GLOBALS['datamachine_pending_action_observer_events'] = array();
datamachine_pending_actions_assert( $store->get( $action_id ) instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action, 'contract get still sees pending action before resolution', $failures, $passes );
datamachine_pending_actions_assert( $store->record_resolution( $action_id, \AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision::accepted(), 'user:123' ), 'contract record_resolution resolves transient fallback payload', $failures, $passes );
$resolved_hooks = array_map( static fn( array $event ): string => (string) ( $event['hook'] ?? '' ), $GLOBALS['datamachine_pending_action_observer_events'] ?? array() );
datamachine_pending_actions_assert( in_array( 'datamachine_pending_action_resolved', $resolved_hooks, true ), 'resolved observer action fires after contract resolution', $failures, $passes );
$resolved_event = null;
foreach ( $GLOBALS['datamachine_pending_action_observer_events'] ?? array() as $event ) {
	if ( 'datamachine_pending_action_resolved' === ( $event['hook'] ?? null ) ) {
		$resolved_event = $event;
		break;
	}
}
$resolved_args = is_array( $resolved_event ) ? ( $resolved_event['args'] ?? array() ) : array();
datamachine_pending_actions_assert( ( $resolved_args[0] ?? null ) instanceof \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action, 'resolved hook receives canonical pending action value object', $failures, $passes );
datamachine_pending_actions_assert( ( $resolved_args[1] ?? null ) instanceof \AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision, 'resolved hook receives canonical approval decision value object', $failures, $passes );
datamachine_pending_actions_assert( 'user:123' === ( $resolved_args[2] ?? null ), 'resolved hook receives resolver identifier as third argument', $failures, $passes );
datamachine_pending_actions_assert( null === $store->get( $action_id ), 'contract get returns null after transient fallback resolution', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending action Agents API contract smoke passed ({$passes} assertions).\n";
