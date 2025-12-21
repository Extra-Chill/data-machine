<?php
/**
 * Promoter Taxonomy Registration and Management
 *
 * Provides promoter taxonomy for events with metadata support.
 * Maps to Schema.org "organizer" property for structured data output.
 * Simpler than Venue_Taxonomy - no geocoding, exact name matching only.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Promoter_Taxonomy {

    public static $meta_fields = [
        'url' => '_promoter_url',
        'type' => '_promoter_type'
    ];

    private static $type_options = [
        'Organization' => 'Organization',
        'Person' => 'Person'
    ];

    public static function register() {
        self::register_promoter_taxonomy();
        self::init_admin_hooks();
    }

    private static function register_promoter_taxonomy() {
        if (taxonomy_exists('promoter')) {
            register_taxonomy_for_object_type('promoter', Event_Post_Type::POST_TYPE);
        } else {
            register_taxonomy('promoter', [Event_Post_Type::POST_TYPE], [
                'hierarchical' => false,
                'labels' => [
                    'name' => _x('Promoters', 'taxonomy general name', 'datamachine-events'),
                    'singular_name' => _x('Promoter', 'taxonomy singular name', 'datamachine-events'),
                    'search_items' => __('Search Promoters', 'datamachine-events'),
                    'all_items' => __('All Promoters', 'datamachine-events'),
                    'edit_item' => __('Edit Promoter', 'datamachine-events'),
                    'update_item' => __('Update Promoter', 'datamachine-events'),
                    'add_new_item' => __('Add New Promoter', 'datamachine-events'),
                    'new_item_name' => __('New Promoter Name', 'datamachine-events'),
                    'menu_name' => __('Promoters', 'datamachine-events'),
                ],
                'show_ui' => true,
                'show_in_menu' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => ['slug' => 'promoter'],
                'show_in_rest' => true,
            ]);
        }

        register_taxonomy_for_object_type('promoter', Event_Post_Type::POST_TYPE);
    }

    /**
     * Find or create a promoter with given name and metadata
     *
     * @param string $promoter_name Promoter name
     * @param array $promoter_data Promoter metadata (url, type)
     * @return array Array with keys: term_id, was_created
     */
    public static function find_or_create_promoter($promoter_name, $promoter_data = []) {
        $promoter_name = trim($promoter_name);

        if (empty($promoter_name)) {
            return [
                'term_id' => null,
                'was_created' => false
            ];
        }

        $existing = get_term_by('name', $promoter_name, 'promoter');

        if ($existing) {
            $term_id = $existing->term_id;

            if (!empty($promoter_data)) {
                self::smart_merge_promoter_meta($term_id, $promoter_data);
            }

            return [
                'term_id' => $term_id,
                'was_created' => false
            ];
        }

        $term_args = [];
        if (!empty($promoter_data['description'])) {
            $term_args['description'] = sanitize_textarea_field($promoter_data['description']);
        }

        $result = wp_insert_term($promoter_name, 'promoter', $term_args);

        if (is_wp_error($result)) {
            error_log('DM Events: Failed to create promoter "' . $promoter_name . '": ' . $result->get_error_message());
            return [
                'term_id' => null,
                'was_created' => false
            ];
        }

        $term_id = $result['term_id'];

        self::update_promoter_meta($term_id, $promoter_data);

        return [
            'term_id' => $term_id,
            'was_created' => true
        ];
    }

    /**
     * Smartly merge new promoter data into existing promoter
     * Only updates fields that are currently empty in the database
     *
     * @param int $term_id Promoter term ID
     * @param array $promoter_data New promoter data
     */
    private static function smart_merge_promoter_meta($term_id, $promoter_data) {
        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (empty($promoter_data[$data_key])) {
                continue;
            }

            $existing_value = get_term_meta($term_id, $meta_key, true);

            if (empty($existing_value)) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($promoter_data[$data_key]));
            }
        }

        if (!empty($promoter_data['description'])) {
            $term = get_term($term_id, 'promoter');
            if ($term && empty($term->description)) {
                wp_update_term($term_id, 'promoter', [
                    'description' => sanitize_textarea_field($promoter_data['description'])
                ]);
            }
        }
    }

    /**
     * Update promoter term meta with promoter data
     *
     * @param int $term_id Promoter term ID
     * @param array $promoter_data Promoter data array
     * @return bool Success status
     */
    public static function update_promoter_meta($term_id, $promoter_data) {
        if (!$term_id || !is_array($promoter_data)) {
            return false;
        }

        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (array_key_exists($data_key, $promoter_data)) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($promoter_data[$data_key]));
            }
        }

        return true;
    }

    /**
     * Retrieves complete promoter data with all meta fields populated
     *
     * @param int $term_id Promoter term ID
     * @return array Promoter data
     */
    public static function get_promoter_data($term_id) {
        $term = get_term($term_id, 'promoter');
        if (!$term || is_wp_error($term)) {
            return [];
        }

        $promoter_data = [
            'name' => $term->name,
            'term_id' => $term_id,
            'slug' => $term->slug,
            'description' => $term->description,
        ];

        foreach (self::$meta_fields as $data_key => $meta_key) {
            $promoter_data[$data_key] = get_term_meta($term_id, $meta_key, true);
        }

        return $promoter_data;
    }

    /**
     * Get all promoters
     *
     * @return array Array of promoter data
     */
    public static function get_all_promoters() {
        $promoters = get_terms([
            'taxonomy' => 'promoter',
            'hide_empty' => false,
        ]);

        if (is_wp_error($promoters)) {
            return [];
        }

        $promoter_data = [];
        foreach ($promoters as $promoter) {
            $promoter_data[] = self::get_promoter_data($promoter->term_id);
        }

        return $promoter_data;
    }

    /**
     * Get promoters for dropdown options
     *
     * @return array Array of term_id => name for select fields
     */
    public static function get_promoter_options() {
        $promoters = get_terms([
            'taxonomy' => 'promoter',
            'hide_empty' => false,
        ]);

        if (is_wp_error($promoters)) {
            return [];
        }

        $options = [];
        foreach ($promoters as $promoter) {
            $options[$promoter->term_id] = $promoter->name;
        }

        return $options;
    }

    private static function init_admin_hooks() {
        add_action('promoter_add_form_fields', [__CLASS__, 'add_promoter_form_fields']);
        add_action('promoter_edit_form_fields', [__CLASS__, 'edit_promoter_form_fields']);
        add_action('created_promoter', [__CLASS__, 'save_promoter_meta']);
        add_action('edited_promoter', [__CLASS__, 'save_promoter_meta']);
    }

    public static function add_promoter_form_fields($taxonomy) {
        ?>
        <div class="form-field">
            <label for="_promoter_url"><?php esc_html_e('Website', 'datamachine-events'); ?></label>
            <input type="url" name="_promoter_url" id="_promoter_url" value="" class="regular-text" />
            <p class="description"><?php esc_html_e('The promoter website URL.', 'datamachine-events'); ?></p>
        </div>
        <div class="form-field">
            <label for="_promoter_type"><?php esc_html_e('Type', 'datamachine-events'); ?></label>
            <select name="_promoter_type" id="_promoter_type">
                <?php foreach (self::$type_options as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Organization or Person (for Schema.org).', 'datamachine-events'); ?></p>
        </div>
        <?php
    }

    public static function edit_promoter_form_fields($term) {
        $url = get_term_meta($term->term_id, '_promoter_url', true);
        $type = get_term_meta($term->term_id, '_promoter_type', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="_promoter_url"><?php esc_html_e('Website', 'datamachine-events'); ?></label></th>
            <td>
                <input type="url" name="_promoter_url" id="_promoter_url" value="<?php echo esc_url($url); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e('The promoter website URL.', 'datamachine-events'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="_promoter_type"><?php esc_html_e('Type', 'datamachine-events'); ?></label></th>
            <td>
                <select name="_promoter_type" id="_promoter_type">
                    <?php foreach (self::$type_options as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($type, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Organization or Person (for Schema.org).', 'datamachine-events'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save_promoter_meta($term_id) {
        if (isset($_POST['_promoter_url'])) {
            update_term_meta($term_id, '_promoter_url', esc_url_raw(wp_unslash($_POST['_promoter_url'])));
        }
        if (isset($_POST['_promoter_type'])) {
            $type = sanitize_text_field(wp_unslash($_POST['_promoter_type']));
            if (array_key_exists($type, self::$type_options)) {
                update_term_meta($term_id, '_promoter_type', $type);
            }
        }
    }
}
