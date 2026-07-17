<?php
/**
 * Dependency-free smoke test for Mediavine dimensional contracts.
 *
 * Run with: php tests/mediavine-dimensional-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace DataMachine\Cli {
	class BaseCommand {
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	class WP_Error {
		public function __construct( private string $code, private string $message ) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_CLI {
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/Analytics/MediavineReportsAbilities.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/MediavineCommand.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';

	use DataMachineBusiness\Abilities\Analytics\MediavineReportsAbilities;
	use DataMachineBusiness\Cli\MediavineCommand;
	use DataMachineBusiness\Cli\CommandRegistry;

	$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mediavine-dimensional-reports.json' ), true );
	$failures = array();
	$passes   = 0;

	$assert = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			$passes++;
			return;
		}
		$failures[] = $label;
	};

	$expected_contracts = array(
		'devices'   => array( 'DevicesMetricsSummaryQuery', 'GetDevicesMetricsSummaryInput!', 'devicesMetricsSummary' ),
		'countries' => array( 'CountriesReportQuery', 'GetCountriesReportInput!', 'countriesReport' ),
		'sources'   => array( 'SourceReportsQuery', 'GetSourceReportsInput!', 'sourceReports' ),
		'ad_units'  => array( 'AdunitsMetricsQuery', 'GetAdunitsMetricsInput!', 'adunitsMetrics' ),
	);

	foreach ( $expected_contracts as $action => $expected ) {
		$body   = MediavineReportsAbilities::buildDimensionalRequestBody( $action, 'relay-id', '2026-07-10', '2026-07-16' );
		$parsed = MediavineReportsAbilities::parseDimensionalPayload( $action, $fixtures[ $action ], '2026-07' );
		$assert( $expected[0] === $body['operationName'], $action . ' operation name' );
		$assert( str_contains( $body['query'], $expected[1] ), $action . ' input type' );
		$assert( str_contains( $body['query'], $expected[2] ), $action . ' root operation' );
		$assert( ! is_wp_error( $parsed ) && 2 === count( $parsed['rows'] ), $action . ' parses two fixture rows' );
		$assert( 2 === $parsed['meta']['totalCount'], $action . ' typed row count' );
	}

	$ad_units = MediavineReportsAbilities::parseDimensionalPayload( 'ad_units', $fixtures['ad_units'], '2026-07' );
	$assert( 'parent' === $ad_units['rows'][0]['grain'] && null === $ad_units['rows'][0]['deviceType'], 'parent ad-unit grain' );
	$assert( 'child' === $ad_units['rows'][1]['grain'] && 'Mobile' === $ad_units['rows'][1]['deviceType'], 'child ad-unit device grain' );

	$envelope = array( 'success' => true, 'action' => 'ad_units', 'results' => $ad_units['rows'], 'provenance' => array( 'source' => array( 'operation' => 'AdunitsMetricsQuery' ) ) );
	$assert( $envelope === json_decode( MediavineCommand::jsonOutput( $envelope ), true ), 'CLI JSON preserves envelope' );
	$assert( MediavineCommand::tableFields( 'ad_units' ) === array_keys( MediavineCommand::tabularRows( $envelope )[0] ), 'CLI table/CSV fields are stable' );
	$assert( MediavineCommand::class === CommandRegistry::map()['datamachine analytics mediavine'], 'CLI command is registered' );
	foreach ( array( 'password', 'Bearer ', 'accessToken', 'refreshToken', 'userId' ) as $secret_key ) {
		$assert( ! str_contains( (string) wp_json_encode( $fixtures ), $secret_key ), 'fixtures omit ' . $secret_key );
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "Mediavine dimensional smoke failed:\n - " . implode( "\n - ", $failures ) . "\n" );
		exit( 1 );
	}

	echo "Mediavine dimensional smoke: {$passes} assertions passed.\n";
}
