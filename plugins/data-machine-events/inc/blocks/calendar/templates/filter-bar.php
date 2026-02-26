<?php
/**
 * Calendar Filter Bar Template
 *
 * Renders the complete filter bar with:
 * - Location input (address/Near Me + radius selector)
 * - Search input
 * - Date range picker
 * - Inline collapsible taxonomy filters (replaces modal)
 *
 * @var array  $attributes Block attributes
 * @var string $instance_id Calendar instance ID
 * @var array  $tax_filters Active taxonomy filters
 * @var string $search_query Current search query
 * @var string $date_start Date range start
 * @var string $date_end Date range end
 * @var int    $filter_count Number of active taxonomy filters
 * @var array  $archive_context Archive page context
 * @var bool   $hide_filter_button_when_inactive Whether to hide filter toggle
 * @var string $geo_lat Current geo latitude
 * @var string $geo_lng Current geo longitude
 * @var int    $geo_radius Current geo radius
 * @var string $geo_radius_unit Current geo radius unit
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
$location_id   = 'datamachine-events-location-' . $instance_id;
$filters_id    = 'datamachine-taxonomy-filters-' . $instance_id;

$archive_context     = $archive_context ?? array(
	'taxonomy'  => '',
	'term_id'   => 0,
	'term_name' => '',
);
$has_archive_context = ! empty( $archive_context['taxonomy'] ) && ! empty( $archive_context['term_id'] );

$hide_filter_button_when_inactive = $hide_filter_button_when_inactive ?? false;
$hide_filter_button_attr          = $hide_filter_button_when_inactive ? ' hidden data-hide-when-inactive="1"' : '';

$geo_lat         = $geo_lat ?? '';
$geo_lng         = $geo_lng ?? '';
$geo_radius      = $geo_radius ?? 25;
$geo_radius_unit = $geo_radius_unit ?? 'mi';
$has_geo         = ! empty( $geo_lat ) && ! empty( $geo_lng );
?>

<div class="datamachine-events-filter-bar">
	<!-- Location Filter Row -->
	<div class="datamachine-events-location-row">
		<div class="datamachine-events-location-input" id="<?php echo esc_attr( $location_id ); ?>">
			<div class="datamachine-events-location-field">
				<span class="dashicons dashicons-location" aria-hidden="true"></span>
				<input type="text"
						class="datamachine-events-location-search"
						placeholder="<?php esc_html_e( 'Enter city or address...', 'datamachine-events' ); ?>"
						autocomplete="off"
						data-geo-lat="<?php echo esc_attr( $geo_lat ); ?>"
						data-geo-lng="<?php echo esc_attr( $geo_lng ); ?>" />
				<button type="button"
						class="datamachine-events-nearme-btn"
						title="<?php esc_attr_e( 'Use my location', 'datamachine-events' ); ?>">
					<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Near Me', 'datamachine-events' ); ?>
				</button>
				<?php if ( $has_geo ) : ?>
				<button type="button"
						class="datamachine-events-location-clear-btn"
						title="<?php esc_attr_e( 'Clear location', 'datamachine-events' ); ?>">
					✕
				</button>
				<?php endif; ?>
			</div>
			<div class="datamachine-events-location-autocomplete" hidden></div>
			<div class="datamachine-events-radius-selector">
				<label for="<?php echo esc_attr( $location_id . '-radius' ); ?>" class="screen-reader-text">
					<?php esc_html_e( 'Search radius', 'datamachine-events' ); ?>
				</label>
				<select id="<?php echo esc_attr( $location_id . '-radius' ); ?>"
						class="datamachine-events-radius-select"
						data-radius-unit="<?php echo esc_attr( $geo_radius_unit ); ?>">
					<option value="5" <?php selected( $geo_radius, 5 ); ?>>5 <?php echo esc_html( 'mi' === $geo_radius_unit ? 'mi' : 'km' ); ?></option>
					<option value="10" <?php selected( $geo_radius, 10 ); ?>>10 <?php echo esc_html( 'mi' === $geo_radius_unit ? 'mi' : 'km' ); ?></option>
					<option value="25" <?php selected( $geo_radius, 25 ); ?>>25 <?php echo esc_html( 'mi' === $geo_radius_unit ? 'mi' : 'km' ); ?></option>
					<option value="50" <?php selected( $geo_radius, 50 ); ?>>50 <?php echo esc_html( 'mi' === $geo_radius_unit ? 'mi' : 'km' ); ?></option>
					<option value="100" <?php selected( $geo_radius, 100 ); ?>>100 <?php echo esc_html( 'mi' === $geo_radius_unit ? 'mi' : 'km' ); ?></option>
				</select>
			</div>
		</div>
	</div>

	<!-- Search + Date + Filter Toggle Row -->
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
			<button<?php echo $hide_filter_button_attr; ?> type="button" class="datamachine-events-filter-btn datamachine-taxonomy-toggle<?php echo ( ! empty( $tax_filters ) ? ' datamachine-filters-active' : '' ); ?>" aria-controls="<?php echo esc_attr( $filters_id ); ?>" aria-expanded="<?php echo ( ! empty( $tax_filters ) ? 'true' : 'false' ); ?>">
				<span class="datamachine-filter-count" aria-hidden="true"><?php echo ( ! empty( $tax_filters ) ? array_sum( array_map( 'count', $tax_filters ) ) : '' ); ?></span>
				<span class="dashicons dashicons-filter"></span>
				<?php esc_html_e( 'Filter', 'datamachine-events' ); ?>
			</button>
		</div>
	</div>

	<!-- Inline Collapsible Taxonomy Filters -->
	<div id="<?php echo esc_attr( $filters_id ); ?>" class="datamachine-taxonomy-filters-inline" <?php echo empty( $tax_filters ) ? 'hidden' : ''; ?>
	<?php
	if ( $has_archive_context ) :
		?>
		data-archive-taxonomy="<?php echo esc_attr( $archive_context['taxonomy'] ); ?>" data-archive-term-id="<?php echo esc_attr( $archive_context['term_id'] ); ?>" data-archive-term-name="<?php echo esc_attr( $archive_context['term_name'] ); ?>"<?php endif; ?>>
		<div class="datamachine-taxonomy-filters-loading" style="display: none;">
			<span class="spinner is-active"></span>
			<?php esc_html_e( 'Loading filters...', 'datamachine-events' ); ?>
		</div>
		<div class="datamachine-filter-taxonomies"></div>
		<div class="datamachine-taxonomy-filters-actions" hidden>
			<button type="button" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_modal_button_classes', array( 'datamachine-button', 'datamachine-button-primary', 'datamachine-apply-filters' ), 'primary' ) ) ); ?>">
				<?php esc_html_e( 'Apply Filters', 'datamachine-events' ); ?>
			</button>
			<button type="button" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_modal_button_classes', array( 'datamachine-button', 'datamachine-clear-all-filters' ), 'secondary' ) ) ); ?>">
				<?php esc_html_e( 'Clear All', 'datamachine-events' ); ?>
			</button>
		</div>
	</div>
</div>
