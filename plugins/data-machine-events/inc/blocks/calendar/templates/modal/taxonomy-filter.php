<?php
/**
 * Taxonomy Filter Modal Content Template
 *
 * Displays hierarchical checkbox interface for all taxonomies with post counts.
 * Modal actions (footer) are rendered in filter-bar.php for sticky positioning.
 *
 * @var array $taxonomies_data All taxonomies with terms and hierarchy
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get taxonomy data from template variables
$taxonomies_data = $taxonomies_data ?? [];

// Debug: Add visual indicator if no taxonomies found (remove after testing)
if (empty($taxonomies_data) && defined('WP_DEBUG') && WP_DEBUG) {
    echo '<!-- DEBUG: No taxonomies data found -->';
}
?>

<?php if (!empty($taxonomies_data)) : ?>
    <div class="datamachine-taxonomy-filter-content">
        <?php foreach ($taxonomies_data as $taxonomy_slug => $taxonomy_data) : ?>
            <div class="datamachine-taxonomy-section" data-taxonomy="<?php echo esc_attr($taxonomy_slug); ?>">
                <h4 class="datamachine-taxonomy-label"><?php echo esc_html($taxonomy_data['label']); ?></h4>
                
                <div class="datamachine-taxonomy-terms">
                    <?php 
                    // Flatten the hierarchy for easier template rendering
                    $flattened_terms = \DataMachineEvents\Blocks\Calendar\Taxonomy_Helper::flatten_hierarchy($taxonomy_data['terms']);
                    
                    foreach ($flattened_terms as $term) : 
                        $indent_class = $term['level'] > 0 ? 'datamachine-term-level-' . $term['level'] : '';
                        $indent_style = $term['level'] > 0 ? 'style="margin-left: ' . ($term['level'] * 20) . 'px;"' : '';
                    ?>
                        <div class="datamachine-taxonomy-term <?php echo esc_attr($indent_class); ?>" <?php echo $indent_style; ?>>
                            <label class="datamachine-term-checkbox-label">
                                <input type="checkbox" <?php if (!empty($tax_filters) && isset($tax_filters[$taxonomy_slug]) && in_array($term['term_id'], $tax_filters[$taxonomy_slug], true)) { echo 'checked="checked"'; } ?> 
                                       name="taxonomy_filters[<?php echo esc_attr($taxonomy_slug); ?>][]" 
                                       value="<?php echo esc_attr($term['term_id']); ?>"
                                       class="datamachine-term-checkbox"
                                       data-taxonomy="<?php echo esc_attr($taxonomy_slug); ?>"
                                       data-term-slug="<?php echo esc_attr($term['slug']); ?>"
                                />
                                <span class="datamachine-term-name"><?php echo esc_html($term['name']); ?></span>
                                <span class="datamachine-term-count">(<?php echo esc_html($term['event_count']); ?> <?php echo _n('event', 'events', $term['event_count'], 'datamachine-events'); ?>)</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($taxonomy_slug !== array_key_last($taxonomies_data)) : ?>
                    <hr class="datamachine-taxonomy-separator">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else : ?>
    <div class="datamachine-no-taxonomies">
        <p><?php _e('No filter options are currently available.', 'datamachine-events'); ?></p>
    </div>
<?php endif; ?>