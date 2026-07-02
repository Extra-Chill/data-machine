<?php
/**
 * REST wrappers for agent bundle lifecycle abilities.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'datamachine/v1',
			'/agent-bundles',
			array(
				'methods'             => 'GET',
				'callback'            => static fn(): array => AgentAbilities::listAgentBundles(),
				'permission_callback' => static fn(): bool => PermissionHelper::can_manage(),
			)
		);

		register_rest_route(
			'datamachine/v1',
			'/agent-bundles/(?P<slug>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => static fn( \WP_REST_Request $request ): array => AgentAbilities::getAgentBundleStatus(
					array( 'slug' => (string) $request['slug'] )
				),
				'permission_callback' => static fn(): bool => PermissionHelper::can_manage(),
			)
		);

		$routes = array(
			'/agent-bundles/inspect' => array( AgentAbilities::class, 'inspectAgentBundle' ),
			'/agent-bundles/validate' => array( AgentAbilities::class, 'validateAgentBundle' ),
			'/agent-bundles/plan'    => array( AgentAbilities::class, 'planAgentBundleUpgrade' ),
			'/agent-bundles/rebase'  => array( AgentAbilities::class, 'rebaseAgentBundleArtifacts' ),
			'/agent-bundles/upgrade' => array( AgentAbilities::class, 'applyAgentBundleUpgrade' ),
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				'datamachine/v1',
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => static fn( \WP_REST_Request $request ): array => call_user_func( $callback, $request->get_json_params() ?: array() ),
					'permission_callback' => static fn(): bool => PermissionHelper::can_manage(),
				)
			);
		}
	}
);
