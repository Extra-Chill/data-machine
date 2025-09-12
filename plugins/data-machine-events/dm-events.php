<?php
/**
 * Plugin Name: Data Machine Events
 * Plugin URI: https://chubes.net/dm-events
 * Description: WordPress events plugin with block-first architecture. Features AI-driven event creation via Data Machine integration, Event Details blocks for data storage, Calendar blocks for display, and venue taxonomy management.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dm-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * Network: false
 *
 * @package DmEvents
 * @author Chris Huber
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
define('DM_EVENTS_VERSION', '1.0.0');
define('DM_EVENTS_PLUGIN_FILE', __FILE__);
define('DM_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DM_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DM_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DM_EVENTS_PATH', plugin_dir_path(__FILE__));


/**
 * PSR-4 Autoloader with special naming for Data Machine handlers
 *
 * @param string $class_name Fully qualified class name
 */
function dm_events_autoloader($class_name) {
    if (strpos($class_name, 'DmEvents\\') !== 0) {
        return;
    }
    $class_name = str_replace('DmEvents\\', '', $class_name);
    $class_parts = explode('\\', $class_name);
    $actual_class = end($class_parts);
    $file_name = 'class-' . strtolower(str_replace('_', '-', $actual_class)) . '.php';
    
    $dm_handlers = ['DmEventsSettings', 'DmEventsPublisher', 'DmEventsFilters', 'DmEventsVenue', 'DmEventsSchema'];
    if (in_array($actual_class, $dm_handlers)) {
        $file_name = $actual_class . '.php';
    }
    
    $namespace_dirs = array_slice($class_parts, 0, -1);
    $directory_path = '';
    if (!empty($namespace_dirs)) {
        $directory_path = strtolower(implode('/', $namespace_dirs)) . '/';
    }
    
    $base_directories = array('inc', 'inc/admin', 'inc/events', 'inc/core', 'inc/steps');
    
    foreach ($base_directories as $base_directory) {
        if (!empty($directory_path)) {
            $file_path = DM_EVENTS_PLUGIN_DIR . $base_directory . '/' . $directory_path . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }
        }
        
        $file_path = DM_EVENTS_PLUGIN_DIR . $base_directory . '/' . $file_name;
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }
    
}

spl_autoload_register('dm_events_autoloader');

/**
 * Main Data Machine Events plugin class
 *
 * Handles plugin initialization, component loading, and hook registration.
 *
 * @since 1.0.0
 */
class DM_Events {
    
    /** @var DM_Events */
    private static $instance = null;
    
    /** @return DM_Events */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /** Initialize the plugin */
    private function __construct() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /** Initialize core plugin components and hooks */
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
        $this->init_status_detection();
    }
    
    /** Register activation, deactivation, and admin asset hooks */
    private function init_hooks() {
        register_activation_hook(DM_EVENTS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(DM_EVENTS_PLUGIN_FILE, array($this, 'deactivate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /** Load admin classes when in WordPress admin */
    private function init_admin() {
        if (class_exists('DmEvents\\Admin')) {
            new \DmEvents\Admin();
        }
        
        if (class_exists('DmEvents\\Admin\\Settings_Page')) {
            new \DmEvents\Admin\Settings_Page();
        }
    }
    
    /** Setup frontend template loading */
    private function init_frontend() {
        add_filter('template_include', array($this, 'load_event_templates'));
    }
    
    /** Load status detection for Data Machine integration monitoring */
    private function init_status_detection() {
        if (!function_exists('apply_filters') || !has_filter('dm_detect_status')) {
            return;
        }
        
        if (class_exists('DmEvents\\Admin\\Status_Detection')) {
            new \DmEvents\Admin\Status_Detection();
        }
    }
    
    /** Load Data Machine integration components when plugin is available */
    public function init_data_machine_integration() {
        if (!defined('DATA_MACHINE_VERSION')) {
            return;
        }
        
        $this->load_data_machine_components();
    }
    
    /** Load event import step, filters, and handlers */
    private function load_data_machine_components() {
        if (file_exists(DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php')) {
            require_once DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php';
        }
        if (file_exists(DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php')) {
            require_once DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php';
        }
        
        $this->load_event_import_handlers();
        $this->load_publish_handlers();
        
        if (class_exists('DmEvents\\Steps\\Publish\\Handlers\\DmEvents\\DmEventsPublisher')) {
            new \DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsPublisher();
        }
    }
    
    /** Load all event import handlers from their directories */
    private function load_event_import_handlers() {
        $handlers = ['ticketmaster', 'dice-fm', 'web-scraper'];
        
        foreach ($handlers as $handler) {
            $handler_path = DM_EVENTS_PLUGIN_DIR . "inc/steps/event-import/handlers/{$handler}/";
            if (is_dir($handler_path)) {
                foreach (glob($handler_path . '*.php') as $file) {
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
                
                if ($handler === 'web-scraper') {
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
    
    /** Load Data Machine Events publisher and related classes */
    private function load_publish_handlers() {
        $dm_events_handler_path = DM_EVENTS_PLUGIN_DIR . 'inc/steps/publish/handlers/dm-events/';
        if (is_dir($dm_events_handler_path)) {
            foreach (glob($dm_events_handler_path . '*.php') as $file) {
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        }
    }
    
    
    /** Load plugin textdomain for internationalization */
    public function load_textdomain() {
        load_plugin_textdomain(
            'dm-events',
            false,
            dirname(DM_EVENTS_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    
    /** Enqueue admin CSS and JavaScript with cache busting */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'dm-events') === false) {
            return;
        }
        
        $css_file = DM_EVENTS_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file = DM_EVENTS_PLUGIN_DIR . 'assets/js/admin.js';
        
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'dm-events-admin',
                DM_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }
        
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'dm-events-admin',
                DM_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery', 'wp-util'),
                filemtime($js_file),
                true
            );
        }
    }
    
    
    /** Load single event template for dm_events post type */
    public function load_event_templates($template) {
        global $post;
        
        if ($post && $post->post_type === 'dm_events') {
            if (is_single()) {
                $plugin_template = DM_EVENTS_PLUGIN_DIR . 'templates/single-dm_events.php';
                if (file_exists($plugin_template)) {
                    return $plugin_template;
                }
            }
        }
        
        return $template;
    }
    
    /** Register post types and flush rewrite rules on activation */
    public function activate() {
        $this->register_post_types();
        $this->register_taxonomies();
        flush_rewrite_rules();
    }
    
    /** Flush rewrite rules on deactivation */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    
    /** Register dm_events post type */
    public function register_post_types() {
        \DmEvents\Core\Event_Post_Type::register();
    }
    
    
    /** Register venue taxonomy */
    public function register_taxonomies() {
        \DmEvents\Core\Venue_Taxonomy::register();
    }
    /**
     * Filter allowed block types based on post type
     *
     * @param array|bool $allowed_block_types Array of block type slugs, or boolean to enable/disable all
     * @param WP_Block_Editor_Context $block_editor_context The current block editor context
     * @return array|bool
     */
    public function filter_allowed_block_types($allowed_block_types, $block_editor_context) {
        if (!isset($block_editor_context->post) || !isset($block_editor_context->post->post_type)) {
            return $allowed_block_types;
        }
        
        $post_type = $block_editor_context->post->post_type;
        
        if (!is_array($allowed_block_types)) {
            return $allowed_block_types;
        }
        
        // Allow dm-events blocks on all post types
        $allowed_block_types[] = 'dm-events/event-details';
        $allowed_block_types[] = 'dm-events/calendar';
        
        return $allowed_block_types;
    }

    /** Register Calendar and Event Details blocks */
    public function register_blocks() {
        register_block_type(DM_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar');
        register_block_type(DM_EVENTS_PLUGIN_DIR . 'inc/blocks/event-details');
        
        // Enqueue root CSS custom properties when any block is present
        add_action('wp_enqueue_scripts', array($this, 'enqueue_root_styles'));
        add_action('enqueue_block_assets', array($this, 'enqueue_root_styles'));
    }
    
    /** Enqueue root CSS custom properties */
    public function enqueue_root_styles() {
        // Only enqueue if dm-events blocks are present on the page
        if (has_block('dm-events/calendar') || has_block('dm-events/event-details')) {
            wp_enqueue_style(
                'dm-events-root',
                DM_EVENTS_PLUGIN_URL . 'inc/blocks/root.css',
                array(),
                filemtime(DM_EVENTS_PLUGIN_DIR . 'inc/blocks/root.css')
            );
        }
    }
    
    /**
     * Register custom block category for dm-events blocks
     *
     * @param array $block_categories Array of block categories
     * @param object $editor_context Editor context
     * @return array Modified block categories array
     */
    public function register_block_category($block_categories, $editor_context) {
        if (!empty($editor_context->post)) {
            array_unshift($block_categories, array(
                'slug'  => 'dm-events',
                'title' => __('DM Events', 'dm-events'),
                'icon'  => 'calendar-alt',
            ));
        }
        
        return $block_categories;
    }
    

}

/** @return DM_Events */
function dm_events() {
    return DM_Events::get_instance();
}

dm_events(); 