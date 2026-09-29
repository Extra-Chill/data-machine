<?php
/**
 * Smoke: retention keeps failed step-execution actions for live jobs
 * (Extra-Chill/data-machine#3577, part of #3481).
 *
 * Action Scheduler's QueueCleaner flips a dead worker's in-progress action to
 * `failed`; that row is the crash signal the recovery reaper reads. Retention
 * must not delete it while its job is still pending/processing/waiting.
 *
 * Drives the real RetentionCleanup::cleanupActionSchedulerActions() against an
 * in-memory SQLite database (actions, logs, jobs) through a minimal wpdb
 * stand-in that records every query, so "ONE jobs lookup per batch" is
 * asserted from the query log rather than assumed.
 *
 * Run with: php tests/retention-live-job-evidence-smoke.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database\Jobs {
	if ( ! class_exists( __NAMESPACE__ . '\\Jobs' ) ) {
		class Jobs {
			public const TABLE_NAME = 'datamachine' . '_jobs'; // Mirrors the real repository constant.
		}
	}
}

namespace {

	use DataMachine\Core\DirectJobEnqueuer;
	use DataMachine\Engine\AI\AIConcurrencyBackpressure;
	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['dm_filters'] = array();

	function apply_filters( string $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['dm_filters'] ) ? $GLOBALS['dm_filters'][ $hook ] : $value;
	}

	function do_action( ...$args ) {
		unset( $args );
	}

	function maybe_unserialize( $data ) {
		return $data;
	}

	/** Minimal wpdb over SQLite covering the prepare()/query subset retention uses. */
	final class Retention_Live_Wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';

		/** @var array<int,string> */
		public array $queries = array();
		public \PDO $pdo;

		public function __construct() {
			$this->pdo = new \PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		}

		public function prepare( string $sql, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i   = 0;
			$sql = (string) preg_replace( '/FORCE INDEX \([^)]*\)/', '', $sql );
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
			return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
		}

		public function get_col( string $sql ): array {
			$this->queries[] = $sql;
			return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_COLUMN );
		}

		public function get_var( string $sql ) {
			if ( str_contains( $sql, 'information_schema' ) ) {
				return 0;
			}
			$this->queries[] = $sql;
			$value           = $this->pdo->query( $sql )->fetchColumn();
			return false === $value ? null : $value;
		}

		public function query( string $sql ) {
			$this->queries[] = $sql;
			// SQLite ships without DELETE ... LIMIT.
			$sql = (string) preg_replace( '/^(DELETE .*) LIMIT \d+$/', '$1', $sql );
			return $this->pdo->exec( $sql );
		}
	}

	$GLOBALS['dm_failures'] = 0;
	$GLOBALS['dm_passes']   = 0;

	function assert_live( string $name, bool $condition, string $detail = '' ): void {
		if ( $condition ) {
			++$GLOBALS['dm_passes'];
			echo "  PASS {$name}\n";
			return;
		}
		++$GLOBALS['dm_failures'];
		echo "  FAIL {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}

	require_once __DIR__ . '/fixtures/retention-batching-stubs.php';
	require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';

	$wpdb            = new Retention_Live_Wpdb();
	$GLOBALS['wpdb'] = $wpdb;
	$now             = time();
	$ago             = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', $now - $seconds );

	// Tests must not depend on the host defaults: row ceilings need UNION arms
	// with per-arm LIMIT that SQLite lacks, and a 2-day evidence ceiling keeps
	// the ceiling case distinct from the 7-day global window.
	$GLOBALS['dm_filters']['datamachine_as_actions_hook_max_rows']          = array();
	$GLOBALS['dm_filters']['datamachine_as_live_job_evidence_max_age_days'] = 2;

	$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL)' );
	$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, action_id INTEGER)' );
	$wpdb->pdo->exec( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY, status TEXT)' );

	$jobs = array(
		1 => 'processing',
		2 => 'completed',
		3 => 'pending',
		4 => 'waiting',
		5 => 'failed - worker crashed',
		6 => 'agent_skipped - not a music event',
		7 => 'processing',
	);
	foreach ( $jobs as $job_id => $status ) {
		$wpdb->pdo->prepare( 'INSERT INTO wp_datamachine_jobs (job_id, status) VALUES (?, ?)' )->execute( array( $job_id, $status ) );
	}

	$next_id = 1;
	$insert  = static function ( string $hook, string $status, string $args, int $age_seconds, ?string $extended = null ) use ( $wpdb, $ago, &$next_id ): int {
		$id = $next_id++;
		$wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, extended_args) VALUES (?, ?, ?, ?, ?, ?, ?)' )
			->execute( array( $id, $hook, $status, $ago( $age_seconds ), $ago( $age_seconds ), $args, $extended ) );
		$wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_logs (action_id) VALUES (?)' )->execute( array( $id ) );
		return $id;
	};
	$step   = static fn( int $job_id ): string => '{"job_id":' . $job_id . ',"flow_step_id":"s1"}';
	$hours3 = 3 * HOUR_IN_SECONDS;

	$exec = DirectJobEnqueuer::HOOK;
	$ai   = AIConcurrencyBackpressure::RESUME_HOOK;
	$bat  = 'datamachine_pipeline_batch_chunk';

	// Failed rows past the 1h window, inside the 2-day evidence ceiling.
	$processing_failed  = $insert( $exec, 'failed', $step( 1 ), $hours3 );
	$completed_failed   = $insert( $exec, 'failed', $step( 2 ), $hours3 );
	$pending_ai_failed  = $insert( $ai, 'failed', $step( 3 ), $hours3 );
	$waiting_failed     = $insert( $exec, 'failed', $step( 4 ), $hours3 );
	$failed_job_failed  = $insert( $exec, 'failed', $step( 5 ), $hours3 );
	$skipped_job_failed = $insert( $exec, 'failed', $step( 6 ), $hours3 );
	$batch_live_failed  = $insert( $bat, 'failed', '{"parent_job_id":1,"offset":0}', $hours3 );
	$batch_done_failed  = $insert( $bat, 'failed', '{"parent_job_id":2,"offset":0}', $hours3 );
	$missing_job_failed = $insert( $exec, 'failed', $step( 999 ), $hours3 );
	$no_job_failed      = $insert( $exec, 'failed', '{"flow_step_id":"s1"}', $hours3 );
	$long_args_failed   = $insert( $exec, 'failed', md5( 'long' ), $hours3, '{"job_id":7,"flow_step_id":"' . str_repeat( 'x', 200 ) . '"}' );

	// complete/canceled rows for a live job are pruned regardless.
	$processing_complete = $insert( $exec, 'complete', $step( 1 ), $hours3 );
	$processing_canceled = $insert( $exec, 'canceled', $step( 1 ), $hours3 );

	// Older than the evidence ceiling: a stuck job cannot pin rows forever.
	$ceiling_failed = $insert( $exec, 'failed', $step( 1 ), 3 * DAY_IN_SECONDS );

	// Too fresh for the 1h window: untouched either way.
	$fresh_failed = $insert( $exec, 'failed', $step( 2 ), 60 );

	// Live-job hooks outside the protected set behave exactly as before.
	$other_failed = $insert( 'datamachine_run_flow_now', 'failed', '[7,1]', 8 * DAY_IN_SECONDS );

	echo "[1] failed rows for live jobs are retained; everything else is pruned\n";
	$before_queries = count( $wpdb->queries );
	$result         = RetentionCleanup::cleanupActionSchedulerActions();
	$survivors      = array_map( 'intval', $wpdb->pdo->query( 'SELECT action_id FROM wp_actionscheduler_actions' )->fetchAll( \PDO::FETCH_COLUMN ) );
	$has            = static fn( int $id ): bool => in_array( $id, $survivors, true );

	assert_live( 'failed action for a processing job is retained', $has( $processing_failed ) );
	assert_live( 'failed resume_ai_step for a pending job is retained', $has( $pending_ai_failed ) );
	assert_live( 'failed action for a waiting job is retained', $has( $waiting_failed ) );
	assert_live( 'failed batch chunk for a processing parent is retained', $has( $batch_live_failed ) );
	assert_live( 'md5-args action resolves its job through extended_args and is retained', $has( $long_args_failed ) );
	assert_live( 'failed action for a completed job is pruned', ! $has( $completed_failed ) );
	assert_live( 'failed action for a compound failed status job is pruned', ! $has( $failed_job_failed ) );
	assert_live( 'failed action for a compound agent_skipped job is pruned', ! $has( $skipped_job_failed ) );
	assert_live( 'failed batch chunk for a completed parent is pruned', ! $has( $batch_done_failed ) );
	assert_live( 'failed action for a deleted job is pruned', ! $has( $missing_job_failed ) );
	assert_live( 'failed action with no job id is pruned', ! $has( $no_job_failed ) );

	echo "[2] complete and canceled pruning is unchanged\n";
	assert_live( 'complete action is pruned even when its job is live', ! $has( $processing_complete ) );
	assert_live( 'canceled action is pruned even when its job is live', ! $has( $processing_canceled ) );
	assert_live( 'non-step hook failed rows follow the global window', ! $has( $other_failed ) );
	assert_live( 'rows inside the 1h window survive', $has( $fresh_failed ) );

	echo "[3] the absolute ceiling releases rows a stuck job would pin\n";
	assert_live( 'failed action older than the ceiling is pruned though its job is still processing', ! $has( $ceiling_failed ) );
	assert_live( 'the ceiling is filterable and defaults to 7 days', 7.0 === ( function () {
		$saved = $GLOBALS['dm_filters'];
		unset( $GLOBALS['dm_filters']['datamachine_as_live_job_evidence_max_age_days'] );
		$days                = RetentionCleanup::liveJobEvidenceMaxAgeDays();
		$GLOBALS['dm_filters'] = $saved;
		return $days;
	} )() );

	echo "[4] counts and lookups\n";
	assert_live( 'retained_for_live_jobs counts kept rows', 5 === $result['retained_for_live_jobs'], 'got ' . $result['retained_for_live_jobs'] );
	assert_live( 'deletion counts still report actions and logs', $result['actions_deleted'] > 0 && $result['logs_deleted'] > 0 );
	assert_live( 'logs of pruned actions are deleted; logs of retained actions stay', 0 === (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_actionscheduler_logs WHERE action_id IN (' . $processing_complete . ',' . $completed_failed . ')' )->fetchColumn() && 5 <= (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_actionscheduler_logs' )->fetchColumn() );

	$jobs_queries = array_values( array_filter( array_slice( $wpdb->queries, $before_queries ), static fn( string $q ): bool => str_contains( $q, 'wp_datamachine_jobs' ) ) );
	assert_live( 'one jobs lookup per hook batch, never per row', count( $jobs_queries ) <= 3, 'got ' . count( $jobs_queries ) );
	assert_live( 'the jobs lookup is a single IN (...) query', array() === array_filter( $jobs_queries, static fn( string $q ): bool => ! str_contains( $q, 'job_id IN (' ) ) );

	echo "[5] bounded: many failed rows resolve with one lookup per batch and page past retained rows\n";
	$wpdb->pdo->exec( 'DELETE FROM wp_actionscheduler_actions' );
	$wpdb->pdo->exec( 'DELETE FROM wp_actionscheduler_logs' );
	$next_id = 1;
	$retained_ids = array();
	for ( $i = 0; $i < 999; $i++ ) {
		// Oldest first, so the first 1000-row batch is almost entirely retained rows.
		$retained_ids[] = $insert( $exec, 'failed', $step( 1 ), $hours3 + 5000 - $i );
	}
	for ( $i = 0; $i < 1300; $i++ ) {
		$insert( $exec, 'failed', $step( 2 ), $hours3 + 1000 - ( $i % 500 ) );
	}
	$before_queries = count( $wpdb->queries );
	$result         = RetentionCleanup::cleanupActionSchedulerActions();
	$remaining      = (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_actionscheduler_actions' )->fetchColumn();
	$kept_live      = (int) $wpdb->pdo->query( "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE args LIKE '%\"job_id\":1,%'" )->fetchColumn();
	$queries        = array_slice( $wpdb->queries, $before_queries );
	$jobs_queries   = array_values( array_filter( $queries, static fn( string $q ): bool => str_contains( $q, 'wp_datamachine_jobs' ) ) );
	$select_queries = array_values( array_filter( $queries, static fn( string $q ): bool => str_starts_with( $q, 'SELECT action_id, last_attempt_gmt' ) ) );
	assert_live( 'all 1300 completed-job rows are pruned after paging past retained rows', 999 === $remaining && 999 === $kept_live, "remaining={$remaining} kept_live={$kept_live}" );
	assert_live( 'retained count reflects the live-job rows', 999 === $result['retained_for_live_jobs'], 'got ' . $result['retained_for_live_jobs'] );
	assert_live( 'jobs lookups never exceed one per selected batch', count( $jobs_queries ) <= count( $select_queries ) && count( $jobs_queries ) <= 4, count( $jobs_queries ) . ' lookups / ' . count( $select_queries ) . ' selects' );
	assert_live( 'each lookup binds distinct job ids (2), not one id per row', array() === array_filter( $jobs_queries, static fn( string $q ): bool => 1 !== preg_match( '/job_id IN \(\s*\d+\s*(,\s*\d+\s*)?\)/', $q ) ) );
	assert_live( 'the pass did not hit the exclusion cap', false === $result['hit_limit'] );

	echo "\n";
	if ( $GLOBALS['dm_failures'] > 0 ) {
		echo "retention-live-job-evidence-smoke: {$GLOBALS['dm_failures']} failed, {$GLOBALS['dm_passes']} passed.\n";
		exit( 1 );
	}
	echo "retention-live-job-evidence-smoke passed: {$GLOBALS['dm_passes']} assertions.\n";
}
