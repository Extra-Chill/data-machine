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

use DmEvents\Blocks\Calendar\DisplayStyles\CircuitGrid\CircuitGridRenderer;
if (wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
    return '';
}

$show_search = $attributes['showSearch'] ?? true;
$enable_pagination = $attributes['enablePagination'] ?? true;
$events_per_page = get_option('posts_per_page', 10);
$current_page = max(1, get_query_var('paged', 1));
$show_past = isset($_GET['past']) && $_GET['past'] === '1';

$current_utc = current_time('mysql', 1);
$query_args = array(
    'post_type' => 'dm_events',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'date',
    'order' => 'DESC'
);

// Auto-detect taxonomy archives and filter events
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

// Allow external filtering of query args
$query_args = apply_filters( 'dm_events_calendar_query_args', $query_args, $attributes, $block );

$all_events = get_posts($query_args);
$filtered_events = array();

foreach ($all_events as $event_post) {
    $blocks = parse_blocks($event_post->post_content);
    $event_data = null;
    foreach ($blocks as $block) {
        if ('dm-events/event-details' === $block['blockName']) {
            $event_data = $block['attrs'];
            break;
        }
    }

    if (empty($event_data['startDate'])) {
        continue;
    }

    $start_time = $event_data['startTime'] ?? '00:00:00';
    $event_datetime = new DateTime($event_data['startDate'] . ' ' . $start_time);
    $current_datetime = new DateTime($current_utc);

    $filtered_events[] = array(
        'post' => $event_post,
        'datetime' => $event_datetime,
        'event_data' => $event_data
    );
}

$current_datetime = new DateTime($current_utc);
$future_events = array();
$past_events = array();

foreach ($filtered_events as $event_item) {
    if ($event_item['datetime'] >= $current_datetime) {
        $future_events[] = $event_item;
    } else {
        $past_events[] = $event_item;
    }
}

usort($future_events, function($a, $b) {
    return $a['datetime'] <=> $b['datetime'];
});

usort($past_events, function($a, $b) {
    return $b['datetime'] <=> $a['datetime'];
});

if ($show_past) {
    $events_offset = ($current_page - 1) * $events_per_page;
    $page_events = $enable_pagination ? array_slice($past_events, $events_offset, $events_per_page) : $past_events;
    $total_events_in_direction = count($past_events);
} else {
    $events_offset = ($current_page - 1) * $events_per_page;
    $page_events = $enable_pagination ? array_slice($future_events, $events_offset, $events_per_page) : $future_events;
    $total_events_in_direction = count($future_events);
}

$paged_events = $page_events;
$total_events = $total_events_in_direction;

$paged_date_groups = array();
foreach ($paged_events as $event_item) {
    $event_data = $event_item['event_data'];
    $start_date = $event_data['startDate'] ?? '';
    
    if (!empty($start_date)) {
        $start_time = $event_data['startTime'] ?? '00:00:00';
        $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
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

$max_pages = $enable_pagination ? ceil($total_events / $events_per_page) : 1;
$can_go_previous = $current_page > 1;
$can_go_next = $current_page < $max_pages;

$display_type = \DmEvents\Admin\Settings_Page::get_setting('calendar_display_type', 'circuit-grid');

// Gap detection for carousel-list mode only
$gaps_detected = array();
if ($display_type === 'carousel-list' && !empty($paged_date_groups)) {
    $previous_date = null;
    foreach ($paged_date_groups as $date_key => $date_group) {
        if ($previous_date !== null) {
            $current_date = new DateTime($date_key);
            $days_diff = $current_date->diff($previous_date)->days;

            // Mark gaps of 2 or more days
            if ($days_diff > 1) {
                $gaps_detected[$date_key] = $days_diff;
            }
        }
        $previous_date = new DateTime($date_key);
    }
}

\DmEvents\Blocks\Calendar\Template_Loader::init();

$wrapper_attributes = get_block_wrapper_attributes(array(
    'class' => 'dm-events-calendar dm-events-date-grouped'
));
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php 
    \DmEvents\Blocks\Calendar\Template_Loader::include_template('filter-bar', [
        'attributes' => $attributes,
        'used_taxonomies' => []
    ]);
    ?>
    
    <div class="dm-events-content">
        <?php if (!empty($paged_date_groups)) : ?>
            <?php 
            if ($display_type === 'carousel-list') {
                wp_enqueue_style(
                    'dm-events-carousel-list',
                    plugin_dir_url(__FILE__) . 'DisplayStyles/CarouselList/carousel-list.css',
                    [],
                    filemtime(plugin_dir_path(__FILE__) . 'DisplayStyles/CarouselList/carousel-list.css')
                );
            } else {
                wp_enqueue_style(
                    'dm-events-circuit-grid',
                    plugin_dir_url(__FILE__) . 'DisplayStyles/CircuitGrid/circuit-grid.css',
                    [],
                    filemtime(plugin_dir_path(__FILE__) . 'DisplayStyles/CircuitGrid/circuit-grid.css')
                );
            }
            ?>
            
            <svg class="dm-border-overlay" xmlns="http://www.w3.org/2000/svg">
            </svg>
                <?php
                
                foreach ($paged_date_groups as $date_key => $date_group) :
                    $date_obj = $date_group['date_obj'];
                    $events_for_date = $date_group['events'];

                    // Show time gap separator if in carousel mode and gap exists
                    if ($display_type === 'carousel-list' && isset($gaps_detected[$date_key])) {
                        \DmEvents\Blocks\Calendar\Template_Loader::include_template('time-gap-separator', [
                            'gap_days' => $gaps_detected[$date_key]
                        ]);
                    }

                    $day_number = (int) $date_obj->format('w');
                    $day_name = $date_obj->format('l');
                    $day_of_week = strtolower($day_name);
                    $formatted_date_label = $date_obj->format('l, F jS');

                    \DmEvents\Blocks\Calendar\Template_Loader::include_template('date-group', [
                        'date_obj' => $date_obj,
                        'day_of_week' => $day_of_week,
                        'formatted_date_label' => $formatted_date_label
                    ]);
                    ?>

                    <div class="dm-events-wrapper">
                        <?php
                    foreach ($events_for_date as $event_item) : 
                        $event_post = $event_item['post'];
                        $event_data = $event_item['event_data'];
                        
                        global $post;
                        $post = $event_post;
                        setup_postdata($post);
                        
                        $start_date = $event_data['startDate'] ?? '';
                        $start_time = $event_data['startTime'] ?? '';
                        $venue_name = $event_data['venue'] ?? '';
                        $performer_name = $event_data['performer'] ?? '';
                        
                        $formatted_start_time = '';
                        $iso_start_date = '';
                        if ($start_date) {
                            $start_datetime_obj = new DateTime($start_date . ' ' . $start_time);
                            $formatted_start_time = $start_datetime_obj->format('g:i A');
                            $iso_start_date = $start_datetime_obj->format('c');
                        }
                        
                        $display_vars = [
                            'formatted_start_time' => $formatted_start_time,
                            'venue_name' => $venue_name,
                            'performer_name' => $performer_name,
                            'iso_start_date' => $iso_start_date,
                            'show_venue' => $event_data['showVenue'] ?? true,
                            'show_performer' => $event_data['showPerformer'] ?? true,
                            'show_price' => $event_data['showPrice'] ?? true,
                            'show_ticket_link' => $event_data['showTicketLink'] ?? true
                        ];
                        
                        \DmEvents\Blocks\Calendar\Template_Loader::include_template('event-item', [
                            'event_post' => $event_post,
                            'event_data' => $event_data,
                            'display_vars' => $display_vars
                        ]);
                    endforeach;
                    ?>
                    </div><!-- .dm-events-wrapper -->
                    <?php

                    echo '</div><!-- .dm-date-group -->';
                    
                endforeach;
                ?>
            
            <?php 
            \DmEvents\Blocks\Calendar\Template_Loader::include_template('pagination', [
                'current_page' => $current_page,
                'max_pages' => $max_pages,
                'show_past' => $show_past,
                'enable_pagination' => $enable_pagination
            ]);
            ?>
            
            <?php 
            \DmEvents\Blocks\Calendar\Template_Loader::include_template('navigation', [
                'show_past' => $show_past,
                'past_events_count' => count($past_events),
                'future_events_count' => count($future_events)
            ]);
            ?>
            
        <?php else : ?>
            <?php \DmEvents\Blocks\Calendar\Template_Loader::include_template('no-events'); ?>
        <?php endif; ?>
    </div>
</div>

<?php
wp_reset_postdata();
?> 