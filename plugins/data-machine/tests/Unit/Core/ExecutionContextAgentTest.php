<?php
/**
 * ExecutionContext Agent Identity Tests
 *
 * Tests that agent_id is properly carried through ExecutionContext and EngineData.
 *
 * @package DataMachine\Tests\Unit\Core
 * @since 0.41.0
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\EngineData;
use DataMachine\Core\ExecutionContext;
use WP_UnitTestCase;

class ExecutionContextAgentTest extends WP_UnitTestCase {

	// =========================================================================
	// EngineData::getAgentId()
	// =========================================================================

	/**
	 * Test EngineData returns agent_id from job context.
	 */
	public function test_engine_data_get_agent_id_from_job(): void {
		$engine = new EngineData(
			array(
				'job' => array(
					'job_id'      => 1,
					'flow_id'     => 10,
					'pipeline_id' => 20,
					'agent_id'    => 42,
				),
			),
			1
		);

		$this->assertSame( 42, $engine->getAgentId() );
	}

	/**
	 * Test EngineData returns null when no agent_id in job context.
	 */
	public function test_engine_data_get_agent_id_null_when_absent(): void {
		$engine = new EngineData(
			array(
				'job' => array(
					'job_id'      => 1,
					'flow_id'     => 10,
					'pipeline_id' => 20,
				),
			),
			1
		);

		$this->assertNull( $engine->getAgentId() );
	}

	/**
	 * Test EngineData returns null when no job context at all.
	 */
	public function test_engine_data_get_agent_id_null_when_no_job(): void {
		$engine = new EngineData( array(), null );

		$this->assertNull( $engine->getAgentId() );
	}

	// =========================================================================
	// ExecutionContext::getAgentId() — explicit
	// =========================================================================

	/**
	 * Test fromFlow with explicit agent_id.
	 */
	public function test_from_flow_with_agent_id(): void {
		$ctx = ExecutionContext::fromFlow( 10, 20, 'step_1', '99', 'rss', 42 );

		$this->assertSame( 42, $ctx->getAgentId() );
	}

	/**
	 * Test fromFlow without agent_id defaults to null.
	 */
	public function test_from_flow_without_agent_id(): void {
		$ctx = ExecutionContext::fromFlow( 10, 20, 'step_1', null, 'rss' );

		$this->assertNull( $ctx->getAgentId() );
	}

	/**
	 * Test direct mode with no agent_id.
	 */
	public function test_direct_mode_agent_id_null(): void {
		$ctx = ExecutionContext::direct( 'test' );

		$this->assertNull( $ctx->getAgentId() );
	}

	/**
	 * Test standalone mode with no agent_id.
	 */
	public function test_standalone_mode_agent_id_null(): void {
		$ctx = ExecutionContext::standalone( null, 'test' );

		$this->assertNull( $ctx->getAgentId() );
	}

	// =========================================================================
	// ExecutionContext::fromConfig() — agent_id extraction
	// =========================================================================

	/**
	 * Test fromConfig extracts agent_id from config array.
	 */
	public function test_from_config_extracts_agent_id(): void {
		$ctx = ExecutionContext::fromConfig(
			array(
				'pipeline_id'  => 10,
				'flow_id'      => 20,
				'flow_step_id' => 'step_1',
				'agent_id'     => 42,
			),
			'99',
			'rss'
		);

		$this->assertTrue( $ctx->isFlow() );
		$this->assertSame( 42, $ctx->getAgentId() );
	}

	/**
	 * Test fromConfig without agent_id returns null.
	 */
	public function test_from_config_without_agent_id(): void {
		$ctx = ExecutionContext::fromConfig(
			array(
				'pipeline_id'  => 10,
				'flow_id'      => 20,
				'flow_step_id' => 'step_1',
			),
			'99'
		);

		$this->assertNull( $ctx->getAgentId() );
	}

	/**
	 * Test fromConfig in direct mode carries agent_id.
	 */
	public function test_from_config_direct_mode_with_agent_id(): void {
		$ctx = ExecutionContext::fromConfig(
			array(
				'pipeline_id' => 'direct',
				'flow_id'     => 'direct',
				'agent_id'    => 7,
			),
			null
		);

		$this->assertTrue( $ctx->isDirect() );
		$this->assertSame( 7, $ctx->getAgentId() );
	}

	// =========================================================================
	// Immutable modifiers preserve agent_id
	// =========================================================================

	/**
	 * Test withHandlerType preserves agent_id.
	 */
	public function test_with_handler_type_preserves_agent_id(): void {
		$ctx = ExecutionContext::fromFlow( 10, 20, 'step_1', '99', 'rss', 42 );
		$new = $ctx->withHandlerType( 'files' );

		$this->assertSame( 42, $new->getAgentId() );
		$this->assertSame( 'files', $new->getHandlerType() );
	}

	/**
	 * Test withJobId preserves agent_id.
	 */
	public function test_with_job_id_preserves_agent_id(): void {
		$ctx = ExecutionContext::fromFlow( 10, 20, 'step_1', null, 'rss', 42 );
		$new = $ctx->withJobId( '55' );

		$this->assertSame( 42, $new->getAgentId() );
		$this->assertSame( '55', $new->getJobId() );
	}
}
