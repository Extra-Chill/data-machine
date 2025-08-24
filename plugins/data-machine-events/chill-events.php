<?php
/**
 * Plugin Name: Chill Events
 * Plugin URI: https://chubes.net/chill-events
 * Description: A modern WordPress events plugin with Gutenberg block-first architecture. Features Event Details blocks for manual event creation, Calendar blocks for event display, and rich venue taxonomy management.
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
define('CHILL_EVENTS_PATH', plugin_dir_path(__FILE__)); // Alias for Data Machine integration

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
    $base_directories = array('inc', 'inc/admin', 'inc/events', 'inc/core', 'inc/steps');
    
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
        
        // Register post types and taxonomies
        $this->register_post_types();
        add_action('init', array($this, 'register_taxonomies'), 20);
        
        // Register blocks
        add_action('init', array($this, 'register_blocks'), 20);
        
        // Filter block types to show only in correct post type
        add_filter('allowed_block_types_all', array($this, 'filter_allowed_block_types'), 10, 2);
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
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
        
        // Initialize Data Machine integration if Data Machine is available
        $this->init_data_machine_integration();

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
     * Initialize Data Machine integration
     * 
     * Conditionally loads Data Machine integration if Data Machine plugin is active.
     * This enables event import and publish handlers for Data Machine pipelines.
     * 
     * @since 1.0.0
     */
    private function init_data_machine_integration() {
        // Check if Data Machine is available
        if (!defined('DATA_MACHINE_VERSION')) {
            return;
        }
        
        // Load Data Machine components directly
        $this->load_data_machine_components();
    }
    
    /**
     * Load Data Machine integration components
     * 
     * @since 1.0.0
     */
    private function load_data_machine_components() {
        // Load BaseDataSource class for scrapers
        if (file_exists(CHILL_EVENTS_PLUGIN_DIR . 'inc/events/class-base-data-source.php')) {
            require_once CHILL_EVENTS_PLUGIN_DIR . 'inc/events/class-base-data-source.php';
        }
        
        // Load Event Import step
        if (file_exists(CHILL_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php')) {
            require_once CHILL_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php';
        }
        if (file_exists(CHILL_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php')) {
            require_once CHILL_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php';
        }
        
        // Load event import handlers (they self-register via filters)
        $this->load_event_import_handlers();
        
        // Load publish handlers
        $this->load_publish_handlers();
    }
    
    /**
     * Load event import handlers
     * 
     * @since 1.0.0
     */
    private function load_event_import_handlers() {
        $handlers = ['ticketmaster', 'dice-fm', 'web-scraper'];
        
        foreach ($handlers as $handler) {
            $handler_path = CHILL_EVENTS_PLUGIN_DIR . "inc/steps/event-import/handlers/{$handler}/";
            if (is_dir($handler_path)) {
                foreach (glob($handler_path . '*.php') as $file) {
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
                
                // Load scrapers for web-scraper handler
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
    
    /**
     * Load publish handlers
     * 
     * @since 1.0.0
     */
    private function load_publish_handlers() {
        $chill_events_handler_path = CHILL_EVENTS_PLUGIN_DIR . 'inc/steps/publish/handlers/chill-events/';
        if (is_dir($chill_events_handler_path)) {
            foreach (glob($chill_events_handler_path . '*.php') as $file) {
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        }
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
                    'confirmDelete' => __('Are you sure you want to delete this item?', 'chill-events'),
                    'confirmDisable' => __('Are you sure you want to disable this item?', 'chill-events'),
                )
            ));
        }
    }
    
    /**
     * Plugin activation hook
     */
    public function activate() {
        // Register post types and taxonomies for activation
        $this->register_post_types();
        $this->register_taxonomies();
        
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
        // Clear any scheduled cron jobs
        wp_clear_scheduled_hook('chill_events_import_cron');
        
        // Clean up import-related database tables (one-time cleanup)
        $this->cleanup_import_infrastructure();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('Chill Events Plugin deactivated');
    }
    
    /**
     * Clean up legacy import infrastructure (tables and cron jobs)
     * This is a migration cleanup function for installations upgrading from import-based versions
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
        
        // Clean up legacy options from import-based versions
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
    
    // ========================================
    // Core Plugin Methods (moved from Core class)
    // ========================================
    
    /**
     * Register custom post types
     * 
     * @since 1.0.0
     */
    public function register_post_types() {
        $this->register_event_post_type();
    }
    
    /**
     * Register event custom post type
     * 
     * @since 1.0.0
     */
    private function register_event_post_type() {
        $labels = array(
            'name'                  => _x('Events', 'Post type general name', 'chill-events'),
            'singular_name'         => _x('Event', 'Post type singular name', 'chill-events'),
            'menu_name'             => _x('Events', 'Admin Menu text', 'chill-events'),
            'name_admin_bar'        => _x('Event', 'Add New on Toolbar', 'chill-events'),
            'add_new'               => __('Add New', 'chill-events'),
            'add_new_item'          => __('Add New Event', 'chill-events'),
            'new_item'              => __('New Event', 'chill-events'),
            'edit_item'             => __('Edit Event', 'chill-events'),
            'view_item'             => __('View Event', 'chill-events'),
            'all_items'             => __('All Events', 'chill-events'),
            'search_items'          => __('Search Events', 'chill-events'),
            'parent_item_colon'     => __('Parent Events:', 'chill-events'),
            'not_found'             => __('No events found.', 'chill-events'),
            'not_found_in_trash'    => __('No events found in Trash.', 'chill-events'),
            'featured_image'        => _x('Event Image', 'Overrides the "Featured Image" phrase', 'chill-events'),
            'set_featured_image'    => _x('Set event image', 'Overrides the "Set featured image" phrase', 'chill-events'),
            'remove_featured_image' => _x('Remove event image', 'Overrides the "Remove featured image" phrase', 'chill-events'),
            'use_featured_image'    => _x('Use as event image', 'Overrides the "Use as featured image" phrase', 'chill-events'),
            'archives'              => _x('Event archives', 'The post type archive label', 'chill-events'),
            'insert_into_item'      => _x('Insert into event', 'Overrides the "Insert into post" phrase', 'chill-events'),
            'uploaded_to_this_item' => _x('Uploaded to this event', 'Overrides the "Uploaded to this post" phrase', 'chill-events'),
            'filter_items_list'     => _x('Filter events list', 'Screen reader text for the filter links', 'chill-events'),
            'items_list_navigation' => _x('Events list navigation', 'Screen reader text for the pagination', 'chill-events'),
            'items_list'            => _x('Events list', 'Screen reader text for the items list', 'chill-events'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_admin_bar'  => true,
            'query_var'          => true,
            'rewrite'            => array(
                'slug'       => 'events',
                'with_front' => false,
            ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 5, // Below Posts
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array(
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'custom-fields',
                'revisions',
                'author',
                'page-attributes',
                'editor-styles',
                'wp-block-styles',
                'align-wide',
            ),
            'show_in_rest'       => true,
            'rest_base'          => 'chill_events',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'taxonomies'         => array(), // Will be dynamically assigned
        );

        register_post_type('chill_events', $args);
    }
    
    /**
     * Register taxonomies for chill_events
     * 
     * @since 1.0.0
     */
    public function register_taxonomies() {
        // Register venue taxonomy for chill_events (core functionality)
        $this->register_venue_taxonomy();
    }
    
    /**
     * Register venue taxonomy
     * 
     * @since 1.0.0
     */
    private function register_venue_taxonomy() {
        // Check if venue taxonomy already exists (from theme)
        if (taxonomy_exists('venue')) {
            // Extend existing venue taxonomy to include chill_events
            register_taxonomy_for_object_type('venue', 'chill_events');
        } else {
            // Create new venue taxonomy for both post and chill_events
            register_taxonomy('venue', array('post', 'chill_events'), array(
                'hierarchical' => false,
                'labels' => array(
                    'name' => _x('Venues', 'taxonomy general name', 'chill-events'),
                    'singular_name' => _x('Venue', 'taxonomy singular name', 'chill-events'),
                    'search_items' => __('Search Venues', 'chill-events'),
                    'all_items' => __('All Venues', 'chill-events'),
                    'edit_item' => __('Edit Venue', 'chill-events'),
                    'update_item' => __('Update Venue', 'chill-events'),
                    'add_new_item' => __('Add New Venue', 'chill-events'),
                    'new_item_name' => __('New Venue Name', 'chill-events'),
                    'menu_name' => __('Venues', 'chill-events'),
                ),
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => array('slug' => 'venue'),
                'show_in_rest' => true,
            ));
        }
        
        // Ensure venue taxonomy is registered for chill_events post type
        register_taxonomy_for_object_type('venue', 'chill_events');
    }
    
    /**
     * Filter allowed block types based on post type
     * 
     * @param array|boolean $allowed_block_types Array of block type slugs, or boolean to enable/disable all
     * @param WP_Block_Editor_Context $block_editor_context The current block editor context
     * @return array|boolean
     */
    public function filter_allowed_block_types($allowed_block_types, $block_editor_context) {
        // Only filter if we have a post type
        if (!isset($block_editor_context->post) || !isset($block_editor_context->post->post_type)) {
            return $allowed_block_types;
        }
        
        $post_type = $block_editor_context->post->post_type;
        
        // If it's not an array, we can't filter it
        if (!is_array($allowed_block_types)) {
            return $allowed_block_types;
        }
        
        // If we're in chill_events post type, allow our custom blocks
        if ($post_type === 'chill_events') {
            // Add our custom blocks to the allowed list
            $allowed_block_types[] = 'chill-events/event-details';
            $allowed_block_types[] = 'chill-events/calendar';
        } else {
            // Remove our custom blocks from other post types
            $allowed_block_types = array_filter($allowed_block_types, function($block_type) {
                return strpos($block_type, 'chill-events/') !== 0;
            });
        }
        
        return $allowed_block_types;
    }

    /**
     * Register blocks
     * 
     * @since 1.0.0
     */
    public function register_blocks() {
        // Register calendar block
        $calendar_block_path = CHILL_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar';
        register_block_type($calendar_block_path, array(
            'render_callback' => array($this, 'render_calendar_block')
        ));
        
        // Register event details block
        $event_details_block_path = CHILL_EVENTS_PLUGIN_DIR . 'inc/blocks/event-details';
        register_block_type($event_details_block_path, array(
            'render_callback' => array($this, 'render_event_details_block'),
            'attributes' => array(
                'startDate' => array('type' => 'string', 'default' => ''),
                'endDate' => array('type' => 'string', 'default' => ''),
                'startTime' => array('type' => 'string', 'default' => ''),
                'endTime' => array('type' => 'string', 'default' => ''),
                'venue' => array('type' => 'string', 'default' => ''),
                'address' => array('type' => 'string', 'default' => ''),
                'artist' => array('type' => 'string', 'default' => ''),
                'price' => array('type' => 'string', 'default' => ''),
                'ticketUrl' => array('type' => 'string', 'default' => ''),
                'showVenue' => array('type' => 'boolean', 'default' => true),
                'showArtist' => array('type' => 'boolean', 'default' => true),
                'showPrice' => array('type' => 'boolean', 'default' => true),
                'showTicketLink' => array('type' => 'boolean', 'default' => true)
            ),
            'category' => 'chill-events',
            'supports' => array(
                'html' => false,
                'align' => array('wide', 'full')
            )
        ));
    }
    
    /**
     * Render callback for calendar block
     * 
     * @param array $attributes Block attributes
     * @param string $content Block content
     * @param WP_Block $block Block instance
     * @return string HTML output
     */
    public function render_calendar_block($attributes, $content, $block) {
        // Include the render template directly without output buffering
        include CHILL_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar/render.php';
    }

    /**
     * Render callback for event details block
     * 
     * @param array $attributes Block attributes
     * @param string $content Block content
     * @param WP_Block $block Block instance
     * @return string HTML output
     */
    public function render_event_details_block($attributes, $content, $block) {
        // Start output buffering to capture the rendered content
        ob_start();
        
        // Include the render template
        include CHILL_EVENTS_PLUGIN_DIR . 'inc/blocks/event-details/render.php';
        
        // Return the captured content
        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets
     * 
     * @since 1.0.0
     */
    public function enqueue_frontend_assets() {
        // Enqueue calendar block frontend JavaScript
        wp_enqueue_script(
            'chill-events-calendar-frontend',
            CHILL_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/build/frontend.js',
            array(),
            CHILL_EVENTS_VERSION,
            true
        );
        
        // Enqueue calendar block frontend CSS (includes flatpickr styles)
        wp_enqueue_style(
            'chill-events-calendar-frontend-style',
            CHILL_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/build/frontend.css',
            array(),
            CHILL_EVENTS_VERSION
        );
        
        // Enqueue calendar block styles
        wp_enqueue_style(
            'chill-events-calendar-style',
            CHILL_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/style.css',
            array(),
            CHILL_EVENTS_VERSION
        );
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