<?php
/**
 * Agent File Abilities
 *
 * Abilities API primitives for agent memory file operations.
 * Supports all three layers (shared, agent, user) with routing
 * driven by the MemoryFileRegistry for registered files.
 *
 * New files default to the agent layer. A `layer` parameter can
 * explicitly target shared or user layers.
 *
 * @package DataMachine\Abilities\File
 * @since   0.38.0
 * @since   0.42.0 Layer-aware CRUD via MemoryFileRegistry.
 */

namespace DataMachine\Abilities\File;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\AgentMemoryScope;
use DataMachine\Core\FilesRepository\AgentMemoryStoreFactory;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class AgentFileAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerListAgentFiles();
			$this->registerGetAgentFile();
			$this->registerWriteAgentFile();
			$this->registerDeleteAgentFile();
			$this->registerUploadAgentFile();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// =========================================================================
	// Ability Registration
	// =========================================================================

	private function registerListAgentFiles(): void {
		wp_register_ability(
			'datamachine/list-agent-files',
			array(
				'label'               => __( 'List Agent Files', 'data-machine' ),
				'description'         => __( 'List memory files from all layers (shared, agent, user).', 'data-machine' ),
				'category'            => 'datamachine-memory',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping. Defaults to 0 (resolved to default agent).', 'data-machine' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListAgentFiles' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetAgentFile(): void {
		wp_register_ability(
			'datamachine/get-agent-file',
			array(
				'label'               => __( 'Get Agent File', 'data-machine' ),
				'description'         => __( 'Get a single agent memory file with content.', 'data-machine' ),
				'category'            => 'datamachine-memory',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to retrieve', 'data-machine' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'file'    => array( 'type' => 'object' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerWriteAgentFile(): void {
		wp_register_ability(
			'datamachine/write-agent-file',
			array(
				'label'               => __( 'Write Agent File', 'data-machine' ),
				'description'         => __( 'Write or update content for a memory file. Layer is resolved from the registry, or can be explicitly specified.', 'data-machine' ),
				'category'            => 'datamachine-memory',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'content' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the memory file to write', 'data-machine' ),
						),
						'content'  => array(
							'type'        => 'string',
							'description' => __( 'Content to write to the file', 'data-machine' ),
						),
						'layer'    => array(
							'type'        => 'string',
							'description' => __( 'Target layer: shared, agent, or user. For registered files, defaults to the registered layer. For new files, defaults to agent.', 'data-machine' ),
							'enum'        => array( 'shared', 'agent', 'user' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'filename' => array( 'type' => 'string' ),
						'layer'    => array( 'type' => 'string' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeWriteAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerDeleteAgentFile(): void {
		wp_register_ability(
			'datamachine/delete-agent-file',
			array(
				'label'               => __( 'Delete Agent File', 'data-machine' ),
				'description'         => __( 'Delete a memory file. Protected files cannot be deleted.', 'data-machine' ),
				'category'            => 'datamachine-memory',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Name of the file to delete', 'data-machine' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeDeleteAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUploadAgentFile(): void {
		wp_register_ability(
			'datamachine/upload-agent-file',
			array(
				'label'               => __( 'Upload Agent File', 'data-machine' ),
				'description'         => __( 'Upload a file to a memory layer directory.', 'data-machine' ),
				'category'            => 'datamachine-memory',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'file_data' ),
					'properties' => array(
						'file_data' => array(
							'type'        => 'object',
							'description' => __( 'File data array with name, type, tmp_name, error, size', 'data-machine' ),
							'properties'  => array(
								'name'     => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string' ),
								'tmp_name' => array( 'type' => 'string' ),
								'error'    => array( 'type' => 'integer' ),
								'size'     => array( 'type' => 'integer' ),
							),
						),
						'layer'     => array(
							'type'        => 'string',
							'description' => __( 'Target layer for the uploaded file. Default agent.', 'data-machine' ),
							'enum'        => array( 'shared', 'agent', 'user' ),
						),
						'user_id'   => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID for multi-agent scoping.', 'data-machine' ),
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'files'   => array( 'type' => 'array' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUploadAgentFile' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	// =========================================================================
	// Permission
	// =========================================================================

	/**
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	// =========================================================================
	// Execute callbacks
	// =========================================================================

	/**
	 * List agent memory files from all layers.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with files list.
	 */
	public function executeListAgentFiles( array $input ): array {
		DirectoryManager::ensure_agent_files();

		$dm       = new DirectoryManager();
		$user_id  = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );

		$files = array();
		$seen  = array();

		// First, include convention-path / registered files via the store.
		// Convention-path files (e.g. AGENTS.md at site root) live outside
		// the layer directories but should still appear in the file list.
		foreach ( MemoryFileRegistry::get_all() as $filename => $registry_meta ) {
			$layer = $registry_meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
			$scope = new AgentMemoryScope( $layer, $user_id, $agent_id, $filename );
			$store = AgentMemoryStoreFactory::for_scope( $scope );
			$read  = $store->read( $scope );

			if ( ! $read->exists ) {
				continue;
			}

			$files[]           = array(
				'filename'    => $filename,
				'size'        => $read->bytes,
				'modified'    => null !== $read->updated_at ? gmdate( 'c', $read->updated_at ) : '',
				'type'        => 'core',
				'layer'       => $layer,
				'protected'   => MemoryFileRegistry::is_protected( $filename ),
				'editable'    => MemoryFileRegistry::is_editable( $filename ),
				'contexts'    => $registry_meta['contexts'] ?? array( MemoryFileRegistry::CONTEXT_ALL ),
				'registered'  => true,
				'label'       => $registry_meta['label'] ?? self::filename_to_label( $filename ),
				'description' => $registry_meta['description'] ?? '',
			);
			$seen[ $filename ] = true;
		}

		// Enumerate each layer through the store. Agent layer wins on
		// filename conflicts with user layer; shared layer is always included.
		$layer_order = array(
			MemoryFileRegistry::LAYER_SHARED,
			MemoryFileRegistry::LAYER_AGENT,
			MemoryFileRegistry::LAYER_USER,
		);

		foreach ( $layer_order as $layer ) {
			$scope_query = new AgentMemoryScope( $layer, $user_id, $agent_id, '' );
			$store       = AgentMemoryStoreFactory::for_scope( $scope_query );

			foreach ( $store->list_layer( $scope_query ) as $entry ) {
				if ( isset( $seen[ $entry->filename ] ) ) {
					continue;
				}

				$registry_meta = MemoryFileRegistry::get( $entry->filename );
				$files[]       = array(
					'filename'    => $entry->filename,
					'size'        => $entry->bytes,
					'modified'    => null !== $entry->updated_at ? gmdate( 'c', $entry->updated_at ) : '',
					'type'        => 'core',
					'layer'       => $registry_meta ? $registry_meta['layer'] : $layer,
					'protected'   => MemoryFileRegistry::is_protected( $entry->filename ),
					'editable'    => MemoryFileRegistry::is_editable( $entry->filename ),
					'contexts'    => $registry_meta['contexts'] ?? array( MemoryFileRegistry::CONTEXT_ALL ),
					'registered'  => null !== $registry_meta,
					'label'       => $registry_meta['label'] ?? self::filename_to_label( $entry->filename ),
					'description' => $registry_meta['description'] ?? '',
				);
				$seen[ $entry->filename ] = true;
			}
		}

		// Include context memory files.
		$agent_context = array(
			'agent_id' => (int) ( $input['agent_id'] ?? 0 ),
			'user_id'  => $user_id,
		);
		$contexts_dir  = $dm->get_contexts_directory( $agent_context );

		if ( is_dir( $contexts_dir ) ) {
			foreach ( glob( trailingslashit( $contexts_dir ) . '*.md' ) as $filepath ) {
				$filename = basename( $filepath );
				$slug     = pathinfo( $filename, PATHINFO_FILENAME );
				$files[]  = array(
					'filename'     => $filename,
					'size'         => filesize( $filepath ),
					'modified'     => gmdate( 'c', filemtime( $filepath ) ),
					'type'         => 'context',
					'layer'        => 'context',
					'protected'    => false,
					'editable'     => true,
					'registered'   => false,
					'label'        => ucfirst( $slug ) . ' Context',
					'description'  => "Context-scoped instructions loaded when execution context is '{$slug}'.",
					'context_slug' => $slug,
				);
			}
		}

		// Include daily memory summary.
		$daily        = new DailyMemory( $user_id, (int) ( $input['agent_id'] ?? 0 ) );
		$daily_result = $daily->list_all();

		if ( ! empty( $daily_result['months'] ) ) {
			$total_days = 0;
			foreach ( $daily_result['months'] as $days ) {
				$total_days += count( $days );
			}

			$files[] = array(
				'filename'    => 'daily',
				'type'        => 'daily_summary',
				'month_count' => count( $daily_result['months'] ),
				'day_count'   => $total_days,
				'months'      => $daily_result['months'],
			);
		}

		return array(
			'success' => true,
			'files'   => array_map( array( $this, 'sanitizeFileEntry' ), $files ),
		);
	}

	/**
	 * Get a single agent file with content.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with file data.
	 */
	public function executeGetAgentFile( array $input ): array {
		DirectoryManager::ensure_agent_files();

		$filename = sanitize_file_name( $input['filename'] ?? '' );
		$dm       = new DirectoryManager();
		$user_id  = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );

		$resolved = $this->resolveScope( $filename, $user_id, $agent_id );

		if ( null === $resolved ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found in any layer', $filename ),
			);
		}

		[ $scope, $store, $read ] = $resolved;

		return array(
			'success' => true,
			'file'    => $this->sanitizeFileEntry(
				array(
					'filename' => $filename,
					'size'     => $read->bytes,
					'modified' => null !== $read->updated_at ? gmdate( 'c', $read->updated_at ) : '',
					'content'  => $read->content,
				)
			),
		);
	}

	/**
	 * Write content to a memory file.
	 *
	 * Layer resolution order:
	 * 1. Explicit `layer` parameter (if provided)
	 * 2. Registry layer (if file is registered)
	 * 3. Default: agent layer
	 *
	 * @param array $input Input parameters.
	 * @return array Result with write status.
	 */
	public function executeWriteAgentFile( array $input ): array {
		$filename = sanitize_file_name( $input['filename'] ?? '' );
		$content  = $input['content'] ?? '';

		// Editability gate: check before any write.
		$user_id_for_edit = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id_for_edit <= 0 ) {
			$user_id_for_edit = PermissionHelper::acting_user_id();
		}

		if ( ! MemoryFileRegistry::is_editable( $filename, $user_id_for_edit ) ) {
			$edit_cap = MemoryFileRegistry::get_edit_capability( $filename );
			if ( false === $edit_cap ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'File %s is read-only and cannot be edited.', $filename ),
				);
			}
			return array(
				'success' => false,
				'error'   => sprintf( 'You do not have permission to edit %s. Required capability: %s', $filename, $edit_cap ),
			);
		}

		if ( MemoryFileRegistry::is_protected( $filename ) && '' === trim( $content ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot write empty content to protected file: %s', $filename ),
			);
		}

		// Resolve target layer.
		$explicit_layer = $input['layer'] ?? null;
		$registry_layer = MemoryFileRegistry::get_layer( $filename );
		$target_layer   = $explicit_layer ?? $registry_layer ?? MemoryFileRegistry::LAYER_AGENT;

		$dm      = new DirectoryManager();
		$user_id = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );

		$scope = new AgentMemoryScope(
			$target_layer,
			$user_id,
			(int) ( $input['agent_id'] ?? 0 ),
			$filename
		);
		$store = AgentMemoryStoreFactory::for_scope( $scope );

		$write = $store->write( $scope, $content );

		if ( ! $write->success ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to write file (%s)', $write->error ?? 'unknown' ),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent file written via ability',
			array(
				'filename' => $filename,
				'layer'    => $target_layer,
			)
		);

		return array(
			'success'  => true,
			'filename' => $filename,
			'layer'    => $target_layer,
		);
	}

	/**
	 * Delete a memory file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeleteAgentFile( array $input ): array {
		$filename = sanitize_file_name( $input['filename'] ?? '' );

		if ( MemoryFileRegistry::is_protected( $filename ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Cannot delete protected file: %s', $filename ),
			);
		}

		$dm       = new DirectoryManager();
		$user_id  = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$resolved = $this->resolveScope( $filename, $user_id, $agent_id );

		if ( null === $resolved ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File %s not found in any layer', $filename ),
			);
		}

		[ $scope, $store, $read ] = $resolved;
		unset( $read );

		$delete = $store->delete( $scope );

		if ( ! $delete->success ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to delete file (%s)', $delete->error ?? 'unknown' ),
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent file deleted via ability',
			array(
				'filename' => $filename,
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		return array(
			'success' => true,
			'message' => sprintf( 'File %s deleted', $filename ),
		);
	}

	/**
	 * Upload a file to a memory layer.
	 *
	 * Reads the uploaded tmp file into memory and persists it through the
	 * configured store, so the upload path works on any backend (disk or
	 * DB) and not just on writable filesystems.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with updated file list.
	 */
	public function executeUploadAgentFile( array $input ): array {
		$file = $input['file_data'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No file data provided',
			);
		}

		$dm           = new DirectoryManager();
		$user_id      = $dm->get_effective_user_id( (int) ( $input['user_id'] ?? 0 ) );
		$agent_id     = (int) ( $input['agent_id'] ?? 0 );
		$target_layer = $input['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
		$filename     = sanitize_file_name( (string) $file['name'] );

		// Read tmp content via the WP filesystem helper. The uploaded
		// tmp_name lives on the local FS (PHP's upload mechanism); we then
		// hand bytes off to the store, which decides where they land.
		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return array(
				'success' => false,
				'error'   => 'Filesystem not available',
			);
		}

		$content = $fs->get_contents( $file['tmp_name'] );
		if ( false === $content ) {
			return array(
				'success' => false,
				'error'   => 'Failed to read uploaded file',
			);
		}

		$scope = new AgentMemoryScope( $target_layer, $user_id, $agent_id, $filename );
		$store = AgentMemoryStoreFactory::for_scope( $scope );

		$write = $store->write( $scope, (string) $content );
		if ( ! $write->success ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to store file (%s)', $write->error ?? 'unknown' ),
			);
		}

		// Return updated file list.
		return $this->executeListAgentFiles( array(
			'user_id'  => $user_id,
			'agent_id' => $agent_id,
		) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolve a filename to a (scope, store, read-result) triple by
	 * trying the registered layer first, then falling back agent → user
	 * → shared.
	 *
	 * Returns null if the file does not exist in any layer.
	 *
	 * @since next Replaces the disk-only resolveFilePath helper.
	 *
	 * @param string $filename Filename to resolve.
	 * @param int    $user_id  Effective user ID.
	 * @param int    $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 * @return array{0: AgentMemoryScope, 1: \DataMachine\Core\FilesRepository\AgentMemoryStoreInterface, 2: \DataMachine\Core\FilesRepository\AgentMemoryReadResult}|null
	 */
	private function resolveScope( string $filename, int $user_id, int $agent_id ): ?array {
		$layer_order = array();

		// If file is registered, check its canonical layer first.
		$registered_layer = MemoryFileRegistry::get_layer( $filename );
		if ( $registered_layer ) {
			$layer_order[] = $registered_layer;
		}

		// Fallback: check remaining layers (agent → user → shared) without dupes.
		foreach ( array( MemoryFileRegistry::LAYER_AGENT, MemoryFileRegistry::LAYER_USER, MemoryFileRegistry::LAYER_SHARED ) as $layer ) {
			if ( ! in_array( $layer, $layer_order, true ) ) {
				$layer_order[] = $layer;
			}
		}

		foreach ( $layer_order as $layer ) {
			$scope = new AgentMemoryScope( $layer, $user_id, $agent_id, $filename );
			$store = AgentMemoryStoreFactory::for_scope( $scope );
			$read  = $store->read( $scope );

			if ( $read->exists ) {
				return array( $scope, $store, $read );
			}
		}

		return null;
	}

	/**
	 * Resolve a layer identifier to its directory path.
	 *
	 * @param DirectoryManager $dm        Directory manager instance.
	 * @param string           $layer     Layer identifier ('shared', 'agent', 'user', 'network').
	 * @param int              $user_id   Effective user ID.
	 * @param int              $agent_id  Agent ID.
	 * @return string Directory path.
	 */
	private function resolveLayerDirectory( DirectoryManager $dm, string $layer, int $user_id, int $agent_id = 0 ): string {
		switch ( $layer ) {
			case MemoryFileRegistry::LAYER_SHARED:
				return $dm->get_shared_directory();
			case MemoryFileRegistry::LAYER_USER:
				return $dm->get_user_directory( $user_id );
			case MemoryFileRegistry::LAYER_NETWORK:
				return $dm->get_network_directory();
			case MemoryFileRegistry::LAYER_AGENT:
			default:
				return $dm->resolve_agent_directory( array(
					'agent_id' => $agent_id,
					'user_id'  => $user_id,
				) );
		}
	}

	/**
	 * Normalize and escape file response entry.
	 *
	 * @param array $file File data.
	 * @return array Sanitized file data.
	 */
	private function sanitizeFileEntry( array $file ): array {
		$sanitized = $file;

		if ( isset( $sanitized['filename'] ) ) {
			$sanitized['filename'] = sanitize_file_name( $sanitized['filename'] );
		}

		if ( isset( $sanitized['original_name'] ) ) {
			$sanitized['original_name'] = sanitize_file_name( $sanitized['original_name'] );
		}

		if ( isset( $sanitized['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $sanitized['content'] );
		}

		return $sanitized;
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
