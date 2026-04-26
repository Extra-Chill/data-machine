<?php
/**
 * Shared handler HTTP request helpers.
 *
 * Fetch and publish handlers both expose the same protected convenience
 * methods for subclasses. Keeping them in one trait prevents the two base
 * classes from drifting apart.
 *
 * @package DataMachine\Core\Steps\Handlers
 */

namespace DataMachine\Core\Steps\Handlers;

use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

trait HttpRequestHelpers {

	/**
	 * Perform HTTP request with standardized handling.
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH).
	 * @param string $url     Request URL.
	 * @param array  $options Request options.
	 * @return array{success: bool, data?: string, status_code?: int, headers?: array, response?: array, error?: string}
	 */
	protected function httpRequest( string $method, string $url, array $options = array() ): array {
		if ( ! isset( $options['context'] ) ) {
			$options['context'] = ucfirst( $this->handler_type );
		}
		return HttpClient::request( $method, $url, $options );
	}

	/**
	 * Perform HTTP GET request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $options Request options.
	 * @return array Response array.
	 */
	protected function httpGet( string $url, array $options = array() ): array {
		return $this->httpRequest( 'GET', $url, $options );
	}

	/**
	 * Perform HTTP POST request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $options Request options.
	 * @return array Response array.
	 */
	protected function httpPost( string $url, array $options = array() ): array {
		return $this->httpRequest( 'POST', $url, $options );
	}

	/**
	 * Perform HTTP DELETE request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $options Request options.
	 * @return array Response array.
	 */
	protected function httpDelete( string $url, array $options = array() ): array {
		return $this->httpRequest( 'DELETE', $url, $options );
	}
}
