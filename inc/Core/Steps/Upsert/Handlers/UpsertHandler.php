<?php
/**
 * Base class for Update handlers providing standardized engine data access.
 *
 * Post tracking is automatic — after executeUpsert() returns a successful
 * result with a post_id, the base class writes origin metadata (handler,
 * flow, pipeline) without any action needed from subclasses.
 *
 * @package DataMachine\Core\Steps\Upsert\Handlers
 */

namespace DataMachine\Core\Steps\Upsert\Handlers;

use DataMachine\Core\EngineData;
use DataMachine\Engine\Bundle\AuthRefHandlerConfig;

defined( 'ABSPATH' ) || exit;

abstract class UpsertHandler {

	/**
	 * Get all engine data for the current job.
	 *
	 * @param int $job_id Job identifier
	 * @return array Engine data with source_url, image_file_path, etc.
	 */
	protected function getEngineData( int $job_id ): array {
		if ( ! $job_id ) {
			return array(
				'source_url'      => null,
				'image_file_path' => null,
				'image_url'       => null,
			);
		}
		return datamachine_get_engine_data( $job_id );
	}

	/**
	 * Get source URL from engine data.
	 *
	 * @param int $job_id Job identifier
	 * @return string|null Source URL
	 */
	protected function getSourceUrl( int $job_id ): ?string {
		$engine_data = $this->getEngineData( $job_id );
		return $engine_data['source_url'] ?? null;
	}

	/**
	 * Execute upsert operation.
	 *
	 * @param array $parameters Tool parameters including job_id
	 * @param array $handler_config Handler configuration
	 * @return array Success/failure response
	 */
	abstract protected function executeUpsert( array $parameters, array $handler_config ): array;

	/**
	 * Handle tool call with job_id validation and automatic post tracking.
	 *
	 * After executeUpsert() returns, if the result is successful and contains
	 * a post_id, origin metadata is written automatically. Subclasses never
	 * need to call any tracking methods.
	 *
	 * @param array $parameters Tool parameters
	 * @param array $tool_def Tool definition
	 * @return array Tool call result
	 */
	final public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$job_id = (int) ( $parameters['job_id'] ?? null );
		if ( ! $job_id ) {
			return $this->errorResponse( 'job_id parameter is required for update operations' );
		}

		// Get engine_data for update operations
		$engine_data = $this->getEngineData( $job_id );
		$engine      = new EngineData( $engine_data, $job_id );

		// Enhance parameters for subclasses
		$parameters['job_id'] = $job_id;
		$parameters['engine'] = $engine;

		$handler_config = $tool_def['handler_config'] ?? array();
		$handler_config = AuthRefHandlerConfig::resolve_runtime_config(
			$handler_config,
			(string) ( $tool_def['handler'] ?? static::class ),
			array( 'job_id' => $job_id )
		);
		if ( is_wp_error( $handler_config ) ) {
			return $this->errorResponse( 'Auth ref resolution failed: ' . $handler_config->get_error_message() );
		}

		$result = $this->executeUpsert( $parameters, $handler_config );

		// Post origin tracking is applied centrally in ToolExecutor::executeTool()
		// after every tool call — handler tools and ability tools share the same
		// path now, so individual base classes no longer need to call
		// PostTracking::store() themselves.

		return $result;
	}

	/**
	 * Create standardized error response.
	 *
	 * @param string $message Error message
	 * @param array  $context Additional context
	 * @param string $level Log level
	 * @return array Error response
	 */
	protected function errorResponse( string $message, array $context = array(), string $level = 'error' ): array {
		do_action( 'datamachine_log', $level, 'Upsert Handler Error: ' . $message, $context );
		return array(
			'success'   => false,
			'error'     => $message,
			'tool_name' => static::class,
		);
	}
}
