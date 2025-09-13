<?php
/**
 * Template Loader for Calendar Block
 *
 * @package DmEvents\Blocks\Calendar
 */

namespace DmEvents\Blocks\Calendar;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template loading and rendering system
 */
class Template_Loader {
    
    private static $template_path = '';
    
    public static function init() {
        self::$template_path = plugin_dir_path(__FILE__) . 'templates/';
    }
    
    /**
     * Get template content with variable extraction and output buffering
     *
     * @param string $template_name Template filename without .php extension
     * @param array $variables Variables to extract into template scope
     * @return string Rendered template content
     */
    public static function get_template($template_name, $variables = []) {
        $template_file = self::$template_path . $template_name . '.php';
        
        if (!file_exists($template_file)) {
            return sprintf(
                '<!-- Template not found: %s -->',
                esc_html($template_name)
            );
        }
        
        if (!empty($variables)) {
            extract($variables, EXTR_SKIP);
        }
        ob_start();
        include $template_file;
        return ob_get_clean();
    }
    
    /**
     * Include template directly with echo output
     *
     * @param string $template_name Template filename without .php extension
     * @param array $variables Variables to extract into template scope
     */
    public static function include_template($template_name, $variables = []) {
        echo self::get_template($template_name, $variables);
    }
    
    public static function template_exists($template_name) {
        $template_file = self::$template_path . $template_name . '.php';
        return file_exists($template_file);
    }

    public static function get_template_path() {
        return self::$template_path;
    }
}