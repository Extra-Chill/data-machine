<?php
// Test bootstrap for unit tests: provide minimal WP helper fallbacks and load composer autoload

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal fallback for esc_url_raw
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        // Return a simple sanitized URL
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return esc_url_raw($url);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim(filter_var($text, FILTER_UNSAFE_RAW));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) {
        return sanitize_text_field($text);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $options = 0) {
        return json_encode($value, $options);
    }
}

if (!function_exists('do_action')) {
    function do_action() {
        return null;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($name, $value) {
        return $value;
    }
}


// Autoload our classes from inc/
$loader = require_once __DIR__ . '/../vendor/autoload.php';
