<?php
/**
 * Agent Registration — global helper + hook wiring.
 *
 * Defines the top-level `datamachine_register_agent()` function that
 * plugins call from inside a `datamachine_register_agents` action
 * callback to declare agents. Wires reconciliation on `init` and a
 * SOUL.md scaffold generator that surfaces each registered agent's
 * bundled `soul_path` as the SOUL content at creation time.
 *
 * Also dogfoods the API: DM itself registers the site's default
 * administrator agent through the same hook plugins use. On existing
 * installs the registration is a no-op because the agent already
 * exists in the `datamachine_agents` table. On fresh installs the
 * registry is the primary creation path for the default agent.
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
 * Call from inside a `datamachine_register_agents` action callback.
 * The registry collects all definitions and reconciles them against
 * the `datamachine_agents` table on `init` priority 15.
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
 * Priority 15 runs after ability registration (priority 10) so the
 * scaffold ability is available when reconciliation triggers SOUL/MEMORY
 * creation for newly-materialized agents, and before the existing
 * `datamachine_needs_scaffold` transient check at priority 20.
 *
 * @since 0.71.0
 */
add_action(
	'init',
	static function (): void {
		AgentRegistry::reconcile();
	},
	15
);

/**
 * SOUL.md scaffold generator — surface registered `soul_path` as content.
 *
 * Priority 5 runs before DM's default site-context SOUL generator
 * (priority 10 in inc/migrations/scaffolding.php). When a registered
 * agent provides a `soul_path`, its bundled content becomes the SOUL.md
 * scaffold. Agents without a `soul_path` fall through untouched and the
 * default generator produces the generic site-context SOUL.
 *
 * @since 0.71.0
 *
 * @param string $content  Current content (empty if no prior generator).
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with `agent_slug`.
 * @return string
 */
add_filter(
	'datamachine_scaffold_content',
	static function ( string $content, string $filename, array $context ): string {
		if ( 'SOUL.md' !== $filename || '' !== $content ) {
			return $content;
		}

		$agent_slug = isset( $context['agent_slug'] ) ? (string) $context['agent_slug'] : '';
		if ( '' === $agent_slug ) {
			return $content;
		}

		$def = AgentRegistry::get( $agent_slug );
		if ( ! $def || empty( $def['soul_path'] ) ) {
			return $content;
		}

		$path = (string) $def['soul_path'];
		if ( ! is_readable( $path ) ) {
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
 * Declares the site's default admin-owned agent through the same
 * hook plugins use. Uses the site admin's `user_login` as the slug —
 * the same identity `datamachine_resolve_or_create_agent_id()` produces
 * on a user's first chat turn — so this registration is a no-op on
 * existing installs where that agent already exists.
 *
 * Named function (not a closure) so plugins that want to suppress the
 * default admin-agent registration can `remove_action()` it cleanly:
 *
 *     remove_action(
 *         'datamachine_register_agents',
 *         'datamachine_register_default_admin_agent',
 *         10
 *     );
 *
 * Plugins that want to *replace* (rather than suppress) the default
 * admin agent can hook at a higher priority and re-register with the
 * same slug — standard WordPress last-wins semantics apply at the
 * registry level. Note: reconciliation is create-if-missing, so
 * re-registration only affects fresh installs where the DB row has
 * not yet been materialized.
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
		$default_config['model'] = array(
			'default' => \DataMachine\Core\PluginSettings::getContextModel( 'chat' ),
		);
	}

	datamachine_register_agent(
		$slug,
		array(
			'label'          => (string) $user->display_name,
			'description'    => __( 'Default site administrator agent.', 'data-machine' ),
			'owner_resolver' => static fn() => $default_user_id,
			'default_config' => $default_config,
		)
	);
}
add_action( 'datamachine_register_agents', 'datamachine_register_default_admin_agent', 10 );
