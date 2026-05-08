<?php
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

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class DrainJobAbility {

	use EngineHelpers;

	private const GROUP = 'data-machine';

	private const HOOK_EXECUTE_STEP = 'datamachine_execute_step';

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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the drain-job ability.
	 *
	 * @param array $input Input with job_id and optional budgets.
	 * @return array Result with terminal status and drain stats.
	 */
	public function execute( array $input ): array {
		$job_id          = (int) ( $input['job_id'] ?? 0 );
		$step_budget     = max( 1, (int) ( $input['step_budget'] ?? self::DEFAULT_STEP_BUDGET ) );
		$time_budget_ms  = max( 1, (int) ( $input['time_budget_ms'] ?? self::DEFAULT_TIME_BUDGET_MS ) );
		$started_at      = microtime( true );
		$actions_drained = 0;
		$last_error      = null;

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

		while ( true ) {
			$job    = $this->db_jobs->get_job( $job_id );
			$status = (string) ( $job['status'] ?? '' );
			if ( JobStatus::isStatusFinal( $status ) ) {
				break;
			}

			if ( $actions_drained >= $step_budget || $this->elapsedMs( $started_at ) >= $time_budget_ms ) {
				break;
			}

			$action_id = $this->getNextDuePendingActionId( $job_id );
			if ( ! $action_id ) {
				break;
			}

			try {
				\ActionScheduler::runner()->process_action( $action_id, 'Data Machine drain-job ability' );
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
			}

			++$actions_drained;
		}

		$job               = $this->db_jobs->get_job( $job_id );
		$status            = (string) ( $job['status'] ?? '' );
		$terminal_state    = JobStatus::isStatusFinal( $status ) ? $status : null;
		$remaining_actions = $this->countDuePendingActions( $job_id );
		$wall_time_ms      = $this->elapsedMs( $started_at );
		$budget_exhausted  = null === $terminal_state && ( $actions_drained >= $step_budget || $wall_time_ms >= $time_budget_ms );

		return array(
			'success'           => null !== $terminal_state,
			'job_id'            => $job_id,
			'terminal_state'    => $terminal_state,
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
	 * Get the next due pending Data Machine step action for a single job.
	 */
	private function getNextDuePendingActionId( int $job_id ): int {
		$ids = $this->getDuePendingActionIds( $job_id );

		return $ids[0] ?? 0;
	}

	/**
	 * Count due pending Data Machine step actions for a single job.
	 */
	private function countDuePendingActions( int $job_id ): int {
		return count( $this->getDuePendingActionIds( $job_id ) );
	}

	/**
	 * Query due pending actions and filter by decoded job_id args.
	 *
	 * @return int[] Action IDs.
	 */
	private function getDuePendingActionIds( int $job_id ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated from the WP prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.action_id, a.args
				 FROM {$actions_table} a
				 INNER JOIN {$groups_table} g ON g.group_id = a.group_id
				 WHERE a.hook = %s
				 AND a.status = 'pending'
				 AND g.slug = %s
				 AND a.scheduled_date_gmt <= %s
				 AND a.args LIKE %s
				 ORDER BY a.scheduled_date_gmt ASC, a.action_id ASC",
				self::HOOK_EXECUTE_STEP,
				self::GROUP,
				gmdate( 'Y-m-d H:i:s' ),
				'%"job_id"%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = array();
		foreach ( $rows as $row ) {
			if ( $this->extractActionJobId( (string) $row->args ) !== $job_id ) {
				continue;
			}

			$ids[] = (int) $row->action_id;
		}

		return $ids;
	}

	/**
	 * Extract job_id from Action Scheduler's JSON-encoded args column.
	 */
	private function extractActionJobId( string $args_json ): int {
		$args = json_decode( $args_json, true );
		if ( ! is_array( $args ) ) {
			return 0;
		}

		if ( isset( $args['job_id'] ) ) {
			return (int) $args['job_id'];
		}

		foreach ( $args as $value ) {
			if ( is_array( $value ) && isset( $value['job_id'] ) ) {
				return (int) $value['job_id'];
			}
		}

		return 0;
	}

	/**
	 * Return elapsed wall-clock milliseconds.
	 */
	private function elapsedMs( float $started_at ): int {
		return (int) round( ( microtime( true ) - $started_at ) * 1000 );
	}
}
