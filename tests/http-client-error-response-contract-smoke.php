<?php
/**
 * Smoke coverage for HttpClient error response metadata.
 *
 * Run with: php tests/http-client-error-response-contract-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url(): string {
		return 'https://example.test';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
		unset( $url, $args );
		return array_shift( $GLOBALS['datamachine_http_client_responses'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( array $response ): array {
		return $response['headers'] ?? array();
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['datamachine_http_client_logs'][] = $args;
		}
	}
}

require_once __DIR__ . '/../inc/Core/HttpClient.php';

use DataMachine\Core\HttpClient;

$failed = 0;
$total  = 0;

function http_client_error_contract_assert( string $label, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
}

$normal_response = array(
	'headers'  => array( 'Content-Type' => 'application/json' ),
	'body'     => '{"message":"Invalid request"}',
	'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
);

$rate_limit_response = array(
	'headers'  => array(
		'Retry-After' => '120',
		'Set-Cookie'  => 'session=secret',
	),
	'body'     => '{"error":"Rate limited"}',
	'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
);

$GLOBALS['datamachine_http_client_responses'] = array(
	$normal_response,
	$rate_limit_response,
	new WP_Error( 'http_request_failed', 'Connection timed out' ),
);
$GLOBALS['datamachine_http_client_logs'] = array();

echo "\n[1] Received non-2xx response\n";
$normal_result = HttpClient::get( 'https://example.test/invalid', array( 'context' => 'Contract Test' ) );

http_client_error_contract_assert( 'non-2xx response fails', false === $normal_result['success'] );
http_client_error_contract_assert( 'non-2xx response preserves status code', 400 === $normal_result['status_code'] );
http_client_error_contract_assert( 'non-2xx response preserves headers', 'application/json' === ( $normal_result['headers']['Content-Type'] ?? null ) );
http_client_error_contract_assert( 'non-2xx response preserves body as data', $normal_response['body'] === $normal_result['data'] );
http_client_error_contract_assert( 'non-2xx response preserves raw response', $normal_response === $normal_result['response'] );
http_client_error_contract_assert( 'non-2xx response retains normalized error', str_contains( $normal_result['error'], 'Invalid request' ) );

echo "\n[2] Rate limit metadata\n";
$rate_limit_result = HttpClient::get( 'https://example.test/limited', array( 'context' => 'Contract Test' ) );

http_client_error_contract_assert( '429 response preserves status code', 429 === $rate_limit_result['status_code'] );
http_client_error_contract_assert( '429 response preserves Retry-After', '120' === ( $rate_limit_result['headers']['Retry-After'] ?? null ) );
http_client_error_contract_assert( 'response headers are not copied into logs', ! str_contains( serialize( $GLOBALS['datamachine_http_client_logs'] ), 'session=secret' ) );

echo "\n[3] Transport failure distinction\n";
$transport_result = HttpClient::get( 'https://example.test/unreachable', array( 'context' => 'Contract Test' ) );

http_client_error_contract_assert( 'transport failure fails', false === $transport_result['success'] );
http_client_error_contract_assert( 'transport failure has a non-empty error', '' !== $transport_result['error'] );
http_client_error_contract_assert( 'transport failure has no status code', ! array_key_exists( 'status_code', $transport_result ) );
http_client_error_contract_assert( 'transport failure has no response headers', ! array_key_exists( 'headers', $transport_result ) );
http_client_error_contract_assert( 'transport failure has no raw response', ! array_key_exists( 'response', $transport_result ) );

echo "\n{$total} assertions, {$failed} failures\n";
exit( $failed > 0 ? 1 : 0 );
