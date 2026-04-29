<?php
/**
 * Agent Registry
 *
 * Declarative registration surface for Data Machine agents. Plugins
 * (and DM core itself) declare agent roles by calling
 * `datamachine_register_agent()` inside a `datamachine_register_agents`
 * action callback. The registry collects definitions; Data Machine's
 * materializer reconciles them against the `datamachine_agents` table
 * on init.
 *
 * Registration is side-effect free — adding a slug to the registry
 * does not touch the database. Reconciliation materializes missing
 * rows, triggers the scaffold ability for newly-created agents, and
 * leaves existing rows alone (owner_id, agent_config, and other
 * mutable fields remain DB-owned).
 *
 * Plugins ship declarative agent definitions; DM owns the runtime.
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
	 * Registered agent definitions, keyed by slug.
	 *
	 * @var array<string, array>
	 */
	private static array $agents = array();

	/**
	 * Whether the `datamachine_register_agents` action has fired.
	 *
	 * @var bool
	 */
	private static bool $registration_fired = false;

	/**
	 * Register an agent definition.
	 *
	 * Call from inside a `datamachine_register_agents` action callback.
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
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return;
		}

		$label = isset( $args['label'] ) ? (string) $args['label'] : '';
		if ( '' === $label ) {
			$label = $slug;
		}

		$memory_seeds = array();
		if ( isset( $args['memory_seeds'] ) && is_array( $args['memory_seeds'] ) ) {
			foreach ( $args['memory_seeds'] as $filename => $path ) {
				$filename = sanitize_file_name( (string) $filename );
				$path     = (string) $path;
				if ( '' !== $filename && '' !== $path ) {
					$memory_seeds[ $filename ] = $path;
				}
			}
		}

		self::$agents[ $slug ] = array(
			'slug'           => $slug,
			'label'          => $label,
			'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
			'memory_seeds'   => $memory_seeds,
			'owner_resolver' => isset( $args['owner_resolver'] ) && is_callable( $args['owner_resolver'] ) ? $args['owner_resolver'] : null,
			'default_config' => isset( $args['default_config'] ) && is_array( $args['default_config'] ) ? $args['default_config'] : array(),
		);
	}

	/**
	 * Get all registered agent definitions.
	 *
	 * Fires the `datamachine_register_agents` action once per request so
	 * callers can lazily collect registrations without needing to worry
	 * about hook ordering.
	 *
	 * @since 0.71.0
	 *
	 * @return array<string, array>
	 */
	public static function get_all(): array {
		self::ensure_fired();
		return self::$agents;
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
		self::ensure_fired();
		$slug = sanitize_title( $slug );
		return self::$agents[ $slug ] ?? null;
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
	 * Ensure the `datamachine_register_agents` action has fired.
	 *
	 * Plugins register their agents inside action callbacks — collecting
	 * them lazily lets callers of `get_all()` / `reconcile()` / `get()`
	 * work regardless of hook ordering.
	 *
	 * @return void
	 */
	private static function ensure_fired(): void {
		if ( self::$registration_fired ) {
			return;
		}

		self::$registration_fired = true;

		/**
		 * Fires to let plugins register Data Machine agents.
		 *
		 * Callbacks should call `datamachine_register_agent()` to contribute
		 * one or more agent definitions. Registrations are collected into a
		 * central registry and reconciled against the `datamachine_agents`
		 * table on `init` (priority 15).
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
		self::$agents             = array();
		self::$registration_fired = false;
	}
}
