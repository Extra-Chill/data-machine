<?php
/**
 * Import/Export Pipeline Ability
 *
 * Handles pipeline import and export operations via CSV format.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;

use DataMachine\Engine\Actions\ImportExport;

defined( 'ABSPATH' ) || exit;

class ImportExportAbility {

	use PipelineHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbilities();
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerImportAbility();
			$this->registerExportAbility();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerImportAbility(): void {
		wp_register_ability(
			'datamachine/import-pipelines',
			array(
				'label'               => __( 'Import Pipelines', 'data-machine' ),
				'description'         => __( 'Import pipelines from CSV data.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'data' ),
					'properties' => array(
						'data'   => array(
							'type'        => 'string',
							'description' => __( 'CSV data to import', 'data-machine' ),
						),
						'format' => array(
							'type'        => 'string',
							'enum'        => array( 'csv' ),
							'default'     => 'csv',
							'description' => __( 'Import format (csv)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'imported' => array( 'type' => 'array' ),
						'count'    => array( 'type' => 'integer' ),
						'message'  => array( 'type' => 'string' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeImport' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerExportAbility(): void {
		wp_register_ability(
			'datamachine/export-pipelines',
			array(
				'label'               => __( 'Export Pipelines', 'data-machine' ),
				'description'         => __( 'Export pipelines to CSV format.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'pipeline_ids' => array(
							'type'        => 'array',
							'description' => __( 'Pipeline IDs to export (empty for all)', 'data-machine' ),
						),
						'format'       => array(
							'type'        => 'string',
							'enum'        => array( 'csv' ),
							'default'     => 'csv',
							'description' => __( 'Export format (csv)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'data'    => array( 'type' => 'string' ),
						'count'   => array( 'type' => 'integer' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeExport' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute import pipelines ability.
	 *
	 * @param array $input Input parameters with CSV data.
	 * @return array Result with import summary.
	 */
	public function executeImport( array $input ): array {
		$data = $input['data'] ?? null;

		if ( empty( $data ) ) {
			return array(
				'success' => false,
				'error'   => 'data is required',
			);
		}

		$import_export = new ImportExport();
		$result        = $import_export->handle_import( 'pipelines', $data );

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => 'Import failed',
			);
		}

		$imported = $result['imported'] ?? array();

		return array(
			'success'  => true,
			'imported' => $imported,
			'count'    => count( $imported ),
			'message'  => sprintf( 'Successfully imported %d pipeline(s)', count( $imported ) ),
		);
	}

	/**
	 * Execute export pipelines ability.
	 *
	 * @param array $input Input parameters with optional pipeline_ids.
	 * @return array Result with CSV data.
	 */
	public function executeExport( array $input ): array {
		$pipeline_ids = $input['pipeline_ids'] ?? array();

		if ( empty( $pipeline_ids ) ) {
			$all_pipelines = $this->db_pipelines->get_all_pipelines();
			$pipeline_ids  = array_column( $all_pipelines, 'pipeline_id' );
		}

		if ( empty( $pipeline_ids ) ) {
			return array(
				'success' => true,
				'data'    => '',
				'count'   => 0,
			);
		}

		$import_export = new ImportExport();
		$csv_content   = $import_export->handle_export( 'pipelines', $pipeline_ids );

		if ( false === $csv_content ) {
			return array(
				'success' => false,
				'error'   => 'Export failed',
			);
		}

		return array(
			'success' => true,
			'data'    => $csv_content,
			'count'   => count( $pipeline_ids ),
		);
	}

	public function execute( array $input ): array {
		try {
			$pipeline_id = $input['pipeline_id'] ?? null;
			$user_id     = isset( $input['user_id'] ) ? (int) $input['user_id'] : null;
			$agent_id    = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;
			$per_page    = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );
			$offset      = (int) ( $input['offset'] ?? 0 );
			$output_mode = $input['output_mode'] ?? 'full';

			if ( ! in_array( $output_mode, array( 'full', 'summary', 'ids' ), true ) ) {
				$output_mode = 'full';
			}

			// Direct pipeline lookup by ID - bypasses pagination.
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

			$all_pipelines = $this->db_pipelines->get_all_pipelines( $user_id, $agent_id );
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}
}
