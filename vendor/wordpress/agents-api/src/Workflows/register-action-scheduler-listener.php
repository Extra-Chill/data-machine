<?php
/**
 * Action Scheduler listener for `cron`-triggered workflows.
 *
 * The bridge ({@see WP_Agent_Workflow_Action_Scheduler_Bridge}) registers
 * recurring/cron actions under the `wp_agent_workflow_run_scheduled` hook
 * with `args = [ 'workflow_id' => '...' ]`. This file closes the loop by
 * hooking into that action and dispatching the canonical `agents/run-workflow`
 * ability — same code path admins / channels use to trigger an on-demand run.
 *
 * Without this listener, schedules would land in Action Scheduler's queue
 * but never fire a workflow run.
 *
 * @package AgentsAPI
 * @since   0.104.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

add_action(
	WP_Agent_Workflow_Action_Scheduler_Bridge::SCHEDULED_HOOK,
	__NAMESPACE__ . '\\dispatch_scheduled_workflow_run',
	10,
	1
);

/**
 * Run the scheduled workflow via the canonical dispatcher.
 *
 * Action Scheduler invokes the registered hook with the action's args
 * spread as positional arguments; we only register a single arg (the
 * `workflow_id` key) so the callback receives the full args array as the
 * first positional. AS internally uses `array_values` for the spread —
 * so what arrives is the bare workflow_id string.
 *
 * Errors are swallowed to a `do_action` for observability; throwing here
 * would mark the AS action as failed and back-off the schedule, which is
 * usually desirable, but we surface the failure through agents-api's own
 * dispatch-failure hook instead so consumers can decide policy.
 *
 * @since 0.104.0
 *
 * @param string|array<mixed> $args Either the bare workflow_id string (when the
 *                                  bridge scheduled a single-arg payload) or the
 *                                  full args array (when AS spreads).
 */
function dispatch_scheduled_workflow_run( $args ): void {
	$workflow_id = '';
	if ( is_string( $args ) ) {
		$workflow_id = $args;
	} elseif ( is_array( $args ) ) {
		$workflow_id = extract_scheduled_workflow_id( $args );
	}

	if ( '' === $workflow_id ) {
		do_action( 'agents_run_workflow_dispatch_failed', 'no_workflow_id', array( 'source' => 'action_scheduler' ) );
		return;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		do_action( 'agents_run_workflow_dispatch_failed', 'abilities_api_missing', array( 'workflow_id' => $workflow_id ) );
		return;
	}

	$ability = wp_get_ability( AGENTS_RUN_WORKFLOW_ABILITY );
	if ( null === $ability ) {
		do_action( 'agents_run_workflow_dispatch_failed', 'ability_missing', array( 'workflow_id' => $workflow_id ) );
		return;
	}

	// Action Scheduler runs as the loopback / cron user — bypass the
	// `manage_options` gate for this scheduled invocation only. We scope
	// the temporary widening with add/remove so we don't influence
	// concurrent requests.
	$grant = static fn() => true;
	add_filter( 'agents_run_workflow_permission', $grant );
	try {
		$result = $ability->execute(
			array(
				'workflow_id' => $workflow_id,
				'inputs'      => array(),
				'options'     => array( 'source' => 'action_scheduler' ),
			)
		);
	} finally {
		remove_filter( 'agents_run_workflow_permission', $grant );
	}

	if ( is_wp_error( $result ) ) {
		do_action(
			'agents_run_workflow_dispatch_failed',
			$result->get_error_code(),
			array(
				'workflow_id' => $workflow_id,
				'source'      => 'action_scheduler',
			)
		);
	}
}

/**
 * @param array<mixed> $args Scheduled action args.
 */
function extract_scheduled_workflow_id( array $args ): string {
	$value = $args['workflow_id'] ?? ( $args[0] ?? '' );
	return is_string( $value ) ? $value : '';
}
