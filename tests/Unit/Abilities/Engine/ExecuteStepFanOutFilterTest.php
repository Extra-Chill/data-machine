<?php
/**
 * Tests for ExecuteStepAbility::filterPacketsForFanOut().
 *
 * Regression coverage for issue #1096 — the pre-fix implementation filtered
 * on metadata.source_type instead of the top-level 'type' key, which was a
 * silent no-op. Every packet (including tool_result and ai_response) was
 * fanned out into doomed child jobs that silently completed with status
 * 'completed_no_items' — producing 8,030 orphaned jobs across 7 days on
 * events.extrachill.com.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Core\DataPacket;
use WP_UnitTestCase;

class ExecuteStepFanOutFilterTest extends WP_UnitTestCase {

	/**
	 * Build a packet using the real DataPacket class to guarantee the
	 * structure matches what the engine actually produces.
	 */
	private function make_packet( string $type, array $metadata = array() ): array {
		$dp     = new DataPacket(
			array( 'title' => 'Test', 'body' => 'Test body' ),
			$metadata,
			$type
		);
		$result = $dp->addTo( array() );

		return $result[0];
	}

	public function test_filter_keeps_only_ai_handler_complete_packets(): void {
		$packets = array(
			$this->make_packet(
				'ai_handler_complete',
				array( 'handler_tool' => 'upsert_event', 'source_type' => 'ticketmaster' )
			),
			$this->make_packet(
				'tool_result',
				array( 'tool_name' => 'daily_memory', 'source_type' => 'ticketmaster' )
			),
			$this->make_packet(
				'ai_response',
				array( 'source_type' => 'ticketmaster' )
			),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 1, $filtered );
		$this->assertSame( 'ai_handler_complete', $filtered[0]['type'] );
	}

	/**
	 * When the AI calls different handler tools, each gets its own child job.
	 * Multi-handler pipelines (e.g. upsert_event + publish_post) need this.
	 */
	public function test_filter_preserves_packets_with_different_tool_names(): void {
		$packets = array(
			$this->make_packet( 'ai_handler_complete', array( 'tool_name' => 'upsert_event', 'handler_tool' => 'upsert_event' ) ),
			$this->make_packet( 'ai_handler_complete', array( 'tool_name' => 'publish_post', 'handler_tool' => 'publish_post' ) ),
			$this->make_packet( 'tool_result', array( 'tool_name' => 'search' ) ),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 2, $filtered );
		$this->assertSame( 'upsert_event', $filtered[0]['metadata']['tool_name'] );
		$this->assertSame( 'publish_post', $filtered[1]['metadata']['tool_name'] );
	}

	/**
	 * Regression test for #1108: when the AI calls the same handler tool
	 * multiple times (e.g. conversation loop didn't terminate), duplicate
	 * ai_handler_complete packets must be collapsed to one per tool_name.
	 *
	 * @see https://github.com/Extra-Chill/data-machine/issues/1108
	 */
	public function test_filter_deduplicates_same_tool_name_handler_packets(): void {
		$packets = array(
			$this->make_packet( 'ai_handler_complete', array( 'tool_name' => 'upsert_event', 'handler_tool' => 'upsert_event' ) ),
			$this->make_packet( 'ai_handler_complete', array( 'tool_name' => 'upsert_event', 'handler_tool' => 'upsert_event' ) ),
			$this->make_packet( 'ai_handler_complete', array( 'tool_name' => 'upsert_event', 'handler_tool' => 'upsert_event' ) ),
			$this->make_packet( 'tool_result', array( 'tool_name' => 'search' ) ),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 1, $filtered, 'Duplicate handler tool calls should be collapsed to one per tool_name' );
		$this->assertSame( 'ai_handler_complete', $filtered[0]['type'] );
		$this->assertSame( 'upsert_event', $filtered[0]['metadata']['tool_name'] );
	}

	/**
	 * Handler packets without a tool_name should be kept unconditionally
	 * for backward compatibility.
	 */
	public function test_filter_keeps_handler_packets_without_tool_name(): void {
		$packets = array(
			$this->make_packet( 'ai_handler_complete', array( 'handler_tool' => 'upsert_event' ) ),
			$this->make_packet( 'ai_handler_complete', array( 'handler_tool' => 'upsert_event' ) ),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 2, $filtered, 'Packets without tool_name cannot be deduped and should pass through' );
	}

	/**
	 * Regression guard: the pre-#1096 implementation filtered on
	 * metadata.source_type. The original input source_type is 'ticketmaster',
	 * 'web_scraper', etc. — NEVER 'ai_handler_complete'. If the filter
	 * accidentally reverts to checking metadata.source_type, this test will
	 * fail because tool_result and ai_response packets will leak through.
	 */
	public function test_filter_does_not_confuse_metadata_source_type_with_packet_type(): void {
		$packets = array(
			$this->make_packet(
				'ai_handler_complete',
				array( 'handler_tool' => 'upsert_event', 'source_type' => 'ticketmaster' )
			),
			// Craft a malicious-looking tool_result whose metadata.source_type
			// is literally 'ai_handler_complete' — must still be filtered out.
			$this->make_packet(
				'tool_result',
				array(
					'tool_name'   => 'daily_memory',
					'source_type' => 'ai_handler_complete', // would fool the old filter
				)
			),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 1, $filtered, 'Filter must key on top-level type, not metadata.source_type.' );
		$this->assertSame( 'ai_handler_complete', $filtered[0]['type'] );
		$this->assertSame( 'upsert_event', $filtered[0]['metadata']['handler_tool'] );
	}

	public function test_filter_returns_originals_when_nothing_matches(): void {
		// Backward-compat: steps that don't emit handler packets (e.g. pure
		// tool_result producers) should still fan out their originals.
		$packets = array(
			$this->make_packet( 'tool_result', array( 'tool_name' => 'search' ) ),
			$this->make_packet( 'ai_response', array( 'source_type' => 'custom' ) ),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 2, $filtered );
	}

	public function test_filter_handles_empty_array(): void {
		$this->assertSame( array(), ExecuteStepAbility::filterPacketsForFanOut( array() ) );
	}

	public function test_filter_handles_packets_without_type_key(): void {
		$packets = array(
			array( 'metadata' => array(), 'data' => array() ),
			$this->make_packet( 'ai_handler_complete', array() ),
		);

		$filtered = ExecuteStepAbility::filterPacketsForFanOut( $packets );

		$this->assertCount( 1, $filtered );
		$this->assertSame( 'ai_handler_complete', $filtered[0]['type'] );
	}
}
