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
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class AgentAbilities {

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
					'description'         => 'List all registered agent identities',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
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
										'agent_id'   => array( 'type' => 'integer' ),
										'agent_slug' => array( 'type' => 'string' ),
										'agent_name' => array( 'type' => 'string' ),
										'owner_id'   => array( 'type' => 'integer' ),
										'status'     => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listAgents' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
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
					'description'         => 'Update an agent\'s mutable fields (name, config, status)',
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
							'status'       => array(
								'type'        => 'string',
								'description' => 'New status (active, inactive, archived).',
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
	public static function listAgents( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_Ability interface.
		$agents_repo = new Agents();
		$rows        = $agents_repo->get_all( array( 'site_id' => get_current_blog_id() ) );

		$agents = array();

		foreach ( $rows as $row ) {
			$agents[] = array(
				'agent_id'   => (int) $row['agent_id'],
				'agent_slug' => (string) $row['agent_slug'],
				'agent_name' => (string) $row['agent_name'],
				'owner_id'   => (int) $row['owner_id'],
				'site_scope' => isset( $row['site_scope'] ) ? (int) $row['site_scope'] : null,
				'status'     => (string) $row['status'],
			);
		}

		return array(
			'success' => true,
			'agents'  => $agents,
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
				'status'       => (string) $agent['status'],
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
	 * @param array $input { agent_id, agent_name?, agent_config?, status? }.
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

		if ( isset( $input['status'] ) ) {
			$valid_statuses = array( 'active', 'inactive', 'archived' );
			$status         = sanitize_text_field( $input['status'] );
			if ( ! in_array( $status, $valid_statuses, true ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Invalid status "%s". Must be one of: %s', $status, implode( ', ', $valid_statuses ) ),
				);
			}
			$update['status'] = $status;
		}

		if ( empty( $update ) ) {
			return array(
				'success' => false,
				'error'   => 'No fields to update. Provide agent_name, agent_config, or status.',
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
						rmdir( $file->getRealPath() );
					} else {
						wp_delete_file( $file->getRealPath( ) );
					}
				}
				rmdir( $agent_dir );
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
