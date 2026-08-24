<?php
/** Schema convergence regression coverage. */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use WP_UnitTestCase;

class SchemaConvergenceTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $schema_changes = array();

	public function test_second_schema_convergence_pass_issues_no_alter_table_queries(): void {
		$this->converge_implicated_tables();

		$this->capture_schema_changes( fn() => $this->converge_implicated_tables() );

		$this->assertSame( array(), $this->schema_changes );
	}

	public function test_stale_columns_are_repaired_before_schema_converges(): void {
		if ( BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'SQLite does not support ALTER TABLE MODIFY COLUMN.' );
		}

		global $wpdb;
		$jobs_table     = $wpdb->prefix . Jobs::TABLE_NAME;
		$processed_table = $wpdb->prefix . ProcessedItems::TABLE_NAME;
		$agents_table   = $wpdb->base_prefix . Agents::TABLE_NAME;

		$this->converge_implicated_tables();
		try {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY terminal_accounting_state TINYINT(3) NULL DEFAULT NULL', $jobs_table ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY terminal_accounting_processed_count INT(10) NOT NULL DEFAULT 0', $jobs_table ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY deferral_count INT(10) NOT NULL DEFAULT 0', $processed_table ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY instance_key LONGTEXT NULL', $agents_table ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY instance_key_hash CHAR(64) NULL', $agents_table ) );

			$this->capture_schema_changes(
				static function (): void {
					Jobs::create_table();
					( new ProcessedItems() )->create_table();
					Agents::ensure_identity_scope_schema();
				}
			);

			$this->assertCount( 5, $this->schema_changes );
			$this->assert_column( $jobs_table, 'terminal_accounting_state', '/^tinyint(?:\(\d+\))? unsigned$/', 'YES', null );
			$this->assert_column( $jobs_table, 'terminal_accounting_processed_count', '/^int(?:\(\d+\))? unsigned$/', 'NO', '0' );
			$this->assert_column( $processed_table, 'deferral_count', '/^int(?:\(\d+\))? unsigned$/', 'NO', '0' );
			$this->assert_column( $agents_table, 'instance_key', '/^longtext$/', 'NO', null );
			$this->assert_column( $agents_table, 'instance_key_hash', '/^char\(64\)$/', 'NO', null );
		} finally {
			$this->converge_implicated_tables();
		}
	}

	private function converge_implicated_tables(): void {
		Jobs::create_table();
		( new ProcessedItems() )->create_table();
		Agents::create_table();
		Agents::ensure_identity_scope_schema();
		Agents::ensure_site_scope_column();
	}

	private function capture_schema_changes( callable $callback ): void {
		$this->schema_changes = array();
		$filter               = function ( string $query ): string {
			if ( 1 === preg_match( '/^ALTER TABLE .*datamachine_(?:jobs|processed_items|agents)\b/i', ltrim( $query ) ) ) {
				$this->schema_changes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$callback();
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	private function assert_column( string $table, string $column, string $type_pattern, string $nullable, ?string $default ): void {
		global $wpdb;
		$actual = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $table, $column ), ARRAY_A );

		$this->assertIsArray( $actual );
		$this->assertMatchesRegularExpression( $type_pattern, strtolower( (string) $actual['Type'] ) );
		$this->assertSame( $nullable, $actual['Null'] );
		$this->assertSame( $default, $actual['Default'] );
	}
}
