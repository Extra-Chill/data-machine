<?php
/**
 * Calendar Block Server-Side Render Template
 *
 * Renders events calendar with filtering and pagination.
 *
 * @var array $attributes Block attributes
 * @var string $content Block inner content
 * @var WP_Block $block Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent rendering during REST API requests
if (wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
    return '';
}

// Extract block configuration with defaults
$events_to_show = $attributes['eventsToShow'] ?? 10;
$show_past_events = $attributes['showPastEvents'] ?? false;
$show_filters = $attributes['showFilters'] ?? true;
$show_search = $attributes['showSearch'] ?? true;
$show_date_filter = $attributes['showDateFilter'] ?? true;
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

// Group events by date
$events_by_date = array();
foreach ($filtered_events as $event_item) {
    $event_data = $event_item['event_data'];
    $start_date = $event_data['startDate'] ?? '';
    
    if (!empty($start_date)) {
        $start_time = $event_data['startTime'] ?? '00:00:00';
        $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
        $date_key = $start_datetime_obj->format('Y-m-d'); // Group by calendar date
        
        if (!isset($events_by_date[$date_key])) {
            $events_by_date[$date_key] = array(
                'date_obj' => $start_datetime_obj,
                'events' => array()
            );
        }
        
        $events_by_date[$date_key]['events'][] = $event_item;
    }
}

// Sort date groups chronologically
uksort($events_by_date, function($a, $b) use ($view_mode) {
    if ($view_mode === 'past') {
        return strcmp($b, $a); // Recent past first
    } else {
        return strcmp($a, $b); // Earliest future first
    }
});

// Apply pagination to date groups
$total_date_groups = count($events_by_date);
$groups_per_page = $enable_pagination ? max(1, intval($events_per_page / 3)) : $total_date_groups; // Fewer groups per page
$offset = ($current_page - 1) * $groups_per_page;
$paged_date_groups = array_slice($events_by_date, $offset, $groups_per_page, true);

// Create query object for pagination
$events_query = new stdClass();
$events_query->post_count = array_sum(array_map(function($group) { return count($group['events']); }, $paged_date_groups));
$events_query->found_posts = array_sum(array_map(function($group) { return count($group['events']); }, $events_by_date));
$events_query->max_num_pages = $enable_pagination ? ceil($total_date_groups / $groups_per_page) : 1;
$events_query->current_post = -1;

// Generate block wrapper with CSS classes
$wrapper_attributes = get_block_wrapper_attributes(array(
    'class' => 'dm-events-calendar dm-events-date-grouped'
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
            </div>
        </div>
    <?php endif; ?>
    
    <div class="dm-events-content">
        <?php if (!empty($paged_date_groups)) : ?>
            <svg class="dm-border-overlay" xmlns="http://www.w3.org/2000/svg">
                <!-- Day group borders rendered by JavaScript -->
            </svg>            
            <div class="dm-events-list" id="dm-events-list">
                <?php
                
                foreach ($paged_date_groups as $date_key => $date_group) : 
                    $date_obj = $date_group['date_obj'];
                    $events_for_date = $date_group['events'];
                    
                    // Calculate day of week data for each event
                    $day_number = (int) $date_obj->format('w'); // 0 = Sunday, 6 = Saturday
                    $day_name = $date_obj->format('l'); // Full day name
                    $day_of_week = strtolower($day_name); // For CSS class
                    $formatted_date_label = $date_obj->format('l, F jS'); // "Saturday, August 31st"
                    ?>
                    
                    <div class="dm-date-group dm-day-<?php echo esc_attr($day_of_week); ?>" data-date="<?php echo esc_attr($date_obj->format('Y-m-d')); ?>">
                        <div class="dm-day-badge dm-day-badge-<?php echo esc_attr($day_of_week); ?>" 
                             data-date-label="<?php echo esc_attr($formatted_date_label); ?>" 
                             data-day-name="<?php echo esc_attr($day_of_week); ?>">
                            <?php echo esc_html($formatted_date_label); ?>
                        </div>
                    <?php
                    
                    foreach ($events_for_date as $event_item) : 
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
                                $formatted_start_time = '';
                                $iso_start_date = '';
                                if ($start_date) {
                                    $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
                                    $formatted_start_time = $start_datetime_obj->format('g:i A'); // Just time for individual events
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
                                            <h4 class="dm-event-title">
                                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                            </h4>
                                            
                                            <div class="dm-event-meta">
                                                <?php if (!empty($formatted_start_time)) : ?>
                                                <div class="dm-event-time">
                                                    <span class="dashicons dashicons-clock"></span>
                                                    <?php echo esc_html($formatted_start_time); ?>
                                                </div>
                                                <?php endif; ?>
                                                
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
                    <?php 
                    endforeach; // End events loop
                    ?>
                    </div><!-- .dm-date-group -->
                    <?php
                endforeach; // End date groups loop 
                ?>
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