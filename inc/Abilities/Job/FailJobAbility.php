<?php
/**
 * Fail Job Ability
 *
 * Manually fails a processing job with an optional reason.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.18.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class FailJobAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/fail-job ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/fail-job',
				array(
					'label'               => __( 'Fail Job', 'data-machine' ),
					'description'         => __( 'Manually fail a processing job with an optional reason.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'job_id' => array(
								'type'        => 'integer',
								'description' => __( 'The job ID to fail.', 'data-machine' ),
							),
							'reason' => array(
								'type'        => 'string',
								'default'     => 'manual',
								'description' => __( 'Reason for failure.', 'data-machine' ),
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
							'new_status'      => array( 'type' => 'string' ),
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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute fail-job ability.
	 *
	 * Marks a processing job as failed with the given reason.
	 *
	 * @param array $input Input parameters with job_id and optional reason.
	 * @return array Result with job_id, previous_status, and new_status.
	 */
	public function execute( array $input ): array {
		if ( empty( $input['job_id'] ) || ! is_numeric( $input['job_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'job_id is required and must be a positive integer.',
			);
		}

		$job_id = (int) $input['job_id'];
		$reason = ! empty( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : 'manual';

		$job = $this->db_jobs->get_job( $job_id );

		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$previous_status = $job['status'] ?? '';

		// Already failed — nothing to do.
		if ( str_starts_with( $previous_status, 'failed' ) ) {
			return array(
				'success'         => true,
				'job_id'          => $job_id,
				'previous_status' => $previous_status,
				'new_status'      => $previous_status,
				'message'         => sprintf( 'Job %d is already failed.', $job_id ),
			);
		}

		// Only allow failing processing jobs.
		if ( 'processing' !== $previous_status ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d has status "%s" — only processing jobs can be failed.', $job_id, $previous_status ),
			);
		}

		$new_status = "failed - {$reason}";
		$this->db_jobs->complete_job( $job_id, $new_status );

		do_action( 'datamachine_job_complete', $job_id, 'failed' );

		do_action(
			'datamachine_log',
			'info',
			'Job manually failed via ability',
			array(
				'job_id'          => $job_id,
				'previous_status' => $previous_status,
				'new_status'      => $new_status,
				'reason'          => $reason,
			)
		);

		return array(
			'success'         => true,
			'job_id'          => $job_id,
			'previous_status' => $previous_status,
			'new_status'      => $new_status,
			'message'         => sprintf( 'Job %d marked as "%s".', $job_id, $new_status ),
		);
	}
}
