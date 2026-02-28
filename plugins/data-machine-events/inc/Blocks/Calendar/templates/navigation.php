<?php
/**
 * Navigation Template
 *
 * Renders Past/Upcoming events navigation buttons.
 *
 * @var bool $show_past Whether currently showing past events
 * @var int $past_events_count Number of past events available
 * @var int $future_events_count Number of future events available
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Preserve all GET parameters except 'paged' and 'past'
$get_params = isset( $_GET ) ? datamachine_events_sanitize_query_params( wp_unslash( $_GET ) ) : array();
unset( $get_params['paged'] );
unset( $get_params['past'] );

// Show Past Events button when viewing upcoming events and past events exist
if ( ! $show_past && $past_events_count > 0 ) :
	$past_url = add_query_arg( array_merge( $get_params, array( 'past' => '1' ) ) );
	?>
	<div class="datamachine-events-past-navigation">
		<a href="<?php echo esc_url( $past_url ); ?>" class="datamachine-events-past-btn">
			<?php esc_html_e( '← Past Events', 'datamachine-events' ); ?>
		</a>
	</div>

	<?php
	// Show Upcoming Events button when viewing past events and future events exist
elseif ( $show_past && $future_events_count > 0 ) :
	$upcoming_url = remove_query_arg( 'past', add_query_arg( $get_params ) );
	?>
	<div class="datamachine-events-past-navigation">
		<a href="<?php echo esc_url( $upcoming_url ); ?>" class="datamachine-events-upcoming-btn">
			<?php esc_html_e( 'Upcoming Events →', 'datamachine-events' ); ?>
		</a>
	</div>

<?php endif; ?>