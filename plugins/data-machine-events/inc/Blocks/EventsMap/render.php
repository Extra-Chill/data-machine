<?php
/**
 * Events Map Block Server-Side Render
 *
 * Outputs a minimal React root container div. All map logic, venue fetching,
 * and Leaflet rendering happens client-side in the bundled frontend.tsx.
 *
 * In static mode (default), venues are embedded as JSON in data-venues for
 * instant render. In dynamic mode, venues are fetched via REST API on pan/zoom.
 *
 * Plugins can still filter the initial venue set for static mode via
 * `datamachine_events_map_venues`.
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
$dynamic  = ! empty( $attributes['dynamic'] );

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

/** @see datamachine_events_map_center */
$center = apply_filters( 'datamachine_events_map_center', $center, $context );

// User location (optional — plugins can set via filter).
$user_location = apply_filters( 'datamachine_events_map_user_location', null, $context );

// Static mode: embed venue data for instant render (no REST round-trip).
$venue_json = '[]';
if ( ! $dynamic ) {
	$venue_taxonomy = 'venue';
	if ( ! taxonomy_exists( $venue_taxonomy ) ) {
		return '';
	}

	$all_venues = get_terms( array(
		'taxonomy'   => $venue_taxonomy,
		'hide_empty' => false,
		'number'     => 0,
	) );

	if ( ! is_wp_error( $all_venues ) && ! empty( $all_venues ) ) {
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

		/** @see datamachine_events_map_venues */
		$venues = apply_filters( 'datamachine_events_map_venues', $venues, $context );

		if ( empty( $venues ) ) {
			return '';
		}

		// Clean URLs for JSON.
		$venue_data = array();
		foreach ( $venues as $venue ) {
			$venue_data[] = array(
				'term_id'     => $venue['term_id'],
				'name'        => $venue['name'],
				'slug'        => $venue['slug'],
				'lat'         => $venue['lat'],
				'lon'         => $venue['lon'],
				'address'     => $venue['address'],
				'url'         => is_string( $venue['url'] ) ? $venue['url'] : '',
				'event_count' => $venue['event_count'] ?? 0,
			);
		}

		$venue_json = wp_json_encode( $venue_data );
	}
}

$map_id  = wp_unique_id( 'dm-events-map-' );
$wrapper = get_block_wrapper_attributes( array(
	'class' => 'datamachine-events-map-block',
) );

// REST URL for dynamic mode.
$rest_url = rest_url( 'datamachine/v1/events/venues' );
$nonce    = wp_create_nonce( 'wp_rest' );

// Summary (kept for backward compat — plugins can filter).
$summary = apply_filters( 'datamachine_events_map_summary', '', $venues ?? array(), $context );

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div
		id="<?php echo esc_attr( $map_id ); ?>"
		class="datamachine-events-map-root"
		data-height="<?php echo esc_attr( $height ); ?>"
		data-zoom="<?php echo esc_attr( $zoom ); ?>"
		data-map-type="<?php echo esc_attr( $map_type ); ?>"
		data-dynamic="<?php echo $dynamic ? '1' : '0'; ?>"
		data-center-lat="<?php echo esc_attr( $center['lat'] ?? '' ); ?>"
		data-center-lon="<?php echo esc_attr( $center['lon'] ?? '' ); ?>"
		<?php if ( $user_location ) : ?>
		data-user-lat="<?php echo esc_attr( $user_location['lat'] ); ?>"
		data-user-lon="<?php echo esc_attr( $user_location['lon'] ); ?>"
		<?php endif; ?>
		data-venues="<?php echo esc_attr( $venue_json ); ?>"
		data-taxonomy="<?php echo esc_attr( $context['taxonomy'] ); ?>"
		data-term-id="<?php echo esc_attr( $context['term_id'] ); ?>"
		data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
	></div>
	<?php if ( ! empty( $summary ) ) : ?>
		<p class="datamachine-events-map-summary"><?php echo wp_kses_post( $summary ); ?></p>
	<?php endif; ?>
	<?php
	/**
	 * Fires after the map summary, inside the block wrapper.
	 *
	 * @param array $venues  Venue data array.
	 * @param array $context Map context.
	 */
	do_action( 'datamachine_events_map_after_summary', $venues ?? array(), $context );
	?>
</div>
