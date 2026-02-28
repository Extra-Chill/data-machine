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

// REST URL for venue fetching.
$rest_url = rest_url( 'datamachine/v1/events/venues' );
$nonce    = wp_create_nonce( 'wp_rest' );

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

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div
		id="<?php echo esc_attr( $map_id ); ?>"
		class="data-machine-events-map-root"
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
		data-nonce="<?php echo esc_attr( $nonce ); ?>"
		<?php if ( $show_location_search ) : ?>
		data-show-location-search="1"
		data-geocode-url="<?php echo esc_attr( $geocode_rest_url ); ?>"
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
</div>
