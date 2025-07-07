<?php

namespace ChillEvents\Admin;

// Fallback: Ensure autoloader is registered for AJAX requests
if (!function_exists('chill_events_autoloader')) {
    require_once dirname(__DIR__, 2) . '/chill-events.php';
}

if (!defined('ABSPATH')) {
    exit;
}

class AjaxImportModule {
    public function __construct() {
        add_action('wp_ajax_chill_save_module', array($this, 'ajax_save_module'));
        add_action('wp_ajax_chill_load_module', array($this, 'ajax_load_module'));
        add_action('wp_ajax_chill_get_data_source_settings', array($this, 'ajax_get_data_source_settings'));
        add_action('wp_ajax_chill_get_taxonomy_mappings', array($this, 'ajax_get_taxonomy_mappings'));
        // Add Run Now AJAX actions
        add_action('wp_ajax_chill_run_module_now', array($this, 'ajax_run_module_now'));
        add_action('wp_ajax_chill_run_all_now', array($this, 'ajax_run_all_now'));
    }

    /**
     * AJAX handler for saving Import Modules
     */
    public function ajax_save_module() {
        // Check nonce
        if (!wp_verify_nonce($_POST['chill_module_nonce'], 'chill_module_save')) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'chill-events'));
        }
        global $wpdb;
        $table = \ChillEvents\Database::get_modules_table();
        // Sanitize input
        $module_id = intval($_POST['module_id']);
        $name = sanitize_text_field($_POST['module_name']);
        $slug = sanitize_title($name);
        $data_source = isset($_POST['data_source']) ? trim(wp_unslash($_POST['data_source'])) : '';
        $status = sanitize_text_field($_POST['status']);
        $max_events = intval($_POST['max_events']);
        // Process data source settings
        $data_source_settings = array();
        if (isset($_POST['data_source_settings']) && is_array($_POST['data_source_settings'])) {
            foreach ($_POST['data_source_settings'] as $key => $value) {
                $data_source_settings[sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        // Process taxonomy mappings
        $taxonomy_mappings = array();
        if (isset($_POST['taxonomy_mappings']) && is_array($_POST['taxonomy_mappings'])) {
            foreach ($_POST['taxonomy_mappings'] as $field => $taxonomy) {
                $taxonomy_mappings[sanitize_key($field)] = sanitize_text_field($taxonomy);
            }
        }
        $data = array(
            'name' => $name,
            'slug' => $slug,
            'data_source' => $data_source,
            'data_source_settings' => maybe_serialize($data_source_settings),
            'taxonomy_mappings' => maybe_serialize($taxonomy_mappings),
            'status' => $status,
            'max_events' => $max_events,
            'updated_at' => current_time('mysql')
        );
        if ($module_id > 0) {
            // Update existing module
            $wpdb->update($table, $data, array('id' => $module_id), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'), array('%d'));
            $result_id = $module_id;
        } else {
            // Create new module
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data, array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
            $result_id = $wpdb->insert_id;
        }
        if ($result_id) {
            wp_send_json_success(array('module_id' => $result_id, 'message' => __('Module saved successfully.', 'chill-events')));
        } else {
            wp_send_json_error(__('Failed to save module.', 'chill-events'));
        }
    }

    /**
     * AJAX handler for loading module data for editing
     */
    public function ajax_load_module() {
        // Check nonce
        if (!wp_verify_nonce($_GET['_wpnonce'], 'load_module')) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'chill-events'));
        }
        $module_id = intval($_GET['module_id']);
        global $wpdb;
        $table = \ChillEvents\Database::get_modules_table();
        $module = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $module_id));
        if ($module) {
            $module->data_source_settings = maybe_unserialize($module->data_source_settings);
            $module->taxonomy_mappings = maybe_unserialize($module->taxonomy_mappings);
            wp_send_json_success($module);
        } else {
            wp_send_json_error(__('Module not found.', 'chill-events'));
        }
    }

    /**
     * AJAX handler for getting data source settings fields
     */
    public function ajax_get_data_source_settings() {
        // Check nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'chill_module_save')) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        $data_source_class = isset($_POST['data_source']) ? trim(wp_unslash($_POST['data_source'])) : '';
        // Get available data sources and validate the class
        $data_sources = \ChillEvents\DataSourceManager::get_available_data_sources();
        $source_info = null;
        foreach ($data_sources as $source) {
            if ($source['class'] === $data_source_class) {
                $source_info = $source;
                break;
            }
        }
        if (!$source_info) {
            wp_send_json_error(__('Data source not found.', 'chill-events'));
        }
        // Create instance and get settings fields
        $instance = new $data_source_class();
        $info = $instance->get_info();
        $settings_fields = isset($info['settings_fields']) ? $info['settings_fields'] : array();
        $html = '';
        if (!empty($settings_fields)) {
            $html .= '<table class="form-table">';
            foreach ($settings_fields as $field_key => $field_config) {
                $html .= '<tr class="settings-field-row" data-field="' . esc_attr($field_key) . '"';
                if (isset($field_config['conditional'])) {
                    foreach ($field_config['conditional'] as $cond_field => $cond_value) {
                        $html .= ' data-cond-field="' . esc_attr($cond_field) . '" data-cond-value="' . esc_attr($cond_value) . '"';
                    }
                }
                $html .= '>';
                $html .= '<th scope="row"><label for="setting-' . esc_attr($field_key) . '">' . esc_html($field_config['label']) . '</label></th>';
                $html .= '<td>';
                if ($field_config['type'] === 'select') {
                    $html .= '<select id="setting-' . esc_attr($field_key) . '" name="data_source_settings[' . esc_attr($field_key) . ']" class="regular-text">';
                    foreach ($field_config['options'] as $option_value => $option_label) {
                        $selected = (isset($field_config['default']) && $field_config['default'] == $option_value) ? ' selected="selected"' : '';
                        $html .= '<option value="' . esc_attr($option_value) . '"' . $selected . '>' . esc_html($option_label) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    $input_type = $field_config['type'] === 'number' ? 'number' : 'text';
                    $html .= '<input type="' . $input_type . '" id="setting-' . esc_attr($field_key) . '" name="data_source_settings[' . esc_attr($field_key) . ']" class="regular-text"';
                    if (isset($field_config['default'])) {
                        $html .= ' value="' . esc_attr($field_config['default']) . '"';
                    }
                    $html .= '>';
                }
                if (isset($field_config['description'])) {
                    $html .= '<p class="description">' . esc_html($field_config['description']) . '</p>';
                }
                $html .= '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        } else {
            $html = '<p>' . __('This data source requires no additional configuration.', 'chill-events') . '</p>';
        }
        wp_send_json_success(array('html' => $html, 'fields' => array_keys($settings_fields)));
    }

    /**
     * AJAX handler for getting taxonomy mapping fields
     */
    public function ajax_get_taxonomy_mappings() {
        // Check nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'chill_module_save')) {
            wp_die(__('Security check failed.', 'chill-events'));
        }
        // NEW taxonomy-centric UI ------------------------------------
        $taxonomies = get_taxonomies(array('public' => true), 'objects');

        $html  = '<table class="form-table chill-taxonomy-mapping-table">';
        $html .= '<tr><th>' . __('Taxonomy', 'chill-events') . '</th><th>' . __('Term to Assign', 'chill-events') . '</th></tr>';

        foreach ($taxonomies as $taxonomy) {
            $taxonomy_name = esc_attr($taxonomy->name);
            $html .= '<tr>';
            $html .= '<th scope="row">' . esc_html($taxonomy->label) . '</th>';
            $html .= '<td>';
            $html .= '<select name="taxonomy_mappings[' . $taxonomy_name . ']" class="regular-text taxonomy-term-select">';
            $html .= '<option value="skip">' . __('-- Skip --', 'chill-events') . '</option>';

            $terms = get_terms(array(
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ));

            foreach ($terms as $term) {
                $html .= '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . '</option>';
            }

            $html .= '</select>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler for running a single import module now
     */
    public function ajax_run_module_now() {
        check_ajax_referer('chill_events_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission.', 'chill-events'));
        }
        $module_id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;
        if (!$module_id) {
            wp_send_json_error(__('Missing module ID.', 'chill-events'));
        }
        \ChillEvents\Events\ImportExecutor::get_instance()->run_module($module_id);
        wp_send_json_success(array('message' => __('Module import complete.', 'chill-events')));
    }

    /**
     * AJAX handler for running all import modules now
     */
    public function ajax_run_all_now() {
        check_ajax_referer('chill_events_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission.', 'chill-events'));
        }
        \ChillEvents\Events\ImportExecutor::get_instance()->run_global();
        wp_send_json_success(array('message' => __('All modules import complete.', 'chill-events')));
    }
} 