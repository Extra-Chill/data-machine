<?php
/**
 * Data Machine — Schema migration runtime (#1301).
 *
 * Schema migrations were previously gated only on `register_activation_hook`
 * via `datamachine_activate_for_site()`. The hook does not fire on plugin
 * upgrade-in-place (`homeboy deploy`, `wp plugin update`, manual rsync, or
 * any deploy path that doesn't toggle activation). Sites kept the old
 * persisted shape and ran the new code against it, with no migration ever
 * firing.
 *
 * The bug shape is invisible: each migration is gated on its own option
 * (`datamachine_*_migrated`), so deferring is free for the activation path.
 * The cost only shows up at runtime, where the new code reads the new
 * shape and finds the old one. PR #1296 (queue_mode) and PR #1216
 * (ai_enabled_tools) both bit on this during live-verify.
 *
 * The fix mirrors WordPress core's `db_version` pattern. A single option
 * (`datamachine_db_version`) tracks the migrated version. Every request
 * compares it against the `DATAMACHINE_VERSION` constant; on mismatch,
 * the migration chain re-enters at `plugins_loaded` priority 5 (before
 * the main plugin bootstrap at priority 20, so any migrated shape is in
 * place before consumers run).
 *
 * Each migration is already idempotent and gated on its own option, so
 * re-entry on every version bump is cheap: every gate short-circuits when
 * nothing's needed. The version-bump just decides when to enter the chain
 * at all.
 *
 * Table creation (`dbDelta` + per-table `create_table`) stays on activation.
 * Tables don't drift the way persisted data does — `dbDelta` is idempotent
 * but expensive, and a fresh install needs an activation hook to bootstrap
 * the schema regardless. Schema-altering migrations (e.g. `ensure_*_column`
 * helpers on `Chat`) already have their own `plugins_loaded` priority 6
 * hook in `data-machine.php`.
 *
 * @package DataMachine
 * @since 0.84.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the full schema-migration chain for the current site.
 *
 * Each migration is required to be idempotent and gated on its own
 * `datamachine_*_migrated` option (or equivalent). This function is
 * the shared entry point for both:
 *
 *   1. Activation (`datamachine_activate_for_site()`) — runs the chain
 *      after table creation on initial install / explicit activation.
 *   2. Deferred runtime (`datamachine_maybe_run_deferred_migrations()`)
 *      — runs the chain on the first request after a deploy that
 *      bumped `DATAMACHINE_VERSION` past the persisted
 *      `datamachine_db_version` option.
 *
 * Order matters where one migration depends on another's resulting
 * shape (e.g. `split_queue_payload` runs before `user_message_queue_mode`
 * because the latter assumes the split is in place). Preserve this
 * ordering when adding new migrations to the chain.
 *
 * @since 0.84.0
 * @return void
 */
function datamachine_run_schema_migrations(): void {
	// Layered architecture migration (idempotent).
	datamachine_migrate_to_layered_architecture();

	// Migrate flow_config handler keys from singular to plural (idempotent).
	datamachine_migrate_handler_keys_to_plural();

	// Backfill agent_id on pipelines, flows, and jobs from user_id→owner_id mapping (idempotent).
	datamachine_backfill_agent_ids();

	// Assign orphaned resources (agent_id IS NULL) to sole agent on single-agent installs (idempotent).
	datamachine_assign_orphaned_resources_to_sole_agent();

	// Migrate USER.md to network-scoped paths and create NETWORK.md on multisite (idempotent).
	datamachine_migrate_user_md_to_network_scope();

	// Migrate per-site agents to network-scoped tables (idempotent).
	datamachine_migrate_agents_to_network_scope();

	// Drop orphaned per-site agent tables left behind by the migration (idempotent).
	datamachine_drop_orphaned_agent_tables();

	// Migrate agent_ping step types to flow configs (idempotent).
	datamachine_migrate_agent_ping_to_system_task();

	// Migrate agent_ping step types to pipeline configs (idempotent).
	datamachine_migrate_agent_ping_pipeline_to_system_task();

	// Migrate existing agent_ping system_task configs to agent_call (idempotent).
	datamachine_migrate_agent_ping_task_to_agent_call();

	// Migrate `update` step type to `upsert` in pipeline/flow configs (idempotent).
	datamachine_migrate_update_to_upsert_step_type();

	// Strip dead `provider`/`model` keys from pipeline_config rows (data-machine#1180, idempotent).
	datamachine_strip_pipeline_step_provider_model();

	// Move AI step tools from handler_slugs to enabled_tools (#1205 Phase 2b, idempotent).
	datamachine_migrate_ai_enabled_tools();

	// Collapse handler storage to match step-type cardinality (#1293, idempotent).
	datamachine_migrate_handler_slug_scalar();

	// Split prompt_queue / config_patch_queue payload polymorphism (#1292, idempotent).
	datamachine_migrate_split_queue_payload();

	// Collapse user_message into prompt_queue and replace queue_enabled with
	// queue_mode enum (drain | loop | static) on every queueable step (#1291,
	// idempotent).
	datamachine_migrate_user_message_queue_mode();

	// Normalize webhook auth scheduling configs to canonical v2 shape (#1333,
	// idempotent).
	datamachine_migrate_webhook_auth_v2();

	// Migrate stale agent_config.model.default.{provider,model} shape to
	// top-level default_provider/default_model so the resolver can read it.
	// Pairs with the writer fix in inc/Engine/Agents/register-agents.php.
	// Idempotent.
	datamachine_migrate_agent_config_model_shape();

	// Drop redundant _datamachine_post_pipeline_id rows (#1091). Idempotent.
	datamachine_drop_redundant_post_pipeline_meta();

	// Ensure installed bundle artifact tracking table exists on upgraded installs (#1531).
	datamachine_migrate_bundle_artifacts_table();
}

/**
 * Maybe run schema migrations on plugins_loaded.
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
	if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
		return;
	}

	// Cheap path: option matches code. Most requests on a stable install.
	$persisted = get_option( 'datamachine_db_version', '' );
	if ( DATAMACHINE_VERSION === $persisted ) {
		return;
	}

	// Mismatch — either fresh install whose activation just ran (option
	// already bumped to current at the end of `datamachine_activate_for_site`),
	// or a deploy bumped the constant past the persisted option. Run the
	// chain (each migration's gate decides whether real work is needed),
	// then bump the option.
	datamachine_run_schema_migrations();
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}

// Run deferred migrations early in plugins_loaded (priority 5) so that
// `datamachine_run_datamachine_plugin` at priority 20 — and every consumer
// after it — sees the migrated shape. Same hook fires for both activated
// and upgraded installs; the option-gate inside the function avoids
// double-running when activation already migrated this request.
add_action( 'plugins_loaded', 'datamachine_maybe_run_deferred_migrations', 5 );
