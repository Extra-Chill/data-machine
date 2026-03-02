<?php

namespace DataMachine\Core\Steps\Fetch;

use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Abilities\HandlerAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data fetching step for Data Machine pipelines.
 *
 * Supports both single-item and multi-item handler returns. When a handler
 * returns an `items` key containing an array of raw items, each one is
 * wrapped into its own DataPacket. The pipeline batch scheduler then fans
 * each DataPacket out into its own child job.
 *
 * @package DataMachine
 */
class FetchStep extends Step {

	use StepTypeRegistrationTrait;

	/**
	 * Initialize fetch step.
	 */
	public function __construct() {
		parent::__construct( 'fetch' );

		self::registerStepType(
			slug: 'fetch',
			label: 'Fetch',
			description: 'Collect data from external sources',
			class: self::class,
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

		$handler_settings['flow_step_id'] = $this->flow_step_config['flow_step_id'];
		$handler_settings['pipeline_id']  = $this->flow_step_config['pipeline_id'];
		$handler_settings['flow_id']      = $this->flow_step_config['flow_id'];

		$packets = $this->execute_handler( $handler, $this->flow_step_config, $handler_settings, (string) $this->job_id );

		if ( empty( $packets ) ) {
			$this->log( 'error', 'Fetch handler returned no content' );
			return $this->dataPackets;
		}

		$result = $this->dataPackets;
		foreach ( $packets as $packet ) {
			$result = $packet->addTo( $result );
		}

		return $result;
	}

	/**
	 * Execute handler and build DataPackets from its output.
	 *
	 * Supports two return formats from handlers:
	 *
	 * 1. Single item (legacy): `{ title, content, metadata, file_info }` — wrapped into 1 DataPacket.
	 * 2. Multi-item: `{ items: [ {title, content, metadata}, ... ] }` — each item becomes its own DataPacket.
	 *
	 * @param string $handler_name    Handler slug.
	 * @param array  $flow_step_config Flow step configuration.
	 * @param array  $handler_settings Handler-specific settings.
	 * @param string $job_id          Job ID.
	 * @return DataPacket[] Array of DataPackets, or empty array on failure.
	 */
	private function execute_handler( string $handler_name, array $flow_step_config, array $handler_settings, string $job_id ): array {
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
			if ( ! isset( $flow_step_config['pipeline_id'] ) || empty( $flow_step_config['pipeline_id'] ) ) {
				$this->log( 'error', 'Pipeline ID not found in step config' );
				return array();
			}
			if ( ! isset( $flow_step_config['flow_id'] ) || empty( $flow_step_config['flow_id'] ) ) {
				$this->log( 'error', 'Flow ID not found in step config' );
				return array();
			}

			$pipeline_id = $flow_step_config['pipeline_id'];
			$flow_id     = $flow_step_config['flow_id'];

			$result = $handler->get_fetch_data( $pipeline_id, $handler_settings, $job_id );

			if ( empty( $result ) ) {
				return array();
			}

			if ( ! is_array( $result ) ) {
				$this->log(
					'error',
					'Handler output must be an array',
					array( 'handler' => $handler_name, 'result_type' => gettype( $result ) )
				);
				return array();
			}

			// Detect multi-item format: result has an 'items' key with a numerically-indexed array.
			if ( isset( $result['items'] ) && is_array( $result['items'] ) && ! empty( $result['items'] ) ) {
				return $this->wrap_items( $result['items'], $handler_name, $pipeline_id, $flow_id );
			}

			// Single-item format (legacy): { title, content, metadata, file_info }.
			$packet = $this->wrap_single_item( $result, $handler_name, $pipeline_id, $flow_id );
			return $packet ? array( $packet ) : array();

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
	 * Wrap multiple raw items into DataPackets.
	 *
	 * @param array  $items        Array of raw item arrays.
	 * @param string $handler_name Handler slug.
	 * @param mixed  $pipeline_id  Pipeline ID.
	 * @param mixed  $flow_id      Flow ID.
	 * @return DataPacket[] Array of DataPackets.
	 */
	private function wrap_items( array $items, string $handler_name, $pipeline_id, $flow_id ): array {
		$packets = array();

		foreach ( $items as $item ) {
			$packet = $this->wrap_single_item( $item, $handler_name, $pipeline_id, $flow_id );
			if ( $packet ) {
				$packets[] = $packet;
			}
		}

		if ( ! empty( $packets ) ) {
			$this->log(
				'info',
				'Fetch handler returned multiple items',
				array(
					'handler'    => $handler_name,
					'item_count' => count( $packets ),
				)
			);
		}

		return $packets;
	}

	/**
	 * Wrap a single raw item array into a DataPacket.
	 *
	 * @param array  $item         Raw item array with title, content, metadata, file_info.
	 * @param string $handler_name Handler slug.
	 * @param mixed  $pipeline_id  Pipeline ID.
	 * @param mixed  $flow_id      Flow ID.
	 * @return DataPacket|null DataPacket or null if item has no content.
	 */
	private function wrap_single_item( array $item, string $handler_name, $pipeline_id, $flow_id ): ?DataPacket {
		$title     = $item['title'] ?? '';
		$content   = $item['content'] ?? '';
		$file_info = $item['file_info'] ?? null;
		$metadata  = $item['metadata'] ?? array();

		if ( empty( $title ) && empty( $content ) && empty( $file_info ) ) {
			return null;
		}

		$content_array = array(
			'title' => $title,
			'body'  => $content,
		);

		if ( $file_info ) {
			$content_array['file_info'] = $file_info;
		}

		$packet_metadata = array_merge(
			array(
				'source_type' => $handler_name,
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
				'handler'     => $handler_name,
			),
			$metadata
		);

		return new DataPacket( $content_array, $packet_metadata, 'fetch' );
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
