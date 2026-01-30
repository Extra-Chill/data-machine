<?php
/**
 * Get Flow Steps Ability
 *
 * Handles flow step querying and retrieval for a flow or by step ID.
 *
 * @package DataMachine\Abilities\FlowStep
 * @since 0.15.3
 */

namespace DataMachine\Abilities\FlowStep;

defined( 'ABSPATH' ) || exit;

class GetFlowStepsAbility {

	use FlowStepHelpers;

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
				'datamachine/get-flow-steps',
				array(
					'label'               => __( 'Get Flow Steps', 'data-machine' ),
					'description'         => __( 'Get all step configurations for a flow, or a single step by ID.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to get steps for (required unless flow_step_id provided)', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Get a specific step by ID (ignores flow_id when provided)', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'steps'      => array( 'type' => 'array' ),
							'flow_id'    => array( 'type' => 'integer' ),
							'step_count' => array( 'type' => 'integer' ),
							'error'      => array( 'type' => 'string' ),
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
	 * Execute get flow steps ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with steps data.
	 */
	public function execute( array $input ): array {
		$flow_id      = $input['flow_id'] ?? null;
		$flow_step_id = $input['flow_step_id'] ?? null;

		// Direct step lookup by ID - bypasses flow_id requirement.
		if ( $flow_step_id ) {
			if ( ! is_string( $flow_step_id ) ) {
				return array(
					'success' => false,
					'error'   => 'flow_step_id must be a non-empty string',
				);
			}

			$step_config = $this->db_flows->get_flow_step_config( $flow_step_id );

			if ( empty( $step_config ) ) {
				return array(
					'success'    => true,
					'steps'      => array(),
					'flow_id'    => null,
					'step_count' => 0,
				);
			}

			return array(
				'success'    => true,
				'steps'      => array( $step_config ),
				'flow_id'    => $step_config['flow_id'] ?? null,
				'step_count' => 1,
			);
		}

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'flow_id is required and must be a positive integer',
			);
		}

		$flow_id = (int) $flow_id;
		$flow    = $this->db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => 'Flow not found',
			);
		}

		$flow_config = $flow['flow_config'] ?? array();
		$steps       = array();

		foreach ( $flow_config as $step_id => $step_data ) {
			$step_data['flow_step_id'] = $step_id;
			$steps[]                   = $step_data;
		}

		usort(
			$steps,
			function ( $a, $b ) {
				return ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 );
			}
		);

		return array(
			'success'    => true,
			'steps'      => $steps,
			'flow_id'    => $flow_id,
			'step_count' => count( $steps ),
		);
	}
}
