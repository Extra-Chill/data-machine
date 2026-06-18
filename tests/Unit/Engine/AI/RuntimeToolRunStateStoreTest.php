<?php
/**
 * Tests for durable runtime-tool run state storage.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\RuntimeToolRunStateStore;
use PHPUnit\Framework\TestCase;

class RuntimeToolRunStateStoreTest extends TestCase {

	public function test_create_persists_runtime_tool_run_state_fields(): void {
		$jobs  = new RuntimeToolRunStateJobsDouble();
		$store = new RuntimeToolRunStateStore( $jobs );

		$state = $store->create(
			42,
			array(
				'parent_job_id'           => 7,
				'runtime_tool_request_id' => 'runtime_tool_42',
				'tool_name'               => 'client.inspect',
				'timeout_seconds'         => 120,
				'deadline_at'             => '2026-06-18T00:02:00+00:00',
			)
		);

		$this->assertSame( 7, $state['parent_job_id'] );
		$this->assertSame( 'runtime_tool_42', $state['runtime_tool_request_id'] );
		$this->assertSame( 'client.inspect', $state['tool_name'] );
		$this->assertSame( RuntimeToolRunStateStore::STATUS_PENDING, $state['status'] );
		$this->assertSame( 120, $state['timeout_seconds'] );
		$this->assertSame( '2026-06-18T00:02:00+00:00', $state['deadline_at'] );
		$this->assertNull( $state['resume_payload'] );
		$this->assertNull( $state['finalize_payload'] );
		$this->assertArrayHasKey( 'runtime_tool_run_state', $jobs->data[42] );
	}

	public function test_finalize_is_idempotent(): void {
		$jobs  = new RuntimeToolRunStateJobsDouble();
		$store = new RuntimeToolRunStateStore( $jobs );

		$store->create(
			42,
			array(
				'runtime_tool_request_id' => 'runtime_tool_42',
				'tool_name'               => 'client.inspect',
			)
		);

		$first  = $store->finalize( 42, array( 'result' => array( 'value' => 'first' ) ) );
		$second = $store->finalize( 42, array( 'result' => array( 'value' => 'second' ) ) );

		$this->assertSame( $first, $second );
		$this->assertSame( 'first', $second['finalize_payload']['result']['value'] );
		$this->assertSame( RuntimeToolRunStateStore::STATUS_FINALIZED, $second['status'] );
	}

	public function test_resume_is_idempotent(): void {
		$jobs  = new RuntimeToolRunStateJobsDouble();
		$store = new RuntimeToolRunStateStore( $jobs );

		$store->create(
			42,
			array(
				'runtime_tool_request_id' => 'runtime_tool_42',
				'tool_name'               => 'client.inspect',
			)
		);

		$first  = $store->resume( 42, array( 'session_id' => 'session-a' ) );
		$second = $store->resume( 42, array( 'session_id' => 'session-b' ) );

		$this->assertSame( $first, $second );
		$this->assertSame( 'session-a', $second['resume_payload']['session_id'] );
		$this->assertSame( RuntimeToolRunStateStore::STATUS_RESUMED, $second['status'] );
	}

	public function test_finalize_retries_conflicting_engine_data_write(): void {
		$jobs  = new RuntimeToolRunStateJobsDouble();
		$store = new RuntimeToolRunStateStore( $jobs );

		$store->create(
			42,
			array(
				'runtime_tool_request_id' => 'runtime_tool_42',
				'tool_name'               => 'client.inspect',
			)
		);

		$jobs->conflict_once_with = array( 'concurrent_metric' => array( 'count' => 1 ) );
		$jobs->cas_attempts      = 0;

		$state = $store->finalize( 42, array( 'result' => array( 'value' => 'final' ) ) );

		$this->assertSame( RuntimeToolRunStateStore::STATUS_FINALIZED, $state['status'] );
		$this->assertSame( 'final', $state['finalize_payload']['result']['value'] );
		$this->assertSame( 1, $jobs->data[42]['concurrent_metric']['count'] );
		$this->assertSame( 2, $jobs->cas_attempts );
	}

	public function test_create_retries_conflicting_engine_data_write(): void {
		$jobs                         = new RuntimeToolRunStateJobsDouble();
		$jobs->data[42]               = array( 'existing_key' => 'before' );
		$jobs->conflict_once_with     = array( 'concurrent_metric' => array( 'count' => 1 ) );
		$store                        = new RuntimeToolRunStateStore( $jobs );

		$state = $store->create(
			42,
			array(
				'runtime_tool_request_id' => 'runtime_tool_42',
				'tool_name'               => 'client.inspect',
			)
		);

		$this->assertSame( RuntimeToolRunStateStore::STATUS_PENDING, $state['status'] );
		$this->assertSame( 'before', $jobs->data[42]['existing_key'] );
		$this->assertSame( 1, $jobs->data[42]['concurrent_metric']['count'] );
		$this->assertArrayHasKey( 'runtime_tool_run_state', $jobs->data[42] );
		$this->assertSame( 2, $jobs->cas_attempts );
	}

	public function test_create_from_request_maps_timeout_and_deadline_fields(): void {
		$jobs  = new RuntimeToolRunStateJobsDouble();
		$store = new RuntimeToolRunStateStore( $jobs );

		$state = $store->create_from_request(
			array(
				'request_id' => 'runtime_tool_42',
				'tool_name'  => 'client.inspect',
				'timeout_at' => '2026-06-18T00:02:00+00:00',
				'metadata'   => array(
					'datamachine' => array(
						'job_id'          => 42,
						'parent_job_id'   => 7,
						'timeout_seconds' => 120,
					),
				),
			)
		);

		$this->assertSame( 7, $state['parent_job_id'] );
		$this->assertSame( 120, $state['timeout_seconds'] );
		$this->assertSame( '2026-06-18T00:02:00+00:00', $state['deadline_at'] );
	}
}

class RuntimeToolRunStateJobsDouble {

	/** @var array<int,array<string,mixed>> */
	public array $data = array();

	/** @var array<string,mixed>|null */
	public ?array $conflict_once_with = null;

	public int $cas_attempts = 0;

	/**
	 * @param int $job_id Job ID.
	 * @return array<string,mixed>
	 */
	public function retrieve_engine_data( int $job_id ): array {
		return $this->data[ $job_id ] ?? array();
	}

	/**
	 * @param int                 $job_id Job ID.
	 * @param array<string,mixed> $data   Engine data.
	 */
	public function store_engine_data( int $job_id, array $data ): bool {
		$this->data[ $job_id ] = $data;

		return true;
	}

	/**
	 * @param int                 $job_id        Job ID.
	 * @param array<string,mixed> $expected_data Expected engine data.
	 * @param array<string,mixed> $new_data      New engine data.
	 * @return array{updated:bool,conflict:bool,error:string|null}
	 */
	public function compare_and_swap_engine_data( int $job_id, array $expected_data, array $new_data ): array {
		$this->cas_attempts++;
		$current = $this->data[ $job_id ] ?? array();

		if ( null !== $this->conflict_once_with ) {
			$this->data[ $job_id ]      = array_replace_recursive( $current, $this->conflict_once_with );
			$this->conflict_once_with = null;

			return array(
				'updated'  => false,
				'conflict' => true,
				'error'    => null,
			);
		}

		if ( $current !== $expected_data ) {
			return array(
				'updated'  => false,
				'conflict' => true,
				'error'    => null,
			);
		}

		$this->data[ $job_id ] = $new_data;

		return array(
			'updated'  => true,
			'conflict' => false,
			'error'    => null,
		);
	}
}
