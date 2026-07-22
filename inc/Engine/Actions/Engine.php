<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Execution engine — shared utilities and action hook bridges.
 *
 * Business logic lives in Abilities\Engine\* classes. The action hooks
 * registered here are thin bridges required by Action Scheduler, which
 * can only fire do_action() calls.
 *
 * Execution cycle: datamachine_run_flow_now → datamachine_execute_step → datamachine_schedule_next_step
 * Scheduling cycle: datamachine_run_flow_later → Action Scheduler → datamachine_run_flow_now
 *
 * @package DataMachine\Engine\Actions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize stored configuration blobs into arrays.
 */
function datamachine_normalize_engine_config( $config ): array {
	if ( is_array( $config ) ) {
		return $config;
	}

	if ( is_string( $config ) ) {
		$decoded = json_decode( $config, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	return array();
}

/**
 * Get file context array from flow ID.
 *
 * @param int|string|null $flow_id Flow ID, 'direct', or null.
 * @return array Context array with pipeline/flow metadata.
 */
function datamachine_get_file_context( int|string|null $flow_id ): array {
	return \DataMachine\Api\FlowFiles::get_file_context( $flow_id );
}

/**
 * Check if a flow exists by ID.
 *
 * Lightweight existence check to avoid loading full flow data.
 *
 * @param int $flow_id Flow ID to check.
 * @return bool True if flow exists, false otherwise.
 */
function datamachine_flow_exists( int $flow_id ): bool {
	global $wpdb;
	$table_name = $wpdb->prefix . 'datamachine_flows';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE flow_id = %d LIMIT 1', $table_name, $flow_id ) );
	return null !== $exists;
}

/**
 * Bridge an Action Scheduler step action into the canonical execute ability.
 *
 * Both initial execution and AI contention resumes use this callback so a
 * resume cannot drift into a parallel execution implementation.
 */
function datamachine_execute_step_action( $job_id, string $flow_step_id, $operation_generation = 0, $operation_claim_token = '' ): void {
	$ability = wp_get_ability( 'datamachine/execute-step' );
	if ( $ability ) {
		$ability->execute(
			array(
				'job_id'                => (int) $job_id,
				'flow_step_id'          => $flow_step_id,
				'operation_generation'  => is_numeric( $operation_generation ) ? (int) $operation_generation : 0,
				'operation_claim_token' => is_string( $operation_claim_token ) ? $operation_claim_token : '',
			)
		);
	}
}

/**
 * Register execution engine action hooks as thin bridges to abilities.
 *
 * Action Scheduler fires do_action() — these hooks delegate immediately
 * to the corresponding ability via wp_get_ability()->execute().
 */
function datamachine_register_execution_engine() {

	/**
	 * Bridge: datamachine_run_flow_now → datamachine/run-flow ability.
	 *
	 * Includes defensive check for orphaned scheduled actions. If the flow
	 * no longer exists (e.g., was deleted without cleanup), cancels all
	 * scheduled actions for that flow to prevent recurring errors.
	 */
	add_action(
		'datamachine_run_flow_now',
		function ( $flow_id, $job_id = null ) {
			$flow_id = (int) $flow_id;

			// Defensive: Check if flow exists before executing.
			// If flow was deleted without cleaning up scheduled actions,
			// cancel the orphaned actions to prevent recurring errors.
			if ( ! datamachine_flow_exists( $flow_id ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
				do_action(
					'datamachine_log',
					'warning',
					'Orphaned scheduled action cleaned up for deleted flow',
					array( 'flow_id' => $flow_id )
				);
				return;
			}

			$ability = wp_get_ability( 'datamachine/run-flow' );
			if ( $ability ) {
				$ability->execute(
					array(
						'flow_id'        => $flow_id,
						'job_id'         => $job_id ? (int) $job_id : null,
						'respect_paused' => true,
					)
				);
			}
		},
		10,
		2
	);

	/**
	 * Bridge: datamachine_execute_step → datamachine/execute-step ability.
	 */
	add_action(
		'datamachine_execute_step',
		'datamachine_execute_step_action',
		10,
		4
	);

	/** Dedicated resume bridge avoids collision with the running execute action. */
	add_action(
		'datamachine_resume_ai_step',
		'datamachine_execute_step_action',
		10,
		4
	);

	/**
	 * Bridge: datamachine_schedule_next_step → datamachine/schedule-next-step ability.
	 */
	add_action(
		'datamachine_schedule_next_step',
		function ( $job_id, $flow_step_id, $dataPackets = array() ) {
			$ability = wp_get_ability( 'datamachine/schedule-next-step' );
			if ( $ability ) {
				$ability->execute(
					array(
						'job_id'       => (int) $job_id,
						'flow_step_id' => $flow_step_id,
						'data_packets' => $dataPackets,
					)
				);
			}
		},
		10,
		3
	);

	/**
	 * Bridge: datamachine_run_flow_later → datamachine/schedule-flow ability.
	 */
	add_action(
		'datamachine_run_flow_later',
		function ( $flow_id, $interval_or_timestamp ) {
			$ability = wp_get_ability( 'datamachine/schedule-flow' );
			if ( $ability ) {
				$ability->execute(
					array(
						'flow_id'               => (int) $flow_id,
						'interval_or_timestamp' => $interval_or_timestamp,
					)
				);
			}
		},
		10,
		2
	);
}
