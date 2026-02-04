<?php
/**
 * Pipeline Abilities
 *
 * Facade that loads and registers all modular Pipeline ability classes.
 * Maintains backward compatibility by delegating to individual ability instances.
 *
 * @package DataMachine\Abilities
 * @since 0.17.0 Refactored to facade pattern with modular ability classes.
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\Pipeline\GetPipelinesAbility;
use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\Pipeline\UpdatePipelineAbility;
use DataMachine\Abilities\Pipeline\DeletePipelineAbility;
use DataMachine\Abilities\Pipeline\DuplicatePipelineAbility;
use DataMachine\Abilities\Pipeline\ImportExportAbility;

defined( 'ABSPATH' ) || exit;

class PipelineAbilities {

	private static bool $registered = false;

	private GetPipelinesAbility $get_pipelines;
	private CreatePipelineAbility $create_pipeline;
	private UpdatePipelineAbility $update_pipeline;
	private DeletePipelineAbility $delete_pipeline;
	private DuplicatePipelineAbility $duplicate_pipeline;
	private ImportExportAbility $import_export;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->get_pipelines      = new GetPipelinesAbility();
		$this->create_pipeline    = new CreatePipelineAbility();
		$this->update_pipeline    = new UpdatePipelineAbility();
		$this->delete_pipeline    = new DeletePipelineAbility();
		$this->duplicate_pipeline = new DuplicatePipelineAbility();
		$this->import_export      = new ImportExportAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get pipelines ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with pipelines data.
	 */
	public function executeGetPipelines( array $input ): array {
		if ( ! isset( $this->get_pipelines ) ) {
			$this->get_pipelines = new GetPipelinesAbility();
		}
		return $this->get_pipelines->execute( $input );
	}

	/**
	 * Execute create pipeline ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created pipeline data.
	 */
	public function executeCreatePipeline( array $input ): array {
		if ( ! isset( $this->create_pipeline ) ) {
			$this->create_pipeline = new CreatePipelineAbility();
		}
		return $this->create_pipeline->execute( $input );
	}

	/**
	 * Execute update pipeline ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function executeUpdatePipeline( array $input ): array {
		if ( ! isset( $this->update_pipeline ) ) {
			$this->update_pipeline = new UpdatePipelineAbility();
		}
		return $this->update_pipeline->execute( $input );
	}

	/**
	 * Execute delete pipeline ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeletePipeline( array $input ): array {
		if ( ! isset( $this->delete_pipeline ) ) {
			$this->delete_pipeline = new DeletePipelineAbility();
		}
		return $this->delete_pipeline->execute( $input );
	}

	/**
	 * Execute duplicate pipeline ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with duplicated pipeline data.
	 */
	public function executeDuplicatePipeline( array $input ): array {
		if ( ! isset( $this->duplicate_pipeline ) ) {
			$this->duplicate_pipeline = new DuplicatePipelineAbility();
		}
		return $this->duplicate_pipeline->execute( $input );
	}

	/**
	 * Execute import pipelines ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with import summary.
	 */
	public function executeImportPipelines( array $input ): array {
		if ( ! isset( $this->import_export ) ) {
			$this->import_export = new ImportExportAbility();
		}
		return $this->import_export->executeImport( $input );
	}

	/**
	 * Execute export pipelines ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with CSV data.
	 */
	public function executeExportPipelines( array $input ): array {
		if ( ! isset( $this->import_export ) ) {
			$this->import_export = new ImportExportAbility();
		}
		return $this->import_export->executeExport( $input );
	}
}
