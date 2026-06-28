<?php
/**
 * WP_Agent_WordPress_Authorization_Policy implementation.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_WordPress_Authorization_Policy' ) ) {
	/**
	 * Default authorization policy that composes token ceilings with WordPress caps.
	 */
	final class WP_Agent_WordPress_Authorization_Policy implements WP_Agent_Authorization_Policy {

		/**
		 * @param WP_Agent_Access_Store|null $access_store     Optional access-grant store.
		 * @param callable|null                       $user_can_callback Optional user capability checker for tests/hosts.
		 */
		public function __construct(
			private readonly ?WP_Agent_Access_Store $access_store = null,
			private $user_can_callback = null,
		) {}

		/**
		 * Check whether a principal can use a WordPress capability.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal  Execution principal.
		 * @param string                               $capability Required WordPress capability.
		 * @param array<string,mixed>                  $context    Host-owned authorization context.
		 */
		public function can( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $capability, array $context = array() ): bool {
			$capability = trim( $capability );
			if ( '' === $capability ) {
				return false;
			}

			$ceiling = $context['capability_ceiling'] ?? $principal->capability_ceiling;
			if ( is_array( $ceiling ) ) {
				$ceiling = WP_Agent_Capability_Ceiling::from_array( $this->string_keyed_array( $ceiling ) );
			}

			if ( $ceiling instanceof WP_Agent_Capability_Ceiling && ! $ceiling->allows_capability( $capability ) ) {
				return false;
			}

			$user_id = $ceiling instanceof WP_Agent_Capability_Ceiling ? $ceiling->user_id : $principal->acting_user_id;
			if ( $user_id <= 0 ) {
				return false;
			}

			return $this->user_can( $user_id, $capability );
		}

		/**
		 * Check whether a principal can access an agent at a minimum role.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                               $agent_id     Agent identifier.
		 * @param string                               $minimum_role Minimum access role.
		 * @param array<string,mixed>                  $context      Host-owned authorization context.
		 */
		public function can_access_agent( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool {
			if ( '' === trim( $agent_id ) || ! WP_Agent_Access_Grant::is_valid_role( $minimum_role ) ) {
				return false;
			}

			if ( $principal->effective_agent_id === $agent_id ) {
				return true;
			}

			$access_store = $context['access_store'] ?? $this->access_store;
			if ( ! $access_store instanceof WP_Agent_Access_Store ) {
				return false;
			}

			if ( $principal->acting_user_id > 0 ) {
				$grant = $access_store->get_access( $agent_id, $principal->acting_user_id, $principal->workspace_id );
				if ( $grant instanceof WP_Agent_Access_Grant && $grant->role_meets( $minimum_role ) ) {
					return true;
				}
			}

			if ( $access_store instanceof WP_Agent_Principal_Access_Store ) {
				foreach ( WP_Agent_Access::access_principals_for( $principal, $context ) as $access_principal ) {
					$grant = $access_store->get_access_for_principal( $agent_id, $access_principal, $principal->workspace_id );
					if ( $grant instanceof WP_Agent_Access_Grant && $grant->role_meets( $minimum_role ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Run the configured WordPress capability check.
		 */
		private function user_can( int $user_id, string $capability ): bool {
			if ( null !== $this->user_can_callback ) {
				return (bool) call_user_func( $this->user_can_callback, $user_id, $capability );
			}

			return function_exists( 'user_can' ) && user_can( $user_id, $capability );
		}

		/**
		 * @param array<mixed,mixed> $value Raw array.
		 * @return array<string,mixed>
		 */
		private function string_keyed_array( array $value ): array {
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$result[ $key ] = $item;
				}
			}

			return $result;
		}
	}
}
