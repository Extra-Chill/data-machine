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
	private function httpGet( string $url, array $options ): array|\WP_Error {
		$args = array(
			'timeout' => $options['timeout'] ?? 30,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'fetch_http_request_failed',
				$response->get_error_message(),
				array(
					'status'         => 502,
					'url'            => $url,
					'transport_code' => $response->get_error_code(),
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'fetch_http_status',
				sprintf( 'HTTP request returned status %d.', $status_code ),
				array(
					'status'      => $status_code ? $status_code : 502,
					'url'         => $url,
					'status_code' => $status_code,
					'body'        => $body,
				)
			);
		}

		return array(
			'success'     => true,
			'status_code' => $status_code,
			'data'        => $body,
		);
	}
}
