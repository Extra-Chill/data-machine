<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress plugin for automated content workflows with visual pipeline builder and multi-provider AI integration.
 * Version:           0.19.0
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

define( 'DATAMACHINE_VERSION', '0.19.0' );

define( 'DATAMACHINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_URL', plugin_dir_url( __FILE__ ) );

// Log directory constant (individual log files are per-agent-type)
define( 'DATAMACHINE_LOG_DIR', '/datamachine-logs' );

require_once __DIR__ . '/vendor/autoload.php';

// WP-CLI integration
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/Cli/Bootstrap.php';
}

// Load function files that define global functions used by the plugin
require_once __DIR__ . '/inc/Engine/Filters/SchedulerIntervals.php';
require_once __DIR__ . '/inc/Engine/Filters/DataMachineFilters.php';
require_once __DIR__ . '/inc/Engine/Filters/Handlers.php';
require_once __DIR__ . '/inc/Engine/Filters/Admin.php';
require_once __DIR__ . '/inc/Engine/Logger.php';
require_once __DIR__ . '/inc/Engine/Filters/OAuth.php';
require_once __DIR__ . '/inc/Engine/Actions/DataMachineActions.php';
require_once __DIR__ . '/inc/Engine/Filters/EngineData.php';
require_once __DIR__ . '/inc/Engine/AI/ConversationManager.php';
require_once __DIR__ . '/inc/Core/Admin/Modal/ModalFilters.php';
require_once __DIR__ . '/inc/Core/Admin/AdminRootFilters.php';
require_once __DIR__ . '/inc/Core/Admin/Pages/Pipelines/PipelinesFilters.php';
require_once __DIR__ . '/inc/Core/Admin/Settings/SettingsFilters.php';
require_once __DIR__ . '/inc/Core/Admin/Pages/Logs/LogsFilters.php';
require_once __DIR__ . '/inc/Core/Admin/Pages/Jobs/JobsFilters.php';
require_once __DIR__ . '/inc/Core/WordPress/PostTrackingTrait.php';
require_once __DIR__ . '/inc/Core/Steps/StepTypeRegistrationTrait.php';
require_once __DIR__ . '/inc/Engine/AI/Tools/Global/GoogleSearch.php';
require_once __DIR__ . '/inc/Engine/AI/Tools/Global/LocalSearch.php';
require_once __DIR__ . '/inc/Engine/AI/Tools/Global/WebFetch.php';
require_once __DIR__ . '/inc/Engine/AI/Tools/Global/WordPressPostReader.php';
require_once __DIR__ . '/inc/Engine/AI/Directives/GlobalSystemPromptDirective.php';
require_once __DIR__ . '/inc/Engine/AI/Directives/SiteContext.php';
require_once __DIR__ . '/inc/Engine/AI/Directives/SiteContextDirective.php';
require_once __DIR__ . '/inc/Engine/AI/RequestBuilder.php';
require_once __DIR__ . '/inc/Api/Chat/ChatFilters.php';
require_once __DIR__ . '/inc/Api/Chat/ChatAgentDirective.php';
require_once __DIR__ . '/inc/Core/Steps/AI/Directives/PipelineCoreDirective.php';
require_once __DIR__ . '/inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php';
require_once __DIR__ . '/inc/Core/Steps/AI/Directives/PipelineContextDirective.php';
require_once __DIR__ . '/inc/Core/FilesRepository/FileCleanup.php';
require_once __DIR__ . '/inc/Api/StepTypes.php';
require_once __DIR__ . '/inc/Api/Handlers.php';
require_once __DIR__ . '/inc/Api/Providers.php';
require_once __DIR__ . '/inc/Api/Tools.php';
require_once __DIR__ . '/inc/Api/Chat/Chat.php';

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

	// Load chat tools - must happen AFTER step types and handlers are registered
	datamachine_load_chat_tools();

	\DataMachine\Api\Execute::register();
	\DataMachine\Api\Pipelines\Pipelines::register();
	\DataMachine\Api\Pipelines\PipelineSteps::register();
	\DataMachine\Api\Pipelines\PipelineFlows::register();
	\DataMachine\Api\Flows\Flows::register();
	\DataMachine\Api\Flows\FlowSteps::register();
	\DataMachine\Api\Flows\FlowQueue::register();
	\DataMachine\Api\Files::register();
	\DataMachine\Api\Users::register();
	\DataMachine\Api\Logs::register();
	\DataMachine\Api\ProcessedItems::register();
	\DataMachine\Api\Jobs::register();
	\DataMachine\Api\Settings::register();
	\DataMachine\Api\Auth::register();
	\DataMachine\Api\Chat\Chat::register();
	\DataMachine\Api\System\System::register();

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
	require_once __DIR__ . '/inc/Abilities/AgentPingAbilities.php';
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
	new \DataMachine\Abilities\AgentPingAbilities();
}


// Plugin activation hook to initialize default settings
register_activation_hook( __FILE__, 'datamachine_activate_plugin_defaults' );
function datamachine_activate_plugin_defaults() {
	$tool_manager     = new \DataMachine\Engine\AI\Tools\ToolManager();
	$opt_out_defaults = $tool_manager->get_opt_out_defaults();

	$default_settings = array(
		'enabled_tools'               => array_fill_keys( $opt_out_defaults, true ),
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
}

/**
 * Load and instantiate all handlers - they self-register via constructors.
 * Clean, explicit approach using composer PSR-4 autoloading.
 */
function datamachine_load_handlers() {
	// Publish Handlers
	new \DataMachine\Core\Steps\Publish\Handlers\WordPress\WordPress();
	new \DataMachine\Core\Steps\Publish\Handlers\Twitter\Twitter();
	new \DataMachine\Core\Steps\Publish\Handlers\Facebook\Facebook();
	new \DataMachine\Core\Steps\Publish\Handlers\GoogleSheets\GoogleSheets();
	new \DataMachine\Core\Steps\Publish\Handlers\Threads\Threads();
	new \DataMachine\Core\Steps\Publish\Handlers\Bluesky\Bluesky();

	// Fetch Handlers
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPress\WordPress();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI\WordPressAPI();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressMedia\WordPressMedia();
	new \DataMachine\Core\Steps\Fetch\Handlers\Rss\Rss();
	new \DataMachine\Core\Steps\Fetch\Handlers\GoogleSheets\GoogleSheetsFetch();
	new \DataMachine\Core\Steps\Fetch\Handlers\Reddit\Reddit();
	new \DataMachine\Core\Steps\Fetch\Handlers\Files\Files();

	// Update Handlers
	new \DataMachine\Core\Steps\Update\Handlers\WordPress\WordPress();
}

/**
 * Load chat tools - must be called AFTER step types and handlers are registered.
 * These tools build their descriptions from registered step types and handlers.
 */
function datamachine_load_chat_tools() {
	new \DataMachine\Api\Chat\Tools\ApiQuery();
	new \DataMachine\Api\Chat\Tools\CreatePipeline();
	new \DataMachine\Api\Chat\Tools\AddPipelineStep();
	new \DataMachine\Api\Chat\Tools\CreateFlow();
	new \DataMachine\Api\Chat\Tools\ConfigureFlowSteps();
	new \DataMachine\Api\Chat\Tools\RunFlow();
	new \DataMachine\Api\Chat\Tools\UpdateFlow();
	new \DataMachine\Api\Chat\Tools\ConfigurePipelineStep();
	new \DataMachine\Api\Chat\Tools\ExecuteWorkflowTool();
	new \DataMachine\Api\Chat\Tools\CopyFlow();
	new \DataMachine\Api\Chat\Tools\AuthenticateHandler();
	new \DataMachine\Api\Chat\Tools\ReadLogs();
	new \DataMachine\Api\Chat\Tools\ManageLogs();
	new \DataMachine\Api\Chat\Tools\CreateTaxonomyTerm();
	new \DataMachine\Api\Chat\Tools\SearchTaxonomyTerms();
	new \DataMachine\Api\Chat\Tools\UpdateTaxonomyTerm();
	new \DataMachine\Api\Chat\Tools\MergeTaxonomyTerms();
	new \DataMachine\Api\Chat\Tools\AssignTaxonomyTerm();
	new \DataMachine\Api\Chat\Tools\GetHandlerDefaults();
	new \DataMachine\Api\Chat\Tools\SetHandlerDefaults();
	new \DataMachine\Api\Chat\Tools\DeleteFile();
	new \DataMachine\Api\Chat\Tools\DeleteFlow();
	new \DataMachine\Api\Chat\Tools\DeletePipeline();
	new \DataMachine\Api\Chat\Tools\DeletePipelineStep();
	new \DataMachine\Api\Chat\Tools\ReorderPipelineSteps();
	new \DataMachine\Api\Chat\Tools\ListFlows();
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
 */
function datamachine_activate_plugin() {

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

	// Re-schedule any flows with non-manual scheduling
	datamachine_activate_scheduled_flows();
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
