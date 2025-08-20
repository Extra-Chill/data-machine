<?php
/**
 * Encryption utility for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents\Utils;

if (!defined('ABSPATH')) {
    exit;
}

class Encryption {
    /**
     * Encrypt a value using WordPress AUTH_KEY
     *
     * @param string $value Value to encrypt
     * @return string|false Encrypted value or false on failure
     */
    public static function encrypt($value) {
        if (empty($value)) {
            return $value;
        }
        
        if (!defined('AUTH_KEY') || empty(AUTH_KEY)) {
            return false;
        }
        
        $key = hash('sha256', AUTH_KEY, true);
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encrypted === false) {
            return false;
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt a value using WordPress AUTH_KEY
     *
     * @param string $encrypted_value Encrypted value to decrypt
     * @return string|false Decrypted value or false on failure
     */
    public static function decrypt($encrypted_value) {
        if (empty($encrypted_value)) {
            return $encrypted_value;
        }
        
        if (!defined('AUTH_KEY') || empty(AUTH_KEY)) {
            return false;
        }
        
        $data = base64_decode($encrypted_value);
        
        if ($data === false || strlen($data) < 16) {
            return false;
        }
        
        $key = hash('sha256', AUTH_KEY, true);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted;
    }
} 