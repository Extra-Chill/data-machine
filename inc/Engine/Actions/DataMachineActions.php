<?php
/**
 * Data Machine Core Action Hooks
 *
 * Central registration for "button press" style action hooks that simplify
 * repetitive behaviors throughout the Data Machine plugin. These actions
 * provide consistent trigger points for common operations.
 *
 * ACTION HOOK PATTERNS:
 * - "Button Press" Style: Actions that multiple pathways can trigger
 * - Centralized Logic: Complex operations consolidated into single handlers
 * - Consistent Error Handling: Unified logging and validation patterns
 * - Service Discovery: Filter-based service access for architectural consistency
 *
 * Core Workflow and Utility Actions Registered:
 * - datamachine_run_flow_now: Central flow execution trigger for manual/scheduled runs
 * - datamachine_execute_step: Core step execution engine for Action Scheduler pipeline processing
 * - datamachine_schedule_next_step: Central pipeline step scheduling eliminating Action Scheduler duplication
 * - datamachine_mark_item_processed: Universal processed item marking across all handlers
 * - datamachine_fail_job: Central job failure handling with cleanup and logging
 * - datamachine_log: Central logging operations eliminating logger service discovery
 *
 * UTILITIES (Abilities API):
 * - LogAbilities: Log file operations (write, clear, read, metadata, level management)
 *
 * EXTENSIBILITY EXAMPLES:
 * External plugins can add: datamachine_transform, datamachine_validate, datamachine_backup, datamachine_migrate, datamachine_sync, datamachine_analyze
 *
 * ARCHITECTURAL BENEFITS:
 * - WordPress-native action registration: Direct add_action() calls, zero overhead
 * - External plugin extensibility: Standard WordPress action registration patterns
 * - Eliminates code duplication across multiple trigger points
 * - Provides single source of truth for complex operations
 * - Simplifies call sites from 40+ lines to single action calls
 *
 * @package DataMachine
 * @since 0.1.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include organized action classes
require_once __DIR__ . '/ImportExport.php';
require_once __DIR__ . '/Engine.php';

use DataMachine\Engine\Actions\Handlers\MarkItemProcessedHandler;
use DataMachine\Engine\Actions\Handlers\FailJobHandler;
use DataMachine\Engine\Actions\Handlers\JobCompleteHandler;
use DataMachine\Engine\Actions\Handlers\LogHandler;
use DataMachine\Engine\Actions\Handlers\LogManageHandler;

/**
 * Register core Data Machine action hooks.
 *
 * @since 0.1.0
 */
function datamachine_register_core_actions() {

	add_action( 'datamachine_mark_item_processed', array( MarkItemProcessedHandler::class, 'handle' ), 10, 4 );
	add_action( 'datamachine_fail_job', array( FailJobHandler::class, 'handle' ), 10, 3 );
	add_action( 'datamachine_job_complete', array( JobCompleteHandler::class, 'handle' ), 10, 2 );
	add_action( 'datamachine_log', array( LogHandler::class, 'handle' ), 10, 3 );
	add_action( 'datamachine_log_manage', array( LogManageHandler::class, 'handle' ), 10, 4 );

	\DataMachine\Engine\Actions\ImportExport::register();
	datamachine_register_execution_engine();
}
