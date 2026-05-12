<?php
/**
 * Pure-PHP smoke test for the Data Machine Agents API access-store adapter.
 *
 * Run with: php tests/agents-api-access-store-adapter-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/agents-api-smoke-helpers.php';
agents_api_smoke_require_module();

require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Agents/AgentAccess.php';
require_once dirname( __DIR__ ) . '/inc/Core/Auth/AgentAccessStoreAdapter.php';

use DataMachine\Core\Auth\AgentAccessStoreAdapter;
use DataMachine\Core\Database\Agents\AgentAccess;

$GLOBALS['wpdb'] = (object) array(
	'base_prefix' => 'wp_',
	'prefix'      => 'wp_',
);

$failures = array();
$passes   = 0;

class DataMachineAccessStoreAdapterFakeRepository extends AgentAccess {

	/** @var array<string, WP_Agent_Access_Grant> */
	private array $grants = array();

	public function __construct() {}

	public function grant_access( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant {
		$this->grants[ $this->key( $grant->agent_id, $grant->user_id ) ] = $grant;
		return $grant;
	}

	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool {
		unset( $workspace_id );
		$key = $this->key( $agent_id, $user_id );
		if ( ! isset( $this->grants[ $key ] ) ) {
			return false;
		}

		unset( $this->grants[ $key ] );
		return true;
	}

	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?\WP_Agent_Access_Grant {
		unset( $workspace_id );
		return $this->grants[ $this->key( $agent_id, $user_id ) ] ?? null;
	}

	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		unset( $workspace_id );
		$agent_ids = array();

		foreach ( $this->grants as $grant ) {
			if ( $grant->user_id === $user_id && ( null === $minimum_role || $grant->role_meets( $minimum_role ) ) ) {
				$agent_ids[] = (int) $grant->agent_id;
			}
		}

		return $agent_ids;
	}

	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		unset( $workspace_id );
		return array_values(
			array_filter(
				$this->grants,
				static fn( \WP_Agent_Access_Grant $grant ): bool => $grant->agent_id === $agent_id
			)
		);
	}

	private function key( string $agent_id, int $user_id ): string {
		return $agent_id . ':' . $user_id;
	}
}

class DataMachineAccessStoreAdapterExistingStore implements \WP_Agent_Access_Store {
	public function grant_access( \WP_Agent_Access_Grant $grant ): \WP_Agent_Access_Grant { return $grant; }
	public function revoke_access( string $agent_id, int $user_id, ?string $workspace_id = null ): bool { unset( $agent_id, $user_id, $workspace_id ); return true; }
	public function get_access( string $agent_id, int $user_id, ?string $workspace_id = null ): ?\WP_Agent_Access_Grant { unset( $agent_id, $user_id, $workspace_id ); return null; }
	public function get_agent_ids_for_user( int $user_id, ?string $minimum_role = null, ?string $workspace_id = null ): array { unset( $user_id, $minimum_role, $workspace_id ); return array(); }
	public function get_users_for_agent( string $agent_id, ?string $workspace_id = null ): array { unset( $agent_id, $workspace_id ); return array(); }
}

echo "agents-api-access-store-adapter-smoke\n";

$repository = new DataMachineAccessStoreAdapterFakeRepository();
$adapter    = new AgentAccessStoreAdapter( $repository );

agents_api_smoke_assert_equals( true, $adapter instanceof \WP_Agent_Access_Store, 'adapter implements Agents API store contract', $failures, $passes );

$existing = new DataMachineAccessStoreAdapterExistingStore();
agents_api_smoke_assert_equals( $existing, AgentAccessStoreAdapter::filter_access_store( $existing ), 'filter preserves existing host store', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentAccessStoreAdapter::filter_access_store( null ) instanceof AgentAccessStoreAdapter, 'filter supplies Data Machine adapter when empty', $failures, $passes );
agents_api_smoke_assert_equals( AgentAccessStoreAdapter::filter_access_store( null ), AgentAccessStoreAdapter::filter_access_store( null ), 'filter reuses default adapter instance', $failures, $passes );

$grant = new \WP_Agent_Access_Grant( '42', 7, \WP_Agent_Access_Grant::ROLE_OPERATOR );
agents_api_smoke_assert_equals( $grant, $adapter->grant_access( $grant ), 'grant delegates to repository', $failures, $passes );
agents_api_smoke_assert_equals( $grant, $adapter->get_access( '42', 7 ), 'get_access delegates to repository', $failures, $passes );
agents_api_smoke_assert_equals( array( '42' ), $adapter->get_agent_ids_for_user( 7, \WP_Agent_Access_Grant::ROLE_VIEWER ), 'agent ID list is contract string shape', $failures, $passes );
agents_api_smoke_assert_equals( array( $grant ), $adapter->get_users_for_agent( '42' ), 'get_users_for_agent delegates to repository', $failures, $passes );
agents_api_smoke_assert_equals( true, $adapter->revoke_access( '42', 7 ), 'revoke delegates to repository', $failures, $passes );
agents_api_smoke_assert_equals( null, $adapter->get_access( '42', 7 ), 'revoke removes repository grant', $failures, $passes );

agents_api_smoke_finish( 'access-store adapter smoke', $failures, $passes );
