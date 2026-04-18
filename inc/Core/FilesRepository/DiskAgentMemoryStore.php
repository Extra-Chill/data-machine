<?php
/**
 * Disk Agent Memory Store
 *
 * Default implementation of {@see AgentMemoryStoreInterface} that persists
 * agent memory files on the local filesystem under wp-uploads. Preserves
 * the byte-for-byte behavior the codebase used before the store seam was
 * introduced.
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

use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class DiskAgentMemoryStore implements AgentMemoryStoreInterface {

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
	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult {
		$filepath = $this->resolve_filepath( $scope );

		if ( ! file_exists( $filepath ) ) {
			return AgentMemoryReadResult::not_found();
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return AgentMemoryReadResult::not_found();
		}

		$content    = (string) $fs->get_contents( $filepath );
		$bytes      = strlen( $content );
		$updated_at = filemtime( $filepath );

		return new AgentMemoryReadResult(
			true,
			$content,
			sha1( $content ),
			$bytes,
			false === $updated_at ? null : (int) $updated_at,
		);
	}

	/**
	 * @inheritDoc
	 *
	 * `$if_match` is intentionally ignored — see class docblock.
	 */
	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null ): AgentMemoryWriteResult {
		$filepath = $this->resolve_filepath( $scope );
		$dir      = dirname( $filepath );

		if ( ! $this->directory_manager->ensure_directory_exists( $dir ) ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		$ok = $fs->put_contents( $filepath, $content, FS_CHMOD_FILE );

		if ( false === $ok ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		FilesystemHelper::make_group_writable( $filepath );

		$bytes = strlen( $content );
		return AgentMemoryWriteResult::ok( sha1( $content ), $bytes );
	}

	/**
	 * @inheritDoc
	 */
	public function exists( AgentMemoryScope $scope ): bool {
		return file_exists( $this->resolve_filepath( $scope ) );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult {
		$filepath = $this->resolve_filepath( $scope );

		if ( ! file_exists( $filepath ) ) {
			// Idempotent: deleting a missing file is success.
			return AgentMemoryWriteResult::ok( '', 0 );
		}

		$deleted = wp_delete_file( $filepath );

		// wp_delete_file returns void; verify by re-checking existence.
		if ( file_exists( $filepath ) ) {
			return AgentMemoryWriteResult::failure( 'io' );
		}

		unset( $deleted );
		return AgentMemoryWriteResult::ok( '', 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function list_layer( AgentMemoryScope $scope_query ): array {
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
			$entries[] = new AgentMemoryListEntry(
				$entry,
				$scope_query->layer,
				(int) filesize( $path ),
				false === $mtime ? null : (int) $mtime,
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
	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array {
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
			$entries[] = new AgentMemoryListEntry(
				$relative,
				$scope_query->layer,
				(int) $file_info->getSize(),
				false === $mtime ? null : (int) $mtime,
			);
		}

		// Stable ordering — callers can rely on filename-sorted output.
		usort(
			$entries,
			static fn( $a, $b ) => strcmp( $a->filename, $b->filename )
		);

		return $entries;
	}

	// =========================================================================
	// Internal path resolution
	// =========================================================================

	/**
	 * Resolve a scope to its absolute filesystem path, honoring registered
	 * convention paths (e.g. AGENTS.md at ABSPATH).
	 */
	private function resolve_filepath( AgentMemoryScope $scope ): string {
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
	private function resolve_layer_directory( AgentMemoryScope $scope ): string {
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
