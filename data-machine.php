<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress operations engine: persistent agent memory, autonomous pipelines and flows, multi-turn chat, email I/O, and a full WP-CLI control surface over the WordPress Abilities API.
 * Version:           0.175.13
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

define( 'DATAMACHINE_VERSION', '0.175.13' );

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

/*
 * Registration ownership map for source-level compatibility checks:
 * AbilityServiceProvider: InspectRequestAbility.php,
 * new \DataMachine\Abilities\AI\InspectRequestAbility(),
 * new \DataMachine\Engine\AI\Actions\ResolvePendingActionAbility(),
 * new \DataMachine\Engine\AI\Actions\ResolvePendingAction(),
 * PendingActionObservers::register, WordPressActionDispatchObserver,
 * new \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility().
 * RuntimeServiceProvider: AuthRefHandlerConfig::register().
 * HostIntegrationServiceProvider: agents_pending_action_permission.
 * AlwaysOnServiceProvider: RecurringScheduler::registerGenerationFence().
 */

\DataMachine\Core\Bootstrap\AlwaysOnServiceProvider::register_scheduler();

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
		\DataMachine\Core\Bootstrap\RuntimeServiceProvider::register();
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

if ( did_action( 'plugins_loaded' ) ) {
	\DataMachine\Core\Bootstrap\RuntimeServiceProvider::register();
} else {
	// @phpstan-ignore-next-line WordPress stubs in CI omit the optional priority argument.
	add_action( 'plugins_loaded', array( \DataMachine\Core\Bootstrap\RuntimeServiceProvider::class, 'register' ), 20 );
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
 * Resolve an existing default agent without provisioning a new identity.
 *
 * The user's explicit active-agent preference is authoritative. A single
 * owned agent remains a compatibility fallback for existing installations;
 * multiple owned agents require an explicit choice.
 *
 * @since 0.173.0
 *
 * @param int $user_id WordPress user ID.
 * @return int Agent ID, or 0 when no unambiguous existing agent resolves.
 */
function datamachine_resolve_existing_agent_id( int $user_id ): int {
	$user_id = absint( $user_id );
	if ( $user_id <= 0 ) {
		return 0;
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$owned       = $agents_repo->get_all_by_owner_id( $user_id );
	$active_slug = sanitize_title( (string) get_user_meta( $user_id, \DataMachine\Core\Agents\AgentBundler::ACTIVE_AGENT_META_KEY, true ) );
	if ( '' !== $active_slug ) {
		foreach ( $owned as $agent ) {
			if ( (string) $agent['agent_slug'] === $active_slug ) {
				return (int) $agent['agent_id'];
			}
		}
	}

	return 1 === count( $owned ) ? (int) $owned[0]['agent_id'] : 0;
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
 * @since 0.173.0 Fails closed when no explicit or unambiguous existing agent resolves.
 *
 * @return array{user_id:int,agent_id:int,triggering_user_id:int} Acting user id
 *         (the default agent owner), its agent id, and the original triggering
 *         user id. agent_id is 0 when the install has no explicit or unambiguous
 *         existing agent; user_id is 0 only when no default owner resolves.
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

	// System work must use an existing, explicit identity. Fresh installs stay
	// agentless until an agent is created or a package coordinator is installed.
	$agent_id = ( $user_id > 0 && function_exists( 'datamachine_resolve_existing_agent_id' ) )
		? datamachine_resolve_existing_agent_id( $user_id )
		: 0;

	return array(
		'user_id'            => $user_id,
		'agent_id'           => $agent_id,
		'triggering_user_id' => $triggering_user_id,
	);
}

\DataMachine\Core\Bootstrap\ActivationServiceProvider::register_new_site_hook();

// Canonical schema and site setup.
require_once __DIR__ . '/inc/setup/scaffolding.php';
require_once __DIR__ . '/inc/setup/site-md.php';
require_once __DIR__ . '/inc/setup/agents-md.php';
require_once __DIR__ . '/inc/setup/flow-schedules.php';
require_once __DIR__ . '/inc/setup/chat-sessions-network.php';
require_once __DIR__ . '/inc/setup/schema.php';
