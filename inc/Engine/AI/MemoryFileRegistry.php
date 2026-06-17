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
 * @since   0.60.0 Added context-aware injection control.
 * @since   0.66.0 Added composable file support with convention_path.
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

use WP_Agent_Context_Injection_Policy;
use WP_Agent_Memory_Layer;
use WP_Agent_Memory_Registry;

class MemoryFileRegistry {

	/**
	 * Valid layer identifiers.
	 */
	const LAYER_SHARED  = 'shared';
	const LAYER_AGENT   = 'agent';
	const LAYER_USER    = 'user';
	const LAYER_NETWORK = 'network';

	/**
	 * Special context value meaning "inject in all contexts."
	 */
	const MODE_ALL = 'all';

	/**
	 * Semantic prompt-injection contexts independent of execution mode slugs.
	 */
	const INJECTION_AGENT_IDENTITY = 'agent_identity';
	const INJECTION_AGENT_MEMORY   = 'agent_memory';
	const INJECTION_USER_PROFILE   = 'user_profile';

	/**
	 * Default mode list for registered files.
	 *
	 * Files without explicit modes are registered and manageable, but are not
	 * injected into AI prompts. Prompt injection must be an explicit opt-in.
	 */
	const MODES_NONE = array();

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
	 * @since 0.60.0 Added `contexts` argument for context-aware injection.
	 *
	 * @param string $filename Filename (e.g. 'SOUL.md', 'brand-guidelines.md').
	 * @param int    $priority Sort order. Lower numbers load first.
	 * @param array  $args     {
	 *  Optional. Registration arguments.
	 *
	 *     @type string      $layer           One of 'shared', 'agent', 'user', 'network'. Default 'agent'.
	 *     @type bool        $protected       Whether the file is protected from deletion. Default false.
	 *     @type string      $label           Human-readable display label. Default derived from filename.
	 *     @type string      $description     Optional description of the file's purpose.
	 *     @type bool|string $editable        Write-permission control. true = editable by anyone with
	 *                                        can_manage(). false = read-only (backend/filters only).
	 *                                        A capability string (e.g. 'manage_options') = editable only
	 *                                        by users with that WordPress capability. Default true.
	 *                                        Forced to false when composable is true.
	 *     @type string[]    $modes           Execution modes where this file is available for prompt injection.
	 *                                        Array of mode slugs (e.g. 'chat', 'editor', 'pipeline',
	 *                                        'system') or array( 'all' ) to make available everywhere.
	 *                                        Default empty array: registered but never injected.
	 *     @type string[]    $injection_contexts Semantic prompt-injection contexts where this file applies
	 *                                        independent of execution mode (e.g. 'agent_identity').
	 *     @type string      $retrieval_policy Context injection policy. Only `always` files are injected
	 *                                        by CoreMemoryFilesDirective. Defaults to `never` when modes are
	 *                                        omitted, otherwise `always`.
	 *     @type bool        $composable      Whether this file is auto-generated from registered sections
	 *                                        via SectionRegistry. Composable files are regenerated on
	 *                                        demand and are not hand-editable. Default false.
	 *     @type string      $convention_path Relative path from ABSPATH where a convention copy of this
	 *                                        file should also be written (e.g. 'AGENTS.md'). Optional.
	 * }
	 * @return void
	 */
	public static function register( string $filename, int $priority = 50, array $args = array() ): void {
		$filename = sanitize_file_name( $filename );

		if ( empty( $filename ) ) {
			return;
		}


		$metadata = self::normalize_file_metadata( $filename, $priority, $args );

		self::$files[ $filename ] = $metadata;

		// Mirror into the Agents API source registry.
		WP_Agent_Memory_Registry::register(
			self::source_id_for_filename( $filename ),
			self::to_agents_api_source( $metadata )
		);
	}

	/**
	 * Resolve the default retrieval policy for a registration.
	 *
	 * @param bool $modes_empty Whether the registration declared no modes.
	 * @return string Canonical policy slug.
	 */
	private static function default_retrieval_policy( bool $modes_empty ): string {
		return $modes_empty
			? WP_Agent_Context_Injection_Policy::NEVER
			: WP_Agent_Context_Injection_Policy::ALWAYS;
	}

	/**
	 * Normalize a retrieval policy string.
	 *
	 * @param string $policy Raw policy value.
	 * @return string Canonical policy slug.
	 */
	private static function normalize_retrieval_policy( string $policy ): string {
		return WP_Agent_Context_Injection_Policy::normalize( $policy );
	}

	/**
	 * Normalize semantic memory context slugs.
	 *
	 * @param mixed $contexts Raw context value.
	 * @return array<int,string>
	 */
	public static function normalize_injection_contexts( mixed $contexts ): array {
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

	/**
	 * Deregister a memory file.
	 *
	 * @param string $filename Filename to remove.
	 * @return void
	 */
	public static function deregister( string $filename ): void {
		$filename = sanitize_file_name( $filename );
		unset( self::$files[ $filename ] );
		WP_Agent_Memory_Registry::unregister( self::source_id_for_filename( $filename ) );
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

			// WP-CLI is inherently privileged — it's how admins bootstrap,
			// migrate, and recover sites. When no --user is set, treat the
			// invocation as authorized rather than rejecting as anonymous.
			if ( defined( 'WP_CLI' ) && WP_CLI && $check_user <= 0 ) {
				return true;
			}

			return $check_user > 0 && user_can( $check_user, $editable );
		}

		return true;
	}

	/**
	 * Check if a file is composable (auto-generated from sections).
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Filename to check.
	 * @return bool
	 */
	public static function is_composable( string $filename ): bool {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );
		return isset( $resolved[ $filename ] ) && ! empty( $resolved[ $filename ]['composable'] );
	}

	/**
	 * Get all composable files.
	 *
	 * @since 0.66.0
	 *
	 * @return array<string, array> Filtered and sorted file metadata.
	 */
	public static function get_composable(): array {
		return array_filter(
			self::get_resolved(),
			function ( $meta ) {
				return ! empty( $meta['composable'] );
			}
		);
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
	 * Resolve the canonical filepath for a registered file.
	 *
	 * For files with a convention_path, returns the convention path
	 * (ABSPATH-relative). For all other files, returns the standard
	 * layer directory path. Returns null if the file is not registered.
	 *
	 * This is the single source of truth for "where does this file live
	 * on disk?" — all read and write paths should use this method.
	 *
	 * @since 0.67.0
	 *
	 * @param string $filename   Filename to resolve.
	 * @param string $layer_dir  Resolved layer directory (from DirectoryManager).
	 * @return string|null Full filepath, or null if not registered.
	 */
	public static function resolve_filepath( string $filename, string $layer_dir ): ?string {
		$meta = self::get( $filename );

		if ( ! $meta ) {
			return null;
		}

		// Convention path takes precedence — file lives at ABSPATH + convention_path.
		if ( ! empty( $meta['convention_path'] ) ) {
			return rtrim( ABSPATH, '/' ) . '/' . $meta['convention_path'];
		}

		return trailingslashit( $layer_dir ) . $filename;
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
	 * Get always-injected files applicable to active agent modes.
	 *
	 * Returns files that either list the mode in their `modes` array
	 * or are registered with `['all']`, excluding files
	 * whose retrieval policy says they should not be injected eagerly.
	 *
	 * @since 0.60.0
	 * @since 0.68.0 Internal key renamed from contexts to modes.
	 *
	 * @param array $modes Agent mode slugs (e.g. 'chat', 'pipeline', 'system', 'editor').
	 * @return array<string, array> Filtered and sorted file metadata.
	 */
	public static function get_for_modes( array $modes, array $injection_contexts = array() ): array {
		$modes              = array_values( array_unique( array_filter( array_map( 'sanitize_key', $modes ) ) ) );
		$injection_contexts = self::normalize_injection_contexts( $injection_contexts );

		if ( empty( $modes ) && empty( $injection_contexts ) ) {
			return self::get_resolved();
		}

		return array_filter(
			self::get_resolved(),
			function ( $meta ) use ( $modes, $injection_contexts ) {
				$default_policy   = WP_Agent_Context_Injection_Policy::ALWAYS;
				$retrieval_policy = $meta['retrieval_policy'] ?? $default_policy;
				$is_always        = WP_Agent_Context_Injection_Policy::is_always_injected( $retrieval_policy );
				if ( ! $is_always ) {
					return false;
				}

				$file_modes    = $meta['modes'] ?? self::MODES_NONE;
				$file_contexts = self::normalize_injection_contexts( $meta['injection_contexts'] ?? array() );
				if ( empty( $file_modes ) && empty( $file_contexts ) ) {
					return false;
				}

				return in_array( self::MODE_ALL, $file_modes, true )
					|| ! empty( array_intersect( $modes, $file_modes ) )
					|| ! empty( array_intersect( $injection_contexts, $file_contexts ) );
			}
		);
	}

	/**
	 * Check if a file applies to active agent modes.
	 *
	 * @since 0.60.0
	 * @since 0.68.0 Internal key renamed from contexts to modes.
	 *
	 * @param string $filename Filename to check.
	 * @param array  $modes    Agent mode slugs.
	 * @return bool True if the file should be injected in this mode.
	 */
	public static function applies_to_modes( string $filename, array $modes, array $injection_contexts = array() ): bool {
		$resolved           = self::get_resolved();
		$filename           = sanitize_file_name( $filename );
		$modes              = array_values( array_unique( array_filter( array_map( 'sanitize_key', $modes ) ) ) );
		$injection_contexts = self::normalize_injection_contexts( $injection_contexts );

		if ( ! isset( $resolved[ $filename ] ) ) {
			return true; // Unregistered files are included everywhere.
		}

		$file_modes    = $resolved[ $filename ]['modes'] ?? array( self::MODE_ALL );
		$file_contexts = self::normalize_injection_contexts( $resolved[ $filename ]['injection_contexts'] ?? array() );
		return in_array( self::MODE_ALL, $file_modes, true )
			|| ! empty( array_intersect( $modes, $file_modes ) )
			|| ! empty( array_intersect( $injection_contexts, $file_contexts ) );
	}

	/**
	 * Get the modes array for a registered file.
	 *
	 * @since 0.60.0
	 * @since 0.68.0 Renamed from get_contexts().
	 *
	 * @param string $filename Filename to look up.
	 * @return string[]|null Modes array, or null if not registered.
	 */
	public static function get_modes( string $filename ): ?array {
		$resolved = self::get_resolved();
		$filename = sanitize_file_name( $filename );
		return isset( $resolved[ $filename ] ) ? ( $resolved[ $filename ]['modes'] ?? array( self::MODE_ALL ) ) : null;
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
		WP_Agent_Memory_Registry::reset();
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

		$files = array_replace( self::from_agents_api_sources( WP_Agent_Memory_Registry::get_all() ), self::$files );
		uasort(
			$files,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $files;
	}

	/**
	 * Convert Agents API source registrations back to Data Machine's filename-keyed product shape.
	 *
	 * @param array<string, array<string, mixed>> $sources Agents API source metadata.
	 * @return array<string, array>
	 */
	private static function from_agents_api_sources( array $sources ): array {
		$files = array();
		foreach ( $sources as $source ) {
			$filename = $source['meta']['filename'] ?? null;
			if ( ! is_string( $filename ) || '' === $filename ) {
				continue;
			}

			$files[ $filename ] = self::normalize_file_metadata(
				$filename,
				(int) ( $source['priority'] ?? 50 ),
				array(
				'filename'           => $filename,
				'layer'              => $source['layer'] ?? self::LAYER_AGENT,
				'protected'          => (bool) ( $source['protected'] ?? false ),
				'editable'           => $source['editable'] ?? true,
				'composable'         => (bool) ( $source['composable'] ?? false ),
				'convention_path'    => is_string( $source['convention_path'] ?? null ) ? $source['convention_path'] : '',
				'modes'              => is_array( $source['modes'] ?? null ) ? $source['modes'] : self::MODES_NONE,
				'injection_contexts' => self::normalize_injection_contexts( $source['injection_contexts'] ?? ( $source['meta']['injection_contexts'] ?? array() ) ),
				'label'              => is_string( $source['label'] ?? null ) ? $source['label'] : self::filename_to_label( $filename ),
				'description'        => is_string( $source['description'] ?? null ) ? $source['description'] : '',
				'retrieval_policy'   => is_string( $source['retrieval_policy'] ?? null ) ? $source['retrieval_policy'] : WP_Agent_Context_Injection_Policy::ALWAYS,
				'authority_tier'     => is_string( $source['meta']['authority_tier'] ?? null ) ? $source['meta']['authority_tier'] : self::default_authority_tier( self::normalize_layer( $source['layer'] ?? self::LAYER_AGENT ), $filename ),
				'provenance'         => is_array( $source['meta']['provenance'] ?? null ) ? $source['meta']['provenance'] : self::default_provenance( $filename ),
				)
			);
		}

		return $files;
	}

	/**
	 * Normalize Data Machine's filename-keyed memory metadata shape.
	 *
	 * @param string $filename Filename.
	 * @param int    $priority Sort priority.
	 * @param array  $args     Raw registration args.
	 * @return array<string,mixed> Normalized metadata.
	 */
	private static function normalize_file_metadata( string $filename, int $priority, array $args ): array {
		$layer              = self::normalize_layer( $args['layer'] ?? self::LAYER_AGENT );
		$composable         = (bool) ( $args['composable'] ?? false );
		$editable           = $composable ? false : ( $args['editable'] ?? true );
		$editable           = ( is_bool( $editable ) || is_string( $editable ) ) ? $editable : true;
		$modes              = is_array( $args['modes'] ?? null ) ? array_values( array_unique( array_map( 'sanitize_key', $args['modes'] ) ) ) : self::MODES_NONE;
		$injection_contexts = self::normalize_injection_contexts( $args['injection_contexts'] ?? array() );
		$retrieval_policy   = $args['retrieval_policy'] ?? self::default_retrieval_policy( empty( $modes ) && empty( $injection_contexts ) );

		return array(
			'filename'           => $filename,
			'priority'           => $priority,
			'layer'              => $layer,
			'protected'          => (bool) ( $args['protected'] ?? false ),
			'editable'           => $editable,
			'composable'         => $composable,
			'convention_path'    => isset( $args['convention_path'] ) ? ltrim( (string) $args['convention_path'], '/' ) : '',
			'modes'              => $modes,
			'injection_contexts' => $injection_contexts,
			'label'              => $args['label'] ?? self::filename_to_label( $filename ),
			'description'        => $args['description'] ?? '',
			'retrieval_policy'   => self::normalize_retrieval_policy( (string) $retrieval_policy ),
			'authority_tier'     => $args['authority_tier'] ?? self::default_authority_tier( $layer, $filename ),
			'provenance'         => is_array( $args['provenance'] ?? null ) ? $args['provenance'] : self::default_provenance( $filename ),
		);
	}

	/**
	 * Project normalized Data Machine memory metadata into Agents API source shape.
	 *
	 * @param array $metadata Normalized file metadata.
	 * @return array<string,mixed> Agents API memory source metadata.
	 */
	private static function to_agents_api_source( array $metadata ): array {
		$filename = (string) $metadata['filename'];

		return array(
			'layer'              => $metadata['layer'],
			'priority'           => $metadata['priority'],
			'protected'          => $metadata['protected'],
			'editable'           => $metadata['editable'],
			'modes'              => $metadata['modes'],
			'injection_contexts' => $metadata['injection_contexts'],
			'retrieval_policy'   => $metadata['retrieval_policy'],
			'composable'         => $metadata['composable'],
			'context_slug'       => self::context_slug_for_filename( $filename ),
			'convention_path'    => $metadata['convention_path'],
			'label'              => $metadata['label'],
			'description'        => $metadata['description'],
			'meta'               => array(
				'filename'           => $filename,
				'authority_tier'     => $metadata['authority_tier'],
				'provenance'         => $metadata['provenance'],
				'injection_contexts' => $metadata['injection_contexts'],
			),
		);
	}

	private static function source_id_for_filename( string $filename ): string {
		return 'datamachine/' . strtolower( $filename );
	}

	public static function context_slug_for_filename( string $filename ): string {
		return sanitize_key( str_replace( '.', '-', strtolower( $filename ) ) );
	}

	/**
	 * Normalize a raw layer string.
	 *
	 * @param string $layer Raw layer identifier.
	 * @return string Normalized layer slug.
	 */
	private static function normalize_layer( string $layer ): string {
		return WP_Agent_Memory_Layer::normalize( $layer, self::LAYER_AGENT );
	}

	/**
	 * Default authority tier for a layer/filename pair.
	 *
	 * @param string $layer    Normalized layer slug.
	 * @param string $filename Filename being registered.
	 * @return string Authority tier slug.
	 */
	private static function default_authority_tier( string $layer, string $filename ): string {
		if ( self::LAYER_SHARED === $layer || self::LAYER_NETWORK === $layer ) {
			return 'workspace_shared';
		}

		if ( self::LAYER_USER === $layer ) {
			return 'user_global';
		}

		if ( 'SOUL.md' === $filename ) {
			return 'agent_identity';
		}

		return 'agent_memory';
	}

	private static function default_provenance( string $filename ): array {
		return array(
			'source_type' => 'datamachine_memory_file',
			'source_ref'  => $filename,
		);
	}

	/**
	 * Derive a human-readable label from a filename.
	 *
	 * @param string $filename The filename.
	 * @return string Label.
	 */
	public static function filename_to_label( string $filename ): string {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		return ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
	}
}
