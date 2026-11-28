<?php
/**
 * Explicit execution-scope snapshot for Data Machine permission checks.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use AgentsAPI\AI\WP_Agent_Execution_Principal;

/**
 * Immutable view of the current Data Machine execution scope.
 *
 * This intentionally delegates authorization decisions to PermissionHelper so
 * existing CLI, scheduler, token, and user-session semantics remain unchanged.
 */
class ExecutionScope {

	/**
	 * Action key used for capability checks.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Whether the scope can perform the configured action.
	 *
	 * @var bool
	 */
	private bool $can_action;

	/**
	 * Acting WordPress user ID.
	 *
	 * @var int
	 */
	private int $acting_user_id;

	/**
	 * Acting agent ID, when authenticated as an agent.
	 *
	 * @var int|null
	 */
	private ?int $acting_agent_id;

	/**
	 * Acting token ID, when authenticated with an agent token.
	 *
	 * @var int|null
	 */
	private ?int $acting_token_id;

	/**
	 * Agents API execution principal, when available.
	 *
	 * @var WP_Agent_Execution_Principal|null
	 */
	private ?WP_Agent_Execution_Principal $principal;

	/**
	 * Whether this scope is pre-authenticated.
	 *
	 * @var bool
	 */
	private bool $authenticated_context;

	/**
	 * Whether this scope is authenticated as an agent.
	 *
	 * @var bool
	 */
	private bool $agent_context;

	/**
	 * Create an explicit snapshot of the current permission context.
	 *
	 * @param string $action Action key for capability checks.
	 * @return self
	 */
	public static function current( string $action = 'manage_flows' ): self {
		return new self(
			$action,
			PermissionHelper::can( $action ),
			PermissionHelper::acting_user_id(),
			PermissionHelper::get_acting_agent_id(),
			PermissionHelper::get_acting_token_id(),
			PermissionHelper::get_execution_principal(),
			PermissionHelper::is_authenticated_context(),
			PermissionHelper::in_agent_context()
		);
	}

	/**
	 * Constructor.
	 *
	 * @param string                            $action                Action key.
	 * @param bool                              $can_action            Whether action is allowed.
	 * @param int                               $acting_user_id        Acting user ID.
	 * @param int|null                          $acting_agent_id       Acting agent ID.
	 * @param int|null                          $acting_token_id       Acting token ID.
	 * @param WP_Agent_Execution_Principal|null $principal             Execution principal.
	 * @param bool                              $authenticated_context Pre-authenticated context flag.
	 * @param bool                              $agent_context         Agent context flag.
	 */
	private function __construct( string $action, bool $can_action, int $acting_user_id, ?int $acting_agent_id, ?int $acting_token_id, ?WP_Agent_Execution_Principal $principal, bool $authenticated_context, bool $agent_context ) {
		$this->action                = $action;
		$this->can_action            = $can_action;
		$this->acting_user_id        = $acting_user_id;
		$this->acting_agent_id       = $acting_agent_id;
		$this->acting_token_id       = $acting_token_id;
		$this->principal             = $principal;
		$this->authenticated_context = $authenticated_context;
		$this->agent_context         = $agent_context;
	}

	/**
	 * Get the scope action key.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Whether the scope can perform the configured action.
	 *
	 * @return bool
	 */
	public function can_action(): bool {
		return $this->can_action;
	}

	/**
	 * Get the acting WordPress user ID.
	 *
	 * @return int
	 */
	public function acting_user_id(): int {
		return $this->acting_user_id;
	}

	/**
	 * Get the acting agent ID.
	 *
	 * @return int|null
	 */
	public function acting_agent_id(): ?int {
		return $this->acting_agent_id;
	}

	/**
	 * Get the acting token ID.
	 *
	 * @return int|null
	 */
	public function acting_token_id(): ?int {
		return $this->acting_token_id;
	}

	/**
	 * Get the Agents API execution principal.
	 *
	 * @return WP_Agent_Execution_Principal|null
	 */
	public function principal(): ?WP_Agent_Execution_Principal {
		return $this->principal;
	}

	/**
	 * Whether the scope is pre-authenticated.
	 *
	 * @return bool
	 */
	public function is_authenticated_context(): bool {
		return $this->authenticated_context;
	}

	/**
	 * Whether the scope is authenticated as an agent.
	 *
	 * @return bool
	 */
	public function is_agent_context(): bool {
		return $this->agent_context;
	}

	/**
	 * Check access to an agent-scoped resource under existing PermissionHelper policy.
	 *
	 * @param int|null $resource_agent_id Agent ID on the resource record.
	 * @param int      $resource_user_id  User ID on the resource record.
	 * @return bool
	 */
	public function owns_agent_resource( ?int $resource_agent_id, int $resource_user_id ): bool {
		return PermissionHelper::owns_agent_resource( $resource_agent_id, $resource_user_id, $this->action );
	}
}
