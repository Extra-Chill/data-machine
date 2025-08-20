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
        
        // Filter block types to show only in correct post type
        add_filter('allowed_block_types_all', array($this, 'filter_allowed_block_types'), 10, 2);
        
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
        
        // Register dynamic taxonomies for chill_events (theme taxonomies)
        if (class_exists('ChillEvents\\Utils\\DynamicTaxonomies')) {
            DynamicTaxonomies::register_for_chill_events();
        }
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
            $this->get_version(),
            true
        );
        
        // Enqueue calendar block frontend CSS (includes flatpickr styles)
        wp_enqueue_style(
            'chill-events-calendar-frontend-style',
            CHILL_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/build/frontend.css',
            array(),
            $this->get_version()
        );
        
        // Enqueue calendar block styles
        wp_enqueue_style(
            'chill-events-calendar-style',
            CHILL_EVENTS_PLUGIN_URL . 'inc/blocks/calendar/style.css',
            array(),
            $this->get_version()
        );
    }

} 