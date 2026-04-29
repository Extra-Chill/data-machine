<?php
/**
 * Agent Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent identity operations.
 * Provides rename functionality for first-class agent identities.
 *
 * @package DataMachine\Abilities
 * @since 0.38.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class AgentAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/rename-agent',
				array(
					'label'               => 'Rename Agent',
					'description'         => 'Rename an agent slug — updates database and moves filesystem directory',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'old_slug', 'new_slug' ),
						'properties' => array(
							'old_slug' => array(
								'type'        => 'string',
								'description' => 'Current agent slug.',
							),
							'new_slug' => array(
								'type'        => 'string',
								'description' => 'New agent slug (sanitized automatically).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'message'  => array( 'type' => 'string' ),
							'old_slug' => array( 'type' => 'string' ),
							'new_slug' => array( 'type' => 'string' ),
							'old_path' => array( 'type' => 'string' ),
							'new_path' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'renameAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/list-agents',
				array(
					'label'               => 'List Agents',
					'description'         => 'List agents accessible to the caller. Defaults to the caller\'s own accessible agents; admins can escalate to all agents or query other users.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'        => array(
								'type'        => 'string',
								'enum'        => array( 'mine', 'all' ),
								'description' => '"mine" (default) returns agents the caller can access (owned + granted). "all" returns every agent on the site (admin-only).',
							),
							'user_id'      => array(
								'type'        => 'integer',
								'description' => 'Resolve accessible agents for this user instead of the caller. Non-admins are forced to themselves. Ignored when scope=all.',
							),
							'site_id'      => array(
								'type'        => 'integer',
								'description' => 'Filter by site_scope. Matches the exact site OR network-wide (NULL) agents. Defaults to current blog.',
							),
							'include_role' => array(
								'type'        => 'boolean',
								'description' => 'When true, enriches each row with the resolved user\'s role on that agent.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agents'  => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'agent_id'    => array( 'type' => 'integer' ),
										'agent_slug'  => array( 'type' => 'string' ),
										'agent_name'  => array( 'type' => 'string' ),
										'owner_id'    => array( 'type' => 'integer' ),
										'site_scope'  => array( 'type' => array( 'integer', 'null' ) ),
										'description' => array( 'type' => 'string' ),
										'is_owner'    => array( 'type' => 'boolean' ),
										'user_role'   => array( 'type' => array( 'string', 'null' ) ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listAgents' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ) || PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/create-agent',
				array(
					'label'               => 'Create Agent',
					'description'         => 'Create a new agent identity with filesystem directory and owner access. Admins can create agents for any user. Non-admins with create_own_agent can create one agent for themselves.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'agent_slug' ),
						'properties' => array(
							'agent_slug' => array(
								'type'        => 'string',
								'description' => 'Unique agent slug (sanitized automatically).',
							),
							'agent_name' => array(
								'type'        => 'string',
								'description' => 'Display name (defaults to slug if omitted).',
							),
							'owner_id'   => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID of the agent owner. Non-admins can only create agents for themselves (this field is ignored).',
							),
							'config'     => array(
								'type'        => 'object',
								'description' => 'Agent configuration object.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'agent_id'   => array( 'type' => 'integer' ),
							'agent_slug' => array( 'type' => 'string' ),
							'agent_name' => array( 'type' => 'string' ),
							'owner_id'   => array( 'type' => 'integer' ),
							'agent_dir'  => array( 'type' => 'string' ),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'createAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage() || PermissionHelper::can( 'create_own_agent' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/import-agent',
				array(
					'label'               => 'Import Agent',
					'description'         => 'Materialize a portable agent bundle from a local bundle directory, JSON file, or ZIP archive.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'source' ),
						'properties' => array(
							'source'      => array(
								'type'        => 'string',
								'description' => 'Local bundle source path. Supports bundle directories, .json files, and .zip archives.',
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => 'Optional target agent slug override.',
							),
							'on_conflict' => array(
								'type'        => 'string',
								'enum'        => array( 'error', 'skip' ),
								'description' => 'How to handle an existing target agent slug.',
							),
							'owner_id'    => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID that should own the imported agent.',
							),
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'Validate and summarize without writing.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'skipped'       => array( 'type' => 'boolean' ),
							'agent_id'      => array( 'type' => 'integer' ),
							'agent_slug'    => array( 'type' => 'string' ),
							'imported'      => array( 'type' => 'object' ),
							'auth_warnings' => array( 'type' => 'array' ),
							'summary'       => array( 'type' => 'object' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'importAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-agent',
				array(
					'label'               => 'Get Agent',
					'description'         => 'Retrieve a single agent by slug or ID with access grants and directory info',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_slug' => array(
								'type'        => 'string',
								'description' => 'Agent slug (provide this or agent_id).',
							),
							'agent_id'   => array(
								'type'        => 'integer',
								'description' => 'Agent ID (provide this or agent_slug).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agent'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			wp_register_ability(
				'datamachine/update-agent',
				array(
					'label'               => 'Update Agent',
					'description'         => 'Update an agent\'s mutable fields (name, config)',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'agent_id' ),
						'properties' => array(
							'agent_id'     => array(
								'type'        => 'integer',
								'description' => 'Agent ID to update.',
							),
							'agent_name'   => array(
								'type'        => 'string',
								'description' => 'New display name.',
							),
							'agent_config' => array(
								'type'        => 'object',
								'description' => 'New agent configuration (replaces existing config).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agent'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'updateAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/delete-agent',
				array(
					'label'               => 'Delete Agent',
					'description'         => 'Delete an agent record and access grants, optionally removing filesystem directory',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_slug'   => array(
								'type'        => 'string',
								'description' => 'Agent slug (provide this or agent_id).',
							),
							'agent_id'     => array(
								'type'        => 'integer',
								'description' => 'Agent ID (provide this or agent_slug).',
							),
							'delete_files' => array(
								'type'        => 'boolean',
								'description' => 'Also delete filesystem directory and contents.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'agent_id'      => array( 'type' => 'integer' ),
							'agent_slug'    => array( 'type' => 'string' ),
							'files_deleted' => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'deleteAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Rename an agent — update DB slug and move filesystem directory.
	 *
	 * @param array $input Input parameters with old_slug and new_slug.
	 * @return array Result.
	 */
	public static function renameAgent( array $input ): array {
		$old_slug = sanitize_title( $input['old_slug'] );
		$new_slug = sanitize_title( $input['new_slug'] );

		if ( $old_slug === $new_slug ) {
			return array(
				'success' => false,
				'message' => 'Old and new slugs are identical.',
			);
		}

		if ( empty( $new_slug ) ) {
			return array(
				'success' => false,
				'message' => 'New slug cannot be empty.',
			);
		}

		$agents_repo = new Agents();

		// Validate source exists.
		$existing = $agents_repo->get_by_slug( $old_slug );

		if ( ! $existing ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Agent with slug "%s" not found.', $old_slug ),
			);
		}

		// Validate target is free.
		$conflict = $agents_repo->get_by_slug( $new_slug );

		if ( $conflict ) {
			return array(
				'success' => false,
				'message' => sprintf( 'An agent with slug "%s" already exists.', $new_slug ),
			);
		}

		$agent_id          = (int) $existing['agent_id'];
		$directory_manager = new DirectoryManager();
		$old_path          = $directory_manager->get_agent_identity_directory( $old_slug );
		$new_path          = $directory_manager->get_agent_identity_directory( $new_slug );

		// Move directory first — easier to roll back than a DB change.
		$dir_moved = false;

		if ( is_dir( $old_path ) ) {
			if ( is_dir( $new_path ) ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Target directory "%s" already exists.', $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$dir_moved = rename( $old_path, $new_path );

			if ( ! $dir_moved ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Failed to move directory from "%s" to "%s".', $old_path, $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}
		}

		// Update database.
		$db_ok = $agents_repo->update_slug( $agent_id, $new_slug );

		if ( ! $db_ok ) {
			// Roll back directory move.
			if ( $dir_moved ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $new_path, $old_path );
			}

			return array(
				'success'  => false,
				'message'  => 'Database update failed. Directory change reverted.',
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
				'old_path' => $old_path,
				'new_path' => $new_path,
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				'Agent renamed from "%s" to "%s".%s',
				$old_slug,
				$new_slug,
				$dir_moved ? ' Directory moved.' : ' No directory to move.'
			),
			'old_slug' => $old_slug,
			'new_slug' => $new_slug,
			'old_path' => $old_path,
			'new_path' => $new_path,
		);
	}

	/**
	 * List registered agents, scoped to the current site.
	 *
	 * On multisite, returns agents with site_scope matching the current blog_id
	 * OR site_scope IS NULL (network-wide). This mirrors WordPress core's default
	 * of scoping user queries to the current site via wp_N_capabilities meta.
	 *
	 * @since 0.38.0
	 * @since 0.57.0 Added site_scope filtering and site_scope in output.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function listAgents( array $input ): array {
		// ---- Parameter resolution ----------------------------------------
		$scope             = isset( $input['scope'] ) ? (string) $input['scope'] : 'mine';
		$requested_user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$site_id           = isset( $input['site_id'] ) ? (int) $input['site_id'] : get_current_blog_id();
		$include_role      = ! empty( $input['include_role'] );

		if ( ! in_array( $scope, array( 'mine', 'all' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid scope. Allowed values: "mine", "all".',
			);
		}

		$is_admin  = PermissionHelper::can_manage();
		$caller_id = PermissionHelper::acting_user_id();

		// ---- Escalation checks -------------------------------------------
		if ( 'all' === $scope && ! $is_admin ) {
			return array(
				'success' => false,
				'error'   => 'scope=all requires admin privileges.',
			);
		}

		// Non-admins are always forced to self. Admin omitting user_id also
		// defaults to self (the intuitive "show me MY accessible agents").
		if ( $requested_user_id > 0 && $requested_user_id !== $caller_id && ! $is_admin ) {
			return array(
				'success' => false,
				'error'   => 'Querying another user\'s agents requires admin privileges.',
			);
		}

		$target_user_id = $requested_user_id > 0 ? $requested_user_id : $caller_id;

		$agents_repo = new Agents();
		$access_repo = new AgentAccess();

		// ---- Resolve candidate rows --------------------------------------
		if ( 'all' === $scope ) {
			// Admin firehose: all agents on the requested site.
			$candidates = $agents_repo->get_all( array( 'site_id' => $site_id ) );
		} else {
			if ( $target_user_id <= 0 ) {
				return array(
					'success' => true,
					'agents'  => array(),
				);
			}

			// Union of agents the target user OWNS plus agents they have
			// ACCESS GRANTS to. The owner relationship lives on
			// datamachine_agents.owner_id, not on agent_access, so merging
			// both sides is the only way to get the complete picture.
			$owned        = $agents_repo->get_all_by_owner_id( $target_user_id );
			$owned_ids    = array_map( static fn( $a ) => (int) $a['agent_id'], $owned );
			$granted_ids  = array_map( 'intval', $access_repo->get_agent_ids_for_user( $target_user_id ) );
			$extra_ids    = array_values( array_diff( $granted_ids, $owned_ids ) );
			$granted_rows = ! empty( $extra_ids ) ? $agents_repo->get_agents_by_ids( $extra_ids ) : array();

			$candidates = array_merge( $owned, $granted_rows );

			// Site scoping: match the requested site OR network-wide (NULL).
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $row ) use ( $site_id ) {
						$scope_value = $row['site_scope'] ?? null;
						return null === $scope_value || (int) $scope_value === $site_id;
					}
				)
			);
		}

		// ---- Final access gate (mine only) -------------------------------
		//
		// When listing the caller's OWN agents, defence-in-depth: run each
		// row through can_access_agent() so the filter `datamachine_can_access_agent`
		// still has the final say. When the admin queries another user via
		// `user_id`, this gate is skipped — the admin permission check above
		// is authoritative and we must not accidentally filter out agents
		// the target user can actually access.
		if ( 'mine' === $scope && $target_user_id === $caller_id ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static fn( $row ) => PermissionHelper::can_access_agent( (int) $row['agent_id'] )
				)
			);
		}

		// ---- Role enrichment (optional) ----------------------------------
		// Computed against $target_user_id so `include_role=true` reflects
		// the resolved user's role even when an admin queries on their behalf.
		$agents = array();

		foreach ( $candidates as $row ) {
			$agent_id    = (int) $row['agent_id'];
			$owner_id    = (int) $row['owner_id'];
			$config      = is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array();
			$description = isset( $config['description'] ) ? (string) $config['description'] : '';

			$item = array(
				'agent_id'    => $agent_id,
				'agent_slug'  => (string) $row['agent_slug'],
				'agent_name'  => (string) $row['agent_name'],
				'owner_id'    => $owner_id,
				'site_scope'  => isset( $row['site_scope'] ) ? (int) $row['site_scope'] : null,
				'description' => $description,
				'is_owner'    => $target_user_id > 0 && $owner_id === $target_user_id,
			);

			if ( $include_role ) {
				if ( $target_user_id > 0 && $owner_id === $target_user_id ) {
					$item['user_role'] = 'admin';
				} elseif ( $target_user_id > 0 ) {
					$grant             = $access_repo->get_access( $agent_id, $target_user_id );
					$item['user_role'] = $grant && isset( $grant['role'] ) ? (string) $grant['role'] : null;
				} else {
					$item['user_role'] = null;
				}
			}

			$agents[] = $item;
		}

		return array(
			'success' => true,
			'agents'  => $agents,
		);
	}

	/**
	 * Import an agent bundle through the generic ability surface.
	 *
	 * @param array $input Import parameters.
	 * @return array<string,mixed>
	 */
	public static function importAgent( array $input ): array {
		$source = trim( (string) ( $input['source'] ?? '' ) );
		if ( '' === $source || ! file_exists( $source ) ) {
			return array(
				'success' => false,
				'error'   => 'Bundle source not found.',
			);
		}

		$on_conflict = (string) ( $input['on_conflict'] ?? 'error' );
		if ( ! in_array( $on_conflict, array( 'error', 'skip' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'on_conflict must be one of: error, skip.',
			);
		}

		$owner_id = self::resolve_import_owner_id( isset( $input['owner_id'] ) ? (int) $input['owner_id'] : 0 );
		if ( $owner_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Unable to resolve import owner. Pass owner_id, authenticate as a user, or set datamachine_default_owner_id.',
			);
		}

		$bundler = new AgentBundler();
		$bundle  = self::load_import_bundle( $bundler, $source );
		if ( ! is_array( $bundle ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse bundle. Use a bundle directory, .json file, or .zip archive.',
			);
		}

		$slug = sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		if ( isset( $input['slug'] ) && '' !== trim( (string) $input['slug'] ) ) {
			$slug = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['slug'] ) && '' !== $slug ) {
			$bundle['agent']['agent_slug'] = $slug;
		}
		$existing = $slug ? ( new Agents() )->get_by_slug( $slug ) : null;

		$auth_resolution = self::resolve_import_auth_refs( $bundle );
		$bundle          = $auth_resolution['bundle'];
		$auth_warnings   = $auth_resolution['warnings'];

		if ( $existing && 'skip' === $on_conflict ) {
			return array(
				'success'       => true,
				'skipped'       => true,
				'agent_id'      => (int) $existing['agent_id'],
				'agent_slug'    => $slug,
				'auth_warnings' => $auth_warnings,
				'message'       => sprintf( 'Agent "%s" already exists; import skipped.', $slug ),
			);
		}

		if ( $existing ) {
			return array(
				'success'    => false,
				'agent_id'   => (int) $existing['agent_id'],
				'agent_slug' => $slug,
				'error'      => sprintf( 'Agent slug "%s" already exists. Use on_conflict=skip to no-op, or import with a new slug.', $slug ),
			);
		}

		$result = $bundler->import( $bundle, null, $owner_id, ! empty( $input['dry_run'] ) );
		if ( empty( $result['success'] ) ) {
			$result['auth_warnings'] = $auth_warnings;
			return $result;
		}

		$summary  = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$imported = array(
			'pipelines' => (int) ( $summary['pipelines_imported'] ?? 0 ),
			'flows'     => (int) ( $summary['flows_imported'] ?? 0 ),
			'files'     => (int) ( $summary['files'] ?? 0 ),
		);

		$result['agent_id']      = (int) ( $summary['agent_id'] ?? 0 );
		$result['agent_slug']    = (string) ( $summary['agent_slug'] ?? $slug );
		$result['imported']      = $imported;
		$result['auth_warnings'] = $auth_warnings;

		return $result;
	}

	private static function load_import_bundle( AgentBundler $bundler, string $source ): ?array {
		if ( is_dir( $source ) ) {
			return $bundler->from_directory( $source );
		}

		if ( preg_match( '/\.zip$/i', $source ) ) {
			return $bundler->from_zip( $source );
		}

		if ( preg_match( '/\.json$/i', $source ) ) {
			return $bundler->from_json( (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return null;
	}

	private static function resolve_import_owner_id( int $explicit_owner_id ): int {
		if ( $explicit_owner_id > 0 ) {
			return $explicit_owner_id;
		}

		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $current_user_id > 0 ) {
			return $current_user_id;
		}

		$default_owner_id = function_exists( 'get_option' ) ? (int) get_option( 'datamachine_default_owner_id', 0 ) : 0;
		return $default_owner_id > 0 ? $default_owner_id : 0;
	}

	/**
	 * Resolve auth_ref markers into local handler configs before import.
	 *
	 * @param array $bundle Legacy bundle array.
	 * @return array{bundle: array, warnings: array<int,array<string,string>>}
	 */
	private static function resolve_import_auth_refs( array $bundle ): array {
		$warnings = array();
		if ( ! is_array( $bundle['flows'] ?? null ) ) {
			return array(
				'bundle'   => $bundle,
				'warnings' => $warnings,
			);
		}

		foreach ( $bundle['flows'] as $flow_index => &$flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$disable_flow = false;
			if ( ! is_array( $flow['flow_config'] ?? null ) ) {
				continue;
			}

			foreach ( $flow['flow_config'] as $flow_step_id => &$step ) {
				if ( ! is_array( $step ) || ! is_array( $step['handler_configs'] ?? null ) ) {
					continue;
				}
				foreach ( $step['handler_configs'] as $handler_slug => &$handler_config ) {
					if ( ! is_array( $handler_config ) || empty( $handler_config['auth_ref'] ) ) {
						continue;
					}

					$resolved = apply_filters( 'datamachine_auth_ref_to_handler_config', $handler_config, (string) $handler_slug, array( 'import' => true ) );
					if ( is_wp_error( $resolved ) ) {
						$disable_flow = true;

						$warnings[] = array(
							'flow'         => (string) ( $flow['portable_slug'] ?? ( $flow['flow_name'] ?? '' ) ),
							'flow_step_id' => (string) $flow_step_id,
							'handler_slug' => (string) $handler_slug,
							'auth_ref'     => (string) $handler_config['auth_ref'],
							'code'         => $resolved->get_error_code(),
							'message'      => $resolved->get_error_message(),
						);
						continue;
					}

					if ( is_array( $resolved ) ) {
						$handler_config = $resolved;
					}
				}
				unset( $handler_config );
			}
			unset( $step );

			if ( $disable_flow ) {
				$scheduling_config                    = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
				$scheduling_config['enabled']         = false;
				$scheduling_config['interval']        = 'manual';
				$scheduling_config['disabled_reason'] = 'unresolved_auth_ref';
				$flow['scheduling_config']            = $scheduling_config;
			}
		}
		unset( $flow );

		return array(
			'bundle'   => $bundle,
			'warnings' => $warnings,
		);
	}

	/**
	 * Create a new agent.
	 *
	 * @param array $input { agent_slug, agent_name, owner_id, config? }.
	 * @return array Result with agent_id on success.
	 */
	public static function createAgent( array $input ): array {
		$slug     = sanitize_title( $input['agent_slug'] ?? '' );
		$name     = sanitize_text_field( $input['agent_name'] ?? '' );
		$owner_id = (int) ( $input['owner_id'] ?? 0 );
		$config   = $input['config'] ?? array();

		if ( empty( $slug ) ) {
			return array(
				'success' => false,
				'error'   => 'Agent slug is required.',
			);
		}

		if ( empty( $name ) ) {
			$name = $slug;
		}

		// Self-service creation: non-admins can only create agents for themselves.
		$is_admin = PermissionHelper::can_manage();

		if ( ! $is_admin ) {
			// Force owner to the acting user — non-admins cannot create agents for others.
			$owner_id = PermissionHelper::acting_user_id();

			if ( $owner_id <= 0 ) {
				return array(
					'success' => false,
					'error'   => 'Could not determine acting user for self-service agent creation.',
				);
			}

			// Enforce per-user agent limit for non-admins.
			$agents_repo = new Agents();
			$existing    = $agents_repo->get_by_owner_id( $owner_id );

			/**
			 * Filter the maximum number of agents a non-admin user can create.
			 *
			 * @since 0.52.0
			 *
			 * @param int $limit    Maximum agents per user. Default 1.
			 * @param int $owner_id The user creating the agent.
			 */
			$max_agents = (int) apply_filters( 'datamachine_max_agents_per_user', 1, $owner_id );

			if ( $existing && $max_agents <= 1 ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						'You already have an agent ("%s"). Non-admin users are limited to %d agent.',
						$existing['agent_name'],
						$max_agents
					),
				);
			}

			// For limits > 1, count all agents owned by this user.
			if ( $max_agents > 1 ) {
				$owned = $agents_repo->get_all_by_owner_id( $owner_id );

				if ( count( $owned ) >= $max_agents ) {
					return array(
						'success' => false,
						'error'   => sprintf(
							'You already have %d agent(s). Non-admin users are limited to %d.',
							count( $owned ),
							$max_agents
						),
					);
				}
			}
		}

		if ( $owner_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Owner user ID is required (--owner=<user_id>).',
			);
		}

		$user = get_user_by( 'id', $owner_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Owner user ID %d not found.', $owner_id ),
			);
		}

		$agents_repo = isset( $agents_repo ) ? $agents_repo : new Agents();

		// Check for conflict.
		$existing = $agents_repo->get_by_slug( $slug );
		if ( $existing ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent with slug "%s" already exists (ID: %d).', $slug, $existing['agent_id'] ),
			);
		}

		$agent_id = $agents_repo->create_if_missing( $slug, $name, $owner_id, $config );

		if ( ! $agent_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent in database.',
			);
		}

		// Bootstrap owner access.
		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
		$access_repo->bootstrap_owner_access( $agent_id, $owner_id );

		// Ensure agent directory exists.
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );
		$directory_manager->ensure_directory_exists( $agent_dir );

		// Scaffold agent-layer memory files (SOUL.md, MEMORY.md) with identity context.
		$scaffold_ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $scaffold_ability ) {
			$scaffold_ability->execute( array(
				'layer'      => 'agent',
				'agent_slug' => $slug,
				'agent_id'   => $agent_id,
			) );
		}

		/**
		 * Fires after a new agent has been created.
		 *
		 * @since 0.65.0
		 *
		 * @param int    $agent_id Agent ID.
		 * @param string $slug     Agent slug.
		 * @param string $name     Agent display name.
		 */
		do_action( 'datamachine_agent_created', $agent_id, $slug, $name );

		return array(
			'success'    => true,
			'agent_id'   => $agent_id,
			'agent_slug' => $slug,
			'agent_name' => $name,
			'owner_id'   => $owner_id,
			'agent_dir'  => $agent_dir,
			'message'    => sprintf( 'Agent "%s" created (ID: %d).', $slug, $agent_id ),
		);
	}

	/**
	 * Get a single agent by slug or ID.
	 *
	 * @param array $input { agent_slug or agent_id }.
	 * @return array Agent data or error.
	 */
	public static function getAgent( array $input ): array {
		$agents_repo = new Agents();
		$agent       = null;

		if ( ! empty( $input['agent_slug'] ) ) {
			$agent = $agents_repo->get_by_slug( sanitize_title( $input['agent_slug'] ) );
		} elseif ( ! empty( $input['agent_id'] ) ) {
			$agent = $agents_repo->get_agent( (int) $input['agent_id'] );
		}

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		// Enrich with access grants.
		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
		$access      = $access_repo->get_users_for_agent( (int) $agent['agent_id'] );

		// Check for agent directory.
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $agent['agent_slug'] );

		return array(
			'success' => true,
			'agent'   => array(
				'agent_id'     => (int) $agent['agent_id'],
				'agent_slug'   => (string) $agent['agent_slug'],
				'agent_name'   => (string) $agent['agent_name'],
				'owner_id'     => (int) $agent['owner_id'],
				'agent_config' => is_array( $agent['agent_config'] ?? null )
					? $agent['agent_config']
					: ( json_decode( $agent['agent_config'] ?? '{}', true ) ? json_decode( $agent['agent_config'] ?? '{}', true ) : array() ),
				'created_at'   => $agent['created_at'] ?? '',
				'updated_at'   => $agent['updated_at'] ?? '',
				'agent_dir'    => $agent_dir,
				'has_files'    => is_dir( $agent_dir ),
				'access'       => $access,
			),
		);
	}

	/**
	 * Update an agent's mutable fields.
	 *
	 * @param array $input { agent_id, agent_name?, agent_config? }.
	 * @return array Result with updated agent data.
	 */
	public static function updateAgent( array $input ): array {
		$agent_id = (int) ( $input['agent_id'] ?? 0 );

		if ( $agent_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'agent_id is required.',
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent ID %d not found.', $agent_id ),
			);
		}

		// Build update payload from allowed mutable fields.
		$update = array();

		if ( isset( $input['agent_name'] ) ) {
			$name = sanitize_text_field( $input['agent_name'] );
			if ( empty( $name ) ) {
				return array(
					'success' => false,
					'error'   => 'Agent name cannot be empty.',
				);
			}
			$update['agent_name'] = $name;
		}

		if ( array_key_exists( 'agent_config', $input ) ) {
			$update['agent_config'] = is_array( $input['agent_config'] ) ? $input['agent_config'] : array();
		}

		if ( empty( $update ) ) {
			return array(
				'success' => false,
				'error'   => 'No fields to update. Provide agent_name or agent_config.',
			);
		}

		// Capture old name before update for propagation.
		$old_name = (string) $agent['agent_name'];

		$ok = $agents_repo->update_agent( $agent_id, $update );

		if ( ! $ok ) {
			return array(
				'success' => false,
				'error'   => 'Database update failed.',
			);
		}

		// Propagate name change to agent memory files.
		if ( isset( $update['agent_name'] ) && $update['agent_name'] !== $old_name ) {
			self::propagateNameChange(
				(string) $agent['agent_slug'],
				$old_name,
				$update['agent_name']
			);
		}

		/**
		 * Fires after an agent has been updated.
		 *
		 * @since 0.65.0
		 *
		 * @param int $agent_id Agent ID.
		 */
		do_action( 'datamachine_agent_updated', $agent_id );

		// Return the updated agent.
		return self::getAgent( array( 'agent_id' => $agent_id ) );
	}

	/**
	 * Propagate an agent name change across memory files.
	 *
	 * Performs a find-and-replace of the old name with the new name in
	 * SOUL.md and MEMORY.md. Only touches files that exist and contain
	 * the old name. Uses whole-word matching to avoid partial replacements.
	 *
	 * @since 0.51.0
	 *
	 * @param string $agent_slug Agent slug (for directory resolution).
	 * @param string $old_name   Previous agent display name.
	 * @param string $new_name   New agent display name.
	 * @return array { files_updated: string[], files_skipped: string[] }
	 */
	private static function propagateNameChange( string $agent_slug, string $old_name, string $new_name ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $agent_slug );

		if ( ! is_dir( $agent_dir ) ) {
			return array(
				'files_updated' => array(),
				'files_skipped' => array(),
			);
		}

		$target_files  = array( 'SOUL.md', 'MEMORY.md' );
		$files_updated = array();
		$files_skipped = array();

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		foreach ( $target_files as $filename ) {
			$filepath = trailingslashit( $agent_dir ) . $filename;

			if ( ! file_exists( $filepath ) ) {
				$files_skipped[] = $filename;
				continue;
			}

			$content = $wp_filesystem->get_contents( $filepath );

			if ( false === $content || false === strpos( $content, $old_name ) ) {
				$files_skipped[] = $filename;
				continue;
			}

			$updated_content = str_replace( $old_name, $new_name, $content );

			if ( $updated_content === $content ) {
				$files_skipped[] = $filename;
				continue;
			}

			$wp_filesystem->put_contents( $filepath, $updated_content, FS_CHMOD_FILE );
			$files_updated[] = $filename;
		}

		if ( ! empty( $files_updated ) ) {
			do_action(
				'datamachine_log',
				'info',
				sprintf(
					'Agent name changed from "%s" to "%s". Updated: %s.',
					$old_name,
					$new_name,
					implode( ', ', $files_updated )
				),
				array(
					'agent_slug'    => $agent_slug,
					'old_name'      => $old_name,
					'new_name'      => $new_name,
					'files_updated' => $files_updated,
					'files_skipped' => $files_skipped,
				)
			);
		}

		return array(
			'files_updated' => $files_updated,
			'files_skipped' => $files_skipped,
		);
	}

	/**
	 * Delete an agent.
	 *
	 * Removes the agent record and access grants. Does NOT delete
	 * the filesystem directory (use --delete-files for that).
	 *
	 * @param array $input { agent_slug or agent_id, delete_files? }.
	 * @return array Result.
	 */
	public static function deleteAgent( array $input ): array {
		$agents_repo = new Agents();
		$agent       = null;

		if ( ! empty( $input['agent_slug'] ) ) {
			$agent = $agents_repo->get_by_slug( sanitize_title( $input['agent_slug'] ) );
		} elseif ( ! empty( $input['agent_id'] ) ) {
			$agent = $agents_repo->get_agent( (int) $input['agent_id'] );
		}

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		$agent_id = (int) $agent['agent_id'];
		$slug     = (string) $agent['agent_slug'];

		// Delete access grants.
		global $wpdb;
		$access_table = $wpdb->base_prefix . 'datamachine_agent_access';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $access_table, array( 'agent_id' => $agent_id ) );

		// Delete agent record.
		$agents_table = $wpdb->base_prefix . 'datamachine_agents';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $agents_table, array( 'agent_id' => $agent_id ) );

		if ( false === $deleted ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete agent from database.',
			);
		}

		// Optionally delete files.
		$files_deleted = false;
		if ( ! empty( $input['delete_files'] ) ) {
			$directory_manager = new DirectoryManager();
			$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );
			if ( is_dir( $agent_dir ) ) {
				// Recursive delete.
				$iterator = new \RecursiveDirectoryIterator( $agent_dir, \RecursiveDirectoryIterator::SKIP_DOTS );
				$files    = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $files as $file ) {
					if ( $file->isDir() ) {
						rmdir( $file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- DirectoryManager owns agent filesystem paths.
					} else {
						wp_delete_file( $file->getRealPath() );
					}
				}
				rmdir( $agent_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- DirectoryManager owns agent filesystem paths.
				$files_deleted = true;
			}
		}

		/**
		 * Fires after an agent has been deleted.
		 *
		 * @since 0.65.0
		 *
		 * @param int    $agent_id Agent ID (no longer exists in DB).
		 * @param string $slug     Agent slug.
		 */
		do_action( 'datamachine_agent_deleted', $agent_id, $slug );

		return array(
			'success'       => true,
			'agent_id'      => $agent_id,
			'agent_slug'    => $slug,
			'files_deleted' => $files_deleted,
			'message'       => sprintf( 'Agent "%s" (ID: %d) deleted.%s', $slug, $agent_id, $files_deleted ? ' Files removed.' : '' ),
		);
	}
}
