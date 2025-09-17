<?php
/**
 * DM Events Breadcrumb System
 *
 * Provides theme-agnostic breadcrumb functionality for DM Events single pages.
 * Themes can override the entire breadcrumb output via the 'dm_events_breadcrumbs' filter.
 *
 * @package DmEvents\Core
 */

namespace DmEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles breadcrumb generation for DM Events pages
 */
class Breadcrumbs {

    /**
     * Render breadcrumbs for an event post
     *
     * @param int|null $post_id Event post ID (defaults to current post)
     * @return string Breadcrumb HTML output
     */
    public static function render($post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if (!$post_id || get_post_type($post_id) !== 'dm_events') {
            return '';
        }

        // Allow themes to completely override breadcrumbs
        $custom_breadcrumbs = apply_filters('dm_events_breadcrumbs', null, $post_id);
        if ($custom_breadcrumbs !== null) {
            return $custom_breadcrumbs;
        }

        // Plugin's default breadcrumb implementation
        return self::generate_default_breadcrumbs($post_id);
    }

    /**
     * Generate default breadcrumb structure: Home › Events › Event Title
     *
     * @param int $post_id Event post ID
     * @return string Default breadcrumb HTML
     */
    private static function generate_default_breadcrumbs($post_id) {
        $home_url = home_url();
        $events_url = get_post_type_archive_link('dm_events');
        $event_title = get_the_title($post_id);

        // Use settings page URL if configured
        $main_events_url = \DmEvents\Admin\Settings_Page::get_main_events_page_url();
        if (!empty($main_events_url)) {
            $events_url = $main_events_url;
        }

        $breadcrumb_html = '<nav class="dm-events-breadcrumbs" aria-label="' . esc_attr__('Event Breadcrumb', 'dm-events') . '">';
        $breadcrumb_html .= '<a href="' . esc_url($home_url) . '">' . esc_html__('Home', 'dm-events') . '</a>';
        $breadcrumb_html .= ' › ';
        $breadcrumb_html .= '<a href="' . esc_url($events_url) . '">' . esc_html__('Events', 'dm-events') . '</a>';
        $breadcrumb_html .= ' › ';
        $breadcrumb_html .= '<span>' . esc_html($event_title) . '</span>';
        $breadcrumb_html .= '</nav>';

        return $breadcrumb_html;
    }
}