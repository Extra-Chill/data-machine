<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Drain Job Ability
 *
 * Drains due Action Scheduler work for one Data Machine job inside the
 * current request. Intended for one-shot CI runtimes where cron will not tick.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.104.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\ActionScheduler\ScopedDrainService;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class DrainJobAbility {

	use EngineHelpers;

	private const DEFAULT_STEP_BUDGET = 50;

	private const DEFAULT_TIME_BUDGET_MS = 300000;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/drain-job ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/drain-job',
				array(
					'label'               => __( 'Drain Job', 'data-machine' ),
					'description'         => __( 'Synchronously drain due Action Scheduler work for one Data Machine job until terminal or budgeted.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id' ),
						'properties' => array(
							'job_id'         => array(
								'type'        => 'integer',
								'description' => __( 'Job ID to drain.', 'data-machine' ),
							),
							'step_budget'    => array(
								'type'        => 'integer',
								'default'     => self::DEFAULT_STEP_BUDGET,
								'description' => __( 'Maximum Action Scheduler actions to execute before stopping.', 'data-machine' ),
							),
							'time_budget_ms' => array(
								'type'        => 'integer',
								'default'     => self::DEFAULT_TIME_BUDGET_MS,
								'description' => __( 'Maximum wall-clock milliseconds to drain before stopping.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'job_id'            => array( 'type' => 'integer' ),
							'terminal_state'    => array( 'type' => array( 'string', 'null' ) ),
							'steps_run'         => array( 'type' => 'integer' ),
							'actions_drained'   => array( 'type' => 'integer' ),
							'wall_time_ms'      => array( 'type' => 'integer' ),
							'remaining_actions' => array( 'type' => 'integer' ),
							'budget_exhausted'  => array( 'type' => 'boolean' ),
							'last_error'        => array( 'type' => array( 'string', 'null' ) ),
							'error'             => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute the drain-job ability.
	 *
	 * @param array $input Input with job_id and optional budgets.
	 * @return array Result with terminal status and drain stats.
	 */
	public function execute( array $input ): array {
		$job_id         = (int) ( $input['job_id'] ?? 0 );
		$step_budget    = max( 1, (int) ( $input['step_budget'] ?? self::DEFAULT_STEP_BUDGET ) );
		$time_budget_ms = max( 1, (int) ( $input['time_budget_ms'] ?? self::DEFAULT_TIME_BUDGET_MS ) );
		$started_at     = microtime( true );
		$last_error     = null;

		if ( $job_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'A valid job_id is required.',
			);
		}

		$job = $this->db_jobs->get_job( $job_id );
		if ( ! $job ) {
			return array(
				'success' => false,
				'job_id'  => $job_id,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$guard_error = $this->getActionSchedulerGuardError();
		if ( null !== $guard_error ) {
			return array(
				'success'    => false,
				'job_id'     => $job_id,
				'error'      => $guard_error,
				'error_type' => 'action_scheduler_unavailable',
			);
		}

		$terminal_status = static function () use ( $job_id ): string {
			$job    = ( new Jobs() )->get_job( $job_id );
			$status = (string) ( $job['status'] ?? '' );

			return JobStatus::isStatusFinal( $status ) ? $status : '';
		};

		$drain_stats = ( new ScopedDrainService() )->drain(
			array(
				'limit'                    => $step_budget,
				'batch_size'               => 1,
				'time_limit_ms'            => $time_budget_ms,
				'job_ids'                  => array( $job_id ),
				'hooks'                    => array(
					ScopedDrainService::HOOK_EXECUTE_STEP,
					ScopedDrainService::HOOK_BATCH_CHUNK,
				),
				'execution_context'        => 'Data Machine drain-job ability',
				'terminal_status_callback' => $terminal_status,
				'warning_callback'         => static function ( string $message ) use ( &$last_error ): void {
					$last_error = $message;
				},
			)
		);

		$terminal_state = (string) ( $drain_stats['terminal_state'] ?? '' );
		if ( '' === $terminal_state ) {
			$job            = $this->db_jobs->get_job( $job_id );
			$status         = (string) ( $job['status'] ?? '' );
			$terminal_state = JobStatus::isStatusFinal( $status ) ? $status : '';
		}

		$actions_drained   = (int) ( $drain_stats['actions_processed'] ?? 0 );
		$remaining_actions = (int) ( $drain_stats['remaining_pending'] ?? 0 );
		$wall_time_ms      = $this->elapsedMs( $started_at );
		$budget_exhausted  = '' === $terminal_state && in_array( (string) ( $drain_stats['stop_reason'] ?? '' ), array( 'limit', 'time_limit', 'timeout_margin' ), true );

		return array(
			'success'           => '' !== $terminal_state,
			'job_id'            => $job_id,
			'terminal_state'    => '' === $terminal_state ? null : $terminal_state,
			'steps_run'         => $actions_drained,
			'actions_drained'   => $actions_drained,
			'wall_time_ms'      => $wall_time_ms,
			'remaining_actions' => $remaining_actions,
			'budget_exhausted'  => $budget_exhausted,
			'last_error'        => $last_error,
		);
	}

	/**
	 * Return a typed guard error when Action Scheduler cannot be drained.
	 */
	private function getActionSchedulerGuardError(): ?string {
		if ( ! class_exists( '\ActionScheduler' ) || ! method_exists( '\ActionScheduler', 'runner' ) ) {
			return 'Action Scheduler queue runner is not available.';
		}

		$runner = \ActionScheduler::runner();
		if ( ! is_object( $runner ) || ! method_exists( $runner, 'process_action' ) ) {
			return 'Action Scheduler action processor is not available.';
		}

		return null;
	}

	/**
	 * Return elapsed wall-clock milliseconds.
	 */
	private function elapsedMs( float $started_at ): int {
		return (int) round( ( microtime( true ) - $started_at ) * 1000 );
	}
}
