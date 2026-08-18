<?php
/**
 * Verify the upstream Data Machine HttpClient contract used by aggregate_report.
 *
 * Run with: WP_PATH=/path/to/wordpress DATA_MACHINE_PATH=/path/to/data-machine php tests/data-machine-http-client-contract-smoke.php
 */

$wp_path = getenv( 'WP_PATH' );
$data_machine_path = getenv( 'DATA_MACHINE_PATH' );
if ( empty( $wp_path ) || empty( $data_machine_path ) || ! file_exists( $wp_path . '/wp-load.php' ) || ! file_exists( $data_machine_path . '/inc/Core/HttpClient.php' ) ) {
	fwrite( STDERR, "WP_PATH and DATA_MACHINE_PATH must point to WordPress and Data Machine.\n" );
	exit( 2 );
}

require_once $wp_path . '/wp-load.php';
require_once $data_machine_path . '/inc/Core/HttpClient.php';

$method = new ReflectionMethod( \DataMachine\Core\HttpClient::class, 'buildRequestArgs' );
$args = $method->invoke( null, 'POST', array( 'limit_response_size' => 4194304 ) );
if ( 4194304 !== ( $args['limit_response_size'] ?? null ) ) {
	fwrite( STDERR, "Data Machine HttpClient did not forward limit_response_size.\n" );
	exit( 1 );
}

$log_context = null;
$preempt = static function ( $preempt, $parsed_args, $url ) {
	return array(
		'headers'  => array(),
		'body'     => 'response-body-secret',
		'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
$log = static function ( $level, $message, $context ) use ( &$log_context ): void {
	if ( 'HTTP Request: Error response' === $message ) {
		$log_context = $context;
	}
};
add_filter( 'pre_http_request', $preempt, 10, 3 );
add_action( 'datamachine_log', $log, 10, 3 );
$result = \DataMachine\Core\HttpClient::post( 'https://example.invalid/', array( 'log_response_body_preview' => false ) );
remove_filter( 'pre_http_request', $preempt, 10 );
remove_action( 'datamachine_log', $log, 10 );
if ( ! empty( $result['success'] ) || ! is_array( $log_context ) || array_key_exists( 'body_preview', $log_context ) ) {
	fwrite( STDERR, "Data Machine HttpClient did not suppress the non-2xx response-body preview.\n" );
	exit( 1 );
}

echo "2 assertions passed.\n";
