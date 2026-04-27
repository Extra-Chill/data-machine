<?php
/**
 * Execute Step Ability
 *
 * Executes a single step in a pipeline flow. Resolves step configuration,
 * runs the step class, evaluates success, and routes to the appropriate
 * outcome: next step, job completion, or failure.
 *
 * Backs the datamachine_execute_step action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\FilesRepository\FileRetrieval;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\StepNavigator;

defined( 'ABSPATH' ) || exit;

class ExecuteStepAbility {

	use EngineHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/execute-step ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/execute-step',
				array(
					'label'               => __( 'Execute Step', 'data-machine' ),
					'description'         => __( 'Execute a single pipeline step. Resolves config, runs the step, routes to next step or completion.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'job_id', 'flow_step_id' ),
						'properties' => array(
							'job_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Job ID for the execution.', 'data-machine' ),
							),
							'flow_step_id' => array(
								'type'        => 'string',
								'description' => __( 'Flow step ID to execute.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'step_success' => array( 'type' => 'boolean' ),
							'outcome'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
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
	 * Execute the execute-step ability.
	 *
	 * @param array $input Input with job_id and flow_step_id.
	 * @return array Result with step execution outcome.
	 */
	public function execute( array $input ): array {
		$job_id       = (int) ( $input['job_id'] ?? 0 );
		$flow_step_id = (string) ( $input['flow_step_id'] ?? '' );

		// Transition job to 'processing' now that Action Scheduler is actually
		// executing it. For parent jobs this is a no-op (already processing via
		// RunFlowAbility). For batch child jobs this is the real transition from
		// 'pending' → 'processing', ensuring recover-stuck only catches jobs
		// that genuinely started but never finished.
		$this->db_jobs->start_job( $job_id );

		try {
			$engine_snapshot = datamachine_get_engine_data( $job_id );
			$engine          = new EngineData( $engine_snapshot, $job_id );

			$flow_step_config = $this->resolveFlowStepConfig( $engine, $flow_step_id, $job_id, $engine_snapshot );

			if ( ! isset( $flow_step_config['flow_id'] ) || empty( $flow_step_config['flow_id'] ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'reason'       => 'missing_flow_id_in_step_config',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Missing flow_id in step config.',
				);
			}

			$flow_id = $flow_step_config['flow_id'];

			/** @var array $context */
			$context     = datamachine_get_file_context( $flow_id );
			$retrieval   = new FileRetrieval();
			$dataPackets = $retrieval->retrieve_data_by_job_id( $job_id, $context );

			if ( ! isset( $flow_step_config['step_type'] ) || empty( $flow_step_config['step_type'] ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'reason'       => 'missing_step_type_in_flow_step_config',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Missing step_type in flow step config.',
				);
			}

			$step_type       = $flow_step_config['step_type'];
			$step_definition = $this->resolveStepDefinition( $step_type, $flow_step_id, $job_id );

			if ( ! $step_definition ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Step type "%s" not found in registry.', $step_type ),
				);
			}

			$step_class = $step_definition['class'] ?? '';
			$flow_step  = new $step_class();

			$payload = array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
				'data'         => $dataPackets,
				'engine'       => $engine,
			);

			$dataPackets = $flow_step->execute( $payload );

			if ( ! is_array( $dataPackets ) ) {
				do_action(
					'datamachine_fail_job',
					$job_id,
					'step_execution_failure',
					array(
						'flow_step_id' => $flow_step_id,
						'class'        => $step_class,
						'reason'       => 'non_array_payload_returned',
					)
				);
				return array(
					'success' => false,
					'error'   => 'Step returned non-array payload.',
				);
			}

			$payload['data'] = $dataPackets;
			$step_success    = $this->evaluateStepSuccess( $dataPackets, $job_id, $flow_step_id );

			// Refresh engine data to capture changes made during step execution.
			$refreshed_engine_data = datamachine_get_engine_data( $job_id );
			$engine                = new EngineData( $refreshed_engine_data, $job_id );
			$status_override       = $engine->get( 'job_status' );

			do_action(
				'datamachine_log',
				'debug',
				'Engine: status_override check',
				array(
					'job_id'                 => $job_id,
					'status_override'        => $status_override,
					'has_override'           => ! empty( $status_override ),
					'engine_data_job_status' => $refreshed_engine_data['job_status'] ?? 'not_set',
				)
			);

			return $this->routeAfterExecution(
				$job_id,
				$flow_step_id,
				$flow_id,
				$flow_step_config,
				$step_type,
				$step_class,
				$dataPackets,
				$payload,
				$step_success,
				$status_override
			);
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_fail_job',
				$job_id,
				'step_execution_failure',
				array(
					'flow_step_id'      => $flow_step_id,
					'exception_message' => $e->getMessage(),
					'exception_trace'   => $e->getTraceAsString(),
					'reason'            => 'throwable_exception_in_step_execution',
				)
			);
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Resolve flow step config, falling back to database lookup if missing from snapshot.
	 *
	 * @param EngineData $engine          Engine data instance.
	 * @param string     $flow_step_id    Flow step ID.
	 * @param int        $job_id          Job ID.
	 * @param array      $engine_snapshot Raw engine snapshot data.
	 * @return array|null Flow step config or null.
	 */
	private function resolveFlowStepConfig( EngineData $engine, string $flow_step_id, int $job_id, array $engine_snapshot ): ?array {
		$flow_step_config = $engine->getFlowStepConfig( $flow_step_id );

		if ( ! $flow_step_config ) {
			$flow_step_config = $this->db_flows->get_flow_step_config( $flow_step_id, $job_id, true );

			if ( $flow_step_config ) {
				$existing_flow_config                  = $engine_snapshot['flow_config'] ?? array();
				$existing_flow_config[ $flow_step_id ] = $flow_step_config;
				datamachine_merge_engine_data(
					$job_id,
					array(
						'flow_config' => $existing_flow_config,
					)
				);
			}
		}

		return $flow_step_config;
	}

	/**
	 * Resolve and validate step type definition from the abilities registry.
	 *
	 * @param string $step_type    Step type identifier.
	 * @param string $flow_step_id Flow step ID (for error context).
	 * @param int    $job_id       Job ID (for error context).
	 * @return array|null Step definition or null on failure.
	 */
	private function resolveStepDefinition( string $step_type, string $flow_step_id, int $job_id ): ?array {
		$step_type_abilities = new StepTypeAbilities();
		$step_definition     = $step_type_abilities->getStepType( $step_type );

		if ( ! $step_definition ) {
			do_action(
				'datamachine_fail_job',
				$job_id,
				'step_execution_failure',
				array(
					'flow_step_id' => $flow_step_id,
					'step_type'    => $step_type,
					'reason'       => 'step_type_not_found_in_registry',
				)
			);
			return null;
		}

		return $step_definition;
	}

	/**
	 * Evaluate whether the step execution was successful.
	 *
	 * @param array  $dataPackets  Returned data packets.
	 * @param int    $job_id       Job ID (for logging).
	 * @param string $flow_step_id Flow step ID (for logging).
	 * @return bool True if step succeeded.
	 */
	private function evaluateStepSuccess( array $dataPackets, int $job_id, string $flow_step_id ): bool {
		$step_success = ! empty( $dataPackets );

		if ( $step_success ) {
			foreach ( $dataPackets as $packet ) {
				$metadata = $packet['metadata'] ?? array();
				if ( isset( $metadata['success'] ) && false === $metadata['success'] ) {
					$step_success = false;
					do_action(
						'datamachine_log',
						'warning',
						'Step returned failure packet',
						array(
							'job_id'        => $job_id,
							'flow_step_id'  => $flow_step_id,
							'packet_type'   => $packet['type'] ?? 'unknown',
							'error_message' => $packet['data']['body'] ?? 'No error message',
						)
					);
					break;
				}
			}
		}

		return $step_success;
	}

	/**
	 * Route execution after step completes.
	 *
	 * @param int    $job_id           Job ID.
	 * @param string $flow_step_id     Flow step ID.
	 * @param mixed  $flow_id          Flow ID.
	 * @param array  $flow_step_config Flow step configuration.
	 * @param string $step_type        Step type identifier.
	 * @param string $step_class       Step class name.
	 * @param array  $dataPackets      Returned data packets.
	 * @param array  $payload          Full step payload.
	 * @param bool   $step_success     Whether step succeeded.
	 * @param mixed  $status_override  Status override from engine data.
	 * @return array Result with outcome details.
	 */
	private function routeAfterExecution(
		int $job_id,
		string $flow_step_id,
		$flow_id,
		array $flow_step_config,
		string $step_type,
		string $step_class,
		array $dataPackets,
		array $payload,
		bool $step_success,
		$status_override
	): array {
		$pipeline_id = $flow_step_config['pipeline_id'] ?? null;

		// Waiting status: pipeline is parked at a webhook gate.
		if ( $status_override && JobStatus::isStatusWaiting( $status_override ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Pipeline parked in waiting state (webhook gate)',
				array(
					'job_id'       => $job_id,
					'pipeline_id'  => $pipeline_id,
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
				)
			);
			return array(
				'success'      => true,
				'step_success' => $step_success,
				'outcome'      => 'waiting',
			);
		}

		// Status override: complete with override status and clean up.
		if ( $status_override ) {
			// Mark item as processed if the override indicates successful completion
			// (e.g. agent_skipped is intentional, completed is success).
			// Failed overrides should NOT mark items as processed.
			if ( str_starts_with( $status_override, JobStatus::FAILED ) === false ) {
				$this->markCompletedItemProcessed( $job_id );
			}
			$this->db_jobs->complete_job( $job_id, $status_override );

			do_action(
				'datamachine_log',
				'debug',
				'Engine: complete_job called with status_override',
				array(
					'job_id' => $job_id,
					'status' => $status_override,
				)
			);

			$cleanup = new FileCleanup();
			$context = datamachine_get_file_context( $flow_id );
			$cleanup->cleanup_job_data_packets( $job_id, $context );

			do_action(
				'datamachine_log',
				'info',
				'Pipeline execution completed with status override',
				array(
					'job_id'          => $job_id,
					'pipeline_id'     => $pipeline_id,
					'flow_id'         => $flow_id,
					'flow_step_id'    => $flow_step_id,
					'final_status'    => $status_override,
					'override_source' => 'engine_data',
				)
			);

			return array(
				'success'      => true,
				'step_success' => $step_success,
				'outcome'      => 'completed_override',
			);
		}

		// Success: advance to next step or complete.
		if ( $step_success ) {
			$navigator         = new StepNavigator();
			$next_flow_step_id = $navigator->get_next_flow_step_id( $flow_step_id, $payload );

			if ( $next_flow_step_id ) {
				$packet_count = count( $dataPackets );

				// Inline continuation: when a step produces 0-1 DataPackets,
				// schedule the next step directly on the same job instead of
				// creating child jobs. This eliminates recursive fan-out where
				// children spawn grandchildren (e.g., AI step → upsert step).
				//
				// Fan-out is only meaningful when a step produces MULTIPLE
				// packets that need parallel processing (e.g., fetch step
				// producing one packet per event). A single packet is just
				// the same job continuing to the next step.
				if ( $packet_count <= 1 ) {
					// For fetch/event_import steps with a single item (inline continuation),
					// seed dedup context into engine_data so markCompletedItemProcessed()
					// can find it when the last step completes. For fan-out children this
					// is handled in PipelineBatchScheduler::createChildJob().
					if ( in_array( $step_type, array( 'fetch', 'event_import' ), true ) && ! empty( $dataPackets ) ) {
						$packet_meta = $dataPackets[0]['metadata'] ?? array();
						$seed_data   = array();
						if ( ! empty( $packet_meta['item_identifier'] ) ) {
							$seed_data['item_identifier'] = $packet_meta['item_identifier'];
						}
						if ( ! empty( $packet_meta['source_type'] ) ) {
							$seed_data['source_type'] = $packet_meta['source_type'];
						}
						if ( ! empty( $seed_data ) ) {
							datamachine_merge_engine_data( $job_id, $seed_data );
						}
					}

					do_action(
						'datamachine_schedule_next_step',
						$job_id,
						$next_flow_step_id,
						$dataPackets
					);

					return array(
						'success'      => true,
						'step_success' => true,
						'outcome'      => 'inline_continuation',
					);
				}

				$engine                = $payload['engine'] ?? null;
				$next_flow_step_config = $engine instanceof EngineData ? $engine->getFlowStepConfig( $next_flow_step_id ) : array();
				$transition_route      = self::resolveTransitionRoute( $flow_step_config, $next_flow_step_config, $dataPackets );

				if ( 'fail' === $transition_route['mode'] ) {
					do_action(
						'datamachine_fail_job',
						$job_id,
						'step_execution_failure',
						array(
							'flow_step_id'      => $flow_step_id,
							'next_flow_step_id' => $next_flow_step_id,
							'class'             => $step_class,
							'reason'            => $transition_route['reason'],
						)
					);

					return array(
						'success'      => true,
						'step_success' => false,
						'outcome'      => 'failed',
						'error'        => $transition_route['reason'],
					);
				}

				$fanout_packets = $transition_route['packets'];

				// After filtering, check if we're back to ≤1 packet — inline instead of fan-out.
				if ( 'inline' === $transition_route['mode'] || count( $fanout_packets ) <= 1 ) {
					do_action(
						'datamachine_schedule_next_step',
						$job_id,
						$next_flow_step_id,
						$fanout_packets
					);

					return array(
						'success'      => true,
						'step_success' => true,
						'outcome'      => 'inline_continuation',
					);
				}

				// Fan out: each handler DataPacket becomes its own child job
				// continuing through the remaining pipeline steps.
				$engine_snapshot = datamachine_get_engine_data( $job_id );
				$batch_scheduler = new PipelineBatchScheduler();
				$batch_result    = $batch_scheduler->fanOut(
					$job_id,
					$next_flow_step_id,
					$fanout_packets,
					$engine_snapshot
				);

				return array(
					'success'      => true,
					'step_success' => true,
					'outcome'      => 'batch_scheduled',
					'batch'        => $batch_result,
				);
			}

			// Mark this item as processed now that the full pipeline succeeded.
			// Deferred from the fetch step to prevent "dropped events" where
			// an item is marked processed but a downstream step fails.
			$this->markCompletedItemProcessed( $job_id );

			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED );
			$cleanup = new FileCleanup();
			$context = datamachine_get_file_context( $flow_id );
			$cleanup->cleanup_job_data_packets( $job_id, $context );

			do_action(
				'datamachine_log',
				'info',
				'Pipeline execution completed successfully',
				array(
					'job_id'             => $job_id,
					'pipeline_id'        => $pipeline_id,
					'flow_id'            => $flow_id,
					'flow_step_id'       => $flow_step_id,
					'final_packet_count' => count( $dataPackets ),
					'final_status'       => JobStatus::COMPLETED,
				)
			);

			return array(
				'success'      => true,
				'step_success' => true,
				'outcome'      => 'completed',
			);
		}

		// Fetch/event_import steps: empty data means "nothing to process", not failure.
		// This applies regardless of whether the flow has historical processed items —
		// a new flow checking a source with no events is not broken, it just has nothing yet.
		$is_fetch_step = in_array( $step_type, array( 'fetch', 'event_import' ), true );

		if ( $is_fetch_step ) {
			$this->db_jobs->complete_job( $job_id, JobStatus::COMPLETED_NO_ITEMS );
			do_action(
				'datamachine_log',
				'info',
				'Flow completed with no new items to process',
				array(
					'job_id'       => $job_id,
					'pipeline_id'  => $pipeline_id,
					'flow_id'      => $flow_id,
					'flow_step_id' => $flow_step_id,
					'step_type'    => $step_type,
				)
			);
			return array(
				'success'      => true,
				'step_success' => false,
				'outcome'      => 'completed_no_items',
			);
		}

		// Non-fetch steps: empty data packet is an actual failure.
		do_action(
			'datamachine_log',
			'error',
			'Step execution failed - empty data packet',
			array(
				'job_id'       => $job_id,
				'pipeline_id'  => $pipeline_id,
				'flow_id'      => $flow_id,
				'flow_step_id' => $flow_step_id,
				'step_class'   => $step_class,
				'step_type'    => $step_type,
			)
		);
		do_action(
			'datamachine_fail_job',
			$job_id,
			'step_execution_failure',
			array(
				'flow_step_id' => $flow_step_id,
				'class'        => $step_class,
				'reason'       => $this->getFailureReasonFromPackets( $dataPackets, 'empty_data_packet_returned' ),
			)
		);

		return array(
			'success'      => true,
			'step_success' => false,
			'outcome'      => 'failed',
		);
	}

	/**
	 * Mark a completed job's source item as processed.
	 *
	 * Called when the LAST step in a pipeline completes successfully.
	 * Reads the dedup key (item_identifier), source type, and fetch step ID
	 * from the job's engine_data — these were seeded during fetch and
	 * propagated through fan-out child creation.
	 *
	 * This deferred approach prevents "dropped events" where the fetch
	 * step marks an item as processed but a downstream step (AI, update)
	 * fails. Without this, the item would never be retried because the
	 * dedup filter would skip it on the next fetch run.
	 *
	 * @since 0.58.1
	 * @param int $job_id The completing job ID.
	 */
	private function markCompletedItemProcessed( int $job_id ): void {
		$engine_data = datamachine_get_engine_data( $job_id );

		$item_identifier = $engine_data['item_identifier'] ?? null;
		$source_type     = $engine_data['source_type'] ?? null;

		if ( empty( $item_identifier ) || empty( $source_type ) ) {
			return;
		}

		// Find the fetch/event_import step's flow_step_id from flow_config.
		// The processed items table is keyed by flow_step_id, so we need the
		// fetch step's ID (not the current step's) for dedup to work correctly.
		$fetch_flow_step_id = null;
		$flow_config        = $engine_data['flow_config'] ?? array();

		foreach ( $flow_config as $step_id => $config ) {
			$step_type = $config['step_type'] ?? '';
			if ( in_array( $step_type, array( 'fetch', 'event_import' ), true ) ) {
				$fetch_flow_step_id = $step_id;
				break;
			}
		}

		if ( empty( $fetch_flow_step_id ) ) {
			return;
		}

		do_action(
			'datamachine_mark_item_processed',
			$fetch_flow_step_id,
			$source_type,
			$item_identifier,
			$job_id
		);

		do_action(
			'datamachine_log',
			'debug',
			'Deferred mark-as-processed on pipeline completion',
			array(
				'job_id'             => $job_id,
				'item_identifier'    => $item_identifier,
				'source_type'        => $source_type,
				'fetch_flow_step_id' => $fetch_flow_step_id,
			)
		);
	}

	/**
	 * Resolve whether returned packets should continue inline, fan out, or fail.
	 *
	 * AI output headed into a handler-requiring step is a single logical
	 * conversation result. Keep matching handler completions together so
	 * PublishStep/UpsertStep can enforce their own multi-handler contracts.
	 *
	 * @param array $current_step_config Current flow step config.
	 * @param array $next_step_config    Next flow step config.
	 * @param array $dataPackets         Data packets returned from the current step.
	 * @return array{mode: string, packets: array, reason?: string}
	 */
	public static function resolveTransitionRoute( array $current_step_config, array $next_step_config, array $dataPackets ): array {
		$current_step_type = $current_step_config['step_type'] ?? '';
		$next_step_type    = $next_step_config['step_type'] ?? '';

		if ( 'ai' === $current_step_type && '' !== $next_step_type && FlowStepConfig::usesHandler( $next_step_config ) ) {
			$handler_packets = self::getHandlerPacketsForFanOut( $dataPackets );

			if ( empty( $handler_packets ) ) {
				return array(
					'mode'    => 'fail',
					'packets' => array(),
					'reason'  => 'handler_requiring_step_missing_handler_packets',
				);
			}

			return array(
				'mode'    => 'inline',
				'packets' => $handler_packets,
			);
		}

		return array(
			'mode'    => 'fanout',
			'packets' => self::filterPacketsForFanOut( $dataPackets ),
		);
	}

	/**
	 * Filter data packets to only those safe to fan out into child jobs.
	 *
	 * When the AI step produces multiple packets, the batch scheduler creates
	 * one child job per packet. Only 'ai_handler_complete' packets carry the
	 * handler result that downstream steps (UpsertStep, PublishStep) need via
	 * ToolResultFinder. Non-handler packets ('tool_result', 'ai_response')
	 * would create child jobs that fail with 'required_handler_tool_not_called'.
	 *
	 * The DataPacket structure stores the packet kind in the top-level 'type'
	 * key (set by DataPacket::__construct's third argument).
	 * 'metadata.source_type' carries the ORIGINAL input source_type (e.g.
	 * 'ticketmaster', 'web_scraper') — never 'ai_handler_complete'. The
	 * pre-#1096 implementation filtered on metadata.source_type, which was a
	 * silent no-op that let every packet fan out into doomed child jobs.
	 *
	 * After filtering to handler packets, duplicates are removed. When the
	 * AI conversation loop fails to terminate early (e.g. misconfigured
	 * handler_slugs), the AI may call the same handler tool multiple times
	 * across turns, producing duplicate ai_handler_complete packets. These
	 * would fan out into child jobs that all process the same data.
	 * Dedup keeps only the first packet per handler tool name.
	 * See: https://github.com/Extra-Chill/data-machine/issues/1108
	 *
	 * If filtering removes all packets, the originals are returned unchanged
	 * — the step may not require handlers, or the packets may use a different
	 * convention (backward compatibility).
	 *
	 * @param array $dataPackets Data packets returned from the step.
	 * @return array Packets safe to fan out.
	 */
	public static function filterPacketsForFanOut( array $dataPackets ): array {
		$handler_packets = self::getHandlerPacketsForFanOut( $dataPackets );

		if ( empty( $handler_packets ) ) {
			return $dataPackets;
		}

		return $handler_packets;
	}

	/**
	 * Return de-duplicated ai_handler_complete packets.
	 *
	 * @param array $dataPackets Data packets returned from the step.
	 * @return array Handler-complete packets only.
	 */
	private static function getHandlerPacketsForFanOut( array $dataPackets ): array {
		$handler_packets = array_values(
			array_filter(
				$dataPackets,
				static function ( $packet ) {
					return ( $packet['type'] ?? '' ) === 'ai_handler_complete';
				}
			)
		);

		if ( empty( $handler_packets ) ) {
			return array();
		}

		// Deduplicate handler packets by tool_name. When the AI calls
		// the same handler tool multiple times (e.g. upsert_event called
		// on consecutive turns because the conversation didn't terminate),
		// each call produces a separate ai_handler_complete packet with
		// the same tool_name but possibly varied parameters. Only the
		// first invocation per tool_name is kept — subsequent duplicates
		// would create child jobs processing identical data.
		$seen_tools = array();
		$deduped    = array();

		foreach ( $handler_packets as $packet ) {
			$tool_name = $packet['metadata']['tool_name'] ?? '';

			if ( '' === $tool_name ) {
				// No tool_name — keep unconditionally.
				$deduped[] = $packet;
				continue;
			}

			if ( isset( $seen_tools[ $tool_name ] ) ) {
				continue;
			}

			$seen_tools[ $tool_name ] = true;
			$deduped[]                = $packet;
		}

		return $deduped;
	}

	/**
	 * Extract failure reason from step packets.
	 *
	 * @param array  $dataPackets Data packets from step execution.
	 * @param string $default Default reason when none found.
	 * @return string
	 */
	private function getFailureReasonFromPackets( array $dataPackets, string $default_value ): string {
		foreach ( $dataPackets as $packet ) {
			$metadata = $packet['metadata'] ?? array();
			if ( empty( $metadata['failure_reason'] ) ) {
				continue;
			}

			$reason = $metadata['failure_reason'];
			if ( is_string( $reason ) && '' !== trim( $reason ) ) {
				return sanitize_key( str_replace( ' ', '_', trim( $reason ) ) );
			}
		}

		return $default_value;
	}
}
