<?php
/**
 * Admin functionality for Data Machine Events
 *
 * @package DmEvents
 * @since 1.0.0
 */

namespace DmEvents;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface for Data Machine Events plugin
 * 
 * Manages Event Details block display settings through WordPress admin settings API.
 * Provides per-field visibility controls for venue, artist, price, and ticket link fields.
 * 
 * @since 1.0.0
 */
class Admin {
    
    /**
     * Initialize admin functionality
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Register settings page via extensible filter system
        add_filter('dm_events_post_type_menu_items', array($this, 'register_settings_menu'));
        add_action('admin_init', array($this, 'admin_init'));
    }
    
    /**
     * Register settings menu via extensible filter system
     * 
     * @param array $menu_items Current menu items
     * @return array Modified menu items with settings page
     * @since 1.0.0
     */
    public function register_settings_menu($menu_items) {
        $menu_items['settings'] = array(
            'type' => 'submenu',
            'callback' => array($this, 'add_settings_submenu')
        );
        return $menu_items;
    }
    
    /**
     * Add settings submenu page
     * 
     * Called by the extensible menu system when settings should be registered.
     * 
     * @since 1.0.0
     */
    public function add_settings_submenu() {
        add_submenu_page(
            'edit.php?post_type=dm_events',
            __('Settings', 'dm-events'),
            __('Settings', 'dm-events'),
            'manage_options',
            'dm-events-settings',
            array($this, 'settings_page')
        );
    }
    
    
    /**
     * Initialize admin settings
     * 
     * @since 1.0.0
     */
    public function admin_init() {
        // Register settings for the main settings page
        $this->register_main_settings();
    }
    
    
    /**
     * Settings page
     * 
     * Displays block display settings for Event Details blocks.
     * 
     * @since 1.0.0
     */
    public function settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form action="options.php" method="post">';
        
        // Output security fields for the registered setting
        settings_fields('dm_events_settings_group');
        
        // Output setting sections and fields
        do_settings_sections('dm-events-settings');
        
        // Output save settings button
        submit_button(__('Save Settings', 'dm-events'));
        
        echo '</form>';
        echo '</div>';
    }
    

    /**
     * Register main plugin settings
     *
     * @since 1.0.0
     */
    public function register_main_settings() {
        // Register the main setting group
        register_setting(
            'dm_events_settings_group',
            'dm_events_settings',
            array($this, 'sanitize_settings')
        );

        // Event Details Block Settings Section
        add_settings_section(
            'dm_events_block_section',
            __('Event Details Display Settings', 'dm-events'),
            array($this, 'render_block_section_description'),
            'dm-events-settings'
        );

        // Block Display Settings
        add_settings_field(
            'block_show_venue',
            __('Show Venue Field', 'dm-events'),
            array($this, 'render_checkbox_field'),
            'dm-events-settings',
            'dm_events_block_section',
            [
                'name' => 'block_show_venue',
                'label' => __('Display venue information in Event Details blocks.', 'dm-events'),
            ]
        );

        add_settings_field(
            'block_show_artist',
            __('Show Artist Field', 'dm-events'),
            array($this, 'render_checkbox_field'),
            'dm-events-settings',
            'dm_events_block_section',
            [
                'name' => 'block_show_artist',
                'label' => __('Display artist/performer information in Event Details blocks.', 'dm-events'),
            ]
        );

        add_settings_field(
            'block_show_price',
            __('Show Price Field', 'dm-events'),
            array($this, 'render_checkbox_field'),
            'dm-events-settings',
            'dm_events_block_section',
            [
                'name' => 'block_show_price',
                'label' => __('Display price information in Event Details blocks.', 'dm-events'),
            ]
        );

        add_settings_field(
            'block_show_ticket_link',
            __('Show Ticket Link', 'dm-events'),
            array($this, 'render_checkbox_field'),
            'dm-events-settings',
            'dm_events_block_section',
            [
                'name' => 'block_show_ticket_link',
                'label' => __('Display ticket purchase links in Event Details blocks.', 'dm-events'),
            ]
        );


    }

    /*
    |--------------------------------------------------------------------------
    | Settings Field Renderers
    |--------------------------------------------------------------------------
    */
    
    /**
     * Render a checkbox field
     * @param array $args
     */
    public function render_checkbox_field($args) {
        $options = get_option('dm_events_settings');
        $checked = !empty($options[$args['name']]);
        echo '<label><input type="checkbox" name="dm_events_settings[' . esc_attr($args['name']) . ']" value="1"' . checked($checked, true, false) . '> ' . esc_html($args['label']) . '</label>';
    }
    
    /*
    |--------------------------------------------------------------------------
    | Settings Sanitization
    |--------------------------------------------------------------------------
    */
    
    /**
     * Render block section description
     */
    public function render_block_section_description() {
        echo '<p>' . __('Control which fields are displayed in Event Details blocks. This determines what fields appear in your event blocks by default.', 'dm-events') . '</p>';
        echo '<p><strong>' . __('Simplified Calendar:', 'dm-events') . '</strong> ' . __('Disable fields you don\'t need to create a cleaner, simpler event system.', 'dm-events') . '</p>';
        echo '<p><strong>' . __('Note:', 'dm-events') . '</strong> ' . __('Date/time and description are always available. Users can override display settings per-block in the editor.', 'dm-events') . '</p>';
    }


    /**
     * Sanitize and validate settings
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $output = get_option('dm_events_settings', array());
        
        // Block display settings
        $output['block_show_venue'] = !empty($input['block_show_venue']) ? 1 : 0;
        $output['block_show_artist'] = !empty($input['block_show_artist']) ? 1 : 0;
        $output['block_show_price'] = !empty($input['block_show_price']) ? 1 : 0;
        $output['block_show_ticket_link'] = !empty($input['block_show_ticket_link']) ? 1 : 0;
        
        return $output;
    }
} 