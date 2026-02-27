<?php
/**
 * WP-CLI Workspace Command
 *
 * Provides CLI access to the agent workspace — a managed directory
 * for cloning repos and working with files outside the web root.
 *
 * All commands delegate to WordPress Abilities API primitives registered
 * in WorkspaceAbilities. The CLI layer handles argument parsing, confirmation
 * prompts, and output formatting only.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.31.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\FilesRepository\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceCommand extends BaseCommand {

	/**
	 * Show the workspace directory path.
	 *
	 * Displays the resolved workspace path. The path is determined by:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable)
	 * 3. wp-content/uploads/datamachine-files/workspace (fallback)
	 *
	 * ## OPTIONS
	 *
	 * [--ensure]
	 * : Create the directory if it doesn't exist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show workspace path
	 *     wp datamachine workspace path
	 *
	 *     # Show path and create if missing
	 *     wp datamachine workspace path --ensure
	 *
	 * @subcommand path
	 */
	public function path( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/workspace-path' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace path ability not available.' );
			return;
		}

		$result = $ability->execute( array(
			'ensure' => ! empty( $assoc_args['ensure'] ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['created'] ) ) {
			WP_CLI::success( sprintf( 'Created workspace: %s', $result['path'] ) );
			return;
		}

		WP_CLI::log( $result['path'] );

		if ( empty( $result['exists'] ) && empty( $assoc_args['ensure'] ) ) {
			WP_CLI::warning( 'Directory does not exist yet. Use --ensure to create it.' );
		}
	}

	/**
	 * List repositories in the workspace.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List workspace repos
	 *     wp datamachine workspace list
	 *
	 *     # List as JSON
	 *     wp datamachine workspace list --format=json
	 *
	 * @subcommand list
	 */
	public function list_repos( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/workspace-list' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace list ability not available.' );
			return;
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( empty( $result['repos'] ) ) {
			WP_CLI::log( sprintf( 'No repos in workspace (%s).', $result['path'] ?? '' ) );
			WP_CLI::log( 'Clone one with: wp datamachine workspace clone <url>' );
			return;
		}

		$items = array_map(
			function ( $repo ) {
				return array(
					'name'   => $repo['name'],
					'branch' => $repo['branch'] ?? '-',
					'remote' => $repo['remote'] ?? '-',
					'git'    => $repo['git'] ? 'yes' : 'no',
					'path'   => $repo['path'],
				);
			},
			$result['repos']
		);

		$this->format_items(
			$items,
			array( 'name', 'branch', 'remote', 'git' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Git repository URL to clone.
	 *
	 * [--name=<name>]
	 * : Directory name in workspace (derived from URL if omitted).
	 *
	 * ## EXAMPLES
	 *
	 *     # Clone a repo
	 *     wp datamachine workspace clone https://github.com/Extra-Chill/homeboy.git
	 *
	 *     # Clone with custom name
	 *     wp datamachine workspace clone https://github.com/Extra-Chill/homeboy.git --name=homeboy-dev
	 *
	 * @subcommand clone
	 */
	public function clone_repo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository URL is required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-clone' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace clone ability not available.' );
			return;
		}

		$result = $ability->execute( array(
			'url'  => $args[0],
			'name' => $assoc_args['name'] ?? null,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Path: %s', $result['path'] ) );
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name to remove.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove a repo (with confirmation)
	 *     wp datamachine workspace remove homeboy
	 *
	 *     # Remove without confirmation
	 *     wp datamachine workspace remove homeboy --yes
	 *
	 * @subcommand remove
	 */
	public function remove_repo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository name is required.' );
			return;
		}

		$name = $args[0];

		// Confirm unless --yes is passed. This stays in CLI — abilities don't prompt.
		if ( empty( $assoc_args['yes'] ) ) {
			$workspace = new Workspace();
			$repo_path = $workspace->get_repo_path( $name );
			WP_CLI::confirm( sprintf( 'Remove "%s" from workspace? This deletes %s', $name, $repo_path ) );
		}

		$ability = wp_get_ability( 'datamachine/workspace-remove' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace remove ability not available.' );
			return;
		}

		$result = $ability->execute( array( 'name' => $name ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show repo info
	 *     wp datamachine workspace show homeboy
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository name is required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-show' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace show ability not available.' );
			return;
		}

		$result = $ability->execute( array( 'name' => $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::log( sprintf( 'Name:     %s', $result['name'] ) );
		WP_CLI::log( sprintf( 'Path:     %s', $result['path'] ) );
		WP_CLI::log( sprintf( 'Branch:   %s', $result['branch'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Remote:   %s', $result['remote'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Latest:   %s', $result['commit'] ?? '-' ) );

		$dirty = $result['dirty'] ?? 0;
		WP_CLI::log( sprintf( 'Dirty:    %s', ( 0 === $dirty ) ? 'no' : "yes ({$dirty} files)" ) );
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * Reads text file contents from a cloned repository in the workspace.
	 * Binary files are detected and rejected. Large files are limited by
	 * --max-size (default 1 MB). Use --offset and --limit to read specific
	 * line ranges.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--max-size=<bytes>]
	 * : Maximum file size in bytes.
	 * ---
	 * default: 1048576
	 * ---
	 *
	 * [--offset=<line>]
	 * : Line number to start reading from (1-indexed).
	 *
	 * [--limit=<lines>]
	 * : Maximum number of lines to return.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read a file
	 *     wp datamachine workspace read homeboy src/main.rs
	 *
	 *     # Read with custom size limit
	 *     wp datamachine workspace read homeboy Cargo.toml --max-size=2097152
	 *
	 *     # Read lines 100-130 from a file
	 *     wp datamachine workspace read extrachill style.css --offset=100 --limit=30
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace read <repo> <path>' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace read ability not available.' );
			return;
		}

		$input = array(
			'repo' => $args[0],
			'path' => $args[1],
		);

		if ( isset( $assoc_args['max-size'] ) ) {
			$input['max_size'] = (int) $assoc_args['max-size'];
		}

		if ( isset( $assoc_args['offset'] ) ) {
			$input['offset'] = (int) $assoc_args['offset'];
		}

		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		// Output raw content — suitable for piping.
		WP_CLI::log( $result['content'] );
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * Lists files and directories. Directories are listed first, then
	 * files, both sorted alphabetically.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * [<path>]
	 * : Relative directory path within the repo (defaults to root).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List repo root
	 *     wp datamachine workspace ls homeboy
	 *
	 *     # List subdirectory
	 *     wp datamachine workspace ls homeboy src/commands
	 *
	 *     # List as JSON
	 *     wp datamachine workspace ls homeboy --format=json
	 *
	 * @subcommand ls
	 */
	public function ls( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace ls <repo> [<path>]' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-ls' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace ls ability not available.' );
			return;
		}

		$input = array( 'repo' => $args[0] );

		if ( ! empty( $args[1] ) ) {
			$input['path'] = $args[1];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		if ( empty( $result['entries'] ) ) {
			WP_CLI::log( 'Empty directory.' );
			return;
		}

		$items = array_map(
			function ( $entry ) {
				return array(
					'name' => $entry['name'],
					'type' => $entry['type'],
					'size' => isset( $entry['size'] ) ? size_format( $entry['size'] ) : '-',
				);
			},
			$result['entries']
		);

		$this->format_items(
			$items,
			array( 'name', 'type', 'size' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Write a file to a workspace repo.
	 *
	 * Creates or overwrites a file. Parent directories are created as needed.
	 * Content can be passed via --content flag or piped via stdin.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--content=<content>]
	 * : File content to write. Prefix with @ to read from a local file (e.g. --content=@/tmp/code.rs).
	 * : If omitted, reads from stdin.
	 *
	 * ## EXAMPLES
	 *
	 *     # Write with content flag
	 *     wp datamachine workspace write homeboy src/new.rs --content="fn main() {}"
	 *
	 *     # Write from a local file (@ syntax)
	 *     wp datamachine workspace write homeboy src/main.rs --content=@/tmp/staged-code.rs
	 *
	 *     # Write from stdin
	 *     cat local-file.rs | wp datamachine workspace write homeboy src/main.rs
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace write <repo> <path> --content=<content>' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-write' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace write ability not available.' );
			return;
		}

		$content = $assoc_args['content'] ?? null;

		// Resolve @file syntax — read content from a local file.
		if ( null !== $content ) {
			$content = $this->resolveAtFile( $content );
		}

		// Read from stdin if --content not provided.
		if ( null === $content ) {
			if ( function_exists( 'posix_isatty' ) && posix_isatty( STDIN ) ) {
				WP_CLI::error( 'No content provided. Use --content=<content> or pipe content via stdin.' );
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( 'php://stdin' );
			if ( false === $content ) {
				WP_CLI::error( 'Failed to read from stdin.' );
				return;
			}
		}

		$result = $ability->execute( array(
			'repo'    => $args[0],
			'path'    => $args[1],
			'content' => $content,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		$action = ! empty( $result['created'] ) ? 'Created' : 'Updated';
		WP_CLI::success( sprintf( '%s %s (%s)', $action, $result['path'], size_format( $result['size'] ) ) );
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * Performs exact string replacement. Fails if the old string is not found,
	 * or if multiple matches exist (unless --replace-all is used).
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * --old=<string>
	 * : Text to find. Prefix with @ to read from a local file (e.g. --old=@/tmp/old.txt).
	 *
	 * --new=<string>
	 * : Replacement text. Prefix with @ to read from a local file (e.g. --new=@/tmp/new.txt).
	 *
	 * [--replace-all]
	 * : Replace all occurrences instead of requiring a unique match.
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace a single occurrence
	 *     wp datamachine workspace edit homeboy src/main.rs --old="old_func" --new="new_func"
	 *
	 *     # Replace using @ file syntax
	 *     wp datamachine workspace edit homeboy src/main.rs --old=@/tmp/old.txt --new=@/tmp/new.txt
	 *
	 *     # Replace all occurrences
	 *     wp datamachine workspace edit homeboy src/main.rs --old="v1" --new="v2" --replace-all
	 *
	 * @subcommand edit
	 */
	public function edit( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace edit <repo> <path> --old=<string> --new=<string>' );
			return;
		}

		if ( ! isset( $assoc_args['old'] ) || ! isset( $assoc_args['new'] ) ) {
			WP_CLI::error( 'Both --old and --new flags are required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-edit' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace edit ability not available.' );
			return;
		}

		$input = array(
			'repo'       => $args[0],
			'path'       => $args[1],
			'old_string' => $this->resolveAtFile( $assoc_args['old'] ),
			'new_string' => $this->resolveAtFile( $assoc_args['new'] ),
		);

		if ( ! empty( $assoc_args['replace-all'] ) ) {
			$input['replace_all'] = true;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		$count = $result['replacements'] ?? 1;
		WP_CLI::success( sprintf(
			'Edited %s (%d replacement%s)',
			$result['path'],
			$count,
			1 === $count ? '' : 's'
		) );
	}

	/**
	 * Resolve @file syntax — if a string starts with @, read file contents.
	 *
	 * Mirrors curl's -d @filename convention. If the value doesn't start
	 * with @, it's returned unchanged.
	 *
	 * @param string $value Raw CLI argument value.
	 * @return string Resolved content (file contents or original value).
	 */
	private function resolveAtFile( string $value ): string {
		if ( 0 !== strpos( $value, '@' ) ) {
			return $value;
		}

		$file_path = substr( $value, 1 );

		if ( empty( $file_path ) ) {
			WP_CLI::error( 'Empty file path after @. Usage: --content=@/path/to/file' );
		}

		if ( ! file_exists( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		if ( ! is_readable( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not readable: %s', $file_path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			WP_CLI::error( sprintf( 'Failed to read file: %s', $file_path ) );
		}

		return $content;
	}
}
