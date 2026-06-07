<?php
/**
 * Bounded synchronous flow runner for CLI exploration.
 *
 * @package DataMachine\Engine\Debug
 */

namespace DataMachine\Engine\Debug;

use DataMachine\Abilities\Engine\EngineHelpers;
use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Abilities\Engine\ScheduleNextStepAbility;
use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\JobStatus;
use DataMachine\Core\StepExecutionResult;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\Step;
use DataMachine\Engine\StepNavigator;

defined( 'ABSPATH' ) || exit;

/**
 * Executes a flow inline with hard bounds for local debugging.
 */
class SyncRunner {

	use EngineHelpers;

	/**
	 * Captured step schedules from the run-flow bootstrap.
	 *
	 * @var array<int, array{job_id:int,flow_step_id:string,data_packets:array}>
	 */
	private array $captured_schedules = array();

	public function __construct() {
		$this->initDatabases();
	}

	/**
	 * Run a flow synchronously with bounded execution.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param array $options Runner options.
	 * @return array Diagnostics packet.
	 */
	public function runFlow( int $flow_id, array $options = array() ): array {
		$options      = $this->normalizeOptions( $options );
		$started_at   = microtime( true );
		$started_time = gmdate( 'c' );

		$packet = array(
			'success'        => false,
			'mode'           => 'sync_debug',
			'flow_id'        => $flow_id,
			'job_id'         => null,
			'stopped_reason' => null,
			'bounds'         => array(
				'max_steps'       => $options['max_steps'],
				'max_items'       => $options['max_items'],
				'timeout_seconds' => $options['timeout_seconds'],
			),
			'counts'         => array(
				'steps_executed' => 0,
				'packets_seen'   => 0,
			),
			'steps'          => array(),
			'errors'         => array(),
			'started_at'     => $started_time,
			'completed_at'   => null,
			'duration_ms'    => null,
		);

		$initial_packets  = $options['input_packets'];
		$initial_data     = empty( $initial_packets ) ? array() : array( 'sync_runner_input_packets' => $initial_packets );
		$run_result       = $this->runFlowBootstrap( $flow_id, $initial_data );
		$job_id           = (int) ( $run_result['job_id'] ?? 0 );
		$packet['job_id'] = $job_id > 0 ? $job_id : null;

		if ( empty( $run_result['success'] ) ) {
			$packet['errors'][]       = $run_result['error'] ?? 'Flow bootstrap failed.';
			$packet['stopped_reason'] = $run_result['reason'] ?? 'bootstrap_failed';
			return $this->finishPacket( $packet, $started_at );
		}

		if ( ! empty( $run_result['skipped'] ) ) {
			$packet['success']        = true;
			$packet['stopped_reason'] = $run_result['reason'] ?? 'skipped';
			return $this->finishPacket( $packet, $started_at );
		}

		$current_step_id = (string) ( $this->captured_schedules[0]['flow_step_id'] ?? ( $run_result['first_step'] ?? '' ) );
		$data_packets    = ! empty( $initial_packets ) ? $initial_packets : array();

		while ( '' !== $current_step_id ) {
			if ( $this->timedOut( $started_at, $options['timeout_seconds'] ) ) {
				$packet['stopped_reason'] = 'timeout';
				$this->markBoundedStop( $job_id, 'sync_runner_timeout' );
				break;
			}

			if ( $packet['counts']['steps_executed'] >= $options['max_steps'] ) {
				$packet['stopped_reason'] = 'max_steps';
				$this->markBoundedStop( $job_id, 'sync_runner_max_steps' );
				break;
			}

			$step_result       = $this->executeStepInline( $job_id, $current_step_id, $data_packets, $options );
			$packet['steps'][] = $step_result['diagnostics'];
			++$packet['counts']['steps_executed'];
			$packet['counts']['packets_seen'] += $step_result['output_count'];

			if ( ! empty( $step_result['error'] ) ) {
				$packet['errors'][]       = $step_result['error'];
				$packet['stopped_reason'] = 'error';
				$this->db_jobs->complete_job( $job_id, JobStatus::FAILED . ' - sync_runner_error' );
				break;
			}

			if ( null !== $step_result['stopped_reason'] ) {
				$packet['success']        = true;
				$packet['stopped_reason'] = $step_result['stopped_reason'];
				break;
			}

			$current_step_id = (string) ( $step_result['next_step_id'] ?? '' );
			$data_packets    = $step_result['data_packets'];
		}

		if ( null === $packet['stopped_reason'] ) {
			$packet['success']        = true;
			$packet['stopped_reason'] = 'completed';
		}

		return $this->finishPacket( $packet, $started_at );
	}

	/**
	 * Normalize runner bounds.
	 */
	private function normalizeOptions( array $options ): array {
		return array(
			'max_steps'       => max( 1, min( 100, (int) ( $options['max_steps'] ?? 20 ) ) ),
			'max_items'       => max( 1, min( 1000, (int) ( $options['max_items'] ?? 50 ) ) ),
			'timeout_seconds' => max( 1, min( 900, (int) ( $options['timeout_seconds'] ?? 60 ) ) ),
			'show_packets'    => true === ( $options['show_packets'] ?? false ),
			'input_packets'   => is_array( $options['input_packets'] ?? null ) ? array_values( $options['input_packets'] ) : array(),
		);
	}

	/**
	 * Run normal flow bootstrap while capturing the initial scheduled step.
	 */
	private function runFlowBootstrap( int $flow_id, array $initial_data ): array {
		$this->captured_schedules = array();

		\remove_all_actions( 'datamachine_schedule_next_step' );
		\add_action(
			'datamachine_schedule_next_step',
			function ( $job_id, $flow_step_id, $data_packets = array() ): void {
				$this->captured_schedules[] = array(
					'job_id'       => (int) $job_id,
					'flow_step_id' => (string) $flow_step_id,
					'data_packets' => is_array( $data_packets ) ? $data_packets : array(),
				);
			},
			10,
			3
		);

		try {
			$result = ( new RunFlowAbility() )->execute(
				array(
					'flow_id'      => $flow_id,
					'initial_data' => $initial_data,
				)
			);
		} finally {
			\remove_all_actions( 'datamachine_schedule_next_step' );
			$this->restoreProductionScheduleNextStepHook();
		}

		return is_array( $result ?? null ) ? $result : array(
			'success' => false,
			'error'   => 'Flow bootstrap returned no result.',
		);
	}

	/**
	 * Restore the production schedule bridge after temporary CLI capture.
	 */
	private function restoreProductionScheduleNextStepHook(): void {
		\add_action(
			'datamachine_schedule_next_step',
			static function ( $job_id, $flow_step_id, $data_packets = array() ): void {
				( new ScheduleNextStepAbility() )->execute(
					array(
						'job_id'       => (int) $job_id,
						'flow_step_id' => (string) $flow_step_id,
						'data_packets' => is_array( $data_packets ) ? $data_packets : array(),
					)
				);
			},
			10,
			3
		);
	}

	/**
	 * Execute one flow step inline.
	 */
	private function executeStepInline( int $job_id, string $flow_step_id, array $data_packets, array $options ): array {
		$engine_snapshot  = \datamachine_get_engine_data( $job_id );
		$engine           = new EngineData( $engine_snapshot, $job_id );
		$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

		if ( ! is_array( $flow_step_config ) || empty( $flow_step_config['step_type'] ) ) {
			return $this->stepFailure( $flow_step_id, 'missing_step_type_in_flow_step_config' );
		}

		$step_type       = (string) $flow_step_config['step_type'];
		$step_definition = ( new StepTypeAbilities() )->getStepType( $step_type );
		if ( ! is_array( $step_definition ) || empty( $step_definition['class'] ) ) {
			return $this->stepFailure( $flow_step_id, sprintf( 'Step type "%s" not found in registry.', $step_type ) );
		}

		$step_class = (string) $step_definition['class'];
		$flow_step  = new $step_class();
		if ( ! $flow_step instanceof Step ) {
			return $this->stepFailure( $flow_step_id, sprintf( 'Step class "%s" must extend DataMachine\\Core\\Steps\\Step.', $step_class ) );
		}

		$input_count = count( $data_packets );
		$started_at  = microtime( true );

		try {
			$output_packets = $flow_step->execute(
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'data'         => $data_packets,
					'engine'       => $engine,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->stepFailure( $flow_step_id, $e->getMessage(), $step_type, $step_class, $input_count );
		}

		$execution_result = StepExecutionResult::fromStepOutput( $output_packets, $step_type );
		$output_packets   = $execution_result['packets'];
		$truncated        = false;
		if ( count( $output_packets ) > $options['max_items'] ) {
			$output_packets = array_slice( $output_packets, 0, $options['max_items'] );
			$truncated      = true;
		}

		$output_count = count( $output_packets );
		$next_step_id = ( new StepNavigator() )->get_next_flow_step_id( $flow_step_id, array( 'job_id' => $job_id ) );
		$reason       = null;

		if ( $truncated ) {
			$reason = 'max_items';
			$this->markBoundedStop( $job_id, 'sync_runner_max_items' );
		} elseif ( 'completed_no_items' === $execution_result['status'] ) {
			$reason = 'completed_no_items';
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED_NO_ITEMS );
		} elseif ( ! $execution_result['success'] ) {
			return $this->stepFailure( $flow_step_id, $execution_result['reason'], $step_type, $step_class, $input_count, $output_count );
		} elseif ( null === $next_step_id ) {
			$reason = 'completed';
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
		}

		$diagnostics = array(
			'flow_step_id'    => $flow_step_id,
			'step_type'       => $step_type,
			'step_class'      => $step_class,
			'handler_slugs'   => FlowStepConfig::getConfiguredHandlerSlugs( $flow_step_config ),
			'handler_configs' => FlowStepConfig::getHandlerConfigs( $flow_step_config ),
			'input_count'     => $input_count,
			'output_count'    => $output_count,
			'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'next_step_id'    => $next_step_id,
		);

		if ( $options['show_packets'] ) {
			$diagnostics['packets'] = $output_packets;
		} else {
			$diagnostics['packet_summaries'] = $this->summarizePackets( $output_packets );
		}

		return array(
			'diagnostics'    => $diagnostics,
			'data_packets'   => $output_packets,
			'output_count'   => $output_count,
			'next_step_id'   => $next_step_id,
			'stopped_reason' => $reason,
			'error'          => null,
		);
	}

	/**
	 * Build a consistent failed step result.
	 */
	private function stepFailure( string $flow_step_id, string $error, string $step_type = '', string $step_class = '', int $input_count = 0, int $output_count = 0 ): array {
		return array(
			'diagnostics'    => array(
				'flow_step_id' => $flow_step_id,
				'step_type'    => $step_type,
				'step_class'   => $step_class,
				'input_count'  => $input_count,
				'output_count' => $output_count,
				'error'        => $error,
			),
			'data_packets'   => array(),
			'output_count'   => 0,
			'next_step_id'   => null,
			'stopped_reason' => 'error',
			'error'          => $error,
		);
	}

	/**
	 * Summarize packets without dumping full payloads.
	 */
	private function summarizePackets( array $packets ): array {
		$summaries = array();
		foreach ( $packets as $index => $packet ) {
			$metadata    = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$summaries[] = array(
				'index'           => $index,
				'type'            => (string) ( $packet['type'] ?? ( $metadata['type'] ?? 'unknown' ) ),
				'source_type'     => $metadata['source_type'] ?? null,
				'item_identifier' => $metadata['item_identifier'] ?? null,
				'success'         => $metadata['success'] ?? null,
				'keys'            => is_array( $packet ) ? array_keys( $packet ) : array(),
			);
		}

		return $summaries;
	}

	/**
	 * Mark a non-terminal bounded stop on the debug job.
	 */
	private function markBoundedStop( int $job_id, string $status ): void {
		if ( $job_id > 0 ) {
			$this->db_jobs->update_job_status( $job_id, $status );
		}
	}

	/**
	 * Check timeout against a monotonic-ish wall clock for CLI bounds.
	 */
	private function timedOut( float $started_at, int $timeout_seconds ): bool {
		return ( microtime( true ) - $started_at ) >= $timeout_seconds;
	}

	/**
	 * Complete packet timing metadata.
	 */
	private function finishPacket( array $packet, float $started_at ): array {
		$packet['completed_at'] = gmdate( 'c' );
		$packet['duration_ms']  = (int) round( ( microtime( true ) - $started_at ) * 1000 );
		return $packet;
	}
}
