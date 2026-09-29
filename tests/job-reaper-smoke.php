<?php
/**
 * Smoke: the recurring job reaper (Extra-Chill/data-machine#3576, part of #3481).
 *
 * Covers registration and scheduling, the three modes (dry_run never mutates,
 * apply mutates through the same Jobs compare-and-set path recover-stuck uses,
 * off does nothing), the bounded run history, and the false-positive signal
 * (jobs flagged on run N that were alive or progressed by run N+1).
 *
 * The recover-stuck ability's real pending-orphan pass runs against an in-memory
 * SQLite database through a minimal wpdb stand-in; the Jobs repository is a
 * stand-in whose compare-and-set mirrors Jobs::transition_orphaned_pending_job.
 * Nothing here touches a production database.
 *
 * Run with: php tests/job-reaper-smoke.php
 */

namespace {
	// Mirrors DirectJobEnqueuer::HOOK; the class is not loaded in this standalone harness.
	const JR_EXECUTE_STEP_HOOK = 'datamachine_' . 'execute_step';
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['dm_smoke_failures'] = 0;
	$GLOBALS['dm_smoke_passes']   = 0;
	$GLOBALS['dm_options']        = array();
	$GLOBALS['dm_logs']           = array();

	function assert_reaper( string $name, bool $condition ): void {
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

	function add_filter( ...$args ): bool {
		unset( $args );
		return true;
	}

	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['dm_logs'][] = $args;
		}
	}

	function maybe_unserialize( $value ) {
		return $value;
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function get_option( string $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['dm_options'] ) ? $GLOBALS['dm_options'][ $name ] : $default_value;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		// Round-trip through serialize() like the options table does.
		$GLOBALS['dm_options'][ $name ] = unserialize( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Options fixture.
		return true;
	}

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return null;
		}
	}

	/** Minimal wpdb over SQLite supporting the prepare()/%i/%d/%s subset the recovery pass uses. */
	final class Reaper_Wpdb {
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

	$GLOBALS['wpdb'] = new Reaper_Wpdb();
}

namespace DataMachine\Core\Database\Jobs {
	use DataMachine\Core\JobStatus;
	use DataMachine\Core\PendingJobRecoveryPolicy;

	/** Stand-in repository: records calls and mirrors the real compare-and-set. */
	class Jobs {
		public const TABLE_NAME = 'datamachine_jobs';

		/** @var array<int,array<string,mixed>> */
		public static array $calls = array();

		public function get_job( int $job_id ): ?array {
			global $wpdb;
			$row = $wpdb->pdo->query( "SELECT * FROM wp_datamachine_jobs WHERE job_id = {$job_id}" )->fetch( \PDO::FETCH_ASSOC );
			if ( ! is_array( $row ) ) {
				return null;
			}
			$row['engine_data'] = json_decode( (string) $row['engine_data'], true ) ?: array();
			return $row;
		}

		public function transition_orphaned_pending_job( int $job_id, string $verdict, string $observed_operation_state, int $observed_generation, string $trigger, int $grace_seconds ): array {
			global $wpdb;
			self::$calls[] = compact( 'job_id', 'verdict', 'observed_operation_state', 'observed_generation', 'trigger' );

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
			$updated = $wpdb->pdo->exec( 'UPDATE wp_datamachine_jobs SET status = ' . $wpdb->pdo->quote( $status ) . " WHERE job_id = {$job_id} AND status = 'pending'" );
			return array( 'success' => 1 === $updated, 'changed' => 1 === $updated, 'current_status' => 'pending', 'status' => $status );
		}
	}
}

namespace {
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\Jobs\JobReaper;
	use DataMachine\Core\Jobs\JobReaperHistory;
	use DataMachine\Core\PendingJobRecoveryPolicy;
	use DataMachine\Core\PluginSettings;
	use DataMachine\Engine\AI\System\SystemAgentServiceProvider;
	use DataMachine\Engine\AI\System\Tasks\JobReaperTask;
	use DataMachine\Engine\Tasks\RecurringScheduleRegistry;

	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/PendingJobRecoveryPolicy.php';
	require_once __DIR__ . '/../inc/Core/ChildJobRecoveryPolicy.php';
	require_once __DIR__ . '/../inc/Core/AbilityResult.php';
	require_once __DIR__ . '/../inc/Core/PluginSettings.php';
	require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';
	require_once __DIR__ . '/../inc/Core/Jobs/JobLiveness.php';
	require_once __DIR__ . '/../inc/Core/Jobs/JobReaperHistory.php';
	require_once __DIR__ . '/../inc/Core/Jobs/JobReaper.php';
	require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';
	require_once __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/JobReaperTask.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/SystemAgentServiceProvider.php';
	require_once __DIR__ . '/../inc/Engine/Tasks/RecurringScheduleRegistry.php';
	require_once __DIR__ . '/../inc/Engine/Filters/SchedulerIntervals.php';

	global $wpdb;
	$now = time();
	$ago = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', $now - $seconds );

	// -- 1. Registered and scheduled ---------------------------------------------------------------
	echo "[1] task registered and scheduled\n";
	$provider  = ( new \ReflectionClass( SystemAgentServiceProvider::class ) )->newInstanceWithoutConstructor();
	$tasks     = $provider->getBuiltInTasks( array() );
	$schedules = $provider->getBuiltInSchedules( array() );
	assert_reaper( 'job_reaper task type is registered to JobReaperTask', JobReaperTask::class === ( $tasks['job_reaper'] ?? null ) );
	assert_reaper( 'task type constant matches the registry key', 'job_reaper' === ( new JobReaperTask() )->getTaskType() );
	assert_reaper( 'task opts out of the agent-context gate (pure maintenance)', false === ( new JobReaperTask() )->requiresAgentContext() );
	$meta = JobReaperTask::getTaskMeta();
	assert_reaper( 'task supports manual runs and declares that it mutates', true === $meta['supports_run'] && true === $meta['mutates'] );
	$schedule = $schedules['job_reaper'] ?? array();
	assert_reaper( 'a recurring schedule is registered for the task', 'job_reaper' === ( $schedule['task_type'] ?? null ) );
	assert_reaper( 'the schedule runs every 15 minutes', 'every_15_minutes' === ( $schedule['interval'] ?? null ) );
	assert_reaper( 'every_15_minutes resolves to 900 seconds in the interval table', 900 === ( datamachine_get_default_scheduler_intervals()['every_15_minutes']['seconds'] ?? 0 ) );
	assert_reaper( 'the schedule is per-site (not network_only) and not per_agent', empty( $schedule['network_only'] ) && empty( $schedule['per_agent'] ) );
	assert_reaper( 'the schedule is on by default (mode, not a boolean, gates mutation)', true === ( $schedule['default_enabled'] ?? null ) && ! isset( $schedule['enabled_setting'] ) );
	RecurringScheduleRegistry::reset();
	assert_reaper( 'every site owns the schedule (per-site, not main-site-only)', RecurringScheduleRegistry::isOwnedByCurrentSite( array_merge( array( 'network_only' => false ), $schedule ) ) );
	assert_reaper( 'the recurring hook is a worker bootstrap hook', str_contains( file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '', "'" . RecurringScheduleRegistry::hookFor( array( 'schedule_id' => 'job_reaper' ) ) . "'" ) );

	// -- 2. Mode resolution ---------------------------------------------------------------------------
	echo "[2] mode resolution\n";
	$set_mode = static function ( $mode ): void {
		$GLOBALS['dm_options']['datamachine_settings'] = null === $mode ? array() : array( JobReaper::SETTING_MODE => $mode );
		PluginSettings::clearCache();
	};
	$set_mode( null );
	assert_reaper( 'default mode is dry_run', 'dry_run' === JobReaper::resolveMode() );
	$set_mode( 'apply' );
	assert_reaper( 'apply is honored', 'apply' === JobReaper::resolveMode() );
	$set_mode( 'off' );
	assert_reaper( 'off is honored', 'off' === JobReaper::resolveMode() );
	$set_mode( 'aply' );
	assert_reaper( 'a typo falls back to dry_run, never apply', 'dry_run' === JobReaper::resolveMode() );
	$set_mode( 1 );
	assert_reaper( 'a non-string value falls back to dry_run', 'dry_run' === JobReaper::resolveMode() );
	$set_mode( null );

	// -- Fixture: the pending-orphan verdict rows, shared with the recover-stuck smoke ----------------
	$reset = static function ( int $bulk_orphans = 0 ) use ( $wpdb, $ago, $now ): void {
		$GLOBALS['dm_options'] = array();
		Jobs::$calls           = array();
		$wpdb->query( 'DROP TABLE IF EXISTS wp_datamachine_jobs' );
		$wpdb->query( 'DROP TABLE IF EXISTS wp_actionscheduler_actions' );
		$wpdb->query( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY, flow_id TEXT, status TEXT NOT NULL, created_at TEXT NOT NULL, operation_state TEXT NULL, operation_claimed_at TEXT NULL, operation_generation INTEGER NOT NULL DEFAULT 0, engine_data TEXT NULL)' );
		$wpdb->query( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL)' );

		$job = static function ( int $id, array $o = array() ) use ( $wpdb, $ago ): void {
			$o    = array_merge(
				array(
					'flow_id' => '10',
					'status'  => 'pending',
					'created' => $ago( 3 * HOUR_IN_SECONDS ),
					'op'      => null,
					'claimed' => null,
					'engine'  => '{}',
				),
				$o
			);
			$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_datamachine_jobs (job_id, flow_id, status, created_at, operation_state, operation_claimed_at, operation_generation, engine_data) VALUES (?, ?, ?, ?, ?, ?, 1, ?)' );
			$stmt->execute( array( $id, $o['flow_id'], $o['status'], $o['created'], $o['op'], $o['claimed'], $o['engine'] ) );
		};

		// Five verdict rows (one per verdict, enqueue_interrupted twice).
		$job( 1, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUE_FAILED ) );
		$job( 2, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUED ) );
		$job( 3, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_PREPARING ) );
		$job( 4, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUING, 'claimed' => $ago( 7200 ) ) );
		$job( 5, array() );
		// Healthy in-flight rows the reaper must leave alone.
		$job( 6, array( 'engine' => json_encode( array( 'retry' => array( 'next_retry_at' => gmdate( 'c', $now + 900 ) ) ) ) ) );
		$job( 8, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUING, 'claimed' => $ago( 20 ) ) );
		$job( 9, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUED ) );
		$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, extended_args) VALUES (?, ?, ?, ?, ?, ?, NULL)' );
		$stmt->execute( array( 900, JR_EXECUTE_STEP_HOOK, 'pending', $ago( 3600 ), '0000-00-00 00:00:00', '{"job_id":9,"flow_step_id":"step_1"}' ) );
		$job( 11, array( 'created' => $ago( 600 ) ) );
		$job( 12, array( 'status' => 'processing', 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUED ) );

		$wpdb->pdo->beginTransaction();
		for ( $i = 0; $i < $bulk_orphans; $i++ ) {
			$job( 1000 + $i, array( 'op' => PendingJobRecoveryPolicy::OPERATION_STATE_ENQUEUE_FAILED ) );
		}
		$wpdb->pdo->commit();
	};

	$snapshot = static function () use ( $wpdb ): array {
		return $wpdb->pdo->query( 'SELECT job_id, status, operation_state, operation_generation, engine_data FROM wp_datamachine_jobs ORDER BY job_id' )->fetchAll( \PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC );
	};

	// Executor seam: the ability's REAL pending-orphan pass, shaped like its result.
	$executor_inputs = array();
	$make_executor   = static function ( array &$inputs ): callable {
		return static function ( array $input ) use ( &$inputs ) {
			$inputs[]   = $input;
			$reflection = new \ReflectionClass( \DataMachine\Abilities\Job\RecoverStuckJobsAbility::class );
			$ability    = $reflection->newInstanceWithoutConstructor();
			$prop       = $reflection->getProperty( 'db_jobs' );
			$prop->setAccessible( true );
			$prop->setValue( $ability, new Jobs() );
			$method = $reflection->getMethod( 'recoverPendingOrphans' );
			$method->setAccessible( true );

			$details = array();
			$omitted = 0;
			$summary = $method->invokeArgs(
				$ability,
				array( (bool) $input['dry_run'], null, null, HOUR_IN_SECONDS, (int) $input['pending_limit'], 2, (string) $input['recovery_trigger'], &$details, &$omitted )
			);

			return array(
				'success'         => true,
				'pending_orphans' => $summary,
				'jobs'            => $details,
				'mutations'       => $summary['terminalized'],
				'skipped'         => $summary['guarded'],
				'limit_reached'   => $summary['limit_reached'],
			);
		};
	};

	// -- 3. dry_run never mutates -----------------------------------------------------------------------
	echo "[3] dry_run records verdicts and never mutates\n";
	$reset();
	$executor_inputs = array();
	$before          = $snapshot();
	$run             = JobReaper::run( JobReaper::MODE_DRY_RUN, $make_executor( $executor_inputs ) );
	assert_reaper( 'dry run leaves every row byte-identical', $before === $snapshot() );
	assert_reaper( 'dry run makes no repository (CAS) calls', array() === Jobs::$calls );
	assert_reaper( 'ability input is a dry run without pending-orphan apply authorization', true === $executor_inputs[0]['dry_run'] && false === $executor_inputs[0]['recover_pending_orphans'] );
	assert_reaper( 'pathless-child recovery is never authorized', false === $executor_inputs[0]['recover_pathless_children'] );
	assert_reaper( 'the run is bounded by touch and pending limits', JobReaper::RUN_TOUCH_LIMIT === $executor_inputs[0]['limit'] && JobReaper::RUN_PENDING_LIMIT === $executor_inputs[0]['pending_limit'] );
	assert_reaper( 'ability input carries the job_reaper recovery trigger', 'job_reaper' === $executor_inputs[0]['recovery_trigger'] );
	assert_reaper( 'per-verdict counts are recorded', 1 === $run['verdicts']['enqueue_failed'] && 1 === $run['verdicts']['evidence_pruned'] && 2 === $run['verdicts']['enqueue_interrupted'] && 1 === $run['verdicts']['orphaned_pending'] );
	assert_reaper( 'would_act totals the verdicts and acted is zero', 5 === $run['would_act'] && 0 === $run['acted'] );
	assert_reaper( 'the run is recorded in history', 1 === count( JobReaperHistory::load()['runs'] ) );
	assert_reaper( 'flagged jobs are remembered for the next run', 5 === $run['flagged_count'] && 5 === count( JobReaperHistory::previousFlagged() ) );
	assert_reaper( 'flagged pending orphans record their flagged status and verdict', array( 'job_id' => 1, 'status' => 'pending', 'verdict' => 'enqueue_failed' ) === JobReaperHistory::previousFlagged()[0] );
	assert_reaper( 'a dry run with findings is logged', 1 === count( $GLOBALS['dm_logs'] ) && 'Job reaper dry run' === $GLOBALS['dm_logs'][0][1] );

	// -- 4. apply mutates through the same CAS path --------------------------------------------------------
	echo "[4] apply terminalizes through the repository CAS\n";
	$reset();
	$executor_inputs = array();
	$run             = JobReaper::run( JobReaper::MODE_APPLY, $make_executor( $executor_inputs ) );
	$after           = $snapshot();
	assert_reaper( 'ability input authorizes pending-orphan apply and is not a dry run', false === $executor_inputs[0]['dry_run'] && true === $executor_inputs[0]['recover_pending_orphans'] );
	assert_reaper( 'one CAS call per orphan, none for healthy rows', 5 === count( Jobs::$calls ) );
	assert_reaper( 'CAS calls carry the job_reaper trigger', array( 'job_reaper' ) === array_values( array_unique( array_column( Jobs::$calls, 'trigger' ) ) ) );
	assert_reaper( 'orphans are terminalized with their verdict as the reason', 'failed - enqueue_failed' === $after[1]['status'] && 'failed - evidence_pruned' === $after[2]['status'] && 'failed - enqueue_interrupted' === $after[3]['status'] && 'failed - orphaned_pending' === $after[5]['status'] );
	assert_reaper( 'healthy in-flight rows are untouched', 'pending' === $after[6]['status'] && 'pending' === $after[8]['status'] && 'pending' === $after[9]['status'] && 'pending' === $after[11]['status'] && 'processing' === $after[12]['status'] );
	assert_reaper( 'acted counts the mutations', 5 === $run['acted'] && 5 === $run['would_act'] );
	assert_reaper( 'apply records no flagged list (nothing left to second-guess)', 0 === $run['flagged_count'] && array() === JobReaperHistory::previousFlagged() );

	echo "[4b] apply is bounded per run\n";
	$reset( 130 );
	$executor_inputs = array();
	$run             = JobReaper::run( JobReaper::MODE_APPLY, $make_executor( $executor_inputs ) );
	assert_reaper( 'apply terminalizes at most RUN_PENDING_LIMIT rows', JobReaper::RUN_PENDING_LIMIT === $run['acted'] && JobReaper::RUN_PENDING_LIMIT === count( Jobs::$calls ) );
	assert_reaper( 'the bound is reported', true === $run['limit_reached'] );
	$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_datamachine_jobs WHERE status = 'pending' AND job_id >= 1000" );
	assert_reaper( 'the rest (5 fixture + 130 bulk - 100 bound) wait for the next run', 35 === $remaining );

	// -- 5. off does nothing ------------------------------------------------------------------------------------
	echo "[5] off does nothing\n";
	$reset();
	$executor_inputs = array();
	$before          = $snapshot();
	$run             = JobReaper::run( JobReaper::MODE_OFF, $make_executor( $executor_inputs ) );
	assert_reaper( 'off never calls the ability', array() === $executor_inputs );
	assert_reaper( 'off mutates nothing and makes no CAS calls', $before === $snapshot() && array() === Jobs::$calls );
	assert_reaper( 'off records nothing', array() === $GLOBALS['dm_options'] );
	assert_reaper( 'off reports it did not run', false === $run['ran'] && 'off' === $run['mode'] );
	$set_mode( 'off' );
	$run = JobReaper::run( null, $make_executor( $executor_inputs ) );
	assert_reaper( 'the configured mode is used when none is passed', array() === $executor_inputs && 'off' === $run['mode'] );
	$set_mode( null );

	// -- 6. History is bounded ---------------------------------------------------------------------------------------
	echo "[6] history is bounded\n";
	$GLOBALS['dm_options'] = array();
	$flag_rows             = array();
	for ( $i = 1; $i <= 120; $i++ ) {
		$flag_rows[] = array( 'job_id' => $i, 'scope' => 'pending_orphan', 'verdict' => 'enqueue_failed', 'status' => 'would_terminalize_pending_orphan' );
	}
	$stub = static fn( array $input ): array => array(
		'success'         => true,
		'pending_orphans' => array( 'verdicts' => array( 'enqueue_failed' => 120 ), 'evidence_complete' => true ),
		'jobs'            => $flag_rows,
		'skipped'         => 0,
	);
	$no_probe = static fn( int $job_id ): ?array => null;
	for ( $i = 0; $i < 150; $i++ ) {
		JobReaper::run( JobReaper::MODE_DRY_RUN, $stub, $no_probe );
	}
	$history = JobReaperHistory::load();
	$stored  = $GLOBALS['dm_options'][ JobReaperHistory::OPTION ];
	assert_reaper( 'only the newest MAX_RUNS runs are retained', JobReaperHistory::MAX_RUNS === count( $history['runs'] ) );
	assert_reaper( 'lifetime totals survive the rolling window', 150 === $history['totals']['runs'] && 150 === $history['totals']['dry_run_runs'] && 150 * 120 === $history['totals']['would_act'] );
	assert_reaper( 'the flagged list is capped', JobReaperHistory::MAX_FLAGGED === count( JobReaperHistory::previousFlagged() ) && JobReaperHistory::MAX_FLAGGED === end( $history['runs'] )['flagged_count'] );
	$with_flagged = array_filter( $history['runs'], static fn( array $r ): bool => ! empty( $r['flagged'] ) );
	assert_reaper( 'only the newest run keeps its flagged list', 1 === count( $with_flagged ) );
	echo '  size=' . strlen( serialize( $stored ) ) . "\n"; // phpcs:ignore
	assert_reaper( 'the option stays small (under 64 KB serialized)', strlen( serialize( $stored ) ) < 65536 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Size probe.
	assert_reaper( 'statusReport honors its limit, newest first', 5 === count( JobReaper::statusReport( 5 )['runs'] ) );

	// -- 7. False-positive tracking -------------------------------------------------------------------------------------
	echo "[7] false-positive signal (injected probe)\n";
	$GLOBALS['dm_options'] = array();
	$details               = array(
		array( 'job_id' => 1, 'scope' => 'pending_orphan', 'verdict' => 'enqueue_failed', 'status' => 'would_terminalize_pending_orphan' ),
		array( 'job_id' => 2, 'scope' => 'pending_orphan', 'verdict' => 'evidence_pruned', 'status' => 'would_terminalize_pending_orphan' ),
		array( 'job_id' => 3, 'status' => 'would_timeout' ),
		array( 'job_id' => 4, 'scope' => 'pending_orphan', 'verdict' => 'orphaned_pending', 'status' => 'would_terminalize_pending_orphan' ),
		array( 'job_id' => 5, 'scope' => 'pending_orphan', 'verdict' => 'orphaned_pending', 'status' => 'would_terminalize_pending_orphan' ),
		array( 'job_id' => 6, 'scope' => 'pending_orphan', 'verdict' => 'orphaned_pending', 'status' => 'would_terminalize_pending_orphan' ),
		array( 'job_id' => 7, 'action_id' => 55, 'hook' => 'x', 'status' => 'would_reconcile_action' ),
		array( 'job_id' => 8, 'status' => 'skipped', 'reason' => 'x' ),
	);
	$stub = static fn( array $input ): array => array(
		'success'         => true,
		'timed_out'       => 1,
		'pending_orphans' => array( 'verdicts' => array( 'enqueue_failed' => 1, 'evidence_pruned' => 1, 'orphaned_pending' => 3 ), 'evidence_complete' => true ),
		'jobs'            => $details,
		'skipped'         => 1,
	);
	$first = JobReaper::run( JobReaper::MODE_DRY_RUN, $stub, $no_probe );
	assert_reaper( 'flags exclude skipped rows and terminal-backed action reconciliation', array( 1, 2, 3, 4, 5, 6 ) === array_column( JobReaperHistory::previousFlagged(), 'job_id' ) );
	assert_reaper( 'a processing timeout is flagged with the processing status', 'processing' === JobReaperHistory::previousFlagged()[2]['status'] && 'timeout' === JobReaperHistory::previousFlagged()[2]['verdict'] );
	assert_reaper( 'the first run has nothing to re-check', null === ( $first['previous_check'] ?? null ) );
	assert_reaper( 'guarded rows are counted', 1 === $first['guarded'] );

	$observed = array(
		1 => array( 'status' => 'pending', 'alive' => true ),            // A live action appeared: alive.
		2 => array( 'status' => 'processing', 'alive' => true ),         // Started on its own: progressed.
		3 => array( 'status' => 'processing', 'alive' => false ),        // Still dead: true positive.
		4 => array( 'status' => 'failed - enqueue_failed', 'alive' => false ), // An operator acted: says nothing.
		5 => null,                                                       // Row deleted.
		6 => array( 'status' => 'pending', 'alive' => null ),            // Evidence incomplete: unknown.
	);
	$probe  = static fn( int $job_id ): ?array => $observed[ $job_id ] ?? null;
	$second = JobReaper::run( JobReaper::MODE_DRY_RUN, $stub, $probe );
	$check  = $second['previous_check'];
	assert_reaper( 'alive and progressed flags are false positives', 1 === $check['alive'] && 1 === $check['progressed'] );
	assert_reaper( 'a still-dead flag is a true positive', 1 === $check['still_flagged'] );
	assert_reaper( 'operator-resolved, deleted, and unjudgeable flags are not scored', 1 === $check['resolved'] && 1 === $check['gone'] && 1 === $check['unknown'] );
	assert_reaper( 'checked counts only the scored outcomes', 3 === $check['checked'] );
	$totals = JobReaperHistory::load()['totals'];
	assert_reaper( 'lifetime false-positive counters accumulate', 3 === $totals['fp_checked'] && 2 === $totals['fp_hits'] );
	assert_reaper( 'lifetime rate is hits over checked', abs( 2 / 3 - (float) JobReaperHistory::falsePositiveRate( $totals ) ) < 1e-9 );
	assert_reaper( 'no rate before any re-check', null === JobReaperHistory::falsePositiveRate( JobReaperHistory::emptyTotals() ) );
	$runs = JobReaperHistory::load()['runs'];
	assert_reaper( 'the consumed flagged list is dropped from the older run', empty( $runs[0]['flagged'] ) && ! empty( $runs[1]['flagged'] ) );
	$report = JobReaper::statusReport( 10 );
	assert_reaper( 'status report exposes mode, totals, rate, and shaped rows', 'dry_run' === $report['mode'] && 2 === count( $report['runs'] ) && abs( 2 / 3 - $report['false_positive_rate'] ) < 1e-9 );
	assert_reaper( 'status rows are newest first with the re-check columns', 2 === $report['runs'][0]['false_positives'] && 3 === $report['runs'][0]['rechecked'] && 1 === $report['runs'][0]['still_flagged'] );
	assert_reaper( 'status rows render non-zero verdict counts', str_contains( $report['runs'][0]['verdicts'], 'orphaned_pending=3' ) && ! str_contains( $report['runs'][0]['verdicts'], 'requeued=' ) );

	echo "[7b] false-positive signal (default probe: real liveness predicate)\n";
	$reset();
	$executor_inputs = array();
	$executor        = $make_executor( $executor_inputs );
	$first           = JobReaper::run( JobReaper::MODE_DRY_RUN, $executor );
	// Between runs: job 1 gets a live scheduler action, job 2 starts running, job 3 stays orphaned.
	$stmt = $wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, extended_args) VALUES (?, ?, ?, ?, ?, ?, NULL)' );
	$stmt->execute( array( 901, JR_EXECUTE_STEP_HOOK, 'pending', $ago( 30 ), '0000-00-00 00:00:00', '{"job_id":1,"flow_step_id":"step_1"}' ) );
	$wpdb->query( "UPDATE wp_datamachine_jobs SET status = 'processing' WHERE job_id = 2" );
	$wpdb->query( "UPDATE wp_datamachine_jobs SET status = 'failed - orphaned_pending' WHERE job_id = 5" );
	$second = JobReaper::run( JobReaper::MODE_DRY_RUN, $executor );
	$check  = $second['previous_check'];
	assert_reaper( 'a job that gained a live action is scored alive', 1 === $check['alive'] );
	assert_reaper( 'a job that started on its own is scored progressed', 1 === $check['progressed'] );
	assert_reaper( 'jobs still without a scheduler path stay flagged', 2 === $check['still_flagged'] );
	assert_reaper( 'an externally terminalized job is resolved, not scored', 1 === $check['resolved'] && 4 === $check['checked'] );

	// A failing ability is recorded, not thrown.
	echo "[8] ability failure is recorded\n";
	$GLOBALS['dm_options'] = array();
	$failing               = static fn( array $input ) => new \WP_Error( 'boom', 'ability exploded' );
	$run                   = JobReaper::run( JobReaper::MODE_DRY_RUN, $failing, $no_probe );
	assert_reaper( 'the run is recorded as failed with the error', false === $run['success'] && 'ability exploded' === $run['error'] && 1 === count( JobReaperHistory::load()['runs'] ) );
	assert_reaper( 'a failed pass is logged at warning level', 'warning' === end( $GLOBALS['dm_logs'] )[0] );

	// -- 9. Wiring ----------------------------------------------------------------------------------------------------------
	echo "[9] wiring\n";
	$cli_src    = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
	$reaper_src = file_get_contents( __DIR__ . '/../inc/Core/Jobs/JobReaper.php' ) ?: '';
	$task_src   = file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/JobReaperTask.php' ) ?: '';
	assert_reaper( 'reaper-status is a jobs subcommand', str_contains( $cli_src, '@subcommand reaper-status' ) && str_contains( $cli_src, 'public function reaper_status' ) );
	assert_reaper( 'the CLI is a thin adapter over the report', str_contains( $cli_src, 'JobReaper::statusReport(' ) );
	assert_reaper( 'the reaper runs the existing recover-stuck ability, not a copy of it', str_contains( $reaper_src, "'datamachine/recover-stuck-jobs'" ) && ! str_contains( $reaper_src, 'transition_' ) );
	assert_reaper( 'the reaper runs as the system, not as whichever user owns the request', str_contains( $reaper_src, 'PermissionHelper::run_as_system(' ) );
	assert_reaper( 'the task delegates to JobReaper::run()', str_contains( $task_src, 'JobReaper::run()' ) );
	assert_reaper( 'the re-check uses the single liveness predicate', str_contains( $reaper_src, 'JobLiveness::alive(' ) );
	assert_reaper( 'the jobs liveness CLI shares the child-count helper', str_contains( $cli_src, 'JobLiveness::childCounts(' ) && ! str_contains( $cli_src, 'function get_child_status_counts' ) );

	echo $GLOBALS['dm_smoke_failures']
		? "\nFAILED: {$GLOBALS['dm_smoke_failures']} job reaper assertions failed.\n"
		: "\nAll {$GLOBALS['dm_smoke_passes']} job reaper assertions passed.\n";
	exit( $GLOBALS['dm_smoke_failures'] ? 1 : 0 );
}
