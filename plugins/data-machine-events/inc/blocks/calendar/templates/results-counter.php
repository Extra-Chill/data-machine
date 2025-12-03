<?php
/**
 * Results Counter Template
 *
 * Displays "Viewing days X-Y of Z total" counter for day-based pagination.
 *
 * @var int $current_page Current page number
 * @var int $total_events Total number of unique days (renamed for backward compatibility)
 * @var int $events_per_page Days per page (DAYS_PER_PAGE constant value)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $total_events ) {
	return;
}

$total_days = $total_events;
$days_per_page = $events_per_page;

$start = ( ( $current_page - 1 ) * $days_per_page ) + 1;
$end   = min( $current_page * $days_per_page, $total_days );
?>

<div class="datamachine-events-results-counter">
	<?php
	if ( 1 === $total_days ) {
		esc_html_e( 'Viewing 1 day', 'datamachine-events' );
	} elseif ( $start === $end ) {
		printf(
			/* translators: 1: current day number, 2: total days */
			esc_html__( 'Viewing day %1$d of %2$d', 'datamachine-events' ),
			(int) $start,
			(int) $total_days
		);
	} else {
		printf(
			/* translators: 1: start day number, 2: end day number, 3: total days */
			esc_html__( 'Viewing days %1$d-%2$d of %3$d total', 'datamachine-events' ),
			(int) $start,
			(int) $end,
			(int) $total_days
		);
	}
	?>
</div>
