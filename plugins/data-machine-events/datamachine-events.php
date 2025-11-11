<?php
/**
 * Plugin Name: Data Machine Events
 * Plugin URI: https://chubes.net/datamachine-events
 * Description: WordPress events plugin with block-first architecture. Features AI-driven event creation via Data Machine integration, Event Details blocks for data storage, Calendar blocks for display, and venue taxonomy management.
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
  * Text Domain: datamachine-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: data-machine
 * Network: false
 *
 * @package DatamachineEvents
 * @author Chris Huber
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
define('DATAMACHINE_EVENTS_VERSION', '0.1.0');
define('DATAMACHINE_EVENTS_PLUGIN_FILE', __FILE__);
define('DATAMACHINE_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DATAMACHINE_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DATAMACHINE_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DATAMACHINE_EVENTS_PATH', plugin_dir_path(__FILE__));

// Load core meta storage (monitors Event Details block saves)
require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/core/meta-storage.php';

// Load REST API endpoints
require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/core/rest-api.php';


/**
 * PSR-4 Autoloader with special naming convention for Data Machine handlers
 * @param string $class_name Fully qualified class name
 */
function datamachine_events_autoloader($class_name) {
    if (strpos($class_name, 'DataMachineEvents\\') !== 0) {
        return;
    }
    $class_name = str_replace('DataMachineEvents\\', '', $class_name);
    $class_parts = explode('\\', $class_name);
    $actual_class = end($class_parts);
    $file_name = 'class-' . strtolower(str_replace('_', '-', $actual_class)) . '.php';

    $datamachine_handlers = ['DataMachineEventsSettings', 'DataMachineEventsPublisher', 'DataMachineEventsFilters', 'DataMachineEventsVenue', 'DataMachineEventsSchema'];
    if (in_array($actual_class, $datamachine_handlers)) {
        $file_name = $actual_class . '.php';
    }

    $namespace_dirs = array_slice($class_parts, 0, -1);
    $directory_path = '';
    if (!empty($namespace_dirs)) {
        $directory_path = implode('/', $namespace_dirs) . '/';
    }

    $base_directories = array('inc', 'inc/admin', 'inc/events', 'inc/core', 'inc/steps', 'inc/blocks/calendar');

    foreach ($base_directories as $base_directory) {
        if (!empty($directory_path)) {
            $file_path = DATAMACHINE_EVENTS_PLUGIN_DIR . $base_directory . '/' . $directory_path . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }

            $lowercase_directory_path = strtolower($directory_path);
            $file_path = DATAMACHINE_EVENTS_PLUGIN_DIR . $base_directory . '/' . $lowercase_directory_path . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }
        }

        $file_path = DATAMACHINE_EVENTS_PLUGIN_DIR . $base_directory . '/' . $file_name;
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }
}

spl_autoload_register('datamachine_events_autoloader');

/**
 * Main Data Machine Events plugin class
 *
 * Handles plugin initialization, component loading, and hook registration.
 *
 * @since 1.0.0
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

        // Initialize settings page early to catch admin_init hook
        if (is_admin() && class_exists('DataMachineEvents\\Admin\\Settings_Page')) {
            new \DataMachineEvents\Admin\Settings_Page();
        }
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
        }
        
        $this->init_frontend();
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
    
    private function init_frontend() {
        add_filter('template_include', array($this, 'load_event_templates'));
    }
    
    public function init_data_machine_integration() {
        if (!defined('DATAMACHINE_VERSION')) {
            return;
        }
        
        $this->load_data_machine_components();
    }
    
    private function load_data_machine_components() {
        if (file_exists(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/steps/EventImport/EventImportStep.php')) {
            require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/steps/EventImport/EventImportStep.php';
        }
        if (file_exists(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/steps/EventImport/EventImportFilters.php')) {
            require_once DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/steps/EventImport/EventImportFilters.php';
        }
        
        $this->load_event_import_handlers();
        $this->load_publish_handlers();
        
        if (class_exists('DataMachineEvents\\Steps\\Publish\\Handlers\\DataMachineEvents\\DataMachineEventsPublisher')) {
            new \DataMachineEvents\Steps\Publish\Handlers\DataMachineEvents\DataMachineEventsPublisher();
        }
    }
    
    private function load_event_import_handlers() {
        $handlers = ['ticketmaster', 'DiceFm', 'WebScraper', 'GoogleCalendar'];
        
        foreach ($handlers as $handler) {
            $handler_path = DATAMACHINE_EVENTS_PLUGIN_DIR . "inc/steps/EventImport/handlers/{$handler}/";
            if (is_dir($handler_path)) {
                foreach (glob($handler_path . '*.php') as $file) {
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
                
                if ($handler === 'WebScraper') {
                    $scrapers_path = $handler_path . 'scrapers/';
                    if (is_dir($scrapers_path)) {
                        foreach (glob($scrapers_path . '*.php') as $scraper_file) {
                            if (file_exists($scraper_file)) {
                                require_once $scraper_file;
                            }
                        }
                    }
                }
            }
        }
    }
    
    private function load_publish_handlers() {
        $datamachine_events_handler_path = DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/steps/publish/handlers/DataMachineEvents/';
        if (is_dir($datamachine_events_handler_path)) {
            foreach (glob($datamachine_events_handler_path . '*.php') as $file) {
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
        $js_file = DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/admin.js';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'datamachine-events-admin',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'datamachine-events-admin',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery', 'wp-util'),
                filemtime($js_file),
                true
            );
        }
    }
    
    
    /**
     * @param string $template Current template path
     * @return string Modified template path
     */
    public function load_event_templates($template) {
        global $post;

        if ($post && $post->post_type === 'datamachine_events') {
            if (is_single()) {
                $plugin_template = DATAMACHINE_EVENTS_PLUGIN_DIR . 'templates/single-datamachine_events.php';
                if (file_exists($plugin_template)) {
                    return $plugin_template;
                }
            }
        }

        return $template;
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
        register_block_type(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar');
        register_block_type(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/blocks/EventDetails');
        
        // Enqueue root CSS custom properties when any block is present
        add_action('wp_enqueue_scripts', array($this, 'enqueue_root_styles'));
        add_action('enqueue_block_assets', array($this, 'enqueue_root_styles'));
    }
    
    public function enqueue_root_styles() {
        if (has_block('datamachine-events/calendar') || has_block('datamachine-events/event-details') || is_singular('datamachine_events')) {
            wp_enqueue_style(
                'datamachine-events-root',
                DATAMACHINE_EVENTS_PLUGIN_URL . 'inc/blocks/root.css',
                array(),
                filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/blocks/root.css')
            );
        }

        // Enqueue Leaflet map assets for Event Details block
        if (has_block('datamachine-events/event-details') || is_singular('datamachine_events')) {
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