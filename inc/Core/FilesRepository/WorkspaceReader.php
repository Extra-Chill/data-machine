<?php
/**
 * Workspace File Reader
 *
 * Read-only file operations within the agent workspace — reading file
 * contents and listing directory entries in cloned repositories.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.32.0
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

class WorkspaceReader {

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
	 * Read a file from a workspace repo.
	 *
	 * @param string $name     Repository directory name.
	 * @param string $path     Relative file path within the repo.
	 * @param int    $max_size Maximum file size in bytes.
	 * @return array{success: bool, content?: string, path?: string, size?: int, message?: string}
	 */
	public function read_file( string $name, string $path, int $max_size = Workspace::MAX_READ_SIZE ): array {
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

		if ( ! is_readable( $real_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File not readable: %s', $path ),
			);
		}

		$size = filesize( $real_path );

		if ( $size > $max_size ) {
			return array(
				'success' => false,
				'message' => sprintf(
					'File too large: %s (%s). Maximum: %s.',
					$path,
					size_format( $size ),
					size_format( $max_size )
				),
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

		// Detect binary: check for null bytes in first 8 KB.
		$sample = substr( $content, 0, 8192 );
		if ( false !== strpos( $sample, "\0" ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Binary file detected: %s. Only text files can be read.', $path ),
			);
		}

		return array(
			'success' => true,
			'content' => $content,
			'path'    => $path,
			'size'    => $size,
		);
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param string      $name Repository directory name.
	 * @param string|null $path Relative directory path within the repo (null for root).
	 * @return array{success: bool, repo?: string, path?: string, entries?: array, message?: string}
	 */
	public function list_directory( string $name, ?string $path = null ): array {
		$repo_path = $this->workspace->get_repo_path( $name );

		if ( ! is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Repository "%s" not found in workspace.', $name ),
			);
		}

		$target_path = $repo_path;

		if ( null !== $path && '' !== $path ) {
			$path        = ltrim( $path, '/' );
			$target_path = $repo_path . '/' . $path;

			$validation = $this->workspace->validate_containment( $target_path, $repo_path );

			if ( ! $validation['valid'] ) {
				return array(
					'success' => false,
					'message' => $validation['message'],
				);
			}

			$target_path = $validation['real_path'];
		}

		if ( ! is_dir( $target_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Directory not found: %s', $path ?? '/' ),
			);
		}

		$entries = scandir( $target_path );
		$items   = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $target_path . '/' . $entry;
			$is_dir     = is_dir( $entry_path );

			$item = array(
				'name' => $entry,
				'type' => $is_dir ? 'directory' : 'file',
			);

			if ( ! $is_dir ) {
				$item['size'] = filesize( $entry_path );
			}

			$items[] = $item;
		}

		// Sort: directories first, then alphabetical.
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return ( 'directory' === $a['type'] ) ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'success' => true,
			'repo'    => $name,
			'path'    => $path ?? '/',
			'entries' => $items,
		);
	}
}
