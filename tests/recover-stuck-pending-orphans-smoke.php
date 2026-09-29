<?php
/**
 * Smoke: recover-stuck covers pending rows with no live Action Scheduler action
 * (Extra-Chill/data-machine#3574, part of #3481).
 *
 * Runs the real candidate/evidence SQL of RecoverStuckJobsAbility against an
 * in-memory SQLite database with a minimal wpdb stand-in. The Jobs repository is
 * a stand-in whose compare-and-set mirrors Jobs::transition_orphaned_pending_job
 * (locked-row policy re-check + observed operation state/generation).
 *
 * Run with: php tests/recover-stuck-pending-orphans-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['dm_smoke_failures'] = 0;
	$GLOBALS['dm_smoke_passes']   = 0;

	function assert_pending_orphans( string $name, bool $condition ): void {
		if ( $condition ) {
			++$GLOBALS['dm_smoke_passes'];
			echo "  PASS {$name}\n";
			return;
		}
		++$GLOBALS['dm_smoke_failures'];
		echo "  FAIL {$name}\n";
	}

	function apply_filters( string $hook, $value ) {
		unset( $hook );
		return $value;
	}

	function do_action( ...$args ): void {
		unset( $args );
	}

	function maybe_unserialize( $value ) {
		return $value;
	}

	/** Minimal wpdb over SQLite supporting the prepare()/%i/%d/%s subset the recovery pass uses. */
	final class Orphan_Wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
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

		public function query( string $sql ) {
			return $this->pdo->exec( $sql );
		}

		public function get_var( string $sql ) {
			return $this->pdo->query( $sql )->fetchColumn();
		}

		public function get_results( string $sql, $output = ARRAY_A ) {
			unset( $output );
			try {
				return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
			} catch ( \PDOException $e ) {
				$this->last_error = $e->getMessage();
				return null;
			}
		}
	}

	$GLOBALS['wpdb'] = new Orphan_Wpdb();
}

namespace DataMachine\Core\Database\Jobs {
	use DataMachine\Core\JobStatus;
	use DataMachine\Core\PendingJobRecoveryPolicy;

	/** Stand-in repository: records calls and mirrors the real compare-and-set. */
	class Jobs {
		public array $calls        = array();
		public ?int $drift_job_id  = null;

		public function transition_orphaned_pending_job( int $job_id, string $verdict, string $observed_operation_state, int $observed_generation, string $trigger, int $grace_seconds ): array {
			global $wpdb;
			$this->calls[] = compact( 'job_id', 'verdict', 'observed_operation_state', 'observed_generation', 'trigger' );
			if ( $this->drift_job_id === $job_id ) {
				// Simulate a concurrent enqueue claim between diagnosis and CAS.
				$wpdb->query( "UPDATE wp_datamachine_jobs SET operation_generation = operation_generation + 1 WHERE job_id = {$job_id}" );
			}

			$row = $wpdb->pdo->query( "SELECT * FROM wp_datamachine_jobs WHERE job_id = {$job_id}" )->fetch( \PDO::FETCH_ASSOC );
			if ( ! is_array( $row ) || JobStatus::PENDING !== $row['status'] ) {
				return array( 'success' => false, 'changed' => false, 'current_status' => $row['status'] ?? null, 'status' => 'failed' );
			}
			$engine    = json_decode( (string) $row['engine_data'], true ) ?: array();
			$diagnosis = PendingJobRecoveryPolicy::diagnose( $row, $engine, array(), time(), $grace_seconds );
			if ( $diagnosis['verdict'] !== $verdict
				|| (string) $row['operation_state'] !== $observed_operation_state
				|| (int) $row['operation_generation'] !== $observed_generation ) {
				return array( 'success' => false, 'changed' => false, 'current_status' => $row['status'], 'status' => $row['status'] );
			}

			$status  = PendingJobRecoveryPolicy::terminalStatus( $verdict );
			$updated = $wpdb->pdo->exec( "UPDATE wp_datamachine_jobs SET status = " . $wpdb->pdo->quote( $status ) . " WHERE job_id = {$job_id} AND status = 'pending'" );
			return array( 'success' => 1 === $updated, 'changed' => 1 === $updated, 'current_status' => 'pending', 'status' => $status );
		}
	}
}

namespace {
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\PendingJobRecoveryPolicy;

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/PendingJobRecoveryPolicy.php';
	require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';
	require_once __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php';

	$now = time();
	$ago = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', $now - $seconds );

	// -- 1. Pure policy verdicts ----------------------------------------------------------------
	echo "[1] policy verdicts\n";
	$base = array(
		'status'          => 'pending',
		'created_at'      => $ago( 3 * HOUR_IN_SECONDS ),
		'operation_state' => null,
	);
	$policy = static fn( array $job, array $engine = array(), array $actions = array() ): array => PendingJobRecoveryPolicy::diagnose( array_merge( $base, $job ), $engine, $actions, $now );

	assert_pending_orphans( 'enqueue_failed -> enqueue_failed', PendingJobRecoveryPolicy::VERDICT_ENQUEUE_FAILED === $policy( array( 'operation_state' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUE_FAILED ) )['verdict'] );
	assert_pending_orphans( 'enqueued + no action -> evidence_pruned', 'evidence_pruned' === $policy( array( 'operation_state' => 'enqueued' ) )['verdict'] );
	assert_pending_orphans( 'preparing past lease -> enqueue_interrupted', 'enqueue_interrupted' === $policy( array( 'operation_state' => 'preparing' ) )['verdict'] );
	assert_pending_orphans( 'enqueuing past lease -> enqueue_interrupted', 'enqueue_interrupted' === $policy( array( 'operation_state' => 'enqueuing', 'operation_claimed_at' => $ago( 7200 ) ) )['verdict'] );
	assert_pending_orphans( 'NULL operation_state -> orphaned_pending', 'orphaned_pending' === $policy( array() )['verdict'] );
	assert_pending_orphans( 'future engine_data.retry.next_retry_at is excluded', 'retry_scheduled' === $policy( array(), array( 'retry' => array( 'next_retry_at' => gmdate( 'c', $now + 600 ) ) ) )['skip'] );
	assert_pending_orphans( 'past retry.next_retry_at does not exclude', 'orphaned_pending' === $policy( array(), array( 'retry' => array( 'next_retry_at' => gmdate( 'c', $now - 600 ) ) ) )['verdict'] );
	assert_pending_orphans( 'future ai_concurrency_throttle is excluded', 'ai_throttle_scheduled' === $policy( array(), array( 'ai_concurrency_throttle' => array( 'next_retry_at' => gmdate( 'c', $now + 600 ) ) ) )['skip'] );
	assert_pending_orphans( 'unexpired enqueuing lease is excluded', 'enqueue_lease_active' === $policy( array( 'operation_state' => 'enqueuing', 'operation_claimed_at' => $ago( 30 ) ) )['skip'] );
	assert_pending_orphans( 'live scheduler action is excluded', 'live_scheduler_action' === $policy( array( 'operation_state' => 'enqueued' ), array(), array( 5 ) )['skip'] );
	assert_pending_orphans( 'row inside the grace window is excluded', 'within_grace' === $policy( array( 'created_at' => $ago( 600 ) ) )['skip'] );
	assert_pending_orphans( 'non-pending rows are never diagnosed', 'not_pending' === $policy( array( 'status' => 'processing' ) )['skip'] );
	assert_pending_orphans( 'batch parents are excluded', 'batch_parent' === $policy( array(), array( 'batch' => true ) )['skip'] );
	assert_pending_orphans( 'unknown operation states are not guessed', 'unrecognized_operation_state' === $policy( array( 'operation_state' => 'cancelled' ) )['skip'] );
	assert_pending_orphans( 'terminal status carries the verdict as reason', 'failed - evidence_pruned' === PendingJobRecoveryPolicy::terminalStatus( 'evidence_pruned' ) );

	// -- 2. Ability pass against SQLite -----------------------------------------------------------
	global $wpdb;
	$reset = static function () use ( $wpdb, $ago, $now ): void {
		$wpdb->query( 'DROP TABLE IF EXISTS wp_datamachine_jobs' );
		$wpdb->query( 'DROP TABLE IF EXISTS wp_actionscheduler_actions' );
		$wpdb->query( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY, flow_id TEXT, status TEXT NOT NULL, created_at TEXT NOT NULL, operation_state TEXT NULL, operation_claimed_at TEXT NULL, operation_generation INTEGER NOT NULL DEFAULT 0, engine_data TEXT NULL)' );
		$wpdb->query( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL)' );

		$job = static function ( int $id, array $o = array() ) use ( $wpdb, $ago ): void {
			$o = array_merge(
				array(
					'flow_id'  => '10',
					'status'   => 'pending',
					'created'  => $ago( 3 * HOUR_IN_SECONDS ),
					'op'       => null,
					'claimed'  => null,
					'engine'   => '{}',
				),
				$o
			);
			$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_datamachine_jobs (job_id, flow_id, status, created_at, operation_state, operation_claimed_at, operation_generation, engine_data) VALUES (?, ?, ?, ?, ?, ?, 1, ?)' );
			$stmt->execute( array( $id, $o['flow_id'], $o['status'], $o['created'], $o['op'], $o['claimed'], $o['engine'] ) );
		};
		$action = static function ( int $id, string $hook, string $status, string $args, ?string $extended = null, ?string $last_attempt = null ) use ( $wpdb, $ago ): void {
			$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, extended_args) VALUES (?, ?, ?, ?, ?, ?, ?)' );
			$stmt->execute( array( $id, $hook, $status, $ago( 3600 ), $last_attempt ?? '0000-00-00 00:00:00', $args, $extended ) );
		};

		// Verdict rows.
		$job( 1, array( 'op' => 'enqueue_failed' ) );
		$job( 2, array( 'op' => 'enqueued' ) );
		$job( 3, array( 'op' => 'preparing' ) );
		$job( 4, array( 'op' => 'enqueuing', 'claimed' => $ago( 7200 ) ) );
		$job( 5, array() );
		// Exclusions.
		$job( 6, array( 'engine' => json_encode( array( 'retry' => array( 'next_retry_at' => gmdate( 'c', $now + 900 ) ) ) ) ) );
		$job( 7, array( 'engine' => json_encode( array( 'ai_concurrency_throttle' => array( 'state' => 'deferred', 'next_retry_at' => gmdate( 'c', $now + 900 ) ) ) ) ) );
		$job( 8, array( 'op' => 'enqueuing', 'claimed' => $ago( 20 ) ) );
		$job( 9, array( 'op' => 'enqueued' ) );
		$action( 900, 'datamachine_execute_step', 'pending', '{"job_id":9,"flow_step_id":"step_1"}' );
		$job( 10, array( 'op' => 'enqueued' ) );
		$action( 901, 'datamachine_execute_step', 'pending', md5( 'long-args' ), '{"job_id":10,"flow_step_id":"' . str_repeat( 'x', 200 ) . '"}' );
		$job( 11, array( 'created' => $ago( 600 ) ) );
		$job( 12, array( 'status' => 'processing', 'op' => 'enqueued' ) );
		$job( 14, array( 'op' => 'enqueued' ) );
		$action( 902, 'datamachine_pipeline_batch_chunk', 'pending', '{"parent_job_id":14,"offset":0}' );
		$job( 16, array( 'engine' => json_encode( array( 'batch' => true ) ) ) );
		// Actions that are NOT live evidence: stale in-progress and complete rows.
		$job( 13, array( 'op' => 'enqueued' ) );
		$action( 903, 'datamachine_execute_step', 'in-progress', '{"job_id":13,"flow_step_id":"step_1"}', null, $ago( 10 * HOUR_IN_SECONDS ) );
		$job( 15, array( 'op' => 'enqueued' ) );
		$action( 904, 'datamachine_execute_step', 'complete', '{"job_id":15,"flow_step_id":"step_1"}' );
		// Other flow.
		$job( 17, array( 'flow_id' => '99', 'op' => 'enqueue_failed' ) );
	};

	$statuses = static function () use ( $wpdb ): array {
		return $wpdb->pdo->query( 'SELECT job_id, status, operation_state, operation_generation, engine_data FROM wp_datamachine_jobs ORDER BY job_id' )->fetchAll( \PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC );
	};

	$make = static function (): array {
		$reflection = new \ReflectionClass( \DataMachine\Abilities\Job\RecoverStuckJobsAbility::class );
		$ability    = $reflection->newInstanceWithoutConstructor();
		$jobs_repo  = new Jobs();
		$prop       = $reflection->getProperty( 'db_jobs' );
		$prop->setAccessible( true );
		$prop->setValue( $ability, $jobs_repo );
		$method = $reflection->getMethod( 'recoverPendingOrphans' );
		$method->setAccessible( true );
		return array( $ability, $jobs_repo, $method, $reflection );
	};

	$run = static function ( bool $dry_run, array $opts = array() ) use ( $make ): array {
		list( $ability, $repo, $method ) = $make();
		if ( isset( $opts['drift'] ) ) {
			$repo->drift_job_id = $opts['drift'];
		}
		$details = array();
		$omitted = 0;
		$summary = $method->invokeArgs(
			$ability,
			array(
				$dry_run,
				$opts['flow_id'] ?? null,
				$opts['job_id'] ?? null,
				$opts['grace'] ?? HOUR_IN_SECONDS,
				$opts['limit'] ?? 500,
				2,
				'test',
				&$details,
				&$omitted,
			)
		);
		return array( $summary, $details, $repo );
	};

	echo "[2] dry-run classifies every verdict and mutates nothing\n";
	$reset();
	$before = $statuses();
	list( $summary, $details, $repo ) = $run( true );
	assert_pending_orphans( 'dry-run makes no repository calls', array() === $repo->calls );
	assert_pending_orphans( 'dry-run leaves every row byte-identical', $before === $statuses() );
	assert_pending_orphans( 'dry-run reports enqueue_failed x2 (flows 10 and 99)', 2 === $summary['verdicts']['enqueue_failed'] );
	assert_pending_orphans( 'dry-run reports evidence_pruned x3 (enqueued+pruned, stale in-progress, complete-only)', 3 === $summary['verdicts']['evidence_pruned'] );
	assert_pending_orphans( 'dry-run reports enqueue_interrupted x2 (preparing, expired enqueuing)', 2 === $summary['verdicts']['enqueue_interrupted'] );
	assert_pending_orphans( 'dry-run reports orphaned_pending x1 (NULL operation_state)', 1 === $summary['verdicts']['orphaned_pending'] );
	assert_pending_orphans( 'dry-run would_terminalize totals the verdicts', 8 === $summary['would_terminalize'] && 0 === $summary['terminalized'] );
	$reasons = $summary['skipped_reasons'];
	assert_pending_orphans( 'excludes future retry', 1 === ( $reasons['retry_scheduled'] ?? 0 ) );
	assert_pending_orphans( 'excludes future AI throttle', 1 === ( $reasons['ai_throttle_scheduled'] ?? 0 ) );
	assert_pending_orphans( 'excludes unexpired enqueue lease', 1 === ( $reasons['enqueue_lease_active'] ?? 0 ) );
	assert_pending_orphans( 'excludes live AS actions (plain args, md5+extended_args, batch chunk parent)', 3 === ( $reasons['live_scheduler_action'] ?? 0 ) );
	assert_pending_orphans( 'excludes batch parents', 1 === ( $reasons['batch_parent'] ?? 0 ) );
	assert_pending_orphans( 'rows inside the grace window and non-pending rows are never scanned', 15 === $summary['scanned'] );
	$detail_statuses = array_unique( array_column( $details, 'status' ) );
	assert_pending_orphans( 'details are would_* previews only', array( 'would_terminalize_pending_orphan' ) === array_values( $detail_statuses ) );

	echo "[3] apply terminalizes through the repository CAS\n";
	$reset();
	list( $summary, $details, $repo ) = $run( false );
	$after = $statuses();
	assert_pending_orphans( 'apply terminalizes all 8 orphans', 8 === $summary['terminalized'] && 0 === $summary['guarded'] );
	assert_pending_orphans( 'one repository call per orphan, none for exclusions', 8 === count( $repo->calls ) );
	assert_pending_orphans( 'enqueue_failed row failed with reason', 'failed - enqueue_failed' === $after[1]['status'] );
	assert_pending_orphans( 'enqueued+no action failed as evidence_pruned', 'failed - evidence_pruned' === $after[2]['status'] );
	assert_pending_orphans( 'preparing failed as enqueue_interrupted', 'failed - enqueue_interrupted' === $after[3]['status'] );
	assert_pending_orphans( 'expired enqueuing failed as enqueue_interrupted', 'failed - enqueue_interrupted' === $after[4]['status'] );
	assert_pending_orphans( 'NULL operation_state failed as orphaned_pending', 'failed - orphaned_pending' === $after[5]['status'] );
	assert_pending_orphans( 'future-retry row untouched', 'pending' === $after[6]['status'] );
	assert_pending_orphans( 'future-throttle row untouched', 'pending' === $after[7]['status'] );
	assert_pending_orphans( 'live-lease row untouched', 'pending' === $after[8]['status'] );
	assert_pending_orphans( 'rows with live AS actions untouched', 'pending' === $after[9]['status'] && 'pending' === $after[10]['status'] && 'pending' === $after[14]['status'] );
	assert_pending_orphans( 'young and processing rows untouched', 'pending' === $after[11]['status'] && 'processing' === $after[12]['status'] );
	assert_pending_orphans( 'batch parent untouched', 'pending' === $after[16]['status'] );
	assert_pending_orphans( 'stale in-progress and complete-only actions do not shield', 'failed - evidence_pruned' === $after[13]['status'] && 'failed - evidence_pruned' === $after[15]['status'] );
	assert_pending_orphans( 'repository receives the observed operation state and generation', 'enqueue_failed' === $repo->calls[0]['observed_operation_state'] && 1 === $repo->calls[0]['observed_generation'] && 'test' === $repo->calls[0]['trigger'] );

	echo "[4] apply is idempotent\n";
	list( $again ) = $run( false );
	assert_pending_orphans( 'second apply finds only the exclusions', 0 === $again['terminalized'] && 0 === $again['would_terminalize'] );

	echo "[5] per-run bound\n";
	$reset();
	list( $bounded, , $repo ) = $run( false, array( 'limit' => 3 ) );
	assert_pending_orphans( 'apply stops at the bound', 3 === $bounded['terminalized'] && 3 === count( $repo->calls ) );
	assert_pending_orphans( 'bound reports limit_reached', true === $bounded['limit_reached'] );
	$reset();
	list( $bounded_dry ) = $run( true, array( 'limit' => 3 ) );
	assert_pending_orphans( 'dry-run honors the same bound', 3 === $bounded_dry['would_terminalize'] && true === $bounded_dry['limit_reached'] );
	$reset();
	list( $unbounded ) = $run( true, array( 'limit' => 8 ) );
	assert_pending_orphans( 'bound equal to the orphan count is not reported as reached', false === $unbounded['limit_reached'] );

	echo "[6] scope filters\n";
	$reset();
	list( $flow_scope ) = $run( true, array( 'flow_id' => 99 ) );
	assert_pending_orphans( 'flow scope only sees that flow', 1 === $flow_scope['would_terminalize'] && 1 === $flow_scope['verdicts']['enqueue_failed'] );
	list( $job_scope ) = $run( true, array( 'job_id' => 2 ) );
	assert_pending_orphans( 'job scope only sees that job', 1 === $job_scope['would_terminalize'] && 1 === $job_scope['verdicts']['evidence_pruned'] );
	list( $custom_grace ) = $run( true, array( 'grace' => 5 * MINUTE_IN_SECONDS, 'job_id' => 11 ) );
	assert_pending_orphans( 'a shorter grace admits younger rows', 1 === $custom_grace['would_terminalize'] );

	echo "[7] concurrent state change loses the CAS\n";
	$reset();
	list( $drift, $drift_details ) = $run( false, array( 'drift' => 1 ) );
	$after = $statuses();
	assert_pending_orphans( 'drifted row is guarded, not terminalized', 'pending' === $after[1]['status'] && 1 === $drift['guarded'] && 7 === $drift['terminalized'] );
	assert_pending_orphans( 'drifted row is reported as skipped', in_array( 'pending_orphan_state_changed', array_column( $drift_details, 'reason' ), true ) );

	echo "[8] incomplete scheduler evidence fails closed\n";
	$reset();
	$wpdb->query( 'DROP TABLE wp_actionscheduler_actions' );
	$before = $statuses();
	list( $blind, , $repo ) = $run( false );
	assert_pending_orphans( 'no evidence, no mutation', $before === $statuses() && array() === $repo->calls );
	assert_pending_orphans( 'summary flags incomplete evidence', false === $blind['evidence_complete'] && 0 === $blind['terminalized'] );
	$wpdb->last_error = '';

	echo "[9] grace resolution\n";
	list( $ability, , , $reflection ) = $make();
	$grace = $reflection->getMethod( 'resolvePendingGraceSeconds' );
	$grace->setAccessible( true );
	assert_pending_orphans( 'default grace is one hour', 3600 === $grace->invoke( $ability, array(), null ) );
	assert_pending_orphans( 'input grace is honored in minutes', 1800 === $grace->invoke( $ability, array( 'pending_grace_minutes' => 30 ), null ) );

	echo "[10] wiring\n";
	$ability_src = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
	$jobs_src    = file_get_contents( __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php' ) ?: '';
	$worker_src  = file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '';
	$cli_src     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
	assert_pending_orphans( 'ability terminalizes only via the Jobs CAS method', str_contains( $ability_src, 'transition_orphaned_pending_job' ) );
	assert_pending_orphans( 'Jobs orphan transition routes through the locked terminal transition', 1 === preg_match( '/function transition_orphaned_pending_job.*?transition_terminal_job_status_result.*?\'mode\'\s*=>\s*\'pending_orphan\'/s', $jobs_src ) );
	assert_pending_orphans( 'locked-row owner match re-checks operation state, generation, and verdict', str_contains( $jobs_src, 'function pending_orphan_owner_matches' ) && str_contains( $jobs_src, "'pending_orphan' === \$mode" ) );
	assert_pending_orphans( 'apply requires explicit authorization; dry-run always previews', str_contains( $ability_src, '$pending_orphans_enabled = $dry_run || $recover_pending_orphans' ) );
	assert_pending_orphans( 'automatic worker does not authorize pending-orphan apply', ! str_contains( $worker_src, 'recover_pending_orphans' ) );
	assert_pending_orphans( 'liveness scope includes pending', str_contains( $cli_src, "WHERE status IN ('processing', 'pending')" ) );
	assert_pending_orphans( 'CLI exposes the authorization and bound flags', str_contains( $cli_src, '--recover-pending-orphans' ) && str_contains( $cli_src, '--pending-limit=<limit>' ) );

	echo $GLOBALS['dm_smoke_failures']
		? "\nFAILED: {$GLOBALS['dm_smoke_failures']} pending-orphan recovery assertions failed.\n"
		: "\nAll {$GLOBALS['dm_smoke_passes']} pending-orphan recovery assertions passed.\n";
	exit( $GLOBALS['dm_smoke_failures'] ? 1 : 0 );
}
