<?php
/**
 * Taxonomy Filter Modal Template
 *
 * Displays hierarchical checkbox interface for all taxonomies with post counts.
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

<div class="dm-events-taxonomy-modal">
    <?php if (!empty($taxonomies_data)) : ?>
        <div class="dm-taxonomy-filter-content">
            <?php foreach ($taxonomies_data as $taxonomy_slug => $taxonomy_data) : ?>
                <div class="dm-taxonomy-section" data-taxonomy="<?php echo esc_attr($taxonomy_slug); ?>">
                    <h4 class="dm-taxonomy-label"><?php echo esc_html($taxonomy_data['label']); ?></h4>
                    
                    <div class="dm-taxonomy-terms">
                        <?php 
                        // Flatten the hierarchy for easier template rendering
                        $flattened_terms = \DmEvents\Blocks\Calendar\Taxonomy_Helper::flatten_hierarchy($taxonomy_data['terms']);
                        
                        foreach ($flattened_terms as $term) : 
                            $indent_class = $term['level'] > 0 ? 'dm-term-level-' . $term['level'] : '';
                            $indent_style = $term['level'] > 0 ? 'style="margin-left: ' . ($term['level'] * 20) . 'px;"' : '';
                        ?>
                            <div class="dm-taxonomy-term <?php echo esc_attr($indent_class); ?>" <?php echo $indent_style; ?>>
                                <label class="dm-term-checkbox-label">
                                    <input type="checkbox" 
                                           name="taxonomy_filters[<?php echo esc_attr($taxonomy_slug); ?>][]" 
                                           value="<?php echo esc_attr($term['term_id']); ?>"
                                           class="dm-term-checkbox"
                                           data-taxonomy="<?php echo esc_attr($taxonomy_slug); ?>"
                                           data-term-slug="<?php echo esc_attr($term['slug']); ?>"
                                    />
                                    <span class="dm-term-name"><?php echo esc_html($term['name']); ?></span>
                                    <span class="dm-term-count">(<?php echo esc_html($term['event_count']); ?> <?php echo _n('event', 'events', $term['event_count'], 'dm-events'); ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($taxonomy_slug !== array_key_last($taxonomies_data)) : ?>
                        <hr class="dm-taxonomy-separator">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="dm-modal-actions">
            <div class="dm-modal-actions-left">
                <button type="button" class="button button-secondary dm-clear-all-filters">
                    <?php _e('Clear All Filters', 'dm-events'); ?>
                </button>
            </div>
            
            <div class="dm-modal-actions-right">
                <button type="button" class="button button-primary dm-apply-filters">
                    <?php _e('Apply Filters', 'dm-events'); ?>
                </button>
                <button type="button" class="button button-secondary dm-modal-close">
                    <?php _e('Cancel', 'dm-events'); ?>
                </button>
            </div>
        </div>
        
    <?php else : ?>
        <div class="dm-no-taxonomies">
            <p><?php _e('No filter options are currently available.', 'dm-events'); ?></p>
            <div class="dm-modal-actions">
                <button type="button" class="button button-secondary dm-modal-close">
                    <?php _e('Close', 'dm-events'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>