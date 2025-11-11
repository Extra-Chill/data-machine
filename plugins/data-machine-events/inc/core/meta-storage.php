<?php
/**
 * Event DateTime Meta Storage
 *
 * Core plugin feature that stores event datetime in post meta for efficient SQL queries.
 * Monitors Event Details block changes and syncs to post meta automatically.
 *
 * @package DM_Events
 */

/**
 * Sync event datetime to post meta on save
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function datamachine_events_sync_datetime_meta( $post_id, $post, $update ) {
	// Only for dm_events post type.
	if ( 'dm_events' !== $post->post_type ) {
		return;
	}

	// Avoid infinite loops during autosave.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// Parse blocks to extract event details from Event Details block.
	$blocks = parse_blocks( $post->post_content );

	foreach ( $blocks as $block ) {
		if ( 'datamachine-events/event-details' === $block['blockName'] ) {
			$start_date = $block['attrs']['startDate'] ?? '';
			$start_time = $block['attrs']['startTime'] ?? '00:00:00';

			if ( $start_date ) {
				// Combine into MySQL DATETIME format: "2024-12-25 14:30:00".
				$datetime = $start_date . ' ' . $start_time;
				update_post_meta( $post_id, '_dm_event_datetime', $datetime );
			} else {
				// No date found, delete meta if it exists.
				delete_post_meta( $post_id, '_dm_event_datetime' );
			}
			break;
		}
	}
}
add_action( 'save_post', 'dm_events_sync_datetime_meta', 10, 3 );

/**
 * Migration function to populate meta for existing events
 *
 * @return int Number of events updated.
 */
function datamachine_events_migrate_datetime_meta() {
	$events = get_posts(
		array(
			'post_type'      => 'dm_events',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		)
	);

	$updated = 0;
	foreach ( $events as $event ) {
		dm_events_sync_datetime_meta( $event->ID, $event, true );
		$updated++;
	}

	return $updated;
}

/**
 * Admin notice with migration button
 */
function datamachine_events_migration_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Check if migration has been run.
	$migration_complete = get_option( 'datamachine_events_meta_migration_complete', false );

	if ( $migration_complete ) {
		return;
	}

	?>
	<div class="notice notice-warning is-dismissible">
		<p><strong><?php esc_html_e( 'DM Events:', 'datamachine-events' ); ?></strong> <?php esc_html_e( 'Event datetime meta storage is now enabled. Click below to migrate existing events.', 'datamachine-events' ); ?></p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=datamachine-events-migrate' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Migrate Existing Events', 'datamachine-events' ); ?>
			</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'dm_events_migration_notice' );

/**
 * Admin page for migration
 */
function datamachine_events_migration_page() {
	add_management_page(
		__( 'DM Events Migration', 'datamachine-events' ),
		__( 'DM Events Migration', 'datamachine-events' ),
		'manage_options',
		'datamachine-events-migrate',
		'dm_events_migration_page_content'
	);
}
add_action( 'admin_menu', 'dm_events_migration_page' );

/**
 * Migration page content
 */
function datamachine_events_migration_page_content() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions', 'datamachine-events' ) );
	}

	if ( isset( $_POST['dm_events_run_migration'] ) && check_admin_referer( 'dm_events_migration' ) ) {
		$updated = dm_events_migrate_datetime_meta();
		update_option( 'datamachine_events_meta_migration_complete', true );
		echo '<div class="notice notice-success"><p>' .
			sprintf(
				/* translators: %d: Number of events migrated */
				esc_html__( 'Successfully migrated %d events!', 'datamachine-events' ),
				(int) $updated
			) .
			'</p></div>';
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DM Events Meta Migration', 'datamachine-events' ); ?></h1>
		<p><?php esc_html_e( 'This will populate the _dm_event_datetime meta field for all existing events, enabling efficient SQL-based pagination.', 'datamachine-events' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'dm_events_migration' ); ?>
			<input type="submit" name="dm_events_run_migration" class="button button-primary" value="<?php esc_attr_e( 'Run Migration', 'datamachine-events' ); ?>">
		</form>
	</div>
	<?php
}
