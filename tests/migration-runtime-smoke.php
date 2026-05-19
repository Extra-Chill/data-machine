<?php

/**
 * Pure-PHP smoke test for the current schema runtime.
 *
 * Run with: php tests/migration-runtime-smoke.php
 *
 * Data Machine is pre-1.0, so historical persisted data-shape migrations are
 * not a permanent runtime contract. The deploy-time runtime should only ensure
 * current schema additions that are still needed when code is updated without
 * plugin reactivation.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
	define( 'DATAMACHINE_VERSION', '0.104.0-test' );
}

$failed = 0;
$total  = 0;

function assert_runtime( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

$GLOBALS['__test_options']      = array();
$GLOBALS['__test_actions']      = array();
$GLOBALS['__test_schema_calls'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return $GLOBALS['__test_options'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_actions'][] = array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}

$schema_chain = array(
	'datamachine_migrate_bundle_artifacts_table',
	'datamachine_migrate_processed_item_claims',
	'datamachine_migrate_pending_actions_table',
	'datamachine_migrate_stored_flow_legacy_handler_fields',
);

foreach ( $schema_chain as $fn ) {
	if ( function_exists( $fn ) ) {
		continue;
	}
	$captured = $fn;
	eval( "function {$fn}() { \$GLOBALS['__test_schema_calls'][] = '{$captured}'; }" );
}

require_once dirname( __DIR__ ) . '/inc/migrations/runtime.php';

echo "=== Current Schema Runtime Smoke ===\n";

echo "\n[chain:1] Shared entry point invokes current schema ensures only\n";
$GLOBALS['__test_schema_calls'] = array();
datamachine_run_schema_migrations();
assert_runtime( 'all current schema ensures were called', $schema_chain === $GLOBALS['__test_schema_calls'] );

echo "\n[deferred:1] Cheap path: matching option short-circuits\n";
$GLOBALS['__test_schema_calls']                       = array();
$GLOBALS['__test_options']['datamachine_db_version'] = DATAMACHINE_VERSION;
datamachine_maybe_run_deferred_migrations();
assert_runtime( 'no schema ensures called when option matches constant', array() === $GLOBALS['__test_schema_calls'] );

echo "\n[deferred:2] Stale option triggers current schema ensures + bumps option\n";
$GLOBALS['__test_schema_calls']                       = array();
$GLOBALS['__test_options']['datamachine_db_version'] = '0.1.0-stale';
datamachine_maybe_run_deferred_migrations();
assert_runtime( 'current schema ensures called when option lags', $schema_chain === $GLOBALS['__test_schema_calls'] );
assert_runtime( 'option bumped to current DATAMACHINE_VERSION', DATAMACHINE_VERSION === $GLOBALS['__test_options']['datamachine_db_version'] );

echo "\n[hook:1] Runtime is hooked before main bootstrap\n";
$matching_hook = null;
foreach ( $GLOBALS['__test_actions'] as $registered ) {
	if ( 'plugins_loaded' === $registered['hook'] && 'datamachine_maybe_run_deferred_migrations' === $registered['callback'] ) {
		$matching_hook = $registered;
		break;
	}
}
assert_runtime( 'plugins_loaded hook registered', null !== $matching_hook );
assert_runtime( 'priority is 5', 5 === ( $matching_hook['priority'] ?? null ) );

echo "\n[runtime-file:1] Old data-shape migration calls are gone\n";
$runtime_src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/migrations/runtime.php' );
$removed     = array(
	'datamachine_migrate_to_layered_architecture',
	'datamachine_migrate_handler_keys_to_plural',
	'datamachine_migrate_agent_ping_to_system_task',
	'datamachine_migrate_update_to_upsert_step_type',
	'datamachine_migrate_ai_enabled_tools',
	'datamachine_migrate_split_queue_payload',
	'datamachine_migrate_user_message_queue_mode',
	'datamachine_migrate_webhook_auth_v2',
	'datamachine_migrate_settings_mode_models',
	'datamachine_migrate_ai_provider_keys_to_connectors',
);
foreach ( $removed as $symbol ) {
	assert_runtime( "runtime.php does not call {$symbol}()", ! str_contains( $runtime_src, $symbol . '();' ) );
}

echo "\n";
if ( 0 === $failed ) {
	echo "=== migration-runtime-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== migration-runtime-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
