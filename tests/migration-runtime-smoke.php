<?php
/**
 * Pure-PHP smoke test for the schema-migration runtime (#1301).
 *
 * Run with: php tests/migration-runtime-smoke.php
 *
 * #1301 moved the schema-migration chain off `register_activation_hook`
 * and onto a `plugins_loaded` hook gated by a `datamachine_db_version`
 * option, mirroring WordPress core's `db_version` pattern. The bug: the
 * activation hook does not fire on plugin upgrade-in-place
 * (`homeboy deploy`, `wp plugin update`, manual rsync) so persisted DB
 * shape silently lagged the running code after every deploy.
 *
 * The fix has three contracts that must hold:
 *
 *   1. `datamachine_run_schema_migrations()` is the shared entry point.
 *      Both activation and the deferred runtime hook call it. Each
 *      migration in the chain is invoked exactly once per call.
 *
 *   2. `datamachine_maybe_run_deferred_migrations()` short-circuits when
 *      the persisted `datamachine_db_version` option matches the
 *      DATAMACHINE_VERSION constant (the cheap path, fires every
 *      request). When they differ, it runs the chain and bumps the
 *      option.
 *
 *   3. The deferred runtime is hooked at `plugins_loaded` priority 5.
 *      Earlier than `datamachine_run_datamachine_plugin` (priority 20)
 *      so consumers see the migrated shape.
 *
 * This smoke validates all three by stubbing WordPress's option store
 * and hook registry, then loading the production runtime file and
 * exercising it. The production file is the real `inc/migrations/runtime.php`
 * (no byte-mirror harness — the contracts are too thin to need one).
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mirror the production constant. The shared runtime reads this from
// global scope; we set it before requiring the file.
if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
	define( 'DATAMACHINE_VERSION', '0.84.0-test' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
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

// --- WordPress stubs -------------------------------------------------

$GLOBALS['__test_options']         = array();
$GLOBALS['__test_actions']         = array();
$GLOBALS['__test_migration_calls'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['__test_options'][ $name ] ?? $default;
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

// --- Stubbed migration registry -------------------------------------

/**
 * The runtime file calls a chain of `datamachine_migrate_*` functions.
 * We stub each to record its invocation so the smoke can assert the
 * chain ran end-to-end and didn't accidentally drop or duplicate a
 * migration.
 *
 * The list MUST stay in sync with the real chain in
 * `inc/migrations/runtime.php::datamachine_run_schema_migrations()`.
 * Drift is the whole point this smoke exists to catch.
 */
$migration_chain = array(
	'datamachine_migrate_to_layered_architecture',
	'datamachine_migrate_handler_keys_to_plural',
	'datamachine_backfill_agent_ids',
	'datamachine_assign_orphaned_resources_to_sole_agent',
	'datamachine_migrate_user_md_to_network_scope',
	'datamachine_migrate_agents_to_network_scope',
	'datamachine_drop_orphaned_agent_tables',
	'datamachine_migrate_agent_ping_to_system_task',
	'datamachine_migrate_agent_ping_pipeline_to_system_task',
	'datamachine_migrate_agent_ping_task_to_agent_call',
	'datamachine_migrate_update_to_upsert_step_type',
	'datamachine_strip_pipeline_step_provider_model',
	'datamachine_migrate_ai_enabled_tools',
	'datamachine_migrate_handler_slug_scalar',
	'datamachine_migrate_split_queue_payload',
	'datamachine_migrate_user_message_queue_mode',
	'datamachine_migrate_webhook_auth_v2',
	'datamachine_migrate_agent_config_model_shape',
	'datamachine_drop_redundant_post_pipeline_meta',
	'datamachine_migrate_bundle_artifacts_table',
);

foreach ( $migration_chain as $fn ) {
	if ( function_exists( $fn ) ) {
		continue;
	}
	$captured = $fn;
	eval(
		"function {$fn}() { \$GLOBALS['__test_migration_calls'][] = '{$captured}'; }"
	);
}

// --- Load the production runtime under test --------------------------

require_once dirname( __DIR__ ) . '/inc/migrations/runtime.php';

echo "=== Migration Runtime Smoke (#1301) ===\n";

// ---------------------------------------------------------------
// SECTION 1: datamachine_run_schema_migrations() invokes the full chain.
// ---------------------------------------------------------------

echo "\n[chain:1] Shared entry point invokes every migration exactly once\n";
$GLOBALS['__test_migration_calls'] = array();
datamachine_run_schema_migrations();
assert_runtime(
	'all ' . count( $migration_chain ) . ' migrations were called',
	count( $GLOBALS['__test_migration_calls'] ) === count( $migration_chain )
);
foreach ( $migration_chain as $expected ) {
	assert_runtime(
		"chain includes {$expected}",
		in_array( $expected, $GLOBALS['__test_migration_calls'], true )
	);
}

echo "\n[chain:2] No duplicate calls in a single chain run\n";
$counts     = array_count_values( $GLOBALS['__test_migration_calls'] );
$duplicates = array_filter( $counts, fn( $c ) => $c > 1 );
assert_runtime(
	'no migration called more than once',
	0 === count( $duplicates )
);

echo "\n[chain:3] Chain order is preserved (queue split runs before queue mode)\n";
$queue_split_pos = array_search(
	'datamachine_migrate_split_queue_payload',
	$GLOBALS['__test_migration_calls'],
	true
);
$queue_mode_pos  = array_search(
	'datamachine_migrate_user_message_queue_mode',
	$GLOBALS['__test_migration_calls'],
	true
);
assert_runtime(
	'split_queue_payload runs before user_message_queue_mode',
	false !== $queue_split_pos
		&& false !== $queue_mode_pos
		&& $queue_split_pos < $queue_mode_pos
);
$handler_keys_pos = array_search(
	'datamachine_migrate_handler_keys_to_plural',
	$GLOBALS['__test_migration_calls'],
	true
);
$ai_tools_pos     = array_search(
	'datamachine_migrate_ai_enabled_tools',
	$GLOBALS['__test_migration_calls'],
	true
);
assert_runtime(
	'handler_keys_to_plural runs before ai_enabled_tools (#1216 dependency)',
	false !== $handler_keys_pos
		&& false !== $ai_tools_pos
		&& $handler_keys_pos < $ai_tools_pos
);
$handler_scalar_pos = array_search(
	'datamachine_migrate_handler_slug_scalar',
	$GLOBALS['__test_migration_calls'],
	true
);
assert_runtime(
	'handler_slug_scalar runs after ai_enabled_tools and before split_queue_payload (#1293 dependency)',
	false !== $handler_scalar_pos
		&& false !== $ai_tools_pos
		&& false !== $queue_split_pos
		&& $ai_tools_pos < $handler_scalar_pos
		&& $handler_scalar_pos < $queue_split_pos
);

// ---------------------------------------------------------------
// SECTION 2: deferred runtime hook gates on db_version.
// ---------------------------------------------------------------

echo "\n[deferred:1] Cheap path: matching option short-circuits without running chain\n";
$GLOBALS['__test_migration_calls']                       = array();
$GLOBALS['__test_options']['datamachine_db_version']     = DATAMACHINE_VERSION;
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'no migrations called when option matches constant',
	0 === count( $GLOBALS['__test_migration_calls'] )
);
assert_runtime(
	'option preserved at the matching value',
	DATAMACHINE_VERSION === $GLOBALS['__test_options']['datamachine_db_version']
);

echo "\n[deferred:2] Stale option triggers chain + bumps option to current\n";
$GLOBALS['__test_migration_calls']                       = array();
$GLOBALS['__test_options']['datamachine_db_version']     = '0.79.0-stale';
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'all migrations called when option lags constant',
	count( $GLOBALS['__test_migration_calls'] ) === count( $migration_chain )
);
assert_runtime(
	'option bumped to current DATAMACHINE_VERSION after chain',
	DATAMACHINE_VERSION === $GLOBALS['__test_options']['datamachine_db_version']
);

echo "\n[deferred:3] Missing option (fresh install pre-activation) triggers chain\n";
$GLOBALS['__test_migration_calls'] = array();
unset( $GLOBALS['__test_options']['datamachine_db_version'] );
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'missing option treated as mismatch — chain runs',
	count( $GLOBALS['__test_migration_calls'] ) === count( $migration_chain )
);
assert_runtime(
	'option created and set to current after chain',
	DATAMACHINE_VERSION === $GLOBALS['__test_options']['datamachine_db_version']
);

echo "\n[deferred:4] Re-entry after bump is the cheap path again\n";
$GLOBALS['__test_migration_calls'] = array();
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'second call is a no-op now that option matches',
	0 === count( $GLOBALS['__test_migration_calls'] )
);

echo "\n[deferred:5] Newer option than constant (downgrade path) is also cheap\n";
// The contract is "match → no-op". Anything that mismatches re-enters,
// including downgrade. That's safe because each migration is gated on its
// own option; a downgraded constant doesn't undo persisted shape.
$GLOBALS['__test_options']['datamachine_db_version'] = '99.99.0-future';
$GLOBALS['__test_migration_calls']                   = array();
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'downgrade triggers chain (idempotent gates make it safe)',
	count( $GLOBALS['__test_migration_calls'] ) === count( $migration_chain )
);
assert_runtime(
	'option bumped down to current constant after chain',
	DATAMACHINE_VERSION === $GLOBALS['__test_options']['datamachine_db_version']
);

echo "\n[deferred:6] WP_INSTALLING short-circuits before option access\n";
$GLOBALS['__test_migration_calls'] = array();
unset( $GLOBALS['__test_options']['datamachine_db_version'] );
if ( ! defined( 'WP_INSTALLING' ) ) {
	define( 'WP_INSTALLING', true );
}
datamachine_maybe_run_deferred_migrations();
assert_runtime(
	'no migrations called while WordPress is installing',
	0 === count( $GLOBALS['__test_migration_calls'] )
);
assert_runtime(
	'db_version option not created while WordPress is installing',
	! isset( $GLOBALS['__test_options']['datamachine_db_version'] )
);

// ---------------------------------------------------------------
// SECTION 3: hook registration shape.
// ---------------------------------------------------------------

echo "\n[hook:1] Deferred runtime is hooked at plugins_loaded priority 5\n";
$matching_hook = null;
foreach ( $GLOBALS['__test_actions'] as $registered ) {
	if (
		'plugins_loaded' === $registered['hook']
		&& 'datamachine_maybe_run_deferred_migrations' === $registered['callback']
	) {
		$matching_hook = $registered;
		break;
	}
}
assert_runtime(
	'plugins_loaded hook registered for datamachine_maybe_run_deferred_migrations',
	null !== $matching_hook
);
assert_runtime(
	'priority is 5 (before main bootstrap at 20)',
	5 === ( $matching_hook['priority'] ?? null )
);

// ---------------------------------------------------------------
// SECTION 4: activation path still calls the shared entry point.
// ---------------------------------------------------------------

echo "\n[activation:1] data-machine.php delegates to the shared runtime\n";
// We can't load data-machine.php in this harness (it pulls in real WP),
// but we can grep it. The contract: the activation function calls
// `datamachine_run_schema_migrations()` instead of inlining the chain.
$plugin_main = (string) file_get_contents(
	dirname( __DIR__ ) . '/data-machine.php'
);
assert_runtime(
	'activation calls shared datamachine_run_schema_migrations()',
	false !== strpos(
		$plugin_main,
		'datamachine_run_schema_migrations();'
	)
);
assert_runtime(
	'activation no longer inlines datamachine_migrate_to_layered_architecture()',
	0 === substr_count(
		$plugin_main,
		'datamachine_migrate_to_layered_architecture('
	)
);
assert_runtime(
	'activation no longer inlines datamachine_migrate_user_message_queue_mode()',
	0 === substr_count(
		$plugin_main,
		'datamachine_migrate_user_message_queue_mode('
	)
);
assert_runtime(
	'activation still bumps datamachine_db_version after the chain',
	false !== strpos(
		$plugin_main,
		"update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );"
	)
);

// ---------------------------------------------------------------
// SECTION 5: runtime file declares the chain in the documented order.
// ---------------------------------------------------------------

echo "\n[runtime-file:1] runtime.php chain matches the test fixture\n";
$runtime_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/inc/migrations/runtime.php'
);
foreach ( $migration_chain as $expected ) {
	assert_runtime(
		"runtime.php declares {$expected}();",
		false !== strpos( $runtime_src, $expected . '();' )
	);
}

echo "\n";
if ( 0 === $failed ) {
	echo "=== migration-runtime-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== migration-runtime-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
