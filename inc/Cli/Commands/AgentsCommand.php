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
use DataMachine\Core\Database\Agents\AgentLog;
use DataMachine\Core\Database\Agents\Agents;
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

	/**
	 * View audit trail for an agent.
	 *
	 * ## OPTIONS
	 *
	 * <agent-slug>
	 * : Agent slug to view logs for.
	 *
	 * [--period=<period>]
	 * : Time period to show.
	 * ---
	 * default: 7d
	 * options:
	 *   - 1h
	 *   - 24h
	 *   - 7d
	 *   - 30d
	 *   - all
	 * ---
	 *
	 * [--action=<action>]
	 * : Filter by action (e.g. flow.run, pipeline.create, job.fail).
	 *
	 * [--result=<result>]
	 * : Filter by result.
	 * ---
	 * options:
	 *   - allowed
	 *   - denied
	 *   - error
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum entries to show.
	 * ---
	 * default: 50
	 * ---
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
	 *     wp datamachine agents log chubes-bot
	 *     wp datamachine agents log chubes-bot --period=24h
	 *     wp datamachine agents log chubes-bot --action=flow.run --result=error
	 *     wp datamachine agents log chubes-bot --format=json
	 *
	 * @subcommand log
	 */
	public function log( array $args, array $assoc_args ): void {
		$agent_slug = sanitize_title( $args[0] );
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $agent_slug );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $agent_slug ) );
		}

		$agent_id = (int) $agent['agent_id'];
		$period   = $assoc_args['period'] ?? '7d';
		$limit    = (int) ( $assoc_args['limit'] ?? 50 );

		$filters = array( 'per_page' => $limit );

		// Map period to since datetime.
		if ( 'all' !== $period ) {
			$intervals = array(
				'1h'  => '-1 hour',
				'24h' => '-24 hours',
				'7d'  => '-7 days',
				'30d' => '-30 days',
			);

			if ( isset( $intervals[ $period ] ) ) {
				$dt = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
				$dt->modify( $intervals[ $period ] );
				$filters['since'] = $dt->format( 'Y-m-d H:i:s' );
			}
		}

		if ( ! empty( $assoc_args['action'] ) ) {
			$filters['action'] = sanitize_text_field( $assoc_args['action'] );
		}

		if ( ! empty( $assoc_args['result'] ) ) {
			$filters['result'] = sanitize_text_field( $assoc_args['result'] );
		}

		$log_repo = new AgentLog();
		$data     = $log_repo->get_for_agent( $agent_id, $filters );

		if ( empty( $data['items'] ) ) {
			WP_CLI::warning( sprintf( 'No audit log entries found for "%s" in the %s period.', $agent_slug, $period ) );
			return;
		}

		// Format items for display.
		$items = array();
		foreach ( $data['items'] as $entry ) {
			$metadata_str = '';
			if ( ! empty( $entry['metadata'] ) && is_array( $entry['metadata'] ) ) {
				$parts = array();
				foreach ( $entry['metadata'] as $key => $value ) {
					$parts[] = "{$key}=" . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
				}
				$metadata_str = implode( ', ', $parts );
			}

			$items[] = array(
				'id'            => (int) $entry['id'],
				'action'        => $entry['action'],
				'result'        => $entry['result'],
				'resource_type' => $entry['resource_type'] ?? '-',
				'resource_id'   => $entry['resource_id'] ?? '-',
				'user_id'       => $entry['user_id'] ?? '-',
				'metadata'      => $metadata_str ?: '-',
				'created_at'    => $entry['created_at'],
			);
		}

		$fields = array( 'id', 'action', 'result', 'resource_type', 'resource_id', 'created_at' );

		// Show metadata in JSON format.
		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format || 'yaml' === $format ) {
			$fields[] = 'user_id';
			$fields[] = 'metadata';
		}

		$this->format_items( $items, $fields, $assoc_args, 'id' );
		WP_CLI::log( sprintf( 'Showing %d of %d entries.', count( $items ), $data['total'] ) );
	}
}
