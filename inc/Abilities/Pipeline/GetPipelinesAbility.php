<?php
/**
 * Get Pipelines Ability
 *
 * Handles pipeline querying and listing with filtering, pagination, and output modes.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

defined( 'ABSPATH' ) || exit;

class GetPipelinesAbility {

	use PipelineHelpers;

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
				'datamachine/get-pipelines',
				array(
					'label'               => __( 'Get Pipelines', 'data-machine' ),
					'description'         => __( 'Get pipelines with optional pagination and filtering, or a single pipeline by ID.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Get a specific pipeline by ID (ignores pagination when provided)', 'data-machine' ),
							),
							'per_page'    => array(
								'type'        => 'integer',
								'default'     => self::DEFAULT_PER_PAGE,
								'minimum'     => 1,
								'maximum'     => 100,
								'description' => __( 'Number of pipelines per page', 'data-machine' ),
							),
							'offset'      => array(
								'type'        => 'integer',
								'default'     => 0,
								'minimum'     => 0,
								'description' => __( 'Offset for pagination', 'data-machine' ),
							),
							'output_mode' => array(
								'type'        => 'string',
								'enum'        => array( 'full', 'summary', 'ids' ),
								'default'     => 'full',
								'description' => __( 'Output mode: full=all data with flows, summary=key fields only, ids=just pipeline_ids', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'pipelines'   => array( 'type' => 'array' ),
							'total'       => array( 'type' => 'integer' ),
							'per_page'    => array( 'type' => 'integer' ),
							'offset'      => array( 'type' => 'integer' ),
							'output_mode' => array( 'type' => 'string' ),
							'error'       => array( 'type' => 'string' ),
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
	 * Execute get pipelines ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with pipelines data.
	 */
	public function execute( array $input ): array {
		try {
			$pipeline_id = $input['pipeline_id'] ?? null;
			$per_page    = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
			$offset      = (int) ( $input['offset'] ?? 0 );
			$output_mode = $input['output_mode'] ?? 'full';

			if ( ! in_array( $output_mode, array( 'full', 'summary', 'ids' ), true ) ) {
				$output_mode = 'full';
			}

			// Direct pipeline lookup by ID - bypasses pagination.
			if ( $pipeline_id ) {
				if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
					return array(
						'success' => false,
						'error'   => 'pipeline_id must be a positive integer',
					);
				}

				$pipeline = $this->db_pipelines->get_pipeline( (int) $pipeline_id );

				if ( ! $pipeline ) {
					return array(
						'success'     => true,
						'pipelines'   => array(),
						'total'       => 0,
						'per_page'    => $per_page,
						'offset'      => $offset,
						'output_mode' => $output_mode,
					);
				}

				$formatted_pipeline = $this->formatPipelineByMode( $pipeline, $output_mode );

				return array(
					'success'     => true,
					'pipelines'   => array( $formatted_pipeline ),
					'total'       => 1,
					'per_page'    => $per_page,
					'offset'      => $offset,
					'output_mode' => $output_mode,
				);
			}

			$all_pipelines = $this->db_pipelines->get_all_pipelines();
			$total         = count( $all_pipelines );
			$pipelines     = array_slice( $all_pipelines, $offset, $per_page );

			$formatted_pipelines = $this->formatPipelinesByMode( $pipelines, $output_mode );

			return array(
				'success'     => true,
				'pipelines'   => $formatted_pipelines,
				'total'       => $total,
				'per_page'    => $per_page,
				'offset'      => $offset,
				'output_mode' => $output_mode,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
