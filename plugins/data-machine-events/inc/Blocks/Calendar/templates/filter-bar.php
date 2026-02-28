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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$show_search = $attributes['showSearch'] ?? true;

if ( ! $show_search ) {
	return;
}

$instance_id   = $instance_id ?? uniqid( 'datamachine-calendar-' );
$search_id     = 'datamachine-events-search-' . $instance_id;
$search_value  = isset( $search_query ) ? $search_query : '';
$date_range_id = 'datamachine-events-date-range-' . $instance_id;
$modal_id      = 'datamachine-taxonomy-filter-modal-' . $instance_id;

$archive_context     = $archive_context ?? array(
	'taxonomy'  => '',
	'term_id'   => 0,
	'term_name' => '',
);
$has_archive_context = ! empty( $archive_context['taxonomy'] ) && ! empty( $archive_context['term_id'] );

$hide_filter_button_when_inactive = $hide_filter_button_when_inactive ?? false;
$hide_filter_button_attr          = $hide_filter_button_when_inactive ? ' hidden data-hide-when-inactive="1"' : '';
?>

<div class="datamachine-events-filter-bar">
	<div class="datamachine-events-filter-row">
		<div class="datamachine-events-search">
			<input type="text" 
					id="<?php echo esc_attr( $search_id ); ?>" 
					value="<?php echo esc_attr( $search_value ); ?>"
					placeholder="<?php esc_html_e( 'Search events...', 'datamachine-events' ); ?>" 
					class="datamachine-events-search-input">
			<button type="button" class="datamachine-events-search-btn">
				<span class="dashicons dashicons-search"></span>
			</button>
		</div>
		
		<div class="datamachine-events-date-filter">
			<div class="datamachine-events-date-range-wrapper">
				<input type="text" 
						id="<?php echo esc_attr( $date_range_id ); ?>"
						class="datamachine-events-date-range-input" data-date-start="<?php echo esc_attr( $date_start ); ?>" data-date-end="<?php echo esc_attr( $date_end ); ?>" 
						placeholder="<?php esc_html_e( 'Select date range...', 'datamachine-events' ); ?>" 
						readonly />
				<button type="button" 
						class="datamachine-events-date-clear-btn" 
						title="<?php esc_html_e( 'Clear date filter', 'datamachine-events' ); ?>">
					✕
				</button>
			</div>
		</div>
		
		<div class="datamachine-events-taxonomy-filter">
			<button<?php echo $hide_filter_button_attr; ?> type="button" class="datamachine-events-filter-btn datamachine-taxonomy-modal-trigger<?php echo ( ! empty( $tax_filters ) ? ' datamachine-filters-active' : '' ); ?>" data-modal-id="<?php echo esc_attr( $modal_id ); ?>" aria-controls="<?php echo esc_attr( $modal_id ); ?>" aria-expanded="<?php echo ( ! empty( $tax_filters ) ? 'true' : 'false' ); ?>">
				<span class="datamachine-filter-count" aria-hidden="true"><?php echo ( ! empty( $tax_filters ) ? array_sum( array_map( 'count', $tax_filters ) ) : '' ); ?></span>
				<span class="dashicons dashicons-filter"></span>
				<?php esc_html_e( 'Filter', 'datamachine-events' ); ?>
			</button>
		</div>
	</div>
	
	<!-- Taxonomy Filter Modal -->
	<div id="<?php echo esc_attr( $modal_id ); ?>" class="datamachine-taxonomy-modal" aria-labelledby="<?php echo esc_attr( $modal_id . '-title' ); ?>"
	<?php
	if ( $has_archive_context ) :
		?>
		data-archive-taxonomy="<?php echo esc_attr( $archive_context['taxonomy'] ); ?>" data-archive-term-id="<?php echo esc_attr( $archive_context['term_id'] ); ?>" data-archive-term-name="<?php echo esc_attr( $archive_context['term_name'] ); ?>"<?php endif; ?>>
		<div class="datamachine-taxonomy-modal-overlay"></div>
		<div class="datamachine-taxonomy-modal-container">
			<div class="datamachine-taxonomy-modal-header">
				<h2 id="<?php echo esc_attr( $modal_id . '-title' ); ?>" class="datamachine-taxonomy-modal-title"><?php esc_html_e( 'Event Display Filters', 'datamachine-events' ); ?></h2>
				<button type="button" class="datamachine-taxonomy-modal-close" aria-label="<?php esc_attr_e( 'Close', 'datamachine-events' ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="datamachine-taxonomy-modal-body">
				<?php
				require __DIR__ . '/modal/taxonomy-filter.php';
				?>
			</div>
			<div class="datamachine-taxonomy-modal-footer">
				<div class="datamachine-modal-actions">
					<div class="datamachine-modal-actions-left">
						<button type="button" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_modal_button_classes', array( 'datamachine-button', 'datamachine-clear-all-filters' ), 'secondary' ) ) ); ?>">
							<?php esc_html_e( 'Clear All Filters', 'datamachine-events' ); ?>
						</button>
					</div>
					<div class="datamachine-modal-actions-right">
						<button type="button" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_modal_button_classes', array( 'datamachine-button', 'datamachine-button-primary', 'datamachine-apply-filters' ), 'primary' ) ) ); ?>">
							<?php esc_html_e( 'Apply Filters', 'datamachine-events' ); ?>
						</button>
						<button type="button" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_modal_button_classes', array( 'datamachine-button', 'datamachine-modal-close' ), 'secondary' ) ) ); ?>">
							<?php esc_html_e( 'Cancel', 'datamachine-events' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>