<?php
/**
 * Pure-PHP smoke test for preserving explicit AI failure job statuses.
 *
 * Run with: php tests/ai-error-status-propagation-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function datamachine_empty_action_log(): array {
	return getenv( 'DATAMACHINE_SMOKE_ACTION_SEED' ) ? array( array( 'hook' => '', 'args' => array() ) ) : array();
}

$datamachine_action_log = datamachine_empty_action_log();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		global $datamachine_action_log;
		$datamachine_action_log[] = array(
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

$datamachine_test_engine_data = array();

if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
	function datamachine_get_engine_data( int $job_id ): array {
		global $datamachine_test_engine_data;
		return $datamachine_test_engine_data[ $job_id ] ?? array();
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';
require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\Database\Jobs\Jobs;

class DataMachine_Test_Jobs_For_AI_Status extends Jobs {
	private string $status;
	public ?string $completed_status = null;

	public function __construct( string $status ) {
		$this->status = $status;
	}

	public function get_job( int $job_id ): ?array {
		return array(
			'job_id' => $job_id,
			'status' => $this->status,
		);
	}

	public function complete_job( int $job_id, string $status ): bool {
		unset( $job_id );
		$this->completed_status = $status;
		return true;
	}
}

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

$actions_by_hook = function ( string $hook ) use ( &$datamachine_action_log ): array {
	$matches = array();
	foreach ( $datamachine_action_log as $entry ) {
		if ( $hook === ( $entry['hook'] ?? '' ) ) {
			$matches[] = $entry;
		}
	}

	return $matches;
};

$logs_with_message = function ( string $message ) use ( &$datamachine_action_log ): array {
	$matches = array();
	foreach ( $datamachine_action_log as $entry ) {
		if ( 'datamachine_log' === ( $entry['hook'] ?? '' ) && $message === ( $entry['args'][1] ?? '' ) ) {
			$matches[] = $entry;
		}
	}

	return $matches;
};

$build_ability = function ( string $status ): array {
	$reflection = new ReflectionClass( ExecuteStepAbility::class );
	$ability    = $reflection->newInstanceWithoutConstructor();

	$db_jobs = $reflection->getProperty( 'db_jobs' );
	$db_jobs->setValue( $ability, new DataMachine_Test_Jobs_For_AI_Status( $status ) );

	$route = $reflection->getMethod( 'routeAfterExecution' );

	return array( $ability, $route );
};

$invoke_empty_ai_route = function ( string $status ) use ( $build_ability ): array {
	list( $ability, $route ) = $build_ability( $status );

	return $route->invoke(
		$ability,
		123,
		'ai_step',
		9,
		array(
			'pipeline_id' => 3,
			'step_type'   => 'ai',
		),
		'ai',
		'DataMachine\\Core\\Steps\\AI\\AIStep',
		array(),
		array( 'data' => array() ),
		false,
		null
	);
};

$invoke_empty_fetch_route = function ( string $status ) use ( $build_ability ): array {
	list( $ability, $route ) = $build_ability( $status );

	return $route->invoke(
		$ability,
		123,
		'fetch_step',
		9,
		array(
			'pipeline_id' => 3,
			'step_type'   => 'fetch',
		),
		'fetch',
		'DataMachine\Core\Steps\Fetch\FetchStep',
		array(),
		array( 'data' => array() ),
		false,
		null
	);
};

$fetch_completed_status = function ( string $status ) use ( $build_ability ): ?string {
	list( $ability, $route_method ) = $build_ability( $status );
	$reflection = new ReflectionClass( $ability );
	$db_jobs    = $reflection->getProperty( 'db_jobs' )->getValue( $ability );

	$route_method->invoke(
		$ability,
		123,
		'fetch_step',
		9,
		array(
			'pipeline_id' => 3,
			'step_type'   => 'fetch',
		),
		'fetch',
		'DataMachine\Core\Steps\Fetch\FetchStep',
		array(),
		array( 'data' => array() ),
		false,
		null
	);

	return $db_jobs->completed_status;
};

echo "=== ai-error-status-propagation-smoke ===\n";

$short_circuit_message = 'Step returned no data after job was already marked failed or rescheduled for retry';

echo "\n[1] explicit AI failure is not reclassified as empty packets\n";
$datamachine_action_log       = datamachine_empty_action_log();
$datamachine_test_engine_data = array();
$result                       = $invoke_empty_ai_route( 'failed - ai_processing_failed' );
$failed_jobs                  = $actions_by_hook( 'datamachine_fail_job' );
$debug_logs                   = $logs_with_message( $short_circuit_message );

$assert( 'route still reports failed outcome', 'failed' === ( $result['outcome'] ?? '' ) );
$assert( 'route preserves persisted AI failure status as error', 'failed - ai_processing_failed' === ( $result['error'] ?? '' ) );
$assert( 'generic fail-job action is not emitted again', empty( $failed_jobs ) );
$assert( 'debug log records already-failed short circuit', 1 === count( $debug_logs ) );

echo "\n[2] ordinary empty AI packet failures still use existing generic path\n";
$datamachine_action_log       = datamachine_empty_action_log();
$datamachine_test_engine_data = array();
$result                       = $invoke_empty_ai_route( 'processing' );
$failed_jobs                  = $actions_by_hook( 'datamachine_fail_job' );
$last_failure                 = end( $failed_jobs );
$failure_reason               = is_array( $last_failure ) ? ( $last_failure['args'][2]['reason'] ?? '' ) : '';

$assert( 'ordinary empty packet route still fails', 'failed' === ( $result['outcome'] ?? '' ) );
$assert( 'ordinary empty packet still emits fail-job action', 1 === count( $failed_jobs ) );
$assert( 'ordinary empty packet reason remains empty_data_packet_returned', 'empty_data_packet_returned' === $failure_reason );

echo "\n[3] pending-status job with a scheduled retry short-circuits without re-firing fail-job\n";
$datamachine_action_log       = datamachine_empty_action_log();
$datamachine_test_engine_data = array(
	123 => array(
		'retry' => array(
			'attempts'      => 1,
			'next_retry_at' => gmdate( 'c', time() + 60 ),
		),
	),
);
$result      = $invoke_empty_ai_route( 'pending' );
$failed_jobs = $actions_by_hook( 'datamachine_fail_job' );
$debug_logs  = $logs_with_message( $short_circuit_message );

$assert( 'pending+retry route reports failed outcome', 'failed' === ( $result['outcome'] ?? '' ) );
$assert( 'pending+retry route surfaces pending status as error marker', 'pending' === ( $result['error'] ?? '' ) );
$assert( 'pending+retry route does NOT emit a second fail-job action', empty( $failed_jobs ) );
$assert( 'pending+retry route logs the short-circuit message', 1 === count( $debug_logs ) );

echo "\n[4] pending-status job WITHOUT retry metadata still falls through to fail-job\n";
$datamachine_action_log       = datamachine_empty_action_log();
$datamachine_test_engine_data = array();
$result                       = $invoke_empty_ai_route( 'pending' );
$failed_jobs                  = $actions_by_hook( 'datamachine_fail_job' );
$last_failure                 = end( $failed_jobs );
$failure_reason               = is_array( $last_failure ) ? ( $last_failure['args'][2]['reason'] ?? '' ) : '';

$assert( 'pending without retry still fails through generic path', 'failed' === ( $result['outcome'] ?? '' ) );
$assert( 'pending without retry still emits fail-job action', 1 === count( $failed_jobs ) );
$assert( 'pending without retry reason is empty_data_packet_returned', 'empty_data_packet_returned' === $failure_reason );

echo "\n[5] explicit fetch failure is not reclassified as completed_no_items\n";
$datamachine_action_log       = datamachine_empty_action_log();
$datamachine_test_engine_data = array();
$result                       = $invoke_empty_fetch_route( 'failed - mcp_provider_access_forbidden' );
$failed_jobs                  = $actions_by_hook( 'datamachine_fail_job' );
$debug_logs                   = $logs_with_message( $short_circuit_message );
$completed_status             = $fetch_completed_status( 'failed - mcp_provider_access_forbidden' );

$assert( 'fetch route preserves prior provider failure', 'failed' === ( $result['outcome'] ?? '' ) );
$assert( 'fetch route surfaces provider failure status', 'failed - mcp_provider_access_forbidden' === ( $result['error'] ?? '' ) );
$assert( 'fetch route does not emit a second fail-job action', empty( $failed_jobs ) );
$assert( 'fetch route does not complete failed job as no-items', null === $completed_status );
$assert( 'fetch route logs the short-circuit message', 1 <= count( $debug_logs ) );

if ( $failures > 0 ) {
	echo "\n=== ai-error-status-propagation-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== ai-error-status-propagation-smoke: ALL PASS ({$total} assertions) ===\n";
