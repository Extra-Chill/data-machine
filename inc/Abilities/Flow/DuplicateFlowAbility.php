<?php
/**
 * Duplicate Flow Ability
 *
 * Handles flow duplication including cross-pipeline copying with config mapping.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Api\Flows\FlowScheduling;

defined( 'ABSPATH' ) || exit;

class DuplicateFlowAbility {

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
				'datamachine/duplicate-flow',
				array(
					'label'               => __( 'Duplicate Flow', 'data-machine' ),
					'description'         => __( 'Duplicate a flow, optionally to a different pipeline.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'source_flow_id' ),
						'properties' => array(
							'source_flow_id'        => array(
								'type'        => 'integer',
								'description' => __( 'Source flow ID to duplicate', 'data-machine' ),
							),
							'target_pipeline_id'    => array(
								'type'        => 'integer',
								'description' => __( 'Target pipeline ID (defaults to source pipeline)', 'data-machine' ),
							),
							'flow_name'             => array(
								'type'        => 'string',
								'description' => __( 'Name for new flow (defaults to "Copy of {source}")', 'data-machine' ),
							),
							'scheduling_config'     => array(
								'type'        => 'object',
								'description' => __( 'Scheduling config (defaults to source interval)', 'data-machine' ),
							),
							'step_config_overrides' => array(
								'type'        => 'object',
								'description' => __( 'Step overrides keyed by step_type or execution_order', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'flow_id'            => array( 'type' => 'integer' ),
							'flow_name'          => array( 'type' => 'string' ),
							'source_flow_id'     => array( 'type' => 'integer' ),
							'source_pipeline_id' => array( 'type' => 'integer' ),
							'target_pipeline_id' => array( 'type' => 'integer' ),
							'flow_step_ids'      => array( 'type' => 'array' ),
							'scheduling'         => array( 'type' => 'string' ),
							'error'              => array( 'type' => 'string' ),
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
	 * Execute duplicate flow ability.
	 *
	 * @param array $input Input parameters with source_flow_id, optional target_pipeline_id, flow_name, etc.
	 * @return array Result with duplicated flow data.
	 */
	public function execute( array $input ): array {
		$source_flow_id = $input['source_flow_id'] ?? null;

		if ( ! is_numeric( $source_flow_id ) || (int) $source_flow_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'source_flow_id is required and must be a positive integer',
			);
		}

		$source_flow_id = (int) $source_flow_id;
		$source_flow    = $this->db_flows->get_flow( $source_flow_id );

		if ( ! $source_flow ) {
			do_action( 'datamachine_log', 'error', 'Source flow not found for copy', array( 'source_flow_id' => $source_flow_id ) );
			return array(
				'success' => false,
				'error'   => 'Source flow not found',
			);
		}

		$source_pipeline_id = (int) $source_flow['pipeline_id'];
		$target_pipeline_id = isset( $input['target_pipeline_id'] ) ? (int) $input['target_pipeline_id'] : $source_pipeline_id;
		$is_cross_pipeline  = ( $target_pipeline_id !== $source_pipeline_id );

		$source_pipeline = $this->db_pipelines->get_pipeline( $source_pipeline_id );
		if ( ! $source_pipeline ) {
			return array(
				'success' => false,
				'error'   => 'Source pipeline not found',
			);
		}

		$target_pipeline = $this->db_pipelines->get_pipeline( $target_pipeline_id );
		if ( ! $target_pipeline ) {
			return array(
				'success' => false,
				'error'   => 'Target pipeline not found',
			);
		}

		if ( $is_cross_pipeline ) {
			$source_pipeline_config = $source_pipeline['pipeline_config'] ?? array();
			$target_pipeline_config = $target_pipeline['pipeline_config'] ?? array();

			$compatibility = $this->validatePipelineCompatibility( $source_pipeline_config, $target_pipeline_config );
			if ( ! $compatibility['compatible'] ) {
				do_action(
					'datamachine_log',
					'error',
					'Pipeline compatibility validation failed',
					array(
						'source_pipeline_id' => $source_pipeline_id,
						'target_pipeline_id' => $target_pipeline_id,
						'error'              => $compatibility['error'],
					)
				);
				return array(
					'success' => false,
					'error'   => $compatibility['error'],
				);
			}
		}

		$new_flow_name = isset( $input['flow_name'] ) && ! empty( $input['flow_name'] )
			? sanitize_text_field( $input['flow_name'] )
			: sprintf( 'Copy of %s', $source_flow['flow_name'] );

		$requested_scheduling_config = $input['scheduling_config'] ?? ( $source_flow['scheduling_config'] ?? array() );
		$scheduling_config           = $this->getIntervalOnlySchedulingConfig(
			is_array( $requested_scheduling_config ) ? $requested_scheduling_config : array()
		);

		$flow_data = array(
			'pipeline_id'       => $target_pipeline_id,
			'flow_name'         => $new_flow_name,
			'flow_config'       => array(),
			'scheduling_config' => $scheduling_config,
		);

		$new_flow_id = $this->db_flows->create_flow( $flow_data );
		if ( ! $new_flow_id ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create flow during copy',
				array(
					'source_flow_id'     => $source_flow_id,
					'target_pipeline_id' => $target_pipeline_id,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Failed to create new flow',
			);
		}

		$new_flow_config = $this->buildCopiedFlowConfig(
			$source_flow['flow_config'] ?? array(),
			$source_pipeline['pipeline_config'] ?? array(),
			$target_pipeline['pipeline_config'] ?? array(),
			$new_flow_id,
			$target_pipeline_id,
			$input['step_config_overrides'] ?? array()
		);

		$this->db_flows->update_flow(
			$new_flow_id,
			array( 'flow_config' => $new_flow_config )
		);

		if ( isset( $scheduling_config['interval'] ) && 'manual' !== $scheduling_config['interval'] ) {
			$scheduling_result = FlowScheduling::handle_scheduling_update( $new_flow_id, $scheduling_config );
			if ( is_wp_error( $scheduling_result ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to schedule copied flow',
					array(
						'flow_id' => $new_flow_id,
						'error'   => $scheduling_result->get_error_message(),
					)
				);
			}
		}

		$new_flow = $this->db_flows->get_flow( $new_flow_id );

		do_action(
			'datamachine_log',
			'info',
			'Flow copied successfully',
			array(
				'source_flow_id'     => $source_flow_id,
				'new_flow_id'        => $new_flow_id,
				'source_pipeline_id' => $source_pipeline_id,
				'target_pipeline_id' => $target_pipeline_id,
				'cross_pipeline'     => $is_cross_pipeline,
			)
		);

		return array(
			'success'            => true,
			'flow_id'            => $new_flow_id,
			'flow_name'          => $new_flow_name,
			'source_flow_id'     => $source_flow_id,
			'source_pipeline_id' => $source_pipeline_id,
			'target_pipeline_id' => $target_pipeline_id,
			'flow_data'          => $new_flow,
			'flow_step_ids'      => array_keys( $new_flow_config ),
			'scheduling'         => $scheduling_config['interval'] ?? 'manual',
		);
	}
}
