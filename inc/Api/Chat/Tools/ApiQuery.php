<?php
/**
 * API Query Tool
 *
 * Internal REST API query tool for chat agent.
 * Used for discovery, monitoring, and troubleshooting operations.
 * Supports single and batch requests.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.2.0
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

/**
 * API Query Tool
 */
class ApiQuery extends BaseTool {

	public function __construct() {
		$this->registerTool( 'api_query', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'access_level' => 'editor' ) );
	}

	/**
	 * Get API Query tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildApiDocumentation(),
			'parameters'  => array(
				'endpoint' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Single mode: REST API endpoint path (e.g., /datamachine/v1/handlers)',
				),
				'requests' => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Batch mode: Array of {endpoint, key?}. Results keyed by endpoint or custom key.',
				),
			),
		);
	}

	/**
	 * Build API documentation for the tool description.
	 *
	 * @return string API documentation
	 */
	private function buildApiDocumentation(): string {
		return 'Query Data Machine REST API (GET only). For mutations, use focused tools: create_flow, update_flow, configure_flow_steps, etc.

MODES:
- Single: {endpoint}
- Batch: {requests: [{endpoint, key?}, ...]}

KEY ENDPOINTS:
/datamachine/v1/handlers - List handlers (?step_type=fetch|publish|update)
/datamachine/v1/handlers/{slug} - Handler config schema
/datamachine/v1/pipelines - List pipelines
/datamachine/v1/pipelines/{id} - Pipeline with flows
/datamachine/v1/flows/{id} - Flow details
/datamachine/v1/jobs - List jobs (?flow_id, ?status)';
	}

	/**
	 * Execute API query - single or batch mode.
	 *
	 * @param array $parameters Tool call parameters
	 * @param array $tool_def   Tool definition
	 * @return array Tool execution result
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		// Validate at least one mode is specified
		$has_endpoint = ! empty( $parameters['endpoint'] );
		$has_requests = ! empty( $parameters['requests'] ) && is_array( $parameters['requests'] );

		if ( ! $has_endpoint && ! $has_requests ) {
			return array(
				'success'   => false,
				'error'     => 'Either endpoint (single mode) or requests (batch mode) parameter is required. For external URLs, use web_fetch tool instead - api_query is for internal Data Machine REST API only.',
				'tool_name' => 'api_query',
			);
		}

		// Batch mode: requests array provided
		if ( $has_requests ) {
			return $this->handleBatchRequest( $parameters['requests'] );
		}

		// Single mode: existing behavior
		return $this->handleSingleRequest( $parameters );
	}

	/**
	 * Handle single API request.
	 *
	 * @param array $parameters Request parameters
	 * @return array Result
	 */
	private function handleSingleRequest( array $parameters ): array {
		$endpoint = $parameters['endpoint'] ?? '';

		if ( empty( $endpoint ) ) {
			return array(
				'success'   => false,
				'error'     => 'Endpoint parameter is required',
				'tool_name' => 'api_query',
			);
		}

		$result = $this->executeSingleRequest( $endpoint, 'GET', array() );

		return array_merge( $result, array( 'tool_name' => 'api_query' ) );
	}

	/**
	 * Handle batch API request.
	 *
	 * @param array $requests Array of request definitions
	 * @return array Batch result with keyed responses
	 */
	private function handleBatchRequest( array $requests ): array {
		if ( empty( $requests ) ) {
			return array(
				'success'   => false,
				'error'     => 'Requests array cannot be empty',
				'tool_name' => 'api_query',
			);
		}

		$results   = array();
		$errors    = array();
		$used_keys = array();

		foreach ( $requests as $index => $req ) {
			$endpoint = $req['endpoint'] ?? '';

			// Determine result key
			$key = $req['key'] ?? $this->extractKeyFromEndpoint( $endpoint );

			// Handle duplicate keys by appending index
			if ( isset( $used_keys[ $key ] ) ) {
				$key = $key . '_' . $index;
			}
			$used_keys[ $key ] = true;

			if ( empty( $endpoint ) ) {
				$errors[ $key ] = 'Missing endpoint';
				continue;
			}

			$result = $this->executeSingleRequest( $endpoint, 'GET', array() );

			if ( $result['success'] ) {
				$results[ $key ] = $result['data'];
			} else {
				$errors[ $key ] = $result['error'];
			}
		}

		$response = array(
			'success'       => empty( $errors ),
			'data'          => $results,
			'tool_name'     => 'api_query',
			'batch'         => true,
			'request_count' => count( $requests ),
			'success_count' => count( $results ),
			'error_count'   => count( $errors ),
		);

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
			$response['error']  = count( $errors ) === 1
				? reset( $errors )
				: 'Multiple requests failed: ' . implode( ', ', array_keys( $errors ) );
			// Partial success if some requests succeeded
			if ( ! empty( $results ) ) {
				$response['success'] = true;
				$response['partial'] = true;
			}
		}

		return $response;
	}

	/**
	 * Execute a single GET request.
	 *
	 * @param string $endpoint API endpoint path
	 * @param string $method   HTTP method (always GET)
	 * @param array  $data     Unused, kept for signature compatibility
	 * @return array Result with success, data/error, status
	 */
	private function executeSingleRequest( string $endpoint, string $method = 'GET', array $data = array() ): array {
		$parsed       = wp_parse_url( $endpoint );
		$path         = $parsed['path'] ?? $endpoint;
		$query_string = $parsed['query'] ?? '';

		$request = new \WP_REST_Request( 'GET', $path );

		if ( ! empty( $query_string ) ) {
			parse_str( $query_string, $query_params );
			$request->set_query_params( $query_params );
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$data   = $response->get_data();
		$status = $response->get_status();

		if ( $status >= 400 ) {
			$error_message = $data['message'] ?? 'Request failed with status ' . $status;
			return array(
				'success' => false,
				'error'   => $error_message,
				'status'  => $status,
			);
		}

		return array(
			'success' => true,
			'data'    => $data,
			'status'  => $status,
		);
	}

	/**
	 * Extract a key name from an endpoint path.
	 *
	 * @param string $endpoint Endpoint path
	 * @return string Generated key
	 */
	private function extractKeyFromEndpoint( string $endpoint ): string {
		// Parse and get path without query string
		$parsed = wp_parse_url( $endpoint );
		$path   = $parsed['path'] ?? $endpoint;

		// Remove /datamachine/v1/ prefix
		$path = preg_replace( '#^/datamachine/v\d+/#', '', $path );

		// Split into segments
		$segments = array_filter( explode( '/', $path ) );

		if ( empty( $segments ) ) {
			return 'result';
		}

		// Get main resource (first segment)
		$resource = array_shift( $segments );

		// If there's an ID (numeric second segment), append it
		if ( ! empty( $segments ) ) {
			$next = array_shift( $segments );
			if ( is_numeric( $next ) ) {
				$resource .= '_' . $next;
			} elseif ( ! empty( $next ) ) {
				// Sub-resource like /pipelines/5/steps
				$resource .= '_' . $next;
			}
		}

		return $resource;
	}
}
