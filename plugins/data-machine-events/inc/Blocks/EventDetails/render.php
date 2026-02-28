<?php
/**
 * Event Details Block Server-Side Render Template
 *
 * Displays event information with venue integration and structured data.
 *
 * @var array $attributes Block attributes
 * @var string $content InnerBlocks content
 * @var WP_Block $block Block instance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\EventSchemaProvider;

$decode_unicode = function ( $str ) {
	return html_entity_decode( preg_replace( '/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $str ), ENT_NOQUOTES, 'UTF-8' );
};

$start_date       = $attributes['startDate'] ?? '';
$end_date         = $attributes['endDate'] ?? '';
$start_time       = $attributes['startTime'] ?? '';
$end_time         = $attributes['endTime'] ?? '';
$venue            = $decode_unicode( $attributes['venue'] ?? '' );
$address          = $decode_unicode( $attributes['address'] ?? '' );
$price            = $decode_unicode( $attributes['price'] ?? '' );
$ticket_url       = $attributes['ticketUrl'] ?? '';
$occurrence_dates = $attributes['occurrenceDates'] ?? array();

$post_id = get_the_ID();

$venue_data  = null;
$venue_terms = get_the_terms( $post_id, 'venue' );
if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
	$venue_term = $venue_terms[0];
	$venue_data = Venue_Taxonomy::get_venue_data( $venue_term->term_id );
	$venue      = $venue_data['name'];
	$address    = Venue_Taxonomy::get_formatted_address( $venue_term->term_id, $venue_data );
}

// Promoter taxonomy maps to Schema.org organizer property
$organizer_data = null;
$promoter_terms = get_the_terms( $post_id, 'promoter' );
if ( $promoter_terms && ! is_wp_error( $promoter_terms ) ) {
	$promoter_term  = $promoter_terms[0];
	$organizer_data = Promoter_Taxonomy::get_promoter_data( $promoter_term->term_id );
}

$start_datetime = '';
$end_datetime   = '';
if ( $start_date ) {
	$start_datetime = $start_time ? $start_date . ' ' . $start_time : $start_date;
}
if ( $end_date ) {
	$end_datetime = $end_time ? $end_date . ' ' . $end_time : $end_date;
}

// Filter and limit occurrence dates for display.
$upcoming_occurrences = array();
if ( ! empty( $occurrence_dates ) && is_array( $occurrence_dates ) ) {
	$current_date = current_time( 'Y-m-d' );
	$max_display  = apply_filters( 'datamachine_events_max_occurrence_display', 5 );

	// Filter to future dates only and limit count.
	$upcoming_occurrences = array_slice(
		array_filter(
			$occurrence_dates,
			function ( $date ) use ( $current_date ) {
				return $date >= $current_date;
			}
		),
		0,
		$max_display
	);
}
$has_more_occurrences = count( $occurrence_dates ) > count( $upcoming_occurrences );

$block_classes = array( 'datamachine-event-details' );
if ( ! empty( $attributes['align'] ) ) {
	$block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode( ' ', $block_classes );

$non_ticket_patterns = apply_filters( 'datamachine_events_non_ticket_price_patterns', array( 'free', 'tbd', 'no cover' ) );
$price_lower         = strtolower( trim( $price ) );
$is_non_ticket_price = empty( $price ) || array_reduce(
	$non_ticket_patterns,
	function ( $carry, $pattern ) use ( $price_lower ) {
		return $carry || str_contains( $price_lower, strtolower( $pattern ) );
	},
	false
);
$ticket_button_text  = $is_non_ticket_price
	? __( 'Event Link', 'datamachine-events' )
	: __( 'Get Tickets', 'datamachine-events' );


$event_schema     = null;
$description_text = ! empty( $content ) ? wp_strip_all_tags( $content ) : '';
$event_data       = array_merge(
	$attributes,
	array(
		'description' => $description_text,
	)
);
$event_schema     = EventSchemaProvider::generateSchemaOrg( $event_data, $venue_data ?? array(), $organizer_data ?? array(), $post_id );
?>

<?php if ( $event_schema ) : ?>
	<script type="application/ld+json">
	<?php echo wp_json_encode( $event_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); ?>
	</script>
<?php endif; ?>

<div class="<?php echo esc_attr( $block_class ); ?>">
	<?php if ( ! empty( $content ) ) : ?>
		<?php echo $content; ?>
	<?php endif; ?>
	
	<div class="event-info-grid">
		<?php if ( $start_datetime ) : ?>
			<div class="event-date-time">
				<span class="icon">📅</span>
				<span class="text">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $start_datetime ) ) ); ?>
					<?php if ( $start_time ) : ?>
						<br><small><?php echo esc_html( date_i18n( get_option( 'time_format' ), strtotime( $start_datetime ) ) ); ?></small>
					<?php endif; ?>
				</span>
				<?php if ( ! empty( $upcoming_occurrences ) ) : ?>
					<div class="occurrence-dates">
						<small><?php esc_html_e( 'Also showing:', 'datamachine-events' ); ?></small>
						<ul>
							<?php foreach ( $upcoming_occurrences as $occ_date ) : ?>
								<li><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $occ_date ) ) ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( $has_more_occurrences ) : ?>
							<small class="more-dates"><?php esc_html_e( '+ more dates', 'datamachine-events' ); ?></small>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $venue ) : ?>
			<div class="event-venue">
				<span class="icon">📍</span>
				<span class="text">
					<?php echo esc_html( $venue ); ?>
					<?php if ( $address ) : ?>
						<br><small><?php echo esc_html( $address ); ?></small>
					<?php endif; ?>
					<?php if ( $venue_data && ! empty( $venue_data['phone'] ) ) : ?>
						<br><small><?php printf( __( 'Phone: %s', 'datamachine-events' ), esc_html( $venue_data['phone'] ) ); ?></small>
					<?php endif; ?>
					<?php if ( $venue_data && ! empty( $venue_data['website'] ) ) : ?>
						<br><small><a href="<?php echo esc_url( $venue_data['website'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Venue Website', 'datamachine-events' ); ?></a></small>
					<?php endif; ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="event-price">
			<?php if ( $price ) : ?>
				<span class="icon">💰</span>
				<span class="text"><?php echo esc_html( $price ); ?></span>
			<?php endif; ?>
			<?php
			/**
			 * Fires after price text, inside .event-price container.
			 *
			 * Always fires to support promotional content (e.g., membership badges)
			 * even when no price is set.
			 *
			 * @param int    $post_id Current event post ID.
			 * @param string $price   Event price string (may be empty).
			 */
			do_action( 'datamachine_events_after_price_display', $post_id, $price );
			?>
		</div>
	</div>

	<div class="event-action-buttons">
		<?php if ( $ticket_url ) : ?>
			<a href="<?php echo esc_url( $ticket_url ); ?>" class="<?php echo esc_attr( implode( ' ', apply_filters( 'datamachine_events_ticket_button_classes', array( 'ticket-button' ) ) ) ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( $ticket_button_text ); ?>
			</a>
		<?php endif; ?>

		<?php
		/**
		 * Action hook for additional event action buttons.
		 *
		 * Allows themes and plugins to add buttons (share, RSVP, etc.) alongside the ticket button.
		 *
		 * @param int $post_id Current event post ID
		 * @param string $ticket_url Ticket URL if available (empty string if not)
		 */
		do_action( 'datamachine_events_action_buttons', $post_id, $ticket_url );
		?>
	</div>

	<?php
	// Display venue map if coordinates are available
	if ( $venue_data && ! empty( $venue_data['coordinates'] ) ) {
		$coords = explode( ',', $venue_data['coordinates'] );
		if ( count( $coords ) === 2 ) {
			$lat = trim( $coords[0] );
			$lon = trim( $coords[1] );

			// Validate coordinates are numeric
			if ( is_numeric( $lat ) && is_numeric( $lon ) ) {
				// Get map display type from settings
				$map_display_type = 'osm-standard';
				if ( class_exists( 'DataMachineEvents\\Admin\\Settings_Page' ) ) {
					$map_display_type = \DataMachineEvents\Admin\Settings_Page::get_map_display_type();
				}
				?>
				<div class="datamachine-venue-map-section">
					<h3 class="venue-map-title"><?php echo esc_html__( 'Venue Location', 'datamachine-events' ); ?></h3>
					<div
						id="venue-map-<?php echo esc_attr( $post_id ); ?>"
						class="datamachine-venue-map"
						data-lat="<?php echo esc_attr( $lat ); ?>"
						data-lon="<?php echo esc_attr( $lon ); ?>"
						data-venue-name="<?php echo esc_attr( $venue ); ?>"
						data-venue-address="<?php echo esc_attr( $address ); ?>"
						data-map-type="<?php echo esc_attr( $map_display_type ); ?>"
					></div>
					<div class="venue-map-attribution">
						<small>
							<?php
							printf(
								esc_html__( 'Map data © %s contributors', 'datamachine-events' ),
								'<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
							);
							?>
						</small>
					</div>
				</div>
				<?php
			}
		}
	}
	?>

</div> 