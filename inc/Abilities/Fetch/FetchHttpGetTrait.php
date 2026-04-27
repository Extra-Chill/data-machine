<?php
/**
 * Shared HTTP GET helper for fetch abilities.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

defined( 'ABSPATH' ) || exit;

trait FetchHttpGetTrait {

	/**
	 * Make HTTP GET request.
	 */
	private function httpGet( string $url, array $options ): array {
		$args = array(
			'timeout' => $options['timeout'] ?? 30,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		return array(
			'success'     => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
			'data'        => $body,
		);
	}
}
