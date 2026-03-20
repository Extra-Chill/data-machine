<?php
/**
 * Memory File Registry
 *
 * Central registry for agent memory files injected into AI calls.
 * Each file has a layer (shared, agent, user), priority, protection
 * status, and optional metadata. Core files register through the same
 * API that plugins and themes use.
 *
 * Extension point: the `datamachine_memory_files` filter fires once
 * per request when the registry is first consumed, allowing third
 * parties to register additional files.
 *
 * @package DataMachine\Engine\AI
 * @since   0.30.0
 * @since   0.42.0 Added layer-aware registration with metadata.
 * @since   0.50.0 Added editability control with capability gating.
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

class MemoryFileRegistry {

	/**
	 * Valid layer identifiers.
	 */
	const LAYER_SHARED  = 'shared';
	const LAYER_AGENT   = 'agent';
	const LAYER_USER    = 'user';
	const LAYER_NETWORK = 'network';

	/**
	 * Registered memory files.
	 *
	 * @var array<string, array> Filename => file metadata.
	 */
	private static array $files = array();

	/**
	 * Whether the filter has been applied.
	 *
	 * @var bool
	 */
	private static bool $filter_applied = false;

	/**
	 * Register a memory file.
	 *
	 * @since 0.42.0 Accepts $args array with layer, protected, label, description.
	 * @since 0.50.0 Added `editable` argument for write-permission gating.
	 *
	 * @param string    $filename Filename (e.g. 'SOUL.md', 'brand-guidelines.md').
	 * @param int       $priority Sort order. Lower numbers load first.
	 * @param array     $args     {
	 *     Optional. Registration arguments.
	 *
	 *     @type string      $layer       One of 'shared', 'agent', 'user', 'network'. Default 'agent'.
	 *     @type bool        $protected   Whether the file is protected from deletion. Default false.
	 *     @type string      $label       Human-readable display label. Default derived from filename.
	 *     @type string      $description Optional description of the file's purpose.
	 *     @type bool|string $editable    Write-permission control. true = editable by anyone with
	 *                                    can_manage(). false = read-only (backend/filters only).
	 *                                    A capability string (e.g. 'manage_options') = editable only
	 *                                    by users with that WordPress capability. Default true.
	 * }
	 * @return void
	 */
	public static function register( string $filename, int $priority = 50, array $args = array() ): void {
		$filename = sanitize_file_name( $filename );

		if ( empty( $filename ) ) {
			return;
		}

		$layer = $args['layer'] ?? self::LAYER_AGENT;
		if ( ! in_array( $layer, array( self::LAYER_SHARED, self::LAYER_AGENT, self::LAYER_USER, self::LAYER_NETWORK ), true ) ) {
			$layer = self::LAYER_AGENT;
		}

		// Normalize editable: true (default), false, or a WordPress capability string.
		$editable = $args['editable'] ?? true;
		if ( ! is_bool( $editable ) && ! is_string( $editable ) ) {
			$editable = true;
		}

		self::$files[ $filename ] = array(
			'filename'    => $filename,
			'priority'    => $priority,
			'layer'       => $layer,
			'protected'   => (bool) ( $args['protected'] ?? false ),
			'editable'    => $editable,
			'label'       => $args['label'] ?? self::filename_to_label( $filename ),
			'description' => $args['description'] ?? '',
		);
	}

	/**
	 * Deregister a memory file.
	 *
	 * @param string $filename Filename to remove.
	 * @return void
	 */
	public static function deregister( string $filename ): void {
		unset( self::$files[ sanitize_file_name( $filename ) ] );
	}

	/**
	 * Check if a file is registered.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_registered( string $filename ): bool {
		$resolved = self::get_resolved();
		return isset( $resolved[ sanitize_file_name( $filename ) ] );
	}

	/**
	 * Check if a file is protected from deletion.
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_protected( string $filename ): bool {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );
		return isset( $resolved[ $filename ] ) && $resolved[ $filename ]['protected'];
	}

	/**
	 * Check if a file is editable by the current user.
	 *
	 * Resolution:
	 * - Unregistered files: editable (custom user files).
	 * - `editable === false`: not editable (auto-generated, backend-only).
	 * - `editable === true`: editable by anyone who passes can_manage().
	 * - `editable` is a capability string: editable if the user has that capability.
	 *
	 * @since 0.50.0
	 *
	 * @param string $filename Filename to check.
	 * @param int    $user_id  Optional. User ID to check against. 0 = current user.
	 * @return bool
	 */
	public static function is_editable( string $filename, int $user_id = 0 ): bool {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );

		if ( ! isset( $resolved[ $filename ] ) ) {
			return true; // Unregistered files are editable.
		}

		$editable = $resolved[ $filename ]['editable'];

		if ( false === $editable ) {
			return false;
		}

		if ( true === $editable ) {
			return true;
		}

		// Capability string: check against user.
		if ( is_string( $editable ) && ! empty( $editable ) ) {
			$check_user = $user_id > 0 ? $user_id : get_current_user_id();
			return $check_user > 0 && user_can( $check_user, $editable );
		}

		return true;
	}

	/**
	 * Get the raw edit capability for a file.
	 *
	 * Returns:
	 * - true if editable by any manager.
	 * - false if not editable.
	 * - A capability string if gated by a specific WordPress capability.
	 * - null if not registered.
	 *
	 * @since 0.50.0
	 *
	 * @param string $filename Filename to look up.
	 * @return bool|string|null
	 */
	public static function get_edit_capability( string $filename ) {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );
		return $resolved[ $filename ]['editable'] ?? null;
	}

	/**
	 * Get the layer for a registered file.
	 *
	 * @param string $filename Filename to look up.
	 * @return string|null Layer identifier, or null if not registered.
	 */
	public static function get_layer( string $filename ): ?string {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );
		return $resolved[ $filename ]['layer'] ?? null;
	}

	/**
	 * Get metadata for a single file.
	 *
	 * @param string $filename Filename to look up.
	 * @return array|null File metadata, or null if not registered.
	 */
	public static function get( string $filename ): ?array {
		$resolved = self::get_resolved();
		return $resolved[ sanitize_file_name( $filename ) ] ?? null;
	}

	/**
	 * Get all registered files sorted by priority.
	 *
	 * @return array<string, array> Filename => metadata, sorted by priority ascending.
	 */
	public static function get_all(): array {
		return self::get_resolved();
	}

	/**
	 * Get sorted filenames only.
	 *
	 * @return string[]
	 */
	public static function get_filenames(): array {
		return array_keys( self::get_resolved() );
	}

	/**
	 * Get all files for a specific layer.
	 *
	 * @param string $layer Layer identifier ('shared', 'agent', 'user').
	 * @return array<string, array> Filtered and sorted file metadata.
	 */
	public static function get_by_layer( string $layer ): array {
		return array_filter(
			self::get_resolved(),
			function ( $meta ) use ( $layer ) {
				return $meta['layer'] === $layer;
			}
		);
	}

	/**
	 * Get all protected filenames.
	 *
	 * @return string[]
	 */
	public static function get_protected_filenames(): array {
		return array_keys(
			array_filter(
				self::get_resolved(),
				function ( $meta ) {
					return $meta['protected'];
				}
			)
		);
	}

	/**
	 * Get filenames that belong to a specific layer.
	 *
	 * @param string $layer Layer identifier.
	 * @return string[]
	 */
	public static function get_layer_filenames( string $layer ): array {
		return array_keys( self::get_by_layer( $layer ) );
	}

	/**
	 * Reset the registry. Primarily for testing.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$files          = array();
		self::$filter_applied = false;
	}

	/**
	 * Get the resolved registry (with filter applied).
	 *
	 * The `datamachine_memory_files` filter fires once per request,
	 * allowing plugins/themes to register additional files.
	 *
	 * @return array<string, array> Sorted by priority ascending.
	 */
	private static function get_resolved(): array {
		if ( ! self::$filter_applied ) {
			/**
			 * Filter the memory file registry.
			 *
			 * Third parties can add files by calling MemoryFileRegistry::register()
			 * inside this filter callback. The $files array is the current state
			 * of the registry for inspection — modifications to the array itself
			 * are ignored; use the register/deregister API instead.
			 *
			 * @since 0.42.0
			 *
			 * @param array<string, array> $files Current registry state (read-only snapshot).
			 */
			do_action( 'datamachine_memory_files', self::$files );
			self::$filter_applied = true;
		}

		$files = self::$files;
		uasort(
			$files,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $files;
	}

	/**
	 * Derive a human-readable label from a filename.
	 *
	 * @param string $filename The filename.
	 * @return string Label.
	 */
	private static function filename_to_label( string $filename ): string {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		return ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
	}
}
