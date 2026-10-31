<?php
/**
 * Delete Flow Ability
 *
 * Handles flow deletion and unscheduling of associated actions.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

defined( 'ABSPATH' ) || exit;

class DeleteFlowAbility {

	use FlowHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-flow',
				array(
					'label'               => __( 'Delete Flow', 'data-machine' ),
					'description'         => __( 'Delete a flow and unschedule its actions.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to delete', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'flow_id'     => array( 'type' => 'integer' ),
							'pipeline_id' => array( 'type' => 'integer' ),
							'message'     => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
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
	 * Execute delete flow ability.
	 *
	 * @param array $input Input parameters with flow_id.
	 * @return array Result with success status.
	 */
	public function execute( array $input ): array|\WP_Error {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return new \WP_Error( 'invalid_flow_id', 'flow_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		$flow_id = (int) $flow_id;
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow not found for deletion', array( 'flow_id' => $flow_id ) );
			return new \WP_Error( 'flow_not_found', 'Flow not found', array( 'status' => 404 ) );
		}

		$pipeline_id = (int) ( $flow['pipeline_id'] ?? 0 );

		// Cancel the flow's routine and one-time schedules first, then delete
		// the row. A failed delete leaves the flow persisted but unscheduled,
		// which is visible as manual rather than silently wrong.
		\DataMachine\Engine\Scheduling\FlowRoutines::unschedule( $flow_id );

		$deleted = $this->db_flows->delete_flow( $flow_id );
		if ( ! $deleted ) {
			return new \WP_Error(
				'flow_delete_failed',
				'Flow schedule was cancelled but the flow row could not be deleted.',
				array( 'status' => 500 )
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow deleted successfully',
			array(
				'flow_id'     => $flow_id,
				'pipeline_id' => $pipeline_id,
			)
		);

		return array(
			'success'     => true,
			'flow_id'     => $flow_id,
			'pipeline_id' => $pipeline_id,
			'message'     => 'Flow deleted successfully',
		);
	}
}
