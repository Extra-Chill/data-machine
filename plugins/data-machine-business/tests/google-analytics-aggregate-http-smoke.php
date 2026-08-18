<?php
/**
 * HTTP-boundary smoke coverage for the bounded GA4 aggregate report.
 *
 * Run with: php tests/google-analytics-aggregate-http-smoke.php
 */

namespace DataMachine\Core {
	class HttpClient {
		public static array $response = array();
		public static array $request = array();

		public static function post( string $url, array $options ): array {
			self::$request = array( 'url' => $url, 'options' => $options );
			return self::$response;
		}
	}
}

namespace DataMachine\Engine\AI\Tools {
	abstract class BaseTool {}
}

namespace {
	use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
	use DataMachineBusiness\Engine\AI\Tools\Global\GoogleAnalytics;
	use DataMachine\Core\HttpClient;

	$root = dirname( __DIR__ );
	$failures = array();

	define( 'ABSPATH', $root . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}

	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_json_encode( $value ): string { return json_encode( $value ); }
	function wp_list_pluck( array $items, string $field ): array { return array_map( static fn( $item ) => $item[ $field ] ?? null, $items ); }

	require_once $root . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
	require_once $root . '/inc/Engine/AI/Tools/Global/GoogleAnalytics.php';

	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) { $failures[] = $message; }
	};
	$input = array( 'action' => 'aggregate_report', 'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ), 'metrics' => array( 'sessions' ) );
	$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'fetchAggregateReport' );
	$tool = ( new \ReflectionClass( GoogleAnalytics::class ) )->newInstanceWithoutConstructor();
	$parameters = $tool->getToolDefinition()['parameters']['oneOf'];
	$assert( GoogleAnalyticsAbilities::MAX_LIMIT === $parameters[0]['properties']['limit']['maximum'] && GoogleAnalyticsAbilities::AGGREGATE_MAX_ROWS === $parameters[1]['properties']['limit']['maximum'], 'advertises action-specific legacy and aggregate row bounds' );

	$fixture = json_decode( file_get_contents( $root . '/tests/fixtures/ga4-aggregate-batch-response.json' ), true );
	HttpClient::$response = array( 'success' => true, 'data' => json_encode( $fixture ) );
	$result = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( ! empty( $result['success'] ) && 'primary' === $result['reports'][0]['label'], 'normalizes the primary HTTP batch report' );
	$assert( GoogleAnalyticsAbilities::AGGREGATE_MAX_RESPONSE_BYTES === HttpClient::$request['options']['limit_response_size'], 'sets the 4 MiB HTTP response limit' );
	$assert( false === HttpClient::$request['options']['log_response_body_preview'], 'suppresses non-2xx response-body previews in HTTP logs' );
	$assert( 1 === count( json_decode( HttpClient::$request['options']['body'], true )['requests'] ), 'uses one batch request for one period' );
	$comparison_input = $input;
	$comparison_input['comparison_date_range'] = array( 'start_date' => '2025-01-01', 'end_date' => '2025-01-31' );
	$comparison_fixture = $fixture;
	$comparison_fixture['reports'][] = array( 'kind' => 'analyticsData#runReport', 'dimensionHeaders' => array(), 'metricHeaders' => array( array( 'name' => 'sessions' ) ), 'rows' => array(), 'totals' => array() );
	HttpClient::$response['data'] = json_encode( $comparison_fixture );
	$comparison = $method->invoke( null, $comparison_input, 'secret-token', '123456789' );
	$assert( ! empty( $comparison['success'] ) && array( 'primary', 'comparison' ) === array_column( $comparison['reports'], 'label' ), 'normalizes two batch reports in period order' );

	$mismatched_fixture = $fixture;
	$mismatched_fixture['reports'][0]['dimensionHeaders'] = array( array( 'name' => 'country' ) );
	$mismatched_fixture['reports'][0]['totals'] = array();
	HttpClient::$response['data'] = json_encode( $mismatched_fixture );
	$mismatch = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( empty( $mismatch['success'] ) && 'Google Analytics returned a malformed aggregate report.' === $mismatch['error'], 'rejects response headers that do not match selected fields' );
	$invalid_batch_kind = $fixture;
	$invalid_batch_kind['kind'] = 'analyticsData#runReport';
	HttpClient::$response['data'] = json_encode( $invalid_batch_kind );
	$invalid_batch = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( empty( $invalid_batch['success'] ) && 'Google Analytics returned an unexpected aggregate report response.' === $invalid_batch['error'], 'rejects an incorrect batch response kind' );
	$invalid_report_kind = $fixture;
	$invalid_report_kind['reports'][0]['kind'] = 'analyticsData#batchRunReports';
	HttpClient::$response['data'] = json_encode( $invalid_report_kind );
	$invalid_report = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( empty( $invalid_report['success'] ) && 'Google Analytics returned a malformed aggregate report.' === $invalid_report['error'], 'rejects an incorrect report response kind' );
	HttpClient::$response = array(
		'success' => false,
		'status_code' => 403,
		'error' => 'Google Analytics Data API aggregate report POST returned HTTP 403: filter-value-secret',
		'data' => json_encode( array( 'error' => array( 'code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Filter value filter-value-secret is not allowed.' ) ) ),
	);
	$permission_denied = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( 'GA4 API error (403 PERMISSION_DENIED): Permission denied.' === $permission_denied['error'] && false === str_contains( $permission_denied['error'], 'filter-value-secret' ), 'canonicalizes PERMISSION_DENIED without provider message leakage' );
	HttpClient::$response = array( 'success' => false, 'status_code' => 502, 'error' => 'upstream raw-body-secret', 'data' => '<html>raw-body-secret</html>' );
	$malformed_error = $method->invoke( null, $input, 'secret-token', '123456789' );
	$assert( 'GA4 request failed with HTTP 502.' === $malformed_error['error'] && false === str_contains( $malformed_error['error'], 'raw-body-secret' ), 'uses a status-aware fallback without raw body leakage' );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo "11 assertions passed.\n";
}
