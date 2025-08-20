<?php
/**
 * Admin functionality for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin functionality class
 * 
 * Handles admin interface components including:
 * - Settings page for display configuration
 * - Event Details block settings
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add admin menu pages
     * 
     * WordPress Admin Menu Structure:
     * Events (chill_events post type)
     * ├─ All Events
     * ├─ Add New
     * └─ Settings
     * 
     * @since 1.0.0
     */
    public function add_admin_menu() {
        // Settings - under Events post type menu
        add_submenu_page(
            'edit.php?post_type=chill_events',
            __('Settings', 'chill-events'),
            __('Settings', 'chill-events'),
            'manage_options',
            'chill-events-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'chill-events') === false) {
            return;
        }
        
        // Currently no custom scripts needed for settings page
    }
    
    /**
     * Initialize admin settings
     * 
     * @since 1.0.0
     */
    public function admin_init() {
        // Register settings for the main settings page
        $this->register_main_settings();
        
        // Migrate settings if needed
        $this->migrate_settings();
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
        settings_fields('chill_events_settings_group');
        
        // Output setting sections and fields
        do_settings_sections('chill-events-settings');
        
        // Output save settings button
        submit_button(__('Save Settings', 'chill-events'));
        
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
            'chill_events_settings_group',
            'chill_events_settings',
            array($this, 'sanitize_settings')
        );

        // Event Details Block Settings Section
        add_settings_section(
            'chill_events_block_section',
            __('Event Details Display Settings', 'chill-events'),
            array($this, 'render_block_section_description'),
            'chill-events-settings'
        );

        // Block Display Settings
        add_settings_field(
            'block_show_venue',
            __('Show Venue Field', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_block_section',
            [
                'name' => 'block_show_venue',
                'label' => __('Display venue information in Event Details blocks.', 'chill-events'),
            ]
        );

        add_settings_field(
            'block_show_artist',
            __('Show Artist Field', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_block_section',
            [
                'name' => 'block_show_artist',
                'label' => __('Display artist/performer information in Event Details blocks.', 'chill-events'),
            ]
        );

        add_settings_field(
            'block_show_price',
            __('Show Price Field', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_block_section',
            [
                'name' => 'block_show_price',
                'label' => __('Display price information in Event Details blocks.', 'chill-events'),
            ]
        );

        add_settings_field(
            'block_show_ticket_link',
            __('Show Ticket Link', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_block_section',
            [
                'name' => 'block_show_ticket_link',
                'label' => __('Display ticket purchase links in Event Details blocks.', 'chill-events'),
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
        $options = get_option('chill_events_settings');
        $checked = !empty($options[$args['name']]);
        echo '<label><input type="checkbox" name="chill_events_settings[' . esc_attr($args['name']) . ']" value="1"' . checked($checked, true, false) . '> ' . esc_html($args['label']) . '</label>';
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
        echo '<p>' . __('Control which fields are imported and displayed in Event Details blocks. This determines both what data gets imported from event sources and what fields appear in your event blocks.', 'chill-events') . '</p>';
        echo '<p><strong>' . __('Simplified Calendar:', 'chill-events') . '</strong> ' . __('Disable fields you don\'t need to create a cleaner, simpler event system.', 'chill-events') . '</p>';
        echo '<p><strong>' . __('Note:', 'chill-events') . '</strong> ' . __('Date/time and description are always imported. Users can override display settings per-block in the editor.', 'chill-events') . '</p>';
    }

    /**
     * Migrate existing settings to include new block display settings
     */
    public function migrate_settings() {
        $existing_settings = get_option('chill_events_settings', array());
        
        // Block display settings (default to showing all fields)
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

    /**
     * Sanitize and validate settings
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $output = get_option('chill_events_settings', array());
        
        // Block display settings
        $output['block_show_venue'] = !empty($input['block_show_venue']) ? 1 : 0;
        $output['block_show_artist'] = !empty($input['block_show_artist']) ? 1 : 0;
        $output['block_show_price'] = !empty($input['block_show_price']) ? 1 : 0;
        $output['block_show_ticket_link'] = !empty($input['block_show_ticket_link']) ? 1 : 0;
        
        return $output;
    }
} 