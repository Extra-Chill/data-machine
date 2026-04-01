<?php
/**
 * Execution Context Registry
 *
 * Central registry for AI execution contexts (chat, pipeline, system, editor, etc.).
 * Each context has an ID, label, description, and priority for sort order.
 * Core contexts register through the same API that extensions use.
 *
 * Extension point: the `datamachine_contexts` action fires once per request
 * when the registry is first consumed, allowing extensions to register
 * additional execution contexts.
 *
 * @package DataMachine\Engine\AI
 * @since   0.63.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class ContextRegistry {

	/**
	 * Registered execution contexts.
	 *
	 * @var array<string, array> Context ID => context metadata.
	 */
	private static array $contexts = array();

	/**
	 * Whether the action has been fired.
	 *
	 * @var bool
	 */
	private static bool $filter_applied = false;

	/**
	 * Register an execution context.
	 *
	 * @since 0.63.0
	 *
	 * @param string $id       Context identifier (e.g. 'chat', 'pipeline', 'editor').
	 * @param int    $priority Sort order. Lower numbers appear first.
	 * @param array  $args     {
	 *     Registration arguments.
	 *
	 *     @type string $label       Human-readable display label.
	 *     @type string $description Description of the context's purpose.
	 * }
	 * @return void
	 */
	public static function register( string $id, int $priority = 50, array $args = array() ): void {
		$id = sanitize_key( $id );

		if ( empty( $id ) ) {
			return;
		}

		self::$contexts[ $id ] = array(
			'id'          => $id,
			'priority'    => $priority,
			'label'       => $args['label'] ?? self::id_to_label( $id ),
			'description' => $args['description'] ?? '',
		);
	}

	/**
	 * Deregister an execution context.
	 *
	 * @since 0.63.0
	 *
	 * @param string $id Context identifier to remove.
	 * @return void
	 */
	public static function deregister( string $id ): void {
		unset( self::$contexts[ sanitize_key( $id ) ] );
	}

	/**
	 * Check if a context is registered.
	 *
	 * @since 0.63.0
	 *
	 * @param string $id Context identifier.
	 * @return bool
	 */
	public static function is_registered( string $id ): bool {
		$resolved = self::get_resolved();
		return isset( $resolved[ sanitize_key( $id ) ] );
	}

	/**
	 * Get metadata for a single context.
	 *
	 * @since 0.63.0
	 *
	 * @param string $id Context identifier.
	 * @return array|null Context metadata, or null if not registered.
	 */
	public static function get( string $id ): ?array {
		$resolved = self::get_resolved();
		return $resolved[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Get all registered contexts sorted by priority.
	 *
	 * @since 0.63.0
	 *
	 * @return array<string, array> Context ID => metadata, sorted by priority ascending.
	 */
	public static function get_all(): array {
		return self::get_resolved();
	}

	/**
	 * Get sorted context IDs only.
	 *
	 * @since 0.63.0
	 *
	 * @return string[]
	 */
	public static function get_ids(): array {
		return array_keys( self::get_resolved() );
	}

	/**
	 * Get all contexts formatted for the settings UI.
	 *
	 * Returns the same shape that PluginSettings::getContexts() formerly
	 * returned: a sequential array of [ id, label, description ] arrays.
	 *
	 * @since 0.63.0
	 *
	 * @return array<int, array{ id: string, label: string, description: string }>
	 */
	public static function get_for_settings(): array {
		$contexts = self::get_resolved();

		return array_values(
			array_map(
				function ( $ctx ) {
					return array(
						'id'          => $ctx['id'],
						'label'       => $ctx['label'],
						'description' => $ctx['description'],
					);
				},
				$contexts
			)
		);
	}

	/**
	 * Reset the registry. Primarily for testing.
	 *
	 * @since 0.63.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$contexts       = array();
		self::$filter_applied = false;
	}

	/**
	 * Get the resolved registry (with action fired).
	 *
	 * The `datamachine_contexts` action fires once per request,
	 * allowing extensions to register additional execution contexts.
	 *
	 * @return array<string, array> Sorted by priority ascending.
	 */
	private static function get_resolved(): array {
		if ( ! self::$filter_applied ) {
			/**
			 * Fires when the context registry is first consumed.
			 *
			 * Extensions register their execution contexts by calling
			 * ContextRegistry::register() inside this action callback.
			 * The $contexts parameter is a read-only snapshot for inspection.
			 *
			 * @since 0.63.0
			 *
			 * @param array<string, array> $contexts Current registry state (read-only snapshot).
			 */
			do_action( 'datamachine_contexts', self::$contexts );
			self::$filter_applied = true;
		}

		$contexts = self::$contexts;
		uasort(
			$contexts,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $contexts;
	}

	/**
	 * Derive a human-readable label from a context ID.
	 *
	 * @param string $id The context identifier.
	 * @return string Label.
	 */
	private static function id_to_label( string $id ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $id ) );
	}
}
