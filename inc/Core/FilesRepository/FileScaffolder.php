<?php
/**
 * File Scaffolder
 *
 * Single entry point for creating missing memory files across all layers.
 * Content generators are registered via the `datamachine_scaffold_content`
 * filter, keyed by filename. Each generator receives a context array and
 * returns the default file content (or empty string to skip).
 *
 * Usage:
 *   FileScaffolder::ensure( 'USER.md', [ 'user_id' => 34 ] );
 *   FileScaffolder::ensure( 'SOUL.md', [ 'agent_slug' => 'studio' ] );
 *   FileScaffolder::ensure_layer( 'user', [ 'user_id' => 34 ] );
 *   FileScaffolder::ensure_at( '/path/to/daily/2026/03/20.md', 'daily/2026/03/20.md', $ctx );
 *
 * @package DataMachine\Core\FilesRepository
 * @since   0.50.0
 */

namespace DataMachine\Core\FilesRepository;

use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class FileScaffolder {

	/**
	 * Ensure a registered memory file exists.
	 *
	 * Resolves the file's layer directory from context, generates default
	 * content via the `datamachine_scaffold_content` filter, and writes
	 * the file. Never overwrites existing files.
	 *
	 * @param string $filename Registered filename (e.g. 'USER.md', 'SOUL.md').
	 * @param array  $context  {
	 *     Scaffolding context. Keys vary by layer.
	 *
	 *     @type int    $user_id    WordPress user ID (for user-layer files).
	 *     @type string $agent_slug Agent slug (for agent-layer files).
	 *     @type int    $agent_id   Agent ID (for agent-layer files, resolved to slug).
	 * }
	 * @return bool True if file was created, false if it already existed or generation failed.
	 */
	public static function ensure( string $filename, array $context = array() ): bool {
		$meta = MemoryFileRegistry::get( $filename );
		if ( ! $meta ) {
			return false;
		}

		$directory = self::resolve_directory( $meta['layer'], $context );
		if ( ! $directory ) {
			return false;
		}

		$filepath = trailingslashit( $directory ) . $filename;

		if ( file_exists( $filepath ) ) {
			return false;
		}

		$content = self::generate_content( $filename, $context );
		if ( '' === $content ) {
			return false;
		}

		return self::write_file( $filepath, $directory, $content, $filename, $context );
	}

	/**
	 * Ensure a file exists at an explicit path.
	 *
	 * For dynamic files not in the MemoryFileRegistry (e.g. daily memory
	 * files at agent/daily/YYYY/MM/DD.md). Content generation still uses
	 * the `datamachine_scaffold_content` filter with the logical filename.
	 *
	 * @param string $filepath         Full filesystem path for the file.
	 * @param string $logical_filename Logical name passed to the content filter
	 *                                 (e.g. 'daily/2026/03/20.md').
	 * @param array  $context          Scaffolding context.
	 * @return bool True if file was created.
	 */
	public static function ensure_at( string $filepath, string $logical_filename, array $context = array() ): bool {
		if ( file_exists( $filepath ) ) {
			return false;
		}

		$content = self::generate_content( $logical_filename, $context );
		if ( '' === $content ) {
			return false;
		}

		$directory = dirname( $filepath );
		return self::write_file( $filepath, $directory, $content, $logical_filename, $context );
	}

	/**
	 * Ensure all registered files for a given layer exist.
	 *
	 * @param string $layer   Layer identifier ('shared', 'agent', 'user', 'network').
	 * @param array  $context Scaffolding context (same as ensure()).
	 * @return int Number of files created.
	 */
	public static function ensure_layer( string $layer, array $context = array() ): int {
		$files   = MemoryFileRegistry::get_by_layer( $layer );
		$created = 0;

		foreach ( $files as $filename => $meta ) {
			if ( self::ensure( $filename, $context ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Generate default content for a file via the filter chain.
	 *
	 * @param string $filename Filename (or logical path for dynamic files).
	 * @param array  $context  Scaffolding context.
	 * @return string Generated content, or empty string if no generator registered.
	 */
	public static function generate_content( string $filename, array $context = array() ): string {
		/**
		 * Filter the scaffold content for a memory file.
		 *
		 * Content generators register on this filter and return content
		 * when the filename matches their responsibility. Return empty
		 * string to skip file creation.
		 *
		 * @since 0.50.0
		 *
		 * @param string $content  Default content (empty string).
		 * @param string $filename The filename being scaffolded (e.g. 'USER.md',
		 *                         'SOUL.md', or 'daily/2026/03/20.md').
		 * @param array  $context  Scaffolding context (user_id, agent_slug, date, etc.).
		 */
		$content = apply_filters( 'datamachine_scaffold_content', '', $filename, $context );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Resolve the target directory for a layer + context combination.
	 *
	 * @param string $layer   Layer identifier.
	 * @param array  $context Scaffolding context.
	 * @return string|null Directory path, or null if unresolvable.
	 */
	public static function resolve_directory( string $layer, array $context ): ?string {
		$dm = new DirectoryManager();

		switch ( $layer ) {
			case MemoryFileRegistry::LAYER_SHARED:
				return $dm->get_shared_directory();

			case MemoryFileRegistry::LAYER_NETWORK:
				return $dm->get_network_directory();

			case MemoryFileRegistry::LAYER_USER:
				$user_id = (int) ( $context['user_id'] ?? 0 );
				if ( $user_id <= 0 ) {
					return null;
				}
				return $dm->get_user_directory( $user_id );

			case MemoryFileRegistry::LAYER_AGENT:
				$agent_slug = $context['agent_slug'] ?? null;
				if ( $agent_slug ) {
					return $dm->get_agent_identity_directory( $agent_slug );
				}
				$agent_id = (int) ( $context['agent_id'] ?? 0 );
				if ( $agent_id > 0 ) {
					$slug = $dm->resolve_agent_slug( array( 'agent_id' => $agent_id ) );
					return $dm->get_agent_identity_directory( $slug );
				}
				$user_id = (int) ( $context['user_id'] ?? 0 );
				return $dm->get_agent_identity_directory_for_user( $user_id );

			default:
				return null;
		}
	}

	/**
	 * Write a scaffolded file to disk.
	 *
	 * @param string $filepath  Full file path.
	 * @param string $directory Parent directory.
	 * @param string $content   File content.
	 * @param string $filename  Filename for logging.
	 * @param array  $context   Scaffolding context for logging.
	 * @return bool True on success.
	 */
	private static function write_file( string $filepath, string $directory, string $content, string $filename, array $context ): bool {
		$dm = new DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $directory ) ) {
			return false;
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return false;
		}

		$fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );
		FilesystemHelper::make_group_writable( $filepath );

		// Protect directory from listing.
		$index_path = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			$fs->put_contents( $index_path, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			FilesystemHelper::make_group_writable( $index_path );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Scaffolded %s from default content generator.', $filename ),
			array(
				'filename' => $filename,
				'filepath' => $filepath,
				'context'  => array_filter( $context ),
			)
		);

		return true;
	}
}
