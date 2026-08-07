<?php
/**
 * Agent Mode Registry
 *
 * Central registry for AI execution modes (chat, pipeline, system, editor, etc.).
	 * Each mode has an ID, label, description, priority for sort order, and
	 * optional memory contexts activated by that mode. Runtime requests can
	 * activate multiple registered modes additively.
 *
 * Extension point: the `datamachine_agent_modes` action fires once per request
 * when the registry is first consumed, allowing extensions to register
 * additional execution modes.
 *
 * @package DataMachine\Engine\AI
 * @since   0.68.0
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class AgentModeRegistry {

	/**
	 * Registered execution modes.
	 *
	 * @var array<string, array> Mode ID => mode metadata.
	 */
	private static array $modes = array();

	/**
	 * Whether the action has been fired.
	 *
	 * @var bool
	 */
	private static bool $filter_applied = false;

	/**
	 * Register an execution mode.
	 *
	 * @since 0.68.0
	 *
	 * @param string $id       Mode identifier (e.g. 'chat', 'pipeline', 'editor').
	 * @param int    $priority Sort order. Lower numbers appear first.
	 * @param array  $args     {
	 *     Registration arguments.
	 *
	 *     @type string   $label           Human-readable display label.
	 *     @type string   $description     Description of the mode's purpose.
	 *     @type string[] $memory_contexts Semantic memory contexts activated by the mode.
	 * }
	 * @return void
	 */
	public static function register( string $id, int $priority = 50, array $args = array() ): void {
		$id = sanitize_key( $id );

		if ( empty( $id ) ) {
			return;
		}

		self::$modes[ $id ] = array(
			'id'              => $id,
			'priority'        => $priority,
			'label'           => $args['label'] ?? self::id_to_label( $id ),
			'description'     => $args['description'] ?? '',
			'memory_contexts' => self::normalize_memory_contexts( $args['memory_contexts'] ?? array() ),
		);
	}

	/**
	 * Deregister an execution mode.
	 *
	 * @since 0.68.0
	 *
	 * @param string $id Mode identifier to remove.
	 * @return void
	 */
	public static function deregister( string $id ): void {
		unset( self::$modes[ sanitize_key( $id ) ] );
	}

	/**
	 * Check if a mode is registered.
	 *
	 * @since 0.68.0
	 *
	 * @param string $id Mode identifier.
	 * @return bool
	 */
	public static function is_registered( string $id ): bool {
		$resolved = self::get_resolved();
		return isset( $resolved[ sanitize_key( $id ) ] );
	}

	/**
	 * Get metadata for a single mode.
	 *
	 * @since 0.68.0
	 *
	 * @param string $id Mode identifier.
	 * @return array|null Mode metadata, or null if not registered.
	 */
	public static function get( string $id ): ?array {
		$resolved = self::get_resolved();
		return $resolved[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Get all registered modes sorted by priority.
	 *
	 * @since 0.68.0
	 *
	 * @return array<string, array> Mode ID => metadata, sorted by priority ascending.
	 */
	public static function get_all(): array {
		return self::get_resolved();
	}

	/**
	 * Get sorted mode IDs only.
	 *
	 * @since 0.68.0
	 *
	 * @return string[]
	 */
	public static function get_ids(): array {
		return array_keys( self::get_resolved() );
	}

	/**
	 * Get semantic memory contexts activated by execution modes.
	 *
	 * @since 0.124.3
	 *
	 * @param array<int,string> $modes Execution mode slugs.
	 * @return array<int,string> Memory context slugs.
	 */
	public static function get_memory_contexts_for_modes( array $modes ): array {
		$resolved = self::get_resolved();
		$contexts = array();

		foreach ( $modes as $mode ) {
			$mode = sanitize_key( (string) $mode );
			if ( '' === $mode || empty( $resolved[ $mode ]['memory_contexts'] ) ) {
				continue;
			}

			$contexts = array_merge( $contexts, $resolved[ $mode ]['memory_contexts'] );
		}

		return array_values( array_unique( $contexts ) );
	}

	/**
	 * Get all modes formatted for the settings UI.
	 *
	 * Returns a sequential array of [ id, label, description ] arrays.
	 *
	 * @since 0.68.0
	 *
	 * @return array<int, array{ id: string, label: string, description: string }>
	 */
	public static function get_for_settings(): array {
		$modes = self::get_resolved();

		return array_values(
			array_map(
				function ( $mode ) {
					return array(
						'id'          => $mode['id'],
						'label'       => $mode['label'],
						'description' => $mode['description'],
					);
				},
				$modes
			)
		);
	}

	/**
	 * Reset the registry. Primarily for testing.
	 *
	 * @since 0.68.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$modes          = array();
		self::$filter_applied = false;
	}

	/**
	 * Get the resolved registry (with action fired).
	 *
	 * The `datamachine_agent_modes` action fires once per request,
	 * allowing extensions to register additional execution modes.
	 *
	 * @return array<string, array> Sorted by priority ascending.
	 */
	private static function get_resolved(): array {
		if ( ! self::$filter_applied ) {
			/**
			 * Fires when the agent mode registry is first consumed.
			 *
			 * Extensions register their execution modes by calling
			 * AgentModeRegistry::register() inside this action callback.
			 * The $modes parameter is a read-only snapshot for inspection.
			 *
			 * @since 0.68.0
			 *
			 * @param array<string, array> $modes Current registry state (read-only snapshot).
			 */
			do_action( 'datamachine_agent_modes', self::$modes );
			self::$filter_applied = true;
		}

		$modes = self::$modes;
		uasort(
			$modes,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $modes;
	}

	/**
	 * Derive a human-readable label from a mode ID.
	 *
	 * @param string $id The mode identifier.
	 * @return string Label.
	 */
	private static function id_to_label( string $id ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $id ) );
	}

	/**
	 * Normalize memory context slugs.
	 *
	 * @param mixed $contexts Raw memory context list.
	 * @return array<int,string>
	 */
	private static function normalize_memory_contexts( mixed $contexts ): array {
		if ( is_string( $contexts ) ) {
			$contexts = array( $contexts );
		}

		if ( ! is_array( $contexts ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $contexts as $context ) {
			if ( ! is_scalar( $context ) ) {
				continue;
			}

			$context = sanitize_key( (string) $context );
			if ( '' !== $context ) {
				$normalized[] = $context;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
