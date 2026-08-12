<?php
/**
 * Update Flow Ability
 *
 * Handles flow updates including name and scheduling configuration changes.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Api\Flows\FlowScheduling;

defined( 'ABSPATH' ) || exit;

class UpdateFlowAbility {

	use FlowHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/update-flow',
				array(
					'label'               => __( 'Update Flow', 'data-machine' ),
					'description'         => __( 'Update flow name or scheduling.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'           => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to update', 'data-machine' ),
							),
							'flow_name'         => array(
								'type'        => 'string',
								'description' => __( 'New flow name', 'data-machine' ),
							),
							'scheduling_config' => array(
								'type'        => 'object',
								'description' => __( 'New scheduling configuration', 'data-machine' ),
								'properties'  => array(
									'interval' => array( 'type' => 'string' ),
								),
							),
							'agent_id'          => array(
								'type'        => 'integer',
								'description' => __( 'New agent ID to assign', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'flow_id'   => array( 'type' => 'integer' ),
							'flow_name' => array( 'type' => 'string' ),
							'flow_data' => array( 'type' => 'object' ),
							'message'   => array( 'type' => 'string' ),
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
	 * Execute update flow ability.
	 *
	 * @param array $input Input parameters with flow_id, optional flow_name and scheduling_config.
	 * @return array|\WP_Error Result with updated flow data or failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$flow_id           = $input['flow_id'] ?? null;
		$flow_name         = $input['flow_name'] ?? null;
		$scheduling_config = $input['scheduling_config'] ?? null;
		$agent_id          = $input['agent_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return new \WP_Error( 'update_failed', 'flow_id is required and must be a positive integer', array( 'status' => 400 ) );
		}

		$flow_id = (int) $flow_id;

		if ( null === $flow_name && null === $scheduling_config && null === $agent_id ) {
			return new \WP_Error( 'update_failed', 'Must provide flow_name, scheduling_config, or agent_id to update', array( 'status' => 400 ) );
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error( 'flow_not_found', 'Flow not found', array( 'status' => 404 ) );
		}

		if ( null !== $scheduling_config ) {
			$validation = datamachine_validate_interval( $scheduling_config['interval'] ?? 'manual', $scheduling_config );
			if ( ! $validation['valid'] ) {
				return new \WP_Error( 'update_failed', $validation['error'], array( 'status' => 400 ) );
			}
			$scheduling_config['interval'] = $validation['resolved'];
		}

		if ( null !== $flow_name ) {
			$flow_name = sanitize_text_field( wp_unslash( $flow_name ) );
			if ( empty( trim( $flow_name ) ) ) {
				return new \WP_Error( 'update_failed', 'Flow name cannot be empty', array( 'status' => 400 ) );
			}

			$success = $this->db_flows->update_flow(
				$flow_id,
				array( 'flow_name' => $flow_name )
			);

			if ( ! $success ) {
				return new \WP_Error( 'update_failed', 'Failed to update flow name', array( 'status' => 400 ) );
			}
		}

		if ( null !== $agent_id ) {
			$success = $this->db_flows->update_flow(
				$flow_id,
				array( 'agent_id' => absint( $agent_id ) )
			);

			if ( ! $success ) {
				return new \WP_Error( 'update_failed', 'Failed to update flow agent_id', array( 'status' => 400 ) );
			}
		}

		if ( null !== $scheduling_config ) {
			$result = FlowScheduling::handle_scheduling_update( $flow_id, $scheduling_config );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'update_failed', $result->get_error_message(), array( 'status' => 400 ) );
			}
		}

		$updated_flow = $this->db_flows->get_flow( $flow_id );

		do_action(
			'datamachine_log',
			'info',
			'Flow updated successfully',
			array(
				'flow_id'   => $flow_id,
				'flow_name' => $updated_flow['flow_name'] ?? '',
			)
		);

		return array(
			'success'   => true,
			'flow_id'   => $flow_id,
			'flow_name' => $updated_flow['flow_name'] ?? '',
			'flow_data' => $updated_flow,
			'message'   => 'Flow updated successfully',
		);
	}
}
