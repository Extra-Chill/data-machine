<?php
/**
 * Import Executor for Chill Events
 *
 * Handles global and per-module import execution, locking, and logging.
 *
 * @package ChillEvents\Events
 * @since 1.0.0
 */

namespace ChillEvents\Events;

if (!defined('ABSPATH')) {
    exit;
}

class ImportExecutor {
    /**
     * Singleton instance
     * @var ImportExecutor|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * @return ImportExecutor
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Run all active import modules (global run)
     * @return void
     */
    public function run_global() {
        // Get all active modules from the database
        global $wpdb;
        $modules_table = $wpdb->prefix . 'chill_import_modules';
        $modules = $wpdb->get_results("SELECT * FROM $modules_table WHERE status = 'active'");
        if (!$modules) return;
        foreach ($modules as $module) {
            $this->run_module($module->id);
        }
    }

    /**
     * Run a single import module by ID
     * @param int $module_id
     * @return void
     */
    public function run_module($module_id) {
        global $wpdb;
        $modules_table = $wpdb->prefix . 'chill_import_modules';
        $module = $wpdb->get_row($wpdb->prepare("SELECT * FROM $modules_table WHERE id = %d", $module_id));
        if (!$module || $module->status !== 'active') return;

        // Get data source instance
        $data_source = \ChillEvents\DataSourceManager::get_data_source_instance($module->data_source);
        if (!$data_source) return;

        // Get settings and taxonomy mappings
        $settings = maybe_unserialize($module->data_source_settings);
        if (!is_array($settings)) {
            $settings = json_decode($module->data_source_settings, true);
        }
        $taxonomy_mappings = maybe_unserialize($module->taxonomy_mappings);
        if (!is_array($taxonomy_mappings)) {
            $taxonomy_mappings = json_decode($module->taxonomy_mappings, true);
        }

        // Fetch plugin settings for meta field enable/disable
        $settings_options = get_option('chill_events_settings', array());
        $save_ticket_url = !empty($settings_options['meta_ticket_url']);
        $save_artist_name = !empty($settings_options['meta_artist_name']);

        $max_events = isset($module->max_events) ? intval($module->max_events) : 0;
        $page_size = 200; // or adjust as needed
        // Track last page fetched for this module
        $last_page = get_option('chill_events_module_last_page_' . $module_id, 0);
        $page = $last_page;
        $unique_imported = 0;
        $has_more = true;
        while ($has_more && ($max_events === 0 || $unique_imported < $max_events)) {
            error_log('[ChillEvents][DEBUG] Fetching page ' . $page . ' for module ' . $module_id);
            $events = $data_source->get_events($settings, $page, $page_size);
            if (!$events || !is_array($events) || count($events) === 0) {
                error_log('[ChillEvents][DEBUG] No events returned for page ' . $page . ' (module ' . $module_id . '). Resetting last page to 0.');
                update_option('chill_events_module_last_page_' . $module_id, 0);
                break;
            }
            $event_titles = array();
            foreach ($events as $event) {
                $event_titles[] = $event->get('title') ?: '[no title]';
            }
            error_log('[ChillEvents][DEBUG] Events on page ' . $page . ': ' . implode(' | ', $event_titles));
            foreach ($events as $event) {
                if ($max_events > 0 && $unique_imported >= $max_events) {
                    break 2;
                }
                // $event is now a StandardizedEvent object
                $event_id = $event->get('id');
                if ($event_id) {
                    $existing_by_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_chill_event_ticketmaster_id' AND meta_value = %s LIMIT 1",
                        $event_id
                    ));
                    error_log('[ChillEvents][DEBUG] Checking duplicate by event ID: ' . $event_id . ' | Found: ' . var_export($existing_by_id, true));
                    if ($existing_by_id) {
                        error_log('[ChillEvents][DEBUG] Duplicate found by event ID, skipping: ' . $event->get('title') . ' @ ' . $event->get('start_date') . ' [ID: ' . $event_id . ']');
                        continue;
                    }
                } else {
                    error_log('[ChillEvents][DEBUG] No event ID found, skipping event: ' . $event->get('title') . ' @ ' . $event->get('start_date'));
                    continue;
                }
                error_log('[ChillEvents][DEBUG] Importing new event: ' . $event->get('title') . ' @ ' . $event->get('start_date') . ' [ID: ' . $event_id . ']');
                $postarr = array(
                    'post_type'    => 'chill_events',
                    'post_title'   => $event->get('title'),
                    'post_content' => $event->get('description'),
                    'post_status'  => 'publish',
                );
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'chill_events' AND post_title = %s LIMIT 1",
                    $postarr['post_title']
                ));
                if ($existing) {
                    $postarr['ID'] = $existing;
                    $post_id = wp_update_post($postarr);
                } else {
                    $post_id = wp_insert_post($postarr);
                }
                if (!$post_id || is_wp_error($post_id)) continue;
                // Save meta fields (excluding venue data - handled by taxonomy)
                if ($event->get('start_date')) {
                    update_post_meta($post_id, '_chill_event_start_date', $event->get('start_date'));
                }
                if ($event->get('end_date')) {
                    update_post_meta($post_id, '_chill_event_end_date', $event->get('end_date'));
                }
                if ($event->get('price')) {
                    update_post_meta($post_id, '_chill_event_price', $event->get('price'));
                }
                if ($save_ticket_url && $event->get('ticket_url')) {
                    update_post_meta($post_id, '_chill_event_ticket_url', $event->get('ticket_url'));
                }
                if ($save_artist_name && $event->get('artist_name')) {
                    update_post_meta($post_id, '_chill_event_artist_name', $event->get('artist_name'));
                }
                if ($event_id) {
                    update_post_meta($post_id, '_chill_event_ticketmaster_id', $event_id);
                    error_log('[ChillEvents][DEBUG] Saved event ID to post meta: ' . $event_id . ' for post ' . $post_id);
                }
                // Assign taxonomies
                if (!empty($taxonomy_mappings) && is_array($taxonomy_mappings)) {
                    foreach ($taxonomy_mappings as $field => $taxonomy) {
                        $value = $event->get($field);
                        if ($taxonomy === 'skip' || empty($value)) continue;
                        if (taxonomy_exists($taxonomy)) {
                            wp_set_object_terms($post_id, $value, $taxonomy, false);
                            
                            // If this is a venue taxonomy, store venue data as term meta
                            if ($taxonomy === 'venue' && !empty($value)) {
                                $this->process_venue_data($event, $value);
                            }
                        }
                    }
                }
                
                // Auto-map venue_name to venue taxonomy (if not already mapped)
                $venue_name = $event->get('venue_name');
                if (!empty($venue_name) && taxonomy_exists('venue')) {
                    // Check if venue_name wasn't already mapped in taxonomy_mappings
                    $venue_already_mapped = false;
                    if (!empty($taxonomy_mappings) && is_array($taxonomy_mappings)) {
                        foreach ($taxonomy_mappings as $field => $taxonomy) {
                            if ($field === 'venue_name' && $taxonomy === 'venue') {
                                $venue_already_mapped = true;
                                break;
                            }
                        }
                    }
                    
                    // Auto-map if not already mapped
                    if (!$venue_already_mapped) {
                        wp_set_object_terms($post_id, $venue_name, 'venue', false);
                        $this->process_venue_data($event, $venue_name);
                    }
                }
                $unique_imported++;
            }
            // Always increment page after each fetch
            $page++;
            // If we got less than a full page, no more pages
            if (count($events) < $page_size) {
                $has_more = false;
                // Reset last page to 0 for next run
                update_option('chill_events_module_last_page_' . $module_id, 0);
            } else {
                // Save last page for next run
                update_option('chill_events_module_last_page_' . $module_id, $page);
            }
        }
    }

    /**
     * Acquire a lock to prevent overlapping runs
     * @param string $key
     * @param int $ttl
     * @return bool
     */
    public function acquire_lock($key, $ttl = 600) {
        // TODO: Implement locking logic
        return true;
    }

    /**
     * Release a lock
     * @param string $key
     * @return void
     */
    public function release_lock($key) {
        // TODO: Implement lock release logic
    }
    
    /**
     * Process venue data and store as term meta
     * 
     * @param \ChillEvents\Events\StandardizedEvent $event Event data
     * @param string $venue_name Venue name
     * @return bool True on success, false on failure
     */
    private function process_venue_data($event, $venue_name) {
        // Use existing DynamicTaxonomies system to ensure venue term exists
        $venue_term_id = \ChillEvents\DynamicTaxonomies::ensure_term('venue', $venue_name);
        
        if (is_wp_error($venue_term_id)) {
            // If venue taxonomy doesn't exist, fallback to post meta (already handled)
            if ($venue_term_id->get_error_code() === 'venue_taxonomy_missing') {
                error_log('[ChillEvents][DEBUG] Venue taxonomy not found, using post meta fallback');
                return true; // Don't treat this as an error
            }
            error_log('[ChillEvents][DEBUG] Failed to create venue term: ' . $venue_term_id->get_error_message());
            return false;
        }
        
        // Store venue data as term meta using Venue_Term_Meta
        $result = \ChillEvents\Events\Venues\Venue_Term_Meta::update_venue_meta($venue_term_id, $event);
        
        if ($result) {
            error_log('[ChillEvents][DEBUG] Successfully stored venue data for: ' . $venue_name);
        } else {
            error_log('[ChillEvents][DEBUG] Failed to store venue data for: ' . $venue_name);
        }
        
        return $result;
    }
} 