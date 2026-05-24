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

if ( $failed > 0 ) {
	echo "=== ability-result-wp-error-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}

echo "=== ability-result-wp-error-smoke: ALL PASS ({$total}) ===\n";
