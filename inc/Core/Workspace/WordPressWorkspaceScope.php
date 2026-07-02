<?php
/**
 * WordPress workspace scope adapter.
 *
 * @package DataMachine\Core\Workspace
 */

namespace DataMachine\Core\Workspace;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the generic Agents API workspace identity for the current WordPress site.
 */
class WordPressWorkspaceScope {

	/**
	 * Return the current site's generic workspace scope.
	 *
	 * WordPress-specific details such as blog ID and network ID stay in adapter
	 * metadata; the generic boundary is the Agents API workspace value object.
	 *
	 * @return WP_Agent_Workspace_Scope
	 */
	public static function current(): WP_Agent_Workspace_Scope {
		return WP_Agent_Workspace_Scope::from_parts( 'site', self::current_site_id() );
	}

	/**
	 * Return JSON-serializable WordPress adapter metadata for the current site.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata(): array {
		$metadata = array(
			'blog_id'  => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'home_url' => function_exists( 'home_url' ) ? untrailingslashit( (string) home_url( '/' ) ) : '',
			'site_url' => function_exists( 'site_url' ) ? untrailingslashit( (string) site_url( '/' ) ) : '',
		);

		if ( function_exists( 'get_current_network_id' ) ) {
			$metadata['network_id'] = (int) get_current_network_id();
		}

		return array_filter(
			$metadata,
			static fn( $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * Resolve a stable current-site workspace identifier.
	 *
	 * @return string
	 */
	private static function current_site_id(): string {
		if ( function_exists( 'home_url' ) ) {
			$home_url = untrailingslashit( (string) home_url( '/' ) );
			if ( '' !== $home_url ) {
				return $home_url;
			}
		}

		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			return 'local:' . rtrim( (string) ABSPATH, '/' );
		}

		return 'local:unknown';
	}
}
