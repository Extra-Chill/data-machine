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
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

/**
 * Data Machine Agents CLI Command.
 *
 * @since 0.37.0
 */
class AgentsCommand extends BaseCommand {

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
	 * [--status=<status>]
	 * : Filter by agent status. Use "any" to skip status filtering.
	 * ---
	 * default: any
	 * ---
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
		$input = array(
			'status' => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'any' ),
		);

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
				'status'      => (string) ( $agent['status'] ?? '' ),
			);

			if ( $include_role ) {
				$role             = $agent['user_role'] ?? null;
				$row['user_role'] = ( null === $role || '' === $role ) ? '-' : (string) $role;
			}

			$items[] = $row;
		}

		$fields = array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'owner_login', 'has_files', 'status' );
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
			$config = json_decode( wp_unslash( $assoc_args['config'] ), true );
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
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
			WP_CLI::line( wp_json_encode( $agent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$owner = get_user_by( 'id', $agent['owner_id'] );

		WP_CLI::log( sprintf( 'Agent ID:    %d', $agent['agent_id'] ) );
		WP_CLI::log( sprintf( 'Slug:        %s', $agent['agent_slug'] ) );
		WP_CLI::log( sprintf( 'Name:        %s', $agent['agent_name'] ) );
		WP_CLI::log( sprintf( 'Owner:       %s (ID: %d)', $owner ? $owner->user_login : '(deleted)', $agent['owner_id'] ) );
		WP_CLI::log( sprintf( 'Status:      %s', $agent['status'] ) );
		WP_CLI::log( sprintf( 'Created:     %s', $agent['created_at'] ) );
		WP_CLI::log( sprintf( 'Updated:     %s', $agent['updated_at'] ) );
		WP_CLI::log( sprintf( 'Directory:   %s', $agent['agent_dir'] ) );
		WP_CLI::log( sprintf( 'Has files:   %s', $agent['has_files'] ? 'Yes' : 'No' ) );

		// Config.
		if ( ! empty( $agent['agent_config'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Config:' );
			WP_CLI::log( wp_json_encode( $agent['agent_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
	 * Manage agent access grants.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action: grant, revoke, or list.
	 *
	 * <slug>
	 * : Agent slug.
	 *
	 * [<user>]
	 * : User ID, login, or email (required for grant/revoke).
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
	 *     wp datamachine agents access revoke chubes-bot 2
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
				$grants = $access_repo->get_users_for_agent( $agent_id );

				if ( empty( $grants ) ) {
					WP_CLI::warning( sprintf( 'No access grants for agent "%s".', $slug ) );
					return;
				}

				$items = array();
				foreach ( $grants as $grant ) {
					$user    = get_user_by( 'id', $grant['user_id'] );
					$items[] = array(
						'user_id' => $grant['user_id'],
						'login'   => $user ? $user->user_login : '(deleted)',
						'role'    => $grant['role'],
					);
				}

				$this->format_items( $items, array( 'user_id', 'login', 'role' ), $assoc_args, 'user_id' );
				break;

			case 'grant':
				$user_value = $args[2] ?? null;
				if ( null === $user_value ) {
					WP_CLI::error( 'User is required for grant. Usage: wp datamachine agents access grant <slug> <user> [--role=<role>]' );
					return;
				}

				$user_id = $this->resolveUserId( $user_value );
				$role    = $assoc_args['role'] ?? 'operator';

				$ok = $access_repo->grant_access( $agent_id, $user_id, $role );
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
				$ok      = $access_repo->revoke_access( $agent_id, $user_id );

				if ( $ok ) {
					WP_CLI::success( sprintf( 'Revoked access for user %d on agent "%s".', $user_id, $slug ) );
				} else {
					WP_CLI::warning( 'No access grant found to revoke.' );
				}
				break;

			default:
				WP_CLI::error( "Unknown action: {$action}. Use: grant, revoke, list" );
		}
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
			WP_CLI::log( sprintf( 'Capabilities: %s', implode( ', ', $capabilities ) ) );
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
			WP_CLI::log( wp_json_encode( $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
				'label'     => $token['label'] ?: '(none)',
				'last_used' => $token['last_used_at'] ?? 'never',
				'expires'   => $token['expires_at'] ?? 'never',
				'status'    => $expired ? 'expired' : 'active',
			);
		}

		$this->format_items( $items, array( 'token_id', 'prefix', 'label', 'last_used', 'expires', 'status' ), $assoc_args, 'token_id' );
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
	 *     wp datamachine agents config sarai --set='allowed_redirect_uris=["saraichinwag.com","https://saraichinwag.com/*"]'
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
				WP_CLI::log( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
				if ( 'site_scope' === $key ) {
					$scope_value = ( 'null' === strtolower( $raw_value ) || '' === $raw_value ) ? null : (int) $raw_value;
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->base_prefix . 'datamachine_agents',
						array( 'site_scope' => $scope_value ),
						array( 'agent_id' => $agent_id ),
						array( null === $scope_value ? null : '%d' ),
						array( '%d' )
					);
					$site_scope_changed = true;
					WP_CLI::log( sprintf( '  site_scope → %s', null === $scope_value ? 'NULL (network-wide)' : $scope_value ) );
					continue;
				}

				// Try JSON decode first (for arrays, objects, numbers, booleans).
				$decoded = json_decode( $raw_value, true );
				$value   = ( null !== $decoded || 'null' === $raw_value ) ? $decoded : $raw_value;

				$config[ $key ] = $value;
				$display        = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : (string) $value;
				WP_CLI::log( sprintf( '  %s → %s', $key, $display ) );
			}
		}

		// Handle --unset flags.
		if ( $has_unset ) {
			$unsets = is_array( $assoc_args['unset'] ) ? $assoc_args['unset'] : array( $assoc_args['unset'] );

			foreach ( $unsets as $key ) {
				if ( array_key_exists( $key, $config ) ) {
					unset( $config[ $key ] );
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
	 * Export an agent's full identity into a portable bundle.
	 *
	 * Exports agent config, identity files (SOUL.md, MEMORY.md), USER.md
	 * template, pipelines, flows, and associated memory files into a
	 * portable bundle that can be imported on another Data Machine installation.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug to export.
	 *
	 * [--format=<format>]
	 * : Bundle format.
	 * ---
	 * default: zip
	 * options:
	 *   - zip
	 *   - json
	 *   - dir
	 * ---
	 *
	 * [--output=<path>]
	 * : Output path. For zip/json, a file path. For dir, a directory path.
	 *   Defaults to current directory with auto-generated filename.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export as ZIP (default)
	 *     wp datamachine agents export mattic-agent
	 *
	 *     # Export as JSON
	 *     wp datamachine agents export mattic-agent --format=json
	 *
	 *     # Export as directory
	 *     wp datamachine agents export mattic-agent --format=dir --output=/tmp/mattic-bundle
	 *
	 *     # Export to specific file
	 *     wp datamachine agents export mattic-agent --output=/tmp/mattic-agent.zip
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc_args ): void {
		$slug   = sanitize_title( $args[0] ?? '' );
		$format = $assoc_args['format'] ?? 'zip';
		$output = $assoc_args['output'] ?? null;

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Agent slug is required.' );
			return;
		}

		WP_CLI::log( sprintf( 'Exporting agent "%s"...', $slug ) );

		$bundler = new AgentBundler();
		$result  = $bundler->export( $slug );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
			return;
		}

		$bundle = $result['bundle'];

		// Log what's being exported.
		WP_CLI::log( sprintf( '  Agent:     %s (%s)', $bundle['agent']['agent_name'], $bundle['agent']['agent_slug'] ) );
		WP_CLI::log( sprintf( '  Files:     %d identity file(s)', count( $bundle['files'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Pipelines: %d', count( $bundle['pipelines'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Flows:     %d', count( $bundle['flows'] ?? array() ) ) );

		switch ( $format ) {
			case 'json':
				$output = $output ?? $slug . '-bundle.json';
				$json   = $bundler->to_json( $bundle );
				file_put_contents( $output, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				WP_CLI::success( sprintf( 'Bundle exported to %s (%s)', $output, size_format( filesize( $output ) ) ) );
				break;

			case 'dir':
				$output = $output ?? $slug . '-bundle';
				if ( is_dir( $output ) ) {
					WP_CLI::error( sprintf( 'Directory "%s" already exists. Remove it first or use --output=<path>.', $output ) );
					return;
				}
				$wrote = $bundler->to_directory( $bundle, $output );
				if ( ! $wrote ) {
					WP_CLI::error( 'Failed to write bundle directory.' );
					return;
				}
				WP_CLI::success( sprintf( 'Bundle exported to directory: %s', $output ) );
				break;

			case 'zip':
			default:
				$output = $output ?? $slug . '-bundle.zip';
				$wrote  = $bundler->to_zip( $bundle, $output );
				if ( ! $wrote ) {
					WP_CLI::error( 'Failed to create ZIP archive.' );
					return;
				}
				WP_CLI::success( sprintf( 'Bundle exported to %s (%s)', $output, size_format( filesize( $output ) ) ) );
				break;
		}
	}

	/**
	 * Import an agent from a portable bundle.
	 *
	 * Creates a new agent from a previously exported bundle. Pipelines and
	 * flows are recreated with new IDs. Flows are imported in paused/manual
	 * state to prevent immediate execution.
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
	 * [--dry-run]
	 * : Validate the bundle and show what would be imported without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
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

		if ( empty( $path ) ) {
			WP_CLI::error( 'Bundle path is required.' );
			return;
		}

		if ( ! file_exists( $path ) ) {
			WP_CLI::error( sprintf( 'Path not found: %s', $path ) );
			return;
		}

		$bundler = new AgentBundler();

		// Parse the bundle based on path type.
		if ( is_dir( $path ) ) {
			$bundle = $bundler->from_directory( $path );
		} elseif ( preg_match( '/\.zip$/i', $path ) ) {
			$bundle = $bundler->from_zip( $path );
		} elseif ( preg_match( '/\.json$/i', $path ) ) {
			$json   = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$bundle = $bundler->from_json( $json );
		} else {
			WP_CLI::error( 'Unsupported bundle format. Use .zip, .json, or a directory path.' );
			return;
		}

		if ( ! $bundle ) {
			WP_CLI::error( 'Failed to parse bundle. Ensure the file is a valid agent bundle.' );
			return;
		}

		// Display bundle info.
		$agent_data  = $bundle['agent'] ?? array();
		$target_slug = $new_slug ? sanitize_title( $new_slug ) : sanitize_title( $agent_data['agent_slug'] ?? 'unknown' );

		WP_CLI::log( 'Bundle contents:' );
		WP_CLI::log( sprintf( '  Agent:     %s (%s)', $agent_data['agent_name'] ?? '(unnamed)', $agent_data['agent_slug'] ?? '(no slug)' ) );
		WP_CLI::log( sprintf( '  Target:    %s', $target_slug ) );
		WP_CLI::log( sprintf( '  Files:     %d identity file(s)', count( $bundle['files'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Pipelines: %d', count( $bundle['pipelines'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Flows:     %d', count( $bundle['flows'] ?? array() ) ) );
		WP_CLI::log( sprintf( '  Exported:  %s', $bundle['exported_at'] ?? 'unknown' ) );

		// Resolve owner.
		$owner_id = 0;
		if ( isset( $assoc_args['owner'] ) ) {
			$owner_id = $this->resolveUserId( $assoc_args['owner'] );
		}

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%YDry run mode — validating bundle...%n' ) );
		} elseif ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Import agent "%s"?', $target_slug ) );
		}

		$result = $bundler->import( $bundle, $new_slug, $owner_id, $dry_run );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
			return;
		}

		if ( $dry_run ) {
			$summary = $result['summary'] ?? array();

			WP_CLI::log( '' );
			WP_CLI::log( 'Import preview:' );
			WP_CLI::log( sprintf( '  Agent slug:  %s', $summary['agent_slug'] ?? $target_slug ) );
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

		WP_CLI::success( $result['message'] );
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
			}
			return $user->ID;
		}

		if ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User with email "%s" not found.', $value ) );
			}
			return $user->ID;
		}

		$user = get_user_by( 'login', $value );
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User with login "%s" not found.', $value ) );
		}
		return $user->ID;
	}
}
