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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
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
}
