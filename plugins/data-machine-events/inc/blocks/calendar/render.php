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
    'meta_key' => '_chill_event_start_date_utc',
    'orderby' => 'meta_value',
    'order' => 'ASC',
);

// Filter by date if not showing past events
if (!$show_past_events) {
    $query_args['meta_query'] = array(
        array(
            'key' => '_chill_event_start_date_utc',
            'value' => current_time('mysql', 1), // UTC time
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
                    // Block-first: Parse block attributes to get event data
                    $event_data = null;
                    if (has_blocks(get_the_content())) {
                        $blocks = parse_blocks(get_the_content());
                        foreach ($blocks as $block) {
                            if ('chill-events/event-details' === $block['blockName']) {
                                $event_data = $block['attrs'];
                                break;
                            }
                        }
                    }

                    // If we couldn't get block data, skip this event
                    if (!$event_data) {
                        continue;
                    }

                    // Extract data from attributes
                    $start_date = $event_data['startDate'] ?? '';
                    $start_time = $event_data['startTime'] ?? '';
                    $end_date = $event_data['endDate'] ?? '';
                    $end_time = $event_data['endTime'] ?? '';
                    $venue_name = $event_data['venue'] ?? '';
                    $venue_address = $event_data['address'] ?? '';
                    $artist_name = $event_data['artist'] ?? '';
                    $price = $event_data['price'] ?? '';
                    $ticket_url = $event_data['ticketUrl'] ?? '';

                    // Get display settings from global options
                    $settings = get_option('chill_events_settings', array());
                    $show_venue = !empty($settings['block_show_venue']);
                    $show_artist = !empty($settings['block_show_artist']);
                    $show_price = !empty($settings['block_show_price']);
                    $show_ticket_link = !empty($settings['block_show_ticket_link']);
                    
                    // Format dates
                    $formatted_start_date = '';
                    if ($start_date) {
                        $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
                        $formatted_start_date = $start_datetime_obj->format('M j, Y g:i A');
                    }
                    ?>
                    <div class="chill-event-item">
                        <div class="chill-event-card">
                            <div class="chill-event-card-body">
                                <h3 class="chill-event-title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h3>
                                
                                <div class="chill-event-meta">
                                    <div class="chill-event-date">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <?php echo esc_html($formatted_start_date); ?>
                                    </div>
                                    
                                    <?php if ($show_venue && !empty($venue_name)) : ?>
                                    <div class="chill-event-venue">
                                        <span class="dashicons dashicons-location"></span>
                                        <?php echo esc_html($venue_name); ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($show_artist && !empty($artist_name)) : ?>
                                        <div class="chill-event-artist">
                                            <span class="dashicons dashicons-admin-users"></span>
                                            <?php echo esc_html($artist_name); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="chill-event-excerpt">
                                    <?php the_excerpt(); ?>
                                </div>
                            </div>
                            
                            <div class="chill-event-card-footer">
                                <a href="<?php the_permalink(); ?>" class="chill-event-details-link">
                                    <?php _e('View Details', 'chill-events'); ?>
                                </a>
                                <?php if ($show_ticket_link && !empty($ticket_url)) : ?>
                                    <a href="<?php echo esc_url($ticket_url); ?>" 
                                       class="chill-event-ticket-link" 
                                       target="_blank" 
                                       rel="noopener noreferrer">
                                        <?php _e('Get Tickets', 'chill-events'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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