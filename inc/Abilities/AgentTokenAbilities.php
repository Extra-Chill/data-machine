<?php
/**
 * Agent Token Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent token management.
 * Create, revoke, and list bearer tokens for agent runtime authentication.
 *
 * @package DataMachine\Abilities
 * @since 0.47.0
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentTokens;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class AgentTokenAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerCreateToken();
			$this->registerRevokeToken();
			$this->registerListTokens();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerCreateToken(): void {
		wp_register_ability(
			'datamachine/create-agent-token',
			array(
				'label'               => __( 'Create Agent Token', 'data-machine' ),
				'description'         => __( 'Create a bearer token for agent runtime authentication. The raw token is only returned once.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'agent_id' ),
					'properties' => array(
						'agent_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID to create token for', 'data-machine' ),
						),
						'label'        => array(
							'type'        => 'string',
							'description' => __( 'Human-readable label (e.g., "kimaki-prod", "ci-pipeline")', 'data-machine' ),
						),
						'capabilities' => array(
							'type'        => 'array',
							'description' => __( 'Allowed capabilities subset (null = all agent capabilities)', 'data-machine' ),
						),
						'expires_in'   => array(
							'type'        => 'integer',
							'description' => __( 'Token expiry in seconds from now (null = never)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'token_id'     => array( 'type' => 'integer' ),
						'raw_token'    => array( 'type' => 'string' ),
						'token_prefix' => array( 'type' => 'string' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeCreateToken' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRevokeToken(): void {
		wp_register_ability(
			'datamachine/revoke-agent-token',
			array(
				'label'               => __( 'Revoke Agent Token', 'data-machine' ),
				'description'         => __( 'Revoke (delete) an agent bearer token. The token will immediately stop working.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'agent_id', 'token_id' ),
					'properties' => array(
						'agent_id' => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID that owns the token', 'data-machine' ),
						),
						'token_id' => array(
							'type'        => 'integer',
							'description' => __( 'Token ID to revoke', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeRevokeToken' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerListTokens(): void {
		wp_register_ability(
			'datamachine/list-agent-tokens',
			array(
				'label'               => __( 'List Agent Tokens', 'data-machine' ),
				'description'         => __( 'List all tokens for an agent. Returns metadata only (never the token value).', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'agent_id' ),
					'properties' => array(
						'agent_id' => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID to list tokens for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'tokens'  => array( 'type' => 'array' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListTokens' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'manage_agents' );
	}

	/**
	 * Create a bearer token for an agent.
	 *
	 * @param array $input Input with agent_id, optional label, capabilities, expires_in.
	 * @return array Result with raw_token (only returned once).
	 */
	public function executeCreateToken( array $input ): array {
		$agent_id     = intval( $input['agent_id'] ?? 0 );
		$label        = sanitize_text_field( $input['label'] ?? '' );
		$capabilities = $input['capabilities'] ?? null;
		$expires_in   = $input['expires_in'] ?? null;

		if ( $agent_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => __( 'agent_id is required', 'data-machine' ),
			);
		}

		// Verify agent exists and is active.
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => __( 'Agent not found', 'data-machine' ),
			);
		}

		if ( 'active' !== ( $agent['status'] ?? '' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Agent is not active', 'data-machine' ),
			);
		}

		// Check access — caller must have admin access to the agent.
		if ( ! PermissionHelper::can_access_agent( $agent_id, 'admin' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'You do not have admin access to this agent', 'data-machine' ),
			);
		}

		// Calculate expiry.
		$expires_at = null;
		if ( null !== $expires_in && $expires_in > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + intval( $expires_in ) );
		}

		$tokens_repo = new AgentTokens();
		$result      = $tokens_repo->create_token(
			$agent_id,
			$agent['agent_slug'],
			$label,
			$capabilities,
			$expires_at
		);

		if ( ! $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to create token', 'data-machine' ),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent token created',
			array(
				'agent_id'            => $agent_id,
				'agent_slug'          => $agent['agent_slug'],
				'token_id'            => $result['token_id'],
				'label'               => $label,
				'has_expiry'          => null !== $expires_at,
				'has_cap_restriction' => null !== $capabilities,
			)
		);

		return array(
			'success'      => true,
			'token_id'     => $result['token_id'],
			'raw_token'    => $result['raw_token'],
			'token_prefix' => $result['token_prefix'],
			'message'      => __( 'Token created. Save it now — it cannot be retrieved again.', 'data-machine' ),
		);
	}

	/**
	 * Revoke an agent token.
	 *
	 * @param array $input Input with agent_id and token_id.
	 * @return array Result.
	 */
	public function executeRevokeToken( array $input ): array {
		$agent_id = intval( $input['agent_id'] ?? 0 );
		$token_id = intval( $input['token_id'] ?? 0 );

		if ( $agent_id <= 0 || $token_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => __( 'agent_id and token_id are required', 'data-machine' ),
			);
		}

		if ( ! PermissionHelper::can_access_agent( $agent_id, 'admin' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'You do not have admin access to this agent', 'data-machine' ),
			);
		}

		$tokens_repo = new AgentTokens();
		$revoked     = $tokens_repo->revoke_token( $token_id, $agent_id );

		if ( ! $revoked ) {
			return array(
				'success' => false,
				'error'   => __( 'Token not found or does not belong to this agent', 'data-machine' ),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent token revoked',
			array(
				'agent_id' => $agent_id,
				'token_id' => $token_id,
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Token revoked successfully', 'data-machine' ),
		);
	}

	/**
	 * List tokens for an agent.
	 *
	 * @param array $input Input with agent_id.
	 * @return array Result with tokens array.
	 */
	public function executeListTokens( array $input ): array {
		$agent_id = intval( $input['agent_id'] ?? 0 );

		if ( $agent_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => __( 'agent_id is required', 'data-machine' ),
			);
		}

		if ( ! PermissionHelper::can_access_agent( $agent_id, 'operator' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'You do not have access to this agent', 'data-machine' ),
			);
		}

		$tokens_repo = new AgentTokens();
		$tokens      = $tokens_repo->list_tokens( $agent_id );

		return array(
			'success' => true,
			'tokens'  => $tokens,
		);
	}
}
