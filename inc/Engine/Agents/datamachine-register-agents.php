<?php
/**
 * Data Machine agent registration integration.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.71.0
 */

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Agents\AgentRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Register a Data Machine agent.
 *
 * Existing Data Machine-named wrapper for the in-place transition. New
 * declarations should use `wp_register_agent()` from `wp_agents_api_init`.
 *
 * @since 0.71.0
 *
 * @param string $slug Unique agent slug.
 * @param array  $args Registration arguments. See AgentRegistry::register().
 * @return void
 */
function datamachine_register_agent( string $slug, array $args = array() ): void {
	AgentRegistry::register( $slug, $args );
}

/**
 * Reconcile registered agents on `init`.
 *
 * Priority 15 runs after ability registration (priority 10) so the scaffold
 * ability is available when reconciliation triggers SOUL/MEMORY creation for
 * newly-materialized agents, and before the existing `datamachine_needs_scaffold`
 * transient check at priority 20.
 */
add_action(
	'init',
	static function (): void {
		AgentRegistry::reconcile();
	},
	15
);

/**
 * Memory-seed scaffold generator — surface registered `memory_seeds` as content.
 *
 * @param string $content  Current content (empty if no prior generator).
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with `agent_slug`.
 * @return string
 */
add_filter(
	'datamachine_scaffold_content',
	static function ( string $content, string $filename, array $context ): string {
		if ( '' !== $content ) {
			return $content;
		}

		$agent_slug = isset( $context['agent_slug'] ) ? (string) $context['agent_slug'] : '';
		if ( '' === $agent_slug ) {
			return $content;
		}

		$def = AgentRegistry::get( $agent_slug );
		if ( ! $def || empty( $def['memory_seeds'] ) ) {
			return $content;
		}

		$filename_key = sanitize_file_name( $filename );
		$seeds        = $def['memory_seeds'];
		if ( ! isset( $seeds[ $filename_key ] ) ) {
			return $content;
		}

		$path = (string) $seeds[ $filename_key ];
		if ( '' === $path || ! is_readable( $path ) ) {
			return $content;
		}

		$bundled = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bundled || '' === $bundled ) {
			return $content;
		}

		return $bundled;
	},
	5,
	3
);

/**
 * DM core dogfood — register the default site administrator agent.
 *
 * Declares the site's default admin-owned agent through the same hook plugins
 * use. Named function (not a closure) so plugins can remove or replace it.
 *
 * @since 0.71.0
 */
function datamachine_register_default_admin_agent(): void {
	if ( ! class_exists( DirectoryManager::class ) ) {
		return;
	}

	$default_user_id = (int) DirectoryManager::get_default_agent_user_id();
	if ( $default_user_id <= 0 ) {
		return;
	}

	$user = get_user_by( 'id', $default_user_id );
	if ( ! $user ) {
		return;
	}

	$slug = sanitize_title( (string) $user->user_login );
	if ( '' === $slug ) {
		return;
	}

	$default_config = array();
	if ( class_exists( '\\DataMachine\\Core\\PluginSettings' ) ) {
		$resolved = \DataMachine\Core\PluginSettings::getModelForMode( 'chat' );
		$provider = isset( $resolved['provider'] ) ? (string) $resolved['provider'] : ''; // @phpstan-ignore isset.offset
		$model    = isset( $resolved['model'] ) ? (string) $resolved['model'] : ''; // @phpstan-ignore isset.offset

		if ( '' !== $provider ) {
			$default_config['default_provider'] = $provider;
		}
		if ( '' !== $model ) {
			$default_config['default_model'] = $model;
		}
	}

	wp_register_agent(
		$slug,
		array(
			'label'          => (string) $user->display_name,
			'description'    => __( 'Default site administrator agent.', 'data-machine' ),
			'owner_resolver' => static fn() => $default_user_id,
			'default_config' => $default_config,
		)
	);
}
add_action( 'wp_agents_api_init', 'datamachine_register_default_admin_agent', 10 );
