<?php
/**
 * Data Machine agent registration integration.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.71.0
 */

use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Agents\AgentMaterializer;
use DataMachine\Engine\Agents\PersistedAgentProjector;

defined( 'ABSPATH' ) || exit;

/**
 * Reconcile registered agents on `init`.
 *
 * Priority 15 runs after ability registration (priority 10) so the scaffold
 * ability is available when reconciliation triggers SOUL/MEMORY creation for
 * newly-materialized agents, and before the existing `datamachine_needs_scaffold`
 * transient check at priority 20.
 *
 * @return array{created: string[], existing: string[], definition_only: string[], skipped: string[]}
 */
function datamachine_reconcile_registered_agents(): array {
	if ( class_exists( \DataMachine\Core\Bootstrap\ActivationServiceProvider::class ) ) {
		\DataMachine\Core\Bootstrap\ActivationServiceProvider::ensure_all_tables();
	}

	$definitions = array_map(
		static fn( \WP_Agent $agent ): array => $agent->to_array(),
		// @phpstan-ignore-next-line Bundled Agents API functions are absent from the WordPress stubs.
		wp_get_agents()
	);

	return AgentMaterializer::reconcile( $definitions );
}

add_action(
	'init',
	'datamachine_reconcile_registered_agents',
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
function datamachine_registered_agent_memory_seed( string $content, string $filename, array $context ): string {
	if ( '' !== $content ) {
		return $content;
	}

	$agent_slug = isset( $context['agent_slug'] ) ? (string) $context['agent_slug'] : '';
	// @phpstan-ignore-next-line Bundled Agents API functions are absent from the WordPress stubs.
	if ( '' === $agent_slug || ! wp_has_agent( $agent_slug ) ) {
		return $content;
	}

	// @phpstan-ignore-next-line Bundled Agents API functions are absent from the WordPress stubs.
	$agent = wp_get_agent( $agent_slug );
	if ( ! $agent instanceof \WP_Agent ) {
		return $content;
	}

	$seeds        = $agent->get_memory_seeds();
	$filename_key = sanitize_file_name( $filename );
	if ( ! isset( $seeds[ $filename_key ] ) ) {
		return $content;
	}

	$path = (string) $seeds[ $filename_key ];
	if ( '' === $path || ! is_readable( $path ) ) {
		return $content;
	}

	$bundled = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return false !== $bundled && '' !== $bundled ? $bundled : $content;
}
add_filter( 'datamachine_scaffold_content', 'datamachine_registered_agent_memory_seed', 5, 3 );

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

	// @phpstan-ignore-next-line Bundled Agents API functions are absent from the WordPress stubs.
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

/**
 * Project durable Data Machine agents into the Agents API runtime registry.
 *
 * @since 0.110.3
 */
function datamachine_register_persisted_agents(): void {
	PersistedAgentProjector::register_persisted_agents();
}
add_action( 'wp_agents_api_init', 'datamachine_register_persisted_agents', 20 );

/**
 * Materialize runtime-imported Agents API bundles for Data Machine chat.
 *
 * Browser runtimes can import a request-local agent bundle immediately before
 * calling `agents/chat`. The generic importer registers that definition in the
 * Agents API registry; Data Machine still needs a persisted identity row so its
 * chat handler can resolve owner, model config, and tool policy.
 *
 * @param mixed $result Runtime bundle import result.
 * @return mixed Import result.
 */
function datamachine_reconcile_runtime_agent_bundle_import( $result ) {
	if ( is_array( $result ) && ! empty( $result['success'] ) && ! empty( $result['agent_slug'] ) ) {
		datamachine_reconcile_registered_agents();
	}

	return $result;
}
add_filter( 'wp_agent_runtime_import_bundle', 'datamachine_reconcile_runtime_agent_bundle_import', 20, 1 );
