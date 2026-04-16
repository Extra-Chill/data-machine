<?php
/**
 * Pause Flow Ability
 *
 * Pauses one or more flows by setting enabled=false in scheduling_config.
 * Preserves the original schedule so flows can be resumed without reconfiguration.
 * Unschedules Action Scheduler hooks for paused flows.
 *
 * Supports three scoping levels:
 * - Single flow: flow_id
 * - All flows in a pipeline: pipeline_id
 * - All flows for an agent: agent_id
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.59.0
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Api\Flows\FlowScheduling;

defined( 'ABSPATH' ) || exit;

class PauseFlowAbility {

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
				'datamachine/pause-flow',
				array(
					'label'               => __( 'Pause Flow', 'data-machine' ),
					'description'         => __( 'Pause one or more flows. Preserves schedule for later resume.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Single flow ID to pause.', 'data-machine' ),
							),
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => __( 'Pause all flows in this pipeline.', 'data-machine' ),
							),
							'agent_id'    => array(
								'type'        => 'integer',
								'description' => __( 'Pause all flows for this agent.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'paused'  => array( 'type' => 'integer' ),
							'skipped' => array( 'type' => 'integer' ),
							'flows'   => array( 'type' => 'array' ),
							'error'   => array( 'type' => 'string' ),
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
	 * Execute pause flow ability.
	 *
	 * @param array $input Input with flow_id, pipeline_id, or agent_id.
	 * @return array Result with pause counts and affected flow IDs.
	 */
	public function execute( array $input ): array {
		$flow_id     = isset( $input['flow_id'] ) ? (int) $input['flow_id'] : null;
		$pipeline_id = isset( $input['pipeline_id'] ) ? (int) $input['pipeline_id'] : null;
		$agent_id    = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;

		if ( null === $flow_id && null === $pipeline_id && null === $agent_id ) {
			return array(
				'success' => false,
				'error'   => 'Must provide flow_id, pipeline_id, or agent_id.',
			);
		}

		$flows = $this->resolveFlows( $flow_id, $pipeline_id, $agent_id );

		if ( empty( $flows ) ) {
			return array(
				'success' => false,
				'error'   => 'No flows found matching the specified criteria.',
			);
		}

		$paused  = 0;
		$skipped = 0;
		$details = array();

		foreach ( $flows as $flow ) {
			$fid              = (int) $flow['flow_id'];
			$scheduling       = $flow['scheduling_config'] ?? array();

			// Already paused — skip.
			if ( isset( $scheduling['enabled'] ) && false === $scheduling['enabled'] ) {
				++$skipped;
				$details[] = array(
					'flow_id' => $fid,
					'status'  => 'already_paused',
				);
				continue;
			}

			// Set enabled=false, preserving all other scheduling fields.
			$scheduling['enabled'] = false;
			$this->db_flows->update_flow_scheduling( $fid, $scheduling );

			// Unschedule Action Scheduler hooks so paused flows don't fire.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'datamachine_run_flow_now', array( $fid ), 'data-machine' );
			}

			++$paused;
			$details[] = array(
				'flow_id' => $fid,
				'status'  => 'paused',
			);
		}

		$scope = $flow_id ? "flow {$flow_id}" : ( $pipeline_id ? "pipeline {$pipeline_id}" : "agent {$agent_id}" );

		do_action(
			'datamachine_log',
			'info',
			"Flows paused for {$scope}",
			array(
				'paused'  => $paused,
				'skipped' => $skipped,
			)
		);

		return array(
			'success' => true,
			'paused'  => $paused,
			'skipped' => $skipped,
			'flows'   => $details,
			'message' => sprintf( 'Paused %d flow(s), skipped %d (already paused).', $paused, $skipped ),
		);
	}

	/**
	 * Resolve flows from the given scope.
	 *
	 * @param int|null $flow_id     Single flow ID.
	 * @param int|null $pipeline_id Pipeline ID for bulk scope.
	 * @param int|null $agent_id    Agent ID for bulk scope.
	 * @return array Array of flow records.
	 */
	private function resolveFlows( ?int $flow_id, ?int $pipeline_id, ?int $agent_id ): array {
		if ( null !== $flow_id ) {
			$flow = $this->db_flows->get_flow( $flow_id );
			return $flow ? array( $flow ) : array();
		}

		if ( null !== $pipeline_id ) {
			return $this->db_flows->get_flows_for_pipeline( $pipeline_id );
		}

		if ( null !== $agent_id ) {
			return $this->db_flows->get_all_flows( null, $agent_id );
		}

		return array();
	}
}
