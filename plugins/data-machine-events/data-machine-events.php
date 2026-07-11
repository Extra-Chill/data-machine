<?php
/**
 * Plugin Name: Data Machine Events
 * Plugin URI: https://chubes.net
 * Description: WordPress events plugin with block-first architecture. Features AI-driven event creation via Data Machine integration, Event Details blocks for data storage, Calendar blocks for display, and venue taxonomy management.
 * Version: 0.47.4
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-events
 * Domain Path: /languages
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Network: false
 *
 * @package DataMachineEvents
 * @author Chris Huber
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
define( 'DATA_MACHINE_EVENTS_VERSION', '0.47.4' );
define( 'DATA_MACHINE_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'DATA_MACHINE_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DATA_MACHINE_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DATA_MACHINE_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DATA_MACHINE_EVENTS_PATH', plugin_dir_path( __FILE__ ) );

if ( ! function_exists( 'data_machine_events_sanitize_query_params' ) ) {
	/**
	 * Recursively sanitize query parameters while preserving nested structure
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	function data_machine_events_sanitize_query_params( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'data_machine_events_sanitize_query_params', $value );
		}

		return is_scalar( $value ) ? sanitize_text_field( $value ) : $value;
	}
}

// Public integration API — stable function surface for downstream plugins/themes.
// See docs/integration-api.md.
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/public-api.php';

// Add-to-Calendar button + dropdown for single-event pages (issue #312).
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventDetails/add-to-calendar-button.php';

// Load event dates sync (monitors Event Details block saves → datamachine_event_dates table).
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/event-dates-sync.php';
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/EventDatesTable.php';

// Global alias — event-dates-sync.php is namespaced so this makes the public API accessible globally.
if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
	/**
	 * Get event dates from the dedicated event_dates table.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Object with start_datetime and end_datetime, or null.
	 */
	function datamachine_get_event_dates( int $post_id ): ?object {
		return \DataMachineEvents\Core\EventDatesTable::get( $post_id );
	}
}

if ( ! function_exists( 'datamachine_get_event_timing' ) ) {
	/**
	 * Get event timing state (upcoming, ongoing, or past).
	 *
	 * @param int $post_id Event post ID.
	 * @return string 'upcoming' | 'ongoing' | 'past'
	 */
	function datamachine_get_event_timing( int $post_id ): string {
		return \DataMachineEvents\Core\datamachine_get_event_timing( $post_id );
	}
}

// Load performance optimizations (transient-cached last-modified queries).
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/performance.php';
\DataMachineEvents\Core\cache_last_post_time();

// Load retention policy overrides (tightens Data Machine core defaults for this site's volume).
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/retention.php';

// Short-circuit AI web_fetch requests to bot-blocked ticketing domains before they
// burn a billed model turn returning HTTP 403 (Ticketmaster, TicketWeb, AXS, etc.).
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/web-fetch-guard.php';
\DataMachineEvents\Core\register_web_fetch_guard();

// Strip generic content-writing tools from events AI steps so the model can only
// publish through the adjacent `upsert_event` handler (see issue #412). Hooked
// unconditionally so it is registered before any pipeline AI step resolves; the
// `datamachine_resolved_tools` filter never fires when Data Machine is inactive.
require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/event-tool-guard.php';
\DataMachineEvents\Core\register_event_tool_guard();

	// Load REST API routes (modular)
if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Routes.php' ) ) {
	require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Routes.php';
}

/*
|--------------------------------------------------------------------------
| AGENTS.md — composable file section registration
|--------------------------------------------------------------------------
| Registers the Data Machine Events CLI section in the AGENTS.md composable
| file so external agent runtimes discover this (largest extension) CLI
| surface automatically. Runs outside the WP_CLI guard below because compose
| and auto-regeneration fire in non-CLI (web/cron) contexts where the
| WP-CLI runner / PSR-4 autoloader are not loaded — the section generator
| resolves command class files from disk and reflects over them.
*/
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( '\DataMachine\Engine\AI\SectionRegistry' ) ) {
		return;
	}

	require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/CommandRegistry.php';
	require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/AgentsMdSection.php';

	$wp = 'wp --allow-root --path=' . ABSPATH;

	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'data-machine-events', 55, function () use ( $wp ) {
		return \DataMachineEvents\Cli\AgentsMdSection::render( $wp );
	}, array(
		'label'       => 'Data Machine Events CLI',
		'description' => 'Event + venue data-quality and maintenance WP-CLI commands.',
		'freshness'   => 'generated',
	) );
}, 22 );

// WP-CLI commands — registered from the single-source-of-truth CommandRegistry
// map, which also drives the AGENTS.md section generator above so the
// documented surface can never drift from what is actually registered here.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/CommandRegistry.php';

	foreach ( \DataMachineEvents\Cli\CommandRegistry::map() as $command => $entry ) {
		if ( isset( $entry['file'] ) && is_readable( $entry['file'] ) ) {
			require_once $entry['file'];
		}
		if ( isset( $entry['class'] ) && class_exists( $entry['class'] ) ) {
			\WP_CLI::add_command( $command, $entry['class'] );
		}
	}
}

/**
 * Main Data Machine Events plugin class
 *
 * Handles plugin initialization, component loading, and hook registration.
 *
 * @since 0.1.0
 */
class DATAMACHINE_Events {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	public function init() {
		$this->init_hooks();
		$this->register_post_types();
		add_action( 'init', array( $this, 'register_taxonomies' ), 20 );
		add_action( 'init', array( $this, 'register_blocks' ), 15 );

		add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
		add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed_block_types' ), 10, 2 );

		if ( is_admin() ) {
			$this->init_admin();

			// Instantiate Settings_Page to register its hooks
			if ( class_exists( 'DataMachineEvents\\Admin\\Settings_Page' ) ) {
				new \DataMachineEvents\Admin\Settings_Page();
			}

		}

		add_action( 'init', array( $this, 'init_data_machine_integration' ), 25 );

		// Fire the public integration loaded action. Consumers gate filter
		// registration / rendering on `did_action('data_machine_events_loaded')`
		// instead of class_exists() against internal classes.
		// See inc/public-api.php and docs/integration-api.md.
		add_action( 'init', array( $this, 'fire_loaded_action' ), 30 );

		// Initialize admin bar for all logged-in users
		if ( class_exists( 'DataMachineEvents\\Admin\\Admin_Bar' ) ) {
			new \DataMachineEvents\Admin\Admin_Bar();
		}
	}

	/**
	 * Fire the public `data_machine_events_loaded` action.
	 *
	 * Hooked at init priority 30 so it runs after post types (priority 0),
	 * blocks (priority 15), taxonomies (priority 20), and the Data Machine
	 * integration (priority 25). Consumers should hook their own filter
	 * registrations on this action — or gate them on
	 * `did_action('data_machine_events_loaded')` — rather than checking for
	 * the existence of internal classes.
	 *
	 * @since 0.32.0
	 */
	public function fire_loaded_action(): void {
		do_action( 'data_machine_events_loaded' );
	}

	private function init_hooks() {
		register_activation_hook( DATA_MACHINE_EVENTS_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( DATA_MACHINE_EVENTS_PLUGIN_FILE, array( $this, 'deactivate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	private function init_admin() {
		// Admin components are bootstrapped individually where required.
	}

	public function init_data_machine_integration() {
		if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
			return;
		}

		$this->load_data_machine_components();
	}

	private function load_data_machine_components() {
		// Load step type - self-registers via constructor using StepTypeRegistrationTrait
		new \DataMachineEvents\Steps\EventImport\EventImportStep();

		// Load EventImportFilters for admin asset enqueuing
		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/EventImport/EventImportFilters.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/EventImport/EventImportFilters.php';
		}

		$this->load_event_import_handlers();
		$this->load_upsert_handlers();

		// Instantiate EventUpsert handler
		if ( class_exists( 'DataMachineEvents\\Steps\\Upsert\\Events\\EventUpsert' ) ) {
			new \DataMachineEvents\Steps\Upsert\Events\EventUpsert();
		}

		// Register event dedup strategy with DM core's duplicate detection system
		// and identity writer to keep the PostIdentityIndex in sync.
		if ( class_exists( 'DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {
			\DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::register();
			\DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter::register();
			\DataMachineEvents\Core\DuplicateDetection\PreAIEventDedupGate::register();
		}

		// Notify submitters when their submitted events are published.
		\DataMachineEvents\Core\SubmissionNotification::register();

		// Register the event OG card template with Data Machine's image
		// template registry. Consumers (e.g. extrachill-multisite) trigger
		// the actual rendering via datamachine/render-image-template — this
		// plugin only owns the layout, not the orchestration.
		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Templates/EventOgCardTemplate.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Templates/EventOgCardTemplate.php';
			add_filter(
				'datamachine/image_generation/templates',
				function ( array $templates ): array {
					$templates['event_og_card'] = \DataMachineEvents\Templates\EventOgCardTemplate::class;
					return $templates;
				}
			);
		}

		// Register ability categories first — must happen before any ability registration.
		require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/AbilityCategories.php';
		\DataMachineEvents\Abilities\AbilityCategories::ensure_registered();

		// Shared permission callbacks. Must load before any ability that
		// references AbilityPermissions::canWrite()/canRead().
		require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/AbilityPermissions.php';

		// Load chat tools - self-register via ToolRegistrationTrait
		new \DataMachineEvents\Api\Chat\Tools\VenueHealthCheck();
		new \DataMachineEvents\Api\Chat\Tools\UpdateVenue();
		new \DataMachineEvents\Api\Chat\Tools\EventHealthCheck();
		new \DataMachineEvents\Api\Chat\Tools\EventQualityAudit();
		new \DataMachineEvents\Api\Chat\Tools\UpdateEvent();
		new \DataMachineEvents\Api\Chat\Tools\MoveEvent();
		new \DataMachineEvents\Api\Chat\Tools\DeleteEvent();
		new \DataMachineEvents\Api\Chat\Tools\GetVenueEvents();
		new \DataMachineEvents\Api\Chat\Tools\FindBrokenTimezoneEvents();
		new \DataMachineEvents\Api\Chat\Tools\FixEventTimezone();
		new \DataMachineEvents\Api\Chat\Tools\TestEventScraper();

		// Load abilities - self-register ability + tool
		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventScraperTest.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventScraperTest.php';
			new \DataMachineEvents\Abilities\EventScraperTest();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TimezoneAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TimezoneAbilities.php';
			new \DataMachineEvents\Abilities\TimezoneAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventQueryAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventQueryAbilities.php';
			new \DataMachineEvents\Abilities\EventQueryAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventHealthAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventHealthAbilities.php';
			new \DataMachineEvents\Abilities\EventHealthAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventUpdateAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventUpdateAbilities.php';
			new \DataMachineEvents\Abilities\EventUpdateAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MoveEventAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MoveEventAbilities.php';
			new \DataMachineEvents\Abilities\MoveEventAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DeleteEventAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DeleteEventAbilities.php';
			new \DataMachineEvents\Abilities\DeleteEventAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/BatchTimeFixAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/BatchTimeFixAbilities.php';
			new \DataMachineEvents\Abilities\BatchTimeFixAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EncodingFixAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EncodingFixAbilities.php';
			new \DataMachineEvents\Abilities\EncodingFixAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueAbilities.php';
			new \DataMachineEvents\Abilities\VenueAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueMapAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueMapAbilities.php';
			new \DataMachineEvents\Abilities\VenueMapAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/CalendarAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/CalendarAbilities.php';
			new \DataMachineEvents\Abilities\CalendarAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TicketUrlResyncAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TicketUrlResyncAbilities.php';
			new \DataMachineEvents\Abilities\TicketUrlResyncAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TicketmasterTest.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/TicketmasterTest.php';
			new \DataMachineEvents\Abilities\TicketmasterTest();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DiceFmTest.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DiceFmTest.php';
			new \DataMachineEvents\Abilities\DiceFmTest();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/GeocodingAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/GeocodingAbilities.php';
			new \DataMachineEvents\Abilities\GeocodingAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueStatsAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueStatsAbilities.php';
			new \DataMachineEvents\Abilities\VenueStatsAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/FilterAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/FilterAbilities.php';
			new \DataMachineEvents\Abilities\FilterAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventQualityAuditAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventQualityAuditAbilities.php';
			new \DataMachineEvents\Abilities\EventQualityAuditAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/SettingsAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/SettingsAbilities.php';
			new \DataMachineEvents\Abilities\SettingsAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DuplicateDetectionAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/DuplicateDetectionAbilities.php';
			new \DataMachineEvents\Abilities\DuplicateDetectionAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/UpcomingCountAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/UpcomingCountAbilities.php';
			new \DataMachineEvents\Abilities\UpcomingCountAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventDateQueryAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventDateQueryAbilities.php';
			new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventsByTermAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventsByTermAbilities.php';
			new \DataMachineEvents\Abilities\EventsByTermAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergedBillDetectAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergedBillDetectAbilities.php';
			new \DataMachineEvents\Abilities\MergedBillDetectAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergeEventPostsAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergeEventPostsAbilities.php';
			new \DataMachineEvents\Abilities\MergeEventPostsAbilities();
		}

		if ( file_exists( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergedBillDecideAbilities.php' ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Abilities/MergedBillDecideAbilities.php';
			new \DataMachineEvents\Abilities\MergedBillDecideAbilities();
		}

		// Chat tools for the merged-bill agent decision step (issue #256).
		if ( class_exists( 'DataMachineEvents\\Api\\Chat\\Tools\\MergedBillInspect' ) ) {
			new \DataMachineEvents\Api\Chat\Tools\MergedBillInspect();
		}
		if ( class_exists( 'DataMachineEvents\\Api\\Chat\\Tools\\MergedBillDecide' ) ) {
			new \DataMachineEvents\Api\Chat\Tools\MergedBillDecide();
		}

		// Recurring resolver hook — drains the merged-bill candidate queue
		// by invoking the chat agent. Registered at init so the hook fires
		// once Action Scheduler is ready.
		if ( class_exists( 'DataMachineEvents\\Steps\\MergedBills\\MergedBillResolverFlow' ) ) {
			\DataMachineEvents\Steps\MergedBills\MergedBillResolverFlow::register();
		}

		$this->registerSystemHealthChecks();
	}

	/**
	 * Register event extension health checks with the unified system health check.
	 *
	 * @since 0.10.10
	 */
	private function registerSystemHealthChecks(): void {
		add_filter(
			'datamachine_system_health_checks',
			function ( $checks ) {
				$checks['events'] = array(
					'label'    => __( 'Event Health', 'data-machine-events' ),
					'callback' => function ( $options ) {
						$abilities = new \DataMachineEvents\Abilities\EventHealthAbilities();
						return $abilities->executeHealthCheck( $options );
					},
					'default'  => true,
				);

				$checks['venues'] = array(
					'label'    => __( 'Venue Health', 'data-machine-events' ),
					'callback' => function ( $options ) {
						$abilities = new \DataMachineEvents\Abilities\VenueAbilities();
						return $abilities->executeHealthCheck( $options );
					},
					'default'  => true,
				);

				$checks['handlers'] = array(
					'label'    => __( 'Handler Test', 'data-machine-events' ),
					'callback' => function ( $options ) {
						$url = $options['url'] ?? '';
						if ( empty( $url ) ) {
							return array( 'error' => 'URL required for handler testing' );
						}
						$abilities = new \DataMachineEvents\Abilities\EventScraperTest();
						return $abilities->test( $url );
					},
					'default'  => false,
				);

				return $checks;
			}
		);
	}

	private function load_event_import_handlers() {
		$handlers = array(
			'DataMachineEvents\\Steps\\EventImport\\Handlers\\Ticketmaster\\Ticketmaster',
			'DataMachineEvents\\Steps\\EventImport\\Handlers\\DiceFm\\DiceFm',
			'DataMachineEvents\\Steps\\EventImport\\Handlers\\WebScraper\\UniversalWebScraper',
			'DataMachineEvents\\Steps\\EventImport\\Handlers\\EventFlyer\\EventFlyer',
			'DataMachineEvents\\Steps\\EventImport\\Handlers\\SingleRecurring\\SingleRecurring',
		);

		foreach ( $handlers as $handler_class ) {
			if ( class_exists( $handler_class ) ) {
				new $handler_class();
			}
		}
	}

	private function load_upsert_handlers() {
		$upsert_handler_path = DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/Upsert/Events/';
		if ( is_dir( $upsert_handler_path ) ) {
			// Load Filters
			foreach ( glob( $upsert_handler_path . '*Filters.php' ) as $file ) {
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'data-machine-events',
			false,
			dirname( DATA_MACHINE_EVENTS_PLUGIN_BASENAME ) . '/languages'
		);
	}


	/**
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'data-machine-events' ) === false ) {
			return;
		}

		$css_file = DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/css/admin.css';

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'data-machine-events-admin',
				DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				filemtime( $css_file )
			);
		}
	}

	public function activate() {
		\DataMachineEvents\Core\EventDatesTable::create_table();
		$this->register_post_types();
		$this->register_taxonomies();
		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	public function register_post_types() {
		\DataMachineEvents\Core\Event_Post_Type::register();
	}

	public function register_taxonomies() {
		\DataMachineEvents\Core\Venue_Taxonomy::register();
		\DataMachineEvents\Core\Promoter_Taxonomy::register();
	}
	/**
	 * @param array|null $allowed_block_types Current allowed block types
	 * @param WP_Block_Editor_Context $block_editor_context Block editor context
	 * @return array|null Modified allowed block types
	 */
	public function filter_allowed_block_types( $allowed_block_types, $block_editor_context ) {
		if ( ! isset( $block_editor_context->post ) || ! isset( $block_editor_context->post->post_type ) ) {
			return $allowed_block_types;
		}

		if ( ! is_array( $allowed_block_types ) ) {
			return $allowed_block_types;
		}

		$allowed_block_types[] = 'data-machine-events/event-details';
		$allowed_block_types[] = 'data-machine-events/calendar';
		$allowed_block_types[] = 'data-machine-events/events-map';

		return $allowed_block_types;
	}

	public function register_blocks() {
		// Register shared design tokens as a named style handle.
		// Each block declares this as a style dependency via block.json,
		// so WordPress auto-enqueues it whenever any block renders —
		// no has_block() checks or manual enqueuing needed.
		wp_register_style(
			'data-machine-events-root',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'inc/Blocks/root.css',
			array( 'dashicons' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/root.css' )
		);

		// Register Leaflet CDN assets for event-details block (single venue maps).
		// The events-map block bundles Leaflet via webpack and does not need these handles.
		wp_register_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		// Register venue map JS for event-details block.
		wp_register_script(
			'data-machine-events-venue-map',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-map.js',
			array( 'leaflet' ),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-map.js' ),
			true
		);

		register_block_type( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/Calendar' );
		register_block_type( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventDetails' );
		register_block_type( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventsMap' );

		// Initialize calendar cache invalidation hooks
		\DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator::init();
	}

	public function register_block_category( $block_categories, $editor_context ) {
		if ( ! empty( $editor_context->post ) ) {
			array_unshift(
				$block_categories,
				array(
					'slug'  => 'data-machine-events',
					'title' => __( 'Data Machine Events', 'data-machine-events' ),
					'icon'  => 'calendar-alt',
				)
			);
		}

		return $block_categories;
	}
}

function data_machine_events() {
	return DATAMACHINE_Events::get_instance();
}

data_machine_events();

/**
 * Generate excerpt from Event Details block for data_machine_events posts
 *
 * Extracts paragraph text from the Event Details block's inner blocks
 * when no manual excerpt is set.
 *
 * @param string $excerpt Current excerpt
 * @param WP_Post $post Post object
 * @return string Generated excerpt or original
 */
add_filter(
	'get_the_excerpt',
	function ( $excerpt, $post ) {
		if ( \DataMachineEvents\Core\Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return $excerpt;
		}

		if ( ! empty( trim( $excerpt ) ) ) {
			return $excerpt;
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' !== $block['blockName'] ) {
				continue;
			}

			$text_parts = array();
			foreach ( $block['innerBlocks'] as $inner ) {
				if ( 'core/paragraph' === $inner['blockName'] && ! empty( $inner['innerHTML'] ) ) {
					$text_parts[] = wp_strip_all_tags( $inner['innerHTML'] );
				}
			}

			if ( ! empty( $text_parts ) ) {
				$full_text = implode( ' ', $text_parts );
				return wp_trim_words( $full_text, 55, '...' );
			}
		}

		return $excerpt;
	},
	10,
	2
);
