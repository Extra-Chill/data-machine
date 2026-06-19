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

// #318: resolve the effective display mode. The block attribute is the
// baseline; the `data_machine_events_calendar_display_mode` filter lets
// consumers (e.g. My Shows, festival pages) override per-context at
// runtime without editing block markup. The filter receives a context
// array with the resolved archive so it can branch on taxonomy/term.
$raw_display_mode     = isset( $attributes['displayMode'] ) ? (string) $attributes['displayMode'] : 'date-groups';
$display_mode_context = array(
	'attributes'   => $attributes,
	'archive_term' => $archive_term,
);
$display_mode         = (string) apply_filters(
	'data_machine_events_calendar_display_mode',
	$raw_display_mode,
	$display_mode_context
);
if ( ! in_array( $display_mode, array( 'date-groups', 'month-grid' ), true ) ) {
	$display_mode = 'date-groups';
}
$is_month_grid_mode = ( 'month-grid' === $display_mode );

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

// #160: let a consumer inject an opaque scope token at server-render time.
// The token is uninterpreted by data-machine-events — it is emitted as a
// `data-scope-token` attribute on the calendar root so the frontend
// re-sends it on every prev/next month REST fetch, and threaded into the
// ability `$input` so a consumer's query-args filter can re-apply a
// server-side constraint that would otherwise die over REST. The block
// render path has no `$_GET['scope_token']` (the embedded calendar is
// rendered via do_blocks(), not a URL), so the filter is the only way a
// consumer can seed it on initial paint. URL-driven values (set via the
// passthrough above on the JS round-trip) win when present.
if ( empty( $query_args['scope_token'] ) ) {
	/**
	 * Filter the opaque scope token emitted on the calendar root and
	 * threaded into the ability input.
	 *
	 * Generic seam: data-machine-events neither mints nor validates this
	 * value. A consumer (e.g. a "my tracked shows" page) returns a
	 * non-spoofable, server-minted token here; the same consumer validates
	 * it inside its `data_machine_events_calendar_query_args` callback. The
	 * token must round-trip through the public REST endpoint, so consumers
	 * MUST make it tamper-evident (HMAC / signed nonce) rather than a plain
	 * identifier.
	 *
	 * @since 0.41.0
	 *
	 * @param string $scope_token Default empty string (no scoping).
	 * @param array  $context     Render context: block `attributes` + resolved `display_mode`.
	 */
	$injected_scope_token = (string) apply_filters(
		'data_machine_events_calendar_scope_token',
		'',
		array(
			'attributes'   => $attributes,
			'display_mode' => $display_mode,
		)
	);
	if ( '' !== $injected_scope_token ) {
		$query_args['scope_token'] = $injected_scope_token;
	}
}

// #318: in month-grid mode, default to the current month (in the site
// timezone) when no `?month=YYYY-MM` was supplied. The CalendarRequest
// sanitizer still validates the format — we only default when it would
// otherwise be empty.
if ( $is_month_grid_mode && empty( $query_args['month'] ) ) {
	$query_args['month'] = current_time( 'Y-m' );
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

$abilities    = new CalendarAbilities();
$ability_args = $request->toAbilitiesArgs();
$result       = $abilities->executeGetCalendarPage( $ability_args );

$current_page        = $result['current_page'];
$max_pages           = $result['max_pages'];
$total_event_count   = $result['total_event_count'];
$past_events_count   = $result['event_counts']['past'];
$future_events_count = $result['event_counts']['future'];

// #318: build the month-grid structure for the grid render path. We only
// need this when the block is in month-grid mode. `paged_date_groups` is
// the serialized form (post_id + event_data + display_context) but we
// need the raw form with WP_Post objects for MonthGridBuilder's permalink
// + title lookups. Re-fetch the unrenderered groups via the same path
// the ability used. Cheaper alternative: thread the raw groups out from
// the ability — done by adding `raw_date_groups` to the ability result
// (zero-cost when the caller doesn't read it). See below.
$grid_payload = null;
if ( $is_month_grid_mode ) {
	$visible_month = $request->month();
	if ( '' === $visible_month ) {
		$visible_month = current_time( 'Y-m' );
	}
	$raw_groups   = $result['raw_date_groups'] ?? array();
	$grid_payload = \DataMachineEvents\Blocks\Calendar\Grid\MonthGridBuilder::build(
		$visible_month,
		$raw_groups,
		current_time( 'Y-m-d' )
	);
}

$date_context = array(
	'date_start' => $date_start,
	'date_end'   => $date_end,
	'past'       => $show_past ? '1' : '',
);

// Use FilterAbilities to determine filter-bar visibility on archive pages.
$hide_filter_button_when_inactive = false;
$filter_count                     = ! empty( $tax_filters ) ? array_sum( array_map( 'count', $tax_filters ) ) : 0;

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
$wrapper_class      = $is_month_grid_mode
	? 'data-machine-events-calendar data-machine-events-date-grouped data-machine-events-month-grid-mode'
	: 'data-machine-events-calendar data-machine-events-date-grouped';
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => $wrapper_class,
	)
);

$display_mode_attr = sprintf( ' data-display-mode="%s"', esc_attr( $display_mode ) );

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

// #160: opaque scope token round-trip attribute. The frontend re-sends
// this on every month-grid prev/next REST fetch so the server-side
// constraint survives the round-trip.
$scope_token_data_attr = '';
$scope_token_value     = $request->scopeToken();
if ( '' !== $scope_token_value ) {
	$scope_token_data_attr = sprintf( ' data-scope-token="%s"', esc_attr( $scope_token_value ) );
}
?>

<div data-instance-id="<?php echo esc_attr( $instance_id ); ?>"<?php echo $archive_data_attrs; ?><?php echo $geo_data_attrs; ?><?php echo $scope_data_attr; ?><?php echo $scope_token_data_attr; ?><?php echo $display_mode_attr; ?> <?php echo $wrapper_attributes; ?>>
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
			'scope'                            => $scope,
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

	<?php
	if ( $is_month_grid_mode && is_array( $grid_payload ) ) :
		// Build the base URL for prev/next/today nav. `add_query_arg`
		// with null/null returns the current request URL (with all
		// existing query args); `remove_query_arg` strips `month` so
		// the template can append a fresh `?month=YYYY-MM`.
		$base_url_for_nav = remove_query_arg(
			'month',
			home_url( add_query_arg( null, null ) )
		);
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'month-grid',
			array(
				'grid'     => $grid_payload,
				'base_url' => $base_url_for_nav,
			)
		);
	endif;
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
	if ( ! $is_month_grid_mode ) :
		// #318: hide Load More / pagination and Past Events button in
		// grid mode. The month is the page; prev/next/today navigates.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Pagination\Renderer::render_pagination
		echo $result['html']['pagination'];
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated by Template_Loader
		echo $result['html']['navigation'];
	endif;
	?>
</div>
