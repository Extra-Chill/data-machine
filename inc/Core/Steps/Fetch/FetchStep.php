<?php

namespace DataMachine\Core\Steps\Fetch;

use DataMachine\Core\DataPacket;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Steps\QueueableTrait;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\Handlers\HandlerConfigValidator;
use DataMachine\Engine\Bundle\AuthRefHandlerConfig;
use DataMachine\Abilities\HandlerAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data fetching step for Data Machine pipelines.
 *
 * Delegates to FetchHandler::get_fetch_data() which returns DataPacket[].
 * Each DataPacket is added to the step's data packet array. When the
 * PipelineBatchScheduler is active, multiple packets trigger fan-out
 * into child jobs.
 *
 * Supports queue-driven dynamic params via QueueableTrait. The
 * `queue_mode` enum on the flow step config (#1291) picks the access
 * pattern:
 *
 *   - drain  — pop one queued patch per tick, discard. Empty queue →
 *              COMPLETED_NO_ITEMS. Drives windowed historical
 *              backfills.
 *   - loop   — pop one queued patch per tick, append same patch to
 *              tail. Drives rotating-source forward-ingestion (e.g.
 *              cycle through a list of fetch configs).
 *   - static — peek the head, do not mutate. The position-0 patch
 *              fires every tick; positions 1..N stay staged for
 *              iterative flow development. Switch to drain or loop
 *              once the patch is dialed in.
 *
 * Empty queue in drain/loop modes is treated as a no-op tick: the job
 * completes with COMPLETED_NO_ITEMS and no fetch is attempted. Static
 * mode falls through to the unmerged static handler config when the
 * queue is empty (no patch == no overlay).
 *
 * @package DataMachine
 */
class FetchStep extends Step {

	use StepTypeRegistrationTrait;
	use QueueableTrait;

	/**
	 * Initialize fetch step.
	 */
	public function __construct() {
		parent::__construct( 'fetch' );

		self::registerStepType(
			slug: 'fetch',
			label: 'Fetch',
			description: 'Collect data from external sources',
			class_name: self::class,
			position: 10,
			usesHandler: true,
			hasPipelineConfig: false
		);
	}

	/**
	 * Execute fetch step logic.
	 *
	 * @return array Updated data packet array.
	 */
	protected function executeStep(): array {
		$handler          = $this->getHandlerSlug();
		$handler_settings = $this->getHandlerConfig();

		if ( ! isset( $this->flow_step_config['flow_step_id'] ) || empty( $this->flow_step_config['flow_step_id'] ) ) {
			$this->log( 'error', 'Fetch Step: Missing flow_step_id in step config' );
			return $this->dataPackets;
		}
		if ( ! isset( $this->flow_step_config['pipeline_id'] ) || empty( $this->flow_step_config['pipeline_id'] ) ) {
			$this->log( 'error', 'Fetch Step: Missing pipeline_id in step config' );
			return $this->dataPackets;
		}
		if ( ! isset( $this->flow_step_config['flow_id'] ) || empty( $this->flow_step_config['flow_id'] ) ) {
			$this->log( 'error', 'Fetch Step: Missing flow_id in step config' );
			return $this->dataPackets;
		}

		// Queue-driven params: read the configured mode and consume from
		// the config_patch_queue accordingly. Drain pops, loop pops+
		// appends, static peeks — see QueueableTrait for the contract.
		$queue_mode   = $this->flow_step_config['queue_mode'] ?? 'static';
		$queue_result = $this->consumeFromConfigPatchQueue( $queue_mode );

		if ( ! $queue_result['from_queue'] ) {
			// Empty queue in drain or loop modes implies per-tick work
			// that can't proceed — short-circuit cleanly. Static mode
			// falls through to the unmerged handler config below.
			if ( in_array( $queue_mode, array( 'drain', 'loop' ), true ) ) {
				$this->log(
					'info',
					'Fetch step skipped — queue mode requires per-tick patch but queue is empty',
					array(
						'flow_step_id' => $this->flow_step_id,
						'queue_mode'   => $queue_mode,
					)
				);

				$this->engine->set( 'job_status', \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS );
				RunMetrics::recordStepResult(
					$this->job_id,
					$this->flow_step_id,
					array(
						'step_type'    => 'fetch',
						'result'       => 'completed_no_items',
						'handler_slug' => (string) $handler,
						'packet_count' => 0,
						'reason'       => 'empty_queue',
						'queue_mode'   => $queue_mode,
					)
				);

				return $this->dataPackets;
			}
		} else {
			$handler_settings = $this->mergeQueuedConfigPatch( $handler_settings, $queue_result['patch'] );

			$this->log(
				'info',
				'Fetch step merged queued config patch',
				array(
					'flow_step_id' => $this->flow_step_id,
					'queue_mode'   => $queue_mode,
					'patch_keys'   => array_keys( $queue_result['patch'] ),
					'merged_keys'  => array_keys( $handler_settings ),
					'queued_at'    => $queue_result['added_at'],
				)
			);
		}

		$handler_settings['flow_step_id'] = $this->flow_step_config['flow_step_id'];
		$handler_settings['pipeline_id']  = $this->flow_step_config['pipeline_id'];
		$handler_settings['flow_id']      = $this->flow_step_config['flow_id'];
		$job_context                      = $this->engine->getJobContext();
		$owner_user_id                    = (int) ( $job_context['user_id'] ?? 0 );
		$agent_id                         = (int) ( $job_context['agent_id'] ?? 0 );

		if ( $owner_user_id > 0 ) {
			$handler_settings['user_id'] = $owner_user_id;
		}
		if ( $agent_id > 0 ) {
			$handler_settings['agent_id'] = $agent_id;
		}

		$resolved_handler_settings = AuthRefHandlerConfig::resolve_runtime_config(
			$handler_settings,
			(string) $handler,
			array(
				'flow_step_id' => $this->flow_step_config['flow_step_id'],
				'pipeline_id'  => $this->flow_step_config['pipeline_id'],
				'flow_id'      => $this->flow_step_config['flow_id'],
				'job_id'       => $this->job_id,
				'user_id'      => $owner_user_id,
				'agent_id'     => $agent_id,
			)
		);

		if ( is_wp_error( $resolved_handler_settings ) ) {
			$this->log( 'error', 'Fetch auth_ref resolution failed: ' . $resolved_handler_settings->get_error_message() );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array(
					'step_type'    => 'fetch',
					'result'       => 'failed',
					'handler_slug' => (string) $handler,
					'packet_count' => 0,
					'reason'       => 'auth_ref_resolution_failed',
					'error'        => $resolved_handler_settings->get_error_message(),
				)
			);
			return $this->dataPackets;
		}

		$handler_settings = $resolved_handler_settings;
		$validation       = HandlerConfigValidator::validate(
			(string) $handler,
			'fetch',
			$handler_settings,
			array(
				'flow_step_id' => $this->flow_step_config['flow_step_id'],
				'pipeline_id'  => $this->flow_step_config['pipeline_id'],
				'flow_id'      => $this->flow_step_config['flow_id'],
				'job_id'       => $this->job_id,
				'user_id'      => $owner_user_id,
				'agent_id'     => $agent_id,
			)
		);

		if ( is_wp_error( $validation ) ) {
			$diagnostics = array_merge(
				$this->extractHandlerOutcomeIds( $handler_settings ),
				HandlerConfigValidator::diagnostics( $validation )
			);

			$this->log( 'error', 'Fetch handler config validation failed: ' . $validation->get_error_message(), $diagnostics );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array_merge(
					$diagnostics,
					array(
						'step_type'    => 'fetch',
						'result'       => 'failed',
						'handler_slug' => (string) $handler,
						'packet_count' => 0,
					)
				)
			);

			return $this->buildHandlerConfigValidationFailurePackets( $validation, (string) $handler );
		}

		$packets = $this->execute_handler_as_owner( $handler, $handler_settings, (string) $this->job_id, $owner_user_id );

		if ( is_wp_error( $packets ) ) {
			$this->log( 'error', 'Fetch handler failed: ' . $packets->get_error_message() );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array_merge(
					$this->extractHandlerOutcomeIds( $handler_settings ),
					array(
						'step_type'    => 'fetch',
						'result'       => 'failed',
						'handler_slug' => (string) $handler,
						'packet_count' => 0,
						'reason'       => 'handler_failed',
						'error'        => $packets->get_error_message(),
					)
				)
			);

			return array(
				'status'  => 'failed',
				'packets' => $this->dataPackets,
				'reason'  => 'handler_failed',
				'error'   => $packets->get_error_message(),
			);
		}

		if ( empty( $packets ) ) {
			// Empty fetch is routine, expected behavior (filtered sources, quiet
			// windows, small subreddits with no qualifying items) — not a handler
			// failure. The job proceeds to the success-family `completed_no_items`
			// terminal status, and RunMetrics already records `result: no_content`
			// below. Reserve `error` for the is_wp_error() branch above, which is
			// an actual handler failure. See #2873.
			$this->log( 'info', 'Fetch handler returned no content' );
			RunMetrics::recordStepResult(
				$this->job_id,
				$this->flow_step_id,
				array_merge(
					$this->extractHandlerOutcomeIds( $handler_settings ),
					array(
						'step_type'    => 'fetch',
						'result'       => 'no_content',
						'handler_slug' => (string) $handler,
						'packet_count' => 0,
					)
				)
			);
			return $this->dataPackets;
		}

		RunMetrics::recordStepResult(
			$this->job_id,
			$this->flow_step_id,
			array_merge(
				$this->extractHandlerOutcomeIds( $handler_settings ),
				array(
					'step_type'    => 'fetch',
					'result'       => 'completed',
					'handler_slug' => (string) $handler,
					'packet_count' => count( $packets ),
				)
			)
		);

		$this->log(
			'info',
			'Fetch handler returned data',
			array(
				'handler'      => $handler,
				'packet_count' => count( $packets ),
			)
		);

		$result = $this->dataPackets;
		foreach ( $packets as $packet ) {
			$result = $packet->addTo( $result );
		}

		return $result;
	}

	/**
	 * Execute handler and return DataPackets.
	 *
	 * FetchHandler::get_fetch_data() now returns DataPacket[] directly.
	 * This method just resolves the handler object and delegates.
	 *
	 * @param string $handler_name    Handler slug.
	 * @param array  $handler_settings Handler settings (includes pipeline_id, flow_id).
	 * @param string $job_id          Job ID.
	 * @return DataPacket[]|\WP_Error Array of DataPackets, or error on handler failure.
	 */
	private function execute_handler( string $handler_name, array $handler_settings, string $job_id ): array|\WP_Error {
		$handler = $this->get_handler_object( $handler_name );
		if ( ! $handler ) {
			$this->log(
				'error',
				'Handler not found or invalid',
				array(
					'handler' => $handler_name,
				)
			);
			return new \WP_Error( 'fetch_handler_not_found', sprintf( 'Fetch handler "%s" was not found.', $handler_name ) );
		}

		try {
			$pipeline_id = $handler_settings['pipeline_id'] ?? null;

			if ( empty( $pipeline_id ) ) {
				$this->log( 'error', 'Pipeline ID not found in handler settings' );
				return new \WP_Error( 'fetch_handler_missing_pipeline_id', 'Pipeline ID not found in handler settings.' );
			}

			return $handler->get_fetch_data( $pipeline_id, $handler_settings, $job_id );
		} catch ( \Exception $e ) {
			$this->log(
				'error',
				'Handler execution failed',
				array(
					'handler'   => $handler_name,
					'exception' => $e->getMessage(),
				)
			);
			return new \WP_Error( 'fetch_handler_execution_failed', $e->getMessage() );
		}
	}

	/**
	 * Execute the fetch handler as the flow owner when one is known.
	 *
	 * Scheduled fetches often run without a logged-in WordPress user. Principal-
	 * scoped auth providers still need the flow owner as current user so handler
	 * code can resolve user-scoped OAuth credentials without falling back to an
	 * anonymous/site context.
	 *
	 * @param string $handler_name Handler slug.
	 * @param array  $handler_settings Handler settings.
	 * @param string $job_id Job ID.
	 * @param int    $owner_user_id Flow owner user ID.
	 * @return DataPacket[]|\WP_Error Array of DataPackets, or error on handler failure.
	 */
	private function execute_handler_as_owner( string $handler_name, array $handler_settings, string $job_id, int $owner_user_id ): array|\WP_Error {
		if ( $owner_user_id <= 0 || ! function_exists( 'wp_set_current_user' ) || ! function_exists( 'get_current_user_id' ) ) {
			return $this->execute_handler( $handler_name, $handler_settings, $job_id );
		}

		$previous_user_id = (int) get_current_user_id();
		if ( $previous_user_id !== $owner_user_id ) {
			wp_set_current_user( $owner_user_id );
		}

		try {
			return $this->execute_handler( $handler_name, $handler_settings, $job_id );
		} finally {
			if ( $previous_user_id !== $owner_user_id ) {
				wp_set_current_user( $previous_user_id );
			}
		}
	}

	/**
	 * Get handler object instance by name.
	 *
	 * @param string $handler_name Handler identifier
	 * @return object|null Handler instance or null if not found
	 */
	private function get_handler_object( string $handler_name ): ?object {
		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_name, 'fetch' );

		if ( ! $handler_info || ! isset( $handler_info['class'] ) ) {
			return null;
		}

		$class_name = $handler_info['class'];
		return class_exists( $class_name ) ? new $class_name() : null;
	}

	/**
	 * Build an explicit failure packet for handler config validation errors.
	 *
	 * @param \WP_Error $error Validation error.
	 * @param string    $handler_slug Handler slug.
	 * @return array Updated data packet array.
	 */
	private function buildHandlerConfigValidationFailurePackets( \WP_Error $error, string $handler_slug ): array {
		$packet = new DataPacket(
			array(
				'body' => $error->get_error_message(),
			),
			array_merge(
				HandlerConfigValidator::diagnostics( $error ),
				array(
					'step_type'      => 'fetch',
					'flow_step_id'   => $this->flow_step_id,
					'handler_slug'   => $handler_slug,
					'success'        => false,
					'failure_reason' => HandlerConfigValidator::ERROR_CODE,
				)
			),
			'step_error'
		);

		return $packet->addTo( $this->dataPackets );
	}

	/**
	 * Extract generic provider/tool identifiers from handler settings.
	 *
	 * @param array $handler_settings Handler settings after runtime auth resolution.
	 * @return array<string,mixed>
	 */
	private function extractHandlerOutcomeIds( array $handler_settings ): array {
		$out = array();
		foreach ( array( 'provider_id', 'provider_slug', 'provider', 'auth_provider', 'server_key', 'context_server_key' ) as $key ) {
			if ( ! empty( $handler_settings[ $key ] ) && is_scalar( $handler_settings[ $key ] ) ) {
				$out['provider_id'] = (string) $handler_settings[ $key ];
				break;
			}
		}

		$tool_ids = array();
		foreach ( array( 'tool_id', 'tool_slug', 'tool_name', 'handler_tool', 'mcp_tool' ) as $key ) {
			if ( ! empty( $handler_settings[ $key ] ) && is_scalar( $handler_settings[ $key ] ) ) {
				$tool_ids[] = (string) $handler_settings[ $key ];
			}
		}
		if ( ! empty( $tool_ids ) ) {
			$out['tool_ids'] = array_values( array_unique( $tool_ids ) );
		}

		return $out;
	}
}
