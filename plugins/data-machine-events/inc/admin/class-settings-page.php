<?php
/**
 * Events Settings Page
 *
 * Provides minimal settings interface for controlling event archive behavior,
 * search integration, and display preferences.
 *
 * @package DmEvents
 * @subpackage Admin
 */

namespace DmEvents\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page for DM Events plugin
 */
class Settings_Page {
    
    /**
     * Settings option key
     */
    const OPTION_KEY = 'dm_events_settings';
    
    /**
     * Settings page slug
     */
    const PAGE_SLUG = 'dm-events-settings';
    
    /**
     * Default settings values
     */
    private $defaults = array(
        'include_in_archives' => false,
        'include_in_search' => true,
        'use_events_page' => true,
        'default_calendar_view' => 'month',
        'events_per_page' => 12
    );
    
    /**
     * Initialize the settings page
     */
    public function __construct() {
        add_action('admin_init', array($this, 'init_settings'));
        add_filter('dm_events_post_type_menu_items', array($this, 'add_settings_menu_item'));
        
        // Archive query control
        add_action('pre_get_posts', array($this, 'control_archive_queries'));
    }
    
    /**
     * Add settings menu item to events post type menu
     *
     * @param array $allowed_items Current allowed menu items
     * @return array Modified allowed menu items
     */
    public function add_settings_menu_item($allowed_items) {
        $allowed_items['settings'] = array(
            'type' => 'submenu',
            'callback' => array($this, 'add_settings_submenu')
        );
        
        return $allowed_items;
    }
    
    /**
     * Add settings submenu page
     */
    public function add_settings_submenu() {
        add_submenu_page(
            'edit.php?post_type=dm_events',
            __('Event Settings', 'dm-events'),
            __('Settings', 'dm-events'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Initialize WordPress settings API
     */
    public function init_settings() {
        register_setting(
            'dm_events_settings_group',
            self::OPTION_KEY,
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->defaults
            )
        );
    }
    
    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $template_path = DM_EVENTS_PLUGIN_DIR . 'templates/admin/settings-page.php';
        if (file_exists($template_path)) {
            include $template_path;
        }
    }
    
    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Boolean settings
        $boolean_fields = array('include_in_archives', 'include_in_search', 'use_events_page');
        foreach ($boolean_fields as $field) {
            $sanitized[$field] = !empty($input[$field]);
        }
        
        // Calendar view setting
        $allowed_views = array('month', 'list', 'grid');
        $sanitized['default_calendar_view'] = in_array($input['default_calendar_view'], $allowed_views) 
            ? $input['default_calendar_view'] 
            : 'month';
        
        // Events per page (numeric)
        $sanitized['events_per_page'] = max(1, min(100, (int) $input['events_per_page']));
        
        return $sanitized;
    }
    
    /**
     * Control archive queries based on settings
     */
    public function control_archive_queries($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $settings = get_option(self::OPTION_KEY, $this->defaults);
        
        // Control search inclusion
        if ($query->is_search() && !$settings['include_in_search']) {
            $post_types = $query->get('post_type');
            if (empty($post_types)) {
                $post_types = array('post', 'page');
            } elseif (is_string($post_types)) {
                $post_types = array($post_types);
            }
            
            $post_types = array_diff($post_types, array('dm_events'));
            $query->set('post_type', $post_types);
        }
        
        // Control archive inclusion
        if (!$settings['include_in_archives']) {
            if ($query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() || $query->is_home()) {
                $post_types = $query->get('post_type');
                if (empty($post_types)) {
                    $post_types = array('post');
                } elseif (is_string($post_types)) {
                    $post_types = array($post_types);
                }
                
                $post_types = array_diff($post_types, array('dm_events'));
                $query->set('post_type', $post_types);
            }
        }
    }
    
    /**
     * Get a specific setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return mixed Setting value
     */
    public static function get_setting($key, $default = null) {
        $instance = new self();
        $settings = get_option(self::OPTION_KEY, $instance->defaults);
        
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        
        if ($default !== null) {
            return $default;
        }
        
        return isset($instance->defaults[$key]) ? $instance->defaults[$key] : null;
    }
}