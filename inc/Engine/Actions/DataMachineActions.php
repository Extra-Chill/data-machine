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

use DataMachine\Abilities\EngineAbilities;
use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Engine\Actions\Handlers\MarkItemProcessedHandler;
use DataMachine\Engine\Actions\Handlers\FailJobHandler;
use DataMachine\Engine\Actions\Handlers\JobCompleteHandler;
use DataMachine\Engine\Actions\Handlers\LogHandler;
use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;

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
	add_action( 'datamachine_step_lifecycle_inline_continuation', array( StepLifecycleHandler::class, 'handleInlineContinuation' ), 10, 3 );
	add_action( 'datamachine_step_lifecycle_completed', array( StepLifecycleHandler::class, 'handleCompleted' ), 10, 2 );
	add_action( 'datamachine_step_lifecycle_failed', array( StepLifecycleHandler::class, 'handleFailed' ), 10, 2 );
	add_action( 'datamachine_job_complete', array( StepLifecycleHandler::class, 'handleTerminal' ), 5, 2 );
	add_filter( 'datamachine_job_terminal_status', array( StepLifecycleHandler::class, 'filterTerminalStatus' ), 10, 3 );
	add_action( 'datamachine_job_terminal_rolled_back', array( StepLifecycleHandler::class, 'handleTerminalRollback' ) );
	add_action( 'datamachine_job_terminal_committed', array( StepLifecycleHandler::class, 'handleTerminalCommit' ), 10, 2 );
	add_action( 'datamachine_batch_items_discarded', array( StepLifecycleHandler::class, 'handleDiscardedPackets' ), 10, 4 );
	add_filter( 'datamachine_batch_item_cleanup_context', array( StepLifecycleHandler::class, 'captureBatchItemCleanupContext' ), 10, 2 );
	add_filter( 'datamachine_item_claim_completion_handlers', array( TrackedItems::class, 'registerClaimCompletionHandler' ) );
	add_action(
		'datamachine_pending_action_staged',
		function ( string $action_id, array $payload ): void {
			$context = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();
			$job_id  = (int) ( $context['job_id'] ?? 0 );
			if ( $job_id > 0 ) {
				\DataMachine\Core\RunMetrics::increment( $job_id, 'staged_actions' );
			}
		},
		10,
		2
	);
	add_action(
		'datamachine_pending_action_resolved',
		function ( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver ): void {
			unset( $resolver );

			$metadata    = $action->get_metadata();
			$datamachine = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
			$context     = is_array( $datamachine['context'] ?? null ) ? $datamachine['context'] : array();
			$job_id      = (int) ( $context['job_id'] ?? 0 );
			if ( $job_id <= 0 ) {
				return;
			}
			$key = $decision->is_accepted() ? 'accepted_actions' : 'rejected_actions';
			\DataMachine\Core\RunMetrics::increment( $job_id, $key );
		},
		10,
		3
	);

	\DataMachine\Engine\Actions\ImportExport::register();

	// Pipeline batch fan-out: process chunks and track child completion.
	add_action(
		PipelineBatchScheduler::BATCH_HOOK,
		function ( $parent_job_id, $offset = null ) {
			$scheduler = new PipelineBatchScheduler();
			$scheduler->processChunk( (int) $parent_job_id, null === $offset ? null : (int) $offset );
		},
		10,
		2
	);
	add_action( 'datamachine_job_complete', array( PipelineBatchScheduler::class, 'onChildComplete' ), 20, 2 );

	// Register engine abilities (business logic) before hook bridges.
	new EngineAbilities();
	datamachine_register_execution_engine();
}
