<?php
/**
 * Data Machine — Drop redundant _datamachine_post_pipeline_id post meta (#1091).
 *
 * pipeline_id is fully derivable from _datamachine_post_flow_id via the
 * flows table (datamachine_flows.pipeline_id is immutable). The redundant
 * meta was written by PostTracking::store() on every publish until v0.69.1;
 * this migration removes the stale rows from wp_postmeta.
 *
 * Runs once per site via the version-gated datamachine_maybe_run_migrations
 * flow. Stores its completion flag in the site option
 * datamachine_post_pipeline_meta_dropped so repeated activation calls are
 * no-ops.
 *
 * @package DataMachine
 * @since 0.69.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delete all _datamachine_post_pipeline_id rows from wp_postmeta.
 *
 * Idempotent: the completion flag prevents repeat execution, and the
 * underlying DELETE is safe to re-run (matches zero rows after the first
 * successful pass).
 *
 * @since 0.69.1
 */
function datamachine_drop_redundant_post_pipeline_meta(): void {
	$already_done = get_option( 'datamachine_post_pipeline_meta_dropped', false );
	if ( $already_done ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => '_datamachine_post_pipeline_id' ),
		array( '%s' )
	);

	if ( false === $deleted ) {
		do_action(
			'datamachine_log',
			'error',
			'Failed to drop redundant _datamachine_post_pipeline_id rows',
			array(
				'db_error' => $wpdb->last_error,
			)
		);
		return;
	}

	update_option( 'datamachine_post_pipeline_meta_dropped', true, true );

	if ( $deleted > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Dropped redundant _datamachine_post_pipeline_id rows (#1091)',
			array(
				'rows_deleted' => (int) $deleted,
			)
		);
	}
}
