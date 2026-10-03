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
	 * @return array{success:true,message:string,filepath:string,stale_removed:string}|array{success:false,message:string,error_code?:string,blocker?:array<string,int|string|bool>}
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
			$diagnostic = $acquisition['diagnostic'];

			// "unusable" never waited, so reporting a timeout describes a
			// contention that did not happen and hides a one-file permission
			// fault behind a hunt for a process that does not exist.
			if ( 'unusable' === ( $diagnostic['lock_status'] ?? '' ) ) {
				return array(
					'success'    => false,
					'error_code' => 'composition_lock_unusable',
					'message'    => sprintf(
						'Composition lock file for "%s" cannot be opened for writing by this user (%s). It is not held by any process; remove it and retry.',
						$filename,
						(string) ( $diagnostic['lock_path'] ?? '' )
					),
					'blocker'    => $diagnostic,
				);
			}

			return array(
				'success'    => false,
				'error_code' => 'composition_locked',
				'message'    => sprintf( 'Composition lock unavailable for "%s" after 2 seconds.', $filename ),
				'blocker'    => $diagnostic,
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

			$write_result = self::write_file( $filepath, $directory, $content );

			if ( ! $write_result['success'] ) {
				return array(
					'success'    => false,
					'message'    => $write_result['message'],
					'error_code' => $write_result['error_code'],
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
			'success' => count( $composable ) === $regenerated,
			'message' => sprintf( 'Regenerated %d of %d composable file(s).', $regenerated, count( $composable ) ),
			'results' => $results,
		);
	}

	/**
	 * Write content to a file, ensuring directory exists and permissions are set.
	 *
	 * The write-to-temp-then-rename pattern below is only atomic when the temp
	 * file lands in the same directory (same filesystem) as the target. PHP's
	 * tempnam() does not fail when `$directory` is not writable by the running
	 * process — it silently falls back to `sys_get_temp_dir()` and emits an
	 * E_NOTICE. That fallback both (a) prints a notice on every CLI compose and
	 * (b) puts the temp file on a different filesystem, degrading the following
	 * rename() into a non-atomic copy+unlink that overwrites the target in
	 * place while this method reports success — exactly the failure a
	 * concurrent reader (every agent session start reads a composed file) can
	 * observe as a half-written file.
	 *
	 * We do not trust tempnam()'s non-false return alone. `is_writable()` is
	 * checked up front so the fallback path is never entered for the directory
	 * this bug was filed against (#3545), and the temp file's actual directory
	 * is re-verified after creation as a defense-in-depth check against any
	 * other route to the same fallback (e.g. a permission model `is_writable()`
	 * does not understand). Either check failing aborts the write with an
	 * explicit, named error instead of silently completing a non-atomic
	 * cross-filesystem rename.
	 *
	 * @since next Returns an array with an explicit error_code/message on
	 *             failure instead of trusting tempnam()'s fallback (#3545).
	 *
	 * @param string $filepath  Full file path.
	 * @param string $directory Parent directory.
	 * @param string $content   File content.
	 * @return array{success:true}|array{success:false,message:string,error_code:string}
	 */
	private static function write_file( string $filepath, string $directory, string $content ): array {
		$dm = new DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $directory ) ) {
			return array(
				'success'    => false,
				'error_code' => 'directory_uncreatable',
				'message'    => sprintf( 'Could not create directory "%s".', $directory ),
			);
		}

		// Refuse to let tempnam() silently fall back to a different filesystem.
		if ( ! is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return array(
				'success'    => false,
				'error_code' => 'directory_not_writable',
				'message'    => sprintf(
					'Directory "%s" is not writable by this process. Refusing to fall back to a non-atomic cross-filesystem write; fix the directory ownership/permissions and retry.',
					$directory
				),
			);
		}

		$temp_path = tempnam( $directory, '.' . basename( $filepath ) . '.tmp-' );
		if ( false === $temp_path ) {
			return array(
				'success'    => false,
				'error_code' => 'tempnam_failed',
				'message'    => sprintf( 'Could not create a temporary file in "%s".', $directory ),
			);
		}

		// Defense in depth: is_writable() does not model every permission
		// scheme (ACLs, mount options, quotas). If tempnam() still fell back
		// to sys_get_temp_dir() despite the check above, the temp file is not
		// actually in $directory and the rename() below would silently
		// degrade to a non-atomic copy+unlink. Fail instead of completing it.
		if ( realpath( dirname( $temp_path ) ) !== realpath( $directory ) ) {
			@unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success'    => false,
				'error_code' => 'tempnam_fallback',
				'message'    => sprintf(
					'tempnam() fell back to a different filesystem for "%s". Refusing to complete a non-atomic cross-filesystem write.',
					$directory
				),
			);
		}

		$payload   = $content . "\n";
		$written   = file_put_contents( $temp_path, $payload, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file_mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		@chmod( $temp_path, $file_mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged

		// Before the rename, so the file is never visible in the wrong group.
		// tempnam() gives the temp file the writing process's primary group, and
		// rename() carries that onto the target — which is how a root-run
		// composition leaves a root:root file that the service user can no
		// longer replace, despite the 0664 applied just above.
		FilesystemHelper::inherit_shared_group( $temp_path, $directory );

		if ( strlen( $payload ) !== $written || ! @rename( $temp_path, $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success'    => false,
				'error_code' => 'write_or_rename_failed',
				'message'    => sprintf( 'Failed to write or atomically rename into place for "%s".', $filepath ),
			);
		}

		FilesystemHelper::make_group_writable( $filepath );
		return array( 'success' => true );
	}

	/**
	 * Resolve a composable file's canonical write target.
	 *
	 * @param string $filename Composable filename.
	 * @param array  $meta     Registry metadata.
	 * @param array  $context  Generation context.
	 * @return array{success:true,directory:string,filepath:string}|array{success:false,message:string}
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
