<?php
/**
 * Plugin Name: Chill Events
 * Plugin URI: https://chubes.net/chill-events
 * Description: A comprehensive WordPress events plugin designed as a complete replacement for Tribe Events Calendar. Features Import Modules system for automated event management, native API integrations (Ticketmaster, Dice FM, Eventbrite), and universal taxonomy support.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chill-events
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * Network: false
 *
 * @package ChillEvents
 * @author Chris Huber
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Composer autoload (for external libraries)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define plugin constants
define('CHILL_EVENTS_VERSION', '1.0.0');
define('CHILL_EVENTS_PLUGIN_FILE', __FILE__);
define('CHILL_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHILL_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHILL_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Always load venue term meta admin UI in admin
if (is_admin()) {
    require_once CHILL_EVENTS_PLUGIN_DIR . 'inc/events/venues/class-venue-term-meta.php';
}

/**
 * PSR-4 Autoloader for Chill Events classes
 * 
 * @param string $class_name The class name to load
 * @return void
 */
function chill_events_autoloader($class_name) {
    // Only autoload our classes
    if (strpos($class_name, 'ChillEvents\\') !== 0) {
        return;
    }
    
    // Remove namespace prefix and convert to file path
    $class_name = str_replace('ChillEvents\\', '', $class_name);
    $class_parts = explode('\\', $class_name);
    
    // Get the actual class name (last part)
    $actual_class = end($class_parts);
    
    // Handle specific class name mappings
    $file_name_mappings = array(
        'Core' => 'core/class-chill-events-core.php',
        
        // Events
        'Venue_Term_Meta' => 'events/venues/class-venue-term-meta.php',
        'Event_Data_Manager' => 'events/class-event-data-manager.php',
    );
    
    // Check if we have a specific mapping for this class
    if (isset($file_name_mappings[$actual_class])) {
        $file_name = $file_name_mappings[$actual_class];
    } else {
        // Default conversion: PascalCase to kebab-case
        $file_name = 'class-' . strtolower(str_replace('_', '-', $actual_class)) . '.php';
    }
    
    // Build directory path from namespace
    $namespace_dirs = array_slice($class_parts, 0, -1); // Remove class name
    $directory_path = '';
    if (!empty($namespace_dirs)) {
        $directory_path = strtolower(implode('/', $namespace_dirs)) . '/';
    }
    
    // Try multiple base directories
    $base_directories = array('inc', 'inc/admin', 'inc/events', 'inc/core', 'inc/utils');
    
    foreach ($base_directories as $base_directory) {
        // Try with namespace directory path
        if (!empty($directory_path)) {
            $file_path = CHILL_EVENTS_PLUGIN_DIR . $base_directory . '/' . $directory_path . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
                return;
            }
        }
        // Try directly in the base directory
        $file_path = CHILL_EVENTS_PLUGIN_DIR . $base_directory . '/' . $file_name;
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }
}

// Register autoloader
spl_autoload_register('chill_events_autoloader');

/**
 * Main Chill Events plugin class
 * 
 * @since 1.0.0
 */
class Chill_Events {
    
    /**
     * Single instance of the plugin
     * 
     * @var Chill_Events
     */
    private static $instance = null;
    
    /**
     * Get single instance of the plugin
     * 
     * @return Chill_Events
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - initialize the plugin
     */
    private function __construct() {
        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /**
     * Initialize plugin functionality
     */
    public function init() {
        // Initialize core components
        $this->init_hooks();
        
        // Initialize core functionality directly
        if (class_exists('ChillEvents\\Core')) {
            $core = new \ChillEvents\Core();
            // Call the registration methods directly since we're already in init
            $core->register_post_types();
        }
        
        // Register taxonomies after post types are registered
        add_action('init', function() {
            if (class_exists('ChillEvents\\Core')) {
                $core = new \ChillEvents\Core();
                $core->register_taxonomies();
            }
        }, 20);
        
        // Register REST API fields
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        
        // Load admin functionality if in admin
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Load frontend functionality
        $this->init_frontend();

        // Initialize the Event Data Manager to handle block-to-meta sync
        if (class_exists('ChillEvents\\Events\\Event_Data_Manager')) {
            new \ChillEvents\Events\Event_Data_Manager();
        }

    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Plugin lifecycle hooks
        register_activation_hook(CHILL_EVENTS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(CHILL_EVENTS_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Core WordPress hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        // Initialize admin class
        if (class_exists('ChillEvents\\Admin')) {
            new \ChillEvents\Admin();
        }
    }
    
    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        // Frontend components will be loaded here in Phase 18-20
        // For now, just ensure frontend structure is ready
    }
    
    /**
     * Register REST API fields (for headless implementations)
     * 
     * @since 1.0.0
     */
    public function register_rest_fields() {
        // Event meta fields for REST API
        register_rest_field('chill_events', 'event_meta', array(
            'get_callback' => array($this, 'get_event_meta_for_rest'),
            'update_callback' => array($this, 'update_event_meta_from_rest'),
            'schema' => array(
                'description' => __('Event metadata', 'chill-events'),
                'type' => 'object',
                'context' => array('view', 'edit'),
                'properties' => array(
                    'start_date' => array(
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => __('Event start date and time', 'chill-events'),
                    ),
                    'end_date' => array(
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => __('Event end date and time', 'chill-events'),
                    ),
                    'venue_name' => array(
                        'type' => 'string',
                        'description' => __('Venue name', 'chill-events'),
                    ),
                    'artist_name' => array(
                        'type' => 'string',
                        'description' => __('Artist or performer name', 'chill-events'),
                    ),
                    'price' => array(
                        'type' => 'string',
                        'description' => __('Ticket price', 'chill-events'),
                    ),
                    'ticket_url' => array(
                        'type' => 'string',
                        'format' => 'uri',
                        'description' => __('Ticket purchase URL', 'chill-events'),
                    ),
                ),
            ),
        ));
    }
    
    /**
     * Get event meta for REST API
     * 
     * @param array $object Post object
     * @return array Event metadata
     * @since 1.0.0
     */
    public function get_event_meta_for_rest($object) {
        $post_id = $object['id'];
        
        return array(
            'start_date' => get_post_meta($post_id, '_chill_event_start_date', true),
            'end_date' => get_post_meta($post_id, '_chill_event_end_date', true),
            'venue_name' => get_post_meta($post_id, '_chill_event_venue_name', true),
            'artist_name' => get_post_meta($post_id, '_chill_event_artist_name', true),
            'price' => get_post_meta($post_id, '_chill_event_price', true),
            'ticket_url' => get_post_meta($post_id, '_chill_event_ticket_url', true),
        );
    }
    
    /**
     * Update event meta from REST API
     * 
     * @param mixed $value The value to update
     * @param WP_Post $object Post object
     * @param string $field_name Field name
     * @return bool True on success
     * @since 1.0.0
     */
    public function update_event_meta_from_rest($value, $object, $field_name) {
        if (!is_array($value)) {
            return false;
        }
        
        $post_id = $object->ID;
        $meta_fields = array(
            'start_date' => '_chill_event_start_date',
            'end_date' => '_chill_event_end_date',
            'venue_name' => '_chill_event_venue_name',
            'artist_name' => '_chill_event_artist_name',
            'price' => '_chill_event_price',
            'ticket_url' => '_chill_event_ticket_url',
        );
        
        foreach ($meta_fields as $key => $meta_key) {
            if (isset($value[$key])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($value[$key]));
            }
        }
        
        return true;
    }
    
    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'chill-events',
            false,
            dirname(CHILL_EVENTS_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    /**
     * Enqueue frontend assets with dynamic versioning
     */
    public function enqueue_frontend_assets() {
        $css_file = CHILL_EVENTS_PLUGIN_DIR . 'assets/css/chill-events-frontend.css';
        
        // Enqueue CSS with dynamic versioning by filemtime
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'chill-events-frontend',
                CHILL_EVENTS_PLUGIN_URL . 'assets/css/chill-events-frontend.css',
                array(),
                filemtime($css_file)
            );
        }
        
        // Note: JavaScript is handled by individual blocks (e.g., calendar block has its own JS)
    }
    
    /**
     * Enqueue admin assets with dynamic versioning
     */
    public function enqueue_admin_assets($hook) {
        // Only load on Chill Events admin pages
        if (strpos($hook, 'chill-events') === false) {
            return;
        }
        
        $css_file = CHILL_EVENTS_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file = CHILL_EVENTS_PLUGIN_DIR . 'assets/js/admin.js';
        
        // Enqueue CSS with dynamic versioning by filemtime
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'chill-events-admin',
                CHILL_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }
        
        // Enqueue JS with dynamic versioning by filemtime
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'chill-events-admin',
                CHILL_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery', 'wp-util'),
                filemtime($js_file),
                true
            );
            
            // Pass data to JS
            wp_localize_script('chill-events-admin', 'chillEventsAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chill_events_admin'),
                'strings' => array(
                    'confirmDelete' => __('Are you sure you want to delete this module?', 'chill-events'),
                    'confirmDisable' => __('Are you sure you want to disable this module?', 'chill-events'),
                )
            ));
        }
    }
    
    /**
     * Plugin activation hook
     */
    public function activate() {
        // Initialize core functionality first
        if (class_exists('ChillEvents\\Core')) {
            new \ChillEvents\Core();
        }
        
        // Flush rewrite rules to ensure custom post types work
        flush_rewrite_rules();
        
        // Set default options
        $this->set_default_options();
        
        // Migrate existing settings to include new block settings
        $this->migrate_settings();
        
        // Log activation
        error_log('Chill Events Plugin activated successfully');
    }
    
    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('chill_events_import_cron');
        
        // Clean up import-related database tables (one-time cleanup)
        $this->cleanup_import_infrastructure();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('Chill Events Plugin deactivated');
    }
    
    /**
     * Clean up import infrastructure (tables and cron jobs)
     * This is a one-time cleanup function for the transition
     */
    private function cleanup_import_infrastructure() {
        global $wpdb;
        
        // Drop import-related tables
        $tables_to_drop = array(
            $wpdb->prefix . 'chill_import_modules',
            $wpdb->prefix . 'chill_import_logs'
        );
        
        foreach ($tables_to_drop as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // Clear any remaining scheduled events
        wp_clear_scheduled_hook('chill_events_import_cron');
        
        // Clean up import-related options
        delete_option('chill_events_import_settings');
        delete_option('chill_events_api_config');
        
        error_log('Chill Events: Cleaned up import infrastructure');
    }
    

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_settings = array(
            // Event Details block display settings
            'block_show_venue' => 1,
            'block_show_artist' => 1,
            'block_show_price' => 1,
            'block_show_ticket_link' => 1
        );
        
        add_option('chill_events_settings', $default_settings);
    }
    
    /**
     * Migrate existing settings to include new block settings
     */
    private function migrate_settings() {
        $existing_settings = get_option('chill_events_settings', array());
        
        // Add block display settings if they don't exist
        if (!isset($existing_settings['block_show_venue'])) {
            $existing_settings['block_show_venue'] = 1;
        }
        if (!isset($existing_settings['block_show_artist'])) {
            $existing_settings['block_show_artist'] = 1;
        }
        if (!isset($existing_settings['block_show_price'])) {
            $existing_settings['block_show_price'] = 1;
        }
        if (!isset($existing_settings['block_show_ticket_link'])) {
            $existing_settings['block_show_ticket_link'] = 1;
        }
        
        // Update the settings
        update_option('chill_events_settings', $existing_settings);
    }
}

/**
 * Initialize the plugin
 * 
 * @return Chill_Events
 */
function chill_events() {
    return Chill_Events::get_instance();
}

// Start the plugin
chill_events(); 