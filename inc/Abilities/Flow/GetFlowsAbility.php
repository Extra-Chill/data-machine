<?php
/**
 * Get Flows Ability
 *
 * Handles flow querying and listing with filtering, pagination, and output modes.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

defined( 'ABSPATH' ) || exit;

class GetFlowsAbility {

	use FlowHelpers;

	private const DEFAULT_PER_PAGE = 20;

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
				'datamachine/get-flows',
				array(
					'label'               => __( 'Get Flows', 'data-machine' ),
					'description'         => __( 'Get flows with optional filtering by pipeline ID or handler slug. Supports single flow retrieval and flexible output modes.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'      => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Get a specific flow by ID (ignores pagination when provided)', 'data-machine' ),
							),
							'pipeline_id'  => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Filter flows by pipeline ID', 'data-machine' ),
							),
							'handler_slug' => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Filter flows using this handler slug (any step that uses this handler)', 'data-machine' ),
							),
							'per_page'     => array(
								'type'        => 'integer',
								'default'     => self::DEFAULT_PER_PAGE,
								'minimum'     => 1,
								'maximum'     => 100,
								'description' => __( 'Number of flows per page', 'data-machine' ),
							),
							'offset'       => array(
								'type'        => 'integer',
								'default'     => 0,
								'minimum'     => 0,
								'description' => __( 'Offset for pagination', 'data-machine' ),
							),
							'output_mode'  => array(
								'type'        => 'string',
								'enum'        => array( 'full', 'summary', 'ids' ),
								'default'     => 'full',
								'description' => __( 'Output mode: full=all data with latest job status, summary=key fields only, ids=just flow_ids', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'flows'           => array( 'type' => 'array' ),
							'total'           => array( 'type' => 'integer' ),
							'per_page'        => array( 'type' => 'integer' ),
							'offset'          => array( 'type' => 'integer' ),
							'filters_applied' => array( 'type' => 'object' ),
							'output_mode'     => array( 'type' => 'string' ),
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
	 * Execute get flows ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flows data.
	 */
	public function execute( array $input ): array {
		try {
			$flow_id      = $input['flow_id'] ?? null;
			$pipeline_id  = $input['pipeline_id'] ?? null;
			$handler_slug = $input['handler_slug'] ?? null;
			$per_page     = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
			$offset       = (int) ( $input['offset'] ?? 0 );
			$output_mode  = $input['output_mode'] ?? 'full';

			if ( ! in_array( $output_mode, array( 'full', 'summary', 'ids' ), true ) ) {
				$output_mode = 'full';
			}

			if ( $flow_id ) {
				$flow = $this->db_flows->get_flow( (int) $flow_id );
				if ( ! $flow ) {
					return array(
						'success'         => true,
						'flows'           => array(),
						'total'           => 0,
						'per_page'        => $per_page,
						'offset'          => $offset,
						'output_mode'     => $output_mode,
						'filters_applied' => array( 'flow_id' => $flow_id ),
					);
				}

				$formatted_flow = $this->formatFlowByMode( $flow, $output_mode );

				return array(
					'success'         => true,
					'flows'           => array( $formatted_flow ),
					'total'           => 1,
					'per_page'        => $per_page,
					'offset'          => $offset,
					'output_mode'     => $output_mode,
					'filters_applied' => array( 'flow_id' => $flow_id ),
				);
			}

			$filters_applied = array(
				'pipeline_id'  => $pipeline_id,
				'handler_slug' => $handler_slug,
			);

			$flows = array();
			$total = 0;

			if ( $pipeline_id ) {
				$flows = $this->db_flows->get_flows_for_pipeline_paginated( $pipeline_id, $per_page, $offset );
				$total = $this->db_flows->count_flows_for_pipeline( $pipeline_id );
			} else {
				$flows = $this->getAllFlowsPaginated( $per_page, $offset );
				$total = $this->countAllFlows();
			}

			if ( $handler_slug ) {
				$flows = $this->filterByHandlerSlug( $flows, $handler_slug );
			}

			$formatted_flows = $this->formatFlowsByMode( $flows, $output_mode );

			return array(
				'success'         => true,
				'flows'           => $formatted_flows,
				'total'           => $total,
				'per_page'        => $per_page,
				'offset'          => $offset,
				'output_mode'     => $output_mode,
				'filters_applied' => $filters_applied,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
