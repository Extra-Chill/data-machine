<?php
/**
 * Composable File Generator
 *
 * Regenerates composable memory files from their registered sections.
 * Works with SectionRegistry for content and MemoryFileRegistry for
 * file metadata (layer, convention_path).
 *
 * @package DataMachine\Engine\AI
 * @since   0.66.0
 */

namespace DataMachine\Engine\AI;

use DataMachine\Abilities\File\ScaffoldAbilities;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;

defined( 'ABSPATH' ) || exit;

class ComposableFileGenerator {

	/**
	 * Regenerate a single composable file from its registered sections.
	 *
	 * Generates content via SectionRegistry. For files with a `convention_path`
	 * the file is written ONLY at `ABSPATH + convention_path` (since v0.67.0);
	 * any pre-v0.67.0 copy in the layer directory is removed so consumers don't
	 * read a frozen snapshot. Files without a `convention_path` write to their
	 * layer directory as before.
	 *
	 * @since 0.66.0
	 * @since 0.67.0 Convention-path files write only to the convention path.
	 * @since x.y.z  Removes pre-v0.67.0 layer-dir copies during regenerate.
	 *
	 * @param string $filename Composable filename (e.g. 'AGENTS.md').
	 * @param array  $context  {
	 *     Generation context.
	 *
	 *     @type int    $user_id    WordPress user ID.
	 *     @type string $agent_slug Agent slug.
	 *     @type int    $agent_id   Agent ID.
	 * }
	 * @return array{success: bool, message: string, filepath?: string, stale_removed?: string}
	 */
	public static function regenerate( string $filename, array $context = array() ): array {
		$meta = MemoryFileRegistry::get( $filename );

		if ( ! $meta ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File "%s" is not registered in the MemoryFileRegistry.', $filename ),
			);
		}

		if ( empty( $meta['composable'] ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File "%s" is not marked as composable.', $filename ),
			);
		}

		if ( ! SectionRegistry::has_sections( $filename ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'No sections registered for "%s".', $filename ),
			);
		}

		$target = self::resolve_target( $filename, $meta, $context );
		if ( ! $target['success'] ) {
			return $target;
		}

		$directory = $target['directory'];
		$filepath  = $target['filepath'];
		$dm        = new DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $directory ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Could not create composition directory for "%s".', $filename ),
			);
		}

		$acquisition = ComposableFileLock::acquire( $filename, $filepath );
		if ( ! $acquisition['acquired'] || ! $acquisition['lock'] instanceof ComposableFileLock ) {
			return array(
				'success'    => false,
				'error_code' => 'composition_locked',
				'message'    => sprintf( 'Composition lock unavailable for "%s" after 2 seconds.', $filename ),
				'blocker'    => $acquisition['diagnostic'],
			);
		}

		$lock = $acquisition['lock'];
		try {
			// A root convention path is shared by every site in a multisite install.
			// Persist each site's rendered ownership snapshot before aggregating so a
			// request-local plugin set cannot erase another site's valid sections.
			if ( ! empty( $meta['convention_path'] ) && is_multisite() ) {
				$content = MultisiteSectionAggregator::compose( $filename, $context );
			} else {
				$content = SectionRegistry::generate( $filename, $context );
			}

			if ( '' === trim( $content ) ) {
				return array(
					'success' => false,
					'message' => sprintf( 'All sections returned empty content for "%s".', $filename ),
				);
			}

			$stale_layer_path = '';
			if ( ! empty( $meta['convention_path'] ) ) {
				// Pre-v0.67.0 versions wrote to the layer dir; resolving that
				// directory must not make best-effort cleanup fail composition.
				$layer_dir = ScaffoldAbilities::resolve_directory( $meta['layer'], $context );
				if ( $layer_dir ) {
					$candidate = trailingslashit( $layer_dir ) . $filename;
					if ( $candidate !== $filepath && file_exists( $candidate ) ) {
						$stale_layer_path = $candidate;
					}
				}
			}

			$written = self::write_file( $filepath, $directory, $content );

			if ( ! $written ) {
				return array(
					'success' => false,
					'message' => sprintf( 'Failed to write %s to disk.', $filename ),
				);
			}

			// Best-effort cleanup only targets the dead layer-directory copy.
			$stale_removed = '';
			if ( '' !== $stale_layer_path ) {
				$fs = FilesystemHelper::get();
				if ( $fs && $fs->delete( $stale_layer_path ) ) {
					$stale_removed = $stale_layer_path;
				}
			}

			/**
			 * Fires after a composable file has been regenerated.
			 *
			 * @since 0.67.0 Convention-path files now write only to the convention path.
			 * @since 0.66.0
			 *
			 * @param string $filename Composable filename.
			 * @param string $filepath Full path where the file was written.
			 * @param array  $context  Generation context.
			 */
			do_action( 'datamachine_composable_regenerated', $filename, $filepath, $context );

			$message = sprintf( 'Regenerated %s at %s (%d sections).', $filename, $filepath, count( SectionRegistry::get_sections( $filename ) ) );
			if ( '' !== $stale_removed ) {
				$message .= sprintf( ' Removed stale layer-dir copy at %s.', $stale_removed );
			}

			return array(
				'success'       => true,
				'message'       => $message,
				'filepath'      => $filepath,
				'stale_removed' => $stale_removed,
			);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Return bounded composition-lock diagnostics without acquiring the lock.
	 *
	 * @param string $filename Composable filename.
	 * @param array  $context  Generation context.
	 * @return array<string,int|string|bool>
	 */
	public static function lock_status( string $filename, array $context = array() ): array {
		$meta = MemoryFileRegistry::get( $filename );
		if ( ! $meta || empty( $meta['composable'] ) ) {
			return array(
				'type'        => 'composable_file_lock',
				'lock_status' => 'unregistered',
				'filename'    => $filename,
			);
		}

		$target = self::resolve_target( $filename, $meta, $context );
		if ( ! $target['success'] ) {
			return array(
				'type'        => 'composable_file_lock',
				'lock_status' => 'unavailable',
				'filename'    => $filename,
			);
		}

		return ComposableFileLock::snapshot( $filename, $target['filepath'] );
	}

	/**
	 * Regenerate all composable files.
	 *
	 * @since 0.66.0
	 *
	 * @param array $context Generation context.
	 * @return array{success: bool, message: string, results: array}
	 */
	public static function regenerate_all( array $context = array() ): array {
		$composable  = MemoryFileRegistry::get_composable();
		$results     = array();
		$regenerated = 0;

		foreach ( $composable as $filename => $meta ) {
			$result    = self::regenerate( $filename, $context );
			$results[] = array_merge( array( 'filename' => $filename ), $result );

			if ( ! empty( $result['success'] ) ) {
				++$regenerated;
			}
		}

		return array(
			'success' => $regenerated === count( $composable ),
			'message' => sprintf( 'Regenerated %d of %d composable file(s).', $regenerated, count( $composable ) ),
			'results' => $results,
		);
	}

	/**
	 * Write content to a file, ensuring directory exists and permissions are set.
	 *
	 * @param string $filepath  Full file path.
	 * @param string $directory Parent directory.
	 * @param string $content   File content.
	 * @return bool True on success.
	 */
	private static function write_file( string $filepath, string $directory, string $content ): bool {
		$dm = new DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $directory ) ) {
			return false;
		}

		$temp_path = tempnam( $directory, '.' . basename( $filepath ) . '.tmp-' );
		if ( false === $temp_path ) {
			return false;
		}

		$payload = $content . "\n";
		$written = file_put_contents( $temp_path, $payload, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@chmod( $temp_path, FS_CHMOD_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( strlen( $payload ) !== $written || ! @rename( $temp_path, $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		FilesystemHelper::make_group_writable( $filepath );
		return true;
	}

	/**
	 * Resolve a composable file's canonical write target.
	 *
	 * @param string $filename Composable filename.
	 * @param array  $meta     Registry metadata.
	 * @param array  $context  Generation context.
	 * @return array{success:bool,directory?:string,filepath?:string,message?:string}
	 */
	private static function resolve_target( string $filename, array $meta, array $context ): array {
		if ( ! empty( $meta['convention_path'] ) ) {
			$filepath = rtrim( ABSPATH, '/' ) . '/' . $meta['convention_path'];
			return array(
				'success'   => true,
				'directory' => dirname( $filepath ),
				'filepath'  => $filepath,
			);
		}

		$directory = ScaffoldAbilities::resolve_directory( $meta['layer'], $context );
		if ( ! $directory ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Could not resolve directory for layer "%s".', $meta['layer'] ),
			);
		}

		return array(
			'success'   => true,
			'directory' => $directory,
			'filepath'  => trailingslashit( $directory ) . $filename,
		);
	}
}
