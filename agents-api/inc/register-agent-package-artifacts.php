<?php
/**
 * Agent package artifact type registration helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_register_agent_package_artifact_type' ) ) {
	/**
	 * Register an agent package artifact type.
	 *
	 * Call from inside a `wp_agent_package_artifacts_init` action callback. The
	 * registry collects type metadata without deciding when lifecycle callbacks run.
	 *
	 * @param string|WP_Agent_Package_Artifact_Type $type Artifact type slug or object.
	 * @param array                                 $args Registration arguments.
	 * @return WP_Agent_Package_Artifact_Type|null Registered artifact type, or null on invalid arguments.
	 */
	function wp_register_agent_package_artifact_type( $type, array $args = array() ): ?WP_Agent_Package_Artifact_Type {
		$slug = $type instanceof WP_Agent_Package_Artifact_Type ? $type->get_type() : (string) $type;
		if ( ! doing_action( 'wp_agent_package_artifacts_init' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					'Agent package artifact types must be registered on the %1$s action. The artifact type %2$s was not registered.',
					'<code>wp_agent_package_artifacts_init</code>',
					'<code>' . esc_html( $slug ) . '</code>'
				),
				'0.102.8'
			);
			return null;
		}

		$registry = WP_Agent_Package_Artifacts_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->register( $type, $args );
	}
}

if ( ! function_exists( 'wp_get_agent_package_artifact_type' ) ) {
	/**
	 * Retrieves a registered agent package artifact type.
	 *
	 * @param string $type Artifact type slug.
	 * @return WP_Agent_Package_Artifact_Type|null Registered type, or null when not registered.
	 */
	function wp_get_agent_package_artifact_type( string $type ): ?WP_Agent_Package_Artifact_Type {
		$registry = WP_Agent_Package_Artifacts_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->get_registered( $type );
	}
}

if ( ! function_exists( 'wp_get_agent_package_artifact_types' ) ) {
	/**
	 * Retrieves all registered agent package artifact types.
	 *
	 * @return array<string, WP_Agent_Package_Artifact_Type>
	 */
	function wp_get_agent_package_artifact_types(): array {
		$registry = WP_Agent_Package_Artifacts_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		return $registry->get_all_registered();
	}
}

if ( ! function_exists( 'wp_has_agent_package_artifact_type' ) ) {
	/**
	 * Checks whether an agent package artifact type is registered.
	 *
	 * @param string $type Artifact type slug.
	 * @return bool
	 */
	function wp_has_agent_package_artifact_type( string $type ): bool {
		$registry = WP_Agent_Package_Artifacts_Registry::get_instance();
		if ( null === $registry ) {
			return false;
		}

		return $registry->is_registered( $type );
	}
}

if ( ! function_exists( 'wp_unregister_agent_package_artifact_type' ) ) {
	/**
	 * Unregisters an agent package artifact type.
	 *
	 * @param string $type Artifact type slug.
	 * @return WP_Agent_Package_Artifact_Type|null Removed type, or null when not registered.
	 */
	function wp_unregister_agent_package_artifact_type( string $type ): ?WP_Agent_Package_Artifact_Type {
		$registry = WP_Agent_Package_Artifacts_Registry::get_instance();
		if ( null === $registry ) {
			return null;
		}

		return $registry->unregister( $type );
	}
}
