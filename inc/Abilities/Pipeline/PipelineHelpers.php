<?php
/**
 * Pipeline Helpers Trait
 *
 * Shared helper methods used across all Pipeline ability classes.
 * Provides database access, formatting, validation, and utility operations.
 *
 * @package DataMachine\Abilities\Pipeline
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Pipeline;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Admin\DateFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;

defined( 'ABSPATH' ) || exit;

trait PipelineHelpers {

	protected Pipelines $db_pipelines;
	protected Flows $db_flows;

	protected function initDatabases(): void {
		$this->db_pipelines = new Pipelines();
		$this->db_flows     = new Flows();
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
	 * Format pipelines based on output mode.
	 *
	 * @param array  $pipelines Pipelines to format.
	 * @param string $output_mode Output mode (full, summary, ids).
	 * @return array Formatted pipelines.
	 */
	protected function formatPipelinesByMode( array $pipelines, string $output_mode ): array {
		if ( 'ids' === $output_mode ) {
			return array_map(
				function ( $pipeline ) {
					return (int) $pipeline['pipeline_id'];
				},
				$pipelines
			);
		}

		return array_map(
			function ( $pipeline ) use ( $output_mode ) {
				return $this->formatPipelineByMode( $pipeline, $output_mode );
			},
			$pipelines
		);
	}

	/**
	 * Format a single pipeline based on output mode.
	 *
	 * @param array  $pipeline Pipeline data.
	 * @param string $output_mode Output mode.
	 * @return array|int Formatted pipeline data or ID.
	 */
	protected function formatPipelineByMode( array $pipeline, string $output_mode ): array|int {
		if ( 'ids' === $output_mode ) {
			return (int) $pipeline['pipeline_id'];
		}

		if ( 'summary' === $output_mode ) {
			return array(
				'pipeline_id'   => (int) $pipeline['pipeline_id'],
				'pipeline_name' => $pipeline['pipeline_name'] ?? '',
				'flow_count'    => $this->db_flows->count_flows_for_pipeline( (int) $pipeline['pipeline_id'] ),
			);
		}

		$pipeline = $this->addDisplayFields( $pipeline );
		$flows    = $this->db_flows->get_flows_for_pipeline( (int) $pipeline['pipeline_id'] );

		return array_merge(
			$pipeline,
			array( 'flows' => $flows )
		);
	}

	/**
	 * Add formatted display fields for timestamps.
	 *
	 * @param array $pipeline Pipeline data.
	 * @return array Pipeline data with *_display fields added.
	 */
	protected function addDisplayFields( array $pipeline ): array {
		if ( isset( $pipeline['created_at'] ) ) {
			$pipeline['created_at_display'] = DateFormatter::format_for_display( $pipeline['created_at'] );
		}

		if ( isset( $pipeline['updated_at'] ) ) {
			$pipeline['updated_at_display'] = DateFormatter::format_for_display( $pipeline['updated_at'] );
		}

		return $pipeline;
	}

	/**
	 * Validate steps array.
	 *
	 * @param array $steps Steps to validate.
	 * @return bool|string True if valid, error message if not.
	 */
	protected function validateSteps( array $steps ): bool|string {
		$step_type_abilities = new StepTypeAbilities();
		$valid_types         = array_keys( $step_type_abilities->getAllStepTypes() );

		foreach ( $steps as $index => $step ) {
			// Accept shorthand: "event_import" → {"step_type": "event_import"}
			if ( is_string( $step ) ) {
				$step = array( 'step_type' => $step );
			}

			if ( ! is_array( $step ) ) {
				return "Step at index {$index} must be a string or object";
			}

			$step_type = $step['step_type'] ?? null;
			if ( empty( $step_type ) ) {
				return "Step at index {$index} is missing required step_type";
			}

			if ( ! in_array( $step_type, $valid_types, true ) ) {
				return "Step at index {$index} has invalid step_type '{$step_type}'. Must be one of: " . implode( ', ', $valid_types );
			}
		}

		return true;
	}

	/**
	 * Validate handler slugs in steps array.
	 *
	 * @param array $steps Steps to validate.
	 * @return true|array True if valid, error array if not.
	 */
	protected function validateHandlerSlugs( array $steps ): bool|array {
		$handler_abilities = new HandlerAbilities();

		foreach ( $steps as $index => $step ) {
			$handler_slug = $step['handler_slug'] ?? null;
			if ( empty( $handler_slug ) ) {
				continue;
			}

			if ( ! $handler_abilities->handlerExists( $handler_slug ) ) {
				return array(
					'success'     => false,
					'error'       => "Step at index {$index} has invalid handler_slug '{$handler_slug}'",
					'remediation' => 'Use list_handlers tool to find valid handler slugs',
				);
			}
		}

		return true;
	}

	/**
	 * Map flow config from source to new pipeline.
	 *
	 * @param array $source_flow_config Source flow configuration.
	 * @param array $step_id_mapping Map of old step IDs to new step IDs.
	 * @param int   $new_flow_id New flow ID.
	 * @param int   $new_pipeline_id New pipeline ID.
	 * @return array New flow configuration.
	 */
	protected function mapFlowConfig(
		array $source_flow_config,
		array $step_id_mapping,
		int $new_flow_id,
		int $new_pipeline_id
	): array {
		$new_flow_config = array();

		foreach ( $source_flow_config as $old_flow_step_id => $step_config ) {
			$old_pipeline_step_id = $step_config['pipeline_step_id'] ?? null;
			if ( ! $old_pipeline_step_id || ! isset( $step_id_mapping[ $old_pipeline_step_id ] ) ) {
				continue;
			}

			$new_pipeline_step_id = $step_id_mapping[ $old_pipeline_step_id ];
			$new_flow_step_id     = apply_filters( 'datamachine_generate_flow_step_id', '', $new_pipeline_step_id, $new_flow_id );

			$new_step_config                     = $step_config;
			$new_step_config['flow_step_id']     = $new_flow_step_id;
			$new_step_config['pipeline_step_id'] = $new_pipeline_step_id;
			$new_step_config['pipeline_id']      = $new_pipeline_id;
			$new_step_config['flow_id']          = $new_flow_id;

			$new_flow_config[ $new_flow_step_id ] = $new_step_config;
		}

		return $new_flow_config;
	}
}
