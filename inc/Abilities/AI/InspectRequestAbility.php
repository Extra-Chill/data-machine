<?php
/**
 * Inspect AI request ability.
 *
 * @package DataMachine\Abilities\AI
 */

namespace DataMachine\Abilities\AI;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\RequestInspector;

defined( 'ABSPATH' ) || exit;

class InspectRequestAbility {

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/inspect-ai-request',
				array(
					'label'               => __( 'Inspect AI Request', 'data-machine' ),
					'description'         => __( 'Reconstruct the final provider request for a pipeline AI job without dispatching it.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID to inspect.', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => 'string',
								'description' => __( 'Optional AI flow step ID. Required when the job snapshot has multiple AI steps.', 'data-machine' ),
							),
						),
						'required'   => array( 'job_id' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
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

	public function checkPermission(): bool {
		return PermissionHelper::can( 'view_logs' ) || PermissionHelper::can_manage();
	}

	public function execute( array $input ): array|\WP_Error {
		$job_id = (int) ( $input['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return new \WP_Error(
				'invalid_job_id',
				'Missing or invalid job_id.',
				array(
					'status' => 400,
					'job_id' => $job_id,
				)
			);
		}

		$flow_step_id = isset( $input['flow_step_id'] ) && '' !== (string) $input['flow_step_id']
			? (string) $input['flow_step_id']
			: null;

		$result = ( new RequestInspector() )->inspectPipelineJob( $job_id, $flow_step_id );
		if ( ! empty( $result['success'] ) ) {
			return $result;
		}

		$message = (string) ( $result['error'] ?? 'AI request inspection failed.' );
		$code    = 'request_inspection_failed';
		$status  = 400;
		if ( str_contains( $message, 'Job ' ) && str_contains( $message, ' not found' ) ) {
			$code   = 'job_not_found';
			$status = 404;
		} elseif ( str_contains( $message, 'not found in job engine snapshot' ) ) {
			$code   = 'flow_step_not_found';
			$status = 404;
		} elseif ( ! empty( $result['missing_required_handler_slugs'] ) ) {
			$code   = 'required_handler_tools_unavailable';
			$status = 409;
		}
		$data = $result;
		unset( $data['success'], $data['error'] );
		$data['status'] = $status;
		return new \WP_Error( $code, $message, $data );
	}
}
