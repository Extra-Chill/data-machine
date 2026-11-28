<?php
/**
 * Storage-level onboarding actions, including the direct CLI write path.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Tests\Unit\Core\Database\Agents;

use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Cli\Commands\AgentsCommand;
use WP_UnitTestCase;

class AgentAccessActionsTest extends WP_UnitTestCase {
	private AgentAccess $repo;
	private int $agent_id;
	private int $user_id;
	private array $granted = array();
	private array $revoked = array();

	public function set_up(): void {
		parent::set_up();
		$this->repo = new AgentAccess();
		$this->user_id = self::factory()->user->create();
		$this->agent_id = ( new Agents() )->create_if_missing( 'access-actions', 'Access actions', self::factory()->user->create() );
		add_action( 'datamachine_agent_access_granted', array( $this, 'record_grant' ), 10, 6 );
		add_action( 'datamachine_agent_access_revoked', array( $this, 'record_revoke' ), 10, 4 );
	}

	public function record_grant( ...$args ): void {
		$this->granted[] = $args;
	}

	public function record_revoke( ...$args ): void {
		$this->revoked[] = $args;
	}

	private function grant( string $role = 'viewer' ): void {
		$this->repo->grant_access( new \WP_Agent_Access_Grant( (string) $this->agent_id, $this->user_id, $role ) );
	}

	public function test_user_grant_role_change_and_revoke_only_announce_real_changes(): void {
		$this->grant();
		$this->assertSame( array( array( $this->agent_id, 'user', (string) $this->user_id, 'viewer', null, array( 'agent_slug' => 'access-actions' ) ) ), $this->granted );
		$this->grant();
		$this->assertCount( 1, $this->granted );
		$this->grant( 'operator' );
		$this->assertSame( 'viewer', $this->granted[1][4] );
		$this->assertSame( 'operator', $this->repo->get_access( (string) $this->agent_id, $this->user_id )->role );
		$this->assertTrue( $this->repo->revoke_access( (string) $this->agent_id, $this->user_id ) );
		$this->assertFalse( $this->repo->revoke_access( (string) $this->agent_id, $this->user_id ) );
		$this->assertSame( array( array( $this->agent_id, 'user', (string) $this->user_id, array( 'agent_slug' => 'access-actions' ) ) ), $this->revoked );
	}

	public function test_audience_grants_share_actions_and_normalized_identifiers(): void {
		$id = (string) $this->agent_id;
		$this->repo->grant_principal_access( $id, 'Audience', 'New Members', 'viewer' );
		$this->repo->grant_principal_access( $id, 'audience', 'new-members', 'viewer' );
		$this->repo->grant_principal_access( $id, 'audience', 'new-members', 'operator' );
		$this->assertCount( 2, $this->granted );
		$this->assertSame( array( $this->agent_id, 'audience', 'new-members', 'viewer', null, array( 'agent_slug' => 'access-actions' ) ), $this->granted[0] );
		$this->assertSame( 'viewer', $this->granted[1][4] );
		$this->assertTrue( $this->repo->revoke_principal_access( $id, 'Audience', 'New Members' ) );
		$this->assertFalse( $this->repo->revoke_principal_access( $id, 'audience', 'new-members' ) );
		$this->assertCount( 1, $this->revoked );
		$this->assertSame( 'new-members', $this->revoked[0][2] );
	}

	public function test_failed_writes_do_not_announce_changes(): void {
		global $wpdb;
		$this->grant();
		$this->repo->grant_principal_access( (string) $this->agent_id, 'audience', 'members', 'viewer' );
		$this->granted = array();
		$previous = $wpdb->suppress_errors();
		$fail = static function ( $query ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query ) && str_contains( $query, 'datamachine_agent_' ) ) {
				return 'INVALID SQL FOR ACCESS ACTION TEST';
			}
			return $query;
		};
		add_filter( 'query', $fail );
		try {
			foreach ( array(
				fn() => $this->grant( 'operator' ),
				fn() => $this->repo->grant_access( new \WP_Agent_Access_Grant( (string) $this->agent_id, self::factory()->user->create(), 'viewer' ) ),
				fn() => $this->repo->grant_principal_access( (string) $this->agent_id, 'audience', 'members', 'operator' ),
				fn() => $this->repo->grant_principal_access( (string) $this->agent_id, 'audience', 'new', 'viewer' ),
			) as $write ) {
				try {
					$write();
					$this->fail( 'Expected failed write to throw.' );
				} catch ( \RuntimeException $e ) {
					$this->assertStringContainsString( '_failed', $e->getMessage() );
				}
			}
			$this->assertFalse( $this->repo->revoke_access( (string) $this->agent_id, $this->user_id ) );
			$this->assertFalse( $this->repo->revoke_principal_access( (string) $this->agent_id, 'audience', 'members' ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( array(), $this->granted );
		$this->assertSame( array(), $this->revoked );
	}

	public function test_cli_grant_uses_the_same_storage_action_once(): void {
		if ( ! class_exists( '\WP_CLI_Command' ) ) {
			require_once dirname( __DIR__, 4 ) . '/Doubles/AccessActionsCliCommand.php';
		}
		if ( ! class_exists( '\WP_CLI' ) ) {
			require_once dirname( __DIR__, 4 ) . '/Doubles/AccessActionsCli.php';
		}
		$command = new AgentsCommand();
		$command->access( array( 'grant', 'access-actions', (string) $this->user_id ), array( 'role' => 'viewer' ) );
		$command->access( array( 'grant', 'access-actions', (string) $this->user_id ), array( 'role' => 'viewer' ) );
		$this->assertCount( 1, $this->granted );
		$this->assertSame( null, $this->granted[0][4] );
	}
}
