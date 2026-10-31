<?php
/**
 * Behavior smoke test for bundle egress target registry.
 *
 * Run with: php tests/bundle-egress-target-registry-smoke.php
 */

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-bundle-egress-test/' );
	$GLOBALS['datamachine_test_filters'] = array();

	$datamachine_test_stderr = static function ( string $message ): void {
		$stream = defined( 'STDERR' ) ? STDERR : fopen( 'php://stderr', 'wb' );
		if ( is_resource( $stream ) ) {
			fwrite( $stream, $message );
		}
	};

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
			unset( $args );
			$override = $GLOBALS['datamachine_test_filters'][ $hook ] ?? null;
			if ( is_callable( $override ) ) {
				return $override( $value );
			}
			return $value;
		}
	}
}

namespace DataMachine\Engine\Bundle {
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleValidationException.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleEgressTargetRegistry.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleSchema.php';
}

namespace {
	use DataMachine\Engine\Bundle\BundleSchema;

	$assertions = 0;
	$assert     = function ( string $label, bool $condition ) use ( &$assertions, $datamachine_test_stderr ): void {
		++$assertions;
		if ( ! $condition ) {
			$datamachine_test_stderr( "FAIL: {$label}\n" );
			exit( 1 );
		}
		echo "ok - {$label}\n";
	};

	echo "=== Bundle Egress Target Registry Smoke ===\n";

	$policy = BundleSchema::normalize_run_artifact_egress_policy(
		array(
			'daily_memory' => array(
				'egress'               => array( 'artifact', 'custom-store' ),
				'bundle_relative_path' => 'memory/daily.md',
			),
		)
	);
	$assert( 'unknown egress target is still dropped by default', array( 'artifact' ) === ( $policy['daily_memory']['egress'] ?? array() ) );

	$register_custom_store = function ( array $targets ): array {
		$targets[] = 'custom-store';
		return $targets;
	};
	if ( function_exists( 'add_filter' ) ) {
		add_filter( 'datamachine_bundle_run_artifact_egress_targets', $register_custom_store );
	} else {
		$GLOBALS['datamachine_test_filters']['datamachine_bundle_run_artifact_egress_targets'] = $register_custom_store;
	}

	$policy = BundleSchema::normalize_run_artifact_egress_policy(
		array(
			'daily_memory' => array(
				'egress'               => array( 'artifact', 'custom-store' ),
				'bundle_relative_path' => 'memory/daily.md',
			),
		)
	);
	$assert( 'registered custom egress target is preserved', array( 'artifact', 'custom-store' ) === ( $policy['daily_memory']['egress'] ?? array() ) );

	echo "\nAssertions: {$assertions}\n";
	echo "PASS\n";
}
