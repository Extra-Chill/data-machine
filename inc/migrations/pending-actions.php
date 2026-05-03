<?php
/**
 * Data Machine — pending-action durable table migration.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure the durable pending-action table exists on upgraded installs.
 */
function datamachine_migrate_pending_actions_table(): void {
	\DataMachine\Engine\AI\Actions\PendingActionStore::create_table();
}
