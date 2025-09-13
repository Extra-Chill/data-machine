<?php
/**
 * Events Settings Page
 *
 * @package DmEvents
 * @subpackage Admin
 */

namespace DmEvents\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings interface for archive behavior and display preferences
 */
class Settings_Page {
    
    const OPTION_KEY = 'dm_events_settings';
    
    const PAGE_SLUG = 'dm-events-settings';
    
    private $defaults = array(
        'include_in_archives' => false,
        'include_in_search' => true,
        'main_events_page_url' => '',
        'calendar_display_type' => 'circuit-grid'
    );
    
    public function __construct() {
        add_action('admin_init', array($this, 'init_settings'));
        add_filter('dm_events_post_type_menu_items', array($this, 'add_settings_menu_item'));
        
        add_action('pre_get_posts', array($this, 'control_archive_queries'));
    }
    
    public function add_settings_menu_item($allowed_items) {
        $allowed_items['settings'] = array(
            'type' => 'submenu',
            'callback' => array($this, 'add_settings_submenu')
        );

        return $allowed_items;
    }
    
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
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Boolean settings
        $boolean_fields = array('include_in_archives', 'include_in_search');
        foreach ($boolean_fields as $field) {
            $sanitized[$field] = !empty($input[$field]);
        }
        
        // Main events page URL
        $sanitized['main_events_page_url'] = !empty($input['main_events_page_url']) 
            ? esc_url_raw($input['main_events_page_url']) 
            : '';
        
        // Calendar display type
        $allowed_display_types = array('circuit-grid', 'carousel-list');
        $sanitized['calendar_display_type'] = in_array($input['calendar_display_type'], $allowed_display_types) 
            ? $input['calendar_display_type'] 
            : 'circuit-grid';
        
        return $sanitized;
    }
    
    /**
     * @param WP_Query $query
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
    
    /**
     * @return string
     */
    public static function get_main_events_page_url() {
        return self::get_setting('main_events_page_url', '');
    }
}