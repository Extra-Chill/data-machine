<?php
/** Schema bootstrap must not ALTER existing tables on a second pass. */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use WP_UnitTestCase;

class SchemaConvergenceTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $schema_changes = array();

	public function test_second_schema_bootstrap_pass_issues_no_alter_table_queries(): void {
		$this->bootstrap_implicated_tables();

		$this->capture_schema_changes( fn() => $this->bootstrap_implicated_tables() );

		$this->assertSame( array(), $this->schema_changes );
	}

	private function bootstrap_implicated_tables(): void {
		Jobs::create_table();
		( new ProcessedItems() )->create_table();
		Agents::create_table();
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
}
