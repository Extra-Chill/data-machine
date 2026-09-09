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
 * Scheduling cycle: Agents API Routines wake `datamachine/run-flow`; one-time runs use datamachine_run_flow_once.
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
function datamachine_execute_step_action( $job_id, string $flow_step_id, $operation_generation = 0, $operation_claim_token = '', $ai_resume_generation = 0, $recovery_generation = 0, $recovery_claim_token = '' ): void {
	$ability = wp_get_ability( 'datamachine/execute-step' );
	if ( $ability ) {
		$result = $ability->execute(
			array(
				'job_id'                => (int) $job_id,
				'flow_step_id'          => $flow_step_id,
				'operation_generation'  => is_numeric( $operation_generation ) ? (int) $operation_generation : 0,
				'operation_claim_token' => is_string( $operation_claim_token ) ? $operation_claim_token : '',
				'ai_resume_generation'  => is_numeric( $ai_resume_generation ) ? (int) $ai_resume_generation : 0,
				'recovery_generation'   => is_numeric( $recovery_generation ) ? (int) $recovery_generation : 0,
				'recovery_claim_token'  => is_string( $recovery_claim_token ) ? $recovery_claim_token : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && ! empty( $error_data['retryable'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal scheduler control flow, not rendered output.
				throw new \RuntimeException( $result->get_error_message() );
			}
			return;
		}
		if ( in_array( (string) ( $result['outcome'] ?? '' ), array( 'claim_completion_failed', 'terminal_transition_failed' ), true ) ) {
			throw new \RuntimeException( 'Data Machine terminal transition failed.' );
		}
	}
}

/**
 * Log one routine lifecycle outcome with the derived flow id attached.
 *
 * @param string               $level     Log level.
 * @param string               $message   Log message.
 * @param array<string, mixed> $context   Log context.
 * @param string               $routine_id Routine id, when known.
 */
function datamachine_log_routine_outcome( string $level, string $message, array $context = array(), string $routine_id = '' ): void {
	$flow_id = \DataMachine\Engine\Scheduling\FlowRoutines::flow_id_from_routine_id( $routine_id );
	if ( $flow_id > 0 ) {
		$context['flow_id'] = $flow_id;
	}
	if ( '' !== $routine_id && ! isset( $context['routine_id'] ) ) {
		$context['routine_id'] = $routine_id;
	}

	do_action( 'datamachine_log', $level, $message, $context );
}

/**
 * Dispatch one flow run through the canonical run-flow ability.
 *
 * Shared by the datamachine_run_flow_now and datamachine_run_flow_once
 * bridges; a missing flow is logged and dropped.
 *
 * @param int   $flow_id Flow ID.
 * @param mixed $job_id  Optional pre-created job ID.
 */
function datamachine_dispatch_run_flow( $flow_id, $job_id = null ): void {
	$flow_id = (int) $flow_id;

	if ( ! datamachine_flow_exists( $flow_id ) ) {
		do_action(
			'datamachine_log',
			'warning',
			'Scheduled wake ignored for missing flow',
			array( 'flow_id' => $flow_id )
		);
		return;
	}

	$ability = wp_get_ability( 'datamachine/run-flow' );
	if ( $ability ) {
		$ability->execute(
			array(
				'flow_id'        => $flow_id,
				'job_id'         => null !== $job_id ? (int) $job_id : null,
				'respect_paused' => true,
			)
		);
	}
}

/** Validate durable resume ownership before entering canonical step execution. */
function datamachine_resume_ai_step_action( $job_id, string $flow_step_id, $operation_generation = 0, $operation_claim_token = '', $ai_resume_generation = 0 ): void {
	$job_id               = (int) $job_id;
	$ai_resume_generation = is_numeric( $ai_resume_generation ) ? (int) $ai_resume_generation : 0;
	if ( ! \DataMachine\Engine\AI\AIConcurrencyBackpressure::beginGeneration( $job_id, $flow_step_id, $ai_resume_generation, time() ) ) {
		do_action(
			'datamachine_log',
			'warning',
			'Stale AI concurrency resume generation rejected',
			array(
				'job_id'               => $job_id,
				'flow_step_id'         => $flow_step_id,
				'ai_resume_generation' => $ai_resume_generation,
			)
		);
		return;
	}

	datamachine_execute_step_action( $job_id, $flow_step_id, $operation_generation, $operation_claim_token, $ai_resume_generation );
}

/**
 * Register execution engine action hooks as thin bridges to abilities.
 *
 * Action Scheduler fires do_action() — these hooks delegate immediately
 * to the corresponding ability via wp_get_ability()->execute().
 *
 * Recurring scheduling runs through Agents API Routines: scheduled wakes
 * fire `wp_agent_routine_run_scheduled` and the substrate executes
 * `datamachine/run-flow` directly. This engine registers the routines
 * adapter on init plus wake observability below.
 */
function datamachine_register_execution_engine() {

	// Boot the routines adapter before Action Scheduler processes the queue.
	add_action( 'init', array( \DataMachine\Engine\Scheduling\FlowRoutines::class, 'boot' ), 5 );

	/**
	 * Routine wake observability: log every scheduled flow/system run.
	 *
	 * @param mixed $routine The waking routine value object.
	 * @param mixed $result  Ability or chat output.
	 */
	add_action(
		'wp_agent_routine_run_completed',
		static function ( $routine, $result = null ): void {
			unset( $result );
			if ( ! is_object( $routine ) || ! method_exists( $routine, 'get_id' ) ) {
				return;
			}

			datamachine_log_routine_outcome(
				'info',
				'Scheduled routine run completed',
				array(),
				(string) $routine->get_id()
			);
		},
		10,
		2
	);

	/**
	 * Routine dispatch failure observability.
	 *
	 * @param string $code   Failure code from the substrate.
	 * @param mixed  $context Failure context (routine_id, ability, ...).
	 */
	add_action(
		'agents_run_routine_dispatch_failed',
		static function ( $code, $context = array() ): void {
			$context = is_array( $context ) ? $context : array();
			if ( isset( $context['routine_id'] ) && is_string( $context['routine_id'] ) ) {
				$context['error_code'] = is_string( $code ) ? $code : 'unknown';
				datamachine_log_routine_outcome( 'error', 'Scheduled routine dispatch failed', $context, $context['routine_id'] );
				return;
			}

			datamachine_log_routine_outcome(
				'error',
				'Scheduled routine dispatch failed',
				array( 'error_code' => is_string( $code ) ? $code : 'unknown' )
			);
		},
		10,
		2
	);

	/**
	 * Bridge: datamachine_run_flow_now → datamachine/run-flow ability.
	 *
	 * Still a live execution path for one-off wakes: queue backpressure
	 * deferrals and stuck-job recovery re-runs schedule this hook with
	 * positional args `[ flow_id ]` / `[ flow_id, job_id ]`. Recurring
	 * schedules moved to Agents API Routines; a legacy recurring action
	 * (identified by the generation marker at args[2]) that is still pending
	 * after migration is ignored and logged so it cannot double-fire beside
	 * its routine. TODO(3458): drop the legacy branch after one release.
	 */
	add_action(
		'datamachine_run_flow_now',
		static function ( $flow_id, $job_id = null, $schedule_generation = null ): void {
			if ( null !== $schedule_generation ) {
				do_action(
					'datamachine_log',
					'warning',
					'Legacy recurring schedule action ignored after routines migration',
					array( 'flow_id' => (int) $flow_id )
				);
				return;
			}
			datamachine_dispatch_run_flow( $flow_id, $job_id );
		},
		10,
		3
	);

	/**
	 * Bridge: datamachine_run_flow_once → datamachine/run-flow ability.
	 *
	 * One-time (timestamp) flow runs are plain Action Scheduler single
	 * actions — routines are recurring by definition.
	 */
	add_action(
		'datamachine_run_flow_once',
		'datamachine_dispatch_run_flow',
		10,
		1
	);

	/**
	 * Bridge: datamachine_execute_step → datamachine/execute-step ability.
	 */
	add_action(
		'datamachine_execute_step',
		'datamachine_execute_step_action',
		10,
		7
	);

	/** Dedicated resume bridge avoids collision with the running execute action. */
	add_action(
		'datamachine_resume_ai_step',
		'datamachine_resume_ai_step_action',
		10,
		5
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
