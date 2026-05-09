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
use DataMachineEvents\Blocks\Calendar\Query\CalendarRequest;

if ( wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
	return '';
}

$show_search = $attributes['showSearch'] ?? true;

// Resolve archive term context first so the value object can pick it up.
$archive_term = null;
if ( is_tax() ) {
	$queried = get_queried_object();
	if ( $queried instanceof WP_Term ) {
		$archive_term = $queried;
	}
}

// CalendarRequest::fromQueryArgs() owns sanitization + unslashing.
// We only need to layer on two non-`$_GET` fallbacks before handing the
// array off:
//   1. `paged` falls back to the WP query var when `?paged=` is absent.
//   2. `scope` falls back to the block's `defaultDateRange` attribute.
// Passing the raw `$_GET` shape (still slashed) is intentional — the
// value object will `wp_unslash()` each value as it sanitizes.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$query_args = $_GET;
if ( ! isset( $query_args['paged'] ) || absint( $query_args['paged'] ) < 1 ) {
	$query_var_paged = (int) get_query_var( 'paged' );
	if ( $query_var_paged > 0 ) {
		$query_args['paged'] = $query_var_paged;
	}
}
if ( ! isset( $query_args['scope'] ) && ! empty( $attributes['defaultDateRange'] ) && 'current' !== $attributes['defaultDateRange'] ) {
	$query_args['scope'] = (string) $attributes['defaultDateRange'];
}

$request = CalendarRequest::fromQueryArgs( $query_args, $archive_term );

// Local aliases used by template includes / data attrs further down.
$current_page    = $request->paged();
$show_past       = $request->past();
$search_query    = $request->eventSearch();
$date_start      = $request->dateStart();
$date_end        = $request->dateEnd();
$scope           = $request->scope();
$tax_filters     = $request->taxFilter();
$geo_lat         = $request->geoLat();
$geo_lng         = $request->geoLng();
$geo_radius      = $request->geoRadius();
$geo_radius_unit = $request->geoRadiusUnit();

$archive_context = array(
	'taxonomy'  => $request->archiveTaxonomy(),
	'term_id'   => $request->archiveTermId(),
	'term_name' => $archive_term instanceof WP_Term ? $archive_term->name : '',
);

$abilities = new CalendarAbilities();
$result    = $abilities->executeGetCalendarPage( $request->toAbilitiesArgs() );

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
		$archive_terms = FilterAbilities::flatten_hierarchy( $taxonomies_with_counts[ $archive_context['taxonomy'] ]['terms'] ?? array() );
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
$instance_id        = 'data-machine-calendar-' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $block_id ) ), 0, 12 );
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
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Pagination\Renderer::render_pagination
	echo $result['html']['pagination'];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Template_Loader
	echo $result['html']['navigation'];
	?>
</div>
