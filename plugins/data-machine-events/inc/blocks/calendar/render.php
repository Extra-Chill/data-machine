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

use DataMachineEvents\Blocks\Calendar\Calendar_Query;
use DataMachineEvents\Blocks\Calendar\Pagination;

if (wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
    return '';
}

$show_search = $attributes['showSearch'] ?? true;

$current_page = 1;
if (isset($_GET['paged']) && absint($_GET['paged']) > 0) {
    $current_page = absint($_GET['paged']);
} elseif (get_query_var('paged')) {
    $current_page = max(1, (int) get_query_var('paged'));
}

$show_past = isset($_GET['past']) && $_GET['past'] === '1';

$search_query = isset($_GET['event_search']) ? sanitize_text_field(wp_unslash($_GET['event_search'])) : '';
$date_start = isset($_GET['date_start']) ? sanitize_text_field(wp_unslash($_GET['date_start'])) : '';
$date_end = isset($_GET['date_end']) ? sanitize_text_field(wp_unslash($_GET['date_end'])) : '';
$tax_filters_raw = isset($_GET['tax_filter']) ? wp_unslash($_GET['tax_filter']) : [];
$tax_filters = [];

if (is_array($tax_filters_raw)) {
    foreach ($tax_filters_raw as $taxonomy_slug => $term_ids) {
        $taxonomy_slug = sanitize_key($taxonomy_slug);
        $term_ids = (array) $term_ids;
        $clean_ids = [];
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

$tax_query_override = null;
$archive_context = [
    'taxonomy' => '',
    'term_id' => 0,
    'term_name' => '',
];

if (is_tax()) {
    $term = get_queried_object();
    if ($term && isset($term->taxonomy) && isset($term->term_id)) {
        $tax_query_override = [
            [
                'taxonomy' => $term->taxonomy,
                'field' => 'term_id',
                'terms' => $term->term_id,
            ],
        ];
        $archive_context = [
            'taxonomy' => $term->taxonomy,
            'term_id' => $term->term_id,
            'term_name' => $term->name,
        ];
    }
}

$user_date_start = $date_start;
$user_date_end = $date_end;

$base_params = [
    'show_past' => $show_past,
    'search_query' => $search_query,
    'date_start' => $user_date_start,
    'date_end' => $user_date_end,
    'tax_filters' => $tax_filters,
    'tax_query_override' => $tax_query_override,
    'archive_taxonomy' => $archive_context['taxonomy'],
    'archive_term_id' => $archive_context['term_id'],
    'source' => 'render',
];

$unique_dates = Calendar_Query::get_unique_event_dates($base_params);
$date_boundaries = Calendar_Query::get_date_boundaries_for_page($unique_dates, $current_page);

$max_pages = $date_boundaries['max_pages'];
$current_page = max(1, min($current_page, max(1, $max_pages)));

$query_params = $base_params;
if (!empty($date_boundaries['start_date']) && !empty($date_boundaries['end_date'])) {
    if (empty($user_date_start)) {
        $query_params['date_start'] = $date_boundaries['start_date'];
    }
    if (empty($user_date_end)) {
        $query_params['date_end'] = $date_boundaries['end_date'];
    }
}

$query_args = Calendar_Query::build_query_args($query_params);
$events_query = new WP_Query($query_args);

$total_events = count($unique_dates);

$event_counts = Calendar_Query::get_event_counts();
$past_events_count = $event_counts['past'];
$future_events_count = $event_counts['future'];

$paged_events = Calendar_Query::build_paged_events($events_query);
$paged_date_groups = Calendar_Query::group_events_by_date($paged_events, $show_past);

$can_go_previous = $current_page > 1;
$can_go_next = $current_page < $max_pages;

$gaps_detected = !empty($paged_date_groups) 
    ? Calendar_Query::detect_time_gaps($paged_date_groups) 
    : [];

\DataMachineEvents\Blocks\Calendar\Template_Loader::init();

$block_id = isset($block) && isset($block->clientId) ? (string) $block->clientId : uniqid('dm', true);
$instance_id = 'datamachine-calendar-' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($block_id)), 0, 12);
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'datamachine-events-calendar datamachine-events-date-grouped'
]);

$archive_data_attrs = '';
if (!empty($archive_context['taxonomy'])) {
    $archive_data_attrs = sprintf(
        ' data-archive-taxonomy="%s" data-archive-term-id="%d" data-archive-term-name="%s"',
        esc_attr($archive_context['taxonomy']),
        esc_attr($archive_context['term_id']),
        esc_attr($archive_context['term_name'])
    );
}
?>

<div data-instance-id="<?php echo esc_attr($instance_id); ?>"<?php echo $archive_data_attrs; ?> <?php echo $wrapper_attributes; ?>>
    <?php 
    $filter_count = !empty($tax_filters) ? array_sum(array_map('count', $tax_filters)) : 0;

    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('filter-bar', [
        'attributes' => $attributes,
        'instance_id' => $instance_id,
        'tax_filters' => $tax_filters,
        'search_query' => $search_query,
        'date_start' => $date_start,
        'date_end' => $date_end,
        'filter_count' => $filter_count,
        'archive_context' => $archive_context,
    ]);
    ?>
    
    <div class="datamachine-events-content">
        <?php if (!empty($paged_date_groups)) : ?>
            <?php
            foreach ($paged_date_groups as $date_key => $date_group) :
                $date_obj = $date_group['date_obj'];
                $events_for_date = $date_group['events'];

                if (isset($gaps_detected[$date_key])) {
                    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('time-gap-separator', [
                        'gap_days' => $gaps_detected[$date_key]
                    ]);
                }

                $day_of_week = strtolower($date_obj->format('l'));
                $formatted_date_label = $date_obj->format('l, F jS');

                \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('date-group', [
                    'date_obj' => $date_obj,
                    'day_of_week' => $day_of_week,
                    'formatted_date_label' => $formatted_date_label,
                    'events_count' => count($events_for_date)
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
                        
                        $display_vars = Calendar_Query::build_display_vars($event_data);
                        
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
    \DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('results-counter', [
        'page_start_date' => $date_boundaries['start_date'],
        'page_end_date' => $date_boundaries['end_date'],
        'event_count' => $events_query->post_count,
    ]);

    echo Pagination::render_pagination($current_page, $max_pages, $show_past);

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
