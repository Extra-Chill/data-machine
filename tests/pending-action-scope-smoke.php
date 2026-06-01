<?php
/**
 * Pure-PHP smoke coverage for pending-action caller scoping.
 *
 * Run with: php tests/pending-action-scope-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['__pending_action_scope_filters'] = array();
$GLOBALS['__pending_action_scope_user_id'] = 123;
$GLOBALS['__pending_action_scope_manage']  = false;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__pending_action_scope_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__pending_action_scope_filters'][ $hook ], SORT_NUMERIC );
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
		if ( empty( $GLOBALS['__pending_action_scope_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['__pending_action_scope_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		unset( $hook );
		return false;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['__pending_action_scope_user_id'];
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return get_current_user_id() > 0;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( int $_user_id, string $capability ): bool {
		return ! empty( $GLOBALS['__pending_action_scope_manage'] ) && 'manage_options' === $capability;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '/' ): string {
		unset( $path );
		return 'https://scope.example';
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '/' ): string {
		unset( $path );
		return 'https://scope.example';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require_once dirname( __DIR__ ) . '/vendor/automattic/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionScope.php';

use DataMachine\Engine\AI\Actions\PendingActionScope;

$failures = array();
$passes   = 0;

function pending_action_scope_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

echo "pending-action-scope-smoke\n";

$scoped = PendingActionScope::filters( array( 'status' => 'pending' ) );
pending_action_scope_assert( is_array( $scoped ), 'default scope returns filters', $failures, $passes );
pending_action_scope_assert( 'site' === ( $scoped['workspace_type'] ?? null ), 'default scope includes workspace type', $failures, $passes );
pending_action_scope_assert( 'https://scope.example' === ( $scoped['workspace_id'] ?? null ), 'default scope includes workspace ID', $failures, $passes );
pending_action_scope_assert( 123 === ( $scoped['owner_user_id'] ?? null ), 'default scope includes owner user ID', $failures, $passes );

$conflicting = PendingActionScope::filters( array( 'created_by' => 999, 'creator' => 'user:999' ) );
pending_action_scope_assert( 123 === ( $conflicting['owner_user_id'] ?? null ), 'normal callers cannot widen owner filters', $failures, $passes );
pending_action_scope_assert( ! isset( $conflicting['created_by'], $conflicting['creator'] ), 'normal caller owner filters use scoped OR matching', $failures, $passes );

$session_scoped = PendingActionScope::filters( array( 'context' => array( 'session_id' => 'sess_123' ) ) );
pending_action_scope_assert( 'sess_123' === ( $session_scoped['context']['session_id'] ?? null ), 'session context narrows scoped filters', $failures, $passes );

add_filter(
	'datamachine_pending_action_current_scope',
	static function ( array $scope ): array {
		$scope['agent_id'] = 456;
		$scope['agent']    = 'agent:456';
		return $scope;
	},
	10,
	1
);

$agent_scoped = PendingActionScope::filters( array() );
pending_action_scope_assert( 456 === ( $agent_scoped['agent_scope']['agent_id'] ?? null ), 'agent scope includes agent ID', $failures, $passes );
pending_action_scope_assert( 'agent:456' === ( $agent_scoped['agent_scope']['agent'] ?? null ), 'agent scope includes agent principal', $failures, $passes );

$matching_payload = array(
	'workspace'  => array(
		'workspace_type' => 'site',
		'workspace_id'   => 'https://scope.example',
	),
	'created_by' => 123,
	'creator'    => 'user:123',
	'agent_id'   => 456,
	'agent'      => 'agent:456',
	'context'    => array( 'session_id' => 'sess_123' ),
);
pending_action_scope_assert( PendingActionScope::can_access_payload( $matching_payload, array( 'context' => array( 'session_id' => 'sess_123' ) ) ), 'matching owner/agent/workspace/session payload is visible', $failures, $passes );

$other_owner_payload               = $matching_payload;
$other_owner_payload['created_by'] = 999;
$other_owner_payload['creator']    = 'user:999';
pending_action_scope_assert( ! PendingActionScope::can_access_payload( $other_owner_payload, array( 'context' => array( 'session_id' => 'sess_123' ) ) ), 'other owner payload is hidden', $failures, $passes );

$other_workspace_payload                                  = $matching_payload;
$other_workspace_payload['workspace']['workspace_id']     = 'https://other.example';
pending_action_scope_assert( ! PendingActionScope::can_access_payload( $other_workspace_payload, array( 'context' => array( 'session_id' => 'sess_123' ) ) ), 'other workspace payload is hidden', $failures, $passes );

$operator_denied = PendingActionScope::filters( array( 'operator_wide' => true, 'created_by' => 999 ) );
pending_action_scope_assert( $operator_denied instanceof WP_Error, 'operator-wide filters require explicit permission', $failures, $passes );

$GLOBALS['__pending_action_scope_manage'] = true;
$operator_allowed                         = PendingActionScope::filters( array( 'operator_wide' => true, 'created_by' => 999 ) );
pending_action_scope_assert( is_array( $operator_allowed ) && 999 === ( $operator_allowed['created_by'] ?? null ), 'operator-wide filters preserve explicit operator query', $failures, $passes );
pending_action_scope_assert( PendingActionScope::can_access_payload( $other_workspace_payload, array( 'operator_wide' => true ) ), 'operator-wide payload access is explicit', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending-action scope smoke passed ({$passes} assertions).\n";
