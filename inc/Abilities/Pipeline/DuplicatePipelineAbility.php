<?php
/**
 * Duplicate Pipeline Ability
 *
 * Handles pipeline duplication including all associated flows.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

defined( 'ABSPATH' ) || exit;

class DuplicatePipelineAbility {

	use PipelineHelpers;

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
				'datamachine/duplicate-pipeline',
				array(
					'label'               => __( 'Duplicate Pipeline', 'data-machine' ),
					'description'         => __( 'Duplicate a pipeline with all its flows.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pipeline_id' ),
						'properties' => array(
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => __( 'Source pipeline ID to duplicate', 'data-machine' ),
							),
							'new_name'    => array(
								'type'        => 'string',
								'description' => __( 'Name for the new pipeline (defaults to "Copy of {original}")', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'              => array( 'type' => 'boolean' ),
							'pipeline_id'          => array( 'type' => 'integer' ),
							'pipeline_name'        => array( 'type' => 'string' ),
							'source_pipeline_id'   => array( 'type' => 'integer' ),
							'source_pipeline_name' => array( 'type' => 'string' ),
							'flows_created'        => array( 'type' => 'integer' ),
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
	 * Execute duplicate pipeline ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with duplicated pipeline data.
	 */
	public function execute( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id     = (int) $pipeline_id;
		$source_pipeline = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $source_pipeline ) {
			do_action( 'datamachine_log', 'error', 'Source pipeline not found for duplication', array( 'pipeline_id' => $pipeline_id ) );
			return array(
				'success' => false,
				'error'   => 'Source pipeline not found',
			);
		}

		$source_name = $source_pipeline['pipeline_name'];
		$new_name    = isset( $input['new_name'] ) && ! empty( $input['new_name'] )
			? sanitize_text_field( $input['new_name'] )
			: sprintf( 'Copy of %s', $source_name );

		$new_pipeline_id = $this->db_pipelines->create_pipeline(
			array(
				'pipeline_name'   => $new_name,
				'pipeline_config' => array(),
			)
		);

		if ( ! $new_pipeline_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create new pipeline',
			);
		}

		$source_config   = $source_pipeline['pipeline_config'] ?? array();
		$new_config      = array();
		$step_id_mapping = array();

		foreach ( $source_config as $old_step_id => $step_data ) {
			$new_step_id                     = $new_pipeline_id . '_' . wp_generate_uuid4();
			$step_id_mapping[ $old_step_id ] = $new_step_id;

			$new_step_data                     = $step_data;
			$new_step_data['pipeline_step_id'] = $new_step_id;
			$new_config[ $new_step_id ]        = $new_step_data;
		}

		if ( ! empty( $new_config ) ) {
			$this->db_pipelines->update_pipeline(
				$new_pipeline_id,
				array( 'pipeline_config' => $new_config )
			);
		}

		$source_flows  = $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		$flows_created = 0;

		foreach ( $source_flows as $source_flow ) {
			$new_flow_name     = sprintf( 'Copy of %s', $source_flow['flow_name'] );
			$scheduling_config = $source_flow['scheduling_config'] ?? array( 'interval' => 'manual' );

			if ( is_string( $scheduling_config ) ) {
				$scheduling_config = json_decode( $scheduling_config, true ) ?? array( 'interval' => 'manual' );
			}

			$interval_only_config = array( 'interval' => $scheduling_config['interval'] ?? 'manual' );

			$create_flow_ability = wp_get_ability( 'datamachine/create-flow' );
			$flow_result         = null;
			if ( $create_flow_ability ) {
				$flow_result = $create_flow_ability->execute(
					array(
						'pipeline_id'       => $new_pipeline_id,
						'flow_name'         => $new_flow_name,
						'scheduling_config' => $interval_only_config,
					)
				);
			}

			if ( $flow_result && $flow_result['success'] ) {
				++$flows_created;

				$source_flow_config = $source_flow['flow_config'] ?? array();
				if ( is_string( $source_flow_config ) ) {
					$source_flow_config = json_decode( $source_flow_config, true ) ?? array();
				}

				$new_flow_config = $this->mapFlowConfig(
					$source_flow_config,
					$step_id_mapping,
					$flow_result['flow_id'],
					$new_pipeline_id
				);

				if ( ! empty( $new_flow_config ) ) {
					$this->db_flows->update_flow(
						$flow_result['flow_id'],
						array( 'flow_config' => $new_flow_config )
					);
				}
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Pipeline duplicated via ability',
			array(
				'source_pipeline_id' => $pipeline_id,
				'new_pipeline_id'    => $new_pipeline_id,
				'new_pipeline_name'  => $new_name,
				'flows_created'      => $flows_created,
			)
		);

		return array(
			'success'              => true,
			'pipeline_id'          => $new_pipeline_id,
			'pipeline_name'        => $new_name,
			'source_pipeline_id'   => $pipeline_id,
			'source_pipeline_name' => $source_name,
			'flows_created'        => $flows_created,
		);
	}
}
