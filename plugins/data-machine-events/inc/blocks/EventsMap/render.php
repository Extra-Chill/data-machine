<?php
/**
 * Events Map Block Server-Side Render
 *
 * Renders an interactive Leaflet map of event venues. Auto-detects context:
 * - Taxonomy archive: shows venues matching the queried term
 * - Manual: shows all venues (filterable via block attributes or hooks)
 *
 * Plugins filter the venue list via `datamachine_events_map_venues` to control
 * what markers appear (e.g. extrachill-events filters by location term).
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content.
 * @var WP_Block $block      Block instance.
 *
 * @package DataMachineEvents
 * @since 0.13.0
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

// Build context array for filters.
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

// Get center coordinates. Default to null (will auto-fit bounds).
$center = null;

/**
 * Filter the map center coordinates.
 *
 * Return an array with 'lat' and 'lon' keys, or null to auto-fit bounds.
 *
 * @param array|null $center  Center coordinates or null.
 * @param array      $context Map context (taxonomy, term, attributes).
 */
$center = apply_filters( 'datamachine_events_map_center', $center, $context );

// Query all venues with coordinates.
$venue_taxonomy = 'venue';
if ( ! taxonomy_exists( $venue_taxonomy ) ) {
	return '';
}

$all_venues = get_terms( array(
	'taxonomy'   => $venue_taxonomy,
	'hide_empty' => false,
	'number'     => 0,
) );

if ( is_wp_error( $all_venues ) || empty( $all_venues ) ) {
	return '';
}

$venues = array();
foreach ( $all_venues as $venue ) {
	$coordinates = get_term_meta( $venue->term_id, '_venue_coordinates', true );
	if ( empty( $coordinates ) || strpos( $coordinates, ',' ) === false ) {
		continue;
	}

	$parts = explode( ',', $coordinates );
	$lat   = floatval( trim( $parts[0] ) );
	$lon   = floatval( trim( $parts[1] ) );

	if ( 0.0 === $lat && 0.0 === $lon ) {
		continue;
	}

	$address = '';
	if ( class_exists( 'DataMachineEvents\\Core\\Venue_Taxonomy' ) ) {
		$address = \DataMachineEvents\Core\Venue_Taxonomy::get_formatted_address( $venue->term_id );
	}

	$venues[] = array(
		'term_id'     => $venue->term_id,
		'name'        => $venue->name,
		'slug'        => $venue->slug,
		'lat'         => $lat,
		'lon'         => $lon,
		'address'     => $address,
		'url'         => get_term_link( $venue ),
		'event_count' => $venue->count,
	);
}

/**
 * Filter the venues displayed on the events map.
 *
 * Plugins use this to narrow the venue list based on context
 * (e.g. only venues in a specific city for location archives).
 *
 * @param array $venues  Array of venue data arrays.
 * @param array $context Map context (taxonomy, term, attributes).
 */
$venues = apply_filters( 'datamachine_events_map_venues', $venues, $context );

if ( empty( $venues ) ) {
	return '';
}

// Build venue data for JS.
$venue_data = array();
foreach ( $venues as $venue ) {
	$venue_data[] = array(
		'name'        => $venue['name'],
		'lat'         => $venue['lat'],
		'lon'         => $venue['lon'],
		'address'     => $venue['address'],
		'url'         => is_string( $venue['url'] ) ? $venue['url'] : '',
		'event_count' => $venue['event_count'] ?? 0,
	);
}

/**
 * Filter the summary text displayed below the map.
 *
 * Return an empty string to hide the summary.
 *
 * @param string $summary Summary HTML string.
 * @param array  $venues  Venue data array.
 * @param array  $context Map context.
 */
$summary = apply_filters( 'datamachine_events_map_summary', '', $venues, $context );

$map_id    = wp_unique_id( 'dm-events-map-' );
$wrapper   = get_block_wrapper_attributes( array(
	'class' => 'datamachine-events-map-block',
) );

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div
		id="<?php echo esc_attr( $map_id ); ?>"
		class="datamachine-events-map"
		style="height: <?php echo esc_attr( $height ); ?>px;"
		data-center-lat="<?php echo esc_attr( $center['lat'] ?? '' ); ?>"
		data-center-lon="<?php echo esc_attr( $center['lon'] ?? '' ); ?>"
		data-zoom="<?php echo esc_attr( $zoom ); ?>"
		data-map-type="<?php echo esc_attr( $map_type ); ?>"
		data-venues="<?php echo esc_attr( wp_json_encode( $venue_data ) ); ?>"
	></div>
	<?php if ( ! empty( $summary ) ) : ?>
		<p class="datamachine-events-map-summary"><?php echo wp_kses_post( $summary ); ?></p>
	<?php endif; ?>
</div>
