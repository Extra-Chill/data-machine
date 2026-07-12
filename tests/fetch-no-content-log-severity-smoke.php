<?php
/**
 * Pure-PHP smoke test for empty-fetch log severity (#2873).
 *
 * Empty fetch results are routine, expected outcomes (filtered sources,
 * quiet windows) that terminate in the success-family `completed_no_items`
 * status. They must not log at `error`, and the paired
 * "Step execution did not produce a successful result" WARNING must not
 * fire for that status either — both would be noise for a successful
 * empty window.
 *
 * Run with: php tests/fetch-no-content-log-severity-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$datamachine_no_content_severity_logs = array();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_no_content_severity_logs'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';
require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\StepExecutionResult;

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}\n";
};

echo "=== fetch-no-content-log-severity-smoke ===\n";

echo "\n[1] FetchStep logs empty results at info, not error\n";
$fetch_step = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';

$assert(
	'FetchStep no-content branch logs at info level',
	str_contains( $fetch_step, "\$this->log( 'info', 'Fetch handler returned no content' );" )
);
$assert(
	'FetchStep no longer logs no-content at error level',
	! str_contains( $fetch_step, "\$this->log( 'error', 'Fetch handler returned no content' );" )
);
$assert(
	'FetchStep still logs actual handler failures at error level',
	str_contains( $fetch_step, "\$this->log( 'error', 'Fetch handler failed: ' . \$packets->get_error_message() );" )
);
$assert(
	'FetchStep still records no_content in RunMetrics unchanged',
	str_contains( $fetch_step, "'result'       => 'no_content'," )
);

echo "\n[2] logStepExecutionResult skips the paired WARNING for completed_no_items\n";

$run_log = function ( array $execution_result ): array {
	$GLOBALS['datamachine_no_content_severity_logs'] = array();

	$reflection = new ReflectionClass( ExecuteStepAbility::class );
	$ability    = $reflection->newInstanceWithoutConstructor();
	$method     = $reflection->getMethod( 'logStepExecutionResult' );
	$method->invoke( $ability, $execution_result, 123, 'fetch_1', 'fetch' );

	return $GLOBALS['datamachine_no_content_severity_logs'];
};

// Real classification path: an empty-packet fetch step classifies to
// completed_no_items via StepExecutionResult::classify(), exactly like
// FetchStep's empty-return path does.
$classified = StepExecutionResult::classify( array(), 'fetch' );
$assert( 'classify() marks empty fetch packets as completed_no_items', 'completed_no_items' === $classified['status'] );

$logs = $run_log( $classified );
$assert( 'no WARNING is fired for completed_no_items status', 0 === count( $logs ) );

// A genuine failure (e.g. empty packets on a non-fetch step) must still warn.
$failed_classified = StepExecutionResult::classify( array(), 'ai' );
$assert( 'classify() marks empty non-fetch packets as failed', 'failed' === $failed_classified['status'] );

$failed_logs = $run_log( $failed_classified );
$assert( 'WARNING still fires for genuine failed status', 1 === count( $failed_logs ) );
$assert(
	'WARNING message is unchanged for genuine failures',
	isset( $failed_logs[0]['args'][1] ) && 'Step execution did not produce a successful result' === $failed_logs[0]['args'][1]
);

if ( $failures > 0 ) {
	echo "\n=== fetch-no-content-log-severity-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== fetch-no-content-log-severity-smoke: ALL PASS ({$total} assertions) ===\n";
