<?php
/**
 * Pure-PHP smoke coverage for pending-action resolver contract adaptation.
 *
 * Run with: php tests/pending-action-resolver-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

$GLOBALS['__resolver_filters']     = array();
$GLOBALS['__resolver_transients']  = array();
$GLOBALS['__resolver_current_blog'] = 1;
$GLOBALS['__resolver_blog_stack']   = array();
$GLOBALS['__resolver_switches']     = array();

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 123;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['__resolver_current_blog'];
	}
}
if ( ! function_exists( 'get_site' ) ) {
	function get_site( int $blog_id ) {
		return in_array( $blog_id, array( 1, 2, 7 ), true ) ? (object) array( 'blog_id' => $blog_id ) : null;
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['__resolver_blog_stack'][] = $GLOBALS['__resolver_current_blog'];
		$GLOBALS['__resolver_current_blog'] = $blog_id;
		$GLOBALS['__resolver_switches'][]   = $blog_id;
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		if ( empty( $GLOBALS['__resolver_blog_stack'] ) ) {
			return false;
		}
		$GLOBALS['__resolver_current_blog'] = array_pop( $GLOBALS['__resolver_blog_stack'] );
		return true;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		unset( $hook );
		return 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		unset( $hook );
		return false;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__resolver_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__resolver_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__resolver_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['__resolver_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}

		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		apply_filters( $hook, null, ...$args );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['__resolver_transients'][ get_current_blog_id() ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['__resolver_transients'][ get_current_blog_id() ][ $key ] ?? false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__resolver_transients'][ get_current_blog_id() ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			unset( $code );
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionObservers.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/WordPressActionDispatchObserver.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionHelper.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionResolverAdapter.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/ResolvePendingActionAbility.php';

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Handler;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Resolver;
use DataMachine\Engine\AI\Actions\PendingActionObservers;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;

$failures = array();
$passes   = 0;

function resolver_smoke_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

echo "pending-action-resolver-contract-smoke\n";

$adapter = ResolvePendingActionAbility::adapter();
resolver_smoke_assert( $adapter instanceof WP_Agent_Pending_Action_Resolver, 'resolver adapter implements Agents API resolver contract', $failures, $passes );

add_filter(
	'wp_agent_pending_action_resolver',
	static function () use ( $adapter ) {
		return $adapter;
	},
	10,
	2
);

$handler_calls   = array();
$permission_seen = array();
$handler         = new class( $handler_calls ) implements WP_Agent_Pending_Action_Handler {
	public function __construct( private array &$handler_calls ) {}

	public function can_resolve_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): bool {
		$this->handler_calls[] = array(
			'permission_action_id' => $action->get_action_id(),
			'permission_decision'  => $decision->value(),
			'permission_payload'   => $payload,
			'permission_context'   => $context,
		);

		return true;
	}

	public function handle_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): mixed {
		$this->handler_calls[] = array(
			'action_id' => $action->get_action_id(),
			'decision' => $decision->value(),
			'apply'    => $action->get_apply_input(),
			'payload'  => $payload,
			'context'  => $context,
		);

		return array(
			'success'  => true,
			'decision' => $decision->value(),
			'target'   => $action->get_apply_input()['target'] ?? null,
			'reason'   => $payload['reason'] ?? null,
			'actor'    => $context['actor'] ?? null,
		);
	}
};

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ) use ( $handler, &$permission_seen ) {
		$handlers['contract_kind'] = array(
			'apply'       => $handler,
			'can_resolve' => static function ( array $payload, string $decision, int $user_id ) use ( &$permission_seen ) {
				$permission_seen[] = array(
					'kind'     => $payload['kind'] ?? null,
					'decision' => $decision,
					'user_id'  => $user_id,
				);
				return true;
			},
		);

		return $handlers;
	},
	10,
	1
);

PendingActionStore::store(
	'act_contract_accept',
	array(
		'kind'        => 'contract_kind',
		'summary'     => 'Apply contract handler.',
		'apply_input' => array( 'target' => 'diff-123' ),
		'created_by'  => 123,
		'creator'     => 'user:123',
	)
);

$accepted = $adapter->resolve_pending_action(
	'act_contract_accept',
	WP_Agent_Approval_Decision::accepted(),
	'user:123',
	array( 'reason' => 'looks-good' ),
	array( 'actor' => 'reviewer' )
);

resolver_smoke_assert( true === ( $accepted['success'] ?? false ), 'accepted contract resolution succeeds', $failures, $passes );
resolver_smoke_assert( 'accepted' === ( $accepted['decision'] ?? null ), 'accepted response keeps Data Machine decision string', $failures, $passes );
resolver_smoke_assert( 'accepted' === ( $handler_calls[0]['permission_decision'] ?? null ), 'contract handler permission receives WP_Agent_Approval_Decision object value', $failures, $passes );
resolver_smoke_assert( 'act_contract_accept' === ( $handler_calls[1]['action_id'] ?? null ), 'contract handler receives WP_Agent_Pending_Action value object', $failures, $passes );
resolver_smoke_assert( 'accepted' === ( $handler_calls[1]['decision'] ?? null ), 'contract handler receives WP_Agent_Approval_Decision object value', $failures, $passes );
resolver_smoke_assert( 'looks-good' === ( $handler_calls[1]['payload']['reason'] ?? null ), 'contract handler receives resolver payload', $failures, $passes );
resolver_smoke_assert( 'reviewer' === ( $handler_calls[1]['context']['actor'] ?? null ), 'contract handler receives resolver context', $failures, $passes );
resolver_smoke_assert( empty( $permission_seen ), 'legacy can_resolve is not duplicated for Agents API handler objects', $failures, $passes );

PendingActionStore::store(
	'act_canonical_accept',
	array(
		'kind'        => 'contract_kind',
		'summary'     => 'Apply canonical ability.',
		'apply_input' => array( 'target' => 'diff-canonical' ),
		'created_by'  => 123,
		'creator'     => 'user:123',
	)
);

$canonical = \AgentsAPI\AI\Approvals\agents_resolve_pending_action(
	array(
		'action_id' => 'act_canonical_accept',
		'decision'  => 'accepted',
		'resolver'  => 'user:123',
		'payload'   => array( 'reason' => 'canonical-path' ),
		'context'   => array( 'actor' => 'canonical-reviewer' ),
	)
);

resolver_smoke_assert( ! is_wp_error( $canonical ), 'canonical Agents API resolve ability succeeds against Data Machine adapter', $failures, $passes );
resolver_smoke_assert( 'act_canonical_accept' === ( $canonical['action_id'] ?? null ), 'canonical resolve response keeps action ID', $failures, $passes );
resolver_smoke_assert( 'accepted' === ( $canonical['decision'] ?? null ), 'canonical resolve response keeps decision', $failures, $passes );
resolver_smoke_assert( true === ( $canonical['result']['success'] ?? false ), 'canonical resolve wraps Data Machine resolver result', $failures, $passes );
resolver_smoke_assert( 'diff-canonical' === ( $canonical['result']['result']['target'] ?? null ), 'canonical resolve applies the Data Machine handler', $failures, $passes );

PendingActionStore::store(
	'act_alias_accept',
	array(
		'kind'        => 'contract_kind',
		'summary'     => 'Apply alias ability.',
		'apply_input' => array( 'target' => 'diff-alias' ),
		'created_by'  => 123,
		'creator'     => 'user:123',
	)
);

$alias = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_alias_accept',
		'decision'  => 'accepted',
		'payload'   => array( 'reason' => 'alias-path' ),
		'context'   => array( 'actor' => 'alias-reviewer' ),
	)
);

resolver_smoke_assert( true === ( $alias['success'] ?? false ), 'Data Machine resolve alias delegates through canonical Agents API resolve', $failures, $passes );
resolver_smoke_assert( 'diff-alias' === ( $alias['result']['target'] ?? null ), 'Data Machine resolve alias preserves legacy result shape', $failures, $passes );

$legacy_apply_calls = 0;
$legacy_permission_seen = array();
add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ) use ( &$legacy_apply_calls, &$legacy_permission_seen ) {
		$handlers['legacy_kind'] = array(
			'apply'       => static function ( array $apply_input, array $payload ) use ( &$legacy_apply_calls ) {
				unset( $apply_input, $payload );
				++$legacy_apply_calls;
				return array( 'success' => true );
			},
			'can_resolve' => static function ( array $payload, string $decision, int $user_id, array $context ) use ( &$legacy_permission_seen ) {
				$legacy_permission_seen[] = compact( 'payload', 'decision', 'user_id', 'context' );
				return true;
			},
		);

		return $handlers;
	},
	20,
	1
);

PendingActionStore::store(
	'act_ungranted_agent_accept',
	array(
		'kind'        => 'legacy_kind',
		'summary'     => 'Ungrant agent acceptance.',
		'apply_input' => array( 'target' => 'diff-ungranted' ),
		'created_by'  => 123,
		'creator'     => 'user:123',
	)
);

$ungranted_agent_accept = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_ungranted_agent_accept',
		'decision'  => 'accepted',
		'resolver'  => 'agent:7',
	)
);

resolver_smoke_assert( false === ( $ungranted_agent_accept['success'] ?? true ), 'ungranted agent acceptance fails closed', $failures, $passes );
resolver_smoke_assert( 0 === $legacy_apply_calls, 'ungranted agent acceptance does not invoke apply handler', $failures, $passes );

PendingActionStore::store(
	'act_granted_agent_accept',
	array(
		'kind'            => 'legacy_kind',
		'summary'         => 'Grant agent acceptance.',
		'apply_input'     => array( 'target' => 'diff-agent-granted' ),
		'created_by'      => 123,
		'creator'         => 'user:123',
		'resolver_grants' => array(
			array(
				'type'                    => 'agent',
				'decisions'               => array( 'accepted' ),
				'resolvers'               => array( 'agent:7' ),
				'kind'                    => 'legacy_kind',
				'required_payload_fields' => array( 'evidence' ),
			),
		),
	)
);

$granted_agent_accept = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_granted_agent_accept',
		'decision'  => 'accepted',
		'resolver'  => 'agent:7',
		'payload'   => array( 'evidence' => 'deterministic-policy' ),
	)
);

resolver_smoke_assert( true === ( $granted_agent_accept['success'] ?? false ), 'granted agent acceptance succeeds', $failures, $passes );
resolver_smoke_assert( 1 === $legacy_apply_calls, 'granted agent acceptance invokes apply handler once', $failures, $passes );

PendingActionStore::store(
	'act_rejection_only_system',
	array(
		'kind'            => 'legacy_kind',
		'summary'         => 'Grant system rejection only.',
		'apply_input'     => array( 'target' => 'diff-rejection-only' ),
		'created_by'      => 123,
		'creator'         => 'user:123',
		'resolver_grants' => array(
			array(
				'type'      => 'system_task',
				'decision'  => 'rejected',
				'resolvers' => array( 'system_task:wiki_graph_maintain' ),
			),
		),
	)
);

$rejection_only_accept = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_rejection_only_system',
		'decision'  => 'accepted',
		'resolver'  => 'system_task:wiki_graph_maintain',
	)
);

resolver_smoke_assert( false === ( $rejection_only_accept['success'] ?? true ), 'rejection-only system resolver cannot accept', $failures, $passes );
resolver_smoke_assert( 1 === $legacy_apply_calls, 'rejection-only accept denial does not invoke apply handler', $failures, $passes );

PendingActionStore::store(
	'act_legacy_reject',
	array(
		'kind'            => 'legacy_kind',
		'summary'         => 'Reject legacy handler.',
		'apply_input'     => array( 'target' => 'diff-456' ),
		'created_by'      => 123,
		'creator'         => 'user:123',
		'resolver_grants' => array(
			array(
				'type'      => 'system_task',
				'decision'  => 'rejected',
				'resolvers' => array( 'system:test-resolver' ),
			),
		),
	)
);

$rejected = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_legacy_reject',
		'decision'  => 'rejected',
		'resolver'  => 'system:test-resolver',
		'context'   => array( 'reason' => 'safe-test' ),
	)
);

resolver_smoke_assert( true === ( $rejected['success'] ?? false ), 'rejected legacy resolution succeeds', $failures, $passes );
resolver_smoke_assert( 'rejected' === ( $rejected['decision'] ?? null ), 'rejected response keeps Data Machine decision string', $failures, $passes );
$legacy_reject_permission = end( $legacy_permission_seen );
resolver_smoke_assert( 'safe-test' === ( $legacy_reject_permission['context']['reason'] ?? null ), 'legacy can_resolve receives resolver context', $failures, $passes );
resolver_smoke_assert( 'system:test-resolver' === ( $legacy_reject_permission['context']['resolver'] ?? null ), 'legacy can_resolve receives resolver identity', $failures, $passes );
resolver_smoke_assert( 1 === $legacy_apply_calls, 'rejected resolution does not invoke apply handler', $failures, $passes );

$denied_apply_calls = 0;
$denying_handler    = new class( $denied_apply_calls ) implements WP_Agent_Pending_Action_Handler {
	public function __construct( private int &$denied_apply_calls ) {}

	public function can_resolve_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): bool {
		unset( $action, $decision, $payload, $context );
		return false;
	}

	public function handle_pending_action( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, array $payload = array(), array $context = array() ): mixed {
		unset( $action, $decision, $payload, $context );
		++$this->denied_apply_calls;
		return array( 'success' => true );
	}
};

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ) use ( $denying_handler ) {
		$handlers['denied_kind'] = array( 'apply' => $denying_handler );
		return $handlers;
	},
	30,
	1
);

PendingActionStore::store(
	'act_contract_denied',
	array(
		'kind'        => 'denied_kind',
		'summary'     => 'Denied contract handler.',
		'apply_input' => array( 'target' => 'diff-789' ),
		'created_by'  => 123,
		'creator'     => 'user:123',
	)
);

$denied = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_contract_denied',
		'decision'  => 'accepted',
	)
);

resolver_smoke_assert( false === ( $denied['success'] ?? true ), 'contract handler permission denial fails resolution', $failures, $passes );
resolver_smoke_assert( 0 === $denied_apply_calls, 'permission denial does not invoke contract apply handler', $failures, $passes );

$invalid = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_missing',
		'decision'  => 'approved',
	)
);
resolver_smoke_assert( false === ( $invalid['success'] ?? true ), 'unknown Agents API approval decision is rejected', $failures, $passes );

$scoped_out_apply_calls = 0;
add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ) use ( &$scoped_out_apply_calls ) {
		$handlers['scoped_out_kind'] = array(
			'apply' => static function () use ( &$scoped_out_apply_calls ) {
				++$scoped_out_apply_calls;
				return array( 'success' => true );
			},
		);

		return $handlers;
	},
	40,
	1
);

PendingActionStore::store(
	'act_scoped_out',
	array(
		'kind'        => 'scoped_out_kind',
		'summary'     => 'Scoped-out handler.',
		'apply_input' => array( 'target' => 'diff-000' ),
		'created_by'  => 999,
		'creator'     => 'user:999',
	)
);

$scoped_out = ResolvePendingActionAbility::execute(
	array(
		'action_id' => 'act_scoped_out',
		'decision'  => 'accepted',
	)
);
resolver_smoke_assert( false === ( $scoped_out['success'] ?? true ), 'resolver rejects actions outside caller owner scope', $failures, $passes );
resolver_smoke_assert( 0 === $scoped_out_apply_calls, 'scope denial happens before handler execution', $failures, $passes );

$origin_handler_blogs = array();
$origin_audit_blogs   = array();
$origin_observer      = new class( $origin_audit_blogs ) implements WP_Agent_Pending_Action_Observer {
	public function __construct( private array &$origin_audit_blogs ) {}
	public function on_stored( WP_Agent_Pending_Action $action ): void {
		unset( $action );
	}
	public function on_resolved( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver ): void {
		unset( $action, $decision, $resolver );
		$this->origin_audit_blogs[] = get_current_blog_id();
	}
	public function on_expired( WP_Agent_Pending_Action $action ): void {
		unset( $action );
	}
};
PendingActionObservers::register( $origin_observer );
add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ) use ( &$origin_handler_blogs ) {
		$handlers['origin_kind'] = array(
			'apply' => static function () use ( &$origin_handler_blogs ) {
				$origin_handler_blogs[] = get_current_blog_id();
				return array( 'success' => true );
			},
		);
		return $handlers;
	},
	50,
	1
);

switch_to_blog( 7 );
$origin_envelope = PendingActionHelper::stage(
	array(
		'action_id'   => 'act_00000000-0000-4000-8000-000000000007',
		'kind'        => 'origin_kind',
		'summary'     => 'Resolve at origin.',
		'apply_input' => array( 'target' => 'origin' ),
		'user_id'     => 123,
		'context'     => array(
			'wordpress' => array( 'blog_id' => 1 ),
			'trace_id'  => 'caller-context-preserved',
		),
	)
);
$origin_action_id = $origin_envelope['action_id'] ?? '';
$origin_stored    = PendingActionStore::get( $origin_action_id );
restore_current_blog();
$GLOBALS['__resolver_current_blog'] = 2;

resolver_smoke_assert( 7 === ( $origin_stored['context']['wordpress']['blog_id'] ?? null ), 'store overwrites forged WordPress origin with the actual staging blog', $failures, $passes );
resolver_smoke_assert( 'caller-context-preserved' === ( $origin_stored['context']['trace_id'] ?? null ), 'store preserves non-reserved caller context', $failures, $passes );
resolver_smoke_assert( 7 === ( $origin_envelope['payload']['metadata']['datamachine']['context']['wordpress']['blog_id'] ?? null ), 'approval envelope exposes the actual server-owned origin', $failures, $passes );

$origin_result = $adapter->resolve_pending_action(
	$origin_action_id,
	WP_Agent_Approval_Decision::accepted(),
	'user:123',
	array(),
	array( 'wordpress' => array( 'blog_id' => 7 ) )
);
resolver_smoke_assert( true === ( $origin_result['success'] ?? false ), 'cross-site resolution succeeds against the originating store', $failures, $passes );
resolver_smoke_assert( array( 7 ) === $origin_handler_blogs, 'approval handler executes in the originating blog context', $failures, $passes );
resolver_smoke_assert( 2 === get_current_blog_id(), 'origin routing restores the caller blog after resolution', $failures, $passes );
resolver_smoke_assert( array( 7 ) === $origin_audit_blogs, 'resolution audit lifecycle runs in the originating blog context', $failures, $passes );

$forged_result = $adapter->resolve_pending_action(
	'act_origin_accept',
	WP_Agent_Approval_Decision::accepted(),
	'user:123',
	array(),
	array( 'wordpress' => array( 'blog_id' => 1 ) )
);
resolver_smoke_assert( false === ( $forged_result['success'] ?? true ), 'forged origin metadata is denied', $failures, $passes );
resolver_smoke_assert( array( 7 ) === $origin_handler_blogs, 'forged origin does not execute the handler', $failures, $passes );
resolver_smoke_assert( 2 === get_current_blog_id(), 'forged origin denial also restores the caller blog', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending-action resolver contract smoke passed ({$passes} assertions).\n";
