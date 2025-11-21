<?php
/**
 * Event Post Type Registration
 *
 * Handles registration of the datamachine_events custom post type with selective taxonomy menu control.
 *
 * @package DataMachineEvents
 * @subpackage Core
 */

namespace DataMachineEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Post Type registration and configuration
 */
class Event_Post_Type {
    
    public static function register() {
        $labels = array(
            'name'                  => _x('Events', 'Post type general name', 'datamachine-events'),
            'singular_name'         => _x('Event', 'Post type singular name', 'datamachine-events'),
            'menu_name'             => _x('Events', 'Admin Menu text', 'datamachine-events'),
            'name_admin_bar'        => _x('Event', 'Add New on Toolbar', 'datamachine-events'),
            'add_new'               => __('Add New', 'datamachine-events'),
            'add_new_item'          => __('Add New Event', 'datamachine-events'),
            'new_item'              => __('New Event', 'datamachine-events'),
            'edit_item'             => __('Edit Event', 'datamachine-events'),
            'view_item'             => __('View Event', 'datamachine-events'),
            'all_items'             => __('All Events', 'datamachine-events'),
            'search_items'          => __('Search Events', 'datamachine-events'),
            'parent_item_colon'     => __('Parent Events:', 'datamachine-events'),
            'not_found'             => __('No events found.', 'datamachine-events'),
            'not_found_in_trash'    => __('No events found in Trash.', 'datamachine-events'),
            'featured_image'        => _x('Event Image', 'Overrides the "Featured Image" phrase', 'datamachine-events'),
            'set_featured_image'    => _x('Set event image', 'Overrides the "Set featured image" phrase', 'datamachine-events'),
            'remove_featured_image' => _x('Remove event image', 'Overrides the "Remove featured image" phrase', 'datamachine-events'),
            'use_featured_image'    => _x('Use as event image', 'Overrides the "Use as featured image" phrase', 'datamachine-events'),
            'archives'              => _x('Event archives', 'The post type archive label', 'datamachine-events'),
            'insert_into_item'      => _x('Insert into event', 'Overrides the "Insert into post" phrase', 'datamachine-events'),
            'uploaded_to_this_item' => _x('Uploaded to this event', 'Overrides the "Uploaded to this post" phrase', 'datamachine-events'),
            'filter_items_list'     => _x('Filter events list', 'Screen reader text for the filter links', 'datamachine-events'),
            'items_list_navigation' => _x('Events list navigation', 'Screen reader text for the pagination', 'datamachine-events'),
            'items_list'            => _x('Events list', 'Screen reader text for the items list', 'datamachine-events'),
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
            'menu_position'      => 5,
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
            'rest_base'          => 'datamachine_events',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'taxonomies'         => array(),
        );

        register_post_type('datamachine_events', $args);
        
        self::setup_admin_menu_control();
    }
    
    private static function setup_admin_menu_control() {
        add_action('admin_menu', array(__CLASS__, 'control_taxonomy_menus'), 999);
        
        add_filter('parent_file', array(__CLASS__, 'filter_parent_file'));
        
        add_filter('submenu_file', array(__CLASS__, 'filter_submenu_file'));
    }
    
    public static function control_taxonomy_menus() {
        global $submenu;
        
        $post_type_menu = 'edit.php?post_type=datamachine_events';
        
        $allowed_items = apply_filters('datamachine_events_post_type_menu_items', array(
            'venue' => true,
            'settings' => true
        ));
        
        if (isset($submenu[$post_type_menu])) {
            foreach ($submenu[$post_type_menu] as $key => $menu_item) {
                if (strpos($menu_item[2], 'taxonomy=') !== false) {
                    parse_str(parse_url($menu_item[2], PHP_URL_QUERY), $query_vars);
                    $taxonomy = $query_vars['taxonomy'] ?? '';
                    
                    if ($taxonomy && !isset($allowed_items[$taxonomy])) {
                        unset($submenu[$post_type_menu][$key]);
                    }
                }
            }
        }
        
        foreach ($allowed_items as $item_key => $item_config) {
            if (is_array($item_config) && isset($item_config['type']) && $item_config['type'] === 'submenu') {
                if (isset($item_config['callback']) && is_callable($item_config['callback'])) {
                    call_user_func($item_config['callback']);
                }
            }
        }
    }
    
    /**
     * Ensures proper menu highlighting by filtering parent file for disallowed taxonomies
     */
    public static function filter_parent_file($parent_file) {
        global $current_screen;

        if (!$current_screen || $current_screen->post_type !== 'datamachine_events') {
            return $parent_file;
        }

        $allowed_items = apply_filters('datamachine_events_post_type_menu_items', array(
            'venue' => true,
            'settings' => true
        ));

        if ($current_screen->taxonomy && !isset($allowed_items[$current_screen->taxonomy])) {
            return 'edit.php?post_type=datamachine_events';
        }

        return $parent_file;
    }

    /**
     * Ensures proper submenu highlighting for allowed taxonomies
     */
    public static function filter_submenu_file($submenu_file) {
        global $current_screen;
        
        if (!$current_screen || $current_screen->post_type !== 'datamachine_events') {
            return $submenu_file;
        }
        
        if ($current_screen->taxonomy) {
            $allowed_items = apply_filters('datamachine_events_post_type_menu_items', array(
                'venue' => true,
                'settings' => true
            ));
            
            if (isset($allowed_items[$current_screen->taxonomy])) {
                return "edit-tags.php?taxonomy={$current_screen->taxonomy}&post_type=datamachine_events";
            }
        }
        
        return $submenu_file;
    }
}