<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress plugin for automated content workflows with visual pipeline builder and multi-provider AI integration.
 * Version:           0.43.3
 * Requires at least: 6.9
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

if ( ! datamachine_check_requirements() ) {
	return;
}

define( 'DATAMACHINE_VERSION', '0.43.3' );

define( 'DATAMACHINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

// WP-CLI integration
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/Cli/Bootstrap.php';
}

// Procedural includes and side-effect registrations (see inc/bootstrap.php).
// Namespaced classes without file-level side effects rely on Composer PSR-4.
require_once __DIR__ . '/inc/bootstrap.php';

if ( ! class_exists( 'ActionScheduler' ) ) {
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}


function datamachine_run_datamachine_plugin() {

	// Set Action Scheduler timeout to 10 minutes (600 seconds) for large tasks
	add_filter(
		'action_scheduler_timeout_period',
		function () {
			return 600;
		}
	);

	// Initialize translation readiness tracking for lazy tool resolution
	\DataMachine\Engine\AI\Tools\ToolManager::init();

	// Cache invalidation hooks for dynamic registration
	add_action(
		'datamachine_handler_registered',
		function () {
			\DataMachine\Abilities\HandlerAbilities::clearCache();
		}
	);
	add_action(
		'datamachine_step_type_registered',
		function () {
			\DataMachine\Abilities\StepTypeAbilities::clearCache();
		}
	);

	datamachine_register_utility_filters();
	datamachine_register_admin_filters();
	datamachine_register_oauth_system();
	datamachine_register_core_actions();

	// Load step types - they self-register via constructors
	datamachine_load_step_types();

	// Load and instantiate all handlers - they self-register via constructors
	datamachine_load_handlers();

	// Initialize FetchHandler to register skip_item tool for all fetch-type handlers
	\DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::init();

	// Register all tools - must happen AFTER step types and handlers are registered.
	\DataMachine\Engine\AI\Tools\ToolServiceProvider::register();

	\DataMachine\Api\Execute::register();
	\DataMachine\Api\WebhookTrigger::register();
	\DataMachine\Api\Pipelines\Pipelines::register();
	\DataMachine\Api\Pipelines\PipelineSteps::register();
	\DataMachine\Api\Pipelines\PipelineFlows::register();
	\DataMachine\Api\Flows\Flows::register();
	\DataMachine\Api\Flows\FlowSteps::register();
	\DataMachine\Api\Flows\FlowQueue::register();
	\DataMachine\Api\AgentPing::register();
	\DataMachine\Api\AgentFiles::register();
	\DataMachine\Api\FlowFiles::register();
	\DataMachine\Api\Users::register();
	\DataMachine\Api\Agents::register();
	\DataMachine\Api\Logs::register();
	\DataMachine\Api\ProcessedItems::register();
	\DataMachine\Api\Jobs::register();
	\DataMachine\Api\Settings::register();
	\DataMachine\Api\Auth::register();
	\DataMachine\Api\Chat\Chat::register();
	\DataMachine\Api\System\System::register();
	\DataMachine\Api\Handlers::register();
	\DataMachine\Api\StepTypes::register();
	\DataMachine\Api\Tools::register();
	\DataMachine\Api\Providers::register();
	\DataMachine\Api\Analytics::register();
	\DataMachine\Api\InternalLinks::register();

	// Load abilities
	require_once __DIR__ . '/inc/Abilities/AuthAbilities.php';
	require_once __DIR__ . '/inc/Abilities/File/FileConstants.php';
	require_once __DIR__ . '/inc/Abilities/File/AgentFileAbilities.php';
	require_once __DIR__ . '/inc/Abilities/File/FlowFileAbilities.php';
	require_once __DIR__ . '/inc/Abilities/FlowAbilities.php';
	require_once __DIR__ . '/inc/Abilities/FlowStepAbilities.php';
	require_once __DIR__ . '/inc/Abilities/JobAbilities.php';
	require_once __DIR__ . '/inc/Abilities/LogAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PostQueryAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PipelineAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PipelineStepAbilities.php';
	require_once __DIR__ . '/inc/Core/Similarity/SimilarityResult.php';
	require_once __DIR__ . '/inc/Core/Similarity/SimilarityEngine.php';
	require_once __DIR__ . '/inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php';
	require_once __DIR__ . '/inc/Abilities/ProcessedItemsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SettingsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/HandlerAbilities.php';
	require_once __DIR__ . '/inc/Abilities/StepTypeAbilities.php';
	require_once __DIR__ . '/inc/Abilities/LocalSearchAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SystemAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/AltTextAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageGenerationAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/MediaAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/MetaDescriptionAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/IndexNowAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageTemplateAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/BingWebmasterAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/PageSpeedAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentPingAbilities.php';
	require_once __DIR__ . '/inc/Abilities/TaxonomyAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentMemoryAbilities.php';
	require_once __DIR__ . '/inc/Abilities/DailyMemoryAbilities.php';
	require_once __DIR__ . '/inc/Abilities/WorkspaceAbilities.php';
	require_once __DIR__ . '/inc/Abilities/ChatAbilities.php';
	require_once __DIR__ . '/inc/Abilities/InternalLinkingAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Content/BlockSanitizer.php';
	require_once __DIR__ . '/inc/Abilities/Content/GetPostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Content/EditPostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Content/ReplacePostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/GitHubAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchFilesAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchRssAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchWordPressApiAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchWordPressMediaAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/GetWordPressPostAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/QueryWordPressPostsAbility.php';
	require_once __DIR__ . '/inc/Abilities/Publish/PublishWordPressAbility.php';
	require_once __DIR__ . '/inc/Abilities/Update/UpdateWordPressAbility.php';
	// Defer ability instantiation to init so translations are loaded.
	add_action( 'init', function () {
		new \DataMachine\Abilities\AuthAbilities();
		new \DataMachine\Abilities\File\AgentFileAbilities();
		new \DataMachine\Abilities\File\FlowFileAbilities();
		new \DataMachine\Abilities\FlowAbilities();
		new \DataMachine\Abilities\FlowStepAbilities();
		new \DataMachine\Abilities\JobAbilities();
		new \DataMachine\Abilities\LogAbilities();
		new \DataMachine\Abilities\PostQueryAbilities();
		new \DataMachine\Abilities\PipelineAbilities();
		new \DataMachine\Abilities\PipelineStepAbilities();
		new \DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility();
		new \DataMachine\Abilities\ProcessedItemsAbilities();
		new \DataMachine\Abilities\SettingsAbilities();
		new \DataMachine\Abilities\HandlerAbilities();
		new \DataMachine\Abilities\StepTypeAbilities();
		new \DataMachine\Abilities\LocalSearchAbilities();
		new \DataMachine\Abilities\SystemAbilities();
		new \DataMachine\Engine\AI\System\SystemAgentServiceProvider();
		new \DataMachine\Abilities\Media\AltTextAbilities();
		new \DataMachine\Abilities\Media\ImageGenerationAbilities();
		new \DataMachine\Abilities\Media\MediaAbilities();
		new \DataMachine\Abilities\SEO\MetaDescriptionAbilities();
		new \DataMachine\Abilities\SEO\IndexNowAbilities();
		new \DataMachine\Abilities\Media\ImageTemplateAbilities();
		new \DataMachine\Abilities\Analytics\BingWebmasterAbilities();
		new \DataMachine\Abilities\Analytics\GoogleAnalyticsAbilities();
		new \DataMachine\Abilities\Analytics\GoogleSearchConsoleAbilities();
		new \DataMachine\Abilities\Analytics\PageSpeedAbilities();
		new \DataMachine\Abilities\AgentPingAbilities();
		new \DataMachine\Abilities\TaxonomyAbilities();
		new \DataMachine\Abilities\AgentAbilities();
		new \DataMachine\Abilities\AgentMemoryAbilities();
		new \DataMachine\Abilities\DailyMemoryAbilities();
		new \DataMachine\Abilities\WorkspaceAbilities();
		new \DataMachine\Abilities\ChatAbilities();
		new \DataMachine\Abilities\InternalLinkingAbilities();
		new \DataMachine\Abilities\Content\GetPostBlocksAbility();
		new \DataMachine\Abilities\Content\EditPostBlocksAbility();
		new \DataMachine\Abilities\Content\ReplacePostBlocksAbility();
		new \DataMachine\Abilities\Fetch\GitHubAbilities();
		new \DataMachine\Abilities\Fetch\FetchFilesAbility();
		new \DataMachine\Abilities\Fetch\FetchRssAbility();
		new \DataMachine\Abilities\Fetch\FetchWordPressApiAbility();
		new \DataMachine\Abilities\Fetch\FetchWordPressMediaAbility();
		new \DataMachine\Abilities\Fetch\GetWordPressPostAbility();
		new \DataMachine\Abilities\Fetch\QueryWordPressPostsAbility();
		new \DataMachine\Abilities\Publish\PublishWordPressAbility();
		new \DataMachine\Abilities\Update\UpdateWordPressAbility();
	} );
}


// Plugin activation hook to initialize default settings
register_activation_hook( __FILE__, 'datamachine_activate_plugin_defaults' );
function datamachine_activate_plugin_defaults( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		datamachine_for_each_site( 'datamachine_activate_defaults_for_site' );
	} else {
		datamachine_activate_defaults_for_site();
	}
}

/**
 * Set default settings for a single site.
 */
function datamachine_activate_defaults_for_site() {
	$default_settings = array(
		'disabled_tools'              => array(), // Opt-out pattern: empty = all tools enabled
		'enabled_pages'               => array(
			'pipelines' => true,
			'jobs'      => true,
			'logs'      => true,
			'settings'  => true,
		),
		'site_context_enabled'        => true,
		'cleanup_job_data_on_failure' => true,
	);

	add_option( 'datamachine_settings', $default_settings );
}

add_action( 'plugins_loaded', 'datamachine_run_datamachine_plugin', 20 );




/**
 * Load and instantiate all step types - they self-register via constructors.
 * Uses StepTypeRegistrationTrait for standardized registration.
 */
function datamachine_load_step_types() {
	new \DataMachine\Core\Steps\Fetch\FetchStep();
	new \DataMachine\Core\Steps\Publish\PublishStep();
	new \DataMachine\Core\Steps\Update\UpdateStep();
	new \DataMachine\Core\Steps\AI\AIStep();
	new \DataMachine\Core\Steps\AgentPing\AgentPingStep();
	new \DataMachine\Core\Steps\WebhookGate\WebhookGateStep();
	new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();
}

/**
 * Load and instantiate all handlers - they self-register via constructors.
 * Clean, explicit approach using composer PSR-4 autoloading.
 */
function datamachine_load_handlers() {
	// Publish Handlers (core only - social handlers moved to data-machine-socials plugin)
	new \DataMachine\Core\Steps\Publish\Handlers\WordPress\WordPress();

	// Fetch Handlers
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPress\WordPress();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI\WordPressAPI();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressMedia\WordPressMedia();
	new \DataMachine\Core\Steps\Fetch\Handlers\Rss\Rss();
	new \DataMachine\Core\Steps\Fetch\Handlers\Files\Files();
	new \DataMachine\Core\Steps\Fetch\Handlers\GitHub\GitHub();
	new \DataMachine\Core\Steps\Fetch\Handlers\Workspace\Workspace();

	// Update Handlers
	new \DataMachine\Core\Steps\Update\Handlers\WordPress\WordPress();

	// Workspace publish handler
	new \DataMachine\Core\Steps\Publish\Handlers\Workspace\Workspace();
}

/**
 * Scan directory for PHP files and instantiate classes.
 * Classes are expected to self-register in their constructors.
 */
function datamachine_scan_and_instantiate( $directory ) {
	$files = glob( $directory . '/*.php' );

	foreach ( $files as $file ) {
		// Skip if it's a *Filters.php file (will be deleted)
		if ( strpos( basename( $file ), 'Filters.php' ) !== false ) {
			continue;
		}

		// Skip if it's a *Settings.php file
		if ( strpos( basename( $file ), 'Settings.php' ) !== false ) {
			continue;
		}

		// Include the file - classes will auto-instantiate
		include_once $file;
	}
}

function datamachine_allow_json_upload( $mimes ) {
	$mimes['json'] = 'application/json';
	return $mimes;
}
add_filter( 'upload_mimes', 'datamachine_allow_json_upload' );

add_action( 'update_option_datamachine_settings', array( \DataMachine\Core\PluginSettings::class, 'clearCache' ) );

add_action(
	'plugins_loaded',
	function () {
		\DataMachine\Core\Database\Chat\Chat::ensure_context_column();
		\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();
	},
	6
);

register_activation_hook( __FILE__, 'datamachine_activate_plugin' );
register_deactivation_hook( __FILE__, 'datamachine_deactivate_plugin' );

/**
 * Register Data Machine custom capabilities on roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_register_capabilities(): void {
	$capabilities = array(
		'datamachine_manage_agents',
		'datamachine_manage_flows',
		'datamachine_manage_settings',
		'datamachine_chat',
		'datamachine_use_tools',
		'datamachine_view_logs',
	);

	$administrator = get_role( 'administrator' );
	if ( $administrator ) {
		foreach ( $capabilities as $capability ) {
			$administrator->add_cap( $capability );
		}
	}

	$editor = get_role( 'editor' );
	if ( $editor ) {
		$editor->add_cap( 'datamachine_chat' );
		$editor->add_cap( 'datamachine_use_tools' );
		$editor->add_cap( 'datamachine_view_logs' );
	}

	$author = get_role( 'author' );
	if ( $author ) {
		$author->add_cap( 'datamachine_chat' );
		$author->add_cap( 'datamachine_use_tools' );
	}

	$subscriber = get_role( 'subscriber' );
	if ( $subscriber ) {
		$subscriber->add_cap( 'datamachine_chat' );
	}
}

/**
 * Remove Data Machine custom capabilities from roles.
 *
 * @since 0.37.0
 * @return void
 */
function datamachine_remove_capabilities(): void {
	$capabilities = array(
		'datamachine_manage_agents',
		'datamachine_manage_flows',
		'datamachine_manage_settings',
		'datamachine_chat',
		'datamachine_use_tools',
		'datamachine_view_logs',
	);

	$roles = array( 'administrator', 'editor', 'author', 'subscriber' );

	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}

		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

function datamachine_deactivate_plugin() {
	datamachine_remove_capabilities();

	// Unschedule recurring maintenance actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'datamachine_cleanup_stale_claims', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_failed_jobs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_logs', array(), 'datamachine-maintenance' );
	}
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
	if ( is_multisite() && $network_wide ) {
		datamachine_for_each_site( 'datamachine_activate_for_site' );
	} else {
		datamachine_activate_for_site();
	}
}

/**
 * Run activation tasks for a single site.
 *
 * Creates tables, log directory, default memory files, and re-schedules flows.
 * Called directly on single-site, or per-site during network activation and
 * new site creation.
 */
function datamachine_activate_for_site() {
	datamachine_register_capabilities();

	// Create logs table first — other table migrations log messages during creation.
	\DataMachine\Core\Database\Logs\LogRepository::create_table();

	// Ensure first-class agents table exists.
	\DataMachine\Core\Database\Agents\Agents::create_table();
	\DataMachine\Core\Database\Agents\AgentAccess::create_table();

	$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
	$db_pipelines->create_table();
	$db_pipelines->migrate_columns();

	$db_flows = new \DataMachine\Core\Database\Flows\Flows();
	$db_flows->create_table();
	$db_flows->migrate_columns();

	$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
	$db_jobs->create_table();

	$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
	$db_processed_items->create_table();

	\DataMachine\Core\Database\Chat\Chat::create_table();
	\DataMachine\Core\Database\Chat\Chat::ensure_context_column();
	\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();

	// Ensure default agent memory files exist.
	datamachine_ensure_default_memory_files();

	// Run layered architecture migration (idempotent).
	datamachine_migrate_to_layered_architecture();

	// Migrate flow_config handler keys from singular to plural (idempotent).
	datamachine_migrate_handler_keys_to_plural();

	// Backfill agent_id on pipelines, flows, and jobs from user_id→owner_id mapping (idempotent).
	datamachine_backfill_agent_ids();

	// Assign orphaned resources (agent_id IS NULL) to sole agent on single-agent installs (idempotent).
	datamachine_assign_orphaned_resources_to_sole_agent();

	// Clean up legacy per-agent-type log level options (idempotent).
	foreach ( array( 'pipeline', 'chat', 'system' ) as $legacy_agent_type ) {
		delete_option( "datamachine_log_level_{$legacy_agent_type}" );
	}

	// Re-schedule any flows with non-manual scheduling
	datamachine_activate_scheduled_flows();

	// Track DB schema version so deploy-time migrations auto-run.
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
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
	$agent_model = \DataMachine\Core\PluginSettings::getContextModel( 'chat' );

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
 * Run a callback for every site on the network.
 *
 * Switches to each site, runs the callback, then restores. Used by
 * activation hooks and new site hooks to ensure per-site setup.
 *
 * @param callable $callback Function to call in each site context.
 */
function datamachine_for_each_site( callable $callback ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sites as $blog_id ) {
		switch_to_blog( $blog_id );
		$callback();
		restore_current_blog();
	}
}

/**
 * Create Data Machine tables and defaults when a new site is added to the network.
 *
 * Only runs if Data Machine is network-active.
 *
 * @param WP_Site $new_site New site object.
 */
function datamachine_on_new_site( \WP_Site $new_site ) {
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( $new_site->blog_id );
	datamachine_activate_defaults_for_site();
	datamachine_activate_for_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'datamachine_on_new_site', 200 );

// Migrations, scaffolding, and activation helpers.
require_once __DIR__ . '/inc/migrations.php';


function datamachine_check_requirements() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				printf(
					esc_html( 'Data Machine requires PHP %2$s or higher. You are running PHP %1$s.' ),
					esc_html( PHP_VERSION ),
					'8.0'
				);
				echo '</p></div>';
			}
		);
		return false;
	}

	global $wp_version;
	$current_wp_version = $wp_version ?? '0.0.0';
	if ( version_compare( $current_wp_version, '6.9', '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $current_wp_version ) {
				echo '<div class="notice notice-error"><p>';
				printf(
					esc_html( 'Data Machine requires WordPress %2$s or higher. You are running WordPress %1$s.' ),
					esc_html( $current_wp_version ),
					'6.9'
				);
				echo '</p></div>';
			}
		);
		return false;
	}

	if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html( 'Data Machine: Composer dependencies are missing. Please run "composer install" or contact Chubes to report a bug.' );
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}
