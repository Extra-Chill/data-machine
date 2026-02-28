<?php
/**
 * No Events Template
 *
 * Renders the empty state message when no events are found.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="datamachine-events-no-events">
	<p><?php esc_html_e( 'No events found.', 'datamachine-events' ); ?></p>
	<p>
		<button type="button" class="datamachine-events-no-events-today-link">
			<?php esc_html_e( 'Show events from Today', 'datamachine-events' ); ?>
		</button>
	</p>
</div>