<?php
/**
 * Plugin Name: Data Machine Events
 * Plugin URI: https://chubes.net
 * Description: WordPress events plugin with block-first architecture. Features AI-driven event creation via Data Machine integration, Event Details blocks for data storage, Calendar blocks for display, and venue taxonomy management.
 * Version: 0.8.40
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: datamachine-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Network: false
 *
 * @package DatamachineEvents
 * @author Chris Huber
 * @since 0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
define('DATAMACHINE_EVENTS_VERSION', '0.8.40');
define('DATAMACHINE_EVENTS_PLUGIN_FILE', __FILE__);
define('DATAMACHINE_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DATAMACHINE_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DATAMACHINE_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DATAMACHINE_EVENTS_PATH', plugin_dir_path(__FILE__));

if (!function_exists('datamachine_events_sanitize_query_params')) {
    /**
     * Recursively sanitize query parameters while preserving nested structure
     *
     * @param mixed $value
     * @return mixed
     */
    function datamachine_events_sanitize_query_params($value) {
        if (is_array($value)) {
            return array_map('datamachine_events_sanitize_query_params', $value);
        }

        return is_scalar($value) ? sanitize_text_field($value) : $value;
    }
}

// Load core meta storage (monitors Event Details block saves)
require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Core/meta-storage.php';

	// Load REST API routes (modular)
	if ( file_exists( DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Routes.php' ) ) {
		require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Routes.php';
	}

	// WP-CLI commands (optional)
	if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/UniversalWebScraperTestCommand.php' ) ) {
		require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/UniversalWebScraperTestCommand.php';
		\WP_CLI::add_command( 'datamachine-events test-scraper', \DataMachineEvents\Cli\UniversalWebScraperTestCommand::class );
		\WP_CLI::add_command( 'datamachine-events test-scraper-url', \DataMachineEvents\Cli\UniversalWebScraperTestCommand::class );
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
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    public function init() {
        $this->init_hooks();
        $this->register_post_types();
        add_action('init', array($this, 'register_taxonomies'), 20);
        $this->register_blocks();
        
        add_filter('block_categories_all', array($this, 'register_block_category'), 10, 2);
        add_filter('allowed_block_types_all', array($this, 'filter_allowed_block_types'), 10, 2);
        
        if (is_admin()) {
            $this->init_admin();
            
            // Instantiate Settings_Page to register its hooks
            if (class_exists('DataMachineEvents\\Admin\\Settings_Page')) {
                new \DataMachineEvents\Admin\Settings_Page();
            }
        }
        
    add_action('init', array($this, 'init_data_machine_integration'), 25);
 
        // Initialize admin bar for all logged-in users
        if (class_exists('DataMachineEvents\\Admin\\Admin_Bar')) {
            new \DataMachineEvents\Admin\Admin_Bar();
        }
    }
    
    private function init_hooks() {
        register_activation_hook(DATAMACHINE_EVENTS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(DATAMACHINE_EVENTS_PLUGIN_FILE, array($this, 'deactivate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    private function init_admin() {
        // Admin components are bootstrapped individually where required.
    }
    
    public function init_data_machine_integration() {
        if (!defined('DATAMACHINE_VERSION')) {
            return;
        }
        
        $this->load_data_machine_components();
    }
    
    private function load_data_machine_components() {
        // Load step type - self-registers via constructor using StepTypeRegistrationTrait
        new \DataMachineEvents\Steps\EventImport\EventImportStep();

        // Load EventImportFilters for admin asset enqueuing
        if (file_exists(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/EventImport/EventImportFilters.php')) {
            require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/EventImport/EventImportFilters.php';
        }

        $this->load_event_import_handlers();
        $this->load_upsert_handlers();

        // Instantiate EventUpsert handler
        if (class_exists('DataMachineEvents\\Steps\\Upsert\\Events\\EventUpsert')) {
            new \DataMachineEvents\Steps\Upsert\Events\EventUpsert();
        }

        // Load chat tools - self-register via ToolRegistrationTrait
        new \DataMachineEvents\Api\Chat\Tools\VenueHealthCheck();
        new \DataMachineEvents\Api\Chat\Tools\UpdateVenue();
        new \DataMachineEvents\Api\Chat\Tools\GetVenueEvents();
        new \DataMachineEvents\Api\Chat\Tools\EventHealthCheck();
        new \DataMachineEvents\Api\Chat\Tools\UpdateEvent();
    }
    
    private function load_event_import_handlers() {
        $handlers = [
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\Ticketmaster\\Ticketmaster',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\DiceFm\\DiceFm',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\IcsCalendar\\IcsCalendar',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\WebScraper\\UniversalWebScraper',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\EventFlyer\\EventFlyer',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\Eventbrite\\Eventbrite',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\DoStuffMediaApi\\DoStuffMediaApi',
            'DataMachineEvents\\Steps\\EventImport\\Handlers\\SingleRecurring\\SingleRecurring',
        ];
        
        foreach ($handlers as $handler_class) {
            if (class_exists($handler_class)) {
                new $handler_class();
            }
        }
    }
    
    private function load_upsert_handlers() {
        $upsert_handler_path = DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Steps/Upsert/Events/';
        if (is_dir($upsert_handler_path)) {
            // Load Filters
            foreach (glob($upsert_handler_path . '*Filters.php') as $file) {
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'datamachine-events',
            false,
            dirname(DATAMACHINE_EVENTS_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    
    /**
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'datamachine-events') === false) {
            return;
        }

        $css_file = DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/css/admin.css';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'datamachine-events-admin',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }

    }

    public function activate() {
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
    public function filter_allowed_block_types($allowed_block_types, $block_editor_context) {
        if (!isset($block_editor_context->post) || !isset($block_editor_context->post->post_type)) {
            return $allowed_block_types;
        }

        if (!is_array($allowed_block_types)) {
            return $allowed_block_types;
        }

        $allowed_block_types[] = 'datamachine-events/event-details';
        $allowed_block_types[] = 'datamachine-events/calendar';

        return $allowed_block_types;
    }

    public function register_blocks() {
        register_block_type(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/Calendar');
        register_block_type(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventDetails');

        // Enqueue root CSS custom properties when any block is present
        add_action('wp_enqueue_scripts', array($this, 'enqueue_root_styles'));
        add_action('enqueue_block_assets', array($this, 'enqueue_root_styles'));
    }
    
    public function enqueue_root_styles() {
        if (has_block('datamachine-events/calendar') || has_block('datamachine-events/event-details') || is_singular(\DataMachineEvents\Core\Event_Post_Type::POST_TYPE)) {
            wp_enqueue_style(
                'datamachine-events-root',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'inc/Blocks/root.css',
                array(),
                filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/root.css')
            );
            
            wp_enqueue_style('dashicons');
        }

        // Enqueue Leaflet map assets for Event Details block
        if (has_block('datamachine-events/event-details') || is_singular(\DataMachineEvents\Core\Event_Post_Type::POST_TYPE)) {
            // Leaflet CSS
            wp_enqueue_style(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                array(),
                '1.9.4'
            );

            // Leaflet JS
            wp_enqueue_script(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                array(),
                '1.9.4',
                true
            );

            // Custom venue map initialization
            wp_enqueue_script(
                'datamachine-events-venue-map',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-map.js',
                array('leaflet'),
                filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-map.js'),
                true
            );
        }
    }
    
    public function register_block_category($block_categories, $editor_context) {
        if (!empty($editor_context->post)) {
            array_unshift($block_categories, array(
                'slug'  => 'datamachine-events',
                'title' => __('Data Machine Events', 'datamachine-events'),
                'icon'  => 'calendar-alt',
            ));
        }

        return $block_categories;
    }
    

}

function datamachine_events() {
    return DATAMACHINE_Events::get_instance();
}

datamachine_events();

/**
 * Generate excerpt from Event Details block for datamachine_events posts
 *
 * Extracts paragraph text from the Event Details block's inner blocks
 * when no manual excerpt is set.
 *
 * @param string $excerpt Current excerpt
 * @param WP_Post $post Post object
 * @return string Generated excerpt or original
 */
add_filter('get_the_excerpt', function ($excerpt, $post) {
    if ($post->post_type !== 'datamachine_events') {
        return $excerpt;
    }

    if (!empty(trim($excerpt))) {
        return $excerpt;
    }

    $blocks = parse_blocks($post->post_content);

    foreach ($blocks as $block) {
        if ($block['blockName'] !== 'datamachine-events/event-details') {
            continue;
        }

        $text_parts = [];
        foreach ($block['innerBlocks'] as $inner) {
            if ($inner['blockName'] === 'core/paragraph' && !empty($inner['innerHTML'])) {
                $text_parts[] = wp_strip_all_tags($inner['innerHTML']);
            }
        }

        if (!empty($text_parts)) {
            $full_text = implode(' ', $text_parts);
            return wp_trim_words($full_text, 55, '...');
        }
    }

    return $excerpt;
}, 10, 2);
