<?php
/**
 * Pagination Template
 *
 * Renders WordPress-style pagination controls for calendar events.
 *
 * @var int $current_page Current page number
 * @var int $max_pages Total number of pages
 * @var bool $show_past Whether currently showing past events
 * @var bool $enable_pagination Whether pagination is enabled
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Don't render if pagination disabled or only one page
if (!$enable_pagination || $max_pages <= 1) {
    return;
}

$pagination_args = array(
    'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
    'format' => '?paged=%#%',
    'current' => $current_page,
    'total' => $max_pages,
    'prev_text' => __('&laquo; Previous'),
    'next_text' => __('Next &raquo;'),
    'type' => 'list',
    'end_size' => 3,
    'mid_size' => 3,
    'echo' => false
);

// Add past parameter if showing past events
if ($show_past) {
    $pagination_args['add_args'] = array('past' => '1');
}

$pagination_links = paginate_links($pagination_args);

// Only render if pagination links were generated
if (!empty($pagination_links) && trim($pagination_links) !== '') :
?>

<div class="dm-events-pagination">
    <?php echo $pagination_links; ?>
</div>

<?php endif; ?>