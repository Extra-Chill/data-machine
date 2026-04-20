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
	 * Generates content via SectionRegistry, writes to the layer directory,
	 * and optionally writes a convention copy at ABSPATH + convention_path.
	 *
	 * @since 0.66.0
	 *
	 * @param string $filename Composable filename (e.g. 'AGENTS.md').
	 * @param array  $context  {
	 *     Generation context.
	 *
	 *     @type int    $user_id    WordPress user ID.
	 *     @type string $agent_slug Agent slug.
	 *     @type int    $agent_id   Agent ID.
	 * }
	 * @return array{success: bool, message: string, filepath?: string}
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

		// Generate content from registered sections.
		$content = SectionRegistry::generate( $filename, $context );

		if ( '' === trim( $content ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'All sections returned empty content for "%s".', $filename ),
			);
		}

		// Prepend the registrar-owned header, if any. Core does not inject
		// editorial content (titles, warnings) — the file's owner supplies
		// its own header via MemoryFileRegistry::register( ..., array( 'header' => ... ) ).
		$header = isset( $meta['header'] ) && is_string( $meta['header'] ) ? trim( $meta['header'] ) : '';
		if ( '' !== $header ) {
			$content = $header . "\n\n" . $content;
		}

		// Resolve write target.
		// Files with a convention_path write ONLY to that path (e.g. AGENTS.md → site root).
		// Files without one write to their layer directory as before.
		if ( ! empty( $meta['convention_path'] ) ) {
			$filepath  = rtrim( ABSPATH, '/' ) . '/' . $meta['convention_path'];
			$directory = dirname( $filepath );
		} else {
			$directory = ScaffoldAbilities::resolve_directory( $meta['layer'], $context );

			if ( ! $directory ) {
				return array(
					'success' => false,
					'message' => sprintf( 'Could not resolve directory for layer "%s".', $meta['layer'] ),
				);
			}

			$filepath = trailingslashit( $directory ) . $filename;
		}

		$written = self::write_file( $filepath, $directory, $content );

		if ( ! $written ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to write %s to disk.', $filename ),
			);
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

		return array(
			'success'  => true,
			'message'  => $message,
			'filepath' => $filepath,
		);
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
			'success' => true,
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

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return false;
		}

		$result = $fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );
		if ( $result ) {
			FilesystemHelper::make_group_writable( $filepath );
		}

		return (bool) $result;
	}
}
