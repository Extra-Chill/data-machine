<?php
/**
 * WP-CLI Agents Command
 *
 * Lists WordPress users configured as Data Machine agents.
 * An "agent" is a WP user who owns pipelines, flows, or has an agent directory.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.37.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

/**
 * Data Machine Agents CLI Command.
 *
 * Lists WordPress users configured as Data Machine agents.
 *
 * @since 0.37.0
 */
class AgentsCommand extends BaseCommand {

	/**
	 * List WordPress users configured as Data Machine agents.
	 *
	 * Shows the shared agent (user_id=0) and any WP users who own
	 * pipelines, flows, or have user-scoped agent directories.
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
	 *     # List all configured agents
	 *     wp datamachine agents list
	 *
	 *     # JSON output
	 *     wp datamachine agents list --format=json
	 *
	 * @subcommand list
	 */
	/**
	 * Execute agent listing.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_agents( array $args, array $assoc_args ): void {
		global $wpdb;

		$items = array();

		// Always include the shared agent (user_id=0).
		$directory_manager = new DirectoryManager();
		$shared_dir        = $directory_manager->get_agent_directory( 0 );
		$shared_exists     = is_dir( $shared_dir );

		// Count pipelines/flows for user_id=0.
		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$shared_pipelines = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, 0 )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$shared_flows = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, 0 )
		);

		$items[] = array(
			'user_id'   => 0,
			'login'     => '(shared)',
			'email'     => '-',
			'pipelines' => $shared_pipelines,
			'flows'     => $shared_flows,
			'has_files' => $shared_exists ? 'Yes' : 'No',
		);

		// Find all distinct user_ids from pipelines and flows tables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids_from_pipelines = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > %d', $pipelines_table, 0 )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids_from_flows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > %d', $flows_table, 0 )
		);

		$all_user_ids = array_unique(
			array_merge(
				array_map( 'intval', $user_ids_from_pipelines ),
				array_map( 'intval', $user_ids_from_flows )
			)
		);

		// Also check for user-scoped agent directories.
		$upload_dir = wp_upload_dir();
		$agent_base = trailingslashit( $upload_dir['basedir'] ) . 'datamachine-files';
		$user_dirs  = glob( $agent_base . '/agent-*', GLOB_ONLYDIR );

		if ( $user_dirs ) {
			foreach ( $user_dirs as $dir ) {
				$dirname = basename( $dir );
				if ( preg_match( '/^agent-(\d+)$/', $dirname, $matches ) ) {
					$uid = (int) $matches[1];
					if ( $uid > 0 && ! in_array( $uid, $all_user_ids, true ) ) {
						$all_user_ids[] = $uid;
					}
				}
			}
		}

		sort( $all_user_ids );

		foreach ( $all_user_ids as $uid ) {
			$user = get_user_by( 'id', $uid );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_pipelines = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, $uid )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_flows = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, $uid )
			);

			$user_dir   = $directory_manager->get_agent_directory( $uid );
			$dir_exists = is_dir( $user_dir );

			$items[] = array(
				'user_id'   => $uid,
				'login'     => $user ? $user->user_login : '(deleted)',
				'email'     => $user ? $user->user_email : '-',
				'pipelines' => $user_pipelines,
				'flows'     => $user_flows,
				'has_files' => $dir_exists ? 'Yes' : 'No',
			);
		}

		$fields = array( 'user_id', 'login', 'email', 'pipelines', 'flows', 'has_files' );
		$this->format_items( $items, $fields, $assoc_args );

		WP_CLI::log( sprintf( 'Total: %d agent(s).', count( $items ) ) );
	}
}
