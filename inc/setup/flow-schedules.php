<?php
/**
 * Data Machine flow schedule reconciliation lifecycle.
 *
 * Activation and deploy-time migrations only mark the current site. The repair
 * runs after init so the routines adapter has re-declared the flow registry
 * and Action Scheduler datastore reads and writes are safe.
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
 * Repair marked flow schedules once the routines adapter is available.
 *
 * The marker is retained after failures so a later request can retry.
 * FlowRoutines::reconcile() re-declares the registry before reconciling,
 * so repairs classify against a fully populated routine set.
 *
 * @return void
 */
function datamachine_reconcile_marked_flow_schedules(): void {
	if ( ! get_option( 'datamachine_flow_schedule_reconciliation_pending', false ) ) {
		return;
	}

	if ( ! \DataMachine\Engine\Scheduling\FlowRoutines::available() ) {
		return;
	}

	$result = \DataMachine\Engine\Scheduling\FlowRoutines::reconcile( true );
	if ( empty( $result['success'] ) ) {
		do_action(
			'datamachine_log',
			'error',
			'Deferred flow schedule reconciliation failed; marker retained',
			array( 'result' => $result )
		);
		return;
	}

	delete_option( 'datamachine_flow_schedule_reconciliation_pending' );
	do_action(
		'datamachine_log',
		'info',
		'Deferred flow schedule reconciliation completed',
		array(
			'covered' => (int) ( $result['covered'] ?? 0 ),
			'missing' => (int) ( $result['missing'] ?? 0 ),
			'removed' => (int) ( $result['removed'] ?? 0 ),
		)
	);
}

add_action( 'init', 'datamachine_reconcile_marked_flow_schedules', 10 );
