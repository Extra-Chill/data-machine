<?php
/**
 * Engine Helpers Trait
 *
 * Shared helper methods used across Engine ability classes.
 * Provides database access and permission checks for engine operations.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

defined( 'ABSPATH' ) || exit;

trait EngineHelpers {

	protected Flows $db_flows;
	protected Jobs $db_jobs;
	protected Pipelines $db_pipelines;
	protected ProcessedItems $db_processed_items;

	/**
	 * Initialize database instances.
	 */
	protected function initDatabases(): void {
		$this->db_flows           = new Flows();
		$this->db_jobs            = new Jobs();
		$this->db_pipelines       = new Pipelines();
		$this->db_processed_items = new ProcessedItems();
	}

	/**
	 * Permission callback for engine abilities.
	 *
	 * Engine operations are system-level (triggered by Action Scheduler),
	 * but restrict direct invocation to administrators.
	 *
	 * @return bool True if permitted.
	 */
	public function checkPermission(): bool {
		return \DataMachine\Abilities\PermissionHelper::can_manage();
	}
}
