<?php
/**
 * Ticketmaster Authentication Provider
 * 
 * Handles authentication configuration for Ticketmaster Discovery API integration
 * with Data Machine's centralized authentication system.
 *
 * @package ChillEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport\Handlers\Ticketmaster;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TicketmasterAuth class
 * 
 * Authentication provider for Ticketmaster Discovery API.
 * Integrates with Data Machine's unified authentication system.
 */
class TicketmasterAuth {
    
    /**
     * Get configuration fields required for Ticketmaster authentication
     *
     * @return array Configuration field definitions
     */
    public function get_config_fields(): array {
        return [
            'api_key' => [
                'label' => __('API Key', 'chill-events'),
                'type' => 'password',
                'required' => true,
                'description' => __('Your Ticketmaster Discovery API Consumer Key from developer.ticketmaster.com', 'chill-events')
            ]
        ];
    }
    
    /**
     * Check if Ticketmaster authentication is properly configured
     *
     * @return bool True if API key is configured, false otherwise
     */
    public function is_configured(): bool {
        $config = apply_filters('dm_oauth', [], 'get_config', 'ticketmaster_events');
        return !empty($config['api_key']);
    }
    
    /**
     * Check if Ticketmaster is authenticated (same as configured for API key auth)
     *
     * @return bool True if API key is present, false otherwise
     */
    public function is_authenticated(): bool {
        return $this->is_configured();
    }
    
    /**
     * Get account details for display (API key type doesn't have account info)
     *
     * @return array|null Account details or null
     */
    public function get_account_details(): ?array {
        if (!$this->is_authenticated()) {
            return null;
        }
        
        return [
            'display_name' => __('Ticketmaster API', 'chill-events'),
            'type' => __('API Key Authentication', 'chill-events')
        ];
    }
}