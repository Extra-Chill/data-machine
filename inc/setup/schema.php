<?php
/**
 * Data Machine - current schema bootstrap.
 *
 * Installs and deploy-in-place updates converge directly on the 1.0 schema.
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
 * @return bool Whether schema and data-preservation convergence succeeded.
 */
function datamachine_ensure_current_schema(): bool {
	if ( function_exists( 'datamachine_ensure_all_tables' ) ) {
		return false !== datamachine_ensure_all_tables();
	}

	return true;
}

/**
 * Install the independently versioned post identity reservation schema.
 *
 * Identity-backed writes fail closed until this table exists on the current
 * site, so its rollout does not depend on the general plugin version gate.
 */
function datamachine_maybe_install_post_identity_reservations(): void {
	$version    = \DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations::SCHEMA_VERSION;
	$repository = new \DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations();
	$validation = $repository->validate_schema();
	if ( (int) get_option( 'datamachine_post_identity_reservations_schema', 0 ) === $version && true === $validation ) {
		return;
	}
	if ( true !== $validation ) {
		update_option( 'datamachine_post_identity_reservations_schema', 0, true );
	}

	\DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations::create_table();
	$repository = new \DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations();
	if ( true === $repository->validate_schema() ) {
		update_option( 'datamachine_post_identity_reservations_schema', $version, true );
	}
}

/**
 * Maybe ensure current schema on plugins_loaded.
 *
 * Reads the persisted `datamachine_db_version` option and compares it to
 * the `DATAMACHINE_VERSION` constant. On mismatch, ensures the canonical
 * schema and bumps the option to the new version.
 *
 * Cheap-path early return: when the option matches the constant (the
 * common case on every request for a stable install), this function does
 * one option read and exits. The chain only re-enters when a deploy has
 * advanced the constant past the option.
 *
 * Network considerations: on multisite, `datamachine_db_version` is stored
 * per-site (autoloaded `wp_options`). The hook fires per-request which is
 * naturally per-blog, so each subsite converges independently on its own
 * first post-deploy request. Sites with no traffic don't pay the cost
 * until they're hit. Activation still uses `datamachine_for_each_site()`
 * to initialize every site eagerly when the operator explicitly activates
 * network-wide.
 *
 * Network-scoped agent tables don't need per-site setup because they live
 * on `base_prefix` and are touched once at activation via
 * `datamachine_create_network_agent_tables()`.
 *
 * @since 0.84.0
 * @return void
 */
function datamachine_maybe_ensure_current_schema(): void {
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return;
	}

	// Cheap path: option matches code. Most requests on a stable install.
	$persisted = get_option( 'datamachine_db_version', '' );
	if ( DATAMACHINE_VERSION === $persisted ) {
		return;
	}

	// Mismatch: a deploy bumped the constant past the persisted option, or the
	// activation hook never fired for this install at all.
	if ( ! datamachine_ensure_current_schema() ) {
		return;
	}
	datamachine_run_deferred_site_setup();
	if ( function_exists( 'datamachine_mark_flow_schedule_reconciliation' ) ) {
		datamachine_mark_flow_schedule_reconciliation();
	}
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}

/**
 * Run the non-schema activation work that a deploy or a never-activated install
 * would otherwise skip.
 *
 * `datamachine_ensure_current_schema()` already covers every table, so an
 * install that was deployed in place rather than activated ends up with a
 * complete database and an incomplete site: no `datamachine_*` capabilities on
 * any role, and no seeded settings. That is a confusing half-working state —
 * the data is right and every capability check fails.
 *
 * Three ways an install reaches it:
 *
 * - deploy-in-place, where files are updated and activation is never toggled
 * - a test harness that loads the plugin without activating it
 * - a must-use plugin, for which `register_activation_hook` never fires at all
 *
 * Both calls below are idempotent by construction. `add_cap()` is a no-op for a
 * capability the role already has, and `datamachine_activate_defaults_for_site()`
 * uses `add_option()`, which will not overwrite an operator's existing settings.
 * That matters because this runs on every version bump, not only once.
 *
 * Deliberately NOT included: `ComposableFileGenerator::regenerate_all()`. It is
 * expensive and activation-only by design, and the composable files have their
 * own invalidation path. Adding it here would put a full regeneration on the
 * first request after every deploy.
 *
 * @since 0.171.4
 * @return void
 */
function datamachine_run_deferred_site_setup(): void {
	if ( function_exists( 'datamachine_register_capabilities' ) ) {
		datamachine_register_capabilities();
	}

	if ( function_exists( 'datamachine_activate_defaults_for_site' ) ) {
		datamachine_activate_defaults_for_site();
	}
}

// Ensure schema early in plugins_loaded (priority 5) so that
// `datamachine_run_datamachine_plugin` at priority 20 — and every consumer
// after it sees the current shape. Same hook fires for both activated
// and upgraded installs; the option-gate inside the function avoids
// double-running when activation already initialized this request.
add_action( 'plugins_loaded', 'datamachine_maybe_ensure_current_schema', 5 );
add_action( 'plugins_loaded', 'datamachine_maybe_install_post_identity_reservations', 5 );
