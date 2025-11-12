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

<div class="datamachine-events-filter-bar">
    <div class="datamachine-events-filter-row">
        <div class="datamachine-events-search">
            <input type="text" 
                   id="datamachine-events-search" 
                   placeholder="<?php _e('Search events...', 'datamachine-events'); ?>" 
                   class="datamachine-events-search-input">
            <button type="button" class="datamachine-events-search-btn">
                <span class="dashicons dashicons-search"></span>
            </button>
        </div>
        
        <div class="datamachine-events-date-filter">
            <div class="datamachine-events-date-range-wrapper">
                <input type="text" 
                        id="datamachine-events-date-range"
                       class="datamachine-events-date-range-input" 
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
            <button type="button" class="datamachine-events-filter-btn datamachine-taxonomy-modal-trigger">
                <span class="dashicons dashicons-filter"></span>
                <?php _e('Filter', 'datamachine-events'); ?>
            </button>
        </div>
    </div>
    
    <!-- Taxonomy Filter Modal -->
    <div id="datamachine-taxonomy-filter-modal" class="datamachine-taxonomy-modal">
        <div class="datamachine-taxonomy-modal-overlay"></div>
        <div class="datamachine-taxonomy-modal-container">
            <div class="datamachine-taxonomy-modal-header">
                <h2 class="datamachine-taxonomy-modal-title"><?php _e('Event Display Filters', 'datamachine-events'); ?></h2>
                <button type="button" class="datamachine-taxonomy-modal-close" aria-label="<?php esc_attr_e('Close', 'datamachine-events'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="datamachine-taxonomy-modal-body">
                <?php
                // Include the taxonomy filter template directly
                $taxonomies_data = \DataMachineEvents\Blocks\Calendar\Taxonomy_Helper::get_all_taxonomies_with_counts();
                
                
                include __DIR__ . '/modal/taxonomy-filter.php';
                ?>
            </div>
        </div>
    </div>
</div>