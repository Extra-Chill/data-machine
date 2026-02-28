<?php
/**
 * Base class for Update handlers providing standardized engine data access.
 *
 * Post tracking is automatic — after executeUpdate() returns a successful
 * result with a post_id, the base class writes origin metadata (handler,
 * flow, pipeline) without any action needed from subclasses.
 *
 * @package DataMachine\Core\Steps\Update\Handlers
 */

namespace DataMachine\Core\Steps\Update\Handlers;

use DataMachine\Core\EngineData;
use DataMachine\Core\WordPress\PostTracking;

defined( 'ABSPATH' ) || exit;

abstract class UpdateHandler {

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
	 * Execute update operation.
	 *
	 * @param array $parameters Tool parameters including job_id
	 * @param array $handler_config Handler configuration
	 * @return array Success/failure response
	 */
	abstract protected function executeUpdate( array $parameters, array $handler_config ): array;

	/**
	 * Handle tool call with job_id validation and automatic post tracking.
	 *
	 * After executeUpdate() returns, if the result is successful and contains
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
		$result         = $this->executeUpdate( $parameters, $handler_config );

		// Automatic post tracking — write origin metadata on successful results
		if ( ! empty( $result['success'] ) ) {
			$post_id = PostTracking::extractPostId( $result );
			if ( $post_id > 0 ) {
				PostTracking::store( $post_id, $tool_def, $job_id );
			}
		}

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
		do_action( 'datamachine_log', $level, 'Update Handler Error: ' . $message, $context );
		return array(
			'success'   => false,
			'error'     => $message,
			'tool_name' => static::class,
		);
	}
}
