<?php
/**
 * MemoryFileRegistry unit tests — regression coverage for issue #2005.
 *
 * The bootstrap-fatal scenario (Agents API substrate missing) is exercised
 * by the pure-PHP smoke at
 * `tests/memory-file-registry-missing-agents-api-smoke.php`, which loads
 * MemoryFileRegistry without `automattic/agents-api` and asserts every
 * code path the playground bootstrap can hit. That smoke is the
 * substrate-missing test; this file is the substrate-loaded regression test,
 * proving the refactor of `register()` did not change production behavior.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\MemoryFileRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Engine\AI\MemoryFileRegistry
 */
class MemoryFileRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		MemoryFileRegistry::reset();
	}

	protected function tearDown(): void {
		MemoryFileRegistry::reset();
		parent::tearDown();
	}

	/**
	 * register() must populate get_all() with the canonical metadata shape
	 * regardless of whether the Agents API substrate is loaded.
	 */
	public function test_register_round_trips_through_get_all(): void {
		MemoryFileRegistry::register( 'SITE.md', 10, array(
			'layer'      => MemoryFileRegistry::LAYER_SHARED,
			'protected'  => true,
			'composable' => true,
			'modes'      => array( MemoryFileRegistry::MODE_ALL ),
			'label'      => 'Site Context',
		) );

		$all = MemoryFileRegistry::get_all();
		$this->assertArrayHasKey( 'SITE.md', $all );
		$this->assertSame( 'shared', $all['SITE.md']['layer'] );
		$this->assertSame( 'workspace_shared', $all['SITE.md']['authority_tier'] );
		$this->assertSame( 'always', $all['SITE.md']['retrieval_policy'] );
		$this->assertTrue( $all['SITE.md']['protected'] );
		$this->assertTrue( $all['SITE.md']['composable'] );
		$this->assertFalse( $all['SITE.md']['editable'] ); // composable forces editable=false
	}

	/**
	 * Unknown layers must normalize to LAYER_AGENT. This is the contract
	 * `WP_Agent_Memory_Layer::normalize()` provides when the substrate is
	 * loaded, and the local fallback when it isn't.
	 */
	public function test_unknown_layer_normalizes_to_agent_default(): void {
		MemoryFileRegistry::register( 'WEIRD.md', 50, array( 'layer' => 'not-a-real-layer' ) );

		$file = MemoryFileRegistry::get( 'WEIRD.md' );
		$this->assertNotNull( $file );
		$this->assertSame( MemoryFileRegistry::LAYER_AGENT, $file['layer'] );
	}

	/**
	 * Files without explicit modes must default to retrieval_policy=never,
	 * meaning they're registered but not auto-injected into prompts.
	 */
	public function test_no_modes_defaults_to_never_retrieval_policy(): void {
		MemoryFileRegistry::register( 'QUIET.md', 50, array() );

		$file = MemoryFileRegistry::get( 'QUIET.md' );
		$this->assertSame( 'never', $file['retrieval_policy'] );
	}

	/**
	 * Authority tier defaults must match the
	 * `WP_Agent_Context_Authority_Tier` vocabulary — string literals
	 * `workspace_shared`, `user_global`, `agent_identity`, `agent_memory`.
	 */
	public function test_default_authority_tier_uses_canonical_vocabulary(): void {
		MemoryFileRegistry::register( 'SITE.md',    10, array( 'layer' => MemoryFileRegistry::LAYER_SHARED ) );
		MemoryFileRegistry::register( 'NETWORK.md', 10, array( 'layer' => MemoryFileRegistry::LAYER_NETWORK ) );
		MemoryFileRegistry::register( 'USER.md',    10, array( 'layer' => MemoryFileRegistry::LAYER_USER ) );
		MemoryFileRegistry::register( 'SOUL.md',    10, array( 'layer' => MemoryFileRegistry::LAYER_AGENT ) );
		MemoryFileRegistry::register( 'MEMORY.md',  10, array( 'layer' => MemoryFileRegistry::LAYER_AGENT ) );

		$all = MemoryFileRegistry::get_all();
		$this->assertSame( 'workspace_shared', $all['SITE.md']['authority_tier'] );
		$this->assertSame( 'workspace_shared', $all['NETWORK.md']['authority_tier'] );
		$this->assertSame( 'user_global',      $all['USER.md']['authority_tier'] );
		$this->assertSame( 'agent_identity',   $all['SOUL.md']['authority_tier'] );
		$this->assertSame( 'agent_memory',     $all['MEMORY.md']['authority_tier'] );
	}

	/**
	 * deregister() removes the entry from get_all().
	 */
	public function test_deregister_removes_entry(): void {
		MemoryFileRegistry::register( 'TEMP.md', 50, array() );
		$this->assertArrayHasKey( 'TEMP.md', MemoryFileRegistry::get_all() );

		MemoryFileRegistry::deregister( 'TEMP.md' );
		$this->assertArrayNotHasKey( 'TEMP.md', MemoryFileRegistry::get_all() );
	}

	/**
	 * get_for_mode() only returns files whose modes match the requested mode
	 * AND whose retrieval_policy is `always`.
	 */
	public function test_get_for_mode_filters_by_mode_and_policy(): void {
		MemoryFileRegistry::register( 'CHAT_ONLY.md', 10, array(
			'layer' => MemoryFileRegistry::LAYER_AGENT,
			'modes' => array( 'chat' ),
		) );
		MemoryFileRegistry::register( 'PIPELINE_ONLY.md', 20, array(
			'layer' => MemoryFileRegistry::LAYER_AGENT,
			'modes' => array( 'pipeline' ),
		) );
		MemoryFileRegistry::register( 'ALL_MODES.md', 30, array(
			'layer' => MemoryFileRegistry::LAYER_AGENT,
			'modes' => array( MemoryFileRegistry::MODE_ALL ),
		) );
		MemoryFileRegistry::register( 'NO_MODES.md', 40, array(
			'layer' => MemoryFileRegistry::LAYER_AGENT,
		) );

		$chat_files = MemoryFileRegistry::get_for_mode( 'chat' );
		$this->assertArrayHasKey( 'CHAT_ONLY.md', $chat_files );
		$this->assertArrayHasKey( 'ALL_MODES.md', $chat_files );
		$this->assertArrayNotHasKey( 'PIPELINE_ONLY.md', $chat_files );
		// NO_MODES.md is registered with retrieval_policy=never, so it must
		// never appear in mode-filtered injection lists.
		$this->assertArrayNotHasKey( 'NO_MODES.md', $chat_files );
	}
}
