<?php
/**
 * Flow Helpers Trait
 *
 * Shared helper methods used across all Flow ability classes.
 * Provides database access, formatting, validation, and sync operations.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Abilities\FlowStepAbilities;
use DataMachine\Core\Admin\FlowFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;

defined( 'ABSPATH' ) || exit;

trait FlowHelpers {

	protected Flows $db_flows;
	protected Pipelines $db_pipelines;
	protected Jobs $db_jobs;

	protected function initDatabases(): void {
		$this->db_flows     = new Flows();
		$this->db_pipelines = new Pipelines();
		$this->db_jobs      = new Jobs();
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Sync pipeline steps to a flow's configuration.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $steps Array of pipeline step data.
	 * @param array $pipeline_config Full pipeline config.
	 * @return bool Success status.
	 */
	protected function syncStepsToFlow( int $flow_id, int $pipeline_id, array $steps, array $pipeline_config = array() ): bool {
		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow not found for step sync', array( 'flow_id' => $flow_id ) );
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();

		foreach ( $steps as $step ) {
			$pipeline_step_id = $step['pipeline_step_id'] ?? null;
			if ( ! $pipeline_step_id ) {
				continue;
			}

			$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $pipeline_step_id, $flow_id );

			$enabled_tools = $pipeline_config[ $pipeline_step_id ]['enabled_tools'] ?? array();

			$flow_config[ $flow_step_id ] = array(
				'flow_step_id'     => $flow_step_id,
				'step_type'        => $step['step_type'] ?? '',
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $pipeline_id,
				'flow_id'          => $flow_id,
				'execution_order'  => $step['execution_order'] ?? 0,
				'enabled_tools'    => $enabled_tools,
				'handler'          => null,
				'prompt_queue'     => array(),
				'queue_enabled'    => false,
			);
		}

		$success = $this->db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);

		if ( ! $success ) {
			do_action(
				'datamachine_log',
				'error',
				'Flow step sync failed - database update failed',
				array(
					'flow_id'     => $flow_id,
					'steps_count' => count( $steps ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Format flows array based on output mode.
	 *
	 * @param array  $flows Array of flow data.
	 * @param string $output_mode Output mode (full, summary, ids).
	 * @return array Formatted flows.
	 */
	protected function formatFlowsByMode( array $flows, string $output_mode ): array {
		if ( 'ids' === $output_mode ) {
			return $this->formatIds( $flows );
		}

		return array_map(
			function ( $flow ) use ( $output_mode ) {
				return $this->formatFlowByMode( $flow, $output_mode );
			},
			$flows
		);
	}

	/**
	 * Format single flow based on output mode.
	 *
	 * @param array  $flow Flow data.
	 * @param string $output_mode Output mode (full, summary).
	 * @return array Formatted flow.
	 */
	protected function formatFlowByMode( array $flow, string $output_mode ): array {
		if ( 'summary' === $output_mode ) {
			return $this->formatSummary( $flow );
		}

		return $this->formatFull( $flow );
	}

	/**
	 * Format flow with full data including latest job status.
	 *
	 * @param array $flow Flow data.
	 * @return array Formatted flow with full data.
	 */
	protected function formatFull( array $flow ): array {
		$flow_id     = (int) $flow['flow_id'];
		$latest_jobs = $this->db_jobs->get_latest_jobs_by_flow_ids( array( $flow_id ) );
		$latest_job  = $latest_jobs[ $flow_id ] ?? null;

		return FlowFormatter::format_flow_for_response( $flow, $latest_job );
	}

	/**
	 * Format flow with summary fields only.
	 *
	 * @param array $flow Flow data.
	 * @return array Formatted flow summary.
	 */
	protected function formatSummary( array $flow ): array {
		$flow_id     = (int) $flow['flow_id'];
		$latest_jobs = $this->db_jobs->get_latest_jobs_by_flow_ids( array( $flow_id ) );
		$latest_job  = $latest_jobs[ $flow_id ] ?? null;

		return array(
			'flow_id'         => $flow_id,
			'flow_name'       => $flow['flow_name'] ?? '',
			'pipeline_id'     => $flow['pipeline_id'] ?? null,
			'last_run_status' => $latest_job['status'] ?? null,
		);
	}

	/**
	 * Format flows as ID array.
	 *
	 * @param array $flows Array of flow data.
	 * @return array Array of flow IDs.
	 */
	protected function formatIds( array $flows ): array {
		return array_map(
			function ( $flow ) {
				return (int) $flow['flow_id'];
			},
			$flows
		);
	}

	/**
	 * Get all flows with pagination.
	 *
	 * @param int $per_page Items per page.
	 * @param int $offset Pagination offset.
	 * @return array Paginated flows.
	 */
	protected function getAllFlowsPaginated( int $per_page, int $offset ): array {
		$all_pipelines = $this->db_pipelines->get_pipelines_list();
		$all_flows     = array();

		foreach ( $all_pipelines as $pipeline ) {
			$pipeline_flows = $this->db_flows->get_flows_for_pipeline( $pipeline['pipeline_id'] );
			$all_flows      = array_merge( $all_flows, $pipeline_flows );
		}

		return array_slice( $all_flows, $offset, $per_page );
	}

	/**
	 * Count all flows across all pipelines.
	 *
	 * @return int Total flow count.
	 */
	protected function countAllFlows(): int {
		$all_pipelines = $this->db_pipelines->get_pipelines_list();
		$total         = 0;

		foreach ( $all_pipelines as $pipeline ) {
			$total += $this->db_flows->count_flows_for_pipeline( $pipeline['pipeline_id'] );
		}

		return $total;
	}

	/**
	 * Filter flows by handler slug.
	 *
	 * @param array  $flows Array of flow data.
	 * @param string $handler_slug Handler slug to filter by.
	 * @return array Filtered flows.
	 */
	protected function filterByHandlerSlug( array $flows, string $handler_slug ): array {
		return array_filter(
			$flows,
			function ( $flow ) use ( $handler_slug ) {
				$flow_config = $flow['flow_config'] ?? array();

				foreach ( $flow_config as $flow_step_id => $step_data ) {
					if ( ! empty( $step_data['handler_slug'] ) && $step_data['handler_slug'] === $handler_slug ) {
						return true;
					}
				}

				return false;
			}
		);
	}

	/**
	 * Get an interval-only scheduling config for copied flows.
	 *
	 * @param array $scheduling_config Source scheduling config.
	 * @return array Interval-only config.
	 */
	protected function getIntervalOnlySchedulingConfig( array $scheduling_config ): array {
		$interval = $scheduling_config['interval'] ?? 'manual';

		if ( ! is_string( $interval ) || '' === $interval ) {
			$interval = 'manual';
		}

		return array( 'interval' => $interval );
	}

	/**
	 * Validate that two pipelines have compatible step structures.
	 *
	 * @param array $source_config Source pipeline config.
	 * @param array $target_config Target pipeline config.
	 * @return array{compatible: bool, error?: string}
	 */
	protected function validatePipelineCompatibility( array $source_config, array $target_config ): array {
		$source_steps = $this->getOrderedStepTypes( $source_config );
		$target_steps = $this->getOrderedStepTypes( $target_config );

		if ( $source_steps === $target_steps ) {
			return array( 'compatible' => true );
		}

		return array(
			'compatible' => false,
			'error'      => sprintf(
				'Incompatible pipeline structures. Source: [%s], Target: [%s]',
				implode( ', ', $source_steps ),
				implode( ', ', $target_steps )
			),
		);
	}

	/**
	 * Get ordered step types from pipeline config.
	 *
	 * @param array $pipeline_config Pipeline configuration.
	 * @return array Step types ordered by execution_order.
	 */
	protected function getOrderedStepTypes( array $pipeline_config ): array {
		$steps = array_values( $pipeline_config );
		usort( $steps, fn( $a, $b ) => ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 ) );
		return array_map( fn( $s ) => $s['step_type'] ?? '', $steps );
	}

	/**
	 * Build flow config for copied flow, mapping source to target pipeline steps.
	 *
	 * @param array $source_flow_config Source flow configuration.
	 * @param array $source_pipeline_config Source pipeline configuration.
	 * @param array $target_pipeline_config Target pipeline configuration.
	 * @param int   $new_flow_id New flow ID.
	 * @param int   $target_pipeline_id Target pipeline ID.
	 * @param array $overrides Step configuration overrides.
	 * @return array New flow configuration.
	 */
	protected function buildCopiedFlowConfig(
		array $source_flow_config,
		array $source_pipeline_config,
		array $target_pipeline_config,
		int $new_flow_id,
		int $target_pipeline_id,
		array $overrides = array()
	): array {
		$new_flow_config = array();

		$target_steps_by_order = array();
		foreach ( $target_pipeline_config as $pipeline_step_id => $step ) {
			$order                           = $step['execution_order'] ?? 0;
			$target_steps_by_order[ $order ] = array(
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step['step_type'] ?? '',
			);
		}

		$source_steps_by_order = array();
		foreach ( $source_flow_config as $flow_step_id => $step_config ) {
			$order                           = $step_config['execution_order'] ?? 0;
			$source_steps_by_order[ $order ] = $step_config;
		}

		foreach ( $target_steps_by_order as $order => $target_step ) {
			$target_pipeline_step_id = $target_step['pipeline_step_id'];
			$step_type               = $target_step['step_type'];
			$new_flow_step_id        = $target_pipeline_step_id . '_' . $new_flow_id;

			$new_step_config = array(
				'flow_step_id'     => $new_flow_step_id,
				'step_type'        => $step_type,
				'pipeline_step_id' => $target_pipeline_step_id,
				'pipeline_id'      => $target_pipeline_id,
				'flow_id'          => $new_flow_id,
				'execution_order'  => $order,
			);

			if ( isset( $source_steps_by_order[ $order ] ) ) {
				$source_step = $source_steps_by_order[ $order ];

				if ( ! empty( $source_step['handler_slug'] ) ) {
					$new_step_config['handler_slug'] = $source_step['handler_slug'];
				}
				if ( ! empty( $source_step['handler_config'] ) ) {
					$new_step_config['handler_config'] = $source_step['handler_config'];
				}
				if ( ! empty( $source_step['user_message'] ) ) {
					$new_step_config['user_message'] = $source_step['user_message'];
				}
				if ( isset( $source_step['enabled_tools'] ) ) {
					$new_step_config['enabled_tools'] = $source_step['enabled_tools'];
				}
			}

			$override = $this->resolveOverride( $overrides, $step_type, $order );
			if ( $override ) {
				if ( ! empty( $override['handler_slug'] ) ) {
					$new_step_config['handler_slug'] = $override['handler_slug'];
				}
				if ( ! empty( $override['handler_config'] ) ) {
					$existing_config                   = $new_step_config['handler_config'] ?? array();
					$new_step_config['handler_config'] = array_merge( $existing_config, $override['handler_config'] );
				}
				if ( ! empty( $override['user_message'] ) ) {
					$new_step_config['user_message'] = $override['user_message'];
				}
			}

			$new_flow_config[ $new_flow_step_id ] = $new_step_config;
		}

		return $new_flow_config;
	}

	/**
	 * Resolve override config by step_type or execution_order.
	 *
	 * @param array  $overrides Override configurations.
	 * @param string $step_type Step type.
	 * @param int    $execution_order Execution order.
	 * @return array|null Override config or null.
	 */
	protected function resolveOverride( array $overrides, string $step_type, int $execution_order ): ?array {
		if ( isset( $overrides[ $step_type ] ) ) {
			return $overrides[ $step_type ];
		}

		if ( isset( $overrides[ (string) $execution_order ] ) ) {
			return $overrides[ (string) $execution_order ];
		}

		if ( isset( $overrides[ $execution_order ] ) ) {
			return $overrides[ $execution_order ];
		}

		return null;
	}

	/**
	 * Apply step configurations to a newly created flow.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param array $step_configs Configs keyed by step_type.
	 * @return array{applied: array, errors: array}
	 */
	protected function applyStepConfigsToFlow( int $flow_id, array $step_configs ): array {
		$applied = array();
		$errors  = array();

		$flow        = $this->db_flows->get_flow( $flow_id );
		$flow_config = $flow['flow_config'] ?? array();

		$step_type_to_flow_step = array();
		foreach ( $flow_config as $flow_step_id => $step_data ) {
			$step_type = $step_data['step_type'] ?? '';
			if ( ! empty( $step_type ) ) {
				$step_type_to_flow_step[ $step_type ] = $flow_step_id;
			}
		}

		$flow_step_abilities = new FlowStepAbilities();

		foreach ( $step_configs as $step_type => $config ) {
			$flow_step_id = $step_type_to_flow_step[ $step_type ] ?? null;
			if ( ! $flow_step_id ) {
				$errors[] = array(
					'step_type' => $step_type,
					'error'     => "No step of type '{$step_type}' found in flow",
				);
				continue;
			}

			$update_input = array( 'flow_step_id' => $flow_step_id );

			if ( ! empty( $config['handler_slug'] ) ) {
				$update_input['handler_slug'] = $config['handler_slug'];
			}
			if ( ! empty( $config['handler_config'] ) ) {
				$update_input['handler_config'] = $config['handler_config'];
			}
			if ( ! empty( $config['user_message'] ) ) {
				$update_input['user_message'] = $config['user_message'];
			}

			$result = $flow_step_abilities->executeUpdateFlowStep( $update_input );

			if ( $result['success'] ) {
				$applied[] = $flow_step_id;
			} else {
				$errors[] = array(
					'step_type'    => $step_type,
					'flow_step_id' => $flow_step_id,
					'error'        => $result['error'] ?? 'Failed to update step',
				);
			}
		}

		return array(
			'applied' => $applied,
			'errors'  => $errors,
		);
	}
}
