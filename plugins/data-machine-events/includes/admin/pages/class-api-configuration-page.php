<?php
/**
 * API Configuration Admin Page for Chill Events
 *
 * Handles API key configuration for various data sources.
 *
 * @package ChillEvents
 * @author Chris Huber
 * @link https://chubes.net
 * @since 1.0.0
 */

namespace ChillEvents\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

class ApiConfigurationPage extends BaseAdminPage {
    
    /**
     * Initialize page properties
     */
    protected function init() {
        $this->page_slug = 'chill-events-api';
        $this->page_title = __('API Configuration', 'chill-events');
        $this->menu_title = __('API Configuration', 'chill-events');
        $this->parent_slug = 'chill-events';
    }
    
    /**
     * Handle admin_init actions
     */
    public function admin_init() {
        // Register settings for API configuration
        register_setting('chill_events_api', 'chill_events_api_settings', array($this, 'sanitize_api_settings'));
        
        // Add settings sections
        add_settings_section(
            'chill_api_keys',
            __('API Keys', 'chill-events'),
            array($this, 'api_keys_section_callback'),
            'chill_events_api'
        );
        
        // Add individual API key fields
        $this->add_api_key_fields();
    }
    
    /**
     * Add API key fields for each data source
     */
    private function add_api_key_fields() {
        $data_sources = \ChillEvents\DataSourceManager::get_available_data_sources();
        
        foreach ($data_sources as $source) {
            if (isset($source['requires_api_key']) && $source['requires_api_key']) {
                add_settings_field(
                    $source['slug'] . '_api_key',
                    $source['name'] . ' ' . __('API Key', 'chill-events'),
                    array($this, 'render_api_key_field'),
                    'chill_events_api',
                    'chill_api_keys',
                    array(
                        'slug' => $source['slug'],
                        'name' => $source['name'],
                        'description' => isset($source['api_key_description']) ? $source['api_key_description'] : ''
                    )
                );
            }
        }
    }
    
    /**
     * Render the admin page
     */
    public function render() {
        if (!$this->can_access()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chill-events'));
        }
        
        $this->render_header();
        $this->render_notices();
        $this->render_content();
        $this->render_footer();
    }
    
    /**
     * Render main page content
     */
    private function render_content() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('chill_events_api');
            do_settings_sections('chill_events_api');
            submit_button();
            ?>
        </form>
        
        <div class="chill-api-help">
            <h2><?php _e('Getting API Keys', 'chill-events'); ?></h2>
            <div class="chill-api-providers">
                <?php $this->render_api_provider_help(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render admin notices for this page
     */
    protected function render_notices() {
        // Handle WordPress settings saved message
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . __('API Configuration updated successfully!', 'chill-events') . '</strong></p>';
            echo '<p>' . __('All API keys have been encrypted and saved securely.', 'chill-events') . '</p>';
            echo '</div>';
        }
        
        // Call parent method for other notices
        parent::render_notices();
    }
    
    /**
     * API keys section callback
     */
    public function api_keys_section_callback() {
        echo '<p>' . __('Configure API keys for external data sources. All API keys are encrypted before storage.', 'chill-events') . '</p>';
    }
    
    /**
     * Render API key field
     */
    public function render_api_key_field($args) {
        $slug = $args['slug'];
        $name = $args['name'];
        $description = $args['description'];
        
        $options = get_option('chill_events_api_settings', array());
        $value = isset($options[$slug]['api_key']) ? $options[$slug]['api_key'] : '';
        
        // Check if key is encrypted (has our prefix)
        $is_encrypted = strpos($value, 'chill_encrypted_') === 0;
        $field_name = 'chill_events_api_settings[' . esc_attr($slug) . '_api_key]';
        
        // Use placeholder for encrypted keys, empty for new keys
        $display_value = '';
        $placeholder = $is_encrypted ? __('Enter new API key to replace existing', 'chill-events') : __('Enter API key', 'chill-events');
        
        echo '<input type="password" id="' . esc_attr($slug) . '_api_key" name="' . $field_name . '" value="' . esc_attr($display_value) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="off">';
        
        // Hidden field to track if we have an existing key
        if ($is_encrypted) {
            echo '<input type="hidden" name="chill_events_api_settings[' . esc_attr($slug) . '_api_key_exists]" value="1">';
            echo '<p class="description"><span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' . __('API key is configured and encrypted.', 'chill-events') . ' ' . __('Leave field empty to keep current key, or enter a new key to replace it.', 'chill-events') . '</p>';
        }
        
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }
    
    /**
     * Render help for API providers
     */
    private function render_api_provider_help() {
        $providers = array(
            'ticketmaster' => array(
                'name' => 'Ticketmaster',
                'url' => 'https://developer.ticketmaster.com/products-and-docs/apis/getting-started/',
                'steps' => array(
                    __('Create a Ticketmaster Developer account', 'chill-events'),
                    __('Register your application', 'chill-events'),
                    __('Copy your Consumer Key (API Key)', 'chill-events'),
                    __('Paste it in the Ticketmaster API Key field above', 'chill-events')
                )
            ),
            'eventbrite' => array(
                'name' => 'Eventbrite',
                'url' => 'https://www.eventbrite.com/platform/api',
                'steps' => array(
                    __('Create an Eventbrite account', 'chill-events'),
                    __('Go to Account Settings > Developer Links', 'chill-events'),
                    __('Create a new API key or use an existing one', 'chill-events'),
                    __('Copy your Private Token', 'chill-events'),
                    __('Paste it in the Eventbrite API Key field above', 'chill-events')
                )
            )
        );
        
        foreach ($providers as $slug => $provider) {
            ?>
            <div class="chill-api-provider">
                <h3>
                    <?php echo esc_html($provider['name']); ?>
                    <a href="<?php echo esc_url($provider['url']); ?>" target="_blank" class="external-link">
                        <?php _e('Get API Key', 'chill-events'); ?>
                        <span class="dashicons dashicons-external"></span>
                    </a>
                </h3>
                <ol>
                    <?php foreach ($provider['steps'] as $step): ?>
                        <li><?php echo esc_html($step); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php
        }
    }
    
    /**
     * Sanitize API settings
     */
    public function sanitize_api_settings($input) {
        $sanitized = array();
        if (!is_array($input)) {
            return $sanitized;
        }
        
        // Get existing settings to preserve encrypted keys when not changed
        $existing = get_option('chill_events_api_settings', array());
        
        foreach ($input as $key => $value) {
            if (strpos($key, '_api_key') !== false && strpos($key, '_api_key_exists') === false) {
                // This is an API key field
                $service = str_replace('_api_key', '', $key);
                $exists_key = $service . '_api_key_exists';
                
                if (empty($value) && isset($input[$exists_key])) {
                    // Field is empty but we have an existing key - keep the existing encrypted key
                    if (isset($existing[$service]['api_key'])) {
                        $sanitized[$service]['api_key'] = $existing[$service]['api_key'];
                    }
                } elseif (!empty($value)) {
                    // Field has a value - encrypt and save the new key
                    $sanitized[$service]['api_key'] = \ChillEvents\Utils\Encryption::encrypt(sanitize_text_field($value));
                }
                // If field is empty and no _exists flag, no key is configured (don't add to sanitized)
            } elseif (strpos($key, '_api_key_exists') === false) {
                // Not an API key field or _exists flag, sanitize normally
                $sanitized[$key] = sanitize_text_field($value);
            }
            // Skip _api_key_exists fields as they're just for tracking
        }
        
        return $sanitized;
    }
} 