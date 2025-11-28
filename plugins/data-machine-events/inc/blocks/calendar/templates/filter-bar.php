<?php
/**
 * Calendar Filter Bar Template
 *
 * Renders the complete filter bar with search, date range, and dynamic taxonomy filters.
 *
 * @var array $attributes Block attributes
 * @var array $used_taxonomies Available taxonomies for filtering (future use)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$show_search = $attributes['showSearch'] ?? true;

if (!$show_search) {
    return;
}

$instance_id = $instance_id ?? uniqid('datamachine-calendar-');
$search_id = 'datamachine-events-search-' . $instance_id;
$search_value = isset($search_query) ? $search_query : '';
$date_range_id = 'datamachine-events-date-range-' . $instance_id;
$modal_id = 'datamachine-taxonomy-filter-modal-' . $instance_id;
?>

<div class="datamachine-events-filter-bar">
    <div class="datamachine-events-filter-row">
        <div class="datamachine-events-search">
            <input type="text" 
                   id="<?php echo esc_attr($search_id); ?>" 
                   value="<?php echo esc_attr($search_value); ?>"
                   placeholder="<?php _e('Search events...', 'datamachine-events'); ?>" 
                   class="datamachine-events-search-input">
            <button type="button" class="datamachine-events-search-btn">
                <span class="dashicons dashicons-search"></span>
            </button>
        </div>
        
        <div class="datamachine-events-date-filter">
            <div class="datamachine-events-date-range-wrapper">
                <input type="text" 
                        id="<?php echo esc_attr($date_range_id); ?>"
                        class="datamachine-events-date-range-input" data-date-start="<?php echo esc_attr($date_start); ?>" data-date-end="<?php echo esc_attr($date_end); ?>" 
                       placeholder="<?php _e('Select date range...', 'datamachine-events'); ?>" 
                       readonly />
                <button type="button" 
                        class="datamachine-events-date-clear-btn" 
                        title="<?php _e('Clear date filter', 'datamachine-events'); ?>">
                    ✕
                </button>
            </div>
        </div>
        
        <div class="datamachine-events-taxonomy-filter">
            <button type="button" class="datamachine-events-filter-btn datamachine-taxonomy-modal-trigger<?php echo (!empty($tax_filters) ? ' datamachine-filters-active' : ''); ?>" data-modal-id="<?php echo esc_attr($modal_id); ?>" aria-controls="<?php echo esc_attr($modal_id); ?>" aria-expanded="<?php echo (!empty($tax_filters) ? 'true' : 'false'); ?>">
                <span class="datamachine-filter-count" aria-hidden="true"><?php echo (!empty($tax_filters) ? array_sum(array_map('count', $tax_filters)) : ''); ?></span>
                <span class="dashicons dashicons-filter"></span>
                <?php _e('Filter', 'datamachine-events'); ?>
            </button>
        </div>
    </div>
    
    <!-- Taxonomy Filter Modal -->
    <div id="<?php echo esc_attr($modal_id); ?>" class="datamachine-taxonomy-modal" aria-labelledby="<?php echo esc_attr($modal_id . '-title'); ?>">
        <div class="datamachine-taxonomy-modal-overlay"></div>
        <div class="datamachine-taxonomy-modal-container">
            <div class="datamachine-taxonomy-modal-header">
                <h2 id="<?php echo esc_attr($modal_id . '-title'); ?>" class="datamachine-taxonomy-modal-title"><?php _e('Event Display Filters', 'datamachine-events'); ?></h2>
                <button type="button" class="datamachine-taxonomy-modal-close" aria-label="<?php esc_attr_e('Close', 'datamachine-events'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="datamachine-taxonomy-modal-body">
                <?php
                // Include the taxonomy filter template directly
                $taxonomies_data = $taxonomies_data ?? $used_taxonomies ?? \DataMachineEvents\Blocks\Calendar\Taxonomy_Helper::get_all_taxonomies_with_counts();
                include __DIR__ . '/modal/taxonomy-filter.php';
                ?>
            </div>
            <div class="datamachine-taxonomy-modal-footer">
                <div class="datamachine-modal-actions">
                    <div class="datamachine-modal-actions-left">
                        <button type="button" class="<?php echo esc_attr(implode(' ', apply_filters('datamachine_events_modal_button_classes', ['button', 'button-secondary', 'datamachine-clear-all-filters'], 'secondary'))); ?>">
                            <?php _e('Clear All Filters', 'datamachine-events'); ?>
                        </button>
                    </div>
                    <div class="datamachine-modal-actions-right">
                        <button type="button" class="<?php echo esc_attr(implode(' ', apply_filters('datamachine_events_modal_button_classes', ['button', 'button-primary', 'datamachine-apply-filters'], 'primary'))); ?>">
                            <?php _e('Apply Filters', 'datamachine-events'); ?>
                        </button>
                        <button type="button" class="<?php echo esc_attr(implode(' ', apply_filters('datamachine_events_modal_button_classes', ['button', 'button-secondary', 'datamachine-modal-close'], 'secondary'))); ?>">
                            <?php _e('Cancel', 'datamachine-events'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>