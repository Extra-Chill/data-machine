<?php
/**
 * WP_Agent_Installed_Agent_Projector service.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Installed_Agent_Projector' ) ) {
	/**
	 * Projects durable installed-agent state into request-local WP_Agent objects.
	 */
	final class WP_Agent_Installed_Agent_Projector {

		/**
		 * Projects installed state back into a declarative agent definition.
		 *
		 * @param WP_Agent_Installed_Agent $installed_agent Installed state.
		 * @param WP_Agent|null            $source_agent     Optional package/registry source definition.
		 * @return WP_Agent
		 */
		public static function project( WP_Agent_Installed_Agent $installed_agent, ?WP_Agent $source_agent = null ): WP_Agent {
			$args = null === $source_agent ? array() : $source_agent->to_array();
			unset( $args['slug'] );

			$args['default_config'] = $installed_agent->get_config();
			$args['meta']           = array_merge(
				is_array( $args['meta'] ?? null ) ? $args['meta'] : array(),
				$installed_agent->get_meta(),
				array(
					'installed_agent_id'       => $installed_agent->get_id(),
					'installed_agent_status'   => $installed_agent->get_status(),
					'installed_agent_owner_id' => $installed_agent->get_owner_user_id(),
					'installed_agent_instance' => $installed_agent->get_instance_key(),
				)
			);

			if ( null !== $installed_agent->get_package_slug() ) {
				$args['meta']['source_package'] = $installed_agent->get_package_slug();
			}

			if ( null !== $installed_agent->get_package_version() ) {
				$args['meta']['source_version'] = $installed_agent->get_package_version();
			}

			return new WP_Agent( $installed_agent->get_agent_slug(), $args );
		}
	}
}
