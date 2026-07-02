<?php
/**
 * Data Machine — current schema runtime.
 *
 * Pre-1.0 Data Machine carried a long chain of persisted data-shape migrations.
 * Those old internal shapes are no longer supported. This runtime keeps only
 * current deploy-time schema ensures that are still needed when code is updated
 * without toggling plugin activation.
 *
 * @package DataMachine
 * @since 0.84.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure current deploy-time schema additions exist for the current site.
 *
 * Creates or updates every Data Machine database table and runs the
 * version-gated schema ensures. dbDelta is idempotent, so this is cheap to
 * re-run on every DATAMACHINE_VERSION bump and safe on fresh installs whose
 * activation hook never fired (test harness, deploy-in-place, etc).
 *
 * @since 0.84.0
 * @return void
 */
function datamachine_run_schema_migrations(): void {
	if ( function_exists( 'datamachine_ensure_all_tables' ) ) {
		datamachine_ensure_all_tables();
	}

	datamachine_migrate_bundle_artifacts_table();
	datamachine_migrate_run_metadata_table();
	datamachine_migrate_processed_item_claims();
	datamachine_migrate_pending_actions_table();
	datamachine_migrate_chat_sessions_to_network();
}

/**
 * Maybe ensure current schema on plugins_loaded.
 *
 * Reads the persisted `datamachine_db_version` option and compares it to
 * the `DATAMACHINE_VERSION` constant. On mismatch, runs the migration
 * chain and bumps the option to the new version.
 *
 * Cheap-path early return: when the option matches the constant (the
 * common case on every request for a stable install), this function does
 * one option read and exits. The chain only re-enters when a deploy has
 * advanced the constant past the option.
 *
 * Network considerations: on multisite, `datamachine_db_version` is stored
 * per-site (autoloaded `wp_options`). The hook fires per-request which is
 * naturally per-blog, so each subsite migrates independently on its own
 * first post-deploy request. Sites with no traffic don't pay the cost
 * until they're hit. Activation still uses `datamachine_for_each_site()`
 * to migrate every site eagerly when the operator explicitly activates
 * network-wide.
 *
 * Network-scoped agent tables don't need per-site migration — they live
 * on `base_prefix` and are touched once at activation via
 * `datamachine_create_network_agent_tables()`.
 *
 * @since 0.84.0
 * @return void
 */
function datamachine_maybe_run_deferred_migrations(): void {
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return;
	}

	// Cheap path: option matches code. Most requests on a stable install.
	$persisted = get_option( 'datamachine_db_version', '' );
	if ( DATAMACHINE_VERSION === $persisted ) {
		return;
	}

	// Mismatch: a deploy bumped the constant past the persisted option.
	datamachine_run_schema_migrations();
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}

// Run deferred migrations early in plugins_loaded (priority 5) so that
// `datamachine_run_datamachine_plugin` at priority 20 — and every consumer
// after it — sees the migrated shape. Same hook fires for both activated
// and upgraded installs; the option-gate inside the function avoids
// double-running when activation already migrated this request.
add_action( 'plugins_loaded', 'datamachine_maybe_run_deferred_migrations', 5 );
