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

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Composer autoload (for external libraries)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define plugin constants
define('DM_EVENTS_VERSION', '1.0.0');
define('DM_EVENTS_PLUGIN_FILE', __FILE__);
define('DM_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DM_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DM_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DM_EVENTS_PATH', plugin_dir_path(__FILE__)); // Alias for Data Machine integration

// Always load venue term meta admin UI in admin
if (is_admin()) {
    require_once DM_EVENTS_PLUGIN_DIR . 'inc/events/venues/class-venue-term-meta.php';
}

/**
 * PSR-4 Autoloader for Data Machine Events classes
 * 
 * @param string $class_name The class name to load
 * @return void
 */
function dm_events_autoloader($class_name) {
    // Only autoload our classes
    if (strpos($class_name, 'DmEvents\\') !== 0) {
        return;
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("DEBUG Autoloader: Loading class: " . $class_name);
    }
    
    // Remove namespace prefix and convert to file path
    $class_name = str_replace('DmEvents\\', '', $class_name);
    $class_parts = explode('\\', $class_name);
    
    // Get the actual class name (last part)
    $actual_class = end($class_parts);
    
    // Convert class name to file path
    $file_name = 'class-' . strtolower(str_replace('_', '-', $actual_class)) . '.php';
    
    // Handle specific mappings for non-standard names
    if ($actual_class === 'DmEventsSettings') {
        $file_name = 'steps/publish/handlers/dm-events/DmEventsSettings.php';
    } elseif ($actual_class === 'DmEventsPublisher') {
        $file_name = 'steps/publish/handlers/dm-events/DmEventsPublisher.php';
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
            $file_path = DM_EVENTS_PLUGIN_DIR . $base_directory . '/' . $directory_path . $file_name;
            if (file_exists($file_path)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("DEBUG Autoloader: Found and loading: " . $file_path);
                }
                require_once $file_path;
                return;
            }
        }
        // Try directly in the base directory
        $file_path = DM_EVENTS_PLUGIN_DIR . $base_directory . '/' . $file_name;
        if (file_exists($file_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("DEBUG Autoloader: Found and loading: " . $file_path);
            }
            require_once $file_path;
            return;
        }
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("DEBUG Autoloader: Could not find file for class: " . $class_name);
    }
}

// Register autoloader
spl_autoload_register('dm_events_autoloader');

/**
 * Main Data Machine Events plugin class
 * 
 * @since 1.0.0
 */
class DM_Events {
    
    /**
     * Single instance of the plugin
     * 
     * @var DM_Events
     */
    private static $instance = null;
    
    /**
     * Get single instance of the plugin
     * 
     * @return DM_Events
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
        if (class_exists('DmEvents\\Events\\Event_Data_Manager')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("DEBUG: Initializing Event_Data_Manager in dm_events.php");
            }
            new \DmEvents\Events\Event_Data_Manager();
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("DEBUG: Event_Data_Manager class not found!");
            }
        }
        
        // Initialize Data Machine integration after taxonomies are registered
        add_action('init', array($this, 'init_data_machine_integration'), 25);
        
        // Initialize status detection system
        $this->init_status_detection();

    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Plugin lifecycle hooks
        register_activation_hook(DM_EVENTS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(DM_EVENTS_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Core WordPress hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        // Initialize admin class
        if (class_exists('DmEvents\\Admin')) {
            new \DmEvents\Admin();
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
     * Initialize status detection system
     * 
     * @since 1.0.0
     */
    private function init_status_detection() {
        // Only initialize if Data Machine is available
        if (!function_exists('apply_filters') || !has_filter('dm_detect_status')) {
            return;
        }
        
        // Initialize status detection
        if (class_exists('DmEvents\\Admin\\Status_Detection')) {
            new \DmEvents\Admin\Status_Detection();
        }
    }
    
    /**
     * Initialize Data Machine integration
     * 
     * Conditionally loads Data Machine integration if Data Machine plugin is active.
     * This enables event import and publish handlers for Data Machine pipelines.
     * 
     * @since 1.0.0
     */
    public function init_data_machine_integration() {
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
        // Load Event Import step
        if (file_exists(DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php')) {
            require_once DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportStep.php';
        }
        if (file_exists(DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php')) {
            require_once DM_EVENTS_PLUGIN_DIR . 'inc/steps/event-import/EventImportFilters.php';
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
            $handler_path = DM_EVENTS_PLUGIN_DIR . "inc/steps/event-import/handlers/{$handler}/";
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
        $dm_events_handler_path = DM_EVENTS_PLUGIN_DIR . 'inc/steps/publish/handlers/dm-events/';
        if (is_dir($dm_events_handler_path)) {
            foreach (glob($dm_events_handler_path . '*.php') as $file) {
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
        register_rest_field('dm_events', 'event_meta', array(
            'get_callback' => array($this, 'get_event_meta_for_rest'),
            'update_callback' => array($this, 'update_event_meta_from_rest'),
            'schema' => array(
                'description' => __('Event metadata', 'dm-events'),
                'type' => 'object',
                'context' => array('view', 'edit'),
                'properties' => array(
                    'start_date' => array(
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => __('Event start date and time', 'dm-events'),
                    ),
                    'end_date' => array(
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => __('Event end date and time', 'dm-events'),
                    ),
                    'venue_name' => array(
                        'type' => 'string',
                        'description' => __('Venue name', 'dm-events'),
                    ),
                    'artist_name' => array(
                        'type' => 'string',
                        'description' => __('Artist or performer name', 'dm-events'),
                    ),
                    'price' => array(
                        'type' => 'string',
                        'description' => __('Ticket price', 'dm-events'),
                    ),
                    'ticket_url' => array(
                        'type' => 'string',
                        'format' => 'uri',
                        'description' => __('Ticket purchase URL', 'dm-events'),
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
            'start_date' => get_post_meta($post_id, '_dm_event_start_date', true),
            'end_date' => get_post_meta($post_id, '_dm_event_end_date', true),
            'venue_name' => get_post_meta($post_id, '_dm_event_venue_name', true),
            'artist_name' => get_post_meta($post_id, '_dm_event_artist_name', true),
            'price' => get_post_meta($post_id, '_dm_event_price', true),
            'ticket_url' => get_post_meta($post_id, '_dm_event_ticket_url', true),
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
            'start_date' => '_dm_event_start_date',
            'end_date' => '_dm_event_end_date',
            'venue_name' => '_dm_event_venue_name',
            'artist_name' => '_dm_event_artist_name',
            'price' => '_dm_event_price',
            'ticket_url' => '_dm_event_ticket_url',
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
            'dm-events',
            false,
            dirname(DM_EVENTS_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    
    /**
     * Enqueue admin assets with dynamic versioning
     */
    public function enqueue_admin_assets($hook) {
        // Only load on Data Machine Events admin pages
        if (strpos($hook, 'dm-events') === false) {
            return;
        }
        
        $css_file = DM_EVENTS_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file = DM_EVENTS_PLUGIN_DIR . 'assets/js/admin.js';
        
        // Enqueue CSS with dynamic versioning by filemtime
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'dm-events-admin',
                DM_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }
        
        // Enqueue JS with dynamic versioning by filemtime
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
        if (function_exists('do_action')) {
            do_action('dm_log', 'info', 'Data Machine Events Plugin activated successfully');
        }
    }
    
    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        if (function_exists('do_action')) {
            do_action('dm_log', 'info', 'Data Machine Events Plugin deactivated');
        }
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
        
        add_option('dm_events_settings', $default_settings);
    }
    
    /**
     * Migrate existing settings to include new block settings
     */
    private function migrate_settings() {
        $existing_settings = get_option('dm_events_settings', array());
        
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
        update_option('dm_events_settings', $existing_settings);
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
            'name'                  => _x('Events', 'Post type general name', 'dm-events'),
            'singular_name'         => _x('Event', 'Post type singular name', 'dm-events'),
            'menu_name'             => _x('Events', 'Admin Menu text', 'dm-events'),
            'name_admin_bar'        => _x('Event', 'Add New on Toolbar', 'dm-events'),
            'add_new'               => __('Add New', 'dm-events'),
            'add_new_item'          => __('Add New Event', 'dm-events'),
            'new_item'              => __('New Event', 'dm-events'),
            'edit_item'             => __('Edit Event', 'dm-events'),
            'view_item'             => __('View Event', 'dm-events'),
            'all_items'             => __('All Events', 'dm-events'),
            'search_items'          => __('Search Events', 'dm-events'),
            'parent_item_colon'     => __('Parent Events:', 'dm-events'),
            'not_found'             => __('No events found.', 'dm-events'),
            'not_found_in_trash'    => __('No events found in Trash.', 'dm-events'),
            'featured_image'        => _x('Event Image', 'Overrides the "Featured Image" phrase', 'dm-events'),
            'set_featured_image'    => _x('Set event image', 'Overrides the "Set featured image" phrase', 'dm-events'),
            'remove_featured_image' => _x('Remove event image', 'Overrides the "Remove featured image" phrase', 'dm-events'),
            'use_featured_image'    => _x('Use as event image', 'Overrides the "Use as featured image" phrase', 'dm-events'),
            'archives'              => _x('Event archives', 'The post type archive label', 'dm-events'),
            'insert_into_item'      => _x('Insert into event', 'Overrides the "Insert into post" phrase', 'dm-events'),
            'uploaded_to_this_item' => _x('Uploaded to this event', 'Overrides the "Uploaded to this post" phrase', 'dm-events'),
            'filter_items_list'     => _x('Filter events list', 'Screen reader text for the filter links', 'dm-events'),
            'items_list_navigation' => _x('Events list navigation', 'Screen reader text for the pagination', 'dm-events'),
            'items_list'            => _x('Events list', 'Screen reader text for the items list', 'dm-events'),
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
            'rest_base'          => 'dm_events',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'taxonomies'         => array(), // Will be dynamically assigned
        );

        register_post_type('dm_events', $args);
    }
    
    /**
     * Register taxonomies for dm_events
     * 
     * @since 1.0.0
     */
    public function register_taxonomies() {
        // Register venue taxonomy for dm_events (core functionality)
        $this->register_venue_taxonomy();
        
        // Register all public taxonomies for dm_events (full customization support)
        $this->register_all_public_taxonomies();
        
        // Setup selective admin menu display for taxonomies
        $this->setup_admin_menu_control();
    }
    
    /**
     * Register venue taxonomy
     * 
     * @since 1.0.0
     */
    private function register_venue_taxonomy() {
        // Check if venue taxonomy already exists (from theme)
        if (taxonomy_exists('venue')) {
            // Extend existing venue taxonomy to include dm_events
            register_taxonomy_for_object_type('venue', 'dm_events');
        } else {
            // Create new venue taxonomy for both post and dm_events
            register_taxonomy('venue', array('post', 'dm_events'), array(
                'hierarchical' => false,
                'labels' => array(
                    'name' => _x('Venues', 'taxonomy general name', 'dm-events'),
                    'singular_name' => _x('Venue', 'taxonomy singular name', 'dm-events'),
                    'search_items' => __('Search Venues', 'dm-events'),
                    'all_items' => __('All Venues', 'dm-events'),
                    'edit_item' => __('Edit Venue', 'dm-events'),
                    'update_item' => __('Update Venue', 'dm-events'),
                    'add_new_item' => __('Add New Venue', 'dm-events'),
                    'new_item_name' => __('New Venue Name', 'dm-events'),
                    'menu_name' => __('Venues', 'dm-events'),
                ),
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => array('slug' => 'venue'),
                'show_in_rest' => true,
            ));
        }
        
        // Ensure venue taxonomy is registered for dm_events post type
        register_taxonomy_for_object_type('venue', 'dm_events');
    }
    
    /**
     * Register all public taxonomies for dm_events post type
     * 
     * @since 1.0.0
     */
    private function register_all_public_taxonomies() {
        // Get all public taxonomies (Data Machine pattern)
        $taxonomies = get_taxonomies(['public' => true], 'names');
        
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return;
        }
        
        foreach ($taxonomies as $taxonomy_slug) {
            // Skip venue - already handled above
            if ($taxonomy_slug === 'venue') {
                continue;
            }
            
            // Register each public taxonomy for dm_events post type
            register_taxonomy_for_object_type($taxonomy_slug, 'dm_events');
        }
    }
    
    /**
     * Setup selective admin menu display for dm_events taxonomies
     * 
     * Controls which taxonomies appear in the Events admin menu while keeping
     * all taxonomies functionally registered for AI and data operations.
     * 
     * @since 1.0.0
     */
    private function setup_admin_menu_control() {
        // Hook into WordPress admin menu system (late priority)
        add_action('admin_menu', array($this, 'control_taxonomy_menus'), 999);
        
        // Filter parent file for proper menu highlighting
        add_filter('parent_file', array($this, 'filter_parent_file'));
        
        // Filter submenu file for proper submenu highlighting  
        add_filter('submenu_file', array($this, 'filter_submenu_file'));
    }
    
    /**
     * Control which taxonomy menus appear under dm_events post type
     * 
     * @since 1.0.0
     */
    public function control_taxonomy_menus() {
        global $submenu;
        
        $post_type_menu = 'edit.php?post_type=dm_events';
        
        // Get allowed menu items via extensible filter
        // Extensions can add their items like:
        // add_filter('dm_events_post_type_menu_items', function($items) {
        //     $items['my_taxonomy'] = true;  // Show taxonomy in menu
        //     $items['custom_menu'] = ['type' => 'submenu', 'callback' => 'my_callback'];
        //     return $items;
        // });
        $allowed_items = apply_filters('dm_events_post_type_menu_items', array(
            'venue' => true,        // Always show venues
            'settings' => true      // Always show settings  
        ));
        
        // Remove non-allowed taxonomy menus
        if (isset($submenu[$post_type_menu])) {
            foreach ($submenu[$post_type_menu] as $key => $menu_item) {
                // Check if this is a taxonomy menu (contains 'taxonomy=')
                if (strpos($menu_item[2], 'taxonomy=') !== false) {
                    // Extract taxonomy name from menu URL
                    parse_str(parse_url($menu_item[2], PHP_URL_QUERY), $query_vars);
                    $taxonomy = $query_vars['taxonomy'] ?? '';
                    
                    // Remove if not in allowed list
                    if ($taxonomy && !isset($allowed_items[$taxonomy])) {
                        unset($submenu[$post_type_menu][$key]);
                    }
                }
            }
        }
        
        // Process custom menu items from filter
        foreach ($allowed_items as $item_key => $item_config) {
            if (is_array($item_config) && isset($item_config['type']) && $item_config['type'] === 'submenu') {
                // Custom submenu item - call the callback if provided
                if (isset($item_config['callback']) && is_callable($item_config['callback'])) {
                    call_user_func($item_config['callback']);
                }
            }
        }
    }
    
    /**
     * Filter parent file for proper menu highlighting
     * 
     * @param string $parent_file Current parent file
     * @return string Modified parent file
     * @since 1.0.0
     */
    public function filter_parent_file($parent_file) {
        global $current_screen;
        
        // Only process dm_events related screens
        if (!$current_screen || $current_screen->post_type !== 'dm_events') {
            return $parent_file;
        }
        
        // Get allowed menu items
        $allowed_items = apply_filters('dm_events_post_type_menu_items', array(
            'venue' => true,
            'settings' => true
        ));
        
        // If current taxonomy is not allowed, redirect to main Events page
        if ($current_screen->taxonomy && !isset($allowed_items[$current_screen->taxonomy])) {
            return 'edit.php?post_type=dm_events';
        }
        
        return $parent_file;
    }
    
    /**
     * Filter submenu file for proper submenu highlighting
     * 
     * @param string $submenu_file Current submenu file
     * @return string Modified submenu file  
     * @since 1.0.0
     */
    public function filter_submenu_file($submenu_file) {
        global $current_screen;
        
        // Only process dm_events related screens
        if (!$current_screen || $current_screen->post_type !== 'dm_events') {
            return $submenu_file;
        }
        
        // Ensure proper highlighting for allowed taxonomy pages
        if ($current_screen->taxonomy) {
            $allowed_items = apply_filters('dm_events_post_type_menu_items', array(
                'venue' => true,
                'settings' => true
            ));
            
            if (isset($allowed_items[$current_screen->taxonomy])) {
                return "edit-tags.php?taxonomy={$current_screen->taxonomy}&post_type=dm_events";
            }
        }
        
        return $submenu_file;
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
        
        // If we're in dm_events post type, allow our custom blocks
        if ($post_type === 'dm_events') {
            // Add our custom blocks to the allowed list
            $allowed_block_types[] = 'dm-events/event-details';
            $allowed_block_types[] = 'dm-events/calendar';
        } else {
            // Remove our custom blocks from other post types
            $allowed_block_types = array_filter($allowed_block_types, function($block_type) {
                return strpos($block_type, 'dm-events/') !== 0;
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
        $calendar_block_path = DM_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar';
        register_block_type($calendar_block_path, array(
            'render_callback' => array($this, 'render_calendar_block')
        ));
        
        // Register event details block
        $event_details_block_path = DM_EVENTS_PLUGIN_DIR . 'inc/blocks/event-details';
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
            'category' => 'dm-events',
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
        include DM_EVENTS_PLUGIN_DIR . 'inc/blocks/calendar/render.php';
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
        include DM_EVENTS_PLUGIN_DIR . 'inc/blocks/event-details/render.php';
        
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
            'dm-events-calendar-frontend',
            DM_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/build/frontend.js',
            array(),
            DM_EVENTS_VERSION,
            true
        );
        
        // Enqueue calendar block frontend CSS (includes flatpickr styles)
        wp_enqueue_style(
            'dm-events-calendar-frontend-style',
            DM_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/build/frontend.css',
            array(),
            DM_EVENTS_VERSION
        );
        
        // Enqueue calendar block styles
        wp_enqueue_style(
            'dm-events-calendar-style',
            DM_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/style.css',
            array(),
            DM_EVENTS_VERSION
        );
    }
}

/**
 * Initialize the plugin
 * 
 * @return DM_Events
 */
function dm_events() {
    return DM_Events::get_instance();
}

// Start the plugin
dm_events(); 