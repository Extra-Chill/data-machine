<?php
/**
 * Retry Job Ability
 *
 * Retries a failed or stuck job by marking it failed and optionally requeuing its prompt.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.18.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class RetryJobAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/retry-job ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/retry-job',
				array(
					'label'               => __( 'Retry Job', 'data-machine' ),
					'description'         => __( 'Retry a failed or stuck job by marking it failed and optionally requeuing its prompt.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'job_id' => array(
								'type'        => 'integer',
								'description' => __( 'The job ID to retry.', 'data-machine' ),
							),
							'force'  => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Allow retrying any status, not just failed/processing.', 'data-machine' ),
							),
						),
						'required'   => array( 'job_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'job_id'          => array( 'type' => 'integer' ),
							'previous_status' => array( 'type' => 'string' ),
							'prompt_requeued' => array( 'type' => 'boolean' ),
							'message'         => array( 'type' => 'string' ),
							'error'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
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
	 * Execute retry-job ability.
	 *
	 * Marks the job as failed and requeues its prompt if a backup exists in engine_data.
	 *
	 * @param array $input Input parameters with job_id and optional force.
	 * @return array Result with job_id, previous_status, and prompt_requeued flag.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['job_id'] ) || ! is_numeric( $input['job_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'job_id is required and must be a positive integer.',
			);
		}

		$job_id = (int) $input['job_id'];
		$force  = ! empty( $input['force'] );

		$job = $this->db_jobs->get_job( $job_id );

		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$previous_status = $job['status'] ?? '';

		// Unless forced, only allow retrying failed or processing jobs.
		if ( ! $force && ! str_starts_with( $previous_status, 'failed' ) && 'processing' !== $previous_status ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d has status "%s" — use --force to retry non-failed jobs.', $job_id, $previous_status ),
			);
		}

		// Retrieve engine data for prompt backup before marking failed.
		$engine_data = $this->db_jobs->retrieve_engine_data( $job_id );

		// Mark as failed for retry.
		$this->db_jobs->complete_job( $job_id, 'failed - manual_retry' );

		do_action( 'datamachine_job_complete', $job_id, 'failed' );

		// Check for queued_prompt_backup and requeue if found. The
		// `slot` field on the backup tells us which queue it came from
		// (prompt_queue for AI, config_patch_queue for Fetch). Pre-#1292
		// backups have no `slot` field; treat them as prompt_queue for
		// backward compat — those jobs predate the split.
		$prompt_requeued = false;
		$job_flow_id     = (int) ( $job['flow_id'] ?? 0 );
		$backup          = $engine_data['queued_prompt_backup'] ?? array();

		if ( ! empty( $backup ) && isset( $backup['flow_step_id'] ) ) {
			$slot = $backup['slot'] ?? \DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE;

			$flow = $this->db_flows->get_flow( $job_flow_id );

			if ( $flow && isset( $flow['flow_config'] ) ) {
				$flow_config = $flow['flow_config'];
				$step_id     = $backup['flow_step_id'];

				if ( isset( $flow_config[ $step_id ] ) ) {
					$entry = null;

					if ( \DataMachine\Abilities\Flow\QueueAbility::SLOT_CONFIG_PATCH_QUEUE === $slot && isset( $backup['patch'] ) && is_array( $backup['patch'] ) ) {
						$entry = array(
							'patch'    => $backup['patch'],
							'added_at' => gmdate( 'c' ),
						);
					} elseif ( isset( $backup['prompt'] ) ) {
						$entry = array(
							'prompt'   => $backup['prompt'],
							'added_at' => gmdate( 'c' ),
						);
						$slot  = \DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE;
					}

					if ( null !== $entry ) {
						if ( ! isset( $flow_config[ $step_id ][ $slot ] ) || ! is_array( $flow_config[ $step_id ][ $slot ] ) ) {
							$flow_config[ $step_id ][ $slot ] = array();
						}
						$flow_config[ $step_id ][ $slot ][] = $entry;

						$update_result = $this->db_flows->update_flow( $job_flow_id, array( 'flow_config' => $flow_config ) );

						if ( $update_result ) {
							$prompt_requeued = true;
						}
					}
				}
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Job retried via ability',
			array(
				'job_id'          => $job_id,
				'previous_status' => $previous_status,
				'prompt_requeued' => $prompt_requeued,
			)
		);

		$message = $prompt_requeued
			? sprintf( 'Job %d marked as failed and prompt requeued.', $job_id )
			: sprintf( 'Job %d marked as failed (no prompt backup to requeue).', $job_id );

		return array(
			'success'         => true,
			'job_id'          => $job_id,
			'previous_status' => $previous_status,
			'prompt_requeued' => $prompt_requeued,
			'message'         => $message,
		);
	}
}
