<?php
/**
 * Pure-PHP regression coverage for stable worker dispatch-health states.
 *
 * Run with: php tests/worker-dispatch-health-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/../inc/Cli/WorkerHealth.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/ScopedDrainService.php';

use DataMachine\Cli\WorkerHealth;
use DataMachine\Core\ActionScheduler\ScopedDrainService;

final class WorkerDispatchHealthWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,mixed> */
	public array $prepare_args = array();
	public string $query = '';
	/** @var string[] */
	public array $queries = array();

	public function prepare( string $query, array $args ): string {
		$this->query        = $query;
		$this->prepare_args = $args;
		$this->queries[]    = $query;
		return $query;
	}

	public function get_var( string $query ): int {
		unset( $query );
		return 7;
	}

	/** @return array<string,string|int> */
	public function get_row( string $query, string $format ): array {
		unset( $format );
		if ( str_contains( $query, 'AS stale_due_sample_gmt' ) ) {
			return array( 'stale_due_sample_gmt' => '2026-07-12 01:45:52' );
		}
		if ( str_contains( $query, 'AS in_progress_actions' ) ) {
			return array(
				'in_progress_actions'            => 0,
				'in_progress_attempt_sample_gmt' => '0000-00-00 00:00:00',
			);
		}

		return array( 'concurrency_deferred_actions' => 3 );
	}
}

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$worker_source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '';
$lock_source   = file_get_contents( __DIR__ . '/../inc/Cli/WorkerLock.php' ) ?: '';
$system_source = file_get_contents( __DIR__ . '/../inc/Abilities/SystemAbilities.php' ) ?: '';
$assert( str_contains( $worker_source, 'WorkerLock::heartbeat( $lock_token, $lane )' ), 'worker loop refreshes its lease heartbeat before each pass' );
$assert( str_contains( $worker_source, 'if ( ! WorkerLock::heartbeat( $lock_token, $lane ) )' ), 'worker loop fences itself when lease refresh loses ownership' );
$assert( str_contains( $lock_source, 'OptionLeaseStore::refresh' ), 'worker heartbeat uses the existing atomic lease refresh primitive' );
$assert( str_contains( $lock_source, "'heartbeat_age_seconds'" ), 'worker lock snapshots expose heartbeat age' );
$assert( str_contains( $worker_source, "wp_next_scheduled( 'action_scheduler_run_queue', array( 'WP Cron' ) )" ), 'worker diagnostics query the exact Action Scheduler WP-Cron event identity' );
$assert( str_contains( $system_source, "wp_next_scheduled( 'action_scheduler_run_queue', array( 'WP Cron' ) )" ), 'system diagnostics query the exact Action Scheduler WP-Cron event identity' );

$starved = WorkerHealth::classify(
	array(
		'due_count'                    => 1,
		'stale_due_sample_age_seconds' => 7200,
		'queue_trigger_state'          => 'overdue',
		'worker_heartbeat_state'       => 'absent',
		'concurrency_deferred_actions' => 0,
	)
);
$assert( 'scheduler_dispatcher_starved' === $starved['condition'], 'overdue execute-step work plus a stale queue trigger is dispatcher starvation' );
$assert( true === $starved['scheduler_dispatcher_starved'], 'dispatcher starvation has an explicit stable boolean' );
$assert( 'wp datamachine worker run --once' === $starved['recommendation']['command'], 'starvation recommends the supported bounded worker' );

$claimed = WorkerHealth::classify(
	array(
		'due_count'              => 4,
		'stale_due_sample_age_seconds' => 7200,
		'queue_trigger_state'    => 'overdue',
		'worker_heartbeat_state' => 'fresh',
	)
);
$assert( 'worker_claimed_with_heartbeat' === $claimed['condition'], 'a current worker lease heartbeat proves claimed work' );
$assert( false === $claimed['scheduler_dispatcher_starved'], 'claimed work is not mislabeled as dispatcher starvation' );

$attempt_only = WorkerHealth::classify(
	array(
		'due_count'                              => 4,
		'stale_due_sample_age_seconds'           => 7200,
		'queue_trigger_state'                    => 'overdue',
		'worker_heartbeat_state'                 => 'absent',
		'in_progress_count'                      => 1,
		'in_progress_attempt_sample_age_seconds' => 10,
	)
);
$assert( 'scheduler_dispatcher_starved' === $attempt_only['condition'], 'an Action Scheduler attempt timestamp does not prove current worker ownership' );

$idle = WorkerHealth::classify(
	array(
		'due_count'                 => 0,
		'queue_trigger_state'       => 'scheduled',
		'worker_heartbeat_state'    => 'absent',
		'concurrency_deferred_actions' => 0,
	)
);
$assert( 'no_due_work' === $idle['condition'], 'an empty due queue is explicitly healthy' );

$deferred = WorkerHealth::classify(
	array(
		'due_count'                 => 0,
		'queue_trigger_state'       => 'scheduled',
		'worker_heartbeat_state'    => 'absent',
		'concurrency_deferred_actions' => 2,
	)
);
$assert( 'downstream_concurrency_deferred' === $deferred['condition'], 'persisted downstream concurrency deferrals remain distinct from dispatcher starvation' );
$assert( 'wp datamachine jobs liveness --format=json' === $deferred['recommendation']['command'], 'deferred work recommends read-only liveness inspection' );

$unclaimed = WorkerHealth::classify(
	array(
		'due_count'              => 1,
		'stale_due_sample_age_seconds' => 30,
		'queue_trigger_state'    => 'due',
		'worker_heartbeat_state' => 'absent',
	)
);
$assert( 'due_work_unclaimed' === $unclaimed['condition'], 'recent due work without a claim is distinct from starvation' );

$lane = WorkerHealth::classify(
	array(
		'scope'                  => 'lane',
		'due_count'              => 1,
		'queue_trigger_state'    => 'overdue',
		'worker_heartbeat_state' => 'absent',
	)
);
$assert( 'lane_scope_unclassified' === $lane['condition'], 'lane evidence does not assert global dispatcher health' );
$assert( false === $lane['scheduler_dispatcher_starved'], 'lane evidence cannot report global dispatcher starvation' );

$wpdb            = new WorkerDispatchHealthWpdb();
$GLOBALS['wpdb'] = $wpdb;
$method          = new ReflectionMethod( ScopedDrainService::class, 'getDispatchEvidence' );
$evidence        = $method->invoke( new ScopedDrainService(), null, array() );
$assert( '2026-07-12 01:45:52' === $evidence['stale_due_sample_gmt'], 'dispatch evidence exposes a bounded stale-due sample' );
$assert( 0 === $evidence['in_progress_actions'], 'dispatch evidence exposes Action Scheduler claim state' );
$assert( null === $evidence['in_progress_attempt_sample_gmt'], 'unavailable attempt sample time remains null' );
$assert( 3 === $evidence['concurrency_deferred_actions'], 'dispatch evidence distinguishes downstream concurrency resume actions' );
$assert( false === $evidence['in_progress_actions_capped'], 'claim evidence reports whether its bounded count was capped' );
$assert( false === $evidence['concurrency_deferred_actions_capped'], 'deferred evidence reports whether its bounded count was capped' );
$queries = implode( "\n", $wpdb->queries );
$assert( 4 === count( $wpdb->queries ), 'dispatch evidence resolves its group then uses separate indexable reads for stale, claimed, and deferred work' );
$assert( str_contains( $queries, 'AS stale_due_sample_gmt' ) && str_contains( $queries, 'LIMIT 1' ), 'stale due evidence stops after the first matching action without sorting the backlog' );
$assert( ! str_contains( $queries, 'ORDER BY a.scheduled_date_gmt' ), 'stale due evidence does not filesort the current backlog' );
$assert( 2 === substr_count( $queries, 'LIMIT %d' ), 'claim and deferred evidence are independently capped' );
$assert( ! str_contains( $queries, "a.status IN ('pending', 'in-progress')" ), 'future pending actions are not scanned with claimed work' );

echo "OK ({$assertions} assertions)\n";
