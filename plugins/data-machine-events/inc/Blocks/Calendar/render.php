<?php
/**
 * Calendar Block Server-Side Render Template
 *
 * Renders events calendar with filtering and pagination.
 * Uses CalendarAbilities for event data and HTML generation.
 * Uses FilterAbilities for filter-bar visibility logic.
 *
 * @var array $attributes Block attributes
 * @var string $content Block inner content
 * @var WP_Block $block Block instance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachineEvents\Abilities\CalendarAbilities;
use DataMachineEvents\Abilities\FilterAbilities;
use DataMachineEvents\Blocks\Calendar\Taxonomy_Helper;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;

if ( wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
	return '';
}

$show_search = $attributes['showSearch'] ?? true;

$current_page = 1;
if ( isset( $_GET['paged'] ) && absint( $_GET['paged'] ) > 0 ) {
	$current_page = absint( $_GET['paged'] );
} elseif ( get_query_var( 'paged' ) ) {
	$current_page = max( 1, (int) get_query_var( 'paged' ) );
}

$show_past = isset( $_GET['past'] ) && '1' === $_GET['past'];

$search_query    = isset( $_GET['event_search'] ) ? sanitize_text_field( wp_unslash( $_GET['event_search'] ) ) : '';
$date_start      = isset( $_GET['date_start'] ) ? sanitize_text_field( wp_unslash( $_GET['date_start'] ) ) : '';
$date_end        = isset( $_GET['date_end'] ) ? sanitize_text_field( wp_unslash( $_GET['date_end'] ) ) : '';
$geo_lat         = isset( $_GET['lat'] ) ? sanitize_text_field( wp_unslash( $_GET['lat'] ) ) : '';
$geo_lng         = isset( $_GET['lng'] ) ? sanitize_text_field( wp_unslash( $_GET['lng'] ) ) : '';
$geo_radius      = isset( $_GET['radius'] ) ? absint( $_GET['radius'] ) : 25;
$geo_radius_unit = isset( $_GET['radius_unit'] ) ? sanitize_key( wp_unslash( $_GET['radius_unit'] ) ) : 'mi';
$tax_filters_raw = isset( $_GET['tax_filter'] ) ? wp_unslash( $_GET['tax_filter'] ) : array();
$tax_filters     = array();

if ( is_array( $tax_filters_raw ) ) {
	foreach ( $tax_filters_raw as $taxonomy_slug => $term_ids ) {
		$taxonomy_slug = sanitize_key( $taxonomy_slug );
		$term_ids      = (array) $term_ids;
		$clean_ids     = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id > 0 ) {
				$clean_ids[] = $term_id;
			}
		}
		if ( ! empty( $clean_ids ) ) {
			$tax_filters[ $taxonomy_slug ] = $clean_ids;
		}
	}
}

// Resolve scope: URL ?scope= param takes priority, then block attribute, then empty (default).
$scope = '';
if ( isset( $_GET['scope'] ) ) {
	$scope = sanitize_key( wp_unslash( $_GET['scope'] ) );
} elseif ( ! empty( $attributes['defaultDateRange'] ) && 'current' !== $attributes['defaultDateRange'] ) {
	$scope = $attributes['defaultDateRange'];
}

$archive_context = array(
	'taxonomy'  => '',
	'term_id'   => 0,
	'term_name' => '',
);

if ( is_tax() ) {
	$term = get_queried_object();
	if ( $term && isset( $term->taxonomy ) && isset( $term->term_id ) ) {
		$archive_context = array(
			'taxonomy'  => $term->taxonomy,
			'term_id'   => $term->term_id,
			'term_name' => $term->name,
		);
	}
}

$abilities = new CalendarAbilities();
$result    = $abilities->executeGetCalendarPage(
	array(
		'paged'            => $current_page,
		'past'             => $show_past,
		'event_search'     => $search_query,
		'date_start'       => $date_start,
		'date_end'         => $date_end,
		'scope'            => $scope,
		'tax_filter'       => $tax_filters,
		'archive_taxonomy' => $archive_context['taxonomy'],
		'archive_term_id'  => $archive_context['term_id'],
		'geo_lat'          => $geo_lat,
		'geo_lng'          => $geo_lng,
		'geo_radius'       => $geo_radius,
		'geo_radius_unit'  => $geo_radius_unit,
		'include_html'     => true,
		'include_gaps'     => true,
	)
);

$current_page        = $result['current_page'];
$max_pages           = $result['max_pages'];
$total_event_count   = $result['total_event_count'];
$past_events_count   = $result['event_counts']['past'];
$future_events_count = $result['event_counts']['future'];

$date_context = array(
	'date_start' => $date_start,
	'date_end'   => $date_end,
	'past'       => $show_past ? '1' : '',
);

// Use FilterAbilities to determine filter-bar visibility on archive pages.
$hide_filter_button_when_inactive = false;
$filter_count = ! empty( $tax_filters ) ? array_sum( array_map( 'count', $tax_filters ) ) : 0;

if ( ! empty( $archive_context['taxonomy'] ) && ! empty( $archive_context['term_id'] ) && 0 === $filter_count ) {
	$filter_abilities = new FilterAbilities();
	$filter_result    = $filter_abilities->executeGetFilterOptions(
		array(
			'active_filters'   => $tax_filters,
			'date_context'     => $date_context,
			'archive_taxonomy' => $archive_context['taxonomy'],
			'archive_term_id'  => $archive_context['term_id'],
			'geo_lat'          => $geo_lat,
			'geo_lng'          => $geo_lng,
			'geo_radius'       => $geo_radius,
			'geo_radius_unit'  => $geo_radius_unit,
		)
	);

	$taxonomies_with_counts = $filter_result['taxonomies'] ?? array();

	$has_other_taxonomy_options = false;
	foreach ( $taxonomies_with_counts as $taxonomy_slug => $taxonomy_data ) {
		if ( $taxonomy_slug === $archive_context['taxonomy'] ) {
			continue;
		}
		if ( ! empty( $taxonomy_data['terms'] ) ) {
			$has_other_taxonomy_options = true;
			break;
		}
	}

	$has_other_archive_taxonomy_terms = false;
	if ( isset( $taxonomies_with_counts[ $archive_context['taxonomy'] ] ) ) {
		$archive_terms = Taxonomy_Helper::flatten_hierarchy( $taxonomies_with_counts[ $archive_context['taxonomy'] ]['terms'] ?? array() );
		foreach ( $archive_terms as $term_data ) {
			if ( (int) ( $term_data['term_id'] ?? 0 ) !== (int) $archive_context['term_id'] ) {
				$has_other_archive_taxonomy_terms = true;
				break;
			}
		}
	}

	$hide_filter_button_when_inactive = ! $has_other_taxonomy_options && ! $has_other_archive_taxonomy_terms;
}

\DataMachineEvents\Blocks\Calendar\Template_Loader::init();

$block_id           = isset( $block ) && isset( $block->clientId ) ? (string) $block->clientId : uniqid( 'dm', true );
$instance_id        = 'datamachine-calendar-' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $block_id ) ), 0, 12 );
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'data-machine-events-calendar data-machine-events-date-grouped',
	)
);

$archive_data_attrs = '';
if ( ! empty( $archive_context['taxonomy'] ) ) {
	$archive_data_attrs = sprintf(
		' data-archive-taxonomy="%s" data-archive-term-id="%d" data-archive-term-name="%s"',
		esc_attr( $archive_context['taxonomy'] ),
		esc_attr( $archive_context['term_id'] ),
		esc_attr( $archive_context['term_name'] )
	);
}

$geo_data_attrs = '';
if ( ! empty( $geo_lat ) && ! empty( $geo_lng ) ) {
	$geo_data_attrs = sprintf(
		' data-geo-lat="%s" data-geo-lng="%s" data-geo-radius="%s" data-geo-radius-unit="%s"',
		esc_attr( $geo_lat ),
		esc_attr( $geo_lng ),
		esc_attr( $geo_radius ),
		esc_attr( $geo_radius_unit )
	);
}

$scope_data_attr = '';
if ( ! empty( $scope ) ) {
	$scope_data_attr = sprintf( ' data-scope="%s"', esc_attr( $scope ) );
}
?>

<div data-instance-id="<?php echo esc_attr( $instance_id ); ?>"<?php echo $archive_data_attrs; ?><?php echo $geo_data_attrs; ?><?php echo $scope_data_attr; ?> <?php echo $wrapper_attributes; ?>>
	<?php
	\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
		'filter-bar',
		array(
			'attributes'                       => $attributes,
			'instance_id'                      => $instance_id,
			'tax_filters'                      => $tax_filters,
			'search_query'                     => $search_query,
			'date_start'                       => $date_start,
			'date_end'                         => $date_end,
			'filter_count'                     => $filter_count,
			'archive_context'                  => $archive_context,
			'hide_filter_button_when_inactive' => $hide_filter_button_when_inactive,
			'geo_lat'                          => $geo_lat,
			'geo_lng'                          => $geo_lng,
			'geo_radius'                       => $geo_radius,
			'geo_radius_unit'                  => $geo_radius_unit,
		)
	);
	?>

	<div class="data-machine-events-content">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Template_Loader
		echo $result['html']['events'];
		?>
	</div>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Template_Loader
	echo $result['html']['counter'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Pagination::render_pagination
	echo $result['html']['pagination'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Template_Loader
	echo $result['html']['navigation'];
	?>
</div>
