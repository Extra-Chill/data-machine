<?php
/**
 * Results Counter Template
 *
 * Displays "Viewing events X-Y of Z total" counter for pagination context.
 *
 * @var int $current_page Current page number
 * @var int $total_events Total number of events
 * @var int $events_per_page Events per page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $total_events ) {
	return;
}

$start = ( ( $current_page - 1 ) * $events_per_page ) + 1;
$end   = min( $current_page * $events_per_page, $total_events );
?>

<div class="datamachine-events-results-counter">
	<?php
	if ( 1 === $total_events ) {
		esc_html_e( 'Viewing 1 event', 'datamachine-events' );
	} elseif ( $start === $end ) {
		printf(
			/* translators: 1: current event number, 2: total events */
			esc_html__( 'Viewing event %1$d of %2$d', 'datamachine-events' ),
			(int) $start,
			(int) $total_events
		);
	} else {
		printf(
			/* translators: 1: start event number, 2: end event number, 3: total events */
			esc_html__( 'Viewing events %1$d-%2$d of %3$d total', 'datamachine-events' ),
			(int) $start,
			(int) $end,
			(int) $total_events
		);
	}
	?>
</div>
