<?php
/**
 * Base class for all publish handlers.
 *
 * Provides common functionality for publish handlers including:
 * - Engine data retrieval
 * - Image validation
 * - Standardized response formatting
 * - Centralized logging
 *
 * @package DataMachine\Core\Steps\Publish\Handlers
 * @since 0.2.1
 */

namespace DataMachine\Core\Steps\Publish\Handlers;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\HttpClient;
use DataMachine\Core\WordPress\PostTracking;

defined( 'ABSPATH' ) || exit;

abstract class PublishHandler {

	/** @var string Handler type for logging and responses */
	protected string $handler_type;

	public function __construct( string $handler_type ) {
		$this->handler_type = $handler_type;
	}

	/**
	 * Implemented by each handler to execute publishing.
	 *
	 * @param array $parameters Tool parameters including content
	 * @param array $handler_config Handler-specific configuration
	 * @return array Result with success, data/error, and tool_name
	 */
	abstract protected function executePublish( array $parameters, array $handler_config ): array;

	/**
	 * Public entry point called by AI tool executor.
	 *
	 * @param array $parameters Tool parameters
	 * @param array $tool_def Tool definition with handler config
	 * @return array Result array
	 */
	final public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		// Centralize job_id handling and engine_data retrieval
		$job_id = (int) ( $parameters['job_id'] ?? null );
		if ( ! $job_id ) {
			return $this->errorResponse( 'job_id parameter is required for publish operations' );
		}

		$engine_data = $this->getEngineData( $job_id );
		$engine      = new EngineData( $engine_data, $job_id );

		// Dry-run mode: return preview without executing publish
		if ( ! empty( $engine_data['dry_run_mode'] ) ) {
			$handler_config = $tool_def['handler_config'] ?? array();
			return $this->buildDryRunPreview( $parameters, $handler_config, $engine );
		}

		// Enhance parameters for subclasses
		$parameters['job_id'] = $job_id;
		$parameters['engine'] = $engine;

		$handler_config = $tool_def['handler_config'] ?? array();
		$result         = $this->executePublish( $parameters, $handler_config );

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
	 * Build dry-run preview response.
	 *
	 * Subclasses can override to provide handler-specific preview data.
	 *
	 * @param array      $parameters Tool parameters
	 * @param array      $handler_config Handler configuration
	 * @param EngineData $engine Engine data instance
	 * @return array Dry-run preview response
	 */
	protected function buildDryRunPreview( array $parameters, array $handler_config, EngineData $engine ): array {
		$this->log(
			'info',
			'Dry-run mode - returning preview without publishing',
			array(
				'handler' => $this->handler_type,
			)
		);

		return $this->successResponse(
			array(
				'dry_run'    => true,
				'preview'    => array(
					'handler'    => $this->handler_type,
					'parameters' => array_keys( $parameters ),
				),
				'source_url' => $engine->getSourceUrl(),
				'image_path' => $engine->getImagePath(),
			)
		);
	}

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
	 * Get image file path from engine data.
	 *
	 * @param int $job_id Job identifier
	 * @return string|null Image file path
	 */
	protected function getImageFilePath( int $job_id ): ?string {
		$engine_data = $this->getEngineData( $job_id );
		return $engine_data['image_file_path'] ?? null;
	}

	/**
	 * Validate repository image file.
	 *
	 * @param string $image_file_path Path to image file
	 * @return array Validation result with valid, errors, mime_type, size
	 */
	protected function validateImage( string $image_file_path ): array {
		$image_validator = new \DataMachine\Core\FilesRepository\ImageValidator();
		$validation      = $image_validator->validate_repository_file( $image_file_path );

		return array(
			'valid'     => $validation['valid'] ?? false,
			'errors'    => $validation['errors'] ?? array(),
			'mime_type' => $validation['mime_type'] ?? null,
			'size'      => $validation['size'] ?? 0,
		);
	}

	/**
	 * Create standardized success response.
	 *
	 * @param array $data Result data (post_id, post_url, etc.)
	 * @return array Success response
	 */
	protected function successResponse( array $data ): array {
		return array(
			'success'   => true,
			'data'      => $data,
			'tool_name' => "{$this->handler_type}_publish",
		);
	}

	/**
	 * Create standardized error response.
	 *
	 * @param string     $error_message Error message
	 * @param array|null $context Optional context for logging
	 * @param string     $severity Error severity: 'critical', 'warning', 'info' (default: 'warning')
	 * @return array Error response
	 */
	protected function errorResponse( string $error_message, ?array $context = null, string $severity = 'warning' ): array {
		$this->log( 'error', $error_message, $context ?? array() );

		return array(
			'success'   => false,
			'error'     => $error_message,
			'severity'  => $severity,
			'tool_name' => "{$this->handler_type}_publish",
		);
	}

	/**
	 * Centralized logging with handler context.
	 *
	 * @param string $level Log level (debug, info, warning, error)
	 * @param string $message Log message
	 * @param array  $context Additional context data
	 */
	protected function log( string $level, string $message, array $context = array() ): void {
		do_action(
			'datamachine_log',
			$level,
			$message,
			array_merge(
				array(
					'handler' => $this->handler_type,
				),
				$context
			)
		);
	}

	/**
	 * Get OAuth provider instance.
	 *
	 * @param string $provider_key Provider key (e.g., 'reddit', 'googlesheets')
	 * @return object|null Provider instance or null
	 */
	protected function getAuthProvider( string $provider_key ): ?object {
		$auth_abilities = new AuthAbilities();
		return $auth_abilities->getProvider( $provider_key );
	}

	/**
	 * Perform HTTP request with standardized handling
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
	 * @param string $url     Request URL
	 * @param array  $options Request options:
	 *                        - headers: array - Additional headers to merge
	 *                        - body: string|array - Request body (for POST/PUT/PATCH)
	 *                        - timeout: int - Request timeout (default 120)
	 *                        - browser_mode: bool - Use browser-like headers (default false)
	 *                        - context: string - Context for logging (defaults to handler_type)
	 * @return array{success: bool, data?: string, status_code?: int, headers?: array, response?: array, error?: string}
	 */
	protected function httpRequest( string $method, string $url, array $options = array() ): array {
		if ( ! isset( $options['context'] ) ) {
			$options['context'] = ucfirst( $this->handler_type );
		}
		return HttpClient::request( $method, $url, $options );
	}

	/**
	 * Perform HTTP GET request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpGet( string $url, array $options = array() ): array {
		return $this->httpRequest( 'GET', $url, $options );
	}

	/**
	 * Perform HTTP POST request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpPost( string $url, array $options = array() ): array {
		return $this->httpRequest( 'POST', $url, $options );
	}

	/**
	 * Perform HTTP DELETE request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	protected function httpDelete( string $url, array $options = array() ): array {
		return $this->httpRequest( 'DELETE', $url, $options );
	}
}
