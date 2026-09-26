<?php
/**
 * Schedule Next Step Ability
 *
 * Stores data packets in the file repository and schedules the next
 * step for execution via Action Scheduler.
 *
 * Backs the datamachine_schedule_next_step action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Core\EngineData;
use DataMachine\Core\FilesRepository\FileStorage;

defined( 'ABSPATH' ) || exit;

class ScheduleNextStepAbility {

	use EngineHelpers;

	public function __construct( bool $register = true ) {
		$this->initDatabases();

		if ( $register ) {
			$this->registerAbility();
		}
	}

	/**
	 * Register the datamachine/schedule-next-step ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/schedule-next-step',
				array(
					'label'               => __( 'Schedule Next Step', 'data-machine' ),
					'description'         => __( 'Store data packets and schedule the next pipeline step via Action Scheduler.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id', 'flow_step_id' ),
						'properties' => array(
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for the execution.', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to schedule.', 'data-machine' ),
							),
							'data_packets' => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Data packets to pass to the next step.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'action_id' => array( 'type' => 'integer' ),
							'error'     => array( 'type' => 'string' ),
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
	 * Execute the schedule-next-step ability.
	 *
	 * @param array $input Input with job_id, flow_step_id, and optional data_packets.
	 * @return array Result with success status and action_id.
	 */
	public function execute( array $input ): array|\WP_Error {
		$job_id       = (int) ( $input['job_id'] ?? 0 );
		$flow_step_id = $input['flow_step_id'] ?? '';
		$dataPackets  = $input['data_packets'] ?? array();
		if ( $job_id <= 0 || '' === (string) $flow_step_id ) {
			return new \WP_Error( 'invalid_next_step_input', 'job_id and flow_step_id are required.', array( 'status' => 400 ) );
		}

		// Store data by job_id (if present).
		if ( ! empty( $dataPackets ) ) {
			$engine_snapshot  = datamachine_get_engine_data( $job_id );
			$engine           = new EngineData( $engine_snapshot, $job_id );
			$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

			$raw_flow_id = $flow_step_config['flow_id'] ?? ( $engine->getJobContext()['flow_id'] ?? null );

			// Direct workflows do not have a numeric flow file context, so keep
			// step input packets on engine data for execute-step to reload.
			if ( 'direct' === $raw_flow_id ) {
				$direct_step_data_packets                  = is_array( $engine_snapshot['direct_step_data_packets'] ?? null ) ? $engine_snapshot['direct_step_data_packets'] : array();
				$direct_step_data_packets[ $flow_step_id ] = $dataPackets;
				datamachine_merge_engine_data(
					$job_id,
					array( 'direct_step_data_packets' => $direct_step_data_packets )
				);
			} elseif ( null !== $raw_flow_id ) {
				$flow_id = (int) $raw_flow_id;

				if ( $flow_id <= 0 ) {
					do_action(
						'datamachine_log',
						'error',
						'Flow ID missing during data storage',
						array(
							'job_id'       => $job_id,
							'flow_step_id' => $flow_step_id,
						)
					);
					$this->failScheduling(
						$job_id,
						$flow_step_id,
						'missing_flow_id_during_data_storage',
						array( 'packet_count' => count( $dataPackets ) )
					);
					return new \WP_Error(
						'missing_flow_id',
						'Flow ID missing during data storage.',
						array(
							'status'       => 500,
							'retryable'    => true,
							'job_id'       => $job_id,
							'flow_step_id' => $flow_step_id,
						)
					);
				}

				$context = datamachine_get_file_context( $flow_id );

				$storage = new FileStorage();
				$result  = $storage->store_data_packet( $dataPackets, $job_id, $context );

				if ( false === $result ) {
					do_action(
						'datamachine_log',
						'error',
						'Failed to persist data packets to filesystem — step will have no input data',
						array(
							'job_id'       => $job_id,
							'flow_step_id' => $flow_step_id,
							'flow_id'      => $flow_id,
							'packet_count' => count( $dataPackets ),
						)
					);
				}
			}
		}

		$schedule_exception = null;
		try {
			$action_id = $this->scheduleAction( $job_id, $flow_step_id );
		} catch ( \Throwable $error ) {
			$action_id          = 0;
			$schedule_exception = $error;
		}

		if ( ! empty( $dataPackets ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Next step scheduled via Action Scheduler',
				array(
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'action_id'    => $action_id,
					'success'      => is_numeric( $action_id ) && (int) $action_id > 0,
				)
			);
		}

		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			$this->failScheduling(
				$job_id,
				$flow_step_id,
				null !== $schedule_exception ? 'next_step_schedule_exception' : 'next_step_schedule_failed',
				array_filter(
					array(
						'packet_count'      => count( $dataPackets ),
						'exception_class'   => null !== $schedule_exception ? get_class( $schedule_exception ) : null,
						'exception_message' => null !== $schedule_exception ? $schedule_exception->getMessage() : null,
					),
					static fn ( $value ): bool => null !== $value
				)
			);
		}

		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			return new \WP_Error(
				null !== $schedule_exception ? 'next_step_schedule_exception' : 'next_step_schedule_failed',
				null !== $schedule_exception ? $schedule_exception->getMessage() : 'Unable to schedule the next workflow step.',
				array(
					'status'       => 503,
					'retryable'    => true,
					'job_id'       => $job_id,
					'flow_step_id' => $flow_step_id,
					'ownership'    => 'scheduler',
					'diagnostics'  => array( 'packet_count' => count( $dataPackets ) ),
				)
			);
		}

		return array(
			'success'   => is_numeric( $action_id ) && (int) $action_id > 0,
			'action_id' => $action_id,
		);
	}

	/**
	 * Insert one execute-step action and return its durable identifier.
	 *
	 * This intentionally performs no packet persistence so callers that own a
	 * database transaction can include the Action Scheduler row in that boundary.
	 */
	public function scheduleAction( int $job_id, string $flow_step_id ): int {
		return (int) as_schedule_single_action(
			time(),
			'datamachine_execute_step',
			$this->actionArgs( $job_id, $flow_step_id ),
			'data-machine'
		);
	}

	/**
	 * Insert and verify an action on the current wpdb transaction.
	 *
	 * The public scheduling helpers permit filters to return existing or foreign
	 * IDs. Saving the exact action through the canonical DB store gives this
	 * caller provenance over the newly inserted row and keeps it in the wpdb
	 * transaction that owns the webhook handoff.
	 */
	public function scheduleActionAtomically( int $job_id, string $flow_step_id ): int {
		global $wpdb;

		$store = \ActionScheduler::store();
		if ( \ActionScheduler_DBStore::class !== get_class( $store ) ) {
			throw new \RuntimeException( 'Action Scheduler DB store is required for an atomic handoff.' );
		}

		$actions_table = ! empty( $wpdb->actionscheduler_actions ) ? $wpdb->actionscheduler_actions : $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The high-water mark proves the returned auto-increment ID did not predate this insertion attempt.
		$previous_max_id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(action_id), 0) FROM %i', $actions_table )
		);
		if ( null === $previous_max_id ) {
			throw new \RuntimeException( 'Unable to establish Action Scheduler insertion provenance.' );
		}

		$action_args = $this->actionArgs( $job_id, $flow_step_id );
		$action      = new \ActionScheduler_Action(
			'datamachine_execute_step',
			$action_args,
			new \ActionScheduler_SimpleSchedule( as_get_datetime_object( time() ) ),
			'data-machine'
		);
		$action_id   = $this->saveAtomicAction( $store, $action );
		if ( $action_id <= 0
			|| $action_id <= (int) $previous_max_id
			|| \ActionScheduler_Store::STATUS_PENDING !== $store->get_status( $action_id )
		) {
			throw new \RuntimeException( 'Action Scheduler did not durably insert the next-step action.' );
		}

		$stored_action = $store->fetch_action( $action_id );
		if ( 'datamachine_execute_step' !== $stored_action->get_hook()
			|| 'data-machine' !== $stored_action->get_group()
			|| $action->get_args() !== $stored_action->get_args()
		) {
			throw new \RuntimeException( 'Action Scheduler inserted an unverifiable next-step action.' );
		}

		return $action_id;
	}

	/** Save through the verified DB store. Kept separate so returned-ID rejection can be tested. */
	protected function saveAtomicAction( \ActionScheduler_DBStore $store, \ActionScheduler_Action $action ): int {
		return (int) $store->save_action( $action );
	}

	/** Build the canonical execute-step arguments for both scheduling paths. */
	private function actionArgs( int $job_id, string $flow_step_id ): array {
		$action_args = array(
			'job_id'       => $job_id,
			'flow_step_id' => $flow_step_id,
		);
		$job         = $this->db_jobs->get_job( $job_id );
		if ( 'direct' === (string) ( $job['flow_id'] ?? '' ) && (int) ( $job['operation_generation'] ?? 0 ) > 0 ) {
			$action_args['operation_generation']  = (int) $job['operation_generation'];
			$action_args['operation_claim_token'] = (string) ( $job['operation_claim_token'] ?? '' );
		}

		return $action_args;
	}

	/**
	 * Route next-step scheduling failures through the normal job failure policy.
	 *
	 * The caller reaches this ability through do_action(), so a false return value
	 * is not observable by ExecuteStepAbility. Failing here preserves the liveness
	 * invariant: a job that cannot schedule its required next step is either
	 * requeued by JobRetryPolicy or completed as failed, never left processing
	 * without scheduler ownership.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $flow_step_id Next flow step that could not be scheduled.
	 * @param string $reason       Failure reason.
	 * @param array  $context      Additional failure context.
	 */
	private function failScheduling( int $job_id, string $flow_step_id, string $reason, array $context = array() ): void {
		if ( $job_id <= 0 || '' === $flow_step_id ) {
			return;
		}

		do_action(
			'datamachine_fail_job',
			$job_id,
			'step_execution_failure',
			array_merge(
				array(
					'flow_step_id'      => $flow_step_id,
					'next_flow_step_id' => $flow_step_id,
					'reason'            => $reason,
					'retryable'         => true,
				),
				$context
			)
		);
	}
}
