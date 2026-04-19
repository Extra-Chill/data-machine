<?php
/**
 * Tests for MemoryPolicyResolver — per-agent memory file policy.
 *
 * @package DataMachine\Tests\Unit\AI\Memory
 */

namespace DataMachine\Tests\Unit\AI\Memory;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Engine\AI\Memory\MemoryPolicyResolver;
use DataMachine\Engine\AI\MemoryFileRegistry;
use WP_UnitTestCase;

/**
 * @covers \DataMachine\Engine\AI\Memory\MemoryPolicyResolver
 */
class MemoryPolicyResolverTest extends WP_UnitTestCase {

	private MemoryPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Reset the memory file registry before each test so we control
		// exactly which files are registered.
		MemoryFileRegistry::reset();

		// Seed a small, known set of registered files so assertions are
		// stable across test runs. The real core registrations happen in
		// bootstrap code we don't need here.
		MemoryFileRegistry::register( 'SOUL.md', 10, array(
			'layer'    => MemoryFileRegistry::LAYER_AGENT,
			'contexts' => array( 'all' ),
		) );
		MemoryFileRegistry::register( 'MEMORY.md', 20, array(
			'layer'    => MemoryFileRegistry::LAYER_AGENT,
			'contexts' => array( 'all' ),
		) );
		MemoryFileRegistry::register( 'USER.md', 30, array(
			'layer'    => MemoryFileRegistry::LAYER_USER,
			'contexts' => array( 'chat', 'pipeline' ),
		) );
		MemoryFileRegistry::register( 'CHAT_ONLY.md', 40, array(
			'layer'    => MemoryFileRegistry::LAYER_AGENT,
			'contexts' => array( 'chat' ),
		) );

		$this->resolver = new MemoryPolicyResolver();
	}

	public function tear_down(): void {
		MemoryFileRegistry::reset();
		remove_all_filters( 'datamachine_resolved_memory_files' );
		remove_all_filters( 'datamachine_resolved_scoped_memory_files' );
		parent::tear_down();
	}

	// ============================================
	// resolveRegistered() — context filtering (no agent)
	// ============================================

	public function test_registered_chat_context_includes_all_and_chat_files(): void {
		$files = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayHasKey( 'MEMORY.md', $files );
		$this->assertArrayHasKey( 'USER.md', $files );
		$this->assertArrayHasKey( 'CHAT_ONLY.md', $files );
	}

	public function test_registered_pipeline_context_excludes_chat_only(): void {
		$files = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_PIPELINE,
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayHasKey( 'MEMORY.md', $files );
		$this->assertArrayHasKey( 'USER.md', $files );
		$this->assertArrayNotHasKey( 'CHAT_ONLY.md', $files );
	}

	public function test_registered_preserves_metadata(): void {
		$files = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertSame( MemoryFileRegistry::LAYER_AGENT, $files['SOUL.md']['layer'] );
		$this->assertSame( MemoryFileRegistry::LAYER_USER, $files['USER.md']['layer'] );
	}

	// ============================================
	// resolveRegistered() — explicit context deny/allow_only
	// ============================================

	public function test_context_deny_removes_files(): void {
		$files = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
			'deny'    => array( 'MEMORY.md' ),
		) );

		$this->assertArrayNotHasKey( 'MEMORY.md', $files );
		$this->assertArrayHasKey( 'SOUL.md', $files );
	}

	public function test_context_allow_only_narrows_to_subset(): void {
		$files = $this->resolver->resolveRegistered( array(
			'context'    => MemoryPolicyResolver::CONTEXT_CHAT,
			'allow_only' => array( 'SOUL.md' ),
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayNotHasKey( 'MEMORY.md', $files );
		$this->assertCount( 1, $files );
	}

	// ============================================
	// resolveRegistered() — per-agent policy
	// ============================================

	public function test_no_agent_id_means_no_agent_filter(): void {
		$without = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$with_zero = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => 0,
		) );

		$this->assertSame( $without, $with_zero );
	}

	public function test_agent_default_mode_is_noop(): void {
		$agent_id = $this->createAgentWithPolicy( array( 'mode' => 'default' ) );

		$without = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$with = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $without, $with );
	}

	public function test_agent_deny_mode_removes_listed_files(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array( 'MEMORY.md', 'USER.md' ),
		) );

		$files = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayHasKey( 'CHAT_ONLY.md', $files );
		$this->assertArrayNotHasKey( 'MEMORY.md', $files );
		$this->assertArrayNotHasKey( 'USER.md', $files );
	}

	public function test_agent_allow_only_narrows_to_listed_files(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array( 'SOUL.md' ),
		) );

		$files = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayNotHasKey( 'MEMORY.md', $files );
		$this->assertCount( 1, $files );
	}

	public function test_agent_allow_only_empty_returns_nothing(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array(),
		) );

		$files = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( array(), $files );
	}

	public function test_agent_deny_empty_list_is_noop(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array(),
		) );

		$without = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$with = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertSame( $without, $with );
	}

	public function test_agent_allow_only_ignores_unknown_files(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array( 'SOUL.md', 'DOES_NOT_EXIST.md' ),
		) );

		$files = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayNotHasKey( 'DOES_NOT_EXIST.md', $files );
		$this->assertCount( 1, $files );
	}

	// ============================================
	// Precedence: explicit deny wins over agent policy
	// ============================================

	public function test_explicit_deny_wins_over_agent_allow_only(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array( 'SOUL.md', 'MEMORY.md' ),
		) );

		$files = $this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => $agent_id,
			'deny'     => array( 'MEMORY.md' ),
		) );

		$this->assertArrayHasKey( 'SOUL.md', $files );
		$this->assertArrayNotHasKey( 'MEMORY.md', $files );
	}

	// ============================================
	// datamachine_resolved_memory_files filter
	// ============================================

	public function test_resolved_filter_fires_with_context_and_files(): void {
		$captured = null;
		add_filter(
			'datamachine_resolved_memory_files',
			function ( $files, $context_type, $context ) use ( &$captured ) {
				$captured = array(
					'files'        => $files,
					'context_type' => $context_type,
					'context'      => $context,
				);
				return $files;
			},
			10,
			3
		);

		$this->resolver->resolveRegistered( array(
			'context'  => MemoryPolicyResolver::CONTEXT_CHAT,
			'agent_id' => 0,
		) );

		$this->assertNotNull( $captured );
		$this->assertSame( MemoryPolicyResolver::CONTEXT_CHAT, $captured['context_type'] );
		$this->assertArrayHasKey( 'SOUL.md', $captured['files'] );
	}

	public function test_resolved_filter_can_mutate_output(): void {
		add_filter(
			'datamachine_resolved_memory_files',
			function ( $files ) {
				unset( $files['SOUL.md'] );
				return $files;
			}
		);

		$files = $this->resolver->resolveRegistered( array(
			'context' => MemoryPolicyResolver::CONTEXT_CHAT,
		) );

		$this->assertArrayNotHasKey( 'SOUL.md', $files );
		$this->assertArrayHasKey( 'MEMORY.md', $files );
	}

	// ============================================
	// filter() — scoped filename lists (pipeline/flow)
	// ============================================

	public function test_filter_empty_list_is_noop(): void {
		$this->assertSame( array(), $this->resolver->filter( array(), array() ) );
	}

	public function test_filter_no_agent_returns_input_order(): void {
		$out = $this->resolver->filter(
			array( 'a.md', 'b.md', 'c.md' ),
			array()
		);

		$this->assertSame( array( 'a.md', 'b.md', 'c.md' ), $out );
	}

	public function test_filter_agent_deny_removes_files(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array( 'brand-voice.md' ),
		) );

		$out = $this->resolver->filter(
			array( 'brand-voice.md', 'seo-checklist.md' ),
			array( 'agent_id' => $agent_id )
		);

		$this->assertSame( array( 'seo-checklist.md' ), $out );
	}

	public function test_filter_agent_allow_only_narrows_list(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array( 'seo-checklist.md' ),
		) );

		$out = $this->resolver->filter(
			array( 'brand-voice.md', 'seo-checklist.md', 'tone.md' ),
			array( 'agent_id' => $agent_id )
		);

		$this->assertSame( array( 'seo-checklist.md' ), $out );
	}

	public function test_filter_explicit_deny_applies_after_agent_policy(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array( 'brand-voice.md' ),
		) );

		$out = $this->resolver->filter(
			array( 'brand-voice.md', 'seo-checklist.md', 'tone.md' ),
			array(
				'agent_id' => $agent_id,
				'deny'     => array( 'tone.md' ),
			)
		);

		$this->assertSame( array( 'seo-checklist.md' ), $out );
	}

	public function test_scoped_filter_fires_for_filter_method(): void {
		$captured = null;
		add_filter(
			'datamachine_resolved_scoped_memory_files',
			function ( $filenames, $context ) use ( &$captured ) {
				$captured = array(
					'filenames' => $filenames,
					'context'   => $context,
				);
				return $filenames;
			},
			10,
			2
		);

		$this->resolver->filter(
			array( 'a.md' ),
			array( 'scope' => 'pipeline' )
		);

		$this->assertNotNull( $captured );
		$this->assertSame( array( 'a.md' ), $captured['filenames'] );
		$this->assertSame( 'pipeline', $captured['context']['scope'] );
	}

	// ============================================
	// getAgentMemoryPolicy() — validation
	// ============================================

	public function test_get_policy_returns_null_for_invalid_agent_id(): void {
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( 0 ) );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( -1 ) );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( 999999 ) );
	}

	public function test_get_policy_returns_null_for_agent_without_policy(): void {
		$agent_id = $this->createAgentWithPolicy( null );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( $agent_id ) );
	}

	public function test_get_policy_returns_null_for_default_mode(): void {
		$agent_id = $this->createAgentWithPolicy( array( 'mode' => 'default' ) );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( $agent_id ) );
	}

	public function test_get_policy_returns_null_for_invalid_mode(): void {
		$agent_id = $this->createAgentWithPolicy( array( 'mode' => 'nonsense' ) );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( $agent_id ) );
	}

	public function test_get_policy_returns_null_for_deny_with_empty_list(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array(),
		) );
		$this->assertNull( $this->resolver->getAgentMemoryPolicy( $agent_id ) );
	}

	public function test_get_policy_returns_structure_for_valid_deny(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode' => 'deny',
			'deny' => array( 'MEMORY.md' ),
		) );

		$policy = $this->resolver->getAgentMemoryPolicy( $agent_id );

		$this->assertIsArray( $policy );
		$this->assertSame( 'deny', $policy['mode'] );
		$this->assertSame( array( 'MEMORY.md' ), $policy['deny'] );
		$this->assertSame( array(), $policy['allow_only'] );
	}

	public function test_get_policy_returns_structure_for_valid_allow_only(): void {
		$agent_id = $this->createAgentWithPolicy( array(
			'mode'       => 'allow_only',
			'allow_only' => array( 'SOUL.md' ),
		) );

		$policy = $this->resolver->getAgentMemoryPolicy( $agent_id );

		$this->assertIsArray( $policy );
		$this->assertSame( 'allow_only', $policy['mode'] );
		$this->assertSame( array( 'SOUL.md' ), $policy['allow_only'] );
		$this->assertSame( array(), $policy['deny'] );
	}

	// ============================================
	// getContexts()
	// ============================================

	public function test_get_contexts_returns_all_three_presets(): void {
		$contexts = MemoryPolicyResolver::getContexts();

		$this->assertArrayHasKey( MemoryPolicyResolver::CONTEXT_PIPELINE, $contexts );
		$this->assertArrayHasKey( MemoryPolicyResolver::CONTEXT_CHAT, $contexts );
		$this->assertArrayHasKey( MemoryPolicyResolver::CONTEXT_SYSTEM, $contexts );
	}

	// ============================================
	// Helpers
	// ============================================

	/**
	 * Create a test agent with an optional memory policy.
	 *
	 * @param array|null $memory_policy The memory_policy to store, or null for no policy.
	 * @return int Agent ID.
	 */
	private function createAgentWithPolicy( ?array $memory_policy ): int {
		$agents_repo = new Agents();
		$config      = array();

		if ( null !== $memory_policy ) {
			$config['memory_policy'] = $memory_policy;
		}

		$slug = 'test-agent-' . wp_generate_uuid4();

		return $agents_repo->create_if_missing( $slug, 'Test Agent', 1, $config );
	}
}
