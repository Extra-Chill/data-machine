<?php
/**
 * Data Machine — Activation orchestration.
 *
 * Auto-run DB migrations when code version is ahead of stored DB version.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auto-run DB migrations when code version is ahead of stored DB version.
 *
 * Deploys via rsync/homeboy don't trigger activation hooks, so new columns
 * are silently missing until someone manually reactivates. This check runs
 * on every request and calls the idempotent activation function when the
 * deployed code version exceeds the stored DB schema version.
 *
 * Pattern used by WooCommerce, bbPress, and most plugins with custom tables.
 *
 * @since 0.35.0
 */
function datamachine_maybe_run_migrations() {
	$db_version = get_option( 'datamachine_db_version', '0.0.0' );

	if ( version_compare( $db_version, DATAMACHINE_VERSION, '>=' ) ) {
		return;
	}

	datamachine_activate_for_site();
	update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
}
add_action( 'init', 'datamachine_maybe_run_migrations', 5 );
