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

if ( $failed > 0 ) {
	echo "=== ability-result-wp-error-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}

echo "=== ability-result-wp-error-smoke: ALL PASS ({$total}) ===\n";
