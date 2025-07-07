<?php
/**
 * Core Chill Events functionality
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;
use ChillEvents\Utils\DynamicTaxonomies;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core plugin functionality class
 * 
 * @since 1.0.0
 */
class Core {
    
    /**
     * Initialize core functionality
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Register blocks on init to ensure proper timing
        add_action('init', array($this, 'register_blocks'), 20);
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }
    
    /**
     * Register custom post types
     * 
     * @since 1.0.0
     */
    public function register_post_types() {
        $this->register_event_post_type();
        // Register dynamic taxonomies for chill_events
        if (class_exists('ChillEvents\\Utils\\DynamicTaxonomies')) {
            DynamicTaxonomies::register_for_chill_events();
        }
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
            ),
            'show_in_rest'       => true,
            'rest_base'          => 'events',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'taxonomies'         => array(), // Will be dynamically assigned
        );

        register_post_type('chill_events', $args);
    }
    
    /**
     * Register taxonomies (placeholder for Phase 4)
     * 
     * @since 1.0.0
     */
    public function register_taxonomies() {
        // Taxonomies will be implemented in Phase 4
        // This ensures the hook structure is ready
    }
    
    /**
     * Get plugin version
     * 
     * @return string Plugin version
     * @since 1.0.0
     */
    public function get_version() {
        return CHILL_EVENTS_VERSION;
    }
    
    /**
     * Get plugin directory path
     * 
     * @return string Plugin directory path
     * @since 1.0.0
     */
    public function get_plugin_dir() {
        return CHILL_EVENTS_PLUGIN_DIR;
    }
    
    /**
     * Get plugin URL
     * 
     * @return string Plugin URL
     * @since 1.0.0
     */
    public function get_plugin_url() {
        return CHILL_EVENTS_PLUGIN_URL;
    }

    /**
     * Register blocks
     * 
     * @since 1.0.0
     */
    public function register_blocks() {
        $block_path = CHILL_EVENTS_PLUGIN_DIR . 'includes/blocks/calendar';
        
        // Register calendar block with explicit render callback
        register_block_type($block_path, array(
            'render_callback' => array($this, 'render_calendar_block')
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
        include CHILL_EVENTS_PLUGIN_DIR . 'includes/blocks/calendar/render.php';
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
            CHILL_EVENTS_PLUGIN_URL . 'includes/blocks/calendar/build/frontend.js',
            array(),
            $this->get_version(),
            true
        );
        
        // Enqueue calendar block frontend CSS (includes flatpickr styles)
        wp_enqueue_style(
            'chill-events-calendar-frontend-style',
            CHILL_EVENTS_PLUGIN_URL . 'includes/blocks/calendar/build/frontend.css',
            array(),
            $this->get_version()
        );
        
        // Enqueue calendar block styles
        wp_enqueue_style(
            'chill-events-calendar-style',
            CHILL_EVENTS_PLUGIN_URL . 'includes/blocks/calendar/style.css',
            array(),
            $this->get_version()
        );
    }

} 