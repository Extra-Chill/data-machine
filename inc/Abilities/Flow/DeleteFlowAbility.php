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

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-flow',
				array(
					'label'               => __( 'Delete Flow', 'data-machine' ),
					'description'         => __( 'Delete a flow and unschedule its actions.', 'data-machine' ),
					'category'            => 'datamachine',
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute delete flow ability.
	 *
	 * @param array $input Input parameters with flow_id.
	 * @return array Result with success status.
	 */
	public function execute( array $input ): array {
		$flow_id = $input['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		$flow_id = (int) $flow_id;
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow not found for deletion', array( 'flow_id' => $flow_id ) );
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$pipeline_id = (int) ( $flow['pipeline_id'] ?? 0 );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		}

		$success = $this->db_flows->delete_flow( $flow_id );

		if ( $success ) {
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

		do_action( 'datamachine_log', 'error', 'Failed to delete flow', array( 'flow_id' => $flow_id ) );
		return array(
			'success' => false,
			'error'   => 'Failed to delete flow',
		);
	}
}
