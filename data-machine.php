<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress operations engine: persistent agent memory, autonomous pipelines and flows, multi-turn chat, email I/O, and a full WP-CLI control surface over the WordPress Abilities API.
 * Version:           0.172.27
 * Requires at least: 7.0
 * Requires PHP:     8.2
 * Author:          Chris Huber, extrachill
 * Author URI:      https://chubes.net
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     data-machine
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMACHINE_VERSION', '0.172.27' );

define( 'DATAMACHINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Read the Agents API plugin header version without loading the plugin file.
 *
 * @param string $plugin_file Plugin file path.
 * @return string|null Version string, or null when unavailable.
 */
function datamachine_read_agents_api_plugin_version( string $plugin_file ): ?string {
	if ( ! is_readable( $plugin_file ) ) {
		return null;
	}

	if ( function_exists( 'get_file_data' ) ) {
		$headers = get_file_data( $plugin_file, array( 'version' => 'Version' ), 'plugin' );
		$version = trim( (string) ( $headers['version'] ?? '' ) );

		return '' !== $version ? $version : null;
	}

	return null;
}

/**
 * Load bundled Agents API unless another copy is already active.
 *
 * @return array{loaded:string,bundled_version:?string,active_version:?string,active_file:?string,warning:?string}
 */
function datamachine_load_bundled_agents_api(): array {
	$bundled_file    = __DIR__ . '/vendor/wordpress/agents-api/agents-api.php';
	$bundled_version = datamachine_read_agents_api_plugin_version( $bundled_file );

	if ( defined( 'AGENTS_API_LOADED' ) ) {
		$active_file    = defined( 'AGENTS_API_PLUGIN_FILE' ) ? (string) constant( 'AGENTS_API_PLUGIN_FILE' ) : null;
		$active_version = defined( 'AGENTS_API_VERSION' ) ? (string) constant( 'AGENTS_API_VERSION' ) : null;
		if ( null === $active_version && null !== $active_file ) {
			$active_version = datamachine_read_agents_api_plugin_version( $active_file );
		}

		$warning = null;
		if ( null !== $bundled_version && null !== $active_version && $bundled_version !== $active_version ) {
			$warning = sprintf(
				'Data Machine is using an already-loaded Agents API version %1$s instead of its bundled version %2$s. Deactivate the standalone Agents API plugin or align versions to avoid runtime substrate skew.',
				$active_version,
				$bundled_version
			);
		}

		return array(
			'loaded'          => 'external',
			'bundled_version' => $bundled_version,
			'active_version'  => $active_version,
			'active_file'     => $active_file,
			'warning'         => $warning,
		);
	}

	require_once $bundled_file;

	return array(
		'loaded'          => 'bundled',
		'bundled_version' => $bundled_version,
		'active_version'  => $bundled_version,
		'active_file'     => defined( 'AGENTS_API_PLUGIN_FILE' )
			? (string) constant( 'AGENTS_API_PLUGIN_FILE' )
			: $bundled_file,
		'warning'         => null,
	);
}

/**
 * Emit any deferred Agents API load warning through the Data Machine logger.
 *
 * @return void
 */
function datamachine_log_agents_api_load_warning(): void {
	global $datamachine_agents_api_load_state;

	if ( ! is_array( $datamachine_agents_api_load_state ) || empty( $datamachine_agents_api_load_state['warning'] ) ) {
		return;
	}

	do_action(
		'datamachine_log',
		'warning',
		(string) $datamachine_agents_api_load_state['warning'],
		array(
			'component'       => 'agents-api',
			'loaded'          => $datamachine_agents_api_load_state['loaded'] ?? null,
			'active_version'  => $datamachine_agents_api_load_state['active_version'] ?? null,
			'bundled_version' => $datamachine_agents_api_load_state['bundled_version'] ?? null,
			'active_file'     => $datamachine_agents_api_load_state['active_file'] ?? null,
		)
	);
}

require_once __DIR__ . '/vendor/autoload.php';
$datamachine_agents_api_load_state = datamachine_load_bundled_agents_api();

\DataMachine\Core\Bootstrap\CliServiceProvider::register();

// Procedural includes and side-effect registrations (see inc/bootstrap.php).
// Namespaced classes without file-level side effects rely on Composer PSR-4.
require_once __DIR__ . '/inc/bootstrap.php';

\DataMachine\Core\Bootstrap\AlwaysOnServiceProvider::register_scheduler();

/**
 * Prevent AS migration scheduling during wp-phpunit install bootstrap.
 *
 * @return void
 */
function datamachine_skip_action_scheduler_migration_during_install(): void {
	\DataMachine\Core\Bootstrap\AlwaysOnServiceProvider::skip_action_scheduler_migration_during_install();
}

function datamachine_run_datamachine_plugin() {
	\DataMachine\Core\Bootstrap\RuntimeServiceProvider::register();
}

/**
 * Request full Data Machine runtime loading for the current request.
 *
 * Host runtimes that execute Data Machine abilities from non-standard request
 * shapes can call this before `plugins_loaded` priority 20 instead of forcing
 * the generic runtime gate open with a broad filter.
 *
 * @param string $reason Optional caller-readable activation reason.
 * @return void
 */
function datamachine_request_full_runtime( string $reason = '' ): void {
	\DataMachine\Core\Bootstrap\RuntimeEnvironment::request_full_runtime( $reason );
}

/**
 * Request and, when possible, activate the full Data Machine runtime now.
 *
 * This covers late plugin activation flows where a host activates Data Machine
 * after the normal `plugins_loaded` bootstrap window has already passed.
 *
 * @param string $reason Optional caller-readable activation reason.
 * @return void
 */
function datamachine_activate_full_runtime( string $reason = '' ): void {
	datamachine_request_full_runtime( $reason );

	if ( did_action( 'plugins_loaded' ) ) {
		datamachine_run_datamachine_plugin();
	}
}

/**
 * Determine whether the full Data Machine runtime is needed for this request.
 *
 * Normal frontend page views do not need the agent, REST, tool, queue, or admin
 * runtime. Keeping that machinery out of the hot path protects theme rendering
 * while preserving every interactive/background entry point.
 *
 * @return bool True when full runtime registration should run.
 */
function datamachine_should_load_full_runtime(): bool {
	return \DataMachine\Core\Bootstrap\RuntimeEnvironment::should_load_full_runtime();
}


\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_defaults_hook( __FILE__ );

function datamachine_activate_plugin_defaults( $network_wide = false ) {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::activate_defaults( (bool) $network_wide );
}

/**
 * Set default settings for a single site.
 */
function datamachine_activate_defaults_for_site() {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::activate_defaults_for_site();
}

if ( did_action( 'plugins_loaded' ) ) {
	datamachine_run_datamachine_plugin();
} else {
	// @phpstan-ignore-next-line WordPress stubs in CI omit the optional priority argument.
	add_action( 'plugins_loaded', 'datamachine_run_datamachine_plugin', 20 );
}

/**
 * Register ability categories unconditionally on every request.
 *
 * Categories are a cheap registration (~20 string entries) but they are a
 * *contract* depended on by every Data Machine extension plugin
 * (`data-machine-business`, `data-machine-socials`, etc.) and any consumer
 * that calls `wp_register_ability( ..., [ 'category' => 'datamachine-*' ] )`.
 *
 * They MUST NOT be gated by `datamachine_should_load_full_runtime()` because
 * extension plugins do not honour that gate — they instantiate their own
 * ability classes at `plugins_loaded:20` regardless of request shape, and
 * those abilities register against Data Machine categories. If the categories
 * are missing when the lazy `wp_abilities_api_init` fire happens (e.g. when
 * a frontend page calls `wp_get_ability()` via Frontend Agent Chat or an
 * OG-card task), every extension ability registration triggers a
 * `_doing_it_wrong` notice and the ability is silently dropped from the
 * registry. See: Extra-Chill/data-machine#2287.
 *
 * Loading the file is cheap enough to do at file include time; calling
 * `ensure_registered()` here attaches the `wp_abilities_api_categories_init`
 * hook before `init` fires (the action cannot fire before `init`, so any
 * later `plugins_loaded` priority works too — but earlier is safer for any
 * site that includes our plugin file through late runtime inspection or
 * similar late-load paths).
 */
\DataMachine\Core\Bootstrap\AbilityServiceProvider::register_lightweight();


/**
 * Load and instantiate all step types - they self-register via constructors.
 * Uses StepTypeRegistrationTrait for standardized registration.
 */
function datamachine_load_step_types() {
	\DataMachine\Core\Bootstrap\RuntimeServiceProvider::register_step_types();
}

/**
 * Load and instantiate all handlers - they self-register via constructors.
 * Clean, explicit approach using composer PSR-4 autoloading.
 */
function datamachine_load_handlers() {
	\DataMachine\Core\Bootstrap\RuntimeServiceProvider::register_handlers();
}

function datamachine_allow_json_upload( $mimes ) {
	return \DataMachine\Core\Bootstrap\AlwaysOnServiceProvider::allow_json_upload( $mimes );
}

\DataMachine\Core\Bootstrap\AlwaysOnServiceProvider::register_wordpress_hooks();
\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_lifecycle_hooks( __FILE__ );

/**
 * Register Data Machine custom capabilities on roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_register_capabilities(): void {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_capabilities();
}

/**
 * Remove Data Machine custom capabilities from roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_remove_capabilities(): void {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::remove_capabilities();
}

function datamachine_deactivate_plugin() {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::deactivate();
}

/**
 * Plugin activation handler.
 *
 * Creates database tables, log directory, and re-schedules any flows
 * with non-manual scheduling intervals.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function datamachine_activate_plugin( $network_wide = false ) {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::activate( (bool) $network_wide );
}

/**
 * Create network-scoped agent tables.
 *
 * Agent identity, tokens, and access grants are shared across the multisite
 * network, following the WordPress pattern where wp_users/wp_usermeta use
 * base_prefix while per-site content uses site-specific prefixes.
 *
 * Safe to call multiple times — dbDelta is idempotent.
 */
function datamachine_create_network_agent_tables() {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::create_network_agent_tables();
}

/**
 * Run activation tasks for a single site.
 *
 * Creates tables, log directory, default memory files, and re-schedules flows.
 * Called directly on single-site, or per-site during network activation and
 * new site creation.
 */
function datamachine_activate_for_site() {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::activate_for_site();
}

/**
 * Create or update every Data Machine database table.
 *
 * Shared by activation and the deploy-time deferred runtime. dbDelta and
 * the per-table column ensures are idempotent, so this is safe to call on
 * every version bump.
 *
 * @return void
 */
function datamachine_ensure_all_tables() {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::ensure_all_tables();
}

/**
 * Resolve or create first-class agent ID for a WordPress user.
 *
 * @since 0.37.0
 *
 * @param int $user_id WordPress user ID.
 * @return int Agent ID, or 0 when resolution fails.
 */
function datamachine_resolve_or_create_agent_id( int $user_id ): int {
	$user_id = absint( $user_id );

	if ( $user_id <= 0 ) {
		return 0;
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$existing    = $agents_repo->get_by_owner_id( $user_id );

	if ( ! empty( $existing['agent_id'] ) ) {
		return (int) $existing['agent_id'];
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return 0;
	}

	$agent_slug  = sanitize_title( (string) $user->user_login );
	$agent_name  = (string) $user->display_name;
	$agent_model = \DataMachine\Core\PluginSettings::getModelForMode( 'chat' );

	return $agents_repo->create_if_missing(
		$agent_slug,
		$agent_name,
		$user_id,
		array(
			'model' => array(
				'default' => $agent_model,
			),
		)
	);
}

/**
 * Resolve the acting agent identity for a system/ability-triggered operation.
 *
 * Media, SEO, and linking abilities enqueue agent-owned queued tasks (alt text,
 * meta descriptions, image optimization, internal linking). Those tasks require
 * a real agent owner in TaskScheduler — a queued task with agent_id/user_id 0 is
 * rejected by the agent-context gate before it ever runs.
 *
 * System tasks should attribute to the install's default agent rather than the
 * human who triggered the action. A user uploading an image is not "operating an
 * agent"; minting a persistent agent row for them silts the agents table with
 * stray identities. The triggering user is preserved separately in
 * `triggering_user_id` so callers can carry it as task-context metadata for
 * audit without conflating attribution with agent identity.
 *
 * @since 0.72.0
 * @since 0.160.0 Always resolves to the install default agent; never auto-provisions
 *               a per-human agent row for system tasks. ChatOrchestrator remains
 *               the legitimate caller of datamachine_resolve_or_create_agent_id().
 *
 * @return array{user_id:int,agent_id:int,triggering_user_id:int} Acting user id
 *         (the default agent owner), its agent id, and the original triggering
 *         user id. user_id and agent_id may be 0 only when the install has no
 *         resolvable default owner at all.
 */
function datamachine_resolve_system_agent_context(): array {
	$triggering_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$user_id            = 0;

	// Always attribute system-task work to the install's default agent owner.
	// This prevents every authenticated user who triggers a media/SEO/linking
	// ability from getting a full agent row minted from their login.
	if ( class_exists( \DataMachine\Core\FilesRepository\DirectoryManager::class ) ) {
		$user_id = (int) \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
	}

	// Resolve (or create) the agent row for the *default owner* only. System
	// tasks never auto-provision an agent for the triggering human.
	$agent_id = ( $user_id > 0 && function_exists( 'datamachine_resolve_or_create_agent_id' ) )
		? datamachine_resolve_or_create_agent_id( $user_id )
		: 0;

	return array(
		'user_id'            => $user_id,
		'agent_id'           => $agent_id,
		'triggering_user_id' => $triggering_user_id,
	);
}

/**
 * Run a callback for every site on the network.
 *
 * Switches to each site, runs the callback, then restores. Used by
 * activation hooks and new site hooks to ensure per-site setup.
 *
 * @param callable $callback Function to call in each site context.
 */
function datamachine_for_each_site( callable $callback ) {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::for_each_site( $callback );
}

/**
 * Create Data Machine tables and defaults when a new site is added to the network.
 *
 * Only runs if Data Machine is network-active.
 *
 * @param WP_Site $new_site New site object.
 */
function datamachine_on_new_site( \WP_Site $new_site ) {
	\DataMachine\Core\Bootstrap\ActivationServiceProvider::on_new_site( $new_site );
}

\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_new_site_hook();

// Migrations, scaffolding, and activation helpers.
require_once __DIR__ . '/inc/migrations/load.php';
