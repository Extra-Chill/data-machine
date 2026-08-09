<?php
/**
 * Regression coverage for the operator-controlled Action Scheduler claim index.
 *
 * Run with: php tests/action-scheduler-claim-index-migration-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {}
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/ActionScheduler/ClaimIndexMigration.php';

use DataMachine\Core\ActionScheduler\ClaimIndexMigration;

class ClaimIndexMigrationSmokeWpdb extends wpdb {
	public string $prefix = 'wp_7_';
	public string $last_error = '';
	public string $version = '10.11.8-MariaDB-ubu2204';
	public string $version_comment = 'mariadb.org binary distribution';
	public string $engine = 'InnoDB';
	public array $indexes = array();
	public array $queries = array();
	public bool $fail_alter = false;
	public bool $fail_index_inspection = false;

	public function prepare( $query, ...$args ): string {
		foreach ( $args as $argument ) {
			if ( str_contains( $query, '%i' ) ) {
				$query = preg_replace( '/%i/', '`' . str_replace( '`', '``', (string) $argument ) . '`', $query, 1 );
			} elseif ( str_contains( $query, '%s' ) ) {
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $argument ) . "'", $query, 1 );
			}
		}
		return $query;
	}

	public function get_var( $query ) {
		if ( 'SELECT DATABASE()' === $query ) {
			return 'wordpress';
		}
		if ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) {
			return '1';
		}
		return null;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			return array(
				'ENGINE'       => $this->engine,
				'TABLE_ROWS'   => 1000,
				'DATA_LENGTH'  => 1048576,
				'INDEX_LENGTH' => 524288,
			);
		}
		if ( str_contains( $query, 'VERSION()' ) ) {
			return array(
				'version'         => $this->version,
				'version_comment' => $this->version_comment,
				'datadir'         => '/database',
				'tmpdir'          => '/database-tmp',
			);
		}
		if ( str_contains( $query, 'innodb_tmpdir' ) ) {
			return array(
				'Variable_name' => 'innodb_tmpdir',
				'Value'         => '/innodb-tmp',
			);
		}
		return null;
	}

	public function get_results( $query = null, $output = null ): array {
		unset( $query, $output );
		if ( $this->fail_index_inspection ) {
			$this->last_error = 'simulated SHOW INDEX failure';
			return array();
		}
		return $this->indexes;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'ALTER TABLE' ) ) {
			if ( $this->fail_alter ) {
				$this->last_error = 'simulated online DDL failure';
				return false;
			}
			$this->indexes = claim_index_rows( ClaimIndexMigration::INDEX_NAME, ClaimIndexMigration::REQUIRED_COLUMNS );
		}
		return 1;
	}
}

/** @return array<int,array<string,int|string>> */
function claim_index_rows( string $name, array $columns ): array {
	$rows = array();
	foreach ( $columns as $offset => $column ) {
		$rows[] = array(
			'Key_name'     => $name,
			'Seq_in_index' => $offset + 1,
			'Column_name'  => $column,
			'Sub_part'     => null,
			'Index_type'   => 'BTREE',
			'Collation'    => 'A',
			'Visible'      => 'YES',
			'Ignored'      => 'NO',
			'Expression'   => null,
		);
	}
	return $rows;
}

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = "FAIL: {$label}";
	}
};

$required_rows = claim_index_rows( 'upstream_or_custom_name', ClaimIndexMigration::REQUIRED_COLUMNS );
$indexes       = ClaimIndexMigration::normalizeUsableIndexes( array_reverse( $required_rows ) );
$assert( 'upstream_or_custom_name' === ClaimIndexMigration::findCoveringIndex( $indexes ), 'detects exact ordered columns under any index name' );

$wrong_columns    = ClaimIndexMigration::REQUIRED_COLUMNS;
$wrong_columns[3] = 'scheduled_date_gmt';
$wrong_columns[4] = 'attempts';
$assert( null === ClaimIndexMigration::findCoveringIndex( ClaimIndexMigration::normalizeUsableIndexes( claim_index_rows( 'wrong_order', $wrong_columns ) ) ), 'rejects a wrong claim column order' );

$prefix_rows                = $required_rows;
$prefix_rows[1]['Sub_part'] = 10;
$assert( null === ClaimIndexMigration::findCoveringIndex( ClaimIndexMigration::normalizeUsableIndexes( $prefix_rows ) ), 'rejects a prefix index' );
$invisible_rows               = $required_rows;
$invisible_rows[0]['Visible'] = 'NO';
$assert( null === ClaimIndexMigration::findCoveringIndex( ClaimIndexMigration::normalizeUsableIndexes( $invisible_rows ) ), 'rejects an invisible index' );
$ignored_rows               = $required_rows;
$ignored_rows[0]['Ignored'] = 'YES';
$assert( null === ClaimIndexMigration::findCoveringIndex( ClaimIndexMigration::normalizeUsableIndexes( $ignored_rows ) ), 'rejects a MariaDB ignored index' );
$extended_rows   = $required_rows;
$extended_rows[] = array(
	'Key_name'     => 'upstream_or_custom_name',
	'Seq_in_index' => 7,
	'Column_name'  => 'hook',
	'Sub_part'     => 20,
	'Index_type'   => 'BTREE',
	'Collation'    => 'A',
	'Visible'      => 'YES',
	'Ignored'      => 'NO',
	'Expression'   => null,
);
$assert( 'upstream_or_custom_name' === ClaimIndexMigration::findCoveringIndex( ClaimIndexMigration::normalizeUsableIndexes( $extended_rows ) ), 'accepts harmless trailing index columns after the exact required prefix' );

$mysql = ClaimIndexMigration::detectOnlineDdlSupport( '8.0.42', 'MySQL Community Server', 'InnoDB' );
$maria = ClaimIndexMigration::detectOnlineDdlSupport( '5.5.5-10.11.8-MariaDB', 'MariaDB Server', 'InnoDB' );
$old   = ClaimIndexMigration::detectOnlineDdlSupport( '5.5.62', 'MySQL Community Server', 'InnoDB' );
$assert( $mysql['supported'], 'supports explicit online DDL on modern MySQL' );
$assert( $maria['supported'] && '10.11.8' === $maria['version'], 'normalizes the MariaDB compatibility version prefix' );
$assert( ! $old['supported'], 'fails closed on an unsupported MySQL runtime' );
$assert( ClaimIndexMigration::isLocalDatabaseHost( 'localhost:3306' ), 'recognizes an exact loopback DB host with port' );
$assert( ClaimIndexMigration::isLocalDatabaseHost( 'localhost:/run/mysqld/mysqld.sock' ), 'recognizes a local DB socket' );
$assert( ! ClaimIndexMigration::isLocalDatabaseHost( 'localhost.example.com' ), 'does not treat a prefixed remote hostname as local' );

$wpdb      = new ClaimIndexMigrationSmokeWpdb();
$migration = new ClaimIndexMigration( $wpdb, static fn () => false, 'remote-db.example' );
$dry_run   = $migration->inspect( 2 * 1024 * 1024 * 1024 );
$assert( $dry_run['success'] && $dry_run['can_apply'] && ! $dry_run['ready'], 'dry run reports a migration-ready preflight' );
$assert( array() === $wpdb->queries, 'dry run never executes a mutation' );
$assert(
	'ALTER TABLE `wp_7_actionscheduler_actions` ADD INDEX `claim_id_status_priority_attempts_scheduled_date_gmt` (`claim_id`, `status`, `priority`, `attempts`, `scheduled_date_gmt`, `action_id`), ALGORITHM=INPLACE, LOCK=NONE' === $dry_run['ddl'],
	'generates fail-closed online DDL for the active site table'
);

$blocked = $migration->inspect();
$assert( ! $blocked['can_apply'] && 'blocked' === $blocked['status'], 'remote database disk uncertainty blocks apply' );
$assert( str_contains( implode( ' ', $blocked['blockers'] ), '--available-disk-bytes' ), 'blocked preflight explains the operator disk override' );

$disk_paths      = array();
$local_migration = new ClaimIndexMigration(
	new ClaimIndexMigrationSmokeWpdb(),
	static function ( string $path ) use ( &$disk_paths ): int {
		$disk_paths[] = $path;
		return 2 * 1024 * 1024 * 1024;
	},
	'localhost'
);
$local = $local_migration->inspect();
$assert( $local['can_apply'], 'local database filesystems can establish sufficient disk headroom' );
$assert( array( '/database', '/innodb-tmp' ) === $disk_paths, 'disk preflight checks datadir and innodb_tmpdir rather than the generic tmpdir' );

$insufficient = $migration->inspect( 1024 );
$assert( ! $insufficient['can_apply'] && ! $insufficient['disk']['sufficient'], 'insufficient operator-supplied disk fails closed' );

$applied = $migration->apply( 2 * 1024 * 1024 * 1024 );
$assert( $applied['ready'] && $applied['applied'], 'apply reinspects and verifies the physical index' );
$assert( 2 === count( $wpdb->queries ), 'apply executes only the session timeout and online DDL mutations' );

$ready_again = $migration->apply( 2 * 1024 * 1024 * 1024 );
$assert( $ready_again['ready'] && ! $ready_again['applied'], 're-running apply is an idempotent no-op when ready' );
$assert( 2 === count( $wpdb->queries ), 'idempotent apply does not execute DDL again' );

$collision_wpdb          = new ClaimIndexMigrationSmokeWpdb();
$collision_wpdb->indexes = claim_index_rows( ClaimIndexMigration::INDEX_NAME, array( 'claim_id', 'status', 'priority' ) );
$collision               = ( new ClaimIndexMigration( $collision_wpdb, static fn () => false, 'remote-db.example' ) )->inspect( 2 * 1024 * 1024 * 1024 );
$assert( $collision['name_collision'] && ! $collision['can_apply'], 'same-name malformed index is never dropped automatically' );

$unsupported_wpdb          = new ClaimIndexMigrationSmokeWpdb();
$unsupported_wpdb->version = '5.5.62';
$unsupported               = ( new ClaimIndexMigration( $unsupported_wpdb, static fn () => false, 'remote-db.example' ) )->inspect( 2 * 1024 * 1024 * 1024 );
$assert( ! $unsupported['runtime']['supported'] && ! $unsupported['can_apply'], 'unsupported runtime blocks apply even with disk headroom' );

$failed_inspection_wpdb                        = new ClaimIndexMigrationSmokeWpdb();
$failed_inspection_wpdb->fail_index_inspection = true;
$failed_inspection                             = ( new ClaimIndexMigration( $failed_inspection_wpdb, static fn () => false, 'remote-db.example' ) )->inspect( 2 * 1024 * 1024 * 1024 );
$assert( ! $failed_inspection['success'] && 'inspection_failed' === $failed_inspection['status'], 'SHOW INDEX database errors fail closed' );

if ( $failures ) {
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "Action Scheduler claim index migration smoke complete: all assertions passed.\n";
