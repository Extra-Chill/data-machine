<?php
/**
 * Smoke: the orphaned batch-worklist sweep deletes only worklists whose
 * parent job row is gone, in bounded chunks (Extra-Chill/data-machine#3565).
 *
 * Runs against an in-memory SQLite database with a minimal wpdb stand-in, so
 * the real SQL in RetentionCleanup::cleanupBatchWorklists() is exercised.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$failures = 0;
	$passes   = 0;

	function assert_worklist_sweep( string $name, bool $condition ): void {
		global $failures, $passes;
		if ( $condition ) {
			++$passes;
			echo "  PASS {$name}\n";
		} else {
			++$failures;
			echo "  FAIL {$name}\n";
		}
	}

	function apply_filters( string $hook, $value ) {
		unset( $hook );
		return $value;
	}

	function do_action( ...$args ): void {
		unset( $args );
	}

	/** Minimal wpdb over SQLite supporting the prepare()/%i/%d/%s subset the sweep uses. */
	final class Sweep_Wpdb {
		public string $prefix = 'wp_';
		public \PDO $pdo;

		public function __construct() {
			$this->pdo = new \PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		}

		public function prepare( string $sql, ...$args ): string {
			// Like wpdb::prepare(), accept a single array of replacements.
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

		public function get_col( string $sql ): array {
			return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_COLUMN );
		}

		public function get_var( string $sql ) {
			return $this->pdo->query( $sql )->fetchColumn();
		}
	}

	$GLOBALS['wpdb'] = new Sweep_Wpdb();
}

namespace DataMachine\Core\Database\BatchItems {
	class BatchItems {
		public const TABLE_NAME = 'datamachine_batch_items';
	}
}

namespace DataMachine\Core\Database\Jobs {
	// Stand-in exposing only the constant the sweep reads; the value mirrors
	// the real repository's table name.
	class Jobs {
		public const TABLE_NAME = 'datamachine' . '_jobs';
	}
}

namespace {
	require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';

	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	global $wpdb;
	$wpdb->query( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY)' );
	$wpdb->query( 'CREATE TABLE wp_datamachine_batch_items (batch_job_id INTEGER NOT NULL, item_index INTEGER NOT NULL, PRIMARY KEY (batch_job_id, item_index))' );

	// Live parents 1 and 2 with worklists; orphaned worklists for 100..2599 (2,500 parents).
	$wpdb->query( 'INSERT INTO wp_datamachine_jobs (job_id) VALUES (1), (2)' );
	foreach ( array( 1, 2 ) as $parent ) {
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->query( "INSERT INTO wp_datamachine_batch_items VALUES ({$parent}, {$i})" );
		}
	}
	$wpdb->pdo->beginTransaction();
	for ( $parent = 100; $parent < 2600; $parent++ ) {
		$wpdb->query( "INSERT INTO wp_datamachine_batch_items VALUES ({$parent}, 0), ({$parent}, 1)" );
	}
	$wpdb->pdo->commit();

	echo "[1] count\n";
	assert_worklist_sweep( 'counts only orphaned rows', 5000 === RetentionCleanup::countBatchWorklists() );

	echo "[2] sweep\n";
	$result = RetentionCleanup::cleanupBatchWorklists();
	assert_worklist_sweep( 'deletes every orphaned row across multiple chunks', 5000 === $result['deleted'] );
	assert_worklist_sweep( 'reports not capped when all orphans fit in one run', false === $result['capped'] );
	assert_worklist_sweep( 'leaves worklists of live parents untouched', 6 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_datamachine_batch_items' ) );
	assert_worklist_sweep( 'no orphans remain', 0 === RetentionCleanup::countBatchWorklists() );

	echo "[3] idempotent\n";
	$again = RetentionCleanup::cleanupBatchWorklists();
	assert_worklist_sweep( 'a second run deletes nothing', 0 === $again['deleted'] );

	echo $failures ? "\nFAILED: {$failures} batch-worklist sweep assertions failed.\n" : "\nAll {$passes} batch-worklist sweep assertions passed.\n";
	exit( $failures ? 1 : 0 );
}
