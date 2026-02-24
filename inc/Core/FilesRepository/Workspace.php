<?php
/**
 * Agent Workspace
 *
 * Provides a managed directory for agent file operations — cloning repos,
 * storing working files, etc. Lives outside the web root when possible
 * for security.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.31.0
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

class Workspace {

	/**
	 * Maximum file size for reading (1 MB).
	 */
	const MAX_READ_SIZE = 1048576;

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	/**
	 * @var string Resolved workspace path.
	 */
	private string $workspace_path;

	public function __construct() {
		$this->directory_manager = new DirectoryManager();
		$this->workspace_path   = $this->directory_manager->get_workspace_directory();
	}

	/**
	 * Get the workspace base path.
	 *
	 * @return string
	 */
	public function get_path(): string {
		return $this->workspace_path;
	}

	/**
	 * Get the full path to a repo within the workspace.
	 *
	 * @param string $name Repository name (directory name).
	 * @return string Full path.
	 */
	public function get_repo_path( string $name ): string {
		return $this->workspace_path . '/' . $this->sanitize_name( $name );
	}

	/**
	 * Ensure the workspace directory exists with correct permissions.
	 *
	 * @return array{success: bool, path: string, message?: string, created?: bool}
	 */
	public function ensure_exists(): array {
		$path = $this->workspace_path;

		if ( '' === $path ) {
			return array(
				'success' => false,
				'path'    => '',
				'message' => 'Workspace unavailable: no writable path outside the web root. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php or ensure /var/lib/datamachine/ is writable.',
			);
		}

		if ( is_dir( $path ) ) {
			return array(
				'success' => true,
				'path'    => $path,
				'created' => false,
			);
		}

		$created = $this->directory_manager->ensure_directory_exists( $path );

		if ( ! $created ) {
			return array(
				'success' => false,
				'path'    => $path,
				'message' => sprintf( 'Failed to create workspace directory: %s', $path ),
			);
		}

		// Add .htaccess to block web access if inside web root.
		$this->protect_directory( $path );

		return array(
			'success' => true,
			'path'    => $path,
			'created' => true,
		);
	}

	/**
	 * List repositories in the workspace.
	 *
	 * @return array{success: bool, repos: array, path: string}
	 */
	public function list_repos(): array {
		$path = $this->workspace_path;

		if ( ! is_dir( $path ) ) {
			return array(
				'success' => true,
				'repos'   => array(),
				'path'    => $path,
			);
		}

		$repos   = array();
		$entries = scandir( $path );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $path . '/' . $entry;
			if ( ! is_dir( $entry_path ) ) {
				continue;
			}

			$repo_info = array(
				'name' => $entry,
				'path' => $entry_path,
				'git'  => is_dir( $entry_path . '/.git' ),
			);

			// Get git remote if available.
			if ( $repo_info['git'] ) {
				$remote = $this->git_get_remote( $entry_path );
				if ( null !== $remote ) {
					$repo_info['remote'] = $remote;
				}

				$branch = $this->git_get_branch( $entry_path );
				if ( null !== $branch ) {
					$repo_info['branch'] = $branch;
				}
			}

			$repos[] = $repo_info;
		}

		return array(
			'success' => true,
			'repos'   => $repos,
			'path'    => $path,
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param string      $url  Git clone URL.
	 * @param string|null $name Directory name override (derived from URL if null).
	 * @return array{success: bool, name?: string, path?: string, message?: string}
	 */
	public function clone_repo( string $url, ?string $name = null ): array {
		// Validate URL.
		if ( empty( $url ) ) {
			return array(
				'success' => false,
				'message' => 'Repository URL is required.',
			);
		}

		// Derive name from URL if not provided.
		if ( null === $name || '' === $name ) {
			$name = $this->derive_repo_name( $url );
			if ( null === $name ) {
				return array(
					'success' => false,
					'message' => sprintf( 'Could not derive repository name from URL: %s. Use --name to specify.', $url ),
				);
			}
		}

		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		// Check if already exists.
		if ( is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'name'    => $name,
				'path'    => $repo_path,
				'message' => sprintf( 'Directory already exists: %s. Use "remove" first to re-clone.', $name ),
			);
		}

		// Ensure workspace exists.
		$ensure = $this->ensure_exists();
		if ( ! $ensure['success'] ) {
			return $ensure;
		}

		// Clone.
		$escaped_url  = escapeshellarg( $url );
		$escaped_path = escapeshellarg( $repo_path );
		$command      = sprintf( 'git clone %s %s 2>&1', $escaped_url, $escaped_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return array(
				'success' => false,
				'name'    => $name,
				'message' => sprintf( 'Git clone failed (exit %d): %s', $exit_code, implode( "\n", $output ) ),
			);
		}

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'message' => sprintf( 'Cloned %s into workspace as "%s".', $url, $name ),
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param string $name Repository directory name.
	 * @return array{success: bool, message: string}
	 */
	public function remove_repo( string $name ): array {
		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		if ( ! is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Repository "%s" not found in workspace.', $name ),
			);
		}

		// Safety: ensure path is within workspace.
		$validation = $this->validate_containment( $repo_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => $validation['message'],
			);
		}

		// Remove recursively.
		$escaped = escapeshellarg( $validation['real_path'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'rm -rf %s 2>&1', $escaped ), $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to remove (exit %d): %s', $exit_code, implode( "\n", $output ) ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Removed "%s" from workspace.', $name ),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param string $name Repository directory name.
	 * @return array{success: bool, name?: string, path?: string, branch?: string, remote?: string, commit?: string, dirty?: int, message?: string}
	 */
	public function show_repo( string $name ): array {
		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		if ( ! is_dir( $repo_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Repository "%s" not found in workspace.', $name ),
			);
		}

		$escaped = escapeshellarg( $repo_path );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = trim( (string) exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) ) );
		$remote = trim( (string) exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) ) );
		$commit = trim( (string) exec( sprintf( 'git -C %s log -1 --format="%%h %%s" 2>/dev/null', $escaped ) ) );
		$status = trim( (string) exec( sprintf( 'git -C %s status --porcelain 2>/dev/null | wc -l', $escaped ) ) );
		// phpcs:enable

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'branch'  => $branch ?: null,
			'remote'  => $remote ?: null,
			'commit'  => $commit ?: null,
			'dirty'   => (int) $status,
		);
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Validate that a target path is contained within a parent directory.
	 *
	 * Public security primitive — used by WorkspaceReader and WorkspaceWriter
	 * to enforce path containment before (and after) file I/O. Uses realpath()
	 * for symlink-safe resolution, so the target must exist on disk.
	 *
	 * For pre-write validation of non-existent files, use has_traversal()
	 * checks on the relative path first, then call this method post-write
	 * to verify the file landed where expected.
	 *
	 * @param string $target    Path to validate.
	 * @param string $container Parent directory that must contain the target.
	 * @return array{valid: bool, real_path?: string, message?: string}
	 */
	public function validate_containment( string $target, string $container ): array {
		$real_container = realpath( $container );
		$real_target    = realpath( $target );

		if ( false === $real_container || false === $real_target ) {
			return array(
				'valid'   => false,
				'message' => 'Path does not exist.',
			);
		}

		if ( 0 !== strpos( $real_target, $real_container . '/' ) && $real_target !== $real_container ) {
			return array(
				'valid'   => false,
				'message' => 'Path traversal detected. Access denied.',
			);
		}

		return array(
			'valid'     => true,
			'real_path' => $real_target,
		);
	}

	/**
	 * Derive a repo name from a git URL.
	 *
	 * @param string $url Git URL.
	 * @return string|null Derived name or null.
	 */
	private function derive_repo_name( string $url ): ?string {
		// Handle https://github.com/org/repo.git and git@github.com:org/repo.git
		$name = basename( $url );
		$name = preg_replace( '/\.git$/', '', $name );
		$name = $this->sanitize_name( $name );

		return ( '' !== $name ) ? $name : null;
	}

	/**
	 * Sanitize a directory name for use in the workspace.
	 *
	 * @param string $name Raw name.
	 * @return string Sanitized name (alphanumeric, hyphens, underscores, dots).
	 */
	private function sanitize_name( string $name ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
	}

	/**
	 * Get the origin remote URL for a git repo.
	 *
	 * @param string $repo_path Path to repo.
	 * @return string|null Remote URL or null.
	 */
	private function git_get_remote( string $repo_path ): ?string {
		$escaped = escapeshellarg( $repo_path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$remote = exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) );
		return ( '' !== $remote ) ? $remote : null;
	}

	/**
	 * Get the current branch for a git repo.
	 *
	 * @param string $repo_path Path to repo.
	 * @return string|null Branch name or null.
	 */
	private function git_get_branch( string $repo_path ): ?string {
		$escaped = escapeshellarg( $repo_path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) );
		return ( '' !== $branch ) ? $branch : null;
	}

	/**
	 * Add .htaccess protection if the workspace is inside the web root.
	 *
	 * @param string $path Directory path.
	 */
	private function protect_directory( string $path ): void {
		// Only needed if path is under ABSPATH (web root).
		$abspath = rtrim( ABSPATH, '/' );
		if ( 0 !== strpos( $path, $abspath ) ) {
			return;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
