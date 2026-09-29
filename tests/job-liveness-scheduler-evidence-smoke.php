<?php
/**
 * Smoke: Core scheduler evidence provider and the single alive() predicate
 * (Extra-Chill/data-machine#3575, part of #3481).
 *
 * Runs the real SchedulerEvidence::load() query against an in-memory SQLite
 * database through a minimal wpdb stand-in that counts every query, so the
 * "ONE batch query per pass" contract is asserted, not assumed.
 *
 * Run with: php tests/job-liveness-scheduler-evidence-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

function maybe_unserialize( $data ) {
	if ( ! is_string( $data ) ) {
		return $data;
	}
	$unserialized = @unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Compatibility fixture.
	return false === $unserialized && 'b:0;' !== $data ? $data : $unserialized;
}

function apply_filters( string $hook, $value ) {
	unset( $hook );
	return $value;
}

/** Minimal wpdb over SQLite supporting the prepare() subset the evidence query uses. */
final class Evidence_Wpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public array $queries     = array();
	public \PDO $pdo;

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return (string) preg_replace_callback(
			'/%[ids]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				if ( '%i' === $m[0] ) {
					return '"' . str_replace( '"', '', (string) $value ) . '"';
				}
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return $this->pdo->quote( (string) $value );
			},
			$sql
		);
	}

	public function get_results( string $sql, $output = ARRAY_A ) {
		unset( $output );
		$this->queries[] = $sql;
		try {
			return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
	}
}

$GLOBALS['dm_failures'] = 0;
$GLOBALS['dm_passes']   = 0;

function assert_evidence( string $name, bool $condition ): void {
	if ( $condition ) {
		++$GLOBALS['dm_passes'];
		echo "  PASS {$name}\n";
		return;
	}
	++$GLOBALS['dm_failures'];
	echo "  FAIL {$name}\n";
}

require_once __DIR__ . '/../inc/Core/ChildJobRecoveryPolicy.php';
require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';
require_once __DIR__ . '/../inc/Core/Jobs/JobLiveness.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/PathlessBatchRecovery.php';
require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php';

use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Core\Jobs\JobLiveness;
use DataMachine\Core\Jobs\SchedulerEvidence;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;
use DataMachine\Engine\AI\System\Tasks\SystemTask;

$wpdb = new Evidence_Wpdb();
$GLOBALS['wpdb'] = $wpdb;
$now = time();
$ago = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', $now - $seconds );

$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL)' );
$insert = static function ( int $id, string $hook, string $status, string $args, ?string $extended = null, ?string $attempt = null ) use ( $wpdb, $ago ): void {
	$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, extended_args) VALUES (?, ?, ?, ?, ?, ?, ?)' );
	$stmt->execute( array( $id, $hook, $status, $ago( 60 ), $attempt ?? '0000-00-00 00:00:00', $args, $extended ) );
};

$insert( 1, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_PENDING, '{"job_id":100,"flow_step_id":"s1"}' );
$insert( 2, AIConcurrencyBackpressure::RESUME_HOOK, SchedulerEvidence::STATUS_PENDING, '{"job_id":101,"flow_step_id":"s1"}' );
$insert( 3, 'datamachine_pipeline_batch_chunk', SchedulerEvidence::STATUS_PENDING, '{"parent_job_id":102,"offset":0}' );
$insert( 4, 'datamachine_run_flow_now', SchedulerEvidence::STATUS_PENDING, '[7,103]' );
$insert( 5, 'datamachine_task_process_batch', SchedulerEvidence::STATUS_PENDING, '{"parent_job_id":104,"offset":0}' );
$insert( 6, SystemTask::RETRY_HOOK, SchedulerEvidence::STATUS_PENDING, '[105]' );
$insert( 7, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_PENDING, md5( 'long' ), '{"job_id":106,"flow_step_id":"' . str_repeat( 'x', 200 ) . '"}' );
$insert( 8, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_IN_PROGRESS, '{"job_id":107,"flow_step_id":"s1"}', null, $ago( 10 * HOUR_IN_SECONDS ) );
$insert( 9, DirectJobEnqueuer::HOOK, 'complete', '{"job_id":108,"flow_step_id":"s1"}' );
$insert( 10, 'some_other_plugin_hook', SchedulerEvidence::STATUS_PENDING, '{"job_id":109}' );
$insert( 11, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_PENDING, '{"job_id":12,"flow_step_id":"s1"}' );
$insert( 12, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_PENDING, '{"job_id":123,"flow_step_id":"s1"}' );

echo "[1] ONE batch query serves every DM hook\n";
$evidence = SchedulerEvidence::load();
assert_evidence( 'exactly one scheduler query per load', 1 === count( $wpdb->queries ) );
assert_evidence( 'the query is bounded by the truncation sentinel', str_contains( $wpdb->queries[0], 'LIMIT ' . ( SchedulerEvidence::SCAN_LIMIT + 1 ) ) );
assert_evidence( 'the query reads COALESCE(extended_args, args)', str_contains( $wpdb->queries[0], 'COALESCE(extended_args, args)' ) );
assert_evidence( 'evidence is complete', $evidence->isComplete() );
$queries_before = count( $wpdb->queries );
$map            = $evidence->liveActionMap( $now, 2 * HOUR_IN_SECONDS );
foreach ( array( 100, 101, 102, 103, 104, 105, 106, 12, 123 ) as $job_id ) {
	$evidence->actionsFor( $job_id );
}
assert_evidence( 'lookups never re-query the scheduler', count( $wpdb->queries ) === $queries_before );

echo "[2] job_id => actions map\n";
assert_evidence( 'execute_step by job_id', array( 1 ) === $map[100] );
assert_evidence( 'resume_ai_step by job_id', array( 2 ) === $map[101] );
assert_evidence( 'pipeline batch chunk by parent_job_id', array( 3 ) === $map[102] );
assert_evidence( 'run_flow_now by positional job id', array( 4 ) === $map[103] );
assert_evidence( 'task batch chunk by parent_job_id', array( 5 ) === $map[104] );
assert_evidence( 'task retry by positional job id', array( 6 ) === $map[105] );
assert_evidence( 'md5-args action resolves through extended_args', array( 7 ) === $map[106] );
assert_evidence( 'stale in-progress action is not live', ! isset( $map[107] ) );
assert_evidence( 'complete actions are never loaded', ! isset( $map[108] ) && array() === $evidence->actionsFor( 108 ) );
assert_evidence( 'non-Data-Machine hooks are never loaded', ! isset( $map[109] ) && array() === $evidence->actionsFor( 109 ) );
assert_evidence( 'no numeric-prefix collision between job 12 and 123', array( 11 ) === $map[12] && array( 12 ) === $map[123] );
assert_evidence( 'stale in-progress rows stay available for classification', 1 === count( $evidence->actionsFor( 107 ) ) );
assert_evidence( 'hook allow-list filters a job actions', array() === $evidence->actionsFor( 100, array( AIConcurrencyBackpressure::RESUME_HOOK ) ) && 1 === count( $evidence->actionsFor( 100, SchedulerEvidence::stepHooks() ) ) );
assert_evidence( 'decoded args are exposed for generation checks', 100 === $evidence->actionsFor( 100 )[0]['decoded_args']['job_id'] );

echo "[3] fail closed\n";
$over = array_fill( 0, SchedulerEvidence::SCAN_LIMIT + 1, array( 'action_id' => 1, 'hook' => DirectJobEnqueuer::HOOK, 'status' => SchedulerEvidence::STATUS_PENDING, 'scheduled_date_gmt' => $ago( 60 ), 'last_attempt_gmt' => '', 'action_args' => '{"job_id":1}' ) );
assert_evidence( 'over-limit result is incomplete', false === SchedulerEvidence::fromRows( $over, count( $over ) <= SchedulerEvidence::SCAN_LIMIT )->isComplete() );
$wpdb->pdo->exec( 'DROP TABLE wp_actionscheduler_actions' );
$broken = SchedulerEvidence::load();
assert_evidence( 'query error is incomplete', false === $broken->isComplete() );
assert_evidence( 'incomplete evidence exposes no actions', array() === $broken->actionsFor( 100 ) && array() === $broken->liveActionMap( $now, HOUR_IN_SECONDS ) );

echo "[4] alive() is the single liveness predicate\n";
$job = static fn( array $engine = array(), int $id = 100 ): array => array(
	'job_id'      => $id,
	'created_at'  => $ago( 4 * HOUR_IN_SECONDS ),
	'engine_data' => $engine,
);
$fixture = SchedulerEvidence::fromRows(
	array(
		array( 'action_id' => 1, 'hook' => DirectJobEnqueuer::HOOK, 'status' => SchedulerEvidence::STATUS_PENDING, 'scheduled_date_gmt' => $ago( 60 ), 'last_attempt_gmt' => '0000-00-00 00:00:00', 'action_args' => '{"job_id":100,"flow_step_id":"s1"}' ),
		array( 'action_id' => 2, 'hook' => DirectJobEnqueuer::HOOK, 'status' => SchedulerEvidence::STATUS_IN_PROGRESS, 'scheduled_date_gmt' => $ago( 5 * HOUR_IN_SECONDS ), 'last_attempt_gmt' => $ago( 5 * HOUR_IN_SECONDS ), 'action_args' => '{"job_id":200,"flow_step_id":"s1"}' ),
	)
);
assert_evidence( 'a job with a pending step action is alive', true === JobLiveness::alive( $job(), $fixture, array(), 120, $now ) );
assert_evidence( 'a job whose only action is a stale in-progress worker is dead', false === JobLiveness::alive( $job( array(), 200 ), $fixture, array(), 120, $now ) );
assert_evidence( 'a job with no action and no children is dead', false === JobLiveness::alive( $job( array(), 300 ), $fixture, array(), 120, $now ) );
assert_evidence( 'a batch parent with active children is alive', true === JobLiveness::alive( $job( array( 'batch' => true ), 300 ), $fixture, array( 'active' => 2, 'total' => 5 ), 120, $now ) );
assert_evidence( 'incomplete evidence is alive, never guessed dead', true === JobLiveness::alive( $job( array(), 300 ), SchedulerEvidence::fromRows( array(), false ), array(), 120, $now ) );
assert_evidence( 'incomplete evidence is classified explicitly', JobLiveness::EVIDENCE_INCOMPLETE === JobLiveness::diagnoseWithEvidence( $job( array(), 300 ), SchedulerEvidence::fromRows( array(), false ), array(), 120, $now )['classification'] );
assert_evidence( 'dead classifications are not alive', ! JobLiveness::isAliveClassification( JobLiveness::NO_SCHEDULER_PATH ) && ! JobLiveness::isAliveClassification( JobLiveness::EVIDENCE_PRUNED ) && ! JobLiveness::isAliveClassification( JobLiveness::STALE_IN_PROGRESS ) );

echo "[5] recover-stuck processing path reads the same single snapshot\n";
$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL)' );
$insert( 1, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_PENDING, '{"job_id":100,"flow_step_id":"s1"}' );
$insert( 2, AIConcurrencyBackpressure::RESUME_HOOK, SchedulerEvidence::STATUS_IN_PROGRESS, '{"job_id":101,"flow_step_id":"s1"}', null, $ago( 60 ) );
$insert( 3, DirectJobEnqueuer::HOOK, SchedulerEvidence::STATUS_IN_PROGRESS, '{"job_id":107,"flow_step_id":"s1"}', null, $ago( 10 * HOUR_IN_SECONDS ) );
$insert( 4, 'datamachine_run_flow_now', SchedulerEvidence::STATUS_PENDING, '[7,103]' );
$reflection = new ReflectionClass( \DataMachine\Abilities\Job\RecoverStuckJobsAbility::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$owned      = $reflection->getMethod( 'getActiveSchedulerWork' );
$wpdb->queries = array();
$work = array();
foreach ( array( 100, 101, 107, 103, 300 ) as $job_id ) {
	$work[ $job_id ] = $owned->invoke( $ability, $job_id, array(), 2 );
}
assert_evidence( 'five job lookups cost exactly one scheduler query', 1 === count( $wpdb->queries ) );
assert_evidence( 'pending step action owns the job', true === $work[100]['owned'] && array( 1 ) === $work[100]['action_ids'] );
assert_evidence( 'fresh in-progress AI continuation owns the job', true === $work[101]['owned'] && array( 2 ) === $work[101]['action_ids'] );
assert_evidence( 'stale in-progress step action does not own the job', false === $work[107]['owned'] );
assert_evidence( 'run_flow_now is pending-orphan evidence, not step ownership', false === $work[103]['owned'] );
assert_evidence( 'a job with no action is not owned', false === $work[300]['owned'] );
$wpdb->pdo->exec( 'DROP TABLE wp_actionscheduler_actions' );
$ability = $reflection->newInstanceWithoutConstructor();
$closed  = $owned->invoke( $ability, 100, array(), 2 );
assert_evidence( 'an evidence failure fails closed as owned', true === $closed['owned'] && false === $closed['evidence_complete'] && 'scheduler_evidence_incomplete' === $closed['type'] );

echo "\n{$GLOBALS['dm_passes']} passed, {$GLOBALS['dm_failures']} failed.\n";
exit( $GLOBALS['dm_failures'] > 0 ? 1 : 0 );
