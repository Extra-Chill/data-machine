<?php
/**
 * Agent registration helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_agent' ) ) {
	/**
	 * Register an agent definition.
	 *
	 * Call from inside a `wp_agents_api_init` action callback. The registry
	 * collects definitions without deciding whether they should be materialized.
	 *
	 * @param string|WP_Agent $agent Agent slug or definition object.
	 * @param array<mixed>           $args  Registration arguments. Use `meta.source_plugin`,
	 *                               `meta.source_type`, `meta.source_package`, and
	 *                               `meta.source_version` to declare source provenance.
	 * @return WP_Agent|null Registered agent, or null on invalid arguments.
	 */
	function wp_register_agent( $agent, array $args = array() ): ?WP_Agent {
		$slug = $agent instanceof WP_Agent ? $agent->get_slug() : (string) $agent;
		if ( ! doing_action( 'wp_agents_api_init' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					'Agents must be registered on the %1$s action. The agent %2$s was not registered.',
					'<code>wp_agents_api_init</code>',
					'<code>' . esc_html( $slug ) . '</code>'
				),
				'0.102.8'
			);
			return null;
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->register( $agent, $args );
	}
}

if ( ! function_exists( 'wp_get_agent' ) ) {
	/**
	 * Retrieves a registered agent object.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Registered agent, or null when not registered.
	 */
	function wp_get_agent( string $slug ): ?WP_Agent {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->get_registered( $slug );
	}
}

if ( ! function_exists( 'wp_get_agents' ) ) {
	/**
	 * Retrieves all registered agent objects.
	 *
	 * @return array<string, WP_Agent>
	 */
	function wp_get_agents(): array {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		return $registry->get_all_registered();
	}
}

if ( ! function_exists( 'wp_has_agent' ) ) {
	/**
	 * Checks whether an agent is registered.
	 *
	 * @param string $slug Agent slug.
	 * @return bool
	 */
	function wp_has_agent( string $slug ): bool {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return false;
		}

		return $registry->is_registered( $slug );
	}
}

if ( ! function_exists( 'wp_unregister_agent' ) ) {
	/**
	 * Unregisters an agent definition.
	 *
	 * @param string $slug Agent slug.
	 * @return WP_Agent|null Removed agent, or null when not registered.
	 */
	function wp_unregister_agent( string $slug ): ?WP_Agent {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->unregister( $slug );
	}
}

if ( ! function_exists( 'wp_get_agent_identity_store' ) ) {
	/**
	 * Resolves the host-provided materialized identity store.
	 *
	 * Hosts can pass a store with `$context['identity_store']` or provide one
	 * through the `wp_agent_identity_store` filter. Agents API does not choose a
	 * concrete storage backend.
	 *
	 * @param array<string,mixed> $context Host-owned request context.
	 * @return \AgentsAPI\Core\Identity\WP_Agent_Identity_Store|null
	 */
	function wp_get_agent_identity_store( array $context = array() ): ?\AgentsAPI\Core\Identity\WP_Agent_Identity_Store {
		return \AgentsAPI\Core\Identity\WP_Agent_Identity_Stores::get_store( $context );
	}
}

if ( ! function_exists( 'wp_materialize_agent_identity' ) ) {
	/**
	 * Materializes a registered agent definition through a host identity store.
	 *
	 * The identity scope is derived from the registered agent slug, owner user ID,
	 * and instance key. Callers may pass `owner_user_id` and `instance_key` in
	 * `$args`; otherwise owner defaults to the agent's resolver when present, then
	 * `0`, and instance defaults to `default`.
	 *
	 * @param string|WP_Agent                                             $agent Agent slug or definition object.
	 * @param \AgentsAPI\Core\Identity\WP_Agent_Identity_Store|null      $store Store, or null to use the resolver.
	 * @param array<string,mixed>                                         $args  Host-owned materialization options and context.
	 * @return \AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity|null Materialized identity, or null without a registered agent/store.
	 */
	function wp_materialize_agent_identity( $agent, ?\AgentsAPI\Core\Identity\WP_Agent_Identity_Store $store = null, array $args = array() ): ?\AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity {
		$agent = $agent instanceof WP_Agent ? $agent : wp_get_agent( (string) $agent );
		if ( ! $agent instanceof WP_Agent ) {
			return null;
		}

		$store = $store instanceof \AgentsAPI\Core\Identity\WP_Agent_Identity_Store ? $store : wp_get_agent_identity_store( $args );
		if ( ! $store instanceof \AgentsAPI\Core\Identity\WP_Agent_Identity_Store ) {
			return null;
		}

		$owner_user_id = wp_resolve_agent_identity_owner_user_id( $agent, $args );
		$instance_key  = isset( $args['instance_key'] ) && is_scalar( $args['instance_key'] ) ? (string) $args['instance_key'] : 'default';
		$scope         = new \AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( $agent->get_slug(), $owner_user_id, $instance_key );
		$meta          = $agent->get_meta();
		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$meta[ $key ] = $value;
				}
			}
		}

		return $store->materialize( $scope, $agent->get_default_config(), $meta );
	}
}

if ( ! function_exists( 'wp_materialize_registered_agent_identities' ) ) {
	/**
	 * Materializes all currently registered agents through a host identity store.
	 *
	 * @param \AgentsAPI\Core\Identity\WP_Agent_Identity_Store|null $store Store, or null to use the resolver.
	 * @param array<string,mixed>                                    $args  Host-owned materialization options and context.
	 * @return array<string,\AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity> Identities keyed by registered agent slug.
	 */
	function wp_materialize_registered_agent_identities( ?\AgentsAPI\Core\Identity\WP_Agent_Identity_Store $store = null, array $args = array() ): array {
		$store = $store instanceof \AgentsAPI\Core\Identity\WP_Agent_Identity_Store ? $store : wp_get_agent_identity_store( $args );
		if ( ! $store instanceof \AgentsAPI\Core\Identity\WP_Agent_Identity_Store ) {
			return array();
		}

		$identities = array();
		foreach ( wp_get_agents() as $agent ) {
			$identity = wp_materialize_agent_identity( $agent, $store, $args );
			if ( $identity instanceof \AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity ) {
				$identities[ $agent->get_slug() ] = $identity;
			}
		}

		return $identities;
	}
}

if ( ! function_exists( 'wp_resolve_agent_identity_owner_user_id' ) ) {
	/**
	 * Resolves the owner user ID for generic identity materialization.
	 *
	 * @param WP_Agent            $agent Registered agent definition.
	 * @param array<string,mixed> $args  Materialization options.
	 * @return int Non-negative owner user ID. Zero means shared/no owner.
	 */
	function wp_resolve_agent_identity_owner_user_id( WP_Agent $agent, array $args = array() ): int {
		if ( isset( $args['owner_user_id'] ) && is_numeric( $args['owner_user_id'] ) ) {
			return max( 0, (int) $args['owner_user_id'] );
		}

		$owner_resolver = $agent->get_owner_resolver();
		if ( is_callable( $owner_resolver ) ) {
			$owner_user_id = call_user_func( $owner_resolver );
			if ( is_numeric( $owner_user_id ) ) {
				return max( 0, (int) $owner_user_id );
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'wp_materialize_registered_agents' ) ) {
	/**
	 * Materializes registered agents through a host-provided adapter.
	 *
	 * Agents API collects declarative definitions only. This helper exposes the
	 * generic lifecycle seam for products that want to reconcile those definitions
	 * into runtime or persisted agents without making Agents API choose storage.
	 *
	 * Callers may pass an adapter directly or provide one with the
	 * `wp_agent_registered_agent_materialization_adapter` filter. No adapter means
	 * no materialization and an empty result set.
	 *
	 * @param WP_Agent_Registered_Agent_Materialization_Adapter|null $adapter Adapter, or null to use the filter.
	 * @param array<string,mixed>                                    $args    Host-owned materialization options and context.
	 * @return array<string,WP_Agent_Materialization_Result> Results keyed by registered slug or adapter-owned removed-state key.
	 */
	function wp_materialize_registered_agents( ?WP_Agent_Registered_Agent_Materialization_Adapter $adapter = null, array $args = array() ): array {
		$registry = WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		/**
		 * Filters the adapter used to materialize registered agents.
		 *
		 * The adapter decides storage, runtime activation, owner resolution,
		 * duplicate-update behavior for durable identities, and removed-definition
		 * reconciliation. Agents API passes only the current registered definition
		 * snapshot plus caller-provided options.
		 *
		 * @param WP_Agent_Registered_Agent_Materialization_Adapter|null $adapter Adapter.
		 * @param WP_Agents_Registry                                      $registry Registry snapshot source.
		 * @param array<string,mixed>                                     $args     Host-owned materialization options and context.
		 */
		$adapter = apply_filters( 'wp_agent_registered_agent_materialization_adapter', $adapter, $registry, $args );

		if ( ! $adapter instanceof WP_Agent_Registered_Agent_Materialization_Adapter ) {
			return array();
		}

		return $adapter->materialize_registered_agents( $registry->get_all_registered(), $args );
	}
}
