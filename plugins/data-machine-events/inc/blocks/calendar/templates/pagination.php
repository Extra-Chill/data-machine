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

// Preserve all GET parameters except 'paged'
$get_params = isset( $_GET ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET ) ) : array();
unset( $get_params['paged'] );

$pagination_args = array(
    'base'      => add_query_arg( 'paged', '%#%' ),
    'format'    => '',
    'current'   => $current_page,
    'total'     => $max_pages,
    'prev_text' => __( '« Previous', 'datamachine-events' ),
    'next_text' => __( 'Next »', 'datamachine-events' ),
    'type'      => 'list',
    'end_size'  => 1,
    'mid_size'  => 2,
    'add_args'  => $get_params, // Preserve all filters
);

$pagination_links = paginate_links( $pagination_args );

// Only render if pagination links were generated
if ( ! empty( $pagination_links ) && trim( $pagination_links ) !== '' ) :
	?>

<nav class="datamachine-events-pagination" aria-label="<?php esc_attr_e( 'Events pagination', 'datamachine-events' ); ?>">
	<?php echo wp_kses_post( $pagination_links ); ?>
</nav>

<?php endif; ?>