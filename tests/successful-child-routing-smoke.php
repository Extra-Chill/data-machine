<?php
/**
 * Pure-PHP regression for successful child lifecycle routing.
 *
 * Run with: php tests/successful-child-routing-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

$datamachine_successful_child_logs = array();

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_successful_child_logs'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
	function datamachine_get_engine_data( int $job_id ): array {
		unset( $job_id );
		return array();
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/StepExecutionResult.php';
require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
require_once __DIR__ . '/../inc/Engine/ExecutionPlan.php';
require_once __DIR__ . '/../inc/Engine/StepNavigator.php';
require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

class DataMachine_Successful_Child_Jobs extends Jobs {
	public array $transitions = array();

	public function __construct() {}

	public function transition_job_status_result( int $job_id, string $status, bool $require_final = false ): array {
		$this->transitions[] = array( $job_id, $status, $require_final );
		return array(
			'success'        => true,
			'changed'        => false,
			'current_status' => JobStatus::PROCESSING,
			'status'         => $status,
		);
	}
}

$reflection = new ReflectionClass( ExecuteStepAbility::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$jobs       = new DataMachine_Successful_Child_Jobs();
$reflection->getProperty( 'db_jobs' )->setValue( $ability, $jobs );
$route = $reflection->getMethod( 'routeAfterExecution' );

$result = $route->invoke(
	$ability,
	2930,
	'handler_step',
	9,
	array(
		'pipeline_id' => 3,
		'step_type'   => 'upsert',
	),
	'upsert',
	'DataMachine\\Core\\Steps\\Upsert\\UpsertStep',
	array( array( 'type' => 'handler_complete' ) ),
	array(
		'job_id' => 2930,
		'data'   => array( array( 'type' => 'handler_complete' ) ),
	),
	true,
	JobStatus::PROCESSING
);

$failures = 0;
$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}\n";
};

echo "=== successful-child-routing-smoke ===\n";
$assert( 'successful child completes normally', 'completed' === ( $result['outcome'] ?? '' ) );
$assert( 'stale processing override is not reported as completion', 'completed_override' !== ( $result['outcome'] ?? '' ) );
$assert( 'normal terminal transition is committed', array( 2930, JobStatus::COMPLETED, true ) === ( $jobs->transitions[0] ?? array() ) );
$assert(
	'non-terminal override is diagnosed',
	1 === count(
		array_filter(
			$GLOBALS['datamachine_successful_child_logs'],
			static fn( array $entry ): bool => 'datamachine_log' === ( $entry['hook'] ?? '' )
				&& 'Ignoring non-terminal job status override after successful step execution' === ( $entry['args'][1] ?? '' )
		)
	)
);

echo "\nSuccessful child routing smoke complete: {$failures} failures.\n";
exit( $failures > 0 ? 1 : 0 );
