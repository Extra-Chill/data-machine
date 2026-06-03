<?php
/**
 * Pure-PHP smoke coverage for the PendingActionStore no-database failure path.
 *
 * Run with: php tests/pending-action-store-durable-required-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['__pending_action_unavailable_events'] = array();
$GLOBALS['__pending_action_transient_writes']   = 0;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
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

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['__pending_action_unavailable_events'][] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['__pending_action_unavailable_events'][] = array(
			'hook' => '_doing_it_wrong',
			'args' => array( $function_name, $message, $version ),
		);
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $_key, $value, int $_expiration = 0 ): bool {
		unset( $value );
		++$GLOBALS['__pending_action_transient_writes'];
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $_key ) {
		return false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $_key ): bool {
		return true;
	}
}

require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionObservers.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';

use DataMachine\Engine\AI\Actions\PendingActionStore;

$failures = array();
$passes   = 0;

echo "pending-action-store-durable-required-smoke\n";

function datamachine_pending_store_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

$payload = array(
	'kind'        => 'durable_required',
	'summary'     => 'Durable storage required.',
	'apply_input' => array( 'target' => 'approval' ),
);

datamachine_pending_store_assert( false === PendingActionStore::store( 'act_durable_required', $payload ), 'store fails closed without durable storage', $failures, $passes );
datamachine_pending_store_assert( 0 === $GLOBALS['__pending_action_transient_writes'], 'store does not silently write transient approvals', $failures, $passes );
datamachine_pending_store_assert( null === PendingActionStore::get( 'act_durable_required' ), 'get returns no action without durable storage', $failures, $passes );
datamachine_pending_store_assert( false === PendingActionStore::record_resolution( 'act_durable_required', 'accepted' ), 'resolution fails closed without durable storage', $failures, $passes );
datamachine_pending_store_assert( false === PendingActionStore::delete( 'act_durable_required' ), 'delete fails closed without durable storage', $failures, $passes );
datamachine_pending_store_assert( array() === PendingActionStore::list(), 'list remains empty without durable storage', $failures, $passes );
datamachine_pending_store_assert( 0 === PendingActionStore::summary()['total'], 'summary remains empty without durable storage', $failures, $passes );
datamachine_pending_store_assert( 0 === PendingActionStore::expire_due_actions(), 'expiration does not mutate without durable storage', $failures, $passes );

$event_hooks = array_map( static fn( array $event ): string => (string) ( $event['hook'] ?? '' ), $GLOBALS['__pending_action_unavailable_events'] );
datamachine_pending_store_assert( in_array( '_doing_it_wrong', $event_hooks, true ), 'missing durable storage emits a developer warning', $failures, $passes );
datamachine_pending_store_assert( in_array( 'datamachine_pending_action_store_unavailable', $event_hooks, true ), 'missing durable storage emits an operator diagnostic action', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo '- ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nPending-action durable-required smoke passed ({$passes} assertions).\n";
