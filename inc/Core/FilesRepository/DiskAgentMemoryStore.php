<?php
/**
 * Disk Agent Memory Store
 *
 * Default implementation of {@see WP_Agent_Memory_Store} that persists
 * agent memory records as markdown files on the local filesystem under
 * wp-uploads. Preserves the byte-for-byte behavior the codebase used before
 * the store seam was introduced.
 *
 * This name describes the current Data Machine implementation. In a future
 * Agents API extraction, the same behavior may be documented as a markdown or
 * local-file memory store; no public rename happens in this PR.
 *
 * Concurrency: this implementation does not implement compare-and-swap.
 * The `$if_match` parameter on write() is accepted but ignored — the
 * single-host disk model historically relied on infrequent collisions.
 * Callers requiring CAS should use a store that supports it.
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class DiskAgentMemoryStore implements WP_Agent_Memory_Store {

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	public function __construct( ?DirectoryManager $directory_manager = null ) {
		$this->directory_manager = $directory_manager ?? new DirectoryManager();
	}

	/**
	 * @inheritDoc
	 */
	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	/**
	 * @inheritDoc
	 */
	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		$filepath = $this->resolve_filepath( $scope );

		if ( ! file_exists( $filepath ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content    = (string) $fs->get_contents( $filepath );
		$bytes      = strlen( $content );
		$updated_at = filemtime( $filepath );

		return new WP_Agent_Memory_Read_Result(
			true,
			$content,
			sha1( $content ),
			$bytes,
			false === $updated_at ? null : (int) $updated_at,
			null,
			$this->capabilities()->unsupported_metadata_fields( $metadata_fields, 'read' )
		);
	}

	/**
	 * @inheritDoc
	 *
	 * `$if_match` is intentionally ignored — see class docblock.
	 */
	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		$filepath = $this->resolve_filepath( $scope );
		$dir      = dirname( $filepath );

		if ( ! $this->directory_manager->ensure_directory_exists( $dir ) ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		$ok = $fs->put_contents( $filepath, $content, FS_CHMOD_FILE );

		if ( false === $ok ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		FilesystemHelper::make_group_writable( $filepath );

		$bytes = strlen( $content );
		return WP_Agent_Memory_Write_Result::ok(
			sha1( $content ),
			$bytes,
			null,
			null === $metadata ? array() : $this->capabilities()->unsupported_metadata_fields( array_keys( $metadata->to_array() ), 'persist' )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		return file_exists( $this->resolve_filepath( $scope ) );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		$filepath = $this->resolve_filepath( $scope );

		if ( ! file_exists( $filepath ) ) {
			// Idempotent: deleting a missing file is success.
			return WP_Agent_Memory_Write_Result::ok( '', 0 );
		}

		$deleted = wp_delete_file( $filepath );

		if ( ! $deleted ) {
			return WP_Agent_Memory_Write_Result::failure( 'io' );
		}

		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		$layer_dir = $this->resolve_layer_directory( $scope_query );

		if ( ! is_dir( $layer_dir ) ) {
			return array();
		}

		$entries = array();

		foreach ( array_diff( scandir( $layer_dir ), array( '.', '..' ) ) as $entry ) {
			if ( 'index.php' === $entry ) {
				continue;
			}

			$path = $layer_dir . '/' . $entry;

			if ( ! is_file( $path ) ) {
				continue;
			}

			$mtime     = filemtime( $path );
			$entries[] = new WP_Agent_Memory_List_Entry(
				$entry,
				$scope_query->layer,
				(int) filesize( $path ),
				false === $mtime ? null : (int) $mtime,
				null,
				$this->unsupported_query_fields( $query )
			);
		}

		return $entries;
	}

	/**
	 * @inheritDoc
	 *
	 * Walks the filesystem recursively under `{layer_dir}/{prefix}/`.
	 * Returned filenames are relative paths from the layer root,
	 * including the prefix (e.g. `daily/2026/04/17.md`).
	 */
	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			return array();
		}

		$layer_dir   = $this->resolve_layer_directory( $scope_query );
		$subtree_dir = $layer_dir . '/' . $prefix;

		if ( ! is_dir( $subtree_dir ) ) {
			return array();
		}

		$entries  = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $subtree_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$path = $file_info->getPathname();
			if ( 'index.php' === basename( $path ) ) {
				continue;
			}

			// Compute filename relative to the layer root (with prefix).
			$relative = ltrim( substr( $path, strlen( $layer_dir ) ), '/' );

			$mtime     = $file_info->getMTime();
			$entries[] = new WP_Agent_Memory_List_Entry(
				$relative,
				$scope_query->layer,
				(int) $file_info->getSize(),
				false === $mtime ? null : (int) $mtime,
				null,
				$this->unsupported_query_fields( $query )
			);
		}

		// Stable ordering — callers can rely on filename-sorted output.
		usort(
			$entries,
			static fn( $a, $b ) => strcmp( $a->filename, $b->filename )
		);

		return $entries;
	}

	/**
	 * @return string[]
	 */
	private function unsupported_query_fields( ?WP_Agent_Memory_Query $query ): array {
		if ( null === $query ) {
			return array();
		}

		$capabilities = $this->capabilities();
		return array_values( array_unique( array_merge(
			$capabilities->unsupported_metadata_fields( $query->metadata_fields, 'read' ),
			$capabilities->unsupported_metadata_fields( $query->filter_fields(), 'filter' ),
			null === $query->order_by ? array() : $capabilities->unsupported_metadata_fields( array( $query->order_by ), 'rank' )
		) ) );
	}

	// =========================================================================
	// Internal path resolution
	// =========================================================================

	/**
	 * Resolve a scope to its absolute filesystem path, honoring registered
	 * convention paths (e.g. AGENTS.md at ABSPATH).
	 */
	private function resolve_filepath( WP_Agent_Memory_Scope $scope ): string {
		$layer_dir = $this->resolve_layer_directory( $scope );

		// Convention-path files (e.g. AGENTS.md) override the layer directory.
		$convention = MemoryFileRegistry::resolve_filepath( $scope->filename, $layer_dir );
		if ( null !== $convention ) {
			return $convention;
		}

		return $layer_dir . '/' . ltrim( $scope->filename, '/' );
	}

	/**
	 * Resolve the on-disk directory for a given (layer, user_id, agent_id).
	 */
	private function resolve_layer_directory( WP_Agent_Memory_Scope $scope ): string {
		switch ( $scope->layer ) {
			case MemoryFileRegistry::LAYER_SHARED:
				return $this->directory_manager->get_shared_directory();
			case MemoryFileRegistry::LAYER_USER:
				return $this->directory_manager->get_user_directory( $scope->user_id );
			case MemoryFileRegistry::LAYER_NETWORK:
				return $this->directory_manager->get_network_directory();
			case MemoryFileRegistry::LAYER_AGENT:
			default:
				return $this->directory_manager->resolve_agent_directory( array(
					'agent_id' => $scope->agent_id,
					'user_id'  => $scope->user_id,
				) );
		}
	}
}
