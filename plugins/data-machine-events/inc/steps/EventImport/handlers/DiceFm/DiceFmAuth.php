<?php
/**
 * Dice.fm Authentication Provider
 * 
 * Handles authentication configuration for Dice.fm API integration
 * with Data Machine's centralized authentication system.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DiceFmAuth class
 * 
 * Authentication provider for Dice.fm API.
 * Integrates with Data Machine's unified authentication system.
 */
class DiceFmAuth {
    
    /**
     * Get configuration fields required for Dice.fm authentication
     *
     * @return array Configuration field definitions
     */
    public function get_config_fields(): array {
        return [
            'api_key' => [
                'label' => __('API Key', 'datamachine-events'),
                'type' => 'password',
                'required' => true,
                'description' => __('Your Dice.fm API key from dice.fm developer portal', 'datamachine-events')
            ],
            'partner_id' => [
                'label' => __('Partner ID', 'datamachine-events'),
                'type' => 'text',
                'required' => false,
                'description' => __('Optional Partner ID for enhanced API access', 'datamachine-events')
            ]
        ];
    }
    
    /**
     * Check if Dice.fm authentication is properly configured
     *
     * @return bool True if API key is configured, false otherwise
     */
    public function is_configured(): bool {
        $config = apply_filters('datamachine_retrieve_oauth_keys', [], 'dice_fm_events');
        return !empty($config['api_key']);
    }
    
    /**
     * Check if Dice.fm is authenticated (same as configured for API key auth)
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
        
        $config = apply_filters('datamachine_retrieve_oauth_keys', [], 'dice_fm_events');
        $details = [
            'display_name' => __('Dice.fm API', 'datamachine-events'),
            'type' => __('API Key Authentication', 'datamachine-events')
        ];
        
        if (!empty($config['partner_id'])) {
            $details['partner_id'] = $config['partner_id'];
        }
        
        return $details;
    }
}