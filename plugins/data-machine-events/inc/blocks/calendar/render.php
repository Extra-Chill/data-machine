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

use DataMachineEvents\Blocks\Calendar\DisplayStyles\CircuitGrid\CircuitGridRenderer;
use DataMachineEvents\Blocks\Calendar\Pagination;

$decode_unicode = function($str) {
    return html_entity_decode(preg_replace('/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $str), ENT_NOQUOTES, 'UTF-8');
};

// Early exit for REST API requests - prevent duplicate rendering
// REST API endpoint handles its own rendering via rest-api.php
if (wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
    return '';
}

$show_search = $attributes['showSearch'] ?? true;
$enable_pagination = $attributes['enablePagination'] ?? true;
$events_per_page = get_option('posts_per_page', 10);
$current_page = max(1, get_query_var('paged', 1));
$show_past = isset($_GET['past']) && $_GET['past'] === '1';

// Extract filter parameters from URL
$search_query = isset($_GET['event_search']) ? sanitize_text_field( wp_unslash( $_GET['event_search'] ) ) : '';
$date_start = isset($_GET['date_start']) ? sanitize_text_field( wp_unslash( $_GET['date_start'] ) ) : '';
$date_end = isset($_GET['date_end']) ? sanitize_text_field( wp_unslash( $_GET['date_end'] ) ) : '';
$tax_filters_raw = isset($_GET['tax_filter']) ? wp_unslash( $_GET['tax_filter'] ) : array();
$tax_filters = array();
if (is_array($tax_filters_raw)) {
    foreach ($tax_filters_raw as $taxonomy_slug => $term_ids) {
        $taxonomy_slug = sanitize_key($taxonomy_slug);
        $term_ids = (array) $term_ids;
        $clean_ids = array();
        foreach ($term_ids as $term_id) {
            $term_id = absint($term_id);
            if ($term_id > 0) {
                $clean_ids[] = $term_id;
            }
        }
        if (!empty($clean_ids)) {
            $tax_filters[$taxonomy_slug] = $clean_ids;
        }
    }
}

// Build WP_Query args for SQL-based pagination
$query_args = array(
    'post_type' => 'datamachine_events',
    'post_status' => 'publish',
    'posts_per_page' => $enable_pagination ? $events_per_page : -1,
    'paged' => $current_page,
    'meta_key' => '_datamachine_event_datetime',
    'orderby' => 'meta_value',
    'order' => $show_past ? 'DESC' : 'ASC',
);

// Meta query for past/future filtering and date ranges
$meta_query = array('relation' => 'AND');

// Past or future events
$current_datetime = current_time('mysql');
if ($show_past) {
    $meta_query[] = array(
        'key' => '_datamachine_event_datetime',
        'value' => $current_datetime,
        'compare' => '<',
        'type' => 'DATETIME'
    );
} else {
    $meta_query[] = array(
        'key' => '_datamachine_event_datetime',
        'value' => $current_datetime,
        'compare' => '>=',
        'type' => 'DATETIME'
    );
}

// Date range filters
if (!empty($date_start)) {
    $meta_query[] = array(
        'key' => '_datamachine_event_datetime',
        'value' => $date_start . ' 00:00:00',
        'compare' => '>=',
        'type' => 'DATETIME'
    );
}
if (!empty($date_end)) {
    $meta_query[] = array(
        'key' => '_datamachine_event_datetime',
        'value' => $date_end . ' 23:59:59',
        'compare' => '<=',
        'type' => 'DATETIME'
    );
}

$query_args['meta_query'] = $meta_query;

// Auto-detect taxonomy archives
if ( is_tax() ) {
    $term = get_queried_object();
    if ( $term && isset( $term->taxonomy ) && isset( $term->term_id ) ) {
        $query_args['tax_query'] = array(
            array(
                'taxonomy' => $term->taxonomy,
                'field'    => 'term_id',
                'terms'    => $term->term_id,
            ),
        );
    }
}

// Taxonomy filters from URL
if (!empty($tax_filters)) {
    $tax_query = isset($query_args['tax_query']) ? $query_args['tax_query'] : array();
    $tax_query['relation'] = 'AND';

    foreach ($tax_filters as $taxonomy => $term_ids) {
        $term_ids = is_array($term_ids) ? $term_ids : array($term_ids);
        $tax_query[] = array(
            'taxonomy' => sanitize_key($taxonomy),
            'field' => 'term_id',
            'terms' => array_map('absint', $term_ids),
            'operator' => 'IN'
        );
    }

    $query_args['tax_query'] = $tax_query;
}

// Search query
if (!empty($search_query)) {
    $query_args['s'] = $search_query;
}

// Allow external filtering of query args
$query_args = apply_filters( 'datamachine_events_calendar_query_args', $query_args, $attributes, $block );

// Execute SQL-based query
$events_query = new WP_Query($query_args);

// Pagination data
$total_events = $events_query->found_posts;
$max_pages = $events_query->max_num_pages;

// Get counts for past/upcoming navigation (separate queries)
$future_count_args = array(
    'post_type' => 'datamachine_events',
    'post_status' => 'publish',
    'fields' => 'ids',
    'posts_per_page' => 1,
    'meta_query' => array(
        array(
            'key' => '_datamachine_event_datetime',
            'value' => $current_datetime,
            'compare' => '>=',
            'type' => 'DATETIME'
        )
    )
);
$future_query = new WP_Query($future_count_args);
$future_events_count = $future_query->found_posts;

$past_count_args = array(
    'post_type' => 'datamachine_events',
    'post_status' => 'publish',
    'fields' => 'ids',
    'posts_per_page' => 1,
    'meta_query' => array(
        array(
            'key' => '_datamachine_event_datetime',
            'value' => $current_datetime,
            'compare' => '<',
            'type' => 'DATETIME'
        )
    )
);
$past_query = new WP_Query($past_count_args);
$past_events_count = $past_query->found_posts;

// Build paged_events array for display (parse blocks only for current page)
$paged_events = array();
if ($events_query->have_posts()) {
    while ($events_query->have_posts()) {
        $events_query->the_post();
        $event_post = get_post();

        // Parse blocks to extract event data (only for current page events)
        // Event Details block stores date/time in block attributes
        // Meta field (_datamachine_event_datetime) used for SQL queries, blocks used for display data
        $blocks = parse_blocks($event_post->post_content);
        $event_data = null;
        foreach ($blocks as $block) {
            if ('datamachine-events/event-details' === $block['blockName']) {
                $event_data = $block['attrs'];
                break; // Only one Event Details block per event
            }
        }

        if ($event_data && !empty($event_data['startDate'])) {
            $start_time = $event_data['startTime'] ?? '00:00:00';
            $event_datetime = new DateTime($event_data['startDate'] . ' ' . $start_time, wp_timezone());

            $paged_events[] = array(
                'post' => $event_post,
                'datetime' => $event_datetime,
                'event_data' => $event_data
            );
        }
    }
    wp_reset_postdata();
}

$paged_date_groups = array();
foreach ($paged_events as $event_item) {
    $event_data = $event_item['event_data'];
    $start_date = $event_data['startDate'] ?? '';
    
    if (!empty($start_date)) {
        $start_time = $event_data['startTime'] ?? '00:00:00';
        $start_datetime_obj = new DateTime($start_date . ' ' . $start_time, wp_timezone());
        $date_key = $start_datetime_obj->format('Y-m-d');
        
        if (!isset($paged_date_groups[$date_key])) {
            $paged_date_groups[$date_key] = array(
                'date_obj' => $start_datetime_obj,
                'events' => array()
            );
        }
        
        $paged_date_groups[$date_key]['events'][] = $event_item;
    }
}

uksort($paged_date_groups, function($a, $b) use ($show_past) {
    if ($show_past) {
        return strcmp($b, $a);
    } else {
        return strcmp($a, $b);
    }
});

// max_pages already calculated by WP_Query
$can_go_previous = $current_page > 1;
$can_go_next = $current_page < $max_pages;

$display_type = class_exists('DataMachineEvents\Admin\Settings_Page')
    ? \DataMachineEvents\Admin\Settings_Page::get_setting('calendar_display_type', 'circuit-grid')
    : 'circuit-grid';

// Gap detection for carousel-list mode only
// Shows visual separator when events are 2+ days apart
// Circuit Grid mode doesn't need separators (already grouped by date)
$gaps_detected = array();
if ($display_type === 'carousel-list' && !empty($paged_date_groups)) {
    $previous_date = null;
    foreach ($paged_date_groups as $date_key => $date_group) {
        if ($previous_date !== null) {
            $current_date = new DateTime($date_key, wp_timezone());
            $days_diff = $current_date->diff($previous_date)->days;

            // Mark gaps of 2 or more days for visual separator
            if ($days_diff > 1) {
                $gaps_detected[$date_key] = $days_diff;
            }
        }
        $previous_date = new DateTime($date_key, wp_timezone());
    }
}

\DataMachineEvents\Blocks\Calendar\Template_Loader::init();

$block_id = isset($block) && isset($block->clientId) ? (string) $block->clientId : uniqid('dm', true);
$instance_id = 'datamachine-calendar-' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $block_id ) ), 0, 12 );
$wrapper_attributes = get_block_wrapper_attributes(array(
    'class' => 'datamachine-events-calendar datamachine-events-date-grouped'
));
?>

<div data-instance-id="<?php echo esc_attr($instance_id); ?>" <?php echo $wrapper_attributes; ?>>
    <?php 
    $taxonomies_data = \DataMachineEvents\Blocks\Calendar\Taxonomy_Helper::get_all_taxonomies_with_counts();
$filter_count = !empty($tax_filters) ? array_sum(array_map('count', $tax_filters)) : 0;

\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('filter-bar', [
        'attributes' => $attributes,
        'used_taxonomies' => [],
        'instance_id' => $instance_id,
        'tax_filters' => $tax_filters,
        'search_query' => $search_query,
        'date_start' => $date_start,
        'date_end' => $date_end,
        'taxonomies_data' => $taxonomies_data,
        'filter_count' => $filter_count
    ]);
    ?>
    
    <div class="datamachine-events-content">
        <?php if (!empty($paged_date_groups)) : ?>
            <?php 
            if ($display_type === 'carousel-list') {
                wp_enqueue_style(
                    'datamachine-events-carousel-list',
                    plugin_dir_url(__FILE__) . 'DisplayStyles/CarouselList/carousel-list.css',
                    array('datamachine-events-root'),
                    filemtime(plugin_dir_path(__FILE__) . 'DisplayStyles/CarouselList/carousel-list.css')
                );
            } else {
                wp_enqueue_style(
                    'datamachine-events-circuit-grid',
                    plugin_dir_url(__FILE__) . 'DisplayStyles/CircuitGrid/circuit-grid.css',
                    array('datamachine-events-root'),
                    filemtime(plugin_dir_path(__FILE__) . 'DisplayStyles/CircuitGrid/circuit-grid.css')
                );
            }
            ?>
            
            <svg class="datamachine-border-overlay" xmlns="http://www.w3.org/2000/svg">
            </svg>
                <?php
                
                foreach ($paged_date_groups as $date_key => $date_group) :
                    $date_obj = $date_group['date_obj'];
                    $events_for_date = $date_group['events'];

                    // Show time gap separator if in carousel mode and gap exists
                    if ($display_type === 'carousel-list' && isset($gaps_detected[$date_key])) {
                        \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('time-gap-separator', [
                            'gap_days' => $gaps_detected[$date_key]
                        ]);
                    }

                    $day_number = (int) $date_obj->format('w');
                    $day_name = $date_obj->format('l');
                    $day_of_week = strtolower($day_name);
                    $formatted_date_label = $date_obj->format('l, F jS');

                    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('date-group', [
                        'date_obj' => $date_obj,
                        'day_of_week' => $day_of_week,
                        'formatted_date_label' => $formatted_date_label
                    ]);
                    ?>

                    <div class="datamachine-events-wrapper">
                        <?php
                    foreach ($events_for_date as $event_item) : 
                        $event_post = $event_item['post'];
                        $event_data = $event_item['event_data'];
                        
                        global $post;
                        $post = $event_post;
                        setup_postdata($post);
                        
                        $start_date = $event_data['startDate'] ?? '';
                        $start_time = $event_data['startTime'] ?? '';
                        $venue_name = $decode_unicode($event_data['venue'] ?? '');
                        $performer_name = $decode_unicode($event_data['performer'] ?? '');
                        
                        $formatted_start_time = '';
                        $iso_start_date = '';
                        if ($start_date) {
                            $start_datetime_obj = new DateTime($start_date . ' ' . $start_time, wp_timezone());
                            $formatted_start_time = $start_datetime_obj->format('g:i A');
                            $iso_start_date = $start_datetime_obj->format('c');
                        }
                        
                        $display_vars = [
                            'formatted_start_time' => $formatted_start_time,
                            'venue_name' => $venue_name,
                            'performer_name' => $performer_name,
                            'iso_start_date' => $iso_start_date,
                            
                            'show_performer' => $event_data['showPerformer'] ?? true,
                            'show_price' => $event_data['showPrice'] ?? true,
                            'show_ticket_link' => $event_data['showTicketLink'] ?? true
                        ];
                        
                        \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('event-item', [
                            'event_post' => $event_post,
                            'event_data' => $event_data,
                            'display_vars' => $display_vars
                        ]);
                    endforeach;
                    ?>
                    </div><!-- .datamachine-events-wrapper -->
                    <?php

                    echo '</div><!-- .datamachine-date-group -->';
                    
                endforeach;
                ?>
            
        <?php else : ?>
            <?php \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('no-events'); ?>
        <?php endif; ?>
    </div>

    <?php
    // Moved results counter, pagination and navigation outside of the grid container
    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('results-counter', [
        'current_page' => $current_page,
        'total_events' => $total_events,
        'events_per_page' => $events_per_page
    ]);

    echo Pagination::render_pagination($current_page, $max_pages, $show_past, $enable_pagination);

    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('navigation', [
        'show_past' => $show_past,
        'past_events_count' => $past_events_count,
        'future_events_count' => $future_events_count
    ]);
    ?>
</div>

<?php
wp_reset_postdata();
?> 