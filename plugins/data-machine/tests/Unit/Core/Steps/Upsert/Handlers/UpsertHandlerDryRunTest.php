<?php
/**
 * UpsertHandler dry-run mode tests.
 *
 * Regression coverage for Extra-Chill/data-machine#3566: ExecuteWorkflowAbility
 * sets engine_data['dry_run_mode'] = true for dry-run workflows, and
 * PublishHandler::handle_tool_call() already short-circuits to a preview
 * response in that case, but UpsertHandler::handle_tool_call() did not — every
 * upsert subclass wrote real content during dry runs.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Upsert\Handlers
 */

namespace DataMachine\Tests\Unit\Core\Steps\Upsert\Handlers;

use DataMachine\Core\Steps\Upsert\Handlers\UpsertHandler;
use WP_UnitTestCase;

/**
 * Stub upsert handler subclass that records executeUpsert() invocations so
 * tests can assert whether it ran.
 */
class StubUpsertHandler extends UpsertHandler {

	/** @var array<int,array{parameters:array,handler_config:array}> Recorded executeUpsert() calls. */
	public array $calls = array();

	/** @var array Result to return from executeUpsert() when it is called. */
	public array $result_to_return = array(
		'success' => true,
		'post_id' => 42,
	);

	protected function executeUpsert( array $parameters, array $handler_config ): array {
		$this->calls[] = array(
			'parameters'     => $parameters,
			'handler_config' => $handler_config,
		);

		return $this->result_to_return;
	}
}

class UpsertHandlerDryRunTest extends WP_UnitTestCase {

	public function test_dry_run_mode_short_circuits_before_executeUpsert(): void {
		$job_id = 424242;
		datamachine_set_engine_data( $job_id, array( 'dry_run_mode' => true ) );

		$handler = new StubUpsertHandler();

		$result = $handler->handle_tool_call(
			array(
				'job_id' => $job_id,
				'title'  => 'Should never be written',
			),
			array(
				'handler'        => 'stub_upsert',
				'handler_config' => array( 'foo' => 'bar' ),
			)
		);

		$this->assertSame( array(), $handler->calls, 'executeUpsert() must not run during a dry run.' );
		$this->assertTrue( $result['success'] ?? false );
		$this->assertTrue( $result['dry_run'] ?? false );
		$this->assertSame( StubUpsertHandler::class, $result['preview']['handler'] ?? null );
		$this->assertSame(
			array( 'job_id', 'title' ),
			$result['preview']['parameters'] ?? null,
			'Preview must list the original tool parameter names, not job_id/engine added for subclasses.'
		);
		$this->assertSame( StubUpsertHandler::class, $result['tool_name'] ?? null );
	}

	public function test_non_dry_run_mode_calls_executeUpsert_exactly_once(): void {
		$job_id = 424243;
		datamachine_set_engine_data( $job_id, array() );

		$handler = new StubUpsertHandler();

		$result = $handler->handle_tool_call(
			array(
				'job_id' => $job_id,
				'title'  => 'Real content',
			),
			array(
				'handler'        => 'stub_upsert',
				'handler_config' => array( 'foo' => 'bar' ),
			)
		);

		$this->assertCount( 1, $handler->calls, 'executeUpsert() must run exactly once outside of a dry run.' );
		$this->assertTrue( $result['success'] ?? false );
		$this->assertArrayNotHasKey( 'dry_run', $result );
		$this->assertSame( 42, $result['post_id'] ?? null );
	}

	public function test_missing_dry_run_mode_key_is_treated_as_not_dry_run(): void {
		$job_id = 424244;
		datamachine_set_engine_data( $job_id, array( 'dry_run_mode' => false ) );

		$handler = new StubUpsertHandler();

		$handler->handle_tool_call(
			array( 'job_id' => $job_id ),
			array( 'handler' => 'stub_upsert' )
		);

		$this->assertCount( 1, $handler->calls, 'An explicit falsy dry_run_mode must still execute the upsert.' );
	}
}
