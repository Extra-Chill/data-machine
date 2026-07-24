<?php
/**
 * Data Machine — indexed run metadata table migration.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure the indexed run metadata table exists on upgraded installs.
 *
 * @return void
 */
function datamachine_migrate_run_metadata_table(): void {
	\DataMachine\Core\Database\RunMetadata\RunMetadata::create_table();
}
