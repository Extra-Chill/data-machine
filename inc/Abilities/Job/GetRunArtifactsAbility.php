<?php
/**
 * Get Run Artifacts Ability.
 *
 * @package DataMachine\Abilities\Job
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\JobArtifacts;
use DataMachine\Engine\Bundle\BundleSchema;

defined( 'ABSPATH' ) || exit;

class GetRunArtifactsAbility {

	use JobHelpers;

	private const SCHEMA_VERSION = 1;

	public function __construct() {
		$this->initDatabases();
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-run-artifacts',
				array(
					'label'               => __( 'Get Run Artifacts', 'data-machine' ),
					'description'         => __( 'Retrieve the normalized public artifact payload and effective artifact egress policy captured for a job.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'job_id' ),
						'properties'           => array(
							'job_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'Job identifier whose public run artifacts should be returned.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                    => array( 'type' => 'boolean' ),
							'schema_version'             => array( 'type' => 'integer' ),
							'job_id'                     => array( 'type' => 'integer' ),
							'artifacts'                  => array( 'type' => 'object' ),
							'run_artifact_egress_policy' => array( 'type' => 'object' ),
							'policy_provenance'          => array(
								'type'       => 'object',
								'properties' => array(
									'source'     => array(
										'type' => 'string',
										'enum' => array( 'job_snapshot', 'flow_snapshot', 'none' ),
									),
									'path'       => array( 'type' => 'string' ),
									'normalized' => array( 'type' => 'boolean' ),
								),
							),
							'error_code'                 => array( 'type' => 'string' ),
							'error'                      => array( 'type' => 'string' ),
							'status'                     => array( 'type' => 'integer' ),
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

	/**
	 * Retrieve a job's public run artifact contract.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$job_id = (int) ( $input['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return $this->error( 'invalid_job_id', 'job_id must be a positive integer.', 400 );
		}

		$job = $this->db_jobs->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return $this->error( 'job_not_found', sprintf( 'Job %d was not found.', $job_id ), 404 );
		}

		if ( ! $this->canAccessJob( $job ) ) {
			return $this->jobAccessDeniedError();
		}

		$artifact_result = ( new JobArtifacts() )->get( $job_id );
		if ( empty( $artifact_result['success'] ) || ! is_array( $artifact_result['artifacts'] ?? null ) ) {
			return $this->error(
				'artifacts_unavailable',
				(string) ( $artifact_result['error'] ?? 'Run artifacts are unavailable.' ),
				500
			);
		}

		$policy = $this->effectivePolicy( $job['engine_data'] ?? null );

		return array(
			'success'                    => true,
			'schema_version'             => self::SCHEMA_VERSION,
			'job_id'                     => $job_id,
			'artifacts'                  => $artifact_result['artifacts'],
			'run_artifact_egress_policy' => $policy['policy'],
			'policy_provenance'          => $policy['provenance'],
		);
	}

	/**
	 * Resolve the effective captured policy without exposing engine data.
	 *
	 * @param mixed $engine_data Stored engine data.
	 * @return array{policy:array<string,array<string,mixed>>,provenance:array{source:string,path:string,normalized:bool}}
	 */
	private function effectivePolicy( mixed $engine_data ): array {
		$engine_data = is_array( $engine_data ) ? $engine_data : array();
		$candidates  = array(
			array( 'job_snapshot', 'run_artifact_egress_policy', $engine_data['run_artifact_egress_policy'] ?? null ),
			array( 'flow_snapshot', 'flow.run_artifacts', $engine_data['flow']['run_artifacts'] ?? null ),
		);

		foreach ( $candidates as list( $source, $path, $candidate ) ) {
			if ( null === $candidate ) {
				continue;
			}

			return array(
				'policy'     => BundleSchema::normalize_run_artifact_egress_policy( $candidate ),
				'provenance' => array(
					'source'     => $source,
					'path'       => $path,
					'normalized' => true,
				),
			);
		}

		return array(
			'policy'     => array(),
			'provenance' => array(
				'source'     => 'none',
				'path'       => '',
				'normalized' => true,
			),
		);
	}

	/** @return array{success:false,error_code:string,error:string,status:int} */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'retryable' => $status >= 500,
			)
		);
	}
}
