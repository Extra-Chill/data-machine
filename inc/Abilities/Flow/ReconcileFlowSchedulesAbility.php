<?php
/**
 * Reconcile Flow Schedules Ability.
 *
 * @package DataMachine\Abilities\Flow
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Api\Flows\FlowScheduleReconciler;

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
					'description'         => __( 'Audit recurring flow schedule coverage and optionally restore missing Action Scheduler actions.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'apply'        => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Restore missing schedules. Defaults to dry-run.', 'data-machine' ),
							),
							'spread_hours' => array(
								'type'        => array( 'integer', 'null' ),
								'minimum'     => 1,
								'maximum'     => 24,
								'description' => __( 'Explicit fleet distribution window in hours (1-24).', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'transient'         => array( 'type' => 'boolean' ),
							'code'              => array( 'type' => 'string' ),
							'applied'           => array( 'type' => 'boolean' ),
							'eligible'          => array( 'type' => 'integer' ),
							'covered'           => array( 'type' => 'integer' ),
							'missing'           => array( 'type' => 'integer' ),
							'blocked'           => array( 'type' => 'integer' ),
							'repaired'          => array( 'type' => 'integer' ),
							'failed'            => array( 'type' => 'integer' ),
							'remaining_missing' => array( 'type' => 'integer' ),
							'invalid'           => array( 'type' => 'integer' ),
							'details'           => array( 'type' => 'array' ),
							'error'             => array( 'type' => 'string' ),
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
		$spread_hours = isset( $input['spread_hours'] ) ? (int) $input['spread_hours'] : null;
		return ( new FlowScheduleReconciler() )->reconcile( ! empty( $input['apply'] ), $spread_hours );
	}
}
