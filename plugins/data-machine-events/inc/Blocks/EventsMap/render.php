<?php
/**
 * Events Map Block Server-Side Render
 *
 * Outputs a minimal React root container div. All map logic, venue fetching,
 * and Leaflet rendering happens client-side in the bundled frontend.tsx.
 *
 * The map always operates in dynamic mode: venues are fetched from the REST
 * API on mount and on every pan/zoom. Plugins can influence the initial
 * center, user location marker, and summary text via filters.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content.
 * @var WP_Block $block      Block instance.
 *
 * @package DataMachineEvents
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Don't render in REST/JSON contexts.
if ( wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
	return '';
}

$height   = absint( $attributes['height'] ?? 400 );
$zoom     = absint( $attributes['zoom'] ?? 12 );
$map_type = sanitize_text_field( $attributes['mapType'] ?? 'osm-standard' );

// Override map type from plugin settings if available.
if ( 'osm-standard' === $map_type && class_exists( 'DataMachineEvents\\Admin\\Settings_Page' ) ) {
	$map_type = \DataMachineEvents\Admin\Settings_Page::get_map_display_type();
}

// Build context for filters.
$context = array(
	'is_archive'  => is_archive(),
	'is_taxonomy' => false,
	'taxonomy'    => '',
	'term_id'     => 0,
	'term_name'   => '',
	'attributes'  => $attributes,
);

if ( is_tax() ) {
	$queried = get_queried_object();
	if ( $queried && isset( $queried->term_id ) ) {
		$context['is_taxonomy'] = true;
		$context['taxonomy']    = $queried->taxonomy;
		$context['term_id']     = $queried->term_id;
		$context['term_name']   = $queried->name;
	}
}

// Map center (optional — plugins can set via filter).
$center = null;

/** @see data_machine_events_map_center */
$center = apply_filters( 'data_machine_events_map_center', $center, $context );

// User location (optional — plugins can set via filter).
$user_location = apply_filters( 'data_machine_events_map_user_location', null, $context );

$map_id  = wp_unique_id( 'dm-events-map-' );
$wrapper = get_block_wrapper_attributes( array(
	'class' => 'data-machine-events-map-block',
) );

// REST URL for venue fetching (public endpoint — no nonce needed).
$rest_url = rest_url( 'datamachine/v1/events/venues' );

// Location search (plugins can enable via filter).
$show_location_search = (bool) ( $attributes['showLocationSearch'] ?? false );

/**
 * Filter whether the location search input is shown below the map.
 *
 * @param bool  $show    Whether to show the location search input.
 * @param array $context Map context with taxonomy/term info.
 */
$show_location_search = apply_filters( 'data_machine_events_map_show_location_search', $show_location_search, $context );

// Geocode REST URL (only needed when location search is enabled).
$geocode_rest_url = $show_location_search ? rest_url( 'datamachine/v1/events/geocode/search' ) : '';

// Summary (plugins can filter to show venue/event counts).
$summary = apply_filters( 'data_machine_events_map_summary', '', array(), $context );

// Chronological-route mode: render the venues as a chronologically-ordered
// route (polyline through venue markers with distinguished first/last stops).
// Generic display mode for any consumer that needs to draw a route through
// venues by event date. Either the block attribute or the filter can flip
// it on.
$chronological_route_mode = (bool) ( $attributes['chronologicalRouteMode'] ?? false );

/**
 * Filter whether the events map renders in chronological-route mode.
 *
 * Chronological-route mode draws a polyline between venue markers ordered by
 * event date and distinguishes the first/last venues. Generic display mode
 * for any consumer that needs a chronological route through venues. Only
 * meaningful when the block also has a taxonomy/term context.
 *
 * @param bool  $chronological_route_mode Current value.
 * @param array $context                  Map context with taxonomy/term info.
 */
$chronological_route_mode = (bool) apply_filters( 'data_machine_events_map_chronological_route', $chronological_route_mode, $context );

// #160: opaque scope token. Mirrors the calendar's scope_token seam. A
// consumer (e.g. a "my tracked shows" map) returns a non-spoofable,
// server-minted token here; data-machine-events emits it as
// `data-scope-token` on the map root so the frontend re-sends it on every
// venue fetch (mount + pan/zoom), and the consumer validates it inside its
// `data_machine_events_map_query_args` / `data_machine_events_map_venues`
// callbacks. The map fetches venues over the public REST endpoint on
// mount, where page-context gates like is_page() do NOT hold — so without
// this token the consumer's owner-scoping silently drops and the map leaks
// the full venue set. The generic layer never mints or validates the token.
$scope_token = '';
/**
 * Filter the opaque scope token emitted on the events-map root.
 *
 * Generic seam: data-machine-events neither mints nor validates this
 * value. Consumers MUST make it tamper-evident (HMAC / signed nonce)
 * because the venues REST endpoint is public.
 *
 * @since 0.41.0
 *
 * @param string $scope_token Default empty string (no scoping).
 * @param array  $context     Map render context (taxonomy/term/attributes).
 */
$scope_token = (string) apply_filters( 'data_machine_events_map_scope_token', $scope_token, $context );

// Collapsible: opt-in capability letting a consumer make the map non-dominant
// by rendering an expand/collapse control. Default false everywhere, so when
// not enabled this block renders byte-identically to before. When enabled, the
// map (the React root) is wrapped in a collapsible region driven by an
// accessible toggle button; the frontend owns Leaflet's invalidateSize() on
// expand so tiles re-render correctly. The generic block knows nothing about
// which consumer/page enables it.
$collapsible       = (bool) ( $attributes['collapsible'] ?? false );
$default_collapsed = $collapsible && (bool) ( $attributes['defaultCollapsed'] ?? false );

// IDs + accessible labels for the toggle <-> region relationship.
$region_id = $map_id . '-region';
$toggle_id = $map_id . '-toggle';
$show_label = __( 'Show map', 'data-machine-events' );
$hide_label = __( 'Hide map', 'data-machine-events' );

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $collapsible ) : ?>
	<div class="data-machine-events-map-collapsible<?php echo $default_collapsed ? ' is-collapsed' : ''; ?>">
		<button
			type="button"
			id="<?php echo esc_attr( $toggle_id ); ?>"
			class="data-machine-events-map-toggle"
			aria-expanded="<?php echo $default_collapsed ? 'false' : 'true'; ?>"
			aria-controls="<?php echo esc_attr( $region_id ); ?>"
			data-label-show="<?php echo esc_attr( $show_label ); ?>"
			data-label-hide="<?php echo esc_attr( $hide_label ); ?>"
		><?php echo esc_html( $default_collapsed ? $show_label : $hide_label ); ?></button>
		<div
			id="<?php echo esc_attr( $region_id ); ?>"
			class="data-machine-events-map-region"
			<?php echo $default_collapsed ? 'hidden' : ''; ?>
		>
	<?php endif; ?>
	<div
		id="<?php echo esc_attr( $map_id ); ?>"
		class="data-machine-events-map-root"
		<?php if ( $collapsible ) : ?>
		data-collapsible="1"
		data-toggle-id="<?php echo esc_attr( $toggle_id ); ?>"
		data-region-id="<?php echo esc_attr( $region_id ); ?>"
		<?php if ( $default_collapsed ) : ?>
		data-default-collapsed="1"
		<?php endif; ?>
		<?php endif; ?>
		data-height="<?php echo esc_attr( $height ); ?>"
		data-zoom="<?php echo esc_attr( $zoom ); ?>"
		data-map-type="<?php echo esc_attr( $map_type ); ?>"
		data-center-lat="<?php echo esc_attr( $center['lat'] ?? '' ); ?>"
		data-center-lon="<?php echo esc_attr( $center['lon'] ?? '' ); ?>"
		<?php if ( $user_location ) : ?>
		data-user-lat="<?php echo esc_attr( $user_location['lat'] ); ?>"
		data-user-lon="<?php echo esc_attr( $user_location['lon'] ); ?>"
		<?php endif; ?>
		data-taxonomy="<?php echo esc_attr( $context['taxonomy'] ); ?>"
		data-term-id="<?php echo esc_attr( $context['term_id'] ); ?>"
		data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
		<?php if ( $show_location_search ) : ?>
		data-show-location-search="1"
		data-geocode-url="<?php echo esc_attr( $geocode_rest_url ); ?>"
		<?php endif; ?>
		<?php if ( $chronological_route_mode ) : ?>
		data-chronological-route-mode="1"
		<?php endif; ?>
		<?php if ( '' !== $scope_token ) : ?>
		data-scope-token="<?php echo esc_attr( $scope_token ); ?>"
		<?php endif; ?>
	></div>
	<?php if ( ! empty( $summary ) ) : ?>
		<p class="data-machine-events-map-summary"><?php echo wp_kses_post( $summary ); ?></p>
	<?php endif; ?>
	<?php
	/**
	 * Fires after the map summary, inside the block wrapper.
	 *
	 * @param array $context Map context with taxonomy/term info.
	 */
	do_action( 'data_machine_events_map_after_summary', array(), $context );
	?>
	<?php if ( $collapsible ) : ?>
		</div><!-- .data-machine-events-map-region -->
	</div><!-- .data-machine-events-map-collapsible -->
	<?php endif; ?>
</div>
