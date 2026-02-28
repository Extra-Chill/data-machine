<?php
/**
 * Results Counter Template
 *
 * Displays date range and event count: "Viewing Dec 3 - Dec 7 (47 of 120 Events)"
 * When viewing all events (no pagination), shows: "Viewing Dec 3 - Dec 7 (47 Events)"
 *
 * @var string $page_start_date Start date of current page (Y-m-d format)
 * @var string $page_end_date End date of current page (Y-m-d format)
 * @var int $event_count Number of events on current page
 * @var int $total_events Total number of matching events across all pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $page_start_date ) || empty( $page_end_date ) || ! $event_count ) {
	return;
}

$formatted_start = date_i18n( 'M j', strtotime( $page_start_date ) );
$formatted_end   = date_i18n( 'M j', strtotime( $page_end_date ) );
$is_same_day     = $page_start_date === $page_end_date;
$is_paginated    = isset( $total_events ) && $event_count < $total_events;
$event_label     = 1 === $total_events ? __( 'Event', 'data-machine-events' ) : __( 'Events', 'data-machine-events' );
?>

<div class="data-machine-events-results-counter">
	<?php
	if ( $is_same_day && $is_paginated ) {
		printf(
			/* translators: 1: formatted date, 2: page event count, 3: total event count, 4: "Event" or "Events" */
			esc_html__( 'Viewing %1$s (%2$d of %3$d %4$s)', 'data-machine-events' ),
			esc_html( $formatted_start ),
			(int) $event_count,
			(int) $total_events,
			esc_html( $event_label )
		);
	} elseif ( $is_same_day ) {
		printf(
			/* translators: 1: formatted date, 2: event count, 3: "Event" or "Events" */
			esc_html__( 'Viewing %1$s (%2$d %3$s)', 'data-machine-events' ),
			esc_html( $formatted_start ),
			(int) $event_count,
			esc_html( $event_label )
		);
	} elseif ( $is_paginated ) {
		printf(
			/* translators: 1: start date, 2: end date, 3: page event count, 4: total event count, 5: "Event" or "Events" */
			esc_html__( 'Viewing %1$s - %2$s (%3$d of %4$d %5$s)', 'data-machine-events' ),
			esc_html( $formatted_start ),
			esc_html( $formatted_end ),
			(int) $event_count,
			(int) $total_events,
			esc_html( $event_label )
		);
	} else {
		printf(
			/* translators: 1: start date, 2: end date, 3: event count, 4: "Event" or "Events" */
			esc_html__( 'Viewing %1$s - %2$s (%3$d %4$s)', 'data-machine-events' ),
			esc_html( $formatted_start ),
			esc_html( $formatted_end ),
			(int) $event_count,
			esc_html( $event_label )
		);
	}
	?>
</div>
