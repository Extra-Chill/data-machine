<?php
/**
 * WP_Agent_Access helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Access' ) ) {
	/**
	 * Host-store discovery and current-principal access helpers.
	 */
	final class WP_Agent_Access {

		private const CURRENT_USER_EFFECTIVE_AGENT_ID = '__wordpress_user__';
		private const PUBLIC_AUDIENCE_ID              = 'audience:public';

		/**
		 * Resolve the host-provided access store.
		 *
		 * @param array<string,mixed> $context Host-owned request context.
		 */
		public static function get_store( array $context = array() ): ?WP_Agent_Access_Store {
			if ( isset( $context['access_store'] ) && $context['access_store'] instanceof WP_Agent_Access_Store ) {
				return $context['access_store'];
			}

			$store = function_exists( 'apply_filters' ) ? apply_filters( 'wp_agent_access_store', null, $context ) : null;
			return $store instanceof WP_Agent_Access_Store ? $store : null;
		}

		/**
		 * Resolve the principal for the current request.
		 *
		 * @param array<string,mixed> $context Host-owned request context.
		 */
		public static function get_current_principal( array $context = array() ): ?AgentsAPI\AI\WP_Agent_Execution_Principal {
			if ( isset( $context['principal'] ) && $context['principal'] instanceof AgentsAPI\AI\WP_Agent_Execution_Principal ) {
				return $context['principal'];
			}

			$principal = AgentsAPI\AI\WP_Agent_Execution_Principal::resolve( $context );
			if ( null !== $principal ) {
				return $principal;
			}

			$user_id = self::get_current_user_id();
			if ( $user_id <= 0 ) {
				if ( array_key_exists( 'allow_anonymous_audience', $context ) && false === (bool) $context['allow_anonymous_audience'] ) {
					return null;
				}

				return AgentsAPI\AI\WP_Agent_Execution_Principal::audience(
					self::PUBLIC_AUDIENCE_ID,
					self::PUBLIC_AUDIENCE_ID,
					self::string_field( $context, 'request_context', AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST ),
					self::array_field( $context, 'request_metadata' ),
					self::nullable_string_field( $context, 'workspace_id' ),
					self::nullable_string_field( $context, 'client_id' )
				);
			}

			return AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
				$user_id,
				self::CURRENT_USER_EFFECTIVE_AGENT_ID,
				self::string_field( $context, 'request_context', AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST ),
				self::array_field( $context, 'request_metadata' ),
				self::nullable_string_field( $context, 'workspace_id' ),
				self::nullable_string_field( $context, 'client_id' )
			);
		}

		/**
		 * Check whether the current request principal can access an agent.
		 *
		 * @param string              $agent_id     Registered agent slug/id.
		 * @param string              $minimum_role Minimum access role.
		 * @param array<string,mixed> $context      Host-owned request context.
		 */
		public static function can_current_principal_access_agent( string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool {
			$principal = self::get_current_principal( $context );
			if ( null === $principal ) {
				return false;
			}

			return self::can_principal_access_agent( $principal, $agent_id, $minimum_role, $context );
		}

		/**
		 * Check whether a principal can access an agent.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                                    $agent_id     Registered agent slug/id.
		 * @param string                                    $minimum_role Minimum access role.
		 * @param array<string,mixed>                       $context      Host-owned request context.
		 */
		public static function can_principal_access_agent( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $agent_id, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): bool {
			$store  = self::get_store( $context );
			$policy = new WP_Agent_WordPress_Authorization_Policy( $store );

			return $policy->can_access_agent( $principal, $agent_id, $minimum_role, $context );
		}

		/**
		 * List registered agents accessible to the current request principal.
		 *
		 * @param string              $minimum_role Minimum access role.
		 * @param array<string,mixed> $context      Host-owned request context.
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_accessible_agents_for_current_principal( string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): array {
			$principal = self::get_current_principal( $context );
			if ( null === $principal ) {
				return array();
			}

			return self::list_accessible_agents_for_principal( $principal, $minimum_role, $context );
		}

		/**
		 * List registered agents accessible to a principal.
		 *
		 * Iterates every registered agent through the same
		 * {@see WP_Agent_WordPress_Authorization_Policy::can_access_agent()}
		 * decision used by the check path. This guarantees that
		 * `agents/list-accessible-agents` and `agents/can-access-agent` can
		 * never disagree: the list is literally the set of agents for which
		 * the check returns true.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal    Execution principal.
		 * @param string                                    $minimum_role Minimum access role.
		 * @param array<string,mixed>                       $context      Host-owned request context.
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_accessible_agents_for_principal( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, string $minimum_role = WP_Agent_Access_Grant::ROLE_VIEWER, array $context = array() ): array {
			if ( ! WP_Agent_Access_Grant::is_valid_role( $minimum_role ) ) {
				return array();
			}

			$store  = self::get_store( $context );
			$policy = new WP_Agent_WordPress_Authorization_Policy( $store );

			$agents = array();
			$seen   = array();

			$registered = function_exists( 'wp_get_agents' ) ? wp_get_agents() : array();
			foreach ( $registered as $agent ) {
				$slug = sanitize_title( $agent->get_slug() );
				if ( '' === $slug || isset( $seen[ $slug ] ) ) {
					continue;
				}

				$seen[ $slug ] = true;

				if ( ! $policy->can_access_agent( $principal, $slug, $minimum_role, $context ) ) {
					continue;
				}

				$agents[] = self::agent_to_access_summary( $agent );
			}

			return $agents;
		}

		/**
		 * Export a registered agent summary for access-listing clients.
		 *
		 * @return array<string,mixed>
		 */
		private static function agent_to_access_summary( WP_Agent $agent ): array {
			return array(
				'slug'        => $agent->get_slug(),
				'label'       => $agent->get_label(),
				'description' => $agent->get_description(),
				'meta'        => $agent->get_meta(),
			);
		}

		/**
		 * Expand a request principal into the principal grants that apply to it.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal Request principal.
		 * @param array<string,mixed>                       $context   Host-owned request context.
		 * @return AgentsAPI\AI\WP_Agent_Execution_Principal[]
		 */
		public static function access_principals_for( AgentsAPI\AI\WP_Agent_Execution_Principal $principal, array $context = array() ): array {
			$principals = array( $principal );
			$audiences  = array();

			if ( null !== $principal->audience_id ) {
				$audiences[] = $principal->audience_id;
			}

			if ( ! array_key_exists( 'include_public_audience', $context ) || false !== (bool) $context['include_public_audience'] ) {
				$audiences[] = self::PUBLIC_AUDIENCE_ID;
			}

			/**
			 * Filter audience grants that apply to a request principal.
			 *
			 * @param string[]                                  $audiences Audience IDs such as audience:public.
			 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal Request principal.
			 * @param array<string,mixed>                       $context   Host-owned request context.
			 */
			/** @var mixed $filtered_audiences */
			$filtered_audiences = function_exists( 'apply_filters' ) ? apply_filters( 'agents_api_access_audiences_for_principal', $audiences, $principal, $context ) : $audiences;
			$audiences          = is_array( $filtered_audiences ) ? $filtered_audiences : array();

			$audience_ids = array();
			foreach ( $audiences as $audience ) {
				if ( is_scalar( $audience ) ) {
					$audience_ids[] = (string) $audience;
				}
			}

			foreach ( array_values( array_unique( array_filter( $audience_ids ) ) ) as $audience_id ) {
				$principals[] = AgentsAPI\AI\WP_Agent_Execution_Principal::audience(
					$audience_id,
					$principal->effective_agent_id,
					$principal->request_context,
					$principal->request_metadata,
					$principal->workspace_id,
					$principal->client_id,
					$principal->audience_claims
				);
			}

			return $principals;
		}

		/**
		 * Return the current WordPress user ID when WordPress is loaded.
		 */
		private static function get_current_user_id(): int {
			if ( function_exists( 'get_current_user_id' ) ) {
				return (int) get_current_user_id();
			}

			return 0;
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 */
		private static function string_field( array $source, string $key, string $fallback = '' ): string {
			$value = $source[ $key ] ?? null;
			return is_scalar( $value ) ? (string) $value : $fallback;
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 */
		private static function nullable_string_field( array $source, string $key ): ?string {
			if ( ! array_key_exists( $key, $source ) || null === $source[ $key ] ) {
				return null;
			}

			return self::string_field( $source, $key );
		}

		/**
		 * @param array<string,mixed> $source Raw source array.
		 * @return array<string,mixed>
		 */
		private static function array_field( array $source, string $key ): array {
			$value = $source[ $key ] ?? array();
			if ( ! is_array( $value ) ) {
				return array();
			}

			$result = array();
			foreach ( $value as $item_key => $item ) {
				if ( is_string( $item_key ) ) {
					$result[ $item_key ] = $item;
				}
			}

			return $result;
		}
	}
}
