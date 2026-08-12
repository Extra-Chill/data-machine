<?php
/**
 * Regression coverage for bounded owner-layer Action Scheduler claims.
 *
 * Run with: php tests/action-scheduler-claim-bounds-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';

		public function prepare( string $query, ...$args ): string {
			return $query;
		}

		public function get_row( string $query, string $output = '' ): array {
			return array(
				'action_id'          => 1,
				'scheduled_date_gmt' => '2026-08-12 00:00:00',
				'priority'           => 10,
			);
		}

		public function get_var( string $query ): int {
			return 1600000;
		}
	}
}

require_once __DIR__ . '/../inc/Core/ActionScheduler/ScopedDrainService.php';

use DataMachine\Core\ActionScheduler\ScopedDrainService;

$GLOBALS['wpdb'] = new wpdb();

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = "FAIL: {$label}";
	}
};

$method = new ReflectionMethod( ScopedDrainService::class, 'claimSizeForScope' );
$method->setAccessible( true );
$service = new ScopedDrainService();

$assert(
	500 === $method->invoke( $service, 100000, null, array(), '' ),
	'direct drain input is capped at 500 actions'
);
$assert(
	500 === $method->invoke( $service, 25, null, array( 42 ), '' ),
	'a 1.6M-row scoped queue cannot expand the claim beyond 500 actions'
);

if ( ! empty( $failures ) ) {
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "PASS: owner-layer Action Scheduler claims stay bounded at production scale\n";
