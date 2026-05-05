<?php
/**
 * Pure-PHP smoke test for the AI step's soft-skip terminal status.
 *
 * AIStep sets a `job_status` engine override of "agent_skipped - ai_step_no_actionable_output"
 * when the AI loop completed without an error but produced no actionable output.
 * This test asserts that ExecuteStepAbility::routeAfterExecution() routes that
 * override through the existing status-override branch — completing the job
 * with `agent_skipped` and NOT firing `datamachine_fail_job`.
 *
 * Run with: php tests/ai-step-soft-skip-empty-output-smoke.php
 *
 * @package DataMachine\Tests
 */

// Stub the FileCleanup + DirectoryManager classes the engine instantiates
// during the status-override branch of routeAfterExecution(). The smoke does
// not exercise filesystem cleanup; replacing the real classes keeps this a
// pure-PHP test.
namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
	}

	class FileCleanup {
		public function __construct() {
		}

		public function cleanup_job_data_packets( int $job_id, array $context ): void {
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$datamachine_action_log = array();

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

	if ( ! function_exists( 'datamachine_get_engine_data' ) ) {
		function datamachine_get_engine_data( int $job_id ): array {
			return array();
		}
	}

	if ( ! function_exists( 'datamachine_get_file_context' ) ) {
		function datamachine_get_file_context( $flow_id ): array {
			return array(
				'flow_id'          => $flow_id,
				'data_packets_dir' => sys_get_temp_dir(),
			);
		}
	}

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php';
	require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
	require_once __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php';

	use DataMachine\Abilities\Engine\ExecuteStepAbility;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\JobStatus;

	class DataMachine_Test_Jobs_For_Soft_Skip extends Jobs {
		public array $completed = array();

		public function __construct() {
			// Skip parent constructor — bypasses $wpdb access not available in pure-PHP smoke.
		}

		public function get_job( int $job_id ): ?array {
			return array(
				'job_id' => $job_id,
				'status' => 'processing',
			);
		}

		public function complete_job( int $job_id, $status ): bool {
			$this->completed[] = array(
				'job_id' => $job_id,
				'status' => (string) $status,
			);
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

	$invoke_route = function ( $status_override ) use ( &$datamachine_action_log ) {
		$datamachine_action_log = array();

		$reflection = new \ReflectionClass( ExecuteStepAbility::class );
		$ability    = $reflection->newInstanceWithoutConstructor();

		$db_jobs_property = $reflection->getProperty( 'db_jobs' );
		$db_jobs          = new DataMachine_Test_Jobs_For_Soft_Skip();
		$db_jobs_property->setValue( $ability, $db_jobs );

		$route = $reflection->getMethod( 'routeAfterExecution' );

		$result = $route->invoke(
			$ability,
			456,
			'ai_step',
			17,
			array(
				'pipeline_id' => 5,
				'step_type'   => 'ai',
			),
			'ai',
			'DataMachine\\Core\\Steps\\AI\\AIStep',
			array(),
			array( 'data' => array() ),
			false,
			$status_override
		);

		return array( $result, $db_jobs );
	};

	echo "=== ai-step-soft-skip-empty-output-smoke ===\n";

	echo "\n[1] AI step soft-skip override completes the job as agent_skipped\n";
	list( $result, $db_jobs ) = $invoke_route( JobStatus::AGENT_SKIPPED . ' - ai_step_no_actionable_output' );
	$failed_jobs              = $actions_by_hook( 'datamachine_fail_job' );

	$assert( 'route reports completed_override outcome', 'completed_override' === ( $result['outcome'] ?? '' ) );
	$assert( 'route persists agent_skipped status to db', 1 === count( $db_jobs->completed ) );
	$assert(
		'persisted status carries the soft-skip reason',
		JobStatus::AGENT_SKIPPED . ' - ai_step_no_actionable_output' === ( $db_jobs->completed[0]['status'] ?? '' )
	);
	$assert( 'datamachine_fail_job is NOT emitted on soft-skip', empty( $failed_jobs ) );

	echo "\n[2] No override still routes to the existing empty-packet failure path\n";
	list( $result, $db_jobs ) = $invoke_route( null );
	$failed_jobs              = $actions_by_hook( 'datamachine_fail_job' );
	$last_failure             = end( $failed_jobs );
	$failure_reason           = is_array( $last_failure ) ? ( $last_failure['args'][2]['reason'] ?? '' ) : '';

	$assert( 'no override still fails on empty AI packet', 'failed' === ( $result['outcome'] ?? '' ) );
	$assert( 'no override still emits a single fail-job action', 1 === count( $failed_jobs ) );
	$assert( 'no override preserves empty_data_packet_returned reason', 'empty_data_packet_returned' === $failure_reason );

	if ( $failures > 0 ) {
		echo "\n=== ai-step-soft-skip-empty-output-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
		exit( 1 );
	}

	echo "\n=== ai-step-soft-skip-empty-output-smoke: ALL PASS ({$total} assertions) ===\n";
}
