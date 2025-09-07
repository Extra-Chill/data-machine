<?php
/**
 * Data Machine Events Calendar Block Server-Side Render Template
 *
 * Block-first architecture render template that queries events based on Event Details
 * block attributes as the single source of truth. Supports filtering, pagination,
 * and multiple view modes.
 *
 * Available context:
 * @var array $attributes Block attributes (defaultView, eventsToShow, showFilters, etc.)
 * @var string $content Block inner content (unused in dynamic block)
 * @var WP_Block $block Block instance object
 *
 * Key features:
 * - Time-based event filtering (future, past, all)
 * - Search and date range filtering
 * - View toggle (list/grid)
 * - Pagination support
 * - Block-first data architecture
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent rendering during REST API requests
if (wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
    return '';
}

// Extract block configuration with defaults
$default_view = $attributes['defaultView'] ?? 'list';
$events_to_show = $attributes['eventsToShow'] ?? 10;
$show_past_events = $attributes['showPastEvents'] ?? false;
$show_filters = $attributes['showFilters'] ?? true;
$show_search = $attributes['showSearch'] ?? true;
$show_date_filter = $attributes['showDateFilter'] ?? true;
$show_view_toggle = $attributes['showViewToggle'] ?? true;
$enable_pagination = $attributes['enablePagination'] ?? true;
$events_per_page = $attributes['eventsPerPage'] ?? 12;

// Determine time filter mode from URL parameter
$view_mode = $_GET['time_filter'] ?? ($show_past_events ? 'all' : 'future');

// Extract current page number for pagination
$current_page = max(1, get_query_var('paged') ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1));

// Block-first query - get all dm_events posts, filter and sort in PHP
$current_utc = current_time('mysql', 1);
$query_args = array(
    'post_type' => 'dm_events',
    'post_status' => 'publish',
    'numberposts' => -1, // Get all events for PHP filtering
    'orderby' => 'date',
    'order' => 'DESC'
);

// Get all events and extract block data
$all_events = get_posts($query_args);
$filtered_events = array();

foreach ($all_events as $event_post) {
    // Extract Event Details block attributes
    $blocks = parse_blocks($event_post->post_content);
    $event_data = null;
    foreach ($blocks as $block) {
        if ('dm-events/event-details' === $block['blockName']) {
            $event_data = $block['attrs'];
            break;
        }
    }

    // Skip if no start date
    if (empty($event_data['startDate'])) {
        continue;
    }

    // Create DateTime objects for filtering
    $start_time = $event_data['startTime'] ?? '00:00:00';
    $event_datetime = new DateTime($event_data['startDate'] . ' ' . $start_time);
    $current_datetime = new DateTime($current_utc);

    // Apply time-based filtering logic
    if ($view_mode === 'past' && $event_datetime >= $current_datetime) {
        continue;
    } elseif ($view_mode === 'future' && $event_datetime < $current_datetime) {
        continue;
    }
    // 'all' mode includes everything with valid date

    // Store event with datetime
    $filtered_events[] = array(
        'post' => $event_post,
        'datetime' => $event_datetime,
        'event_data' => $event_data
    );
}

// Sort events chronologically
usort($filtered_events, function($a, $b) use ($view_mode) {
    if ($view_mode === 'past') {
        return $b['datetime'] <=> $a['datetime']; // Recent past first
    } else {
        return $a['datetime'] <=> $b['datetime']; // Earliest future first
    }
});

// Apply pagination logic
$total_events = count($filtered_events);
$events_per_page_calc = $enable_pagination ? $events_per_page : $events_to_show;
$offset = ($current_page - 1) * $events_per_page_calc;
$paged_events = array_slice($filtered_events, $offset, $events_per_page_calc);

// Create query object for pagination
$events_query = new stdClass();
$events_query->posts = array_column($paged_events, 'post');
$events_query->post_count = count($paged_events);
$events_query->found_posts = $total_events;
$events_query->max_num_pages = $enable_pagination ? ceil($total_events / $events_per_page_calc) : 1;
$events_query->current_post = -1;

// Generate block wrapper with CSS classes
$wrapper_attributes = get_block_wrapper_attributes(array(
    'class' => 'dm-events-calendar dm-events-view-' . esc_attr($default_view)
));
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php if ($show_filters) : ?>
        <div class="dm-events-filter-bar">
            <div class="dm-events-filter-row">
                <?php if ($show_search) : ?>
                    <div class="dm-events-search">
                        <input type="text" 
                               id="dm-events-search" 
                               placeholder="Search events..." 
                               class="dm-events-search-input">
                        <button type="button" class="dm-events-search-btn">
                            <span class="dashicons dashicons-search"></span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_date_filter) : ?>
                    <div class="dm-events-date-filter">
                        <div class="dm-events-date-range-wrapper">
                            <input type="text" 
                                   id="dm-events-date-range" 
                                   class="dm-events-date-range-input" 
                                   placeholder="Select date range..." 
                                   readonly />
                            <button type="button" 
                                    class="dm-events-date-clear-btn" 
                                    title="Clear date filter">
                                ✕
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="dm-events-time-filter">
                    <button type="button" 
                            class="dm-events-time-btn <?php echo ($view_mode === 'future') ? 'active' : ''; ?>" 
                            data-time="future">
                        <?php _e('Upcoming Events', 'dm-events'); ?>
                    </button>
                    <button type="button" 
                            class="dm-events-time-btn <?php echo ($view_mode === 'past') ? 'active' : ''; ?>" 
                            data-time="past">
                        <?php _e('Past Events', 'dm-events'); ?>
                    </button>
                    <button type="button" 
                            class="dm-events-time-btn <?php echo ($view_mode === 'all') ? 'active' : ''; ?>" 
                            data-time="all">
                        <?php _e('All Events', 'dm-events'); ?>
                    </button>
                </div>
                
                <?php if ($show_view_toggle) : ?>
                    <div class="dm-events-view-toggle">
                        <button type="button" 
                                class="dm-events-view-btn dm-events-view-list active" 
                                data-view="list">
                            <span class="dashicons dashicons-list-view"></span>
                            <?php _e('List', 'dm-events'); ?>
                        </button>
                        <button type="button" 
                                class="dm-events-view-btn dm-events-view-grid" 
                                data-view="grid">
                            <span class="dashicons dashicons-grid-view"></span>
                            <?php _e('Grid', 'dm-events'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="dm-events-content">
        <?php if (!empty($paged_events)) : ?>
            <div class="dm-events-list" id="dm-events-list">
                <?php foreach ($paged_events as $event_item) : 
                    $event_post = $event_item['post'];
                    $event_data = $event_item['event_data'];
                    
                    // Set global post for template functions
                    global $post;
                    $post = $event_post;
                    setup_postdata($post);
                    
                    // Map block attributes to display variables
                    $start_date = $event_data['startDate'] ?? '';
                    $start_time = $event_data['startTime'] ?? '';
                    $end_date = $event_data['endDate'] ?? '';
                    $end_time = $event_data['endTime'] ?? '';
                    $venue_name = $event_data['venue'] ?? '';
                    $venue_address = $event_data['address'] ?? '';
                    $artist_name = $event_data['artist'] ?? '';
                    $price = $event_data['price'] ?? '';
                    $ticket_url = $event_data['ticketUrl'] ?? '';

                    // Get block-level visibility settings
                    $show_venue = $event_data['showVenue'] ?? true;
                    $show_artist = $event_data['showArtist'] ?? true;
                    $show_price = $event_data['showPrice'] ?? true;
                    $show_ticket_link = $event_data['showTicketLink'] ?? true;
                    
                    // Generate date formats for display and JavaScript
                    $formatted_start_date = '';
                    $iso_start_date = '';
                    if ($start_date) {
                        $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
                        $formatted_start_date = $start_datetime_obj->format('M j, Y g:i A');
                        $iso_start_date = $start_datetime_obj->format('c'); // ISO 8601 for JS
                    }
                    ?>
                    <div class="dm-event-item" 
                         data-title="<?php echo esc_attr(get_the_title()); ?>"
                         data-venue="<?php echo esc_attr($venue_name); ?>"
                         data-artist="<?php echo esc_attr($artist_name); ?>"
                         data-date="<?php echo esc_attr($iso_start_date); ?>">
                        <div class="dm-event-card">
                            <div class="dm-event-card-body">
                                <h3 class="dm-event-title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h3>
                                
                                <div class="dm-event-meta">
                                    <div class="dm-event-date">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <?php echo esc_html($formatted_start_date); ?>
                                    </div>
                                    
                                    <?php if ($show_venue && !empty($venue_name)) : ?>
                                    <div class="dm-event-venue">
                                        <span class="dashicons dashicons-location"></span>
                                        <?php echo esc_html($venue_name); ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($show_artist && !empty($artist_name)) : ?>
                                        <div class="dm-event-artist">
                                            <span class="dashicons dashicons-admin-users"></span>
                                            <?php echo esc_html($artist_name); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="dm-event-excerpt">
                                    <?php the_excerpt(); ?>
                                </div>
                            </div>
                            
                            <div class="dm-event-card-footer">
                                <a href="<?php the_permalink(); ?>" class="dm-event-details-link">
                                    <?php _e('View Details', 'dm-events'); ?>
                                </a>
                                <?php if ($show_ticket_link && !empty($ticket_url)) : ?>
                                    <a href="<?php echo esc_url($ticket_url); ?>" 
                                       class="dm-event-ticket-link" 
                                       target="_blank" 
                                       rel="noopener noreferrer">
                                        <?php _e('Get Tickets', 'dm-events'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php 
            // Render pagination with validation
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
                
                // Validate pagination output
                if (!empty($pagination_links) && trim($pagination_links) !== '') : ?>
                    <div class="dm-events-pagination">
                        <?php echo $pagination_links; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
        <?php else : ?>
            <div class="dm-events-no-events">
                <p><?php _e('No events found.', 'dm-events'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Reset global post data
wp_reset_postdata();
?> 