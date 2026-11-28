<?php
namespace DataMachineEvents\Tests\Contracts;

use DataMachineEvents\Core\SourceHealth;
use PHPUnit\Framework\TestCase;

/** Execute the aggregate queries against actual tables, without WordPress. */
final class SourceHealthQueryTest extends TestCase {
	public function test_report_joins_root_telemetry_posts_and_latest_exception(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite required' );
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new class {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public \PDO $db;
			public int $queries = 0;
			public function __construct() {
				$this->db = new \PDO( 'sqlite::memory:' );
				$this->db->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
				$this->db->sqliteCreateFunction( 'NOW', static fn() => '2026-10-31 00:00:00' );
				$this->db->sqliteCreateFunction( 'JSON_UNQUOTE', static fn( $value ) => $value );
			}
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%d', '%d', $sql ), $args ); }
			public function get_results( $sql, $mode ): array {
				++$this->queries;
				return $this->db->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
			}
		};
		$GLOBALS['wpdb'] = $db;
		try {
			foreach ( array(
				'CREATE TABLE wp_datamachine_flows(flow_id INTEGER, flow_name TEXT, pipeline_id INTEGER, flow_config TEXT)',
				'CREATE TABLE wp_datamachine_pipelines(pipeline_id INTEGER, pipeline_name TEXT)',
				'CREATE TABLE wp_posts(ID INTEGER, post_date TEXT, post_status TEXT, post_type TEXT)',
				'CREATE TABLE wp_postmeta(post_id INTEGER, meta_key TEXT, meta_value TEXT)',
				'CREATE TABLE wp_datamachine_event_dates(post_id INTEGER, start_datetime TEXT, post_status TEXT)',
				'CREATE TABLE wp_datamachine_jobs(job_id INTEGER, flow_id INTEGER, parent_job_id INTEGER, status TEXT, status_reason TEXT, engine_data TEXT)',
				'CREATE TABLE wp_datamachine_logs(id INTEGER, context TEXT)',
				"INSERT INTO wp_datamachine_pipelines VALUES(1, 'Test City')",
			) as $sql ) {
				$db->db->exec( $sql );
			}
			$config = json_encode( array( array( 'enabled' => true, 'step_type' => 'event_import', 'handler_slugs' => array( 'universal_web_scraper' ), 'handler_configs' => array( 'universal_web_scraper' => array( 'source_url' => 'https://venue.invalid/events' ) ) ) ) );
			$stmt = $db->db->prepare( 'INSERT INTO wp_datamachine_flows VALUES(?, ?, 1, ?)' );
			$stmt->execute( array( 1, 'Blocked Hall', $config ) );
			$stmt->execute( array( 2, 'Quiet Hall', $config ) );
			$engine = json_encode( array( 'step_results' => array( array( 'fetch_stages' => array( 'source_materialized' => 1 ) ) ), 'run_metrics' => array( 'counts' => array( 'source_rejected' => 1 ) ), 'source_rejection' => array( 'reason' => 'missing event date' ) ) );
			$stmt = $db->db->prepare( 'INSERT INTO wp_datamachine_jobs VALUES(?, ?, ?, ?, NULL, ?)' );
			$stmt->execute( array( 10, 1, null, 'failed', '{}' ) );
			$stmt->execute( array( 11, 2, null, 'completed_no_items', $engine ) );
			$stmt->execute( array( 12, 2, 11, 'completed_no_items', '{}' ) );
			$db->db->exec( "INSERT INTO wp_posts VALUES(1, '2020-01-01', 'publish', 'data_machine_events'); INSERT INTO wp_postmeta VALUES(1, '_datamachine_post_flow_id', '2'); INSERT INTO wp_datamachine_event_dates VALUES(1, '2099-01-01', 'publish')" );
			$stmt = $db->db->prepare( 'INSERT INTO wp_datamachine_logs VALUES(?, ?)' );
			$stmt->execute( array( 1, json_encode( array( 'job_id' => 10, 'flow_id' => 1, 'exception' => 'HTTP 404' ) ) ) );
			$stmt->execute( array( 2, json_encode( array( 'job_id' => 10, 'flow_id' => 1, 'exception' => 'HTTP 403' ) ) ) );
			$result = ( new SourceHealth() )->report( 1 );
			$this->assertSame( 6, $db->queries );
			$this->assertSame( array( 'blocked', 'extraction_incomplete' ), array_column( $result['flows'], 'status' ) );
			$this->assertSame( 'https://venue.invalid/events', $result['flows'][0]['source_url'] );
			$this->assertSame( 1, (int) $result['flows'][1]['total'] ); // Child must not inflate totals.
			$this->assertSame( 1, (int) $result['flows'][1]['upcoming_count'] );
			$this->assertSame( '2020-01-01', $result['flows'][1]['last_published'] );
			$this->assertSame( 'missing event date', $result['flows'][1]['rejection_reason'] );
			$this->assertSame( array(), ( new SourceHealth() )->report( 99 )['flows'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
}
