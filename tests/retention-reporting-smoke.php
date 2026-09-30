<?php
/**
 * Focused regression coverage for retention table allocation reporting (#3282, #3283)
 * and unattended table-space reclaim (#3587).
 *
 * Run with: php tests/retention-reporting-smoke.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database {
	abstract class BaseRepository {
		public static bool $sqlite = false;

		public static function is_sqlite(): bool {
			return self::$sqlite;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}

	$GLOBALS['retention_reporting_filters'] = array();
	function apply_filters( string $hook, $value ) {
		return $GLOBALS['retention_reporting_filters'][ $hook ] ?? $value;
	}
	function do_action( ...$args ): void {}

	$failed = 0;
	$total  = 0;
	function assert_retention_reporting( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$name}\n";
	}

	$root = dirname( __DIR__ );
	$cleanup_source = file_get_contents( $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
	$command_source = file_get_contents( $root . '/inc/Cli/Commands/RetentionCommand.php' ) ?: '';
	assert_retention_reporting( 'SQLite path avoids information_schema', str_contains( $cleanup_source, 'BaseRepository::is_sqlite()' ) );
	$results = '$results';
	assert_retention_reporting( 'restricted information_schema returns unavailable allocation', str_contains( $cleanup_source, "if ( ! is_array( $results ) )" ) );
	assert_retention_reporting( 'show total uses unique physical allocation', str_contains( $command_source, "\$sizes['_unique']" ) );
	assert_retention_reporting( 'show total preserves separately measured chat storage', str_contains( $command_source, "\$sizes['_unique']['Chat sessions']" ) );
	assert_retention_reporting( 'operator optimizer accepts selected tables only', str_contains( $command_source, 'RetentionCleanup::optimizeOwnedTables' ) );

	require_once $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';
	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	final class RetentionReportingWpdb {
		public string $prefix = 'wp_';
		public bool $restricted = false;
		public int $optimize_calls = 0;
		public int $free = 900;
		public string $engine = 'InnoDB';

		public function prepare( string $sql, ...$args ): array {
			return array( 'sql' => $sql, 'args' => $args );
		}

		public function get_results( $prepared ): ?array {
			if ( $this->restricted ) {
				return null;
			}
			return array(
				(object) array(
					'TABLE_NAME'    => 'wp_datamachine_jobs',
					'ENGINE'        => $this->engine,
					'TABLE_ROWS'    => 2,
					'DATA_LENGTH'   => 100,
					'INDEX_LENGTH'  => 100,
					'DATA_FREE'     => $this->free,
				),
			);
		}

		public function get_var( $prepared ): int {
			return 7;
		}

		public function query( $prepared ): int {
			++$this->optimize_calls;
			// A rebuild returns freed pages to the filesystem.
			$this->free = 0;
			return 0;
		}
	}

	$wpdb = new RetentionReportingWpdb();
	$GLOBALS['wpdb'] = $wpdb;
	\DataMachine\Core\Database\BaseRepository::$sqlite = true;
	$sqlite = RetentionCleanup::ownedTableAllocations();
	assert_retention_reporting( 'SQLite reports rows without claiming byte allocation', 7 === $sqlite['wp_datamachine_jobs']['rows'] && null === $sqlite['wp_datamachine_jobs']['live_bytes'] );
	\DataMachine\Core\Database\BaseRepository::$sqlite = false;
	$allocations = RetentionCleanup::ownedTableAllocations();
	$jobs = $allocations['wp_datamachine_jobs'];
	assert_retention_reporting( 'low-row high-DATA_FREE table is reported', 2 === $jobs['rows'] && 900 === $jobs['allocated_free_bytes'] );
	assert_retention_reporting( 'live bytes and reclaim ratio are reported', 200 === $jobs['live_bytes'] && 0.8181818181818182 === $jobs['reclaim_ratio'] );

	$GLOBALS['retention_reporting_filters']['datamachine_table_free_bytes_threshold'] = 800;
	$GLOBALS['retention_reporting_filters']['datamachine_table_free_ratio_threshold'] = 0.8;
	$health = RetentionCleanup::tableBloatHealth();
	assert_retention_reporting( 'threshold warning names exact table and command', 'warning' === $health['status'] && 'wp_datamachine_jobs' === $health['warnings'][0]['table'] && str_contains( $health['warnings'][0]['command'], '--tables=wp_datamachine_jobs --yes' ) );

	$optimized = RetentionCleanup::optimizeOwnedTables( array( 'datamachine_jobs', 'wp_not_owned' ) );
	assert_retention_reporting( 'selected owned table is optimized', in_array( 'wp_datamachine_jobs', $optimized['optimized'], true ) && 1 === $wpdb->optimize_calls );
	assert_retention_reporting( 'selected table allowlist rejects foreign table', isset( $optimized['rejected']['wp_not_owned'] ) );

	// #3587: unattended reclaim requires BOTH thresholds and a bounded live size.
	$wpdb->free           = 900;
	$wpdb->optimize_calls = 0;
	$GLOBALS['retention_reporting_filters']['datamachine_table_free_ratio_threshold'] = 0.9;
	assert_retention_reporting( 'health still warns when only the byte threshold is crossed', 'warning' === RetentionCleanup::tableBloatHealth()['status'] );
	$none = RetentionCleanup::reclaimTableSpace();
	assert_retention_reporting( 'auto reclaim requires both thresholds, not either', array() === $none['optimized'] && 0 === $wpdb->optimize_calls );

	$GLOBALS['retention_reporting_filters']['datamachine_table_free_ratio_threshold'] = 0.8;
	$GLOBALS['retention_reporting_filters']['datamachine_table_auto_reclaim_max_live_bytes'] = 100;
	$capped = RetentionCleanup::reclaimTableSpace();
	assert_retention_reporting( 'auto reclaim skips tables above the live-size cap', array() === $capped['optimized'] && 0 === $wpdb->optimize_calls );

	$GLOBALS['retention_reporting_filters']['datamachine_table_auto_reclaim_max_live_bytes'] = 0;
	$wpdb->engine = 'MyISAM';
	assert_retention_reporting( 'auto reclaim ignores non-InnoDB tables', 0 === RetentionCleanup::countAutoReclaimCandidates() );
	$wpdb->engine = 'InnoDB';

	assert_retention_reporting( 'crossing both thresholds makes the table a candidate', 1 === RetentionCleanup::countAutoReclaimCandidates() );
	$reclaimed = RetentionCleanup::reclaimTableSpace();
	assert_retention_reporting( 'auto reclaim rebuilds the candidate once', array( 'wp_datamachine_jobs' ) === $reclaimed['optimized'] && 1 === $wpdb->optimize_calls );
	assert_retention_reporting( 'auto reclaim reports reclaimed bytes per table and in total', 900 === $reclaimed['reclaimed_bytes'] && 900 === $reclaimed['tables']['wp_datamachine_jobs']['reclaimed_bytes'] && 0 === $reclaimed['tables']['wp_datamachine_jobs']['free_bytes_after'] );
	$again = RetentionCleanup::reclaimTableSpace();
	assert_retention_reporting( 'a reclaimed table is not rebuilt again', array() === $again['optimized'] && 1 === $wpdb->optimize_calls );

	$wpdb->restricted = true;
	assert_retention_reporting( 'auto reclaim is a no-op when allocation metadata is unavailable', array() === RetentionCleanup::reclaimTableSpace()['optimized'] );
	$unavailable = RetentionCleanup::ownedTableAllocations();
	assert_retention_reporting( 'information_schema failure degrades without byte claims', false === $unavailable['wp_datamachine_jobs']['available'] && null === $unavailable['wp_datamachine_jobs']['allocated_free_bytes'] );

	if ( $failed > 0 ) {
		echo "retention-reporting-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}
	echo "retention-reporting-smoke passed: {$total} assertions.\n";
}
