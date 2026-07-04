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
	3
);

if ( ! function_exists( 'data_machine_events_render_add_to_calendar_button' ) ) {
	/**
	 * Emit the Add-to-Calendar button + dropdown HTML.
	 *
	 * @param int    $post_id    Event post ID.
	 * @param string $ticket_url Ticket URL (unused here, present for hook signature parity).
	 * @param string $timing     Event timing state: 'upcoming' | 'ongoing' | 'past'.
	 *                           Defaults to 'upcoming' when omitted (older callers
	 *                           of the action that pass only two args).
	 */
	function data_machine_events_render_add_to_calendar_button( $post_id, $ticket_url, $timing = 'upcoming' ) {
		unset( $ticket_url );

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		/**
		 * Whether to render the Add-to-Calendar button for this event.
		 *
		 * Defaults to false for past events — adding a finished show to a
		 * personal calendar is not a useful action — and true otherwise.
		 * Filterable so a consuming site can override the default in either
		 * direction.
		 *
		 * @since 0.46.0
		 *
		 * @param bool   $show    Whether to show the Add-to-Calendar button. Default: false on past, true otherwise.
		 * @param int    $post_id Event post ID.
		 * @param string $timing  Event timing state: 'upcoming' | 'ongoing' | 'past'.
		 */
		$show_add_to_calendar = apply_filters(
			'data_machine_events_show_add_to_calendar',
			'past' !== $timing,
			$post_id,
			$timing
		);
		if ( ! $show_add_to_calendar ) {
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

		/**
		 * Filters the CSS classes applied to the Add-to-Calendar toggle button.
		 *
		 * Mirrors `data_machine_events_ticket_button_classes` so a consuming
		 * theme/plugin can route the toggle through its own button system (e.g.
		 * map it onto theme `button-*` classes) instead of relying on the
		 * default `ticket-button` styling. The base classes
		 * (`dm-events-action-btn dm-events-add-to-calendar-toggle`) are always
		 * present; only the trailing style classes are filterable.
		 *
		 * @since 0.44.2
		 *
		 * @param string[] $classes Style classes for the toggle. Default `['ticket-button']`.
		 * @param int      $post_id Event post ID.
		 */
		$toggle_style_classes = apply_filters(
			'data_machine_events_add_to_calendar_button_classes',
			array( 'ticket-button' ),
			$post_id
		);

		$toggle_classes = array_merge(
			array( 'dm-events-action-btn', 'dm-events-add-to-calendar-toggle' ),
			(array) $toggle_style_classes
		);
		?>
		<div class="dm-events-add-to-calendar" data-dm-add-to-calendar>
			<button
				type="button"
				class="<?php echo esc_attr( implode( ' ', $toggle_classes ) ); ?>"
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
