<?php
/**
 * Engine data persistence limits.
 *
 * @package DataMachine\Tests\Unit\Core\Database
 */

namespace DataMachine\Tests\Unit\Core\Database;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use WP_UnitTestCase;

class EngineDataPersistenceTest extends WP_UnitTestCase {
	private $budget_filter;
	private $log_capture;
	private array $logs = array();
	private array $queries = array();
	private $query_capture;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( function_exists( 'datamachine_activate_for_site' ) ) {
			datamachine_activate_for_site();
		}
	}

	public function set_up(): void {
		parent::set_up();
		$this->budget_filter = static fn(): int => 8192;
		$this->log_capture   = function ( string $level, string $message, array $context = array() ): void {
			$this->logs[] = compact( 'level', 'message', 'context' );
		};
		$this->query_capture = function ( string $query ): string {
			$this->queries[] = $query;
			return $query;
		};
		add_filter( 'datamachine_engine_data_query_budget', $this->budget_filter );
		add_action( 'datamachine_log', $this->log_capture, 10, 3 );
		add_filter( 'query', $this->query_capture );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_engine_data_query_budget', $this->budget_filter );
		remove_action( 'datamachine_log', $this->log_capture, 10 );
		remove_filter( 'query', $this->query_capture );
		parent::tear_down();
	}

	public function test_blind_and_cas_writes_reject_oversize_payloads_before_update(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job( array( 'source' => 'pipeline', 'label' => 'Engine data budget test' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $jobs->store_engine_data( $job_id, array( 'small' => true ) ) );
		$oversize = array( 'secret_payload' => str_repeat( 'do-not-log', 2048 ) );
		$this->queries = array();

		$this->assertFalse( $jobs->store_engine_data( $job_id, $oversize ) );
		$cas = $jobs->compare_and_swap_engine_data( $job_id, array( 'small' => true ), $oversize );

		$this->assertFalse( $cas['updated'] );
		$this->assertFalse( $cas['retryable'] );
		$this->assertSame( 'engine_data_query_oversize', $cas['error'] );
		$this->assertSame( array( 'small' => true ), $jobs->retrieve_engine_data( $job_id ) );
		$engine_updates = array_filter( $this->queries, static fn( string $query ): bool => str_starts_with( ltrim( $query ), 'UPDATE' ) && str_contains( $query, 'engine_data' ) );
		$this->assertSame( array(), array_values( $engine_updates ) );
		$rejections = array_values( array_filter( $this->logs, static fn( array $log ): bool => 'Rejected engine_data write before database query' === $log['message'] ) );
		$this->assertCount( 2, $rejections );
		$this->assertArrayHasKey( 'secret_payload', $rejections[0]['context']['top_level_key_bytes'] );
		$this->assertStringNotContainsString( 'do-not-log', (string) wp_json_encode( $rejections ) );
	}

	public function test_mutation_stops_after_deterministic_oversize_rejection(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job( array( 'source' => 'pipeline', 'label' => 'Mutation budget test' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $jobs->store_engine_data( $job_id, array( 'small' => true ) ) );
		$calls = 0;

		$result = EngineData::mutate(
			$job_id,
			static function ( array $current ) use ( &$calls ): array {
				++$calls;
				$current['large'] = str_repeat( 'x', 8192 );
				return $current;
			},
			'oversize_test',
			5
		);

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 1, $result['attempts'] );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'engine_data_query_oversize', $result['error'] );
	}

	public function test_compare_and_swap_rejects_stale_snapshot_without_losing_concurrent_update(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job( array( 'source' => 'pipeline', 'label' => 'Stale snapshot test' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $jobs->store_engine_data( $job_id, array( 'revision' => 1 ) ) );

		$stale = $jobs->retrieve_engine_data( $job_id );
		$this->assertTrue( $jobs->store_engine_data( $job_id, array( 'revision' => 2, 'concurrent' => true ) ) );

		$result = $jobs->compare_and_swap_engine_data( $job_id, $stale, array( 'revision' => 3 ) );

		$this->assertFalse( $result['updated'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( array( 'revision' => 2, 'concurrent' => true ), $jobs->retrieve_engine_data( $job_id ) );
	}

	public function test_mutation_logs_bounded_context_when_conflicts_are_exhausted(): void {
		$jobs   = new Jobs();
		$job_id = $jobs->create_job( array( 'source' => 'pipeline', 'label' => 'Conflict exhaustion test' ) );
		$this->assertIsInt( $job_id );
		$this->assertTrue( $jobs->store_engine_data( $job_id, array( 'revision' => 0 ) ) );

		$result = EngineData::mutate(
			$job_id,
			static function ( array $current ) use ( $jobs, $job_id ): array {
				$jobs->store_engine_data( $job_id, array( 'revision' => $current['revision'] + 1 ) );
				$current['mutation'] = true;
				return $current;
			},
			'conflict_test',
			2
		);

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertSame( 'conflict_exhausted', $result['error'] );

		$exhausted = array_values( array_filter( $this->logs, static fn( array $log ): bool => 'EngineData mutation exhausted compare-and-swap attempts' === $log['message'] ) );
		$this->assertCount( 1, $exhausted );
		$this->assertSame(
			array(
				'job_id'     => $job_id,
				'event_type' => 'conflict_test',
				'attempts'   => 2,
				'reason'     => 'conflict_exhausted',
			),
			$exhausted[0]['context']
		);
	}

	public function test_runtime_queue_sanitizer_preserves_all_execution_configuration(): void {
		$snapshot = array(
			'flow_config'     => array(
				'static_step' => array(
					'queue_mode'              => 'static',
					'prompt_queue'            => array( array( 'prompt' => 'large payload' ) ),
					'_queue_consume_revision' => 9,
					'handler_configs'         => array( 'fetch' => array( 'limit' => 175 ) ),
				),
				'drain_step'  => array(
					'queue_mode'         => 'drain',
					'config_patch_queue' => array( array( 'patch' => array( 'page' => 2 ) ) ),
					'handler_slugs'      => array( 'fetch' ),
				),
				'loop_step'   => array(
					'queue_mode'   => 'loop',
					'prompt_queue' => array( array( 'prompt' => 'loop payload' ) ),
					'step_type'    => 'ai',
				),
			),
			'pipeline_config' => array( 'ai' => array( 'system_prompt' => 'preserved' ) ),
			'execution_plan'  => array( 'static_step', 'drain_step', 'loop_step' ),
		);

		$sanitized = EngineData::stripFlowRuntimeQueuePayloads( $snapshot );

		foreach ( array( 'static_step', 'drain_step', 'loop_step' ) as $step_id ) {
			$this->assertArrayNotHasKey( 'prompt_queue', $sanitized['flow_config'][ $step_id ] );
			$this->assertArrayNotHasKey( 'config_patch_queue', $sanitized['flow_config'][ $step_id ] );
			$this->assertArrayNotHasKey( '_queue_consume_revision', $sanitized['flow_config'][ $step_id ] );
		}
		$this->assertSame( 'static', $sanitized['flow_config']['static_step']['queue_mode'] );
		$this->assertSame( 'drain', $sanitized['flow_config']['drain_step']['queue_mode'] );
		$this->assertSame( 'loop', $sanitized['flow_config']['loop_step']['queue_mode'] );
		$this->assertSame( $snapshot['pipeline_config'], $sanitized['pipeline_config'] );
		$this->assertSame( $snapshot['execution_plan'], $sanitized['execution_plan'] );
		$this->assertSame( $snapshot['flow_config']['static_step']['handler_configs'], $sanitized['flow_config']['static_step']['handler_configs'] );
	}
}
