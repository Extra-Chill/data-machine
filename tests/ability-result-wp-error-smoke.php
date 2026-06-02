<?php
/**
 * Smoke test for WP_Error-safe ability result consumption.
 *
 * Run with: php tests/ability-result-wp-error-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response;
	}
}

require_once __DIR__ . '/../inc/Core/AbilityResult.php';

use DataMachine\Core\AbilityResult;

$failed = 0;
$total  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
};

$wp_error_result = AbilityResult::normalize(
	new WP_Error(
		'rest_forbidden',
		'Permission denied.',
		array( 'status' => 403 )
	)
);

$assert( 'WP_Error normalizes to failed legacy result', false === $wp_error_result['success'] );
$assert( 'WP_Error message is preserved', 'Permission denied.' === $wp_error_result['error'] );
$assert( 'WP_Error code is preserved', 'rest_forbidden' === $wp_error_result['wp_error_code'] );
$assert( 'WP_Error data is preserved', 403 === ( $wp_error_result['wp_error_data']['status'] ?? null ) );

$array_result = array(
	'success' => false,
	'error'   => 'Legacy failure.',
);
$assert( 'legacy arrays pass through unchanged', $array_result === AbilityResult::normalize( $array_result ) );

$scalar_result = AbilityResult::normalize( 'ok' );
$assert( 'non-array successful callback values become success arrays', true === $scalar_result['success'] );
$assert( 'non-array callback data is retained', 'ok' === $scalar_result['data'] );

$tool_error_result = AbilityResult::normalize_tool_result(
	new WP_Error( 'tool_forbidden', 'Tool denied.', array( 'status' => 403 ) ),
	'demo_tool',
	'datamachine/demo'
);
$assert( 'tool WP_Error normalizes to failed tool result', false === $tool_error_result['success'] );
$assert( 'tool WP_Error keeps tool name', 'demo_tool' === $tool_error_result['tool_name'] );
$assert( 'tool WP_Error keeps ability slug', 'datamachine/demo' === $tool_error_result['ability'] );
$assert( 'tool WP_Error code is preserved', 'tool_forbidden' === $tool_error_result['wp_error_code'] );

$tool_array_result = array( 'success' => true, 'custom' => 'value' );
$assert( 'tool array results pass through unchanged', $tool_array_result === AbilityResult::normalize_tool_result( $tool_array_result, 'demo_tool', 'datamachine/demo' ) );

$tool_scalar_result = AbilityResult::normalize_tool_result( 'ok', 'demo_tool', 'datamachine/demo' );
$assert( 'tool scalar result is wrapped as success', true === $tool_scalar_result['success'] );
$assert( 'tool scalar result payload uses tool result key', 'ok' === $tool_scalar_result['result'] );
$assert( 'tool scalar result does not mirror payload into data key', ! array_key_exists( 'data', $tool_scalar_result ) );

$legacy_error = AbilityResult::legacy_failure_to_wp_error(
	array(
		'success' => false,
		'error'   => 'session_not_found',
	),
	'ability_failed',
	'Ability execution failed.',
	array( 'session_not_found' => 404 ),
	500,
	true
);
$assert( 'legacy failure arrays convert to WP_Error', $legacy_error instanceof WP_Error );
$assert( 'legacy failure error code is preserved', 'session_not_found' === $legacy_error->get_error_code() );
$assert( 'legacy failure status map is applied', 404 === ( $legacy_error->get_error_data()['status'] ?? null ) );
$assert( 'successful arrays do not convert to WP_Error', null === AbilityResult::legacy_failure_to_wp_error( array( 'success' => true ) ) );

$default_code_error = AbilityResult::legacy_failure_to_wp_error(
	array(
		'success' => false,
		'error'   => 'Human readable failure.',
	),
	'email_error',
	'Operation failed.',
	array(),
	400
);
$assert( 'legacy failures default to provided error code', 'email_error' === $default_code_error->get_error_code() );
$assert( 'legacy failures keep error message', 'Human readable failure.' === $default_code_error->get_error_message() );

$status_error = AbilityResult::failure_to_wp_error(
	array(
		'success'    => false,
		'error'      => 'Pipeline not found.',
		'error_code' => 'pipeline_not_found',
		'status'     => 404,
	),
	'pipeline_failed',
	'Pipeline failed.',
	500
);
$assert( 'machine-readable legacy error_code is preserved', 'pipeline_not_found' === $status_error->get_error_code() );
$assert( 'machine-readable status controls HTTP status', 404 === ( $status_error->get_error_data()['status'] ?? null ) );

$collection = AbilityResult::collection_envelope(
	array(
		'success'     => true,
		'pipelines'   => array( array( 'pipeline_id' => 7 ) ),
		'total'       => 1,
		'per_page'    => 20,
		'offset'      => 0,
		'output_mode' => 'list',
	),
	'pipelines',
	array(
		'data_key'  => 'pipelines',
		'top_extra' => array( 'output_mode' ),
	)
);
$assert( 'collection envelope keeps success flag', true === $collection['success'] );
$assert( 'collection envelope puts items under data key', 7 === ( $collection['data']['pipelines'][0]['pipeline_id'] ?? null ) );
$assert( 'collection envelope keeps pagination metadata at top level', 20 === ( $collection['per_page'] ?? null ) );
$assert( 'collection envelope does not mirror pagination metadata into data aliases', ! array_key_exists( 'total', $collection['data'] ) );
$assert( 'collection envelope carries requested top-level extras', 'list' === ( $collection['output_mode'] ?? null ) );

$rest_collection_error = AbilityResult::rest_collection_response(
	array(
		'success'    => false,
		'error'      => 'No jobs.',
		'error_code' => 'jobs_unavailable',
		'status'     => 503,
	),
	'jobs',
	array(),
	'get_jobs_failed'
);
$assert( 'REST collection presenter returns WP_Error for failed ability results', $rest_collection_error instanceof WP_Error );
$assert( 'REST collection presenter preserves failure status', 503 === ( $rest_collection_error->get_error_data()['status'] ?? null ) );

$rest_item = AbilityResult::rest_item_response(
	array( 'success' => true ),
	array( 'job_id' => 9 ),
	array( 'message' => 'ok' )
);
$assert( 'REST item presenter wraps single resource data', 9 === ( $rest_item['data']['job_id'] ?? null ) );
$assert( 'REST item presenter includes explicit top-level extras', 'ok' === ( $rest_item['message'] ?? null ) );

$item_rest = AbilityResult::rest_item_response(
	array(
		'success' => true,
		'message' => 'Paused flows.',
		'paused'  => 3,
	)
);
$assert( 'REST item presenter preserves success flag', true === ( $item_rest['success'] ?? null ) );
$assert( 'REST item presenter wraps mutation fields under data', 3 === ( $item_rest['data']['paused'] ?? null ) );

$cli_rows = array(
	array(
		'id'     => 12,
		'source' => 'pipeline',
	),
);
$cli_result = array(
	'success'  => true,
	'jobs'     => array(),
	'total'    => 1,
	'per_page' => 20,
	'offset'   => 0,
);
$assert( 'CLI collection payload defaults to legacy row array', $cli_rows === AbilityResult::cli_collection_payload( $cli_rows, $cli_result, 'jobs' ) );
$cli_envelope = AbilityResult::cli_collection_payload( $cli_rows, $cli_result, 'jobs', true );
$assert( 'CLI collection payload can opt into shared envelope', 12 === ( $cli_envelope['data'][0]['id'] ?? null ) );
$assert( 'CLI envelope opt-in includes pagination metadata', 20 === ( $cli_envelope['per_page'] ?? null ) );

if ( $failed > 0 ) {
	echo "=== ability-result-wp-error-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}

echo "=== ability-result-wp-error-smoke: ALL PASS ({$total}) ===\n";
