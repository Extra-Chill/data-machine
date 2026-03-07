<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress plugin for automated content workflows with visual pipeline builder and multi-provider AI integration.
 * Version:           0.37.1
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

define( 'DATAMACHINE_VERSION', '0.37.0' );

define( 'DATAMACHINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_URL', plugin_dir_url( __FILE__ ) );

// Log directory constant (individual log files are per-agent-type)
define( 'DATAMACHINE_LOG_DIR', '/datamachine-logs' );

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
	require_once __DIR__ . '/inc/Abilities/ProcessedItemsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SettingsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/HandlerAbilities.php';
	require_once __DIR__ . '/inc/Abilities/StepTypeAbilities.php';
	require_once __DIR__ . '/inc/Abilities/LocalSearchAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SystemAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/AltTextAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageGenerationAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/MetaDescriptionAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/IndexNowAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageTemplateAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/BingWebmasterAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/PageSpeedAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentPingAbilities.php';
	require_once __DIR__ . '/inc/Abilities/TaxonomyAbilities.php';
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
		new \DataMachine\Abilities\ProcessedItemsAbilities();
		new \DataMachine\Abilities\SettingsAbilities();
		new \DataMachine\Abilities\HandlerAbilities();
		new \DataMachine\Abilities\StepTypeAbilities();
		new \DataMachine\Abilities\LocalSearchAbilities();
		new \DataMachine\Abilities\SystemAbilities();
		new \DataMachine\Engine\AI\System\SystemAgentServiceProvider();
		new \DataMachine\Abilities\Media\AltTextAbilities();
		new \DataMachine\Abilities\Media\ImageGenerationAbilities();
		new \DataMachine\Abilities\SEO\MetaDescriptionAbilities();
		new \DataMachine\Abilities\SEO\IndexNowAbilities();
		new \DataMachine\Abilities\Media\ImageTemplateAbilities();
		new \DataMachine\Abilities\Analytics\BingWebmasterAbilities();
		new \DataMachine\Abilities\Analytics\GoogleAnalyticsAbilities();
		new \DataMachine\Abilities\Analytics\GoogleSearchConsoleAbilities();
		new \DataMachine\Abilities\Analytics\PageSpeedAbilities();
		new \DataMachine\Abilities\AgentPingAbilities();
		new \DataMachine\Abilities\TaxonomyAbilities();
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

	// Ensure first-class agents table exists.
	\DataMachine\Core\Database\Agents\Agents::create_table();

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
	\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();

	// Create log directory during activation
	$upload_dir = wp_upload_dir();
	$log_dir    = $upload_dir['basedir'] . DATAMACHINE_LOG_DIR;
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	// Run layered architecture migration (idempotent).
	// Must run BEFORE scaffold so custom legacy files are copied first,
	// then scaffold fills in any remaining gaps with defaults.
	datamachine_migrate_to_layered_architecture();

	// Ensure default agent memory files exist.
	datamachine_ensure_default_memory_files();

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
	$agent_model = \DataMachine\Core\PluginSettings::getAgentModel( 'chat' );

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

/**
 * Auto-run DB migrations when code version is ahead of stored DB version.
 *
 * Deploys via rsync/homeboy don't trigger activation hooks, so new columns
 * are silently missing until someone manually reactivates. This check runs
 * on every request and calls the idempotent activation function when the
 * deployed code version exceeds the stored DB schema version.
 *
 * Pattern used by WooCommerce, bbPress, and most plugins with custom tables.
 *
 * @since 0.35.0
 */
function datamachine_maybe_run_migrations() {
	$db_version = get_option( 'datamachine_db_version', '0.0.0' );

	if ( version_compare( $db_version, DATAMACHINE_VERSION, '>=' ) ) {
		return;
	}

	datamachine_activate_for_site();
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}
add_action( 'init', 'datamachine_maybe_run_migrations', 5 );

/**
 * Build scaffold defaults for agent memory files using WordPress site data.
 *
 * Gathers site metadata, admin info, active plugins, content types, and
 * environment details to populate agent files with useful context instead
 * of empty placeholder comments.
 *
 * @since 0.32.0
 *
 * @return array<string, string> Filename => content map for SOUL.md, USER.md, MEMORY.md.
 */
function datamachine_get_scaffold_defaults(): array {
	// --- Site metadata ---
	$site_name    = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_tagline = get_bloginfo( 'description' );
	$site_url     = home_url();
	$timezone     = wp_timezone_string();

	// --- Active theme ---
	$theme      = wp_get_theme();
	$theme_name = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : 'Unknown';

	// --- Active plugins (exclude Data Machine itself) ---
	$active_plugins = get_option( 'active_plugins', array() );

	// On multisite, include network-activated plugins too.
	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_names = array();

	foreach ( $active_plugins as $plugin_file ) {
		if ( 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data    = get_plugin_data( $plugin_path, false, false );
			$plugin_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir            = dirname( $plugin_file );
			$plugin_names[] = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}
	}

	// --- Content types with counts ---
	$content_lines = array();
	$post_types    = get_post_types( array( 'public' => true ), 'objects' );

	foreach ( $post_types as $pt ) {
		$count     = wp_count_posts( $pt->name );
		$published = isset( $count->publish ) ? (int) $count->publish : 0;

		if ( $published > 0 || in_array( $pt->name, array( 'post', 'page' ), true ) ) {
			$content_lines[] = sprintf( '%s: %d published', $pt->label, $published );
		}
	}

	// --- Multisite ---
	$multisite_line = '';
	if ( is_multisite() ) {
		$site_count     = get_blog_count();
		$multisite_line = sprintf(
			"\n- **Network:** WordPress Multisite with %d site%s",
			$site_count,
			1 === $site_count ? '' : 's'
		);
	}

	// --- Admin user ---
	$admin_email = get_option( 'admin_email', '' );
	$admin_user  = $admin_email ? get_user_by( 'email', $admin_email ) : null;
	$admin_name  = $admin_user ? $admin_user->display_name : '';

	// --- Versions ---
	$wp_version  = get_bloginfo( 'version' );
	$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
	$dm_version  = defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'unknown';
	$created     = wp_date( 'Y-m-d' );

	// --- Build SOUL.md context lines ---
	$context_items   = array();
	$context_items[] = sprintf( '- **Site:** %s', $site_name );

	if ( $site_tagline ) {
		$context_items[] = sprintf( '- **Tagline:** %s', $site_tagline );
	}

	$context_items[] = sprintf( '- **URL:** %s', $site_url );
	$context_items[] = sprintf( '- **Theme:** %s', $theme_name );

	if ( $plugin_names ) {
		$context_items[] = sprintf( '- **Plugins:** %s', implode( ', ', $plugin_names ) );
	}

	if ( $content_lines ) {
		$context_items[] = sprintf( '- **Content:** %s', implode( ' · ', $content_lines ) );
	}

	$context_items[] = sprintf( '- **Timezone:** %s', $timezone );

	$soul_context = implode( "\n", $context_items ) . $multisite_line;

	// --- SOUL.md ---
	$soul = <<<MD
# Agent Soul

## Identity
You are an AI assistant managing {$site_name}.

## Voice & Tone
Write in a clear, helpful tone.

## Rules
- Follow the site's content guidelines
- Ask for clarification when instructions are ambiguous

## Context
{$soul_context}

## Continuity
SOUL.md (this file) defines who you are. USER.md profiles your human. MEMORY.md tracks persistent knowledge. Daily memory files (daily/YYYY/MM/DD.md) capture session activity — the system generates daily summaries automatically. Keep MEMORY.md lean: persistent facts only, not session logs.
MD;

	// --- USER.md ---
	$user_lines = array();
	if ( $admin_name ) {
		$user_lines[] = sprintf( '- **Name:** %s', $admin_name );
	}
	if ( $admin_email ) {
		$user_lines[] = sprintf( '- **Email:** %s', $admin_email );
	}
	$user_lines[] = '- **Role:** Site Administrator';
	$user_about   = implode( "\n", $user_lines );

	$user = <<<MD
# User Profile

## About
{$user_about}

## Preferences
<!-- Communication style, formatting preferences, things to remember -->

## Goals
<!-- What you're working toward with this site or project -->
MD;

	// --- MEMORY.md ---
	$memory = <<<MD
# Agent Memory

## State
- Data Machine v{$dm_version} activated on {$created}
- WordPress {$wp_version}, PHP {$php_version}

## Lessons Learned
<!-- What worked, what didn't, patterns to remember -->

## Context
<!-- Accumulated knowledge about the site, audience, domain -->
MD;

	return array(
		'SOUL.md'   => $soul,
		'USER.md'   => $user,
		'MEMORY.md' => $memory,
	);
}

/**
 * Build shared SITE.md scaffold content from WordPress site data.
 *
 * @since 0.36.1
 * @return string
 */
function datamachine_get_site_scaffold_content(): string {
	$site_name        = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_description = get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : '';
	$site_url         = home_url();
	$post_types       = get_post_types( array( 'public' => true ), 'names' );
	$taxonomies       = get_taxonomies( array( 'public' => true ), 'names' );
	$active_plugins   = get_option( 'active_plugins', array() );
	$theme_name       = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';

	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_names = array();
	foreach ( $active_plugins as $plugin_file ) {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data = get_plugin_data( $plugin_path, false, false );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir         = dirname( $plugin_file );
			$plugin_name = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}

		if ( 'data-machine' === strtolower( (string) $plugin_name ) || 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_names[] = $plugin_name;
	}

	$lines   = array();
	$lines[] = '# SITE';
	$lines[] = '';
	$lines[] = '## Identity';
	$lines[] = '- **name:** ' . $site_name;
	if ( ! empty( $site_description ) ) {
		$lines[] = '- **description:** ' . $site_description;
	}
	$lines[] = '- **url:** ' . $site_url;
	$lines[] = '- **theme:** ' . $theme_name;
	$lines[] = '- **multisite:** ' . ( is_multisite() ? 'true' : 'false' );
	$lines[] = '';
	$lines[] = '## Content Model';
	$lines[] = '- **post_types:** ' . implode( ', ', $post_types );
	$lines[] = '- **taxonomies:** ' . implode( ', ', $taxonomies );
	$lines[] = '';
	$lines[] = '## Active Plugins';
	if ( ! empty( $plugin_names ) ) {
		$lines[] = '- ' . implode( "\n- ", $plugin_names );
	} else {
		$lines[] = '- (none)';
	}

	return implode( "\n", $lines ) . "\n";
}

/**
 * Build shared RULES.md scaffold content.
 *
 * Creates a template for site-wide behavioral rules that apply to all agents.
 * Loaded by CoreMemoryFilesDirective at Priority 20 from the shared/ directory.
 *
 * @since 0.37.1
 * @return string
 */
function datamachine_get_rules_scaffold_content(): string {
	return <<<'MD'
# Rules

<!-- Site-wide behavioral rules that apply to all agents on this site. -->
<!-- These rules are loaded before agent-specific files (SOUL.md, MEMORY.md). -->

## Content Rules
<!-- Guidelines for content creation, quality standards, taxonomy usage -->

## Operational Rules
<!-- Constraints on agent behavior, safety guardrails, workflow requirements -->
MD;
}

/**
 * Migrate existing user_id-scoped agent files to layered architecture.
 *
 * Idempotent migration that:
 * - Creates shared/ SITE.md and RULES.md
 * - Creates agents/{slug}/ and users/{user_id}/
 * - Copies SOUL.md + MEMORY.md to agent layer
 * - Copies USER.md to user layer
 * - Creates datamachine_agents rows (one per user-owned legacy agent dir)
 * - Backfills chat_sessions.agent_id
 *
 * @since 0.36.1
 * @return void
 */
function datamachine_migrate_to_layered_architecture(): void {
	if ( get_option( 'datamachine_layered_arch_migrated', false ) ) {
		return;
	}

	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$fs                = \DataMachine\Core\FilesRepository\FilesystemHelper::get();

	if ( ! $fs ) {
		return;
	}

	$legacy_agent_base = $directory_manager->get_agent_directory(); // .../datamachine-files/agent
	$shared_dir        = $directory_manager->get_shared_directory();

	update_option(
		'datamachine_layered_arch_migration_backup',
		array(
			'legacy_agent_base' => $legacy_agent_base,
			'migrated_at'       => current_time( 'mysql', true ),
		),
		false
	);

	if ( ! is_dir( $shared_dir ) ) {
		wp_mkdir_p( $shared_dir );
	}

	$site_md = trailingslashit( $shared_dir ) . 'SITE.md';
	if ( ! file_exists( $site_md ) ) {
		$fs->put_contents( $site_md, datamachine_get_site_scaffold_content(), FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $site_md );
	}

	$rules_md = trailingslashit( $shared_dir ) . 'RULES.md';
	if ( ! file_exists( $rules_md ) ) {
		$fs->put_contents( $rules_md, datamachine_get_rules_scaffold_content(), FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $rules_md );
	}

	$index_file = trailingslashit( $shared_dir ) . 'index.php';
	if ( ! file_exists( $index_file ) ) {
		$fs->put_contents( $index_file, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $index_file );
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
	$chat_db     = new \DataMachine\Core\Database\Chat\Chat();

	$legacy_user_dirs = glob( trailingslashit( $legacy_agent_base ) . '*', GLOB_ONLYDIR );

	if ( ! empty( $legacy_user_dirs ) ) {
		foreach ( $legacy_user_dirs as $legacy_dir ) {
			$basename = basename( $legacy_dir );

			if ( ! preg_match( '/^\d+$/', $basename ) ) {
				continue;
			}

			$user_id = (int) $basename;
			if ( $user_id <= 0 ) {
				continue;
			}

			$user        = get_user_by( 'id', $user_id );
			$agent_slug  = $user ? sanitize_title( $user->user_login ) : 'user-' . $user_id;
			$agent_name  = $user ? $user->display_name : 'User ' . $user_id;
			$agent_model = \DataMachine\Core\PluginSettings::getAgentModel( 'chat' );

			$agent_id = $agents_repo->create_if_missing(
				$agent_slug,
				$agent_name,
				$user_id,
				array(
					'model' => array(
						'default' => $agent_model,
					),
				)
			);

			$agent_identity_dir = $directory_manager->get_agent_identity_directory( $agent_slug );
			$user_dir           = $directory_manager->get_user_directory( $user_id );

			if ( ! is_dir( $agent_identity_dir ) ) {
				wp_mkdir_p( $agent_identity_dir );
			}
			if ( ! is_dir( $user_dir ) ) {
				wp_mkdir_p( $user_dir );
			}

			$agent_index = trailingslashit( $agent_identity_dir ) . 'index.php';
			if ( ! file_exists( $agent_index ) ) {
				$fs->put_contents( $agent_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $agent_index );
			}

			$user_index = trailingslashit( $user_dir ) . 'index.php';
			if ( ! file_exists( $user_index ) ) {
				$fs->put_contents( $user_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $user_index );
			}

			$legacy_soul   = trailingslashit( $legacy_dir ) . 'SOUL.md';
			$legacy_memory = trailingslashit( $legacy_dir ) . 'MEMORY.md';
			$legacy_user   = trailingslashit( $legacy_dir ) . 'USER.md';
			$legacy_daily  = trailingslashit( $legacy_dir ) . 'daily';

			$new_soul   = trailingslashit( $agent_identity_dir ) . 'SOUL.md';
			$new_memory = trailingslashit( $agent_identity_dir ) . 'MEMORY.md';
			$new_daily  = trailingslashit( $agent_identity_dir ) . 'daily';
			$new_user   = trailingslashit( $user_dir ) . 'USER.md';

			if ( file_exists( $legacy_soul ) && ! file_exists( $new_soul ) ) {
				$fs->copy( $legacy_soul, $new_soul, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_soul );
			}
			if ( file_exists( $legacy_memory ) && ! file_exists( $new_memory ) ) {
				$fs->copy( $legacy_memory, $new_memory, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_memory );
			}
			if ( file_exists( $legacy_user ) && ! file_exists( $new_user ) ) {
				$fs->copy( $legacy_user, $new_user, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_user );
			} elseif ( ! file_exists( $new_user ) ) {
				$user_profile_lines   = array();
				$user_profile_lines[] = '# User Profile';
				$user_profile_lines[] = '';
				$user_profile_lines[] = '## About';
				$user_profile_lines[] = '- **Name:** ' . ( $user ? $user->display_name : 'User ' . $user_id );
				if ( $user && ! empty( $user->user_email ) ) {
					$user_profile_lines[] = '- **Email:** ' . $user->user_email;
				}
				$user_profile_lines[] = '- **User ID:** ' . $user_id;
				$user_profile_lines[] = '';
				$user_profile_lines[] = '## Preferences';
				$user_profile_lines[] = '<!-- Add user-specific preferences here -->';

				$fs->put_contents( $new_user, implode( "\n", $user_profile_lines ) . "\n", FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $new_user );
			}

			if ( is_dir( $legacy_daily ) && ! is_dir( $new_daily ) ) {
				datamachine_copy_directory_recursive( $legacy_daily, $new_daily );
			}

			// Backfill chat sessions for this user.
			global $wpdb;
			$chat_table = $chat_db->get_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET agent_id = %d WHERE user_id = %d AND (agent_id IS NULL OR agent_id = 0)',
					$chat_table,
					$agent_id,
					$user_id
				)
			);
		}
	}

	// Single-agent case: .md files live directly in agent/ with no numeric subdirs.
	// This is the most common layout for sites that never had multi-user partitioning.
	$legacy_md_files = glob( trailingslashit( $legacy_agent_base ) . '*.md' );

	if ( ! empty( $legacy_md_files ) ) {
		$default_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
		$default_user    = get_user_by( 'id', $default_user_id );
		$default_slug    = $default_user ? sanitize_title( $default_user->user_login ) : 'user-' . $default_user_id;
		$default_name    = $default_user ? $default_user->display_name : 'User ' . $default_user_id;
		$default_model   = \DataMachine\Core\PluginSettings::getAgentModel( 'chat' );

		$agents_repo->create_if_missing(
			$default_slug,
			$default_name,
			$default_user_id,
			array(
				'model' => array(
					'default' => $default_model,
				),
			)
		);

		$default_identity_dir = $directory_manager->get_agent_identity_directory( $default_slug );
		$default_user_dir     = $directory_manager->get_user_directory( $default_user_id );

		if ( ! is_dir( $default_identity_dir ) ) {
			wp_mkdir_p( $default_identity_dir );
		}
		if ( ! is_dir( $default_user_dir ) ) {
			wp_mkdir_p( $default_user_dir );
		}

		$default_agent_index = trailingslashit( $default_identity_dir ) . 'index.php';
		if ( ! file_exists( $default_agent_index ) ) {
			$fs->put_contents( $default_agent_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $default_agent_index );
		}

		$default_user_index = trailingslashit( $default_user_dir ) . 'index.php';
		if ( ! file_exists( $default_user_index ) ) {
			$fs->put_contents( $default_user_index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $default_user_index );
		}

		foreach ( $legacy_md_files as $legacy_file ) {
			$filename = basename( $legacy_file );

			// USER.md goes to user layer; everything else to agent identity.
			if ( 'USER.md' === $filename ) {
				$dest = trailingslashit( $default_user_dir ) . $filename;
			} else {
				$dest = trailingslashit( $default_identity_dir ) . $filename;
			}

			if ( ! file_exists( $dest ) ) {
				$fs->copy( $legacy_file, $dest, true, FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $dest );
			}
		}

		// Migrate daily memory directory.
		$legacy_daily = trailingslashit( $legacy_agent_base ) . 'daily';
		$new_daily    = trailingslashit( $default_identity_dir ) . 'daily';

		if ( is_dir( $legacy_daily ) && ! is_dir( $new_daily ) ) {
			datamachine_copy_directory_recursive( $legacy_daily, $new_daily );
		}
	}

	update_option( 'datamachine_layered_arch_migrated', 1, false );
}

/**
 * Copy directory contents recursively without deleting source.
 *
 * Existing destination files are preserved.
 *
 * @since 0.36.1
 * @param string $source_dir Source directory path.
 * @param string $target_dir Target directory path.
 * @return void
 */
function datamachine_copy_directory_recursive( string $source_dir, string $target_dir ): void {
	if ( ! is_dir( $source_dir ) ) {
		return;
	}

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	if ( ! is_dir( $target_dir ) ) {
		wp_mkdir_p( $target_dir );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$source_path = $item->getPathname();
		$relative    = ltrim( str_replace( $source_dir, '', $source_path ), DIRECTORY_SEPARATOR );
		$target_path = trailingslashit( $target_dir ) . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $target_path ) ) {
				wp_mkdir_p( $target_path );
			}
			continue;
		}

		if ( file_exists( $target_path ) ) {
			continue;
		}

		$parent = dirname( $target_path );
		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}

		$fs->copy( $source_path, $target_path, true, FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $target_path );
	}
}

/**
 * Create default agent memory files if they don't exist.
 *
 * Called on activation and lazily on any request that reads agent files
 * (via DirectoryManager::ensure_agent_files()). Existing files are never
 * overwritten — only missing files are recreated from scaffold defaults.
 *
 * @since 0.30.0
 */
function datamachine_ensure_default_memory_files() {
	$directory_manager = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$default_user_id   = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
	$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $default_user_id );
	$user_dir          = $directory_manager->get_user_directory( $default_user_id );

	// USER.md belongs in the user layer; everything else in the agent identity layer.
	$user_layer_files = array( 'USER.md' );

	if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
		return;
	}
	if ( ! $directory_manager->ensure_directory_exists( $user_dir ) ) {
		return;
	}

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	$defaults = datamachine_get_scaffold_defaults();

	foreach ( $defaults as $filename => $content ) {
		$target_dir = in_array( $filename, $user_layer_files, true ) ? $user_dir : $agent_dir;
		$filepath   = "{$target_dir}/{$filename}";

		if ( file_exists( $filepath ) ) {
			continue;
		}

		$fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $filepath );

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Self-healing: created missing agent file %s with scaffold defaults.', $filename ),
			array( 'filename' => $filename )
		);
	}

	// Ensure shared/ layer files exist (SITE.md, RULES.md).
	$shared_dir = $directory_manager->get_shared_directory();
	if ( $directory_manager->ensure_directory_exists( $shared_dir ) ) {
		$shared_files = array(
			'SITE.md'  => 'datamachine_get_site_scaffold_content',
			'RULES.md' => 'datamachine_get_rules_scaffold_content',
		);

		foreach ( $shared_files as $filename => $scaffold_fn ) {
			$filepath = "{$shared_dir}/{$filename}";
			if ( ! file_exists( $filepath ) ) {
				$fs->put_contents( $filepath, call_user_func( $scaffold_fn ), FS_CHMOD_FILE );
				\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $filepath );
			}
		}
	}
}

/**
 * Re-schedule all flows with non-manual scheduling on plugin activation.
 *
 * Ensures scheduled flows resume after plugin reactivation.
 */
function datamachine_activate_scheduled_flows() {
	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'datamachine_flows';

	// Check if table exists (fresh install won't have flows yet)
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$flows = $wpdb->get_results( $wpdb->prepare( 'SELECT flow_id, scheduling_config FROM %i', $table_name ), ARRAY_A );

	if ( empty( $flows ) ) {
		return;
	}

	$scheduled_count = 0;

	foreach ( $flows as $flow ) {
		$flow_id           = (int) $flow['flow_id'];
		$scheduling_config = json_decode( $flow['scheduling_config'], true );

		if ( empty( $scheduling_config ) || empty( $scheduling_config['interval'] ) ) {
			continue;
		}

		$interval = $scheduling_config['interval'];

		if ( 'manual' === $interval ) {
			continue;
		}

		// Delegate to FlowScheduling — single source of truth for scheduling
		// logic including stagger offsets, interval validation, and AS registration.
		$result = \DataMachine\Api\Flows\FlowScheduling::handle_scheduling_update(
			$flow_id,
			$scheduling_config
		);

		if ( ! is_wp_error( $result ) ) {
			++$scheduled_count;
		}
	}

	if ( $scheduled_count > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Flows re-scheduled on plugin activation',
			array(
				'scheduled_count' => $scheduled_count,
			)
		);
	}
}


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
