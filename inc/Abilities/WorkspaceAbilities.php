<?php
/**
 * Workspace Abilities
 *
 * WordPress 6.9 Abilities API primitives for all agent workspace operations.
 * These are the canonical entry points — CLI commands and chat tools delegate here.
 *
 * Read-only abilities (path, list, show, read, ls) are exposed via REST.
 * Mutating abilities (clone, remove) are CLI-only (show_in_rest = false).
 *
 * @package DataMachine\Abilities
 * @since 0.31.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\Workspace;
use DataMachine\Core\FilesRepository\WorkspaceReader;
use DataMachine\Core\FilesRepository\WorkspaceWriter;

defined( 'ABSPATH' ) || exit;

class WorkspaceAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {

			// -----------------------------------------------------------------
			// Read-only discovery abilities (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-path',
				array(
					'label'               => 'Get Workspace Path',
					'description'         => 'Get the agent workspace directory path. Optionally create the directory.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'ensure' => array(
								'type'        => 'boolean',
								'description' => 'Create the workspace directory if it does not exist.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'exists'  => array( 'type' => 'boolean' ),
							'created' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPath' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-list',
				array(
					'label'               => 'List Workspace Repos',
					'description'         => 'List repositories in the agent workspace.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'repos'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name'   => array( 'type' => 'string' ),
										'path'   => array( 'type' => 'string' ),
										'git'    => array( 'type' => 'boolean' ),
										'remote' => array( 'type' => 'string' ),
										'branch' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listRepos' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-show',
				array(
					'label'               => 'Show Workspace Repo',
					'description'         => 'Show detailed info about a workspace repository (branch, remote, latest commit, dirty status).',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Repository directory name.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'branch'  => array( 'type' => 'string' ),
							'remote'  => array( 'type' => 'string' ),
							'commit'  => array( 'type' => 'string' ),
							'dirty'   => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'showRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// File reading abilities (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-read',
				array(
					'label'               => 'Read Workspace File',
					'description'         => 'Read the contents of a text file from a workspace repository.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Repository directory name.',
							),
							'path'     => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'max_size' => array(
								'type'        => 'integer',
								'description' => 'Maximum file size in bytes (default 1 MB).',
							),
						),
						'required'   => array( 'repo', 'path' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'content' => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'size'    => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'readFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-ls',
				array(
					'label'               => 'List Workspace Directory',
					'description'         => 'List directory contents within a workspace repository.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Repository directory name.',
							),
							'path' => array(
								'type'        => 'string',
								'description' => 'Relative directory path within the repo (omit for root).',
							),
						),
						'required'   => array( 'repo' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'repo'    => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'entries' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name' => array( 'type' => 'string' ),
										'type' => array( 'type' => 'string' ),
										'size' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listDirectory' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// Mutating abilities (show_in_rest = false, CLI-only).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-clone',
				array(
					'label'               => 'Clone Workspace Repo',
					'description'         => 'Clone a git repository into the workspace.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'url'  => array(
								'type'        => 'string',
								'description' => 'Git repository URL to clone.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Directory name override (derived from URL if omitted).',
							),
						),
						'required'   => array( 'url' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'cloneRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-remove',
				array(
					'label'               => 'Remove Workspace Repo',
					'description'         => 'Remove a repository from the workspace.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Repository directory name to remove.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'removeRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-write',
				array(
					'label'               => 'Write Workspace File',
					'description'         => 'Create or overwrite a file in a workspace repository.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'    => array(
								'type'        => 'string',
								'description' => 'Repository directory name.',
							),
							'path'    => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'File content to write.',
							),
						),
						'required'   => array( 'repo', 'path', 'content' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'size'    => array( 'type' => 'integer' ),
							'created' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'writeFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-edit',
				array(
					'label'               => 'Edit Workspace File',
					'description'         => 'Find-and-replace text in a workspace repository file.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository directory name.',
							),
							'path'        => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'old_string'  => array(
								'type'        => 'string',
								'description' => 'Text to find.',
							),
							'new_string'  => array(
								'type'        => 'string',
								'description' => 'Replacement text.',
							),
							'replace_all' => array(
								'type'        => 'boolean',
								'description' => 'Replace all occurrences (default false).',
							),
						),
						'required'   => array( 'repo', 'path', 'old_string', 'new_string' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'path'         => array( 'type' => 'string' ),
							'replacements' => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'editFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// =========================================================================
	// Ability callbacks
	// =========================================================================

	/**
	 * Get workspace path, optionally ensuring the directory exists.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function getPath( array $input ): array {
		$workspace = new Workspace();

		if ( ! empty( $input['ensure'] ) ) {
			$result = $workspace->ensure_exists();
			return array(
				'success' => $result['success'],
				'path'    => $workspace->get_path(),
				'exists'  => $result['success'],
				'created' => $result['created'] ?? false,
				'message' => $result['message'] ?? null,
			);
		}

		return array(
			'success' => true,
			'path'    => $workspace->get_path(),
			'exists'  => is_dir( $workspace->get_path() ),
		);
	}

	/**
	 * List workspace repos.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function listRepos( array $input ): array {
		$workspace = new Workspace();
		return $workspace->list_repos();
	}

	/**
	 * Show detailed repo info.
	 *
	 * @param array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function showRepo( array $input ): array {
		$workspace = new Workspace();
		return $workspace->show_repo( $input['name'] ?? '' );
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', 'path', optional 'max_size'.
	 * @return array Result.
	 */
	public static function readFile( array $input ): array {
		$workspace = new Workspace();
		$reader    = new WorkspaceReader( $workspace );

		$args = array(
			$input['repo'] ?? '',
			$input['path'] ?? '',
		);

		if ( isset( $input['max_size'] ) ) {
			$args[] = (int) $input['max_size'];
		}

		return $reader->read_file( ...$args );
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', optional 'path'.
	 * @return array Result.
	 */
	public static function listDirectory( array $input ): array {
		$workspace = new Workspace();
		$reader    = new WorkspaceReader( $workspace );

		return $reader->list_directory(
			$input['repo'] ?? '',
			$input['path'] ?? null
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param array $input Input parameters with 'url', optional 'name'.
	 * @return array Result.
	 */
	public static function cloneRepo( array $input ): array {
		$workspace = new Workspace();
		return $workspace->clone_repo(
			$input['url'] ?? '',
			$input['name'] ?? null
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function removeRepo( array $input ): array {
		$workspace = new Workspace();
		return $workspace->remove_repo( $input['name'] ?? '' );
	}

	/**
	 * Write (create or overwrite) a file in a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', 'path', 'content'.
	 * @return array Result.
	 */
	public static function writeFile( array $input ): array {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter( $workspace );

		return $writer->write_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$input['content'] ?? ''
		);
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * @param array $input Input parameters with 'repo', 'path', 'old_string', 'new_string', optional 'replace_all'.
	 * @return array Result.
	 */
	public static function editFile( array $input ): array {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter( $workspace );

		return $writer->edit_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$input['old_string'] ?? '',
			$input['new_string'] ?? '',
			! empty( $input['replace_all'] )
		);
	}
}
