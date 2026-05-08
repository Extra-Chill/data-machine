<?php
/**
 * Agent identity resolver tests.
 *
 * @package DataMachine\Tests\Unit\Core\Agents
 */

namespace DataMachine\Tests\Unit\Core\Agents;

use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use WP_UnitTestCase;

class AgentIdentityResolverTest extends WP_UnitTestCase {

	private AgentsRepository $repo;
	private AgentIdentityResolver $resolver;
	private int $owner_id;

	public function set_up(): void {
		parent::set_up();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->repo      = new AgentsRepository();
		$this->resolver  = new AgentIdentityResolver( $this->repo );
		$this->owner_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );

		parent::tear_down();
	}

	private function create_agent( string $slug = 'test-agent', string $name = 'Test Agent' ): int {
		return $this->repo->create_if_missing( $slug, $name, $this->owner_id );
	}

	public function test_resolves_slug_to_id(): void {
		$agent_id = $this->create_agent( 'slug-agent' );

		$this->assertSame( $agent_id, $this->resolver->resolve_agent_id( 'slug-agent' ) );
	}

	public function test_resolves_id_to_slug(): void {
		$agent_id = $this->create_agent( 'id-agent' );

		$this->assertSame( 'id-agent', $this->resolver->resolve_agent_slug( $agent_id ) );
	}

	public function test_resolves_array_context_from_agent_id_only(): void {
		$agent_id = $this->create_agent( 'persisted-id-agent', 'Persisted ID Agent' );

		$identity = $this->resolver->resolve_agent_identity( array( 'agent_id' => $agent_id ) );

		$this->assertSame( $agent_id, $identity->agent_id );
		$this->assertSame( 'persisted-id-agent', $identity->agent_slug );
		$this->assertSame( $this->owner_id, $identity->owner_id );
		$this->assertSame( 'Persisted ID Agent', $identity->agent_name );
	}

	public function test_resolves_array_context_from_agent_slug(): void {
		$agent_id = $this->create_agent( 'array-slug-agent' );

		$identity = $this->resolver->resolve_agent_identity( array( 'agent_slug' => 'array-slug-agent' ) );

		$this->assertSame( $agent_id, $identity->agent_id );
		$this->assertSame( 'array-slug-agent', $identity->agent_slug );
	}

	public function test_normalizes_slug_before_resolution(): void {
		$agent_id = $this->create_agent( 'mixed-case-agent' );

		$this->assertSame( $agent_id, $this->resolver->resolve_agent_id( ' Mixed Case Agent ' ) );
	}

	public function test_rejects_missing_identity(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Agent identity requires agent_id or agent_slug.' );

		$this->resolver->resolve_agent_identity( array( 'owner_id' => $this->owner_id ) );
	}

	public function test_rejects_invalid_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Agent ID 99999 not found.' );

		$this->resolver->resolve_agent_identity( 99999 );
	}

	public function test_rejects_mismatched_array_identity(): void {
		$agent_id = $this->create_agent( 'matching-agent' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Agent identity mismatch' );

		$this->resolver->resolve_agent_identity(
			array(
				'agent_id'   => $agent_id,
				'agent_slug' => 'other-agent',
			)
		);
	}
}
