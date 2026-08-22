<?php
/**
 * In-memory registry of code-defined workflow specs.
 *
 * Mirrors the agent / ability registries: plugins call
 * {@see wp_register_workflow()} during the appropriate boot action and the
 * substrate keeps the resolved Spec in process memory for the duration of
 * the request. Pairs with a {@see WP_Agent_Workflow_Store} for durable
 * (DB-backed) workflows; consumers can query both layers and merge as
 * they prefer.
 *
 * The registry is deliberately stateless across requests — it's not a
 * cache. Use a Store for persistence.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Registry {

	/**
	 * @var array<string,WP_Agent_Workflow_Spec>
	 */
	private static array $workflows = array();

	/**
	 * Register a workflow from a raw spec array. Validation runs first;
	 * on failure the registry returns the WP_Error and the workflow is
	 * not stored.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $raw
	 * @return WP_Agent_Workflow_Spec|WP_Error
	 */
	public static function register( array $raw ) {
		$spec = WP_Agent_Workflow_Spec::from_array( $raw );
		if ( $spec instanceof WP_Error ) {
			return $spec;
		}

		self::$workflows[ $spec->get_id() ] = $spec;

		/**
		 * Fires after a workflow is added to the in-memory registry.
		 *
		 * @since 0.103.0
		 *
		 * @param WP_Agent_Workflow_Spec $spec
		 */
		do_action( 'wp_agent_workflow_registered', $spec );

		return $spec;
	}

	/**
	 * Remove a registered workflow. Returns true on success or a
	 * `WP_Error` with code `not_registered` when the id was never added —
	 * mirrors the `Store::delete()` return shape so consumers don't have
	 * to special-case the in-memory registry.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return true|WP_Error
	 */
	public static function unregister( string $workflow_id ) {
		if ( ! isset( self::$workflows[ $workflow_id ] ) ) {
			return new WP_Error(
				'not_registered',
				sprintf( 'no workflow registered with id `%s`', $workflow_id )
			);
		}
		unset( self::$workflows[ $workflow_id ] );
		return true;
	}

	public static function find( string $workflow_id ): ?WP_Agent_Workflow_Spec {
		return self::$workflows[ $workflow_id ] ?? null;
	}

	/**
	 * @return WP_Agent_Workflow_Spec[]
	 */
	public static function all(): array {
		return array_values( self::$workflows );
	}

	/**
	 * Test-only: clear the in-memory registry. Production code never needs
	 * this; it's exposed for unit tests that share the autoload-loaded
	 * static state across cases.
	 *
	 * @since 0.103.0
	 */
	public static function reset(): void {
		self::$workflows = array();
	}
}
