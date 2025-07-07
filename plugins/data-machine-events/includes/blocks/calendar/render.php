<?php
/**
 * Chill Events Calendar Block Render Template
 * 
 * This file is included by the render_calendar_block callback
 * Variables available: $attributes, $content, $block
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get block attributes
$default_view = $attributes['defaultView'] ?? 'list';
$events_to_show = $attributes['eventsToShow'] ?? 10;
$show_past_events = $attributes['showPastEvents'] ?? false;
$show_filters = $attributes['showFilters'] ?? true;
$show_search = $attributes['showSearch'] ?? true;
$show_date_filter = $attributes['showDateFilter'] ?? true;
$show_view_toggle = $attributes['showViewToggle'] ?? true;
$enable_pagination = $attributes['enablePagination'] ?? true;
$events_per_page = $attributes['eventsPerPage'] ?? 12;

// Get current page for pagination
$current_page = max(1, get_query_var('paged') ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1));

// Build query arguments
$query_args = array(
    'post_type' => 'chill_events',
    'post_status' => 'publish',
    'posts_per_page' => $enable_pagination ? $events_per_page : $events_to_show,
    'paged' => $current_page,
    'meta_key' => '_chill_event_start_date',
    'orderby' => 'meta_value',
    'order' => 'ASC',
);

// Filter by date if not showing past events
if (!$show_past_events) {
    $query_args['meta_query'] = array(
        array(
            'key' => '_chill_event_start_date',
            'value' => current_time('Y-m-d H:i:s'),
            'compare' => '>='
        )
    );
}

// Query events
$events_query = new WP_Query($query_args);

// Start output
$wrapper_attributes = get_block_wrapper_attributes(array(
    'class' => 'chill-events-calendar chill-events-view-' . esc_attr($default_view)
));
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php if ($show_filters) : ?>
        <div class="chill-events-filter-bar">
            <div class="chill-events-filter-row">
                <?php if ($show_search) : ?>
                    <div class="chill-events-search">
                        <input type="text" 
                               id="chill-events-search" 
                               placeholder="Search events..." 
                               class="chill-events-search-input">
                        <button type="button" class="chill-events-search-btn">
                            <span class="dashicons dashicons-search"></span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_date_filter) : ?>
                    <div class="chill-events-date-filter">
                        <div class="chill-events-date-range-wrapper">
                            <input type="text" 
                                   id="chill-events-date-range" 
                                   class="chill-events-date-range-input" 
                                   placeholder="Select date range..." 
                                   readonly />
                            <button type="button" 
                                    class="chill-events-date-clear-btn" 
                                    title="Clear date filter">
                                ✕
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_view_toggle) : ?>
                    <div class="chill-events-view-toggle">
                        <button type="button" 
                                class="chill-events-view-btn chill-events-view-list active" 
                                data-view="list">
                            <span class="dashicons dashicons-list-view"></span>
                            <?php _e('List', 'chill-events'); ?>
                        </button>
                        <button type="button" 
                                class="chill-events-view-btn chill-events-view-grid" 
                                data-view="grid">
                            <span class="dashicons dashicons-grid-view"></span>
                            <?php _e('Grid', 'chill-events'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="chill-events-content">
        <?php if ($events_query->have_posts()) : ?>
            <div class="chill-events-list" id="chill-events-list">
                <?php while ($events_query->have_posts()) : $events_query->the_post(); ?>
                    <?php
        // Get event meta
        $start_date = get_post_meta(get_the_ID(), '_chill_event_start_date', true);
                    $end_date = get_post_meta(get_the_ID(), '_chill_event_end_date', true);
        $ticket_url = get_post_meta(get_the_ID(), '_chill_event_ticket_url', true);
                    $artist_name = get_post_meta(get_the_ID(), '_chill_event_artist_name', true);
        
        // Get venue data from taxonomy only
        $venue_name = '';
        $venue_address = '';
        $venue_terms = get_the_terms(get_the_ID(), 'venue');
        
        if ($venue_terms && !is_wp_error($venue_terms)) {
            $venue_term = $venue_terms[0]; // Get first venue
            $venue_name = $venue_term->name;
            $venue_address = \ChillEvents\Events\Venues\Venue_Term_Meta::get_formatted_address($venue_term->term_id);
        }
        
                    // Format dates
                    $formatted_start_date = '';
                    $formatted_end_date = '';
        if ($start_date) {
                        $start_date_obj = new DateTime($start_date);
                        $formatted_start_date = $start_date_obj->format('M j, Y g:i A');
                    }
                    if ($end_date) {
                        $end_date_obj = new DateTime($end_date);
                        $formatted_end_date = $end_date_obj->format('M j, Y g:i A');
                    }
                    ?>
                    
                    <article class="chill-event-item" 
                             data-title="<?php echo esc_attr(get_the_title()); ?>"
                             data-venue="<?php echo esc_attr($venue_name); ?>"
                             data-artist="<?php echo esc_attr($artist_name); ?>"
                             data-date="<?php echo esc_attr($start_date); ?>">
                        <div class="chill-event-content">
                            <h3 class="chill-event-title">
                                <a href="<?php echo esc_url(get_permalink()); ?>"><?php echo esc_html(get_the_title()); ?></a>
                            </h3>
                            
                            <?php if ($formatted_start_date) : ?>
                                <div class="chill-event-date">
                                    <?php echo esc_html($formatted_start_date); ?>
                                    <?php if ($formatted_end_date && $formatted_end_date !== $formatted_start_date) : ?>
                                        <span class="chill-event-date-separator">to</span>
                                        <?php echo esc_html($end_date_obj->format('M j, Y g:i A')); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($artist_name) : ?>
                                <div class="chill-event-artist">
                                    <?php echo esc_html($artist_name); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($venue_name) : ?>
                                <div class="chill-event-venue">
                                    <?php echo esc_html($venue_name); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($venue_address) : ?>
                                <div class="chill-event-address">
                                    <?php echo esc_html($venue_address); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            $excerpt = get_the_excerpt();
                            if (!empty(trim($excerpt))) : ?>
                                <div class="chill-event-excerpt"><?php echo $excerpt; ?></div>
                            <?php endif; ?>
                            
                            <div class="chill-event-actions">
                                <a href="<?php echo esc_url(get_permalink()); ?>" class="chill-event-link">
                                    <?php _e('View Details', 'chill-events'); ?>
                                </a>
                                <?php if ($ticket_url) : ?>
                                    <a href="<?php echo esc_url($ticket_url); ?>" 
                                       target="_blank" 
                                       rel="noopener" 
                                       class="chill-event-tickets">
                                        <?php _e('Get Tickets', 'chill-events'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>
            
            <?php 
            // Only show pagination if enabled, there are multiple pages, and we have a valid current page
            if ($enable_pagination && $events_query->max_num_pages > 1 && $current_page > 0) : ?>
                <?php
                $pagination_links = paginate_links(array(
                    'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                    'format' => '?paged=%#%',
                    'current' => $current_page,
                    'total' => $events_query->max_num_pages,
                    'prev_text' => __('&laquo; Previous'),
                    'next_text' => __('Next &raquo;'),
                    'type' => 'list',
                    'end_size' => 3,
                    'mid_size' => 3,
                    'echo' => false
                ));
                
                // Only output if we actually have pagination links and they're not just whitespace
                if (!empty($pagination_links) && trim($pagination_links) !== '') : ?>
                    <div class="chill-events-pagination">
                        <?php echo $pagination_links; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
        <?php else : ?>
            <div class="chill-events-no-events">
                <p><?php _e('No events found.', 'chill-events'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Reset post data
wp_reset_postdata();
?> 