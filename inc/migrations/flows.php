<?php
/**
 * Data Machine flow schedule repair lifecycle.
 *
 * Activation and deploy-time migrations only mark the current site. The repair
 * runs after Action Scheduler initializes so datastore reads and writes are safe.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persist a per-site marker requesting flow schedule reconciliation.
 *
 * @return void
 */
function datamachine_mark_flow_schedule_reconciliation(): void {
	update_option(
		'datamachine_flow_schedule_reconciliation_pending',
		array(
			'marked_at' => time(),
			'reason'    => 'activation_or_deploy',
		),
		false
	);
}

/**
 * Repair marked flow schedules once Action Scheduler is ready.
 *
 * The marker is retained after transient failures so a later request can retry.
 * Paused, manual, one-time, and malformed schedule definitions are not repaired;
 * malformed definitions are reported without making the marker permanent.
 *
 * @return void
 */
function datamachine_reconcile_marked_flow_schedules(): void {
	if ( ! get_option( 'datamachine_flow_schedule_reconciliation_pending', false ) ) {
		return;
	}

	if ( ! \DataMachine\Engine\Tasks\RecurringScheduler::isReady() ) {
		return;
	}

	$result = ( new \DataMachine\Api\Flows\FlowScheduleReconciler() )->reconcile( true );
	if ( empty( $result['success'] ) && ! empty( $result['transient'] ) ) {
		do_action(
			'datamachine_log',
			'error',
			'Deferred flow schedule reconciliation failed; marker retained',
			array( 'result' => $result )
		);
		return;
	}

	delete_option( 'datamachine_flow_schedule_reconciliation_pending' );
	if ( empty( $result['success'] ) ) {
		do_action(
			'datamachine_log',
			'error',
			'Deferred flow schedule reconciliation failed permanently; marker cleared',
			array( 'result' => $result )
		);
		return;
	}
	do_action(
		'datamachine_log',
		'info',
		'Deferred flow schedule reconciliation completed',
		array(
			'eligible' => (int) ( $result['eligible'] ?? 0 ),
			'repaired' => (int) ( $result['repaired'] ?? 0 ),
		)
	);
}

add_action( 'action_scheduler_init', 'datamachine_reconcile_marked_flow_schedules', 20 );
