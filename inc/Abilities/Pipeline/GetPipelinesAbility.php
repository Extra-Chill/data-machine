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
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Get a specific pipeline by ID (ignores pagination when provided)', 'data-machine' ),
							),
							'user_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Filter pipelines by WordPress user ID. Defaults to 0 (shared/legacy).', 'data-machine' ),
								'default'     => 0,
							),
							'agent_id'    => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Filter pipelines by agent ID. Takes priority over user_id when provided.', 'data-machine' ),
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
							'search'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Search pipelines by name (SQL LIKE match)', 'data-machine' ),
							),
							'output_mode' => array(
								'type'        => 'string',
								'enum'        => array( 'full', 'summary', 'ids' ),
								'default'     => 'full',
								'description' => __( 'Output mode: full=all data with flows, summary=key fields only, ids=just pipeline_ids', 'data-machine' ),
							),
							'include_flows' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Include full flows array per pipeline in "full" mode. Set false for list views — response returns flow_count only and avoids N+1 flow queries.', 'data-machine' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
			$pipeline_id   = $input['pipeline_id'] ?? null;
			$user_id       = isset( $input['user_id'] ) ? (int) $input['user_id'] : null;
			$agent_id      = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;
			$per_page      = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
			$offset        = (int) ( $input['offset'] ?? 0 );
			$output_mode   = $input['output_mode'] ?? 'full';
			$search        = isset( $input['search'] ) && '' !== $input['search'] ? sanitize_text_field( $input['search'] ) : null;
			$include_flows = array_key_exists( 'include_flows', $input ) ? (bool) $input['include_flows'] : true;

			if ( ! in_array( $output_mode, array( 'full', 'summary', 'ids' ), true ) ) {
				$output_mode = 'full';
			}

			// Direct pipeline lookup by ID - bypasses pagination.
			// Single-pipeline fetches always embed flows for backward compatibility
			// (this is what callers like the REST /pipelines/{id} endpoint rely on).
			if ( null !== $pipeline_id ) {
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

				$formatted_pipeline = $this->formatPipelineByMode(
					$pipeline,
					$output_mode,
					true
				);

				return array(
					'success'     => true,
					'pipelines'   => array( $formatted_pipeline ),
					'total'       => 1,
					'per_page'    => $per_page,
					'offset'      => $offset,
					'output_mode' => $output_mode,
				);
			}

			// List mode: paginate at the SQL layer instead of loading all rows
			// into memory and slicing. Count runs as a separate COUNT(*) query.
			$pipelines = $this->db_pipelines->get_all_pipelines(
				$user_id,
				$agent_id,
				$search,
				$per_page,
				$offset
			);
			$total     = $this->db_pipelines->get_pipelines_count( $user_id, $agent_id, $search );

			$formatted_pipelines = $this->formatPipelinesByMode(
				$pipelines,
				$output_mode,
				$include_flows
			);

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
