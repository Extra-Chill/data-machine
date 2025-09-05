<?php
/**
 * Web Scraper Event Import Handler Settings
 * 
 * Defines settings fields for web scraper event import handler.
 * Dynamically discovers available scrapers and provides selection interface.
 *
 * @package DmEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport\Handlers\WebScraper;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WebScraperSettings class
 * 
 * Configuration fields for web scraper event import with dynamic scraper discovery.
 */
class WebScraperSettings {
    
    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }
    
    /**
     * Get settings fields for web scraper event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        // Get available scrapers dynamically
        $scraper_options = self::get_scraper_options();
        
        $fields = [
            'scraper_source' => [
                'type' => 'select',
                'label' => __('Scraper Source', 'dm-events'),
                'description' => __('Select the venue scraper to use for importing events.', 'dm-events'),
                'options' => $scraper_options,
                'required' => true,
            ]
        ];
        
        // Add scraper-specific settings if a scraper is selected
        $selected_scraper = $current_config['scraper_source'] ?? '';
        if (!empty($selected_scraper)) {
            $scraper_fields = self::get_scraper_specific_fields($selected_scraper, $current_config);
            $fields = array_merge($fields, $scraper_fields);
        }
        
        return $fields;
    }
    
    /**
     * Get available scraper options for select field
     * 
     * @return array Options array for select field
     */
    private static function get_scraper_options(): array {
        $scrapers = apply_filters('dm_web_scrapers', []);
        $options = [
            '' => __('-- Select a Scraper --', 'dm-events')
        ];
        
        foreach ($scrapers as $key => $class) {
            if (class_exists($class)) {
                try {
                    $instance = new $class();
                    if (method_exists($instance, 'get_info')) {
                        $info = $instance->get_info();
                        $options[$key] = $info['name'] ?? ucfirst(str_replace('-', ' ', $key));
                    } else {
                        $options[$key] = ucfirst(str_replace('-', ' ', $key));
                    }
                } catch (\Exception $e) {
                    // Skip scrapers that can't be instantiated
                    error_log('Failed to load scraper for settings: ' . $key . ' - ' . $e->getMessage());
                }
            }
        }
        
        return $options;
    }
    
    /**
     * Get scraper-specific settings fields
     * 
     * @param string $scraper_key Selected scraper key
     * @param array $current_config Current configuration
     * @return array Scraper-specific fields
     */
    private static function get_scraper_specific_fields(string $scraper_key, array $current_config): array {
        $scrapers = apply_filters('dm_web_scrapers', []);
        
        if (!isset($scrapers[$scraper_key])) {
            return [];
        }
        
        $scraper_class = $scrapers[$scraper_key];
        
        if (!class_exists($scraper_class)) {
            return [];
        }
        
        try {
            $instance = new $scraper_class();
            
            if (!method_exists($instance, 'get_info')) {
                return [];
            }
            
            $info = $instance->get_info();
            $settings_fields = $info['settings_fields'] ?? [];
            
            // Convert scraper settings to Data Machine format
            $fields = [];
            foreach ($settings_fields as $field_key => $field_config) {
                $fields[$field_key] = [
                    'type' => $field_config['type'] ?? 'text',
                    'label' => $field_config['label'] ?? ucfirst(str_replace('_', ' ', $field_key)),
                    'description' => $field_config['description'] ?? '',
                    'placeholder' => $field_config['placeholder'] ?? '',
                    'default' => $field_config['default'] ?? '',
                    'required' => $field_config['required'] ?? false,
                ];
                
                // Handle select field options
                if (isset($field_config['options'])) {
                    $fields[$field_key]['options'] = $field_config['options'];
                }
                
                // Handle number field constraints
                if (isset($field_config['min'])) {
                    $fields[$field_key]['min'] = $field_config['min'];
                }
                if (isset($field_config['max'])) {
                    $fields[$field_key]['max'] = $field_config['max'];
                }
            }
            
            return $fields;
            
        } catch (\Exception $e) {
            error_log('Failed to get scraper-specific fields: ' . $scraper_key . ' - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Sanitize Web Scraper handler settings.
     *
     * @param array $raw_settings Raw settings input.
     * @return array Sanitized settings.
     */
    public static function sanitize(array $raw_settings): array {
        $sanitized = [
            'scraper_source' => sanitize_text_field($raw_settings['scraper_source'] ?? '')
        ];
        
        // Sanitize scraper-specific fields
        $selected_scraper = $sanitized['scraper_source'];
        if (!empty($selected_scraper)) {
            $scraper_fields = self::get_scraper_specific_fields($selected_scraper, $raw_settings);
            
            // Sanitize each scraper-specific field based on its type
            foreach ($scraper_fields as $field_key => $field_config) {
                if (isset($raw_settings[$field_key])) {
                    $field_type = $field_config['type'] ?? 'text';
                    
                    switch ($field_type) {
                        case 'number':
                            $value = absint($raw_settings[$field_key]);
                            if (isset($field_config['min'])) {
                                $value = max($field_config['min'], $value);
                            }
                            if (isset($field_config['max'])) {
                                $value = min($field_config['max'], $value);
                            }
                            $sanitized[$field_key] = $value;
                            break;
                            
                        case 'checkbox':
                            $sanitized[$field_key] = !empty($raw_settings[$field_key]);
                            break;
                            
                        case 'select':
                            $value = sanitize_text_field($raw_settings[$field_key]);
                            // Validate against options if available
                            if (isset($field_config['options']) && !array_key_exists($value, $field_config['options'])) {
                                $value = $field_config['default'] ?? '';
                            }
                            $sanitized[$field_key] = $value;
                            break;
                            
                        default: // text, url, email, etc.
                            $sanitized[$field_key] = sanitize_text_field($raw_settings[$field_key]);
                            break;
                    }
                } else {
                    // Use default value if not provided
                    $sanitized[$field_key] = $field_config['default'] ?? '';
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Determine if authentication is required.
     *
     * @param array $current_config Current configuration values.
     * @return bool True if authentication is required.
     */
    public static function requires_authentication(array $current_config = []): bool {
        return false; // Web scrapers typically don't require authentication
    }
    
    /**
     * Get default values for all settings.
     *
     * @return array Default values.
     */
    public static function get_defaults(): array {
        return [
            'scraper_source' => ''
        ];
    }
}