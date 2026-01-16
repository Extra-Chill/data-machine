<?php
/**
 * Events Settings Page
 *
 * @package DataMachineEvents
 * @subpackage Admin
 */

namespace DataMachineEvents\Admin;

use DataMachineEvents\Core\Event_Post_Type;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings interface for archive behavior and display preferences
 */
class Settings_Page {
    
    const OPTION_KEY = 'datamachine_events_settings';
    
    const PAGE_SLUG = 'datamachine-events-settings';
    
    private $defaults = array(
        'include_in_archives' => false,
        'include_in_search' => true,
        'main_events_page_url' => '',
        'map_display_type' => 'osm-standard',
        'geonames_username' => '',
        'next_day_cutoff' => '05:00'
    );
    
    public function __construct() {
        add_action('admin_init', array($this, 'init_settings'));
        add_filter('datamachine_events_post_type_menu_items', array($this, 'add_settings_menu_item'));

        add_action('pre_get_posts', array($this, 'control_archive_queries'));

        // Register filter for theme integration
        add_filter('datamachine_events_main_page_url', array($this, 'provide_main_events_url'));
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
            'edit.php?post_type=' . Event_Post_Type::POST_TYPE,
            __('Event Settings', 'datamachine-events'),
            __('Settings', 'datamachine-events'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }
    
    public function init_settings() {
        register_setting(
            'datamachine_events_settings_group',
            self::OPTION_KEY,
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->defaults
            )
        );
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'datamachine-events'));
        }

        $template_path = DATAMACHINE_EVENTS_PLUGIN_DIR . 'templates/admin/settings-page.php';

        if (!file_exists($template_path)) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(sprintf(__('Settings template not found: %s', 'datamachine-events'), $template_path));
            echo '</p></div>';
            return;
        }

        include $template_path;
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

        // Map display type
        $allowed_map_types = array('osm-standard', 'carto-positron', 'carto-voyager', 'carto-dark', 'humanitarian');
        $sanitized['map_display_type'] = in_array($input['map_display_type'], $allowed_map_types)
            ? $input['map_display_type']
            : 'osm-standard';

        // GeoNames username
        $sanitized['geonames_username'] = !empty($input['geonames_username'])
            ? sanitize_text_field($input['geonames_username'])
            : '';

        // Next day cutoff time (HH:MM format)
        $sanitized['next_day_cutoff'] = !empty($input['next_day_cutoff'])
            ? sanitize_text_field($input['next_day_cutoff'])
            : '05:00';

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
            
            $post_types = array_diff($post_types, array(Event_Post_Type::POST_TYPE));
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
                
                $post_types = array_diff($post_types, array(Event_Post_Type::POST_TYPE));
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

    /**
     * Get map display type setting
     *
     * @return string Map display type (osm-standard, carto-positron, carto-voyager, carto-dark, humanitarian)
     */
    public static function get_map_display_type() {
        return self::get_setting('map_display_type', 'osm-standard');
    }

    /**
     * Get next day cutoff time setting
     *
     * Events ending before this time on the following day are treated as single-day events.
     *
     * @return string Time in HH:MM format (default: 05:00)
     */
    public static function get_next_day_cutoff(): string {
        return self::get_setting('next_day_cutoff', '05:00');
    }

    /**
     * Filter callback to provide main events URL to themes
     *
     * @param string $url Default URL (usually post type archive)
     * @return string Custom main events URL if configured, otherwise original URL
     */
    public function provide_main_events_url($url) {
        $custom_url = self::get_main_events_page_url();
        return !empty($custom_url) ? $custom_url : $url;
    }
}
