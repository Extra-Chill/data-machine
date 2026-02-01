<?php
/**
 * Flow Health Ability
 *
 * Handles flow health metrics including consecutive failures and no-items counts.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class FlowHealthAbility {

	use JobHelpers;

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
				'datamachine/get-flow-health',
				array(
					'label'               => __( 'Get Flow Health', 'data-machine' ),
					'description'         => __( 'Get health metrics for a flow including consecutive failures and no-items counts.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id' => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to check health for', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'              => array( 'type' => 'boolean' ),
							'flow_id'              => array( 'type' => 'integer' ),
							'consecutive_failures' => array( 'type' => 'integer' ),
							'consecutive_no_items' => array( 'type' => 'integer' ),
							'latest_job'           => array( 'type' => array( 'object', 'null' ) ),
							'error'                => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-flow-health ability.
	 *
	 * @param array $input Input parameters with flow_id.
	 * @return array Result with health metrics.
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

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found', $flow_id ),
			);
		}

		$health = $this->db_jobs->get_flow_health( $flow_id );

		return array(
			'success'              => true,
			'flow_id'              => $flow_id,
			'consecutive_failures' => $health['consecutive_failures'] ?? 0,
			'consecutive_no_items' => $health['consecutive_no_items'] ?? 0,
			'latest_job'           => $health['latest_job'] ?? null,
		);
	}
}
