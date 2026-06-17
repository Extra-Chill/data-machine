<?php
/**
 * Run Metrics Ability.
 *
 * @package DataMachine\Abilities\Job
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\RunMetrics;

defined( 'ABSPATH' ) || exit;

class RunMetricsAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-run-metrics',
				array(
					'label'               => __( 'Get Run Metrics', 'data-machine' ),
					'description'         => __( 'Get generic run metrics for a flow, pipeline, batch, or system-task job.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id' ),
						'properties' => array(
							'job_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'Job ID whose run metrics should be returned.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'metrics' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	public function execute( array $input ): array {
		$job_id = (int) ( $input['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'job_id must be a positive integer.',
			);
		}

		$job = $this->db_jobs->get_job( $job_id );
		if ( ! $job ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Job %d not found.', $job_id ),
			);
		}

		$jobs = $this->enrichJobNames( array( $job ) );
		$job  = $this->addDisplayFields( $jobs[0] );

		return array(
			'success' => true,
			'metrics' => RunMetrics::fromJob( $job ),
		);
	}
}
