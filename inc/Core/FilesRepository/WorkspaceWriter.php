<?php
/**
 * Workspace File Writer
 *
 * Write and edit operations within the agent workspace — creating new files,
 * overwriting existing files, and performing find-and-replace edits in
 * cloned repositories.
 *
 * All operations are contained within the workspace via path validation.
 * Mutating abilities are CLI-only (show_in_rest = false) for safety.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.32.0
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

class WorkspaceWriter {

	/**
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * @param Workspace $workspace Workspace instance for path resolution and validation.
	 */
	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * Write (create or overwrite) a file in a workspace repo.
	 *
	 * Creates parent directories as needed. Path traversal is blocked
	 * by rejecting ".." and "." components in the path.
	 *
	 * @param string $name    Repository directory name.
	 * @param string $path    Relative file path within the repo.
	 * @param string $content File content to write.
	 * @return array{success: bool, path?: string, size?: int, created?: bool, message?: string}
	 */
	public function write_file( string $name, string $path, string $content ): array {
		$repo_path = $this->workspace->get_repo_path( $name );
		$path      = ltrim( $path, '/' );

		if ( ! is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Repository "%s" not found in workspace.', $name ),
			);
		}

		if ( '' === $path ) {
			return array(
				'success' => false,
				'message' => 'File path is required.',
			);
		}

		// Reject path traversal components.
		if ( $this->has_traversal( $path ) ) {
			return array(
				'success' => false,
				'message' => 'Path traversal detected. Access denied.',
			);
		}

		$file_path = $repo_path . '/' . $path;
		$existed   = file_exists( $file_path );

		// Ensure parent directory exists.
		$parent = dirname( $file_path );
		if ( ! is_dir( $parent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			$created = mkdir( $parent, 0755, true );
			if ( ! $created ) {
				return array(
					'success' => false,
					'message' => sprintf( 'Failed to create directory: %s', dirname( $path ) ),
				);
			}
		}

		// Write the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $file_path, $content );

		if ( false === $bytes ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to write file: %s', $path ),
			);
		}

		return array(
			'success' => true,
			'path'    => $path,
			'size'    => $bytes,
			'created' => ! $existed,
		);
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * Finds an exact match of old_string and replaces it with new_string.
	 * Fails if old_string is not found or has multiple matches (unless
	 * replace_all is true).
	 *
	 * @param string $name        Repository directory name.
	 * @param string $path        Relative file path within the repo.
	 * @param string $old_string  Text to find.
	 * @param string $new_string  Replacement text.
	 * @param bool   $replace_all Replace all occurrences (default false).
	 * @return array{success: bool, path?: string, replacements?: int, message?: string}
	 */
	public function edit_file( string $name, string $path, string $old_string, string $new_string, bool $replace_all = false ): array {
		$repo_path = $this->workspace->get_repo_path( $name );
		$path      = ltrim( $path, '/' );

		if ( ! is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Repository "%s" not found in workspace.', $name ),
			);
		}

		$file_path  = $repo_path . '/' . $path;
		$validation = $this->workspace->validate_containment( $file_path, $repo_path );

		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
			);
		}

		$real_path = $validation['real_path'];

		if ( ! is_file( $real_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File not found: %s', $path ),
			);
		}

		if ( ! is_readable( $real_path ) || ! is_writable( $real_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File not readable/writable: %s', $path ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $real_path );

		if ( false === $content ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to read file: %s', $path ),
			);
		}

		if ( $old_string === $new_string ) {
			return array(
				'success' => false,
				'message' => 'old_string and new_string are identical.',
			);
		}

		// Count occurrences.
		$count = substr_count( $content, $old_string );

		if ( 0 === $count ) {
			return array(
				'success' => false,
				'message' => 'old_string not found in file content.',
			);
		}

		if ( $count > 1 && ! $replace_all ) {
			return array(
				'success' => false,
				'message' => sprintf(
					'Found %d matches for old_string. Use replace_all to replace all, or provide more context to make the match unique.',
					$count
				),
			);
		}

		// Perform replacement.
		if ( $replace_all ) {
			$new_content = str_replace( $old_string, $new_string, $content );
		} else {
			$pos         = strpos( $content, $old_string );
			$new_content = substr_replace( $content, $new_string, $pos, strlen( $old_string ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $real_path, $new_content );

		if ( false === $bytes ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to write file: %s', $path ),
			);
		}

		return array(
			'success'      => true,
			'path'         => $path,
			'replacements' => $replace_all ? $count : 1,
		);
	}

	/**
	 * Check if a relative path contains traversal components.
	 *
	 * @param string $path Relative path to check.
	 * @return bool True if path contains ".." or "." components.
	 */
	private function has_traversal( string $path ): bool {
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return true;
			}
		}
		return false;
	}
}
