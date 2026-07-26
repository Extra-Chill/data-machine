<?php
/**
 * Pure-PHP regression coverage for stable worker dispatch-health states.
 *
 * Run with: php tests/worker-dispatch-health-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/../inc/Cli/WorkerHealth.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/ScopedDrainService.php';

use DataMachine\Cli\WorkerHealth;
use DataMachine\Core\ActionScheduler\ScopedDrainService;

final class WorkerDispatchHealthWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,mixed> */
	public array $prepare_args = array();
	public string $query = '';

	public function prepare( string $query, array $args ): string {
		$this->query        = $query;
		$this->prepare_args = $args;
		return $query;
	}

	/** @return array<string,string|int> */
	public function get_row( string $query, string $format ): array {
		unset( $query, $format );
		return array(
			'oldest_due_gmt'                 => '2026-07-12 01:45:52',
			'in_progress_actions'             => 0,
			'latest_in_progress_attempt_gmt'  => '0000-00-00 00:00:00',
			'concurrency_deferred_actions'     => 3,
		);
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

$starved = WorkerHealth::classify(
	array(
		'due_count'                 => 1,
		'oldest_due_age_seconds'    => 7200,
		'queue_trigger_state'       => 'overdue',
		'worker_heartbeat_state'    => 'absent',
		'action_heartbeat_state'    => 'absent',
		'concurrency_deferred_actions' => 0,
	)
);
$assert( 'scheduler_dispatcher_starved' === $starved['condition'], 'overdue execute-step work plus a stale queue trigger is dispatcher starvation' );
$assert( true === $starved['scheduler_dispatcher_starved'], 'dispatcher starvation has an explicit stable boolean' );
$assert( 'wp datamachine worker run --once' === $starved['recommendation']['command'], 'starvation recommends the supported bounded worker' );

$claimed = WorkerHealth::classify(
	array(
		'due_count'              => 4,
		'oldest_due_age_seconds' => 7200,
		'queue_trigger_state'    => 'overdue',
		'worker_heartbeat_state' => 'fresh',
	)
);
$assert( 'worker_claimed_with_heartbeat' === $claimed['condition'], 'a current worker lease heartbeat proves claimed work' );
$assert( false === $claimed['scheduler_dispatcher_starved'], 'claimed work is not mislabeled as dispatcher starvation' );

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
		'oldest_due_age_seconds' => 30,
		'queue_trigger_state'    => 'due',
		'worker_heartbeat_state' => 'absent',
	)
);
$assert( 'due_work_unclaimed' === $unclaimed['condition'], 'recent due work without a claim is distinct from starvation' );

$wpdb            = new WorkerDispatchHealthWpdb();
$GLOBALS['wpdb'] = $wpdb;
$method          = new ReflectionMethod( ScopedDrainService::class, 'getDispatchEvidence' );
$evidence        = $method->invoke( new ScopedDrainService(), null, array() );
$assert( '2026-07-12 01:45:52' === $evidence['oldest_due_gmt'], 'dispatch evidence exposes the oldest due Data Machine action time' );
$assert( 0 === $evidence['in_progress_actions'], 'dispatch evidence exposes Action Scheduler claim state' );
$assert( null === $evidence['latest_in_progress_attempt_gmt'], 'unavailable claim heartbeat time remains null' );
$assert( 3 === $evidence['concurrency_deferred_actions'], 'dispatch evidence distinguishes downstream concurrency resume actions' );
$assert( 'wp_actionscheduler_actions' === $wpdb->prepare_args[1], 'Action Scheduler reads use the current site table prefix' );
$assert( str_contains( $wpdb->query, "a.status IN ('pending', 'in-progress')" ), 'dispatch evidence scans only active scheduler rows' );
$assert( str_contains( $wpdb->query, 'datamachine_resume_ai_step' ), 'dispatch evidence uses the existing concurrency resume path' );

echo "OK ({$assertions} assertions)\n";
