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
 * Handles all admin interface components including:
 * - Import Modules dashboard (Phase 8-9)
 * - Settings page (Phase 6)
 * - API Configuration (Phase 6)
 * - Import Logs (Phase 10)
 * 
 * @since 1.0.0
 */
class Admin {
    
    /**
     * @var \ChillEvents\Admin\Pages\ImportModulesPage
     */
    private $import_modules_page;
    /**
     * @var \ChillEvents\Admin\Pages\ApiConfigurationPage
     */
    private $api_configuration_page;
    
    /**
     * Initialize admin functionality
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Instantiate admin page objects
        $this->import_modules_page = new \ChillEvents\Admin\Pages\ImportModulesPage();
        $this->api_configuration_page = new \ChillEvents\Admin\Pages\ApiConfigurationPage();
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // Register Import Module AJAX handlers
        require_once CHILL_EVENTS_PLUGIN_DIR . 'includes/admin/class-ajax-import-module.php';
        new \ChillEvents\Admin\AjaxImportModule();
    }
    
    /**
     * Add admin menu pages
     * 
     * WordPress Admin Menu Structure:
     * Chill Events
     * ├─ Import Modules (primary interface)
     * ├─ API Configuration
     * ├─ Settings  
     * └─ Import Logs
     * 
     * @since 1.0.0
     */
    public function add_admin_menu() {
        // Main menu page - Import Modules (primary interface)
        add_menu_page(
            __('Chill Events', 'chill-events'),
            __('Chill Events', 'chill-events'),
            'manage_options',
            'chill-events',
            array($this->import_modules_page, 'render'),
            'dashicons-calendar-alt',
            25
        );
        
        // Import Modules (same as main page)
        add_submenu_page(
            'chill-events',
            __('Import Modules', 'chill-events'),
            __('Import Modules', 'chill-events'),
            'manage_options',
            'chill-events',
            array($this->import_modules_page, 'render')
        );
        
        // API Configuration
        add_submenu_page(
            'chill-events',
            __('API Configuration', 'chill-events'),
            __('API Configuration', 'chill-events'),
            'manage_options',
            'chill-events-api',
            array($this->api_configuration_page, 'render')
        );
        
        // Settings
        add_submenu_page(
            'chill-events',
            __('Settings', 'chill-events'),
            __('Settings', 'chill-events'),
            'manage_options',
            'chill-events-settings',
            array($this, 'settings_page')
        );
        
        // Import Logs
        add_submenu_page(
            'chill-events',
            __('Import Logs', 'chill-events'),
            __('Import Logs', 'chill-events'),
            'manage_options',
            'chill-events-logs',
            array($this, 'import_logs_page')
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
        
        // Determine which page we're on and enqueue appropriate scripts
        if ($hook === 'toplevel_page_chill-events' || $hook === 'chill-events_page_chill-events') {
            // Import Modules page
            $this->import_modules_page->enqueue_scripts();
        }
        
        // API Configuration page doesn't need custom scripts currently
    }
    
    /**
     * Initialize admin settings
     * 
     * @since 1.0.0
     */
    public function admin_init() {
        // Register settings for the main settings page
        $this->register_main_settings();
        
        // Handle database repair action
        if (isset($_GET['chill_action']) && $_GET['chill_action'] === 'repair_database' && 
            isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'chill_repair_database')) {
            
            if (current_user_can('manage_options')) {
                \ChillEvents\Database::force_recreate_tables();
                wp_redirect(admin_url('admin.php?page=chill-events&database_repaired=1'));
                exit;
            }
        }
    }
    
    /**
     * Display admin notices
     * 
     * @since 1.0.0
     */
    public function admin_notices() {
        // Check if we're on a Chill Events page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'chill-events') === false) {
            return;
        }
        
        // Show success message after database repair
        if (isset($_GET['database_repaired']) && $_GET['database_repaired'] == '1') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . __('Database tables have been successfully recreated!', 'chill-events') . '</strong></p>';
            echo '</div>';
            return;
        }
        
        // Check if database structure is correct
        global $wpdb;
        $table = $wpdb->prefix . 'chill_import_modules';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('Chill Events Database Error:', 'chill-events') . '</strong> ' . __('Required database tables are missing.', 'chill-events') . '</p>';
            echo '<p><a href="' . wp_nonce_url(admin_url('admin.php?page=chill-events&chill_action=repair_database'), 'chill_repair_database') . '" class="button button-primary">' . __('Repair Database', 'chill-events') . '</a></p>';
            echo '</div>';
            return;
        }
        
        // Check if taxonomy_mappings column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'taxonomy_mappings'");
        if (empty($column_exists)) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('Chill Events Database Error:', 'chill-events') . '</strong> ' . __('Database structure is outdated or corrupted.', 'chill-events') . '</p>';
            echo '<p><a href="' . wp_nonce_url(admin_url('admin.php?page=chill-events&chill_action=repair_database'), 'chill_repair_database') . '" class="button button-primary">' . __('Repair Database', 'chill-events') . '</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Settings page
     * 
     * This will be implemented in Phase 6
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
     * Import Logs page
     * 
     * This will be implemented in Phase 10
     * 
     * @since 1.0.0
     */
    public function import_logs_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Import Logs & Analytics', 'chill-events') . '</h1>';
        echo '<p>' . __('Detailed import history and analytics will be implemented in Phase 10.', 'chill-events') . '</p>';
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

        // General Settings Section
        add_settings_section(
            'chill_events_general_section',
            __('General Settings', 'chill-events'),
            null,
            'chill-events-settings'
        );

        // Import Settings Section
        add_settings_section(
            'chill_events_import_section',
            __('Import Schedule Settings', 'chill-events'),
            null,
            'chill-events-settings'
        );

        add_settings_field(
            'import_schedule',
            __('Run Imports', 'chill-events'),
            array($this, 'render_select_field'),
            'chill-events-settings',
            'chill_events_import_section',
            [
                'name' => 'import_schedule',
                'options' => [
                    'manual_only' => __('Manual only', 'chill-events'),
                    'every_hour' => __('Every hour', 'chill-events'),
                    'every_3_hours' => __('Every 3 hours', 'chill-events'),
                    'every_6_hours' => __('Every 6 hours (recommended)', 'chill-events'),
                    'every_12_hours' => __('Every 12 hours', 'chill-events'),
                    'daily' => __('Daily', 'chill-events'),
                ],
                'description' => __('How often to run the global import job. Manual imports can still be run anytime.', 'chill-events'),
            ]
        );
        
        add_settings_field(
            'import_start_time',
            __('Daily Start Time', 'chill-events'),
            array($this, 'render_time_field'),
            'chill-events-settings',
            'chill_events_import_section',
            [
                'name' => 'import_start_time',
                'description' => __('If running daily, what time should the import start? (Uses server time).', 'chill-events'),
            ]
        );

        // Event Post Settings Section
        add_settings_section(
            'chill_events_post_section',
            __('Default Event Post Settings', 'chill-events'),
            null,
            'chill-events-settings'
        );

        add_settings_field(
            'post_status',
            __('Post Status', 'chill-events'),
            array($this, 'render_select_field'),
            'chill-events-settings',
            'chill_events_post_section',
            [
                'name' => 'post_status',
                'options' => [
                    'publish' => __('Publish', 'chill-events'),
                    'draft' => __('Draft', 'chill-events'),
                    'pending' => __('Pending Review', 'chill-events'),
                ],
                'description' => __('Default status for newly imported events.', 'chill-events'),
            ]
        );

        add_settings_field(
            'post_author',
            __('Post Author', 'chill-events'),
            array($this, 'render_author_field'),
            'chill-events-settings',
            'chill_events_post_section',
            [
                'name' => 'post_author',
                'description' => __('Default author for newly imported events.', 'chill-events'),
            ]
        );

        add_settings_field(
            'comment_status',
            __('Comment Status', 'chill-events'),
            array($this, 'render_select_field'),
            'chill-events-settings',
            'chill_events_post_section',
            [
                'name' => 'comment_status',
                'options' => [
                    'closed' => __('Closed', 'chill-events'),
                    'open' => __('Open', 'chill-events'),
                ],
                'description' => __('Default comment status for newly imported events.', 'chill-events'),
            ]
        );

        // Add Event Meta Fields Section
        add_settings_section(
            'chill_events_meta_section',
            __('Event Meta Fields', 'chill-events'),
            null,
            'chill-events-settings'
        );
        add_settings_field(
            'meta_ticket_url',
            __('Enable Ticket URL Field', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_meta_section',
            [
                'name' => 'meta_ticket_url',
                'label' => __('Save ticket URL meta for each event.', 'chill-events'),
            ]
        );
        add_settings_field(
            'meta_artist_name',
            __('Enable Artist/Performer Field', 'chill-events'),
            array($this, 'render_checkbox_field'),
            'chill-events-settings',
            'chill_events_meta_section',
            [
                'name' => 'meta_artist_name',
                'label' => __('Save artist/performer name meta for each event.', 'chill-events'),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Settings Field Renderers
    |--------------------------------------------------------------------------
    */

    /**
     * Render a generic select field
     * @param array $args
     */
    public function render_select_field($args) {
        $options = get_option('chill_events_settings');
        $value = isset($options[$args['name']]) ? $options[$args['name']] : '';
        
        echo '<select id="' . esc_attr($args['name']) . '" name="chill_events_settings[' . esc_attr($args['name']) . ']">';
        foreach ($args['options'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render a time input field
     * @param array $args
     */
    public function render_time_field($args) {
        $options = get_option('chill_events_settings');
        $value = isset($options[$args['name']]) ? $options[$args['name']] : '02:00';
        
        echo '<input type="time" id="' . esc_attr($args['name']) . '" name="chill_events_settings[' . esc_attr($args['name']) . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render author dropdown field
     * @param array $args
     */
    public function render_author_field($args) {
        $options = get_option('chill_events_settings');
        $value = isset($options[$args['name']]) ? $options[$args['name']] : 1;
        
        wp_dropdown_users([
            'name' => 'chill_events_settings[' . esc_attr($args['name']) . ']',
            'selected' => $value,
            'role__in' => ['Administrator', 'Editor', 'Author'],
            'show_option_none' => __('Default to current user', 'chill-events'),
        ]);
        
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
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
     * Sanitize and validate settings
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $output = get_option('chill_events_settings');
        
        $output['import_schedule'] = isset($input['import_schedule']) ? sanitize_key($input['import_schedule']) : 'every_6_hours';
        $output['import_start_time'] = isset($input['import_start_time']) ? sanitize_text_field($input['import_start_time']) : '02:00';
        $output['post_status'] = isset($input['post_status']) && in_array($input['post_status'], ['publish', 'draft', 'pending']) ? $input['post_status'] : 'publish';
        $output['post_author'] = isset($input['post_author']) ? intval($input['post_author']) : 1;
        $output['comment_status'] = isset($input['comment_status']) && in_array($input['comment_status'], ['open', 'closed']) ? $input['comment_status'] : 'closed';
        // Meta field checkboxes
        $output['meta_ticket_url'] = !empty($input['meta_ticket_url']) ? 1 : 0;
        $output['meta_artist_name'] = !empty($input['meta_artist_name']) ? 1 : 0;
        
        // Handle schedule update
        if ($output['import_schedule'] !== get_option('chill_events_settings')['import_schedule']) {
            wp_clear_scheduled_hook('chill_events_import_cron');
            if ($output['import_schedule'] !== 'manual_only') {
                wp_schedule_event(strtotime($output['import_start_time']), $output['import_schedule'], 'chill_events_import_cron');
            }
        }
        
        return $output;
    }
} 