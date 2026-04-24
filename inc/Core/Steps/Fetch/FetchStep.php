<?php

namespace DataMachine\Core\Steps\Fetch;

use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\QueueableTrait;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
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
 * Supports queue-driven dynamic params via QueueableTrait. When
 * `queue_enabled` is true on the flow step config, the step pops one
 * queued JSON-encoded patch per invocation and deep-merges it into the
 * handler's static config before invoking the handler. This is how
 * windowed retroactive backfills (a flow that processes a different
 * date range each tick) and rotating-source forward-ingestion are
 * expressed without an external orchestrator.
 *
 * Empty queue with queue_enabled=true is treated as a no-op tick: the
 * job completes with COMPLETED_NO_ITEMS and no fetch is attempted. This
 * matches the AI step's queue-empty behaviour and keeps recurring flows
 * idempotent once their backfill queue drains.
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

		// Queue-driven params: when enabled, pop one queued patch and
		// merge it into the static handler config. This is how a flow
		// expresses "every tick, process the next windowed slice" with
		// no external orchestrator.
		$queue_enabled = (bool) ( $this->flow_step_config['queue_enabled'] ?? false );

		if ( $queue_enabled ) {
			$queue_result = $this->popQueuedConfigPatch( true );

			if ( ! $queue_result['from_queue'] ) {
				// Queue enabled but empty — clean no-op tick. Mark the
				// job as completed-no-items so the engine treats this
				// as success rather than a fetch failure.
				$this->log(
					'info',
					'Fetch step skipped — queue enabled but empty',
					array(
						'flow_step_id' => $this->flow_step_id,
					)
				);

				$this->engine->set( 'job_status', \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS );

				return $this->dataPackets;
			}

			$handler_settings = $this->mergeQueuedConfigPatch( $handler_settings, $queue_result['patch'] );

			$this->log(
				'info',
				'Fetch step merged queued config patch',
				array(
					'flow_step_id'  => $this->flow_step_id,
					'patch_keys'    => array_keys( $queue_result['patch'] ),
					'queued_at'     => $queue_result['added_at'],
				)
			);
		}

		$handler_settings['flow_step_id'] = $this->flow_step_config['flow_step_id'];
		$handler_settings['pipeline_id']  = $this->flow_step_config['pipeline_id'];
		$handler_settings['flow_id']      = $this->flow_step_config['flow_id'];

		$packets = $this->execute_handler( $handler, $handler_settings, (string) $this->job_id );

		if ( empty( $packets ) ) {
			$this->log( 'error', 'Fetch handler returned no content' );
			return $this->dataPackets;
		}

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
	 * @return DataPacket[] Array of DataPackets, or empty array on failure.
	 */
	private function execute_handler( string $handler_name, array $handler_settings, string $job_id ): array {
		$handler = $this->get_handler_object( $handler_name );
		if ( ! $handler ) {
			$this->log(
				'error',
				'Handler not found or invalid',
				array(
					'handler' => $handler_name,
				)
			);
			return array();
		}

		try {
			$pipeline_id = $handler_settings['pipeline_id'] ?? null;

			if ( empty( $pipeline_id ) ) {
				$this->log( 'error', 'Pipeline ID not found in handler settings' );
				return array();
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
			return array();
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
}
