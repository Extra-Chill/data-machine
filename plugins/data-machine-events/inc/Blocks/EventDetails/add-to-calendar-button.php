<?php
/**
 * Add to Calendar button + dropdown for single-event pages.
 *
 * Hooks onto `data_machine_events_action_buttons` (fired inside the
 * EventDetails block render) and emits a button that opens a dropdown
 * with three calendar destinations: Google Calendar, Outlook.com, and
 * a downloadable .ics file (for Apple Calendar / Thunderbird / etc.).
 *
 * Hook priority 7: this puts the button between the existing attendance
 * button (priority 5, from extrachill-events) and the share button
 * (priority 10, from extrachill-events). Order on a page that has all
 * three: Ticket → Attendance → Add to Calendar → Share.
 *
 * @package DataMachineEvents\Blocks\EventDetails
 * @since   0.40.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\DataMachineEvents\\EventActions\\CalendarUrlBuilder' ) ) {
	require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/EventActions/CalendarUrlBuilder.php';
}

add_action(
	'data_machine_events_action_buttons',
	'data_machine_events_render_add_to_calendar_button',
	7,
	2
);

if ( ! function_exists( 'data_machine_events_render_add_to_calendar_button' ) ) {
	/**
	 * Emit the Add-to-Calendar button + dropdown HTML.
	 *
	 * @param int    $post_id    Event post ID.
	 * @param string $ticket_url Ticket URL (unused here, present for hook signature parity).
	 */
	function data_machine_events_render_add_to_calendar_button( $post_id, $ticket_url ) {
		unset( $ticket_url );

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( ! function_exists( 'data_machine_events_parse_event_data' ) ) {
			return;
		}

		$event = \data_machine_events_parse_event_data( $post );
		if ( ! $event ) {
			return;
		}

		// Inject post_id so URL builders can resolve title + permalink.
		$event['post_id'] = $post_id;

		$google_url  = \DataMachineEvents\EventActions\CalendarUrlBuilder::google( $event );
		$outlook_url = \DataMachineEvents\EventActions\CalendarUrlBuilder::outlook( $event );
		$ics_url     = \DataMachineEvents\EventActions\CalendarUrlBuilder::ics_url( $post_id );

		if ( ! $google_url && ! $outlook_url && ! $ics_url ) {
			return;
		}

		$menu_id = 'dm-atc-menu-' . $post_id;
		?>
		<div class="dm-events-add-to-calendar" data-dm-add-to-calendar>
			<button
				type="button"
				class="dm-events-action-btn dm-events-add-to-calendar-toggle ticket-button"
				aria-haspopup="true"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $menu_id ); ?>"
			>
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Add to Calendar', 'data-machine-events' ); ?>
			</button>
			<ul
				id="<?php echo esc_attr( $menu_id ); ?>"
				class="dm-events-add-to-calendar-menu"
				role="menu"
				hidden
			>
				<?php if ( $google_url ) : ?>
					<li role="none">
						<a
							role="menuitem"
							href="<?php echo esc_url( $google_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						><?php esc_html_e( 'Google Calendar', 'data-machine-events' ); ?></a>
					</li>
				<?php endif; ?>
				<?php if ( $outlook_url ) : ?>
					<li role="none">
						<a
							role="menuitem"
							href="<?php echo esc_url( $outlook_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						><?php esc_html_e( 'Outlook.com', 'data-machine-events' ); ?></a>
					</li>
				<?php endif; ?>
				<?php if ( $ics_url ) : ?>
					<li role="none">
						<a
							role="menuitem"
							href="<?php echo esc_url( $ics_url ); ?>"
							download
						><?php esc_html_e( 'Apple Calendar / Download (.ics)', 'data-machine-events' ); ?></a>
					</li>
				<?php endif; ?>
			</ul>
		</div>
		<?php
	}
}
