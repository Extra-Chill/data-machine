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

<div class="data-machine-events-no-events">
	<p><?php esc_html_e( 'No events found.', 'data-machine-events' ); ?></p>
	<p>
		<button type="button" class="data-machine-events-no-events-today-link">
			<?php esc_html_e( 'Show events from Today', 'data-machine-events' ); ?>
		</button>
	</p>
</div>