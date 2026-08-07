<?php
/**
 * WP-CLI Agents Command
 *
 * Manages Data Machine agent identities via the Abilities API.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.37.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Bundle\BundleSource;

defined( 'ABSPATH' ) || exit;

/**
 * Data Machine Agents CLI Command.
 *
 * @since 0.37.0
 */
class AgentsCommand extends AgentBundleCommand {

	/**
	 * Get or set the active agent for CLI/default user context.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<agent>]
	 * : Agent slug or ID. Required for `set`.
	 *
	 * [--user_id=<id>]
	 * : User ID whose active agent should be read or changed.
	 *
	 * [--format=<format>]
	 * : Output format for `get`.
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
	 *     wp datamachine agent active get
	 *     wp datamachine agent active set intelligence-chubes4
	 *     wp datamachine agent active set wordpress-com-wiki --user_id=1
	 *
	 * @subcommand active
	 */
	public function active( array $args, array $assoc_args ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : '';
		if ( ! in_array( $action, array( 'get', 'set' ), true ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent active <get|set> [agent] [--user_id=<id>]' );
			return;
		}

		$input        = array();
		$user_id_flag = \WP_CLI\Utils\get_flag_value( $assoc_args, 'user_id', null );
		if ( null !== $user_id_flag ) {
			$input['user_id'] = (int) $user_id_flag;
		}

		if ( 'get' === $action ) {
			$result = AgentAbilities::getActiveAgent( $input );
			if ( empty( $result['success'] ) ) {
				WP_CLI::error( $result['error'] ?? 'Failed to get active agent.' );
				return;
			}

			$item = array(
				'agent_slug'   => $result['agent_slug'] ?? '',
				'source'       => $result['source'] ?? '',
				'needs_choice' => ! empty( $result['needs_choice'] ) ? 'Yes' : 'No',
			);

			if ( ! empty( $result['agent'] ) && is_array( $result['agent'] ) ) {
				$item['agent_id']   = (int) ( $result['agent']['agent_id'] ?? 0 );
				$item['agent_name'] = (string) ( $result['agent']['agent_name'] ?? '' );
			}

			$this->format_items( array( $item ), array_keys( $item ), $assoc_args );
			return;
		}

		$agent = isset( $args[1] ) ? (string) $args[1] : '';
		if ( '' === $agent ) {
			WP_CLI::error( 'Agent is required. Usage: wp datamachine agent active set <agent>' );
			return;
		}

		$input['agent'] = $agent;
		$result         = AgentAbilities::setActiveAgent( $input );
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to set active agent.' );
			return;
		}

		WP_CLI::success( sprintf( 'Active agent set to %s for user %d.', (string) $result['agent_slug'], (int) $result['user_id'] ) );
	}

	/**
	 * List registered agent identities.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which agents to list.
	 * ---
	 * default: all
	 * options:
	 *   - mine
	 *   - all
	 * ---
	 *
	 * [--user_id=<id>]
	 * : List accessible agents for a specific user (implies scope=mine).
	 *
	 * [--include_role]
	 * : Include the resolved user's role per agent in the output.
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
	 *     # All agents on the current site (admin default)
	 *     wp datamachine agents list
	 *
	 *     # Agents accessible to a specific user
	 *     wp datamachine agents list --user_id=5
	 *
	 *     # My own accessible agents with role info
	 *     wp datamachine agents list --scope=mine --include_role
	 *
	 *     wp datamachine agents list --format=json
	 *
	 * @subcommand list
	 */
	public function list_agents( array $args, array $assoc_args ): void {
		$input = array();

		$user_id_flag = \WP_CLI\Utils\get_flag_value( $assoc_args, 'user_id', null );
		if ( null !== $user_id_flag ) {
			$input['user_id'] = (int) $user_id_flag;
			// Passing --user_id implies scope=mine for that user.
			$input['scope'] = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'mine' );
		} else {
			$input['scope'] = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'all' );
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'include_role', false ) ) {
			$input['include_role'] = true;
		}

		$result = AgentAbilities::listAgents( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list agents.' );
			return;
		}

		if ( empty( $result['agents'] ) ) {
			WP_CLI::warning( 'No agents found for the given scope.' );
			return;
		}

		$directory_manager = new DirectoryManager();
		$items             = array();
		$include_role      = ! empty( $input['include_role'] );

		foreach ( $result['agents'] as $agent ) {
			$owner_id = (int) $agent['owner_id'];
			$user     = $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;
			$slug     = (string) $agent['agent_slug'];

			$agent_dir = $directory_manager->get_agent_identity_directory( $slug );
			$row       = array(
				'agent_id'    => (int) $agent['agent_id'],
				'agent_slug'  => $slug,
				'agent_name'  => (string) $agent['agent_name'],
				'owner_id'    => $owner_id,
				'owner_login' => $user ? $user->user_login : '(deleted)',
				'has_files'   => is_dir( $agent_dir ) ? 'Yes' : 'No',
			);

			if ( $include_role ) {
				$role             = $agent['user_role'] ?? null;
				$row['user_role'] = ( null === $role || '' === $role ) ? '-' : (string) $role;
			}

			$items[] = $row;
		}

		$fields = array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'owner_login', 'has_files' );
		if ( $include_role ) {
			$fields[] = 'user_role';
		}

		$this->format_items( $items, $fields, $assoc_args, 'agent_id' );

		WP_CLI::log( sprintf( 'Total: %d agent(s).', count( $items ) ) );
	}

	/**
	 * Rename an agent's slug.
	 *
	 * Updates the database record and moves the agent's filesystem directory
	 * to match the new slug.
	 *
	 * ## OPTIONS
	 *
	 * <old-slug>
	 * : Current agent slug.
	 *
	 * <new-slug>
	 * : New agent slug.
	 *
	 * [--dry-run]
	 * : Preview what would change without making modifications.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine agents rename chubesextrachill-com chubes-bot
	 *     wp datamachine agents rename chubesextrachill-com chubes-bot --dry-run
	 *
	 * @subcommand rename
	 */
	public function rename( array $args, array $assoc_args ): void {
		$old_slug = sanitize_title( $args[0] );
		$new_slug = sanitize_title( $args[1] );
		$dry_run  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$directory_manager = new DirectoryManager();
		$old_path          = $directory_manager->get_agent_identity_directory( $old_slug );
		$new_path          = $directory_manager->get_agent_identity_directory( $new_slug );

		WP_CLI::log( sprintf( 'Agent slug:  %s → %s', $old_slug, $new_slug ) );
		WP_CLI::log( sprintf( 'Directory:   %s → %s', $old_path, $new_path ) );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run — no changes made.' );
			return;
		}

		$result = AgentAbilities::renameAgent(
			array(
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
			)
		);

		if ( $result['success'] ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::error( $result['message'] );
		}
	}

	/**
	 * Create a new agent.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug (kebab-case identifier).
	 *
	 * [--name=<name>]
	 * : Agent display name. Defaults to the slug.
	 *
	 * [--owner=<user>]
	 * : Owner WordPress user ID, login, or email.
	 *
	 * [--config=<json>]
	 * : JSON object with agent configuration.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine agents create analytics-bot --name="Analytics Bot" --owner=1
	 *     wp datamachine agents create content-bot --owner=chubes
	 *
	 * @subcommand create
	 */
	public function create( array $args, array $assoc_args ): void {
		$slug   = sanitize_title( $args[0] ?? '' );
		$name   = $assoc_args['name'] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Agent slug is required.' );
			return;
		}

		// Resolve owner.
		$owner_value = $assoc_args['owner'] ?? null;
		if ( null === $owner_value ) {
			WP_CLI::error( 'Owner is required (--owner=<user_id|login|email>).' );
			return;
		}

		$owner_id = $this->resolveUserId( $owner_value );

		$config = array();
		if ( isset( $assoc_args['config'] ) ) {
			$config_json = wp_unslash( $assoc_args['config'] );
			if ( ! is_string( $config_json ) ) {
				WP_CLI::error( 'Invalid JSON in --config.' );
				return;
			}

			$config = json_decode( $config_json, true );
			if ( null === $config ) {
				WP_CLI::error( 'Invalid JSON in --config.' );
				return;
			}
		}

		$result = AgentAbilities::createAgent(
			array(
				'agent_slug' => $slug,
				'agent_name' => $name,
				'owner_id'   => $owner_id,
				'config'     => $config,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create agent.' );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			WP_CLI::log( sprintf( 'Agent ID:  %d', $result['agent_id'] ) );
			WP_CLI::log( sprintf( 'Slug:      %s', $result['agent_slug'] ) );
			WP_CLI::log( sprintf( 'Name:      %s', $result['agent_name'] ) );
			WP_CLI::log( sprintf( 'Owner:     %d', $result['owner_id'] ) );
			WP_CLI::log( sprintf( 'Directory: %s', $result['agent_dir'] ) );
		}
	}

	/**
	 * Show detailed agent information.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug or numeric ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine agents show chubes-bot
	 *     wp datamachine agents show 1 --format=json
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		$identifier = $args[0] ?? '';
		$format     = $assoc_args['format'] ?? 'table';

		$input = is_numeric( $identifier )
			? array( 'agent_id' => (int) $identifier )
			: array( 'agent_slug' => $identifier );

		$result = AgentAbilities::getAgent( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Agent not found.' );
			return;
		}

		$agent = $result['agent'];

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $agent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$owner = get_user_by( 'id', $agent['owner_id'] );

		WP_CLI::log( sprintf( 'Agent ID:    %d', $agent['agent_id'] ) );
		WP_CLI::log( sprintf( 'Slug:        %s', $agent['agent_slug'] ) );
		WP_CLI::log( sprintf( 'Name:        %s', $agent['agent_name'] ) );
		WP_CLI::log( sprintf( 'Owner:       %s (ID: %d)', $owner ? $owner->user_login : '(deleted)', $agent['owner_id'] ) );
		WP_CLI::log( sprintf( 'Created:     %s', $agent['created_at'] ) );
		WP_CLI::log( sprintf( 'Updated:     %s', $agent['updated_at'] ) );
		WP_CLI::log( sprintf( 'Directory:   %s', $agent['agent_dir'] ) );
		WP_CLI::log( sprintf( 'Has files:   %s', $agent['has_files'] ? 'Yes' : 'No' ) );

		// Config.
		if ( ! empty( $agent['agent_config'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Config:' );
			WP_CLI::log( (string) wp_json_encode( $agent['agent_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		// Access grants.
		$access = $agent['access'] ?? array();
		if ( ! empty( $access ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Access grants:' );
			$access_items = array();
			foreach ( $access as $grant ) {
				$grant_user     = get_user_by( 'id', $grant['user_id'] );
				$access_items[] = array(
					'user_id' => $grant['user_id'],
					'login'   => $grant_user ? $grant_user->user_login : '(deleted)',
					'role'    => $grant['role'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', $access_items, array( 'user_id', 'login', 'role' ) );
		}
	}

	/**
	 * Delete an agent.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug or numeric ID.
	 *
	 * [--delete-files]
	 * : Also delete the agent's filesystem directory (SOUL.md, MEMORY.md, etc.).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine agents delete analytics-bot
	 *     wp datamachine agents delete analytics-bot --delete-files --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$identifier   = $args[0] ?? '';
		$delete_files = isset( $assoc_args['delete-files'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		$input = is_numeric( $identifier )
			? array( 'agent_id' => (int) $identifier )
			: array( 'agent_slug' => $identifier );

		// Get agent info for confirmation.
		$info = AgentAbilities::getAgent( $input );
		if ( ! $info['success'] ) {
			WP_CLI::error( $info['error'] ?? 'Agent not found.' );
			return;
		}

		$agent = $info['agent'];

		if ( ! $skip_confirm ) {
			$message = sprintf(
				'Delete agent "%s" (ID: %d)?',
				$agent['agent_slug'],
				$agent['agent_id']
			);
			if ( $delete_files ) {
				$message .= ' This will also delete agent files (SOUL.md, MEMORY.md, daily/).';
			}
			WP_CLI::confirm( $message );
		}

		$input['delete_files'] = $delete_files;
		$result                = AgentAbilities::deleteAgent( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete agent.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Prune agent rows with zero references.
	 *
	 * Lists candidates by default. Use --yes to actually delete. A candidate
	 * is an agent row with no chat sessions, no jobs, no access grants, no
	 * on-disk directory, no bundle install config, and is not the install's
	 * default agent.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the dry-run preview and delete candidates.
	 *
	 * [--format=<format>]
	 * : Output format for the candidate list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview candidates (default)
	 *     wp datamachine agent prune
	 *
	 *     # Delete candidates
	 *     wp datamachine agent prune --yes
	 *
	 * @subcommand prune
	 */
	public function prune( array $args, array $assoc_args ): void {
		$apply = isset( $assoc_args['yes'] );

		$result = AgentAbilities::pruneAgents(
			array(
				'dry_run' => ! $apply,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to prune agents.' );
			return;
		}

		$candidates = $result['candidates'] ?? array();

		if ( empty( $candidates ) ) {
			WP_CLI::success( 'No pruneable agents found — all rows have references.' );
			return;
		}

		$items = array();
		foreach ( $candidates as $agent ) {
			$owner_id = (int) $agent['owner_id'];
			$user     = $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;
			$items[]  = array(
				'agent_id'    => (int) $agent['agent_id'],
				'agent_slug'  => (string) $agent['agent_slug'],
				'agent_name'  => (string) $agent['agent_name'],
				'owner_id'    => $owner_id,
				'owner_login' => $user ? $user->user_login : '(deleted)',
			);
		}

		$fields = array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'owner_login' );
		$this->format_items( $items, $fields, $assoc_args, 'agent_id' );

		if ( ! $apply ) {
			WP_CLI::log( sprintf( 'Found %d pruneable agent(s). Re-run with --yes to delete.', count( $items ) ) );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %d pruneable agent(s).', (int) $result['deleted_count'] ) );
	}

	/**
	 * Manage agent access grants.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action: grant, grant-audience, revoke, revoke-audience, or list.
	 *
	 * <slug>
	 * : Agent slug.
	 *
	 * [<principal>]
	 * : User ID/login/email for user grants, or audience slug / audience:<slug> for audience grants.
	 *
	 * [--role=<role>]
	 * : Access role (grant only).
	 * ---
	 * default: operator
	 * options:
	 *   - admin
	 *   - operator
	 *   - viewer
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (list only).
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
	 *     wp datamachine agents access grant chubes-bot 2 --role=admin
	 *     wp datamachine agents access grant-audience chubes-bot audience:automattician --role=operator
	 *     wp datamachine agents access revoke chubes-bot 2
	 *     wp datamachine agents access revoke-audience chubes-bot audience:automattician
	 *     wp datamachine agents access list chubes-bot
	 *
	 * @subcommand access
	 */
	public function access( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		$slug   = $args[1] ?? '';

		if ( empty( $action ) || empty( $slug ) ) {
			WP_CLI::error( 'Usage: wp datamachine agents access <grant|revoke|list> <slug> [user] [--role=<role>]' );
			return;
		}

		// Resolve agent.
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_by_slug( sanitize_title( $slug ) );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $slug ) );
			return;
		}

		$agent_id    = (int) $agent['agent_id'];
		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();

		switch ( $action ) {
			case 'list':
				$grants           = $access_repo->get_users_for_agent( (string) $agent_id );
				$principal_grants = $access_repo->get_principals_for_agent( (string) $agent_id );

				if ( empty( $grants ) && empty( $principal_grants ) ) {
					WP_CLI::warning( sprintf( 'No access grants for agent "%s".', $slug ) );
					return;
				}

				$items = array();
				foreach ( $grants as $grant ) {
					$user    = get_user_by( 'id', $grant->user_id );
					$items[] = array(
						'principal' => 'user:' . $grant->user_id,
						'label'     => $user ? $user->user_login : '(deleted)',
						'role'      => $grant->role,
					);
				}
				foreach ( $principal_grants as $grant ) {
					$principal_type = (string) ( $grant['principal_type'] ?? '' );
					$principal_id   = (string) ( $grant['principal_id'] ?? '' );
					$items[]        = array(
						'principal' => $principal_type . ':' . $principal_id,
						'label'     => $principal_id,
						'role'      => (string) ( $grant['role'] ?? '' ),
					);
				}

				$this->format_items( $items, array( 'principal', 'label', 'role' ), $assoc_args, 'principal' );
				break;

			case 'grant-audience':
				$audience = $args[2] ?? null;
				if ( null === $audience ) {
					WP_CLI::error( 'Audience is required. Usage: wp datamachine agents access grant-audience <slug> <audience|audience:slug> [--role=<role>]' );
					return;
				}

				list( $principal_type, $principal_id ) = $this->resolveAudiencePrincipal( (string) $audience );
				$role                                  = (string) ( $assoc_args['role'] ?? 'operator' );

				try {
					$access_repo->grant_principal_access( (string) $agent_id, $principal_type, $principal_id, $role );
					$ok = true;
				} catch ( \Throwable $e ) {
					$ok = false;
				}

				if ( $ok ) {
					WP_CLI::success( sprintf( 'Granted %s access to %s:%s for agent "%s".', $role, $principal_type, $principal_id, $slug ) );
				} else {
					WP_CLI::error( 'Failed to grant audience access.' );
				}
				break;

			case 'grant':
				$user_value = $args[2] ?? null;
				if ( null === $user_value ) {
					WP_CLI::error( 'User is required for grant. Usage: wp datamachine agents access grant <slug> <user> [--role=<role>]' );
					return;
				}

				$user_id = $this->resolveUserId( $user_value );
				$role    = $assoc_args['role'] ?? 'operator';

				try {
					$access_repo->grant_access( new \WP_Agent_Access_Grant( (string) $agent_id, $user_id, (string) $role ) );
					$ok = true;
				} catch ( \Throwable $e ) {
					$ok = false;
				}
				if ( $ok ) {
					$user = get_user_by( 'id', $user_id );
					WP_CLI::success( sprintf(
						'Granted %s access to %s for agent "%s".',
						$role,
						$user ? $user->user_login : "user #{$user_id}",
						$slug
					) );
				} else {
					WP_CLI::error( 'Failed to grant access.' );
				}
				break;

			case 'revoke':
				$user_value = $args[2] ?? null;
				if ( null === $user_value ) {
					WP_CLI::error( 'User is required for revoke.' );
					return;
				}

				$user_id = $this->resolveUserId( $user_value );
				$ok      = $access_repo->revoke_access( (string) $agent_id, $user_id );

				if ( $ok ) {
					WP_CLI::success( sprintf( 'Revoked access for user %d on agent "%s".', $user_id, $slug ) );
				} else {
					WP_CLI::warning( 'No access grant found to revoke.' );
				}
				break;

			case 'revoke-audience':
				$audience = $args[2] ?? null;
				if ( null === $audience ) {
					WP_CLI::error( 'Audience is required for revoke-audience.' );
					return;
				}

				list( $principal_type, $principal_id ) = $this->resolveAudiencePrincipal( (string) $audience );
				$ok                                    = $access_repo->revoke_principal_access( (string) $agent_id, $principal_type, $principal_id );

				if ( $ok ) {
					WP_CLI::success( sprintf( 'Revoked access for %s:%s on agent "%s".', $principal_type, $principal_id, $slug ) );
				} else {
					WP_CLI::warning( 'No audience access grant found to revoke.' );
				}
				break;

			default:
				WP_CLI::error( "Unknown action: {$action}. Use: grant, grant-audience, revoke, revoke-audience, list" );
		}
	}

	/**
	 * Resolve audience CLI syntax to an explicit principal tuple.
	 *
	 * @return array{0:string,1:string}
	 */
	private function resolveAudiencePrincipal( string $audience ): array {
		$audience = trim( $audience );
		$type     = 'audience';
		$id       = $audience;

		if ( false !== strpos( $audience, ':' ) ) {
			list( $type, $id ) = explode( ':', $audience, 2 );
		}

		$type = sanitize_key( $type );
		$id   = sanitize_title( $id );

		if ( '' === $type || '' === $id || 'user' === $type ) {
			WP_CLI::error( 'Audience principal must be a non-user value such as audience:automattician.' );
		}

		return array( $type, $id );
	}

	/**
	 * Manage agent bearer tokens for runtime authentication.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action: create, revoke, or list.
	 *
	 * <slug>
	 * : Agent slug or numeric ID.
	 *
	 * [<token_id>]
	 * : Token ID (required for revoke).
	 *
	 * [--label=<label>]
	 * : Human-readable label for the token (create only).
	 *
	 * [--expires-in=<seconds>]
	 * : Token expiry in seconds from now (create only). Default: never.
	 *
	 * [--capabilities=<json>]
	 * : JSON array of allowed capabilities (create only). Default: all agent caps.
	 *
	 * [--format=<format>]
	 * : Output format (list only).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a token for an agent
	 *     wp datamachine agents token create chubes-bot --label="kimaki-prod"
	 *
	 *     # Create a token with 90-day expiry
	 *     wp datamachine agents token create chubes-bot --label="ci" --expires-in=7776000
	 *
	 *     # Create a token with restricted capabilities
	 *     wp datamachine agents token create chubes-bot --capabilities='["datamachine_chat","datamachine_use_tools"]'
	 *
	 *     # List tokens for an agent
	 *     wp datamachine agents token list chubes-bot
	 *
	 *     # Revoke a token
	 *     wp datamachine agents token revoke chubes-bot 3
	 *
	 * @subcommand token
	 */
	public function token( array $args, array $assoc_args ): void {
		$action     = $args[0] ?? '';
		$identifier = $args[1] ?? '';

		if ( empty( $action ) || empty( $identifier ) ) {
			WP_CLI::error( 'Usage: wp datamachine agents token <create|revoke|list> <slug|id> [token_id] [--label=...] [--expires-in=...]' );
			return;
		}

		// Resolve agent.
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = is_numeric( $identifier )
			? $agents_repo->get_agent( (int) $identifier )
			: $agents_repo->get_by_slug( sanitize_title( $identifier ) );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $identifier ) );
			return;
		}

		$agent_id = (int) $agent['agent_id'];

		$abilities = new \DataMachine\Abilities\AgentTokenAbilities();

		switch ( $action ) {
			case 'create':
				$this->tokenCreate( $abilities, $agent, $assoc_args );
				break;

			case 'list':
				$this->tokenList( $abilities, $agent_id, $assoc_args );
				break;

			case 'revoke':
				$token_id = intval( $args[2] ?? 0 );
				if ( $token_id <= 0 ) {
					WP_CLI::error( 'Token ID is required for revoke. Usage: wp datamachine agents token revoke <slug> <token_id>' );
					return;
				}
				$this->tokenRevoke( $abilities, $agent_id, $token_id );
				break;

			default:
				WP_CLI::error( "Unknown action: {$action}. Use: create, revoke, list" );
		}
	}

	/**
	 * Create an agent token.
	 *
	 * @param \DataMachine\Abilities\AgentTokenAbilities $abilities Token abilities.
	 * @param array                                      $agent     Agent row.
	 * @param array                                      $assoc_args CLI arguments.
	 */
	private function tokenCreate( $abilities, array $agent, array $assoc_args ): void {
		$agent_id     = (int) $agent['agent_id'];
		$label        = $assoc_args['label'] ?? '';
		$expires_in   = isset( $assoc_args['expires-in'] ) ? intval( $assoc_args['expires-in'] ) : null;
		$capabilities = null;

		if ( isset( $assoc_args['capabilities'] ) ) {
			$capabilities = json_decode( $assoc_args['capabilities'], true );
			if ( ! is_array( $capabilities ) ) {
				WP_CLI::error( 'Invalid JSON in --capabilities.' );
				return;
			}
		}

		$result = $abilities->executeCreateToken(
			array(
				'agent_id'     => $agent_id,
				'label'        => $label,
				'capabilities' => $capabilities,
				'expires_in'   => $expires_in,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create token.' );
			return;
		}

		WP_CLI::success( 'Token created!' );
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%YSave this token now — it cannot be retrieved again:%n' ) );
		WP_CLI::log( '' );
		WP_CLI::log( '  ' . $result['raw_token'] );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Token ID:    %d', $result['token_id'] ) );
		WP_CLI::log( sprintf( 'Agent:       %s (ID: %d)', $agent['agent_slug'], $agent_id ) );
		if ( ! empty( $label ) ) {
			WP_CLI::log( sprintf( 'Label:       %s', $label ) );
		}
		if ( null !== $expires_in ) {
			$days = intval( $expires_in / DAY_IN_SECONDS );
			WP_CLI::log( sprintf( 'Expires in:  %d days', $days ) );
		}
		if ( null !== $capabilities ) {
			$scope = \DataMachine\Core\Database\Agents\AgentTokens::normalize_capability_payload( $capabilities );
			WP_CLI::log( sprintf( 'Scope:       %s', \DataMachine\Core\Database\Agents\AgentTokens::scope_label( $capabilities ) ) );
			WP_CLI::log( sprintf( 'Capabilities: %s', implode( ', ', $scope['allowed_capabilities'] ?? array() ) ) );
		}
	}

	/**
	 * List tokens for an agent.
	 *
	 * @param \DataMachine\Abilities\AgentTokenAbilities $abilities  Token abilities.
	 * @param int                                        $agent_id   Agent ID.
	 * @param array                                      $assoc_args CLI arguments.
	 */
	private function tokenList( $abilities, int $agent_id, array $assoc_args ): void {
		$result = $abilities->executeListTokens( array( 'agent_id' => $agent_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list tokens.' );
			return;
		}

		$tokens = $result['tokens'] ?? array();

		if ( empty( $tokens ) ) {
			WP_CLI::warning( 'No tokens found for this agent.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$items = array();
		foreach ( $tokens as $token ) {
			$expired = false;
			if ( ! empty( $token['expires_at'] ) ) {
				$expired = strtotime( $token['expires_at'] ) < time();
			}

			$items[] = array(
				'token_id'  => $token['token_id'],
				'prefix'    => $token['token_prefix'] . '...',
				'label'     => ! empty( $token['label'] ) ? $token['label'] : '(none)',
				'scope'     => $token['scope_label'] ?? 'Full owner ceiling',
				'last_used' => $token['last_used_at'] ?? 'never',
				'expires'   => $token['expires_at'] ?? 'never',
				'status'    => $expired ? 'expired' : 'active',
			);
		}

		$this->format_items( $items, array( 'token_id', 'prefix', 'label', 'scope', 'last_used', 'expires', 'status' ), $assoc_args, 'token_id' );
	}

	/**
	 * Revoke an agent token.
	 *
	 * @param \DataMachine\Abilities\AgentTokenAbilities $abilities Token abilities.
	 * @param int                                        $agent_id  Agent ID.
	 * @param int                                        $token_id  Token ID.
	 */
	private function tokenRevoke( $abilities, int $agent_id, int $token_id ): void {
		$result = $abilities->executeRevokeToken(
			array(
				'agent_id' => $agent_id,
				'token_id' => $token_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to revoke token.' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Token revoked.' );
	}

	/**
	 * Read or update agent configuration.
	 *
	 * Without flags, displays the current config. With --set or --unset,
	 * modifies individual config keys. Supports dot notation for nested keys.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug or numeric ID.
	 *
	 * [--set=<pair>]
	 * : Set a config key (format: key=value). Value is JSON-parsed (arrays, objects, strings, numbers).
	 *   For arrays, pass JSON: --set='allowed_redirect_uris=["https://example.com/*"]'
	 *   Can be used multiple times.
	 *
	 * [--unset=<key>]
	 * : Remove a config key. Can be used multiple times.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # View agent config
	 *     wp datamachine agents config sarai
	 *
	 *     # Set allowed redirect URIs
	 *     wp datamachine agents config sarai --set='allowed_redirect_uris=["example.com","https://example.com/*"]'
	 *
	 *     # Set a single key
	 *     wp datamachine agents config sarai --set='model=gpt-4o'
	 *
	 *     # Remove a key
	 *     wp datamachine agents config sarai --unset=model
	 *
	 *     # Set site_scope (special: updates the agent column directly)
	 *     wp datamachine agents config sarai --set='site_scope=7'
	 *     wp datamachine agents config sarai --set='site_scope=null'
	 *
	 * @subcommand config
	 */
	public function config( array $args, array $assoc_args ): void {
		$identifier = $args[0] ?? '';
		$format     = $assoc_args['format'] ?? 'json';

		if ( empty( $identifier ) ) {
			WP_CLI::error( 'Agent slug or ID is required.' );
			return;
		}

		// Resolve agent.
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = is_numeric( $identifier )
			? $agents_repo->get_agent( (int) $identifier )
			: $agents_repo->get_by_slug( sanitize_title( $identifier ) );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $identifier ) );
			return;
		}

		$agent_id = (int) $agent['agent_id'];
		$config   = $agent['agent_config'] ?? array();
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$has_set   = isset( $assoc_args['set'] );
		$has_unset = isset( $assoc_args['unset'] );

		// Read-only mode.
		if ( ! $has_set && ! $has_unset ) {
			if ( empty( $config ) ) {
				WP_CLI::log( 'Agent config is empty.' );
				return;
			}

			if ( 'json' === $format ) {
				WP_CLI::log( (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				$items = array();
				foreach ( $config as $key => $value ) {
					$items[] = array(
						'key'   => $key,
						'value' => is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : (string) $value,
					);
				}
				\WP_CLI\Utils\format_items( 'table', $items, array( 'key', 'value' ) );
			}
			return;
		}

		$site_scope_changed = false;

		// Handle --set flags.
		if ( $has_set ) {
			$sets = is_array( $assoc_args['set'] ) ? $assoc_args['set'] : array( $assoc_args['set'] );

			foreach ( $sets as $pair ) {
				$eq_pos = strpos( $pair, '=' );
				if ( false === $eq_pos ) {
					WP_CLI::warning( sprintf( 'Skipping invalid --set value: %s (expected key=value)', $pair ) );
					continue;
				}

				$key       = substr( $pair, 0, $eq_pos );
				$raw_value = substr( $pair, $eq_pos + 1 );

				// Handle site_scope as a special case — it's a column, not agent_config.
				// Changing scope is an explicit operation; route it through the
				// repository so the nullable-column write stays in one place.
				if ( 'site_scope' === $key ) {
					$scope_value = ( 'null' === strtolower( $raw_value ) || '' === $raw_value ) ? null : (int) $raw_value;
					$agents_repo->update_agent( $agent_id, array( 'site_scope' => $scope_value ) );
					$site_scope_changed = true;
					WP_CLI::log( sprintf( '  site_scope → %s', null === $scope_value ? 'NULL (network-wide)' : $scope_value ) );
					continue;
				}

				// Try JSON decode first (for arrays, objects, numbers, booleans).
				$decoded = json_decode( $raw_value, true );
				$value   = ( null !== $decoded || 'null' === $raw_value ) ? $decoded : $raw_value;

				$this->setConfigValue( $config, $key, $value );
				$display = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : (string) $value;
				WP_CLI::log( sprintf( '  %s → %s', $key, $display ) );
			}
		}

		// Handle --unset flags.
		if ( $has_unset ) {
			$unsets = is_array( $assoc_args['unset'] ) ? $assoc_args['unset'] : array( $assoc_args['unset'] );

			foreach ( $unsets as $key ) {
				if ( $this->unsetConfigValue( $config, (string) $key ) ) {
					WP_CLI::log( sprintf( '  Removed: %s', $key ) );
				} else {
					WP_CLI::warning( sprintf( '  Key not found: %s', $key ) );
				}
			}
		}

		// Save updated config.
		$agents_repo->update_agent( $agent_id, array( 'agent_config' => $config ) );

		WP_CLI::success( sprintf( 'Config updated for agent "%s".', $agent['agent_slug'] ) );
	}

	/**
	 * Set a config value, supporting dot notation for nested paths.
	 *
	 * @param array  $config Config array, passed by reference.
	 * @param string $path   Dot-notated config path.
	 * @param mixed  $value  Value to set.
	 */
	private function setConfigValue( array &$config, string $path, $value ): void {
		$segments = $this->getConfigPathSegments( $path );
		if ( empty( $segments ) ) {
			return;
		}

		$target =& $config;
		$last   = array_pop( $segments );
		foreach ( $segments as $segment ) {
			if ( ! isset( $target[ $segment ] ) || ! is_array( $target[ $segment ] ) ) {
				$target[ $segment ] = array();
			}
			$target =& $target[ $segment ];
		}

		$target[ $last ] = $value;
	}

	/**
	 * Remove a config value, supporting dot notation for nested paths.
	 *
	 * @param array  $config Config array, passed by reference.
	 * @param string $path   Dot-notated config path.
	 * @return bool Whether a value was removed.
	 */
	private function unsetConfigValue( array &$config, string $path ): bool {
		$segments = $this->getConfigPathSegments( $path );
		if ( empty( $segments ) ) {
			return false;
		}

		$target =& $config;
		$last   = array_pop( $segments );
		foreach ( $segments as $segment ) {
			if ( ! isset( $target[ $segment ] ) || ! is_array( $target[ $segment ] ) ) {
				return false;
			}
			$target =& $target[ $segment ];
		}

		if ( ! array_key_exists( $last, $target ) ) {
			return false;
		}

		unset( $target[ $last ] );
		return true;
	}

	/**
	 * Split a dot-notated config path into usable segments.
	 *
	 * @param string $path Config path.
	 * @return string[]
	 */
	private function getConfigPathSegments( string $path ): array {
		$segments = array_filter(
			array_map( 'trim', explode( '.', $path ) ),
			static function ( string $segment ): bool {
				return '' !== $segment;
			}
		);

		return array_values( $segments );
	}

	/**
	 * Export an agent's full identity into a portable bundle.
	 *
	 * Exports agent config, identity files (SOUL.md, MEMORY.md), USER.md
	 * template, pipelines, flows, and associated memory files into a
	 * portable bundle that can be imported on another Data Machine installation.
	 *
	 * ## OPTIONS
	 *
	 * <agent>
	 * : Agent ID or slug to export.
	 *
	 * [--profile=<profile>]
	 * : Export profile.
	 * ---
	 * default: share
	 * options:
	 *   - share
	 *   - backup
	 *   - fork
	 * ---
	 *
	 * [--format=<format>]
	 * : Bundle format.
	 * ---
	 * default: directory
	 * options:
	 *   - zip
	 *   - directory
	 * ---
	 *
	 * [--destination=<path>]
	 * : Output path. For zip, a file path. For directory, a directory path.
	 *   Defaults to current directory with auto-generated filename.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export as directory (default)
	 *     wp datamachine agents export 42
	 *
	 *     # Export as ZIP
	 *     wp datamachine agents export mattic-agent --format=zip --destination=/tmp/mattic-agent.zip
	 *
	 *     # Export backup profile as directory
	 *     wp datamachine agents export mattic-agent --profile=backup --destination=/tmp/mattic-bundle
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc_args ): void {
		$identifier  = (string) ( $args[0] ?? '' );
		$format      = (string) ( $assoc_args['format'] ?? 'directory' );
		$destination = (string) ( $assoc_args['destination'] ?? ( $assoc_args['output'] ?? '' ) );
		$profile     = (string) ( $assoc_args['profile'] ?? 'share' );

		if ( '' === trim( $identifier ) ) {
			WP_CLI::error( 'Agent ID or slug is required.' );
			return;
		}

		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = is_numeric( $identifier )
			? $agents_repo->get_agent( (int) $identifier )
			: $agents_repo->get_by_slug( sanitize_title( $identifier ) );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $identifier ) );
			return;
		}

		$input = array(
			'agent_id' => (int) $agent['agent_id'],
			'profile'  => $profile,
			'format'   => $format,
		);
		if ( '' !== trim( $destination ) ) {
			$input['destination'] = $destination;
		}

		WP_CLI::log( sprintf( 'Exporting agent "%s"...', $agent['agent_slug'] ) );
		$result = AgentAbilities::exportAgent( $input );
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Failed to export agent.' ) );
			return;
		}

		$manifest = is_array( $result['manifest'] ?? null ) ? $result['manifest'] : array();
		$included = is_array( $manifest['included'] ?? null ) ? $manifest['included'] : array();
		WP_CLI::log( sprintf( '  Agent:     %s (%s)', $manifest['agent']['label'] ?? $agent['agent_name'], $agent['agent_slug'] ) );
		WP_CLI::log( sprintf( '  Profile:   %s', $result['profile'] ) );
		WP_CLI::log( sprintf( '  Format:    %s', $result['format'] ) );
		WP_CLI::log( sprintf( '  Memory:    %d file(s)', count( $included['memory'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Pipelines: %d', count( $included['pipelines'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Flows:     %d', count( $included['flows'] ?? array() ) ) );
		WP_CLI::success( sprintf( 'Bundle exported to %s', $result['path'] ) );
	}

	/**
	 * Import an agent from a portable bundle.
	 *
	 * Creates a new agent from a previously exported bundle. Pipelines and
	 * flows are recreated with new IDs. Flow scheduling follows the bundle's
	 * reviewed schedule on create and preserves local schedules on upgrade.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Path to the bundle file (.zip or .json) or directory.
	 *
	 * [--slug=<slug>]
	 * : Override the agent slug on import (rename).
	 *
	 * [--owner=<user>]
	 * : Owner WordPress user ID, login, or email. Defaults to current user.
	 *
	 * [--on-conflict=<policy>]
	 * : How to handle an existing target agent slug.
	 * ---
	 * default: error
	 * options:
	 *   - error
	 *   - skip
	 * ---
	 *
	 * [--dry-run]
	 * : Validate the bundle and show what would be imported without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * [--token=<token>]
	 * : Auth token for private archive downloads. Used for this single resolve(); never persisted, never logged.
	 *
	 * [--token-env=<varname>]
	 * : Environment variable (or PHP constant) name to read the auth token from. Preferred over --token for shell-history hygiene.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import from ZIP
	 *     wp datamachine agents import mattic-agent-bundle.zip
	 *
	 *     # Import with new slug
	 *     wp datamachine agents import mattic-agent-bundle.zip --slug=my-agent
	 *
	 *     # Import with specific owner
	 *     wp datamachine agents import mattic-agent-bundle.json --owner=chubes
	 *
	 *     # Dry run to preview
	 *     wp datamachine agents import mattic-agent-bundle.zip --dry-run
	 *
	 *     # Import from directory
	 *     wp datamachine agents import /tmp/mattic-bundle/
	 *
	 * @subcommand import
	 */
	public function import_agent( array $args, array $assoc_args ): void {
		$path     = $args[0] ?? '';
		$new_slug = $assoc_args['slug'] ?? null;
		$dry_run  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$format   = (string) ( $assoc_args['format'] ?? 'table' );

		if ( empty( $path ) ) {
			WP_CLI::error( 'Bundle source is required.' );
			return;
		}

		// Validate locally before forwarding to the ability so that
		// obvious garbage (typos, missing local file) fails fast with a
		// human-friendly CLI error instead of a generic ability response.
		// The ability re-runs BundleSource::resolve() to actually
		// hydrate any temp file.
		if ( ! BundleSource::is_remote( $path ) && ! file_exists( $path ) ) {
			WP_CLI::error( sprintf( 'Path not found: %s', $path ) );
			return;
		}
		// Resolve owner.
		$owner_id = 0;
		if ( isset( $assoc_args['owner'] ) ) {
			$owner_id = $this->resolveUserId( $assoc_args['owner'] );
		}

		if ( $dry_run && 'json' !== $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%YDry run mode — validating bundle...%n' ) );
		} elseif ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'Import agent bundle?' );
		}

		$ability = wp_get_ability( 'datamachine/import-agent' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/import-agent ability is not registered.' );
			return;
		}

		$ability_input = array(
			'source'      => $path,
			'slug'        => $new_slug,
			'owner_id'    => $owner_id,
			'on_conflict' => (string) ( $assoc_args['on-conflict'] ?? 'error' ),
			'dry_run'     => $dry_run,
		);

		// Forward token / token_env to the ability schema. The ability
		// honors them inside BundleSource::resolve() via $context.
		if ( isset( $assoc_args['token'] ) ) {
			$ability_input['token'] = (string) $assoc_args['token'];
		}
		if ( isset( $assoc_args['token-env'] ) ) {
			$ability_input['token_env'] = (string) $assoc_args['token-env'];
		}

		$result = $ability->execute( $ability_input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to import agent bundle.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		foreach ( $result['auth_warnings'] ?? array() as $warning ) {
			WP_CLI::warning(
				sprintf(
					'%s: %s',
					(string) ( $warning['auth_ref'] ?? 'auth_ref' ),
					(string) ( $warning['message'] ?? 'unresolved auth reference' )
				)
			);
		}

		if ( ! empty( $result['skipped'] ) ) {
			WP_CLI::success( (string) ( $result['message'] ?? 'Import skipped.' ) );
			return;
		}

		if ( $dry_run ) {
			$summary = $result['summary'] ?? array();
			$slug    = (string) ( $summary['agent_slug'] ?? 'unknown' );

			WP_CLI::log( '' );
			WP_CLI::log( 'Import preview:' );
			WP_CLI::log( sprintf( '  Agent slug:  %s', $slug ) );
			WP_CLI::log( sprintf( '  Agent name:  %s', $summary['agent_name'] ?? '(unnamed)' ) );
			WP_CLI::log( sprintf( '  Owner ID:    %d', $summary['owner_id'] ?? 0 ) );
			WP_CLI::log( sprintf( '  Files:       %d', $summary['files'] ?? 0 ) );
			WP_CLI::log( sprintf( '  Pipelines:   %d', $summary['pipelines'] ?? 0 ) );
			WP_CLI::log( sprintf( '  Flows:       %d (will be imported paused)', $summary['flows'] ?? 0 ) );

			$missing = $summary['missing_abilities'] ?? array();
			if ( ! empty( $missing ) ) {
				WP_CLI::log( '' );
				WP_CLI::warning( sprintf( '%d ability slug(s) from the bundle are not registered on this site:', count( $missing ) ) );
				foreach ( $missing as $ability ) {
					WP_CLI::log( '  - ' . $ability );
				}
			}

			WP_CLI::success( 'Dry run complete — no changes made.' );
			return;
		}

		$summary = $result['summary'] ?? array();
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '  Agent ID:    %d', $summary['agent_id'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Pipelines:   %d imported', $summary['pipelines_imported'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Flows:       %d imported (paused)', $summary['flows_imported'] ?? 0 ) );

		WP_CLI::success( $result['message'] ?? 'Agent bundle imported.' );
	}

	/**
	 * Remove legacy contexts/ directories from agent memory.
	 *
	 * Scans all agent directories and deletes contexts/ subdirectories
	 * and their contents. These files are superseded by the runtime
	 * AgentModeDirective which provides mode guidance without per-agent
	 * disk files.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would be cleaned up
	 *     wp datamachine agents cleanup-legacy-context-files --dry-run
	 *
	 *     # Actually remove legacy context files
	 *     wp datamachine agents cleanup-legacy-context-files
	 *
	 * @subcommand cleanup-legacy-context-files
	 */
	public function cleanup_legacy_context_files( array $args, array $assoc_args ): void {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$upload_dir = wp_upload_dir();
		$agents_dir = trailingslashit( $upload_dir['basedir'] ) . 'datamachine-files/agents';
		if ( ! is_dir( $agents_dir ) ) {
			WP_CLI::success( 'No agents directory found — nothing to clean.' );
			return;
		}

		$deleted_files = 0;
		$deleted_dirs  = 0;
		$iterator      = new \DirectoryIterator( $agents_dir );

		foreach ( $iterator as $agent_entry ) {
			if ( $agent_entry->isDot() || ! $agent_entry->isDir() ) {
				continue;
			}

			$contexts_dir = $agent_entry->getPathname() . '/contexts';
			if ( ! is_dir( $contexts_dir ) ) {
				continue;
			}

			// Delete all files in contexts/.
			$files = new \DirectoryIterator( $contexts_dir );
			foreach ( $files as $file ) {
				if ( $file->isDot() || ! $file->isFile() ) {
					continue;
				}

				$path = $file->getPathname();
				if ( $dry_run ) {
					WP_CLI::log( sprintf( '[dry-run] Would delete: %s', $path ) );
				} else {
					unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					WP_CLI::log( sprintf( 'Deleted: %s', $path ) );
				}
				++$deleted_files;
			}

			// Remove the empty contexts/ directory.
			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] Would remove directory: %s', $contexts_dir ) );
			} else {
				rmdir( $contexts_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				WP_CLI::log( sprintf( 'Removed directory: %s', $contexts_dir ) );
			}
			++$deleted_dirs;
		}

		if ( 0 === $deleted_files && 0 === $deleted_dirs ) {
			WP_CLI::success( 'No legacy context files found — already clean.' );
			return;
		}

		$prefix = $dry_run ? '[dry-run] Would delete' : 'Cleaned up';
		WP_CLI::success( sprintf( '%s %d file(s) and %d directory(ies).', $prefix, $deleted_files, $deleted_dirs ) );
	}

	/**
	 * Resolve a user identifier to a WordPress user ID.
	 *
	 * @param string|int $value User ID, login, or email.
	 * @return int WordPress user ID.
	 */
	private function resolveUserId( $value ): int {
		if ( is_numeric( $value ) ) {
			$user = get_user_by( 'id', (int) $value );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User ID %d not found.', (int) $value ) );
				return 0;
			}
			return $user->ID;
		}

		if ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User with email "%s" not found.', $value ) );
				return 0;
			}
			return $user->ID;
		}

		$user = get_user_by( 'login', $value );
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User with login "%s" not found.', $value ) );
			return 0;
		}
		return $user->ID;
	}
}
