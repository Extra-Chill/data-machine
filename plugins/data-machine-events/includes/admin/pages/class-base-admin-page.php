<?php
/**
 * Base Admin Page for Chill Events
 *
 * Provides common functionality for all admin pages.
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

abstract class BaseAdminPage {
    
    /**
     * Page slug
     */
    protected $page_slug;
    
    /**
     * Page title
     */
    protected $page_title;
    
    /**
     * Menu title
     */
    protected $menu_title;
    
    /**
     * Parent slug (for subpages)
     */
    protected $parent_slug;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
        add_action('admin_init', array($this, 'admin_init'));
    }
    
    /**
     * Initialize page properties
     */
    abstract protected function init();
    
    /**
     * Render the admin page
     */
    abstract public function render();
    
    /**
     * Handle admin_init actions for this page
     */
    public function admin_init() {
        // Override in child classes if needed
    }
    
    /**
     * Check if user can access this page
     */
    protected function can_access() {
        return current_user_can('manage_options');
    }
    
    /**
     * Render page header
     */
    protected function render_header() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->page_title) . '</h1>';
    }
    
    /**
     * Render page footer
     */
    protected function render_footer() {
        echo '</div>';
    }
    
    /**
     * Enqueue page-specific scripts and styles
     */
    public function enqueue_scripts() {
        // Override in child classes
    }
    
    /**
     * Handle AJAX actions for this page
     */
    public function handle_ajax() {
        // Override in child classes if needed
    }
    
    /**
     * Render admin notices for this page
     */
    protected function render_notices() {
        // Check for any page-specific notices
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            switch ($message) {
                case 'saved':
                    echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'chill-events') . '</p></div>';
                    break;
                case 'error':
                    echo '<div class="notice notice-error is-dismissible"><p>' . __('An error occurred. Please try again.', 'chill-events') . '</p></div>';
                    break;
                case 'deleted':
                    echo '<div class="notice notice-success is-dismissible"><p>' . __('Item deleted successfully.', 'chill-events') . '</p></div>';
                    break;
            }
        }
    }
    
    /**
     * Get page URL
     */
    protected function get_page_url($args = array()) {
        $url = admin_url('admin.php?page=' . $this->page_slug);
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        return $url;
    }
    
    /**
     * Redirect to page with message
     */
    protected function redirect_with_message($message, $type = 'success') {
        $url = $this->get_page_url(array('message' => $message));
        wp_redirect($url);
        exit;
    }
} 