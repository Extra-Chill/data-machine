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

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\FlowStep\UpdateFlowStepAbility;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Admin\FlowFormatter;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\FlowStepConfigFactory;
use DataMachine\Core\Steps\FlowStepTargetResolver;

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
		return PermissionHelper::can_manage();
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

			$step_config = FlowStepConfigFactory::buildFromPipelineStep(
				$step,
				$pipeline_id,
				$flow_id,
				$flow_step_id,
				$pipeline_config[ $pipeline_step_id ] ?? array()
			);

			$flow_config[ $flow_step_id ] = $step_config;
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
	 * Batches both latest-job and next-run lookups into single queries (N+1 → 2).
	 *
	 * @param array  $flows Array of flow data.
	 * @param string $output_mode Output mode (full, summary, ids).
	 * @return array Formatted flows.
	 */
	protected function formatFlowsByMode( array $flows, string $output_mode ): array {
		if ( 'ids' === $output_mode ) {
			return $this->formatIds( $flows );
		}

		// Batch-fetch latest jobs and next-run times for all flows.
		$flow_ids    = array_map( fn( $f ) => (int) $f['flow_id'], $flows );
		$latest_jobs = ! empty( $flow_ids )
			? $this->db_jobs->get_latest_jobs_by_flow_ids( $flow_ids )
			: array();

		$next_runs = ( in_array( $output_mode, array( 'full', 'list' ), true ) && ! empty( $flow_ids ) )
			? FlowFormatter::batch_get_next_run_times( $flow_ids )
			: array();

		return array_map(
			function ( $flow ) use ( $output_mode, $latest_jobs, $next_runs ) {
				return $this->formatFlowByMode( $flow, $output_mode, $latest_jobs, $next_runs );
			},
			$flows
		);
	}

	/**
	 * Format single flow based on output mode.
	 *
	 * @param array      $flow Flow data.
	 * @param string     $output_mode Output mode (full, summary).
	 * @param array|null $latest_jobs Pre-fetched latest jobs keyed by flow_id.
	 * @param array|null $next_runs   Pre-fetched next run times keyed by flow_id.
	 * @return array Formatted flow.
	 */
	protected function formatFlowByMode( array $flow, string $output_mode, ?array $latest_jobs = null, ?array $next_runs = null ) {
		if ( 'ids' === $output_mode ) {
			return (int) $flow['flow_id'];
		}

		if ( 'summary' === $output_mode ) {
			return $this->formatSummary( $flow, $latest_jobs );
		}

		if ( 'list' === $output_mode ) {
			return $this->formatList( $flow, $latest_jobs, $next_runs );
		}

		return $this->formatFull( $flow, $latest_jobs, $next_runs );
	}

	/**
	 * Format flow with full data including latest job status.
	 *
	 * @param array      $flow Flow data.
	 * @param array|null $latest_jobs Pre-fetched latest jobs keyed by flow_id (avoids N+1).
	 * @param array|null $next_runs   Pre-fetched next run times keyed by flow_id (avoids N+1).
	 * @return array Formatted flow with full data.
	 */
	protected function formatFull( array $flow, ?array $latest_jobs = null, ?array $next_runs = null ): array {
		$flow_id = (int) $flow['flow_id'];

		if ( null === $latest_jobs ) {
			$latest_jobs = $this->db_jobs->get_latest_jobs_by_flow_ids( array( $flow_id ) );
		}

		$latest_job = $latest_jobs[ $flow_id ] ?? null;

		return FlowFormatter::format_flow_for_response( $flow, $latest_job, $next_runs );
	}

	/**
	 * Format flow with summary fields only.
	 *
	 * @param array      $flow Flow data.
	 * @param array|null $latest_jobs Pre-fetched latest jobs keyed by flow_id (avoids N+1).
	 * @return array Formatted flow summary.
	 */
	protected function formatSummary( array $flow, ?array $latest_jobs = null ): array {
		$flow_id = (int) $flow['flow_id'];

		if ( null === $latest_jobs ) {
			$latest_jobs = $this->db_jobs->get_latest_jobs_by_flow_ids( array( $flow_id ) );
		}

		$latest_job = $latest_jobs[ $flow_id ] ?? null;

		return array(
			'flow_id'         => $flow_id,
			'flow_name'       => $flow['flow_name'] ?? '',
			'pipeline_id'     => $flow['pipeline_id'] ?? null,
			'last_run_status' => $latest_job['status'] ?? null,
		);
	}

	/**
	 * Format flow for list views — includes flow_config and scheduling
	 * but skips the expensive handler settings enrichment in FlowFormatter.
	 *
	 * @param array       $flow Flow data.
	 * @param array|null  $latest_jobs Pre-fetched latest jobs keyed by flow_id.
	 * @param array|null  $next_runs   Pre-fetched next run times keyed by flow_id.
	 * @return array Flow data with status fields added.
	 */
	protected function formatList( array $flow, ?array $latest_jobs = null, ?array $next_runs = null ): array {
		$flow_id    = (int) $flow['flow_id'];
		$latest_job = null !== $latest_jobs ? ( $latest_jobs[ $flow_id ] ?? null ) : null;

		$scheduling_config = $flow['scheduling_config'] ?? array();
		$last_run_at       = $latest_job['created_at'] ?? null;
		$is_enabled        = \DataMachine\Core\Database\Flows\Flows::is_flow_enabled( $scheduling_config );

		$next_run = null;
		if ( null !== $next_runs && array_key_exists( $flow_id, $next_runs ) ) {
			$next_run = $next_runs[ $flow_id ];
		}

		return array(
			'flow_id'           => $flow_id,
			'flow_name'         => $flow['flow_name'] ?? '',
			'pipeline_id'       => isset( $flow['pipeline_id'] ) ? (int) $flow['pipeline_id'] : null,
			'flow_config'       => $flow['flow_config'] ?? array(),
			'scheduling_config' => $scheduling_config,
			'enabled'           => $is_enabled,
			'last_run'          => $last_run_at,
			'last_run_status'   => $latest_job['status'] ?? null,
			'last_run_display'  => \DataMachine\Core\Admin\DateFormatter::format_for_display( $last_run_at ),
			'is_running'        => $latest_job && null === $latest_job['completed_at'],
			'next_run'          => $next_run,
			'next_run_display'  => \DataMachine\Core\Admin\DateFormatter::format_for_display( $next_run ),
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
	 * Uses a single DB query with LIMIT/OFFSET instead of loading all flows
	 * across all pipelines and slicing in PHP.
	 *
	 * @param int      $per_page Items per page.
	 * @param int      $offset   Pagination offset.
	 * @param int|null $user_id  Optional user ID filter.
	 * @param int|null $agent_id Optional agent ID filter (takes priority over user_id).
	 * @return array Paginated flows.
	 */
	protected function getAllFlowsPaginated( int $per_page, int $offset, ?int $user_id = null, ?int $agent_id = null ): array {
		return $this->db_flows->get_all_flows_paginated( $per_page, $offset, $user_id, $agent_id );
	}

	/**
	 * Count all flows with optional user/agent filter.
	 *
	 * Single COUNT(*) query instead of loading all pipelines and summing per-pipeline counts.
	 *
	 * @param int|null $user_id  Optional user ID filter.
	 * @param int|null $agent_id Optional agent ID filter (takes priority over user_id).
	 * @return int Total flow count.
	 */
	protected function countAllFlows( ?int $user_id = null, ?int $agent_id = null ): int {
		return $this->db_flows->count_all_flows( $user_id, $agent_id );
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
					$handler_slugs = FlowStepConfig::getConfiguredHandlerSlugs( $step_data );
					if ( in_array( $handler_slug, $handler_slugs, true ) ) {
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

				// Copy canonical handler fields verbatim. Single-handler steps use
				// handler_slug/handler_config; multi-handler steps use
				// handler_slugs/handler_configs; handler-free steps only carry
				// handler_config when they have step-level settings.
				foreach ( array( 'handler_slug', 'handler_slugs', 'handler_config', 'handler_configs' ) as $handler_field ) {
					if ( array_key_exists( $handler_field, $source_step ) ) {
						$new_step_config[ $handler_field ] = $source_step[ $handler_field ];
					}
				}

				// Queue state copies verbatim (#1291 / #1292): AI steps
				// own prompt_queue, fetch steps own config_patch_queue,
				// and queue_mode applies to whichever slot the step
				// type consumes. Pre-fix this lane copied the legacy
				// `user_message` field — that slot is gone and the
				// per-flow user message lives in prompt_queue head.
				if ( isset( $source_step['prompt_queue'] ) && is_array( $source_step['prompt_queue'] ) ) {
					$new_step_config['prompt_queue'] = $source_step['prompt_queue'];
				}
				if ( isset( $source_step['config_patch_queue'] ) && is_array( $source_step['config_patch_queue'] ) ) {
					$new_step_config['config_patch_queue'] = $source_step['config_patch_queue'];
				}
				if ( isset( $source_step['queue_mode'] )
					&& in_array( $source_step['queue_mode'], array( 'drain', 'loop', 'static' ), true )
				) {
					$new_step_config['queue_mode'] = $source_step['queue_mode'];
				}

				if ( isset( $source_step['disabled_tools'] ) ) {
					$new_step_config['disabled_tools'] = $source_step['disabled_tools'];
				}
			}

			$override = $this->resolveOverride( $overrides, $step_type, $order );
			if ( $override ) {
				if ( ! empty( $override['handler_slug'] ) ) {
					$handler_config = $override['handler_config'] ?? array();
					if ( FlowStepConfig::isMultiHandler( $new_step_config ) ) {
						$new_step_config['handler_slugs']   = array( $override['handler_slug'] );
						$new_step_config['handler_configs'] = array( $override['handler_slug'] => $handler_config );
						unset( $new_step_config['handler_slug'], $new_step_config['handler_config'] );
					} else {
						$new_step_config['handler_slug']   = $override['handler_slug'];
						$new_step_config['handler_config'] = $handler_config;
						unset( $new_step_config['handler_slugs'], $new_step_config['handler_configs'] );
					}
				} elseif ( ! empty( $override['handler_config'] ) ) {
					if ( FlowStepConfig::isMultiHandler( $new_step_config ) ) {
						$primary_slug = FlowStepConfig::getPrimaryHandlerSlug( $new_step_config );
						if ( null !== $primary_slug ) {
							$existing_config                                      = $new_step_config['handler_configs'][ $primary_slug ] ?? array();
							$new_step_config['handler_configs'][ $primary_slug ] = array_merge( $existing_config, $override['handler_config'] );
						}
					} else {
						$existing_config                   = FlowStepConfig::getPrimaryHandlerConfig( $new_step_config );
						$new_step_config['handler_config'] = array_merge( $existing_config, $override['handler_config'] );
					}
				}
				// Override user_message arrives as a workflow-spec input
				// (matches the public contract used by `flow copy` and
				// chat tools). Convert it to a 1-entry static
				// prompt_queue so AIStep sees it post-#1291.
				if ( ! empty( $override['user_message'] ) ) {
					$new_step_config['prompt_queue'] = array(
						array(
							'prompt'   => $override['user_message'],
							'added_at' => gmdate( 'c' ),
						),
					);
					$new_step_config['queue_mode']   = 'static';
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

		$update_flow_step_ability = new UpdateFlowStepAbility();

		foreach ( $step_configs as $step_key => $config ) {
			$config = is_array( $config ) ? $config : array();

			$target = FlowStepTargetResolver::resolve( $flow_config, (string) $step_key, $config );
			if ( empty( $target['success'] ) ) {
				$errors[] = $target['error'];
				continue;
			}

			$flow_step_id = $target['flow_step_id'];
			$step_type    = $target['step_type'] ?? (string) $step_key;

			// Multi-handler configs use handler_slugs/handler_configs; single-handler
			// and handler-free configs use handler_slug/handler_config.
			$handler_slugs   = $config['handler_slugs'] ?? array();
			$handler_configs = $config['handler_configs'] ?? array();

			// Scalar forms go through UpdateFlowStepAbility.
			$single_slug   = $config['handler_slug'] ?? '';
			$single_config = $config['handler_config'] ?? array();

			// When handler_slugs is provided, add each handler with its config from handler_configs.
			if ( ! empty( $handler_slugs ) ) {
				foreach ( $handler_slugs as $slug ) {
					$slug_config = $handler_configs[ $slug ] ?? array();
					$add_result  = $update_flow_step_ability->execute(
						array(
							'flow_step_id'       => $flow_step_id,
							'add_handler'        => $slug,
							'add_handler_config' => $slug_config,
						)
					);
					if ( ! $add_result['success'] ) {
						$errors[] = array(
							'step_type'    => $step_type,
							'flow_step_id' => $flow_step_id,
							'handler'      => $slug,
							'error'        => $add_result['error'] ?? 'Failed to add handler',
						);
					}
				}
			}

			// Build the base update input for singular handler_slug / handler_config / user_message.
			$update_input = array( 'flow_step_id' => $flow_step_id );

			if ( ! empty( $single_slug ) ) {
				$update_input['handler_slug'] = $single_slug;
			}
			if ( ! empty( $single_config ) ) {
				$update_input['handler_config'] = $single_config;
			}
			if ( ! empty( $config['user_message'] ) ) {
				$update_input['user_message'] = $config['user_message'];
			}

			// Only call update if there's something beyond the flow_step_id to apply.
			if ( count( $update_input ) <= 1 && ! empty( $handler_slugs ) ) {
				// Already handled via add_handler above — mark as applied.
				$applied[] = $flow_step_id;
				continue;
			}

			$result = $update_flow_step_ability->execute( $update_input );

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

	/**
	 * Apply site handler defaults to unconfigured flow steps.
	 *
	 * For each step without a handler_slug, looks up handlers for that step_type
	 * and applies site defaults from the first handler that has them configured.
	 *
	 * @param int   $flow_id Flow ID.
	 * @param array $configured_step_types Step types that were explicitly configured.
	 * @return array{applied: array, skipped: array}
	 */
	protected function applySiteDefaultsToUnconfiguredSteps( int $flow_id, array $configured_step_types ): array {
		$applied = array();
		$skipped = array();

		$flow        = $this->db_flows->get_flow( $flow_id );
		$flow_config = $flow['flow_config'] ?? array();

		$handler_abilities = new HandlerAbilities();
		$site_defaults     = $handler_abilities->getSiteDefaults();

		if ( empty( $site_defaults ) ) {
			return array(
				'applied' => $applied,
				'skipped' => array_keys( $flow_config ),
			);
		}

		$update_flow_step_ability = new UpdateFlowStepAbility();

		foreach ( $flow_config as $flow_step_id => $step_data ) {
			$step_type = $step_data['step_type'] ?? '';

			if ( in_array( $step_type, $configured_step_types, true ) ) {
				continue;
			}

			// Skip if handler already configured.
			if ( ! empty( FlowStepConfig::getConfiguredHandlerSlugs( $step_data ) ) ) {
				continue;
			}

			$handlers = $handler_abilities->getAllHandlers( $step_type );

			if ( empty( $handlers ) ) {
				$skipped[] = $flow_step_id;
				continue;
			}

			$default_handler_slug   = null;
			$default_handler_config = array();

			foreach ( $handlers as $handler_slug => $handler_def ) {
				if ( isset( $site_defaults[ $handler_slug ] ) && ! empty( $site_defaults[ $handler_slug ] ) ) {
					$default_handler_slug   = $handler_slug;
					$default_handler_config = $site_defaults[ $handler_slug ];
					break;
				}
			}

			if ( ! $default_handler_slug ) {
				$skipped[] = $flow_step_id;
				continue;
			}

			$result = $update_flow_step_ability->execute(
				array(
					'flow_step_id'   => $flow_step_id,
					'handler_slug'   => $default_handler_slug,
					'handler_config' => $default_handler_config,
				)
			);

			if ( $result['success'] ) {
				$applied[] = $flow_step_id;
			} else {
				$skipped[] = $flow_step_id;
			}
		}

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}
}
