<?php
/**
 * Reconcile Flow Schedules Ability.
 *
 * Thin adapter over FlowRoutines::reconcile(), which wraps the Agents API
 * routine registry's reconcile algorithm.
 *
 * @package DataMachine\Abilities\Flow
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\Scheduling\FlowRoutines;

defined( 'ABSPATH' ) || exit;

class ReconcileFlowSchedulesAbility {

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/reconcile-flow-schedules',
				array(
					'label'               => __( 'Reconcile Flow Schedules', 'data-machine' ),
					'description'         => __( 'Audit recurring flow routine coverage and optionally restore missing schedules.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'apply' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Restore missing schedules. Defaults to dry-run.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'applied'     => array( 'type' => 'boolean' ),
							'covered'     => array( 'type' => 'integer' ),
							'missing'     => array( 'type' => 'integer' ),
							'removed'     => array( 'type' => 'integer' ),
							'routine_ids' => array( 'type' => 'object' ),
							'errors'      => array( 'type' => 'object' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute schedule reconciliation.
	 *
	 * @param array $input Ability input.
	 * @return array Reconciliation report.
	 */
	public function execute( array $input ): array {
		return FlowRoutines::reconcile( ! empty( $input['apply'] ) );
	}
}
