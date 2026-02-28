<?php
/**
 * Date Group Template
 *
 * Renders the date group header/badge for grouping events by date.
 *
 * @var DateTime $date_obj Date object for this group
 * @var string $day_of_week Lowercase day name for CSS classes
 * @var string $formatted_date_label Human-readable date display
 * @var int $events_count Number of events for this date
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$events_count = $events_count ?? 0;
?>

<?php
/**
 * Date Group Start Template
 *
 * Opens the date group container and renders the date badge with event count.
 * Note: The closing </div> is handled separately in the render.php loop.
 */
?>
<div class="datamachine-date-group datamachine-day-<?php echo esc_attr( $day_of_week ); ?>" data-date="<?php echo esc_attr( $date_obj->format( 'Y-m-d' ) ); ?>" data-event-count="<?php echo esc_attr( $events_count ); ?>">
	<div class="datamachine-day-header">
		<div class="datamachine-day-badge datamachine-day-badge-<?php echo esc_attr( $day_of_week ); ?>" 
			data-date-label="<?php echo esc_attr( $formatted_date_label ); ?>" 
			data-day-name="<?php echo esc_attr( $day_of_week ); ?>">
			<?php echo esc_html( $formatted_date_label ); ?>
		</div>
		<span class="datamachine-day-event-count">
			<?php
			printf(
				/* translators: %d: number of events */
				esc_html( _n( '%d event', '%d events', $events_count, 'data-machine-events' ) ),
				(int) $events_count
			);
			?>
		</span>
	</div>