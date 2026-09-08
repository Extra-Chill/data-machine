<?php
/**
 * Agent Access Abilities
 *
 * WordPress 6.9 Abilities API surface for agent user-access management:
 * list, grant, and revoke per-user access grants on an agent. Owns the
 * behavior formerly exposed by the datamachine/v1 /agents/{agent}/access
 * wrapper routes (see #3456).
 *
 * @package DataMachine\Abilities
 * @since 0.177.0
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

class AgentAccessAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerManageAgentAccess();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerManageAgentAccess(): void {
		wp_register_ability(
			'datamachine/manage-agent-access',
			array(
				'label'               => __( 'Manage Agent Access', 'data-machine' ),
				'description'         => __( 'List, grant, or revoke per-user access grants on an agent. Use action=list, action=grant (with user_id and role), or action=revoke (with user_id).', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'action', 'agent_id' ),
					'properties' => array(
						'action'   => array(
							'type'        => 'string',
							'enum'        => array( 'list', 'grant', 'revoke' ),
							'description' => __( 'Which access operation to perform.', 'data-machine' ),
						),
						'agent_id' => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID to manage access on.', 'data-machine' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for grant/revoke actions.', 'data-machine' ),
						),
						'role'     => array(
							'type'        => 'string',
							'enum'        => array( 'admin', 'operator', 'viewer' ),
							'description' => __( 'Access role for grant actions. Defaults to viewer.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'agent_id'     => array( 'type' => 'integer' ),
						'user_id'      => array( 'type' => 'integer' ),
						'role'         => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'grants'       => array( 'type' => 'array' ),
						'revoked'      => array( 'type' => 'boolean' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeManageAgentAccess' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'manage_agents' );
	}

	/**
	 * List, grant, or revoke agent user-access grants.
	 *
	 * @param array $input Input with action, agent_id, and user_id/role for grant/revoke.
	 * @return array|\WP_Error Result payload for the requested action.
	 */
	public function executeManageAgentAccess( array $input ): array|\WP_Error {
		$action   = (string) ( $input['action'] ?? '' );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );

		if ( ! in_array( $action, array( 'list', 'grant', 'revoke' ), true ) ) {
			return new \WP_Error(
				'invalid_agent_access_action',
				__( 'action is required and must be "list", "grant", or "revoke".', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		if ( $agent_id <= 0 ) {
			return new \WP_Error(
				'invalid_agent_id',
				__( 'agent_id is required.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return new \WP_Error(
				'agent_not_found',
				__( 'Agent not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		if ( 'list' === $action ) {
			return $this->listAccess( $agent_id );
		}

		$user_id = (int) ( $input['user_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'invalid_agent_access_user',
				__( 'user_id is required for grant and revoke actions.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		return 'grant' === $action ? $this->grantAccess( $agent_id, $user_id, $input ) : $this->revokeAccess( $agent_id, $agent, $user_id );
	}

	/**
	 * List an agent's user-access grants, enriched with user display data.
	 *
	 * @param int $agent_id Agent ID.
	 * @return array Result with grants array.
	 */
	private function listAccess( int $agent_id ): array {
		$access_repo = new AgentAccess();
		$grants      = $access_repo->get_users_for_agent( (string) $agent_id );

		$data = array();
		foreach ( $grants as $grant ) {
			$user   = get_user_by( 'id', $grant->user_id );
			$data[] = array(
				'user_id'      => (int) $grant->user_id,
				'display_name' => $user ? $user->display_name : __( '(unknown user)', 'data-machine' ),
				'user_email'   => $user ? $user->user_email : '',
				'role'         => $grant->role,
				'granted_at'   => $grant->granted_at ?? '',
			);
		}

		return array(
			'success'  => true,
			'agent_id' => $agent_id,
			'grants'   => $data,
		);
	}

	/**
	 * Grant a user access to an agent.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param int   $user_id  WordPress user ID.
	 * @param array $input    Full ability input (reads role).
	 * @return array|\WP_Error Result with the granted access summary.
	 */
	private function grantAccess( int $agent_id, int $user_id, array $input ): array|\WP_Error {
		$role = (string) ( $input['role'] ?? 'viewer' );

		if ( ! in_array( $role, array( 'admin', 'operator', 'viewer' ), true ) ) {
			return new \WP_Error(
				'invalid_agent_access_role',
				__( 'role must be one of: admin, operator, viewer.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'user_not_found',
				__( 'User not found.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		$access_repo = new AgentAccess();
		try {
			$access_repo->grant_access( new \WP_Agent_Access_Grant( (string) $agent_id, $user_id, $role ) );
			$ok = true;
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( ! $ok ) {
			return new \WP_Error(
				'grant_failed',
				__( 'Failed to grant access.', 'data-machine' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'      => true,
			'agent_id'     => $agent_id,
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'role'         => $role,
		);
	}

	/**
	 * Revoke a user's access to an agent.
	 *
	 * @param int   $agent_id Agent ID.
	 * @param array $agent    Agent row.
	 * @param int   $user_id  WordPress user ID.
	 * @return array|\WP_Error Result with the revoked summary.
	 */
	private function revokeAccess( int $agent_id, array $agent, int $user_id ): array|\WP_Error {
		if ( $user_id === (int) $agent['owner_id'] ) {
			return new \WP_Error(
				'cannot_revoke_owner',
				__( 'Cannot revoke the owner\'s access. Transfer ownership first.', 'data-machine' ),
				array( 'status' => 400 )
			);
		}

		$access_repo = new AgentAccess();
		$ok          = $access_repo->revoke_access( (string) $agent_id, $user_id );

		if ( ! $ok ) {
			return new \WP_Error(
				'revoke_failed',
				__( 'No access grant found for this user.', 'data-machine' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'success'  => true,
			'agent_id' => $agent_id,
			'user_id'  => $user_id,
			'revoked'  => true,
		);
	}
}
