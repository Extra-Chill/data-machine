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
require_once dirname( __DIR__ ) . '/inc/Core/Database/Agents/Agents.php';
require_once dirname( __DIR__ ) . '/inc/Core/Auth/AgentAccessStoreAdapter.php';

use DataMachine\Core\Auth\AgentAccessStoreAdapter;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

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

	public function grant_principal_access( string $agent_id, string $principal_type, string $principal_id, string $role ): array {
		$grant                                      = array(
			'agent_id'       => $agent_id,
			'principal_type' => $principal_type,
			'principal_id'   => $principal_id,
			'role'           => $role,
			'workspace_id'   => null,
			'metadata'       => array( 'source' => 'fake' ),
		);
		$this->grants[ $this->principal_key( $agent_id, $principal_type, $principal_id ) ] = $grant;
		return $grant;
	}

	public function revoke_principal_access( string $agent_id, string $principal_type, string $principal_id ): bool {
		$key = $this->principal_key( $agent_id, $principal_type, $principal_id );
		if ( ! isset( $this->grants[ $key ] ) ) {
			return false;
		}

		unset( $this->grants[ $key ] );
		return true;
	}

	public function get_principal_access( string $agent_id, string $principal_type, string $principal_id, ?string $workspace_id = null ): ?array {
		unset( $workspace_id );
		return $this->grants[ $this->principal_key( $agent_id, $principal_type, $principal_id ) ] ?? null;
	}

	public function get_agent_ids_for_principal( string $principal_type, string $principal_id, ?string $minimum_role = null, ?string $workspace_id = null ): array {
		unset( $workspace_id );
		$agent_ids = array();

		foreach ( $this->grants as $grant ) {
			if ( ! is_array( $grant ) ) {
				continue;
			}

			$role_matches = null === $minimum_role || ( new \WP_Agent_Access_Grant( (string) $grant['agent_id'], 1, (string) $grant['role'] ) )->role_meets( $minimum_role );
			if ( $grant['principal_type'] === $principal_type && $grant['principal_id'] === $principal_id && $role_matches ) {
				$agent_ids[] = (int) $grant['agent_id'];
			}
		}

		return $agent_ids;
	}

	public function get_principals_for_agent( string $agent_id, ?string $workspace_id = null ): array {
		unset( $workspace_id );
		return array_values(
			array_filter(
				$this->grants,
				static fn( $grant ): bool => is_array( $grant ) && $grant['agent_id'] === $agent_id
			)
		);
	}

	private function key( string $agent_id, int $user_id ): string {
		return $agent_id . ':' . $user_id;
	}

	private function principal_key( string $agent_id, string $principal_type, string $principal_id ): string {
		return $agent_id . ':' . $principal_type . ':' . $principal_id;
	}
}

class DataMachineAccessStoreAdapterFakeAgentsRepository extends Agents {
	/** @var array<int,array<string,mixed>> */
	private array $rows;

	/**
	 * @param array<int,array<string,mixed>> $rows Agent rows keyed by ID.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get_agent( int $agent_id ): ?array {
		return $this->rows[ $agent_id ] ?? null;
	}

	public function get_by_slug( string $agent_slug ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['agent_slug'] === $agent_slug ) {
				return $row;
			}
		}

		return null;
	}

	public function get_agents_by_ids( array $agent_ids ): array {
		$rows = array();
		foreach ( $agent_ids as $agent_id ) {
			if ( isset( $this->rows[ (int) $agent_id ] ) ) {
				$rows[] = $this->rows[ (int) $agent_id ];
			}
		}

		return $rows;
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
$agents     = new DataMachineAccessStoreAdapterFakeAgentsRepository(
	array(
		42 => array(
			'agent_id'   => 42,
			'agent_slug' => 'wiki-brain',
		),
	)
);
$adapter    = new AgentAccessStoreAdapter( $repository, $agents );

agents_api_smoke_assert_equals( true, $adapter instanceof \WP_Agent_Access_Store, 'adapter implements Agents API store contract', $failures, $passes );
agents_api_smoke_assert_equals( true, $adapter instanceof \WP_Agent_Principal_Access_Store, 'adapter implements Agents API principal store contract', $failures, $passes );

$existing = new DataMachineAccessStoreAdapterExistingStore();
agents_api_smoke_assert_equals( $existing, AgentAccessStoreAdapter::filter_access_store( $existing ), 'filter preserves existing host store', $failures, $passes );
agents_api_smoke_assert_equals( true, AgentAccessStoreAdapter::filter_access_store( null ) instanceof AgentAccessStoreAdapter, 'filter supplies Data Machine adapter when empty', $failures, $passes );
agents_api_smoke_assert_equals( AgentAccessStoreAdapter::filter_access_store( null ), AgentAccessStoreAdapter::filter_access_store( null ), 'filter reuses default adapter instance', $failures, $passes );

$grant          = new \WP_Agent_Access_Grant( 'wiki-brain', 7, \WP_Agent_Access_Grant::ROLE_OPERATOR );
$stored_grant   = new \WP_Agent_Access_Grant( '42', 7, \WP_Agent_Access_Grant::ROLE_OPERATOR );
$returned_grant = $adapter->grant_access( $grant );
agents_api_smoke_assert_equals( $grant->to_array(), $returned_grant->to_array(), 'grant maps slug to storage ID and returns slug contract', $failures, $passes );
agents_api_smoke_assert_equals( $stored_grant->to_array(), $repository->get_access( '42', 7 )->to_array(), 'grant delegates numeric ID to repository', $failures, $passes );
agents_api_smoke_assert_equals( $grant->to_array(), $adapter->get_access( 'wiki-brain', 7 )->to_array(), 'get_access maps slug to storage ID and returns slug contract', $failures, $passes );
agents_api_smoke_assert_equals( array( 'wiki-brain' ), $adapter->get_agent_ids_for_user( 7, \WP_Agent_Access_Grant::ROLE_VIEWER ), 'agent ID list is Agents API slug shape', $failures, $passes );
agents_api_smoke_assert_equals( array( $grant->to_array() ), array_map( static fn( \WP_Agent_Access_Grant $value ): array => $value->to_array(), $adapter->get_users_for_agent( 'wiki-brain' ) ), 'get_users_for_agent returns slug contract', $failures, $passes );

$user_principal = \AgentsAPI\AI\WP_Agent_Execution_Principal::user_session( 7, '__wordpress_user__' );
agents_api_smoke_assert_equals( $grant->to_array(), $adapter->get_access_for_principal( 'wiki-brain', $user_principal )->to_array(), 'principal grant falls back to user access for WordPress user sessions', $failures, $passes );
agents_api_smoke_assert_equals( array( 'wiki-brain' ), $adapter->get_agent_ids_for_principal( $user_principal, \WP_Agent_Access_Grant::ROLE_VIEWER ), 'principal agent IDs fall back to user access for WordPress user sessions', $failures, $passes );

agents_api_smoke_assert_equals( true, $adapter->revoke_access( 'wiki-brain', 7 ), 'revoke maps slug to storage ID', $failures, $passes );
agents_api_smoke_assert_equals( null, $adapter->get_access( 'wiki-brain', 7 ), 'revoke removes repository grant', $failures, $passes );

$principal_grant = array(
	'grant_id'           => null,
	'agent_id'           => 'wiki-brain',
	'user_id'            => 0,
	'role'               => \WP_Agent_Access_Grant::ROLE_OPERATOR,
	'workspace_id'       => null,
	'granted_by_user_id' => null,
	'granted_at'         => null,
	'metadata'           => array( 'source' => 'fake' ),
	'audience_id'        => 'audience:operators',
);
$principal = \AgentsAPI\AI\WP_Agent_Execution_Principal::audience( 'audience:operators', 'frontend-chat' );
$adapter->grant_access_for_principal( 'wiki-brain', 'audience', 'operators', \WP_Agent_Access_Grant::ROLE_OPERATOR );
agents_api_smoke_assert_equals( $principal_grant, $adapter->get_access_for_principal( 'wiki-brain', $principal )->to_array(), 'principal grant resolves audience principal', $failures, $passes );
agents_api_smoke_assert_equals( array( 'wiki-brain' ), $adapter->get_agent_ids_for_principal( $principal, \WP_Agent_Access_Grant::ROLE_VIEWER ), 'principal agent ID list is Agents API slug shape', $failures, $passes );
agents_api_smoke_assert_equals( array( $principal_grant ), $adapter->get_principals_for_agent( 'wiki-brain' ), 'principal grants list returns slug contract', $failures, $passes );
agents_api_smoke_assert_equals( true, $adapter->revoke_access_for_principal( 'wiki-brain', 'audience', 'operators' ), 'principal revoke maps slug to storage ID', $failures, $passes );
agents_api_smoke_assert_equals( null, $adapter->get_access_for_principal( 'wiki-brain', $principal ), 'principal revoke removes grant', $failures, $passes );

agents_api_smoke_finish( 'access-store adapter smoke', $failures, $passes );
