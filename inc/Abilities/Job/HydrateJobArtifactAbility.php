<?php
/**
 * Hydrate Job Artifact Ability.
 *
 * @package DataMachine\Abilities\Job
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\JobArtifacts;

defined( 'ABSPATH' ) || exit;

class HydrateJobArtifactAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/hydrate-job-artifact',
				array(
					'label'               => __( 'Hydrate Job Artifact', 'data-machine' ),
					'description'         => __( 'Resolve a portable job artifact_ref to verified artifact content. The response omits local_debug metadata and returns content as base64 for JSON-safe transport.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'artifact_ref' ),
						'properties' => array(
							'artifact_ref' => array(
								'type'        => 'string',
								'description' => __( 'Portable artifact ref, for example datamachine://jobs/123/artifacts/tool-trace.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'artifact'       => array( 'type' => 'object' ),
							'content_base64' => array( 'type' => 'string' ),
							'encoding'       => array( 'type' => 'string' ),
							'bytes'          => array( 'type' => 'integer' ),
							'sha256'         => array( 'type' => 'string' ),
							'verified'       => array( 'type' => 'boolean' ),
							'error'          => array( 'type' => 'string' ),
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
	 * Execute artifact hydration.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		$artifact_ref = trim( (string) ( $input['artifact_ref'] ?? '' ) );
		if ( '' === $artifact_ref ) {
			return array(
				'success' => false,
				'error'   => 'artifact_ref is required.',
			);
		}

		$artifacts = new JobArtifacts();
		$job_id    = $artifacts->job_id_from_artifact_ref( $artifact_ref );
		$job       = $job_id > 0 ? $this->db_jobs->get_job( $job_id ) : null;
		if ( ! is_array( $job ) ) {
			return array(
				'success' => false,
				'error'   => 'artifact_ref is not associated with an existing job.',
			);
		}

		if ( ! $this->canAccessJob( $job ) ) {
			return $this->jobAccessDenied();
		}

		$result = $artifacts->hydrate_artifact_ref( $artifact_ref, is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array() );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$content = (string) ( $result['content'] ?? '' );
		unset( $result['content'] );
		$result['content_base64'] = base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes verified artifact bytes for JSON-safe transport.
		$result['encoding']       = 'base64';

		return $result;
	}
}
