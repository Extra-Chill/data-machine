<?php
/**
 * Data Machine flow schedule reconciliation lifecycle.
 *
 * Activation and deploy-time migrations only mark the current site. The
 * actual reconcile runs out of the request path, inside a single Action
 * Scheduler async action, so a page view never pays the cost of a full
 * routine registration pass.
 *
 * Extra-Chill/data-machine#3492: the previous version ran
 * `FlowRoutines::reconcile(true)` inline on `init` of every web request
 * while the marker was set. With 60 concurrent php-fpm workers all racing
 * for the registry's reconcile lock, 59 of them re-registered ~700 routines
 * through `boot()` before discovering the lock was already held, and lock
 * contention was misclassified as failure — so the marker was retained and
 * every subsequent request paid the same cost again. `FlowRoutines::reconcile()`
 * now takes a DM-owned lock before `boot()` runs, so a losing caller's cost is
 * one option read; this file additionally moves the whole reconcile call off
 * the request path and treats lock contention as a silent no-op rather than a
 * failure worth retrying on every request.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler hook the deferred reconcile runs under.
 */
const DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK = 'datamachine_run_deferred_flow_schedule_reconciliation';

/**
 * Transient suppressing re-enqueue after a genuine reconcile failure.
 */
const DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF = 'datamachine_flow_schedule_reconciliation_backoff';

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
 * Enqueue the deferred reconcile off the request path, once.
 *
 * Runs on `init` while the marker is set. This function itself never calls
 * `FlowRoutines::reconcile()` — it only ensures exactly one Action Scheduler
 * async action is pending to do that work. Enqueuing is a cheap
 * `as_has_scheduled_action()` read plus (at most) one insert, regardless of
 * how many concurrent requests hit `init` while the marker is live.
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

	if ( get_transient( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF ) ) {
		return;
	}

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return;
	}

	$group = \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP;

	if ( function_exists( 'as_has_scheduled_action' )
		&& as_has_scheduled_action( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK, array(), $group )
	) {
		return;
	}

	as_enqueue_async_action( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK, array(), $group );
}

/**
 * Run the deferred flow schedule reconcile.
 *
 * This is the Action Scheduler worker callback for
 * {@see DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK}. It runs off the web
 * request path so its cost — a full `FlowRoutines::boot()` pass plus the
 * registry reconcile algorithm — never lands on a page view.
 *
 * A `skipped` result (the DM reconcile lock was held by someone else) is a
 * silent no-op: it is not an error, the marker is left alone, and the
 * holder of the lock is responsible for either finishing the repair or
 * leaving the marker for the next enqueue to retry. A genuine failure
 * retains the marker and sets a short backoff transient so a persistently
 * failing reconcile does not re-enqueue on every subsequent request.
 *
 * @return void
 */
function datamachine_run_deferred_flow_schedule_reconciliation(): void {
	if ( ! get_option( 'datamachine_flow_schedule_reconciliation_pending', false ) ) {
		return;
	}

	if ( ! \DataMachine\Engine\Scheduling\FlowRoutines::available() ) {
		return;
	}

	$result = \DataMachine\Engine\Scheduling\FlowRoutines::reconcile( true );

	if ( ! empty( $result['skipped'] ) ) {
		return;
	}

	if ( empty( $result['success'] ) ) {
		do_action(
			'datamachine_log',
			'error',
			'Deferred flow schedule reconciliation failed; marker retained',
			array( 'result' => $result )
		);
		set_transient( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_BACKOFF, true, 5 * MINUTE_IN_SECONDS );
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
add_action( DATAMACHINE_FLOW_SCHEDULE_RECONCILE_HOOK, 'datamachine_run_deferred_flow_schedule_reconciliation' );
