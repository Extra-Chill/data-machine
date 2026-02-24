<?php
/**
 * WP-CLI Workspace Command
 *
 * Provides CLI access to the agent workspace — a managed directory
 * for cloning repos and working with files outside the web root.
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
		$workspace = new Workspace();
		$path      = $workspace->get_path();
		$exists    = is_dir( $path );

		if ( ! empty( $assoc_args['ensure'] ) ) {
			$result = $workspace->ensure_exists();
			if ( ! $result['success'] ) {
				WP_CLI::error( $result['message'] );
				return;
			}
			if ( ! empty( $result['created'] ) ) {
				WP_CLI::success( sprintf( 'Created workspace: %s', $path ) );
				return;
			}
		}

		WP_CLI::log( $path );

		if ( ! $exists && empty( $assoc_args['ensure'] ) ) {
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
		$workspace = new Workspace();
		$result    = $workspace->list_repos();

		if ( empty( $result['repos'] ) ) {
			$path = $workspace->get_path();
			WP_CLI::log( sprintf( 'No repos in workspace (%s).', $path ) );
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

		$url  = $args[0];
		$name = $assoc_args['name'] ?? null;

		$workspace = new Workspace();
		$result    = $workspace->clone_repo( $url, $name );

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

		$name      = $args[0];
		$workspace = new Workspace();
		$repo_path = $workspace->get_repo_path( $name );

		if ( ! is_dir( $repo_path ) ) {
			WP_CLI::error( sprintf( 'Repository "%s" not found in workspace.', $name ) );
			return;
		}

		// Confirm unless --yes is passed.
		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Remove "%s" from workspace? This deletes %s', $name, $repo_path ) );
		}

		$result = $workspace->remove_repo( $name );

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

		$name      = $args[0];
		$workspace = new Workspace();
		$repo_path = $workspace->get_repo_path( $name );

		if ( ! is_dir( $repo_path ) ) {
			WP_CLI::error( sprintf( 'Repository "%s" not found in workspace.', $name ) );
			return;
		}

		$escaped = escapeshellarg( $repo_path );

		// Gather info.
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = trim( (string) exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) ) );
		$remote = trim( (string) exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) ) );
		$commit = trim( (string) exec( sprintf( 'git -C %s log -1 --format="%%h %%s" 2>/dev/null', $escaped ) ) );
		$status = trim( (string) exec( sprintf( 'git -C %s status --porcelain 2>/dev/null | wc -l', $escaped ) ) );
		// phpcs:enable

		WP_CLI::log( sprintf( 'Name:     %s', $name ) );
		WP_CLI::log( sprintf( 'Path:     %s', $repo_path ) );
		WP_CLI::log( sprintf( 'Branch:   %s', $branch ?: '-' ) );
		WP_CLI::log( sprintf( 'Remote:   %s', $remote ?: '-' ) );
		WP_CLI::log( sprintf( 'Latest:   %s', $commit ?: '-' ) );
		WP_CLI::log( sprintf( 'Dirty:    %s', ( '0' === $status ) ? 'no' : "yes ({$status} files)" ) );
	}
}
