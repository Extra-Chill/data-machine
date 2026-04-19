<?php
/**
 * Update step with AI tool-calling architecture.
 *
 * @package DataMachine\Core\Steps\Upsert
 */

namespace DataMachine\Core\Steps\Upsert;

use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Engine\AI\Tools\ToolResultFinder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpsertStep extends Step {

	use StepTypeRegistrationTrait;

	/**
	 * Initialize upsert step.
	 */
	public function __construct() {
		parent::__construct( 'upsert' );

		self::registerStepType(
			slug: 'upsert',
			label: 'Upsert',
			description: 'Create or update content with identity-aware detection (find existing, update if changed, create if new)',
			class_name: self::class,
			position: 40,
			usesHandler: true,
			hasPipelineConfig: false
		);
	}

	/**
	 * Execute update step logic.
	 *
	 * @return array
	 */
	protected function executeStep(): array {
		$configured_handler_slugs = $this->getHandlerSlugs();
		$required_handler_slugs   = $this->getRequiredHandlerSlugs( $configured_handler_slugs );
		$tool_results_by_slug     = $this->findSuccessfulHandlerResultsBySlug( $required_handler_slugs );

		$missing_required_handlers = array_values( array_diff( $required_handler_slugs, array_keys( $tool_results_by_slug ) ) );

		if ( empty( $missing_required_handlers ) && ! empty( $required_handler_slugs ) ) {
			$primary_handler_slug = $required_handler_slugs[0];
			$tool_result_entry    = $tool_results_by_slug[ $primary_handler_slug ] ?? null;

			if ( ! is_array( $tool_result_entry ) ) {
				$this->log(
					'error',
					'Update step missing primary tool result despite required handlers being satisfied',
					array(
						'primary_handler_slug' => $primary_handler_slug,
					)
				);

				return $this->buildMissingHandlerPacket(
					$configured_handler_slugs,
					$required_handler_slugs,
					array( $primary_handler_slug )
				);
			}

			$this->log(
				'info',
				'AI successfully executed required update handler tools',
				array(
					'primary_handler'   => $primary_handler_slug,
					'required_handlers' => $required_handler_slugs,
				)
			);

			return $this->create_update_entry_from_tool_result( $tool_result_entry, $this->dataPackets, $primary_handler_slug, $this->flow_step_id );
		}

		// Fan-out child jobs often receive a data packet that doesn't contain
		// the handler result — a sibling job got it instead. This is expected
		// behavior, not a failure. Complete silently to avoid log noise.
		if ( $this->isFanOutChild() ) {
			$this->log(
				'debug',
				'Fan-out child missing handler result (sibling handled it)',
				array(
					'required_handler_slugs'    => $required_handler_slugs,
					'missing_required_handlers' => $missing_required_handlers,
				)
			);

			return $this->buildFanOutSkipPacket( $configured_handler_slugs, $required_handler_slugs, $missing_required_handlers );
		}

		$this->log(
			'warning',
			'Update step required handler tool was not executed by AI',
			array(
				'configured_handlers'       => $configured_handler_slugs,
				'required_handler_slugs'    => $required_handler_slugs,
				'missing_required_handlers' => $missing_required_handlers,
			)
		);

		return $this->buildMissingHandlerPacket( $configured_handler_slugs, $required_handler_slugs, $missing_required_handlers );
	}

	/**
	 * Validate update step configuration.
	 *
	 * @return bool
	 */
	protected function validateStepConfiguration(): bool {
		$configured_handler_slugs = $this->getHandlerSlugs();

		if ( empty( $configured_handler_slugs ) ) {
			$this->logConfigurationError(
				'Step requires handler configuration',
				array(
					'available_flow_step_config' => array_keys( $this->flow_step_config ),
				)
			);
			return false;
		}

		$raw_required_handlers = $this->getRawRequiredHandlerSlugs();

		if ( empty( $raw_required_handlers ) && count( $configured_handler_slugs ) > 1 ) {
			$this->log(
				'warning',
				'Multi-handler update step has no required_handler_slugs set; defaulting to first handler',
				array(
					'configured_handlers' => $configured_handler_slugs,
					'default_required'    => array( $configured_handler_slugs[0] ),
				)
			);
		}

		if ( ! empty( $raw_required_handlers ) ) {
			$invalid_handlers = array_values( array_diff( $raw_required_handlers, $configured_handler_slugs ) );

			if ( ! empty( $invalid_handlers ) ) {
				$this->logConfigurationError(
					'required_handler_slugs must be a subset of handler_slugs',
					array(
						'configured_handlers' => $configured_handler_slugs,
						'required_handlers'   => $raw_required_handlers,
						'invalid_handlers'    => $invalid_handlers,
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Handle exceptions with job failure action.
	 *
	 * @param \Exception $e Exception instance
	 * @param string     $context Context where exception occurred
	 * @return array Data packet array (unchanged on exception)
	 */
	protected function handleException( \Exception $e, string $context = 'execution' ): array {
		do_action(
			'datamachine_fail_job',
			$this->job_id,
			'upsert_step_exception',
			array(
				'flow_step_id'      => $this->flow_step_id,
				'exception_message' => $e->getMessage(),
				'exception_trace'   => $e->getTraceAsString(),
			)
		);

		return $this->dataPackets;
	}

	/**
	 * Create update entry from AI tool result.
	 *
	 * @param array  $tool_result_entry Tool result from AI step
	 * @param array  $dataPackets Current data packet array
	 * @param string $handler Handler slug
	 * @param string $flow_step_id Flow step ID
	 * @return array Updated data packet array
	 */
	private function create_update_entry_from_tool_result( array $tool_result_entry, array $dataPackets, string $handler, string $flow_step_id ): array {
		$tool_result_data = $tool_result_entry['metadata']['tool_result'] ?? array();

		$packet = new DataPacket(
			array(
				'update_result' => $tool_result_data,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array(
				'step_type'           => 'upsert',
				'handler'             => $handler,
				'flow_step_id'        => $flow_step_id,
				'success'             => $tool_result_data['success'] ?? false,
				'executed_via'        => 'ai_tool_call',
				'tool_execution_data' => $tool_result_data,
			),
			'upsert'
		);

		return $packet->addTo( $dataPackets );
	}

	/**
	 * Check if this job is a fan-out child (has a parent job).
	 *
	 * Fan-out children receive individual data packets from the parent's
	 * output. Only one sibling gets the handler result — the rest are
	 * expected to miss it. This is normal, not a failure.
	 *
	 * @return bool
	 */
	private function isFanOutChild(): bool {
		$engine_data = $this->engine_data ?? array();
		$job_context = $engine_data['job'] ?? array();
		return ! empty( $job_context['parent_job_id'] );
	}

	/**
	 * Build a skip packet for fan-out children that don't have the handler result.
	 *
	 * Uses status_override = 'completed_no_items' so the routing layer
	 * completes the job silently instead of logging a noisy failure.
	 *
	 * @param array $configured_handler_slugs Configured handler slugs.
	 * @param array $required_handler_slugs   Required handler slugs.
	 * @param array $missing_required_handlers Missing required handlers.
	 * @return array
	 */
	private function buildFanOutSkipPacket( array $configured_handler_slugs, array $required_handler_slugs, array $missing_required_handlers ): array {
		// Set job_status override in engine_data so the routing layer
		// completes with 'completed_no_items' instead of a generic 'completed'.
		datamachine_merge_engine_data( $this->job_id, array(
			'job_status' => 'completed_no_items',
		) );

		$packet = new DataPacket(
			array(
				'update_result' => array(),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array(
				'step_type'                 => 'upsert',
				'handler'                   => $required_handler_slugs[0] ?? ( $configured_handler_slugs[0] ?? '' ),
				'flow_step_id'              => $this->flow_step_id,
				'success'                   => true,
				'fanout_sibling_handled'    => true,
				'missing_required_handlers' => $missing_required_handlers,
			),
			'upsert'
		);

		return $packet->addTo( $this->dataPackets );
	}

	/**
	 * Build failure packet when required handlers were not called.
	 *
	 * @param array $configured_handler_slugs Configured handler slugs.
	 * @param array $required_handler_slugs   Required handler slugs.
	 * @param array $missing_required_handlers Missing required handlers.
	 * @return array
	 */
	private function buildMissingHandlerPacket( array $configured_handler_slugs, array $required_handler_slugs, array $missing_required_handlers ): array {
		$packet = new DataPacket(
			array(
				'update_result' => array(),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array(
				'step_type'                 => 'upsert',
				'handler'                   => $required_handler_slugs[0] ?? ( $configured_handler_slugs[0] ?? '' ),
				'flow_step_id'              => $this->flow_step_id,
				'success'                   => false,
				'failure_reason'            => 'required_handler_tool_not_called',
				'missing_handler_tool'      => true,
				'configured_handler_slugs'  => $configured_handler_slugs,
				'required_handler_slugs'    => $required_handler_slugs,
				'missing_required_handlers' => $missing_required_handlers,
			),
			'upsert'
		);

		return $packet->addTo( $this->dataPackets );
	}

	/**
	 * Resolve required handler slugs for this update step.
	 *
	 * @param array $configured_handler_slugs Configured handler slugs.
	 * @return array
	 */
	private function getRequiredHandlerSlugs( array $configured_handler_slugs ): array {
		$required = $this->getRawRequiredHandlerSlugs();

		if ( ! empty( $required ) ) {
			return $required;
		}

		if ( empty( $configured_handler_slugs ) ) {
			return array();
		}

		return array( $configured_handler_slugs[0] );
	}

	/**
	 * Get required handler slugs from flow step config.
	 *
	 * @return array
	 */
	private function getRawRequiredHandlerSlugs(): array {
		$required = $this->flow_step_config['required_handler_slugs'] ?? array();

		if ( ! is_array( $required ) ) {
			return array();
		}

		$required = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $slug ) {
							if ( ! is_string( $slug ) ) {
								return '';
							}

							return sanitize_key( $slug );
						},
						$required
					)
				)
			)
		);

		return $required;
	}

	/**
	 * Find successful tool results keyed by handler slug.
	 *
	 * @param array $handler_slugs Handler slugs to search for.
	 * @return array<string, array>
	 */
	private function findSuccessfulHandlerResultsBySlug( array $handler_slugs ): array {
		$results = array();

		foreach ( $handler_slugs as $handler_slug ) {
			$entry = ToolResultFinder::findHandlerResult( $this->dataPackets, $handler_slug, $this->flow_step_id, false );

			if ( is_array( $entry ) ) {
				$results[ $handler_slug ] = $entry;
			}
		}

		return $results;
	}
}
