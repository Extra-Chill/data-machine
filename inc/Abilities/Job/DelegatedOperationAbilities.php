<?php
/**
 * Public delegated operation abilities.
 *
 * @package DataMachine\Abilities\Job
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\ExecutionScope;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;

defined( 'ABSPATH' ) || exit;

final class DelegatedOperationAbilities {

	private DelegatedOperationService $service;

	public function __construct( ?DelegatedOperationService $service = null ) {
		$this->service = $service ?? new DelegatedOperationService();
		AbilityRegistration::on_abilities_api_init( array( $this, 'register' ) );
	}

	public function register(): void {
		$identity = array(
			'action' => array( 'type' => 'string', 'maxLength' => 129 ),
			'operation_ref' => array( 'type' => 'string', 'pattern' => '^dop_[a-f0-9]{64}$' ),
		);
		$output = array(
			'type'       => 'object',
			'required'   => array( 'success' ),
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'operation_ref' => array( 'type' => 'string' ),
				'status'        => array( 'type' => 'string', 'enum' => array( 'submitted', 'executing', 'executed', 'no-op', 'failed', 'cancelled', 'retrying' ) ),
				'replayed'      => array( 'type' => 'boolean' ),
				'projection'    => array( 'type' => 'object' ),
				'retry'         => array( 'type' => 'object' ),
				'retryable'     => array( 'type' => 'boolean' ),
				'error_code'    => array( 'type' => 'string' ),
				'error'         => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		wp_register_ability(
			'datamachine/submit-delegated-operation',
			array(
				'label'               => __( 'Submit Delegated Operation', 'data-machine' ),
				'description'         => __( 'Submit one owner-registered operation without gaining general workflow authority.', 'data-machine' ),
				'category'            => 'datamachine-jobs',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'action', 'operation_id', 'input' ),
					'properties' => array(
						'action'       => $identity['action'],
						'operation_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
						'input'        => array( 'type' => 'object' ),
						'timestamp'    => array( 'type' => array( 'integer', 'null' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $output,
				'execute_callback'    => array( $this->service, 'submit' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		foreach ( array( 'get' => 'reconcile', 'retry' => 'retry', 'cancel' => 'cancel' ) as $verb => $method ) {
			wp_register_ability(
				'datamachine/' . $verb . '-delegated-operation',
				array(
					'label'               => sprintf( __( '%s Delegated Operation', 'data-machine' ), ucfirst( $verb ) ),
					'description'         => __( 'Act on one owner-authorized delegated operation reference.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action', 'operation_ref' ),
						'properties' => $identity,
						'additionalProperties' => false,
					),
					'output_schema'       => $output,
					'execute_callback'    => array( $this->service, $method ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		}
	}

	/** Require identity only; the action owner supplies all downstream authority. */
	public function checkPermission(): bool {
		$scope = ExecutionScope::current( 'manage_flows' );
		return $scope->acting_user_id() > 0 || (int) ( $scope->acting_agent_id() ?? 0 ) > 0;
	}
}
