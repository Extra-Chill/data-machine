<?php
/**
 * Data Machine Events Status Detection
 *
 * Integrates with Data Machine's status detection system to provide
 * red/yellow/green status indicators for DM Events components.
 *
 * @package DmEvents\Admin
 * @since 1.0.0
 */

namespace DmEvents\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Status detection handler for DM Events system components
 * 
 * Provides status detection for:
 * - Post type registration (dm_events)
 * - Venue taxonomy and term meta
 * - Event Details blocks
 * - Data Machine integration
 * 
 * @since 1.0.0
 */
class Status_Detection {
    
    /**
     * Initialize status detection filters
     * 
     * @since 1.0.0
     */
    public function __construct() {
        $this->register_filters();
    }
    
    /**
     * Register status detection filters with Data Machine
     * 
     * @since 1.0.0
     */
    private function register_filters() {
        // Register DM Events handler status detection
        // Hook into the contexts that Data Machine actually calls
        add_filter('dm_detect_status', array($this, 'detect_dm_events_handler_status'), 10, 3);
        
        // Register import handlers auth status detection (prevent false reds)
        add_filter('dm_detect_status', array($this, 'detect_import_handler_auth_status'), 5, 3);
    }
    
    /**
     * Detect DM Events handler status
     * 
     * Responds to contexts that Data Machine actually calls, specifically
     * the 'handler_auth' context for create_event handler.
     * 
     * @param string $default_status Current status
     * @param string $context Status context
     * @param array $data Context data
     * @return string Status (red/yellow/green)
     */
    public function detect_dm_events_handler_status($default_status, $context, $data) {
        // Only respond to handler_auth context for create_event
        if ($context !== 'handler_auth') {
            return $default_status;
        }
        
        // Check if this is for the DM Events handler
        $handler_slug = $data['handler_slug'] ?? '';
        if ($handler_slug !== 'create_event') {
            return $default_status;
        }
        
        // Perform comprehensive DM Events system status check
        return $this->get_comprehensive_status();
    }
    
    /**
     * Detect import handler authentication status
     * 
     * Prevents import handlers from showing red when they just need configuration.
     * Red should only mean "impossible to configure", not "unconfigured".
     * 
     * @param string $default_status Current status
     * @param string $context Status context
     * @param array $data Context data
     * @return string Status (red/yellow/green)
     */
    public function detect_import_handler_auth_status($default_status, $context, $data) {
        // Only handle handler_auth context
        if ($context !== 'handler_auth') {
            return $default_status;
        }
        
        $handler_slug = $data['handler_slug'] ?? '';
        
        // Handle DM Events import handlers
        $dm_events_handlers = ['ticketmaster_events', 'dice_fm_events', 'web_scraper'];
        if (!in_array($handler_slug, $dm_events_handlers)) {
            return $default_status;
        }
        
        // Check if auth provider exists (this means it CAN be configured)
        $all_auth_providers = apply_filters('dm_auth_providers', []);
        if (!isset($all_auth_providers[$handler_slug])) {
            return $default_status; // No auth provider = doesn't need auth
        }
        
        // Get auth provider instance
        $auth_provider = $all_auth_providers[$handler_slug];
        
        // Check if it's configured
        if (method_exists($auth_provider, 'is_configured') && $auth_provider->is_configured()) {
            return 'green'; // Properly configured
        }
        
        // Not configured but CAN be configured = yellow, not red
        return 'yellow';
    }
    
    /**
     * Get comprehensive DM Events system status
     * 
     * Checks all critical DM Events components and returns the first
     * non-green status found, or green if everything is healthy.
     * 
     * @return string Status (red/yellow/green)
     */
    private function get_comprehensive_status() {
        // Check critical components (red status)
        
        // 1. Check if Data Machine is active
        if (!class_exists('DataMachine\\Core\\DataMachine')) {
            return 'red';
        }
        
        // 2. Check post type registration
        if (!post_type_exists('dm_events')) {
            return 'red';
        }
        
        // 3. Check DM Events publisher registration
        $handlers = apply_filters('dm_handlers', array());
        if (!isset($handlers['create_event'])) {
            return 'red';
        }
        
        // 4. Check if Gutenberg is available
        if (!function_exists('register_block_type')) {
            return 'red';
        }
        
        // Check warning components (yellow status)
        
        // 5. Check post type capabilities
        $post_type_object = get_post_type_object('dm_events');
        if (!$post_type_object || !$post_type_object->public) {
            return 'yellow';
        }
        
        // 6. Check Event Data Manager class
        if (!class_exists('DmEvents\\Events\\Event_Data_Manager')) {
            return 'yellow';
        }
        
        // 7. Check block assets existence
        $block_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'inc/blocks/event-details/';
        if (!file_exists($block_path . 'block.json')) {
            return 'yellow';
        }
        
        // 8. Check venue taxonomy and sample data quality
        $venues = get_terms(array(
            'taxonomy' => 'venue',
            'hide_empty' => false,
            'number' => 3
        ));
        
        if (is_wp_error($venues)) {
            return 'yellow';
        }
        
        // Check if existing venues have proper meta data
        if (!empty($venues)) {
            $sample_venue = $venues[0];
            $venue_meta = get_term_meta($sample_venue->term_id);
            
            // Yellow if venues exist but lack essential meta data
            if (empty($venue_meta) || !isset($venue_meta['address'])) {
                return 'yellow';
            }
        }
        
        // All checks passed
        return 'green';
    }
    
    /**
     * Check if DM Events system is fully ready
     * 
     * @return bool True if all components are green
     */
    public function is_system_ready() {
        return $this->get_comprehensive_status() === 'green';
    }
    
    /**
     * Get system status for debugging
     * 
     * @return array Status information
     */
    public function get_system_status() {
        $overall_status = $this->get_comprehensive_status();
        
        return array(
            'overall_status' => $overall_status,
            'dm_events_ready' => $overall_status === 'green',
            'timestamp' => current_time('mysql')
        );
    }
}