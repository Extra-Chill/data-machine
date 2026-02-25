<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress plugin for automated content workflows with visual pipeline builder and multi-provider AI integration.
 * Version:           0.31.0
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

define( 'DATAMACHINE_VERSION', '0.31.0' );

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
	\DataMachine\Api\Files::register();
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

	// Load abilities
	require_once __DIR__ . '/inc/Abilities/AuthAbilities.php';
	require_once __DIR__ . '/inc/Abilities/FileAbilities.php';
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
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchFilesAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchRedditAbility.php';
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
		new \DataMachine\Abilities\FileAbilities();
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
		new \DataMachine\Abilities\Fetch\FetchFilesAbility();
		new \DataMachine\Abilities\Fetch\FetchRedditAbility();
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
	new \DataMachine\Core\Steps\Fetch\Handlers\Reddit\Reddit();
	new \DataMachine\Core\Steps\Fetch\Handlers\Files\Files();

	// Update Handlers
	new \DataMachine\Core\Steps\Update\Handlers\WordPress\WordPress();
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

function datamachine_deactivate_plugin() {
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
	$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
	$db_pipelines->create_table();

	$db_flows = new \DataMachine\Core\Database\Flows\Flows();
	$db_flows->create_table();

	$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
	$db_jobs->create_table();

	$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
	$db_processed_items->create_table();

	\DataMachine\Core\Database\Chat\Chat::create_table();

	// Create log directory during activation
	$upload_dir = wp_upload_dir();
	$log_dir    = $upload_dir['basedir'] . DATAMACHINE_LOG_DIR;
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	// Ensure default agent memory files exist.
	datamachine_ensure_default_memory_files();

	// Re-schedule any flows with non-manual scheduling
	datamachine_activate_scheduled_flows();
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
	$site_name    = get_bloginfo( 'name' ) ?: 'WordPress Site';
	$site_tagline = get_bloginfo( 'description' );
	$site_url     = home_url();
	$timezone     = wp_timezone_string();

	// --- Active theme ---
	$theme      = wp_get_theme();
	$theme_name = $theme->get( 'Name' ) ?: 'Unknown';

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
			$plugin_data  = get_plugin_data( $plugin_path, false, false );
			$plugin_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir = dirname( $plugin_file );
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
	$user_lines   = array();
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
	$agent_dir         = $directory_manager->get_agent_directory();

	if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
		return;
	}

	$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	if ( ! $fs ) {
		return;
	}

	$defaults = datamachine_get_scaffold_defaults();

	foreach ( $defaults as $filename => $content ) {
		$filepath = "{$agent_dir}/{$filename}";

		if ( file_exists( $filepath ) ) {
			continue;
		}

		$fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );

		do_action(
			'datamachine_log',
			'notice',
			sprintf( 'Self-healing: created missing agent file %s with scaffold defaults.', $filename ),
			array( 'filename' => $filename )
		);
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

	if ( ! function_exists( 'datamachine_get_default_scheduler_intervals' ) ) {
		return;
	}

	$intervals = apply_filters( 'datamachine_scheduler_intervals', datamachine_get_default_scheduler_intervals() );

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

		// Clear any existing scheduled actions for this flow
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		// Handle one-time scheduling
		if ( 'one_time' === $interval ) {
			$timestamp = $scheduling_config['timestamp'] ?? null;
			if ( $timestamp && $timestamp > time() ) {
				as_schedule_single_action( $timestamp, 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
				++$scheduled_count;
			}
			continue;
		}

		// Handle recurring scheduling
		$interval_seconds = $intervals[ $interval ]['seconds'] ?? null;
		if ( ! $interval_seconds ) {
			continue;
		}

		as_schedule_recurring_action(
			time() + $interval_seconds,
			$interval_seconds,
			'datamachine_run_flow_now',
			array( $flow_id ),
			'data-machine'
		);
		++$scheduled_count;
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
