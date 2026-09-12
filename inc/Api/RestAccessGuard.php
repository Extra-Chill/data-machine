<?php
/**
 * REST access guard for Data Machine resources.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\ExecutionScope;
use DataMachine\Abilities\PermissionHelper;
use WP_Error;
use WP_REST_Request;

/**
 * Small REST-facing wrapper around existing Data Machine permission semantics.
 */
class RestAccessGuard {

	/**
	 * Current explicit execution scope.
	 *
	 * @var ExecutionScope
	 */
	private ExecutionScope $scope;

	/**
	 * Create a guard for an action key.
	 *
	 * @param string $action Action key for capability/resource checks.
	 * @return self
	 */
	public static function for_action( string $action = 'manage_flows' ): self {
		return new self( ExecutionScope::current( $action ) );
	}

	/**
	 * Constructor.
	 *
	 * @param ExecutionScope $scope Explicit execution scope.
	 */
	public function __construct( ExecutionScope $scope ) {
		$this->scope = $scope;
	}

	/**
	 * Get the explicit execution scope used by this guard.
	 *
	 * @return ExecutionScope
	 */
	public function scope(): ExecutionScope {
		return $this->scope;
	}

	/**
	 * Check the guard action for a REST permission callback.
	 *
	 * @param string $message Error message when denied.
	 * @return true|WP_Error
	 */
	public function check_permission( string $message ) {
		if ( $this->scope->can_action() ) {
			return true;
		}

		return $this->forbidden( $message );
	}

	/**
	 * Get the acting user ID from the explicit scope.
	 *
	 * @return int
	 */
	public function acting_user_id(): int {
		return $this->scope->acting_user_id();
	}

	/**
	 * Resolve scoped user ID using the guard action.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return int|null
	 */
	public function resolve_scoped_user_id( WP_REST_Request $request ): ?int {
		return PermissionHelper::resolve_scoped_user_id( $request, $this->scope->action() );
	}

	/**
	 * Resolve scoped agent ID using the guard action.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return int|null
	 */
	public function resolve_scoped_agent_id( WP_REST_Request $request ): ?int {
		return PermissionHelper::resolve_scoped_agent_id( $request, $this->scope->action() );
	}

	/**
	 * Check access to an agent-scoped resource.
	 *
	 * @param int|null $resource_agent_id Agent ID on the resource record.
	 * @param int      $resource_user_id  User ID on the resource record.
	 * @param string   $message           Error message when denied.
	 * @return true|WP_Error
	 */
	public function authorize_agent_resource( ?int $resource_agent_id, int $resource_user_id, string $message ) {
		if ( $this->scope->owns_agent_resource( $resource_agent_id, $resource_user_id ) ) {
			return true;
		}

		return $this->forbidden( $message );
	}

	/**
	 * Build a standard REST forbidden error.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function forbidden( string $message ): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			$message,
			array( 'status' => 403 )
		);
	}
}
