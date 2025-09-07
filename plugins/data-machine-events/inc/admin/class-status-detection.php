<?php
/**
 * Data Machine Events Status Detection
 *
 * Provides red/yellow/green status indicators for DM Events components.
 *
 * @package DmEvents\Admin
 */

namespace DmEvents\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Status detection handler for DM Events system components
 */
class Status_Detection {
    
    /**
     * Initialize status detection filters
     */
    public function __construct() {
        $this->register_filters();
    }
    
    /**
     * Register status detection filters with Data Machine
     */
    private function register_filters() {
        add_filter('dm_detect_status', array($this, 'detect_dm_events_handler_status'), 10, 3);
        
        add_filter('dm_detect_status', array($this, 'detect_import_handler_auth_status'), 5, 3);
    }
    
    /**
     * Detect DM Events handler status
     *
     * @param string $default_status Current status
     * @param string $context Status context
     * @param array $data Context data
     * @return string Status (red/yellow/green)
     */
    public function detect_dm_events_handler_status($default_status, $context, $data) {
        if ($context !== 'handler_auth') {
            return $default_status;
        }
        
        $handler_slug = $data['handler_slug'] ?? '';
        if ($handler_slug !== 'create_event') {
            return $default_status;
        }
        
        return $this->get_comprehensive_status();
    }
    
    /**
     * Detect import handler authentication status
     *
     * @param string $default_status Current status
     * @param string $context Status context
     * @param array $data Context data
     * @return string Status (red/yellow/green)
     */
    public function detect_import_handler_auth_status($default_status, $context, $data) {
        if ($context !== 'handler_auth') {
            return $default_status;
        }
        
        $handler_slug = $data['handler_slug'] ?? '';
        
        $dm_events_handlers = ['ticketmaster_events', 'dice_fm_events', 'web_scraper'];
        if (!in_array($handler_slug, $dm_events_handlers)) {
            return $default_status;
        }
        
        $all_auth_providers = apply_filters('dm_auth_providers', []);
        if (!isset($all_auth_providers[$handler_slug])) {
            return $default_status;
        }
        
        $auth_provider = $all_auth_providers[$handler_slug];
        
        if (method_exists($auth_provider, 'is_configured') && $auth_provider->is_configured()) {
            return 'green';
        }
        
        return 'yellow';
    }
    
    /**
     * Get comprehensive DM Events system status
     *
     * @return string Status (red/yellow/green)
     */
    private function get_comprehensive_status() {
        if (!class_exists('DataMachine\\Core\\DataMachine')) {
            return 'red';
        }
        
        if (!post_type_exists('dm_events')) {
            return 'red';
        }
        
        $handlers = apply_filters('dm_handlers', array());
        if (!isset($handlers['create_event'])) {
            return 'red';
        }
        
        if (!function_exists('register_block_type')) {
            return 'red';
        }
        
        $post_type_object = get_post_type_object('dm_events');
        if (!$post_type_object || !$post_type_object->public) {
            return 'yellow';
        }
        
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