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
?>

<div class="dm-events-filter-bar">
    <div class="dm-events-filter-row">
        <div class="dm-events-search">
            <input type="text" 
                   id="dm-events-search" 
                   placeholder="<?php _e('Search events...', 'dm-events'); ?>" 
                   class="dm-events-search-input">
            <button type="button" class="dm-events-search-btn">
                <span class="dashicons dashicons-search"></span>
            </button>
        </div>
        
        <div class="dm-events-date-filter">
            <div class="dm-events-date-range-wrapper">
                <input type="text" 
                       id="dm-events-date-range" 
                       class="dm-events-date-range-input" 
                       placeholder="<?php _e('Select date range...', 'dm-events'); ?>" 
                       readonly />
                <button type="button" 
                        class="dm-events-date-clear-btn" 
                        title="<?php _e('Clear date filter', 'dm-events'); ?>">
                    ✕
                </button>
            </div>
        </div>
        
        <div class="dm-events-taxonomy-filter">
            <button type="button" class="dm-events-filter-btn dm-taxonomy-modal-trigger">
                <span class="dashicons dashicons-filter"></span>
                <?php _e('Filter', 'dm-events'); ?>
            </button>
        </div>
    </div>
    
    <!-- Taxonomy Filter Modal -->
    <div id="dm-taxonomy-filter-modal" class="dm-taxonomy-modal">
        <div class="dm-taxonomy-modal-overlay"></div>
        <div class="dm-taxonomy-modal-container">
            <div class="dm-taxonomy-modal-header">
                <h2 class="dm-taxonomy-modal-title"><?php _e('Event Display Filters', 'dm-events'); ?></h2>
                <button type="button" class="dm-taxonomy-modal-close" aria-label="<?php esc_attr_e('Close', 'dm-events'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="dm-taxonomy-modal-body">
                <?php
                // Include the taxonomy filter template directly
                $taxonomies_data = \DmEvents\Blocks\Calendar\Taxonomy_Helper::get_all_taxonomies_with_counts();
                
                
                include __DIR__ . '/modal/taxonomy-filter.php';
                ?>
            </div>
        </div>
    </div>
</div>