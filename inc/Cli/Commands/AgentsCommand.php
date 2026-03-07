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
	 *     wp datamachine agents list
	 *     wp datamachine agents list --format=json
	 *
	 * @subcommand list
	 */
	public function list_agents( array $args, array $assoc_args ): void {
		$result = AgentAbilities::listAgents( array() );

		if ( empty( $result['agents'] ) ) {
			WP_CLI::warning( 'No agents registered.' );
			return;
		}

		$directory_manager = new DirectoryManager();
		$items             = array();

		foreach ( $result['agents'] as $agent ) {
			$owner_id = (int) $agent['owner_id'];
			$user     = $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;
			$slug     = (string) $agent['agent_slug'];

			$agent_dir = $directory_manager->get_agent_identity_directory( $slug );
			$items[]   = array(
				'agent_id'    => (int) $agent['agent_id'],
				'agent_slug'  => $slug,
				'agent_name'  => (string) $agent['agent_name'],
				'owner_id'    => $owner_id,
				'owner_login' => $user ? $user->user_login : '(deleted)',
				'has_files'   => is_dir( $agent_dir ) ? 'Yes' : 'No',
				'status'      => (string) $agent['status'],
			);
		}

		$fields = array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'owner_login', 'has_files', 'status' );
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
}
