<?php
/**
 * Data Machine — Processed-item in-flight claim columns.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add claim state columns to the processed-items ledger.
 *
 * Idempotent: gated on `datamachine_processed_item_claims_migrated`, with
 * repository-level column/index checks doing the actual schema work.
 *
 * @return void
 */
function datamachine_migrate_processed_item_claims(): void {
	if ( get_option( 'datamachine_processed_item_claims_migrated', false ) ) {
		return;
	}

	$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
	\DataMachine\Core\Database\ProcessedItems\ProcessedItems::ensure_claim_columns( $db_processed_items->get_table_name() );

	update_option( 'datamachine_processed_item_claims_migrated', true, true );
}
