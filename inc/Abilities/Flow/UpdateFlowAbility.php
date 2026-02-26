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

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/update-flow',
				array(
					'label'               => __( 'Update Flow', 'data-machine' ),
					'description'         => __( 'Update flow name or scheduling.', 'data-machine' ),
					'category'            => 'datamachine',
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
							'error'     => array( 'type' => 'string' ),
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
	 * Execute update flow ability.
	 *
	 * @param array $input Input parameters with flow_id, optional flow_name and scheduling_config.
	 * @return array Result with updated flow data.
	 */
	public function execute( array $input ): array {
		$flow_id           = $input['flow_id'] ?? null;
		$flow_name         = $input['flow_name'] ?? null;
		$scheduling_config = $input['scheduling_config'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		$flow_id = (int) $flow_id;

		if ( null === $flow_name && null === $scheduling_config ) {
			return array(
				'success' => false,
				'error'   => 'Must provide flow_name or scheduling_config to update',
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		if ( null !== $flow_name ) {
			$flow_name = sanitize_text_field( wp_unslash( $flow_name ) );
			if ( empty( trim( $flow_name ) ) ) {
				return array(
					'success' => false,
					'error'   => 'Flow name cannot be empty',
				);
			}

			$success = $this->db_flows->update_flow(
				$flow_id,
				array( 'flow_name' => $flow_name )
			);

			if ( ! $success ) {
				return array(
					'success' => false,
					'error'   => 'Failed to update flow name',
				);
			}
		}

		if ( null !== $scheduling_config ) {
			$result = FlowScheduling::handle_scheduling_update( $flow_id, $scheduling_config );
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
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
