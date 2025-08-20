<?php
/**
 * API Configuration management for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

use ChillEvents\Utils\Encryption;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ApiConfig class
 * 
 * Handles secure storage, retrieval, and management of API keys for all supported services.
 *
 * @since 1.0.0
 */
class ApiConfig {
    /**
     * Option key for storing API keys
     */
    const OPTION_KEY = 'chill_events_api_settings';

    /**
     * Get an API key or config value
     *
     * @param string $service Service name (e.g. 'ticketmaster')
     * @param string|null $key Specific key (e.g. 'api_key'), or null for all
     * @return mixed|null
     */
    public static function get($service, $key = null) {
        $all = get_option(self::OPTION_KEY, array());
        if (!isset($all[$service])) {
            return null;
        }
        if ($key === null) {
            // Decrypt all sensitive keys in the service config
            $decrypted = $all[$service];
            foreach (['api_key', 'client_secret', 'secret'] as $sensitive) {
                if (isset($decrypted[$sensitive])) {
                    $plain = Encryption::decrypt($decrypted[$sensitive]);
                    if ($plain === false) {
                        error_log('Chill Events: Failed to decrypt ' . $sensitive . ' for ' . $service);
                        $decrypted[$sensitive] = null;
                    } else {
                        $decrypted[$sensitive] = $plain;
                    }
                }
            }
            return $decrypted;
        }
        if (in_array($key, ['api_key', 'client_secret', 'secret'])) {
            $plain = Encryption::decrypt($all[$service][$key] ?? '');
            if ($plain === false) {
                error_log('Chill Events: Failed to decrypt ' . $key . ' for ' . $service);
                return null;
            }
            return $plain;
        }
        return isset($all[$service][$key]) ? $all[$service][$key] : null;
    }

    /**
     * Set an API key or config value
     *
     * @param string $service Service name
     * @param string $key Config key
     * @param string $value Value to set
     * @return void
     */
    public static function set($service, $key, $value) {
        $all = get_option(self::OPTION_KEY, array());
        if (!isset($all[$service])) {
            $all[$service] = array();
        }
        if (in_array($key, ['api_key', 'client_secret', 'secret'])) {
            $encrypted = Encryption::encrypt($value);
            if ($encrypted === false) {
                error_log('Chill Events: Failed to encrypt ' . $key . ' for ' . $service);
                return;
            }
            $all[$service][$key] = $encrypted;
        } else {
            $all[$service][$key] = $value;
        }
        update_option(self::OPTION_KEY, $all);
    }

    /**
     * Delete an API key/config for a service
     *
     * @param string $service Service name
     * @param string|null $key Config key, or null for all
     * @return void
     */
    public static function delete($service, $key = null) {
        $all = get_option(self::OPTION_KEY, array());
        if (!isset($all[$service])) {
            return;
        }
        if ($key === null) {
            unset($all[$service]);
        } else {
            unset($all[$service][$key]);
        }
        update_option(self::OPTION_KEY, $all);
    }

    /**
     * Get all API configs
     *
     * @return array
     */
    public static function get_all() {
        $all = get_option(self::OPTION_KEY, array());
        foreach ($all as $service => $config) {
            foreach (['api_key', 'client_secret', 'secret'] as $sensitive) {
                if (isset($config[$sensitive])) {
                    $plain = Encryption::decrypt($config[$sensitive]);
                    if ($plain === false) {
                        error_log('Chill Events: Failed to decrypt ' . $sensitive . ' for ' . $service);
                        $all[$service][$sensitive] = null;
                    } else {
                        $all[$service][$sensitive] = $plain;
                    }
                }
            }
        }
        return $all;
    }

    /**
     * Test API connection for a service (stub for now)
     *
     * @param string $service
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public static function test($service) {
        // In a real implementation, this would attempt a real API call
        $config = self::get($service);
        if (!$config || empty($config['api_key'])) {
            return array('success' => false, 'message' => 'API key not set.');
        }
        // For now, just check if the key is present
        return array('success' => true, 'message' => 'API key appears to be set.');
    }

    /**
     * Detect environment (dev, staging, production)
     *
     * @return string
     */
    public static function get_environment() {
        if (defined('WP_ENV')) {
            return WP_ENV;
        }
        $site_url = get_site_url();
        if (strpos($site_url, 'localhost') !== false || strpos($site_url, '.local') !== false) {
            return 'development';
        }
        if (strpos($site_url, 'staging') !== false) {
            return 'staging';
        }
        return 'production';
    }
} 