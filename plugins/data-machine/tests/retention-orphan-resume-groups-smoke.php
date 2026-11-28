<?php
/**
 * Smoke: retention purges orphaned legacy AI-resume Action Scheduler groups
 * in bounded batches (Extra-Chill/data-machine#3943).
 *
 * Before #3943 every AI resume continuation was scheduled in its own group
 * (`data-machine-ai-resume-<job>-<generation>-<hash>`). Action Scheduler never
 * deletes group rows, so production carried ~1.85M orphan rows. Retention must
 * drain them in bounded batches, keep any group still referenced by an action
 * (of any status), and never touch the stable `data-machine-ai-resume` group
 * or unrelated groups.
 *
 * Drives the real RetentionCleanup against an in-memory SQLite database
 * through a minimal wpdb stand-in, so the SELECT / DELETE ... NOT EXISTS SQL
 * actually executes.
 *
 * Run with: php tests/retention-orphan-resume-groups-smoke.php
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

	use DataMachine\Engine\AI\AIConcurrencyBackpressure;
	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	if ( defined( 'WPINC' ) ) {
		echo "retention-orphan-resume-groups-smoke: skipped under real WordPress; standalone SQLite drives this contract.\n";
		exit( 0 );
	}

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
	final class Orphan_Groups_Wpdb {
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
			$sql = (string) preg_replace( '/^(DELETE .*) LIMIT \d+$/s', '$1', $sql );
			return $this->pdo->exec( $sql );
		}
	}

	$GLOBALS['dm_failures'] = 0;
	$GLOBALS['dm_passes']   = 0;

	function assert_groups( string $name, bool $condition, string $detail = '' ): void {
		if ( $condition ) {
			++$GLOBALS['dm_passes'];
			echo "  PASS {$name}\n";
			return;
		}
		++$GLOBALS['dm_failures'];
		echo "  FAIL {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}

	function wp_json_encode_safe( $value ): string {
		return (string) json_encode( $value );
	}

	require_once __DIR__ . '/fixtures/retention-batching-stubs.php';
	require_once __DIR__ . '/fixtures/scheduler-evidence-bootstrap.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Engine/AI/AIConcurrencyBackpressure.php';
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';

	echo "=== retention-orphan-resume-groups-smoke ===\n";

	$wpdb            = new Orphan_Groups_Wpdb();
	$GLOBALS['wpdb'] = $wpdb;
	$now             = time();
	$ago             = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', $now - $seconds );

	// Row ceilings use UNION arms with per-arm LIMIT that SQLite lacks.
	$GLOBALS['dm_filters']['datamachine_as_actions_hook_max_rows'] = array();

	$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY, hook TEXT, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT, args TEXT, extended_args TEXT NULL, group_id INTEGER NOT NULL DEFAULT 0)' );
	$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, action_id INTEGER)' );
	$wpdb->pdo->exec( 'CREATE TABLE wp_actionscheduler_groups (group_id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT)' );
	$wpdb->pdo->exec( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY, status TEXT)' );

	$add_group = static function ( string $slug ) use ( $wpdb ): int {
		$wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_groups (slug) VALUES (?)' )->execute( array( $slug ) );
		return (int) $wpdb->pdo->lastInsertId();
	};
	$next_action = 1;
	$add_action  = static function ( int $group_id, string $status, int $age_seconds, string $hook = 'datamachine_resume_ai_step' ) use ( $wpdb, $ago, &$next_action ): int {
		$id = $next_action++;
		$wpdb->pdo->prepare( 'INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)' )
			->execute( array( $id, $hook, $status, $ago( $age_seconds ), $ago( $age_seconds ), '{"job_id":' . $id . ',"flow_step_id":"ai-1"}', $group_id ) );
		return $id;
	};
	$group_exists = static function ( int $group_id ) use ( $wpdb ): bool {
		$stmt = $wpdb->pdo->prepare( 'SELECT COUNT(*) FROM wp_actionscheduler_groups WHERE group_id = ?' );
		$stmt->execute( array( $group_id ) );
		return 1 === (int) $stmt->fetchColumn();
	};
	$legacy_slug  = static fn( int $job_id, int $generation ): string => AIConcurrencyBackpressure::LEGACY_RESUME_GROUP_PREFIX . $job_id . '-' . $generation . '-' . substr( hash( 'sha256', $job_id . ':ai-1:' . $generation ), 0, 16 );

	// 250 orphan legacy groups (no action references them).
	$orphan_ids = array();
	for ( $job = 1; $job <= 250; $job++ ) {
		$orphan_ids[] = $add_group( $legacy_slug( 1000 + $job, 1 ) );
	}

	// Legacy groups still referenced by an action must survive.
	$live_pending_group     = $add_group( $legacy_slug( 2001, 3 ) );
	$live_in_progress_group = $add_group( $legacy_slug( 2002, 1 ) );
	$fresh_complete_group   = $add_group( $legacy_slug( 2003, 2 ) );
	$add_action( $live_pending_group, 'pending', -60 );
	$add_action( $live_in_progress_group, 'in-progress', 30 );
	$add_action( $fresh_complete_group, 'complete', 60 ); // Inside the resume retention window.

	// Groups that are never eligible, even when orphaned.
	$stable_group     = $add_group( AIConcurrencyBackpressure::RESUME_GROUP );
	$dm_group         = $add_group( 'data-machine' );
	$foreign_group    = $add_group( 'woocommerce-remote-inbox' );
	$lookalike_group  = $add_group( 'data-machine-ai-resumex-1' );
	$underscore_group = $add_group( 'data_machine_ai_resume_1' );

	echo "[1] stable resume group is not a legacy slug\n";
	assert_groups( 'stable group has no trailing dash', ! str_starts_with( AIConcurrencyBackpressure::RESUME_GROUP, AIConcurrencyBackpressure::LEGACY_RESUME_GROUP_PREFIX ) );

	echo "[2] count reports only orphaned legacy groups, without deleting\n";
	$groups_before = (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_actionscheduler_groups' )->fetchColumn();
	$count         = RetentionCleanup::countOrphanResumeGroups();
	assert_groups( 'orphan count equals the 250 seeded orphans', 250 === $count, "got {$count}" );
	assert_groups( 'count does not delete', (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_actionscheduler_groups' )->fetchColumn() === $groups_before );
	$breakdown = RetentionCleanup::countActionSchedulerBreakdown();
	assert_groups( 'dry-run breakdown reports orphan groups and folds them into the total', 250 === $breakdown['groups'] && $breakdown['total'] >= 250 );

	echo "[3] purge is bounded per batch and per pass\n";
	$GLOBALS['dm_filters']['datamachine_retention_orphan_group_batch_size']  = 1; // Clamped to the 100-row floor.
	$GLOBALS['dm_filters']['datamachine_retention_orphan_group_max_batches'] = 2;
	assert_groups( 'batch size is clamped to a 100-row floor', 100 === RetentionCleanup::orphanResumeGroupBatchSize() );

	$before_queries = count( $wpdb->queries );
	$first          = RetentionCleanup::cleanupOrphanResumeGroups();
	$pass_queries   = array_slice( $wpdb->queries, $before_queries );
	$selects        = array_values( array_filter( $pass_queries, static fn( string $q ): bool => str_starts_with( $q, 'SELECT g.group_id' ) ) );
	$deletes        = array_values( array_filter( $pass_queries, static fn( string $q ): bool => str_starts_with( $q, 'DELETE FROM' ) ) );
	assert_groups( 'first pass deletes exactly max_batches x batch_size orphans', 200 === $first['deleted'] && 2 === $first['batches'], wp_json_encode_safe( $first ) );
	assert_groups( 'first pass reports hit_limit so a catch-up run is scheduled', true === $first['hit_limit'] );
	assert_groups( 'every select is LIMIT-bounded by the batch size', 2 === count( $selects ) && array() === array_filter( $selects, static fn( string $q ): bool => ! str_ends_with( $q, 'LIMIT 100' ) ) );
	assert_groups( 'every delete targets an explicit id list and re-checks for referencing actions', 2 === count( $deletes ) && array() === array_filter( $deletes, static fn( string $q ): bool => ! ( str_contains( $q, 'group_id IN (' ) && str_contains( $q, 'NOT EXISTS' ) ) ) );
	assert_groups( '50 orphans remain after the bounded pass', 50 === RetentionCleanup::countOrphanResumeGroups() );

	$second = RetentionCleanup::cleanupOrphanResumeGroups();
	assert_groups( 'next pass drains the remaining backlog', 50 === $second['deleted'] && false === $second['hit_limit'] );
	assert_groups( 'no orphan legacy groups remain', 0 === RetentionCleanup::countOrphanResumeGroups() );
	assert_groups( 'all seeded orphans are gone', array() === array_filter( $orphan_ids, $group_exists ) );

	echo "[4] referenced and non-legacy groups are kept\n";
	assert_groups( 'legacy group with a pending action is kept', $group_exists( $live_pending_group ) );
	assert_groups( 'legacy group with an in-progress action is kept', $group_exists( $live_in_progress_group ) );
	assert_groups( 'legacy group with a not-yet-purged complete action is kept', $group_exists( $fresh_complete_group ) );
	assert_groups( 'stable data-machine-ai-resume group is kept', $group_exists( $stable_group ) );
	assert_groups( 'data-machine group is kept', $group_exists( $dm_group ) );
	assert_groups( 'foreign plugin group is kept', $group_exists( $foreign_group ) );
	assert_groups( 'look-alike slug without the exact prefix is kept', $group_exists( $lookalike_group ) );
	assert_groups( 'underscore look-alike slug is kept', $group_exists( $underscore_group ) );

	$third = RetentionCleanup::cleanupOrphanResumeGroups();
	assert_groups( 'idle pass deletes nothing and does not hit the limit', 0 === $third['deleted'] && 0 === $third['batches'] && false === $third['hit_limit'] );

	echo "[5] the delete re-check keeps a group that gains an action after selection\n";
	$raced_group = $add_group( $legacy_slug( 3001, 1 ) );
	$add_action( $raced_group, 'pending', -60 );
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE group_id IN (%d) AND NOT EXISTS ( SELECT 1 FROM %i a WHERE a.group_id = %i.group_id )',
			'wp_actionscheduler_groups',
			$raced_group,
			'wp_actionscheduler_actions',
			'wp_actionscheduler_groups'
		)
	);
	assert_groups( 'NOT EXISTS guard keeps a referenced group even when its id was selected', $group_exists( $raced_group ) );
	$cleanup_src = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' );
	assert_groups( 'production delete uses the same NOT EXISTS guard', str_contains( $cleanup_src, 'DELETE FROM %i WHERE group_id IN ({$placeholders}) AND NOT EXISTS ( SELECT 1 FROM %i a WHERE a.group_id = %i.group_id )' ) );

	echo "[6] the Action Scheduler retention pass drains groups whose actions it just purged\n";
	unset( $GLOBALS['dm_filters']['datamachine_retention_orphan_group_batch_size'], $GLOBALS['dm_filters']['datamachine_retention_orphan_group_max_batches'] );
	$stale_group  = $add_group( $legacy_slug( 4001, 2 ) );
	$stale_action = $add_action( $stale_group, 'complete', 3 * DAY_IN_SECONDS ); // Past the 1h resume window and 2-day global window.
	$result       = RetentionCleanup::cleanupActionSchedulerActions();
	$stmt         = $wpdb->pdo->prepare( 'SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE action_id = ?' );
	$stmt->execute( array( $stale_action ) );
	assert_groups( 'stale resume action was purged by the action pass', 0 === (int) $stmt->fetchColumn() );
	assert_groups( 'its now-orphaned legacy group was purged in the same pass', ! $group_exists( $stale_group ) );
	assert_groups( 'pass result reports groups_deleted and counts it toward deleted', 1 === ( $result['groups_deleted'] ?? null ) && $result['deleted'] >= 2, wp_json_encode_safe( $result ) );
	assert_groups( 'live legacy groups still survive the full pass', $group_exists( $live_pending_group ) && $group_exists( $live_in_progress_group ) && $group_exists( $stable_group ) );

	echo "[7] groups table is reachable by the operator optimize path\n";
	assert_groups( 'actionscheduler_groups is an owned table for retention optimize', 'wp_actionscheduler_groups' === ( RetentionCleanup::ownedTableNames()['actionscheduler_groups'] ?? null ) );

	$failures = $GLOBALS['dm_failures'];
	$total    = $failures + $GLOBALS['dm_passes'];
	echo "\n";
	if ( $failures > 0 ) {
		echo "retention-orphan-resume-groups-smoke failed: {$failures}/{$total} assertions failed.\n";
		exit( 1 );
	}
	echo "retention-orphan-resume-groups-smoke passed: {$total} assertions.\n";
}
