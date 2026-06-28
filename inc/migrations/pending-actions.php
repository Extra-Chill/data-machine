<?php
/**
 * Data Machine — pending-action durable table migration.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure the durable pending-action table exists on upgraded installs.
 *
 * Also runs the workspace column ensure to backfill the
 * `workspace_type`/`workspace_id` columns on installs that pre-date them.
 * dbDelta inside `create_table()` covers fresh installs; the explicit
 * ensure handles installs created before the columns landed.
 */
function datamachine_migrate_pending_actions_table(): void {
	\DataMachine\Engine\AI\Actions\PendingActionStore::create_table();
	\DataMachine\Engine\AI\Actions\PendingActionStore::ensure_workspace_columns();
}
