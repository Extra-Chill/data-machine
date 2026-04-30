<?php
/**
 * Agent Registry
 *
 * Declarative registration surface for agents. Plugins (and DM core
 * itself) declare agent roles by calling `wp_register_agent()` inside a
 * `wp_agents_api_init` action callback. The registry collects definitions;
 * Data Machine's materializer consumes them and reconciles them against
 * the `datamachine_agents` table on init while Data Machine hosts the
 * substrate.
 *
 * Registration is side-effect free — adding a slug to the registry
 * does not touch the database. Reconciliation materializes missing
 * rows, triggers the scaffold ability for newly-created agents, and
 * leaves existing rows alone (owner_id, agent_config, and other
 * mutable fields remain DB-owned).
 *
 * Plugins ship declarative agent definitions; DM owns today's runtime.
 * The split mirrors the WordPress pattern where
 * `register_post_type()` is declarative and the posts table stays
 * operator-mutable.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.71.0
 */

namespace DataMachine\Engine\Agents;

defined( 'ABSPATH' ) || exit;

class AgentRegistry {

	/**
	 * Whether the legacy Data Machine registration action has fired.
	 *
	 * @var bool
	 */
	private static bool $legacy_registration_fired = false;

	/**
	 * Register an agent definition.
	 *
	 * Call from inside a `wp_agents_api_init` action callback.
	 * Later registrations for the same slug overwrite earlier ones — this
	 * matches WordPress hook semantics, so plugins can override core or
	 * other plugins via action priority.
	 *
	 * @since 0.71.0
	 *
	 * @param string $slug Unique agent slug (sanitize_title applied).
	 * @param array  $args {
	 *     Registration arguments.
	 *
	 *     @type string   $label          Display name. Defaults to the slug.
	 *     @type string   $description    Short description for admin UI / CLI listings.
	 *     @type array    $memory_seeds   Map of filename → absolute path to a bundled
	 *                                    memory-file template. When the scaffold ability
	 *                                    runs for a registered filename AND the target
	 *                                    file does not yet exist on disk, the bundled
	 *                                    content is used as the scaffold seed. Works
	 *                                    for any filename registered via
	 *                                    `MemoryFileRegistry::register()` — SOUL.md and
	 *                                    MEMORY.md are the common cases, but plugins can
	 *                                    seed custom agent-layer files the same way.
	 *                                    Optional.
	 *     @type callable $owner_resolver Callable returning int user_id. Called once
	 *                                    on row creation to determine the owner.
	 *                                    Defaults to `DirectoryManager::get_default_agent_user_id()`.
	 *     @type array    $default_config Initial agent_config persisted on creation.
	 *                                    Mutations thereafter go through the DB.
	 * }
	 * @return void
	 */
	public static function register( string $slug, array $args = array() ): void {
		\WP_Agents_Registry::register( $slug, $args );
	}

	/**
	 * Get all registered agent definitions.
	 *
	 * Fires the `wp_agents_api_init` action once per request so
	 * callers can lazily collect registrations without needing to worry
	 * about hook ordering.
	 *
	 * @since 0.71.0
	 *
	 * @return array<string, array>
	 */
	public static function get_all(): array {
		self::ensure_legacy_fired();
		return \WP_Agents_Registry::get_all();
	}

	/**
	 * Get a single registered agent definition by slug.
	 *
	 * @since 0.71.0
	 *
	 * @param string $slug Agent slug.
	 * @return array|null Definition, or null if not registered.
	 */
	public static function get( string $slug ): ?array {
		self::ensure_legacy_fired();
		return \WP_Agents_Registry::get( $slug );
	}

	/**
	 * Reconcile registered definitions against the `datamachine_agents` table.
	 *
	 * For each registered slug:
	 *   - Row already exists → leave it alone (mutable fields are DB-owned)
	 *   - Row missing        → resolve owner, create row, bootstrap access,
	 *                          ensure agent directory, trigger scaffold ability
	 *
	 * Idempotent: safe to call multiple times per request. Returns a
	 * summary of what happened for logging / testing.
	 *
	 * @since 0.71.0
	 *
	 * @return array{
	 *     created: string[],
	 *     existing: string[],
	 *     skipped: string[],
	 * }
	 */
	public static function reconcile(): array {
		return AgentMaterializer::reconcile( self::get_all() );
	}

	/**
	 * Ensure the agent registration actions have fired.
	 *
	 * Plugins register their agents inside action callbacks — collecting
	 * them lazily lets callers of `get_all()` / `reconcile()` / `get()`
	 * work regardless of hook ordering.
	 *
	 * @return void
	 */
	private static function ensure_legacy_fired(): void {
		\WP_Agents_Registry::get_all();

		if ( self::$legacy_registration_fired ) {
			return;
		}

		self::$legacy_registration_fired = true;

		/**
		 * Fires to let existing Data Machine consumers register agents.
		 *
		 * This hook remains while the Agents API surface lives in Data Machine.
		 * New code should use `wp_agents_api_init` and `wp_register_agent()` so
		 * the registry vocabulary can move cleanly when Agents API is extracted.
		 *
		 * @since 0.71.0
		 */
		do_action( 'datamachine_register_agents' );
	}

	/**
	 * Reset internal state. Test helper only.
	 *
	 * @internal
	 * @since 0.71.0
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$legacy_registration_fired = false;
		\WP_Agents_Registry::reset_for_tests();
	}
}
