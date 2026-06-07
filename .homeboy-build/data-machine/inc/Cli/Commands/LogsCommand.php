<?php
/**
 * WP-CLI Logs Command
 *
 * Provides CLI access to database-backed log operations including
 * reading, clearing, and viewing metadata.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.15.2
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\LogAbilities;

defined( 'ABSPATH' ) || exit;

class LogsCommand extends BaseCommand {

	/**
	 * Read log entries from the database.
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<id>]
	 * : Filter by agent ID.
	 *
	 * [--level=<level>]
	 * : Filter by log level (debug, info, warning, error, critical).
	 *
	 * [--job-id=<id>]
	 * : Filter by job ID (in context).
	 *
	 * [--pipeline-id=<id>]
	 * : Filter by pipeline ID (in context).
	 *
	 * [--flow-id=<id>]
	 * : Filter by flow ID (in context).
	 *
	 * [--search=<text>]
	 * : Free-text search in message.
	 *
	 * [--since=<datetime>]
	 * : Show entries after this datetime (ISO format).
	 *
	 * [--before=<datetime>]
	 * : Show entries before this datetime (ISO format).
	 *
	 * [--limit=<n>]
	 * : Number of entries to show.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--page=<n>]
	 * : Page number for pagination.
	 * ---
	 * default: 1
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
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Read recent logs
	 *     wp datamachine logs read
	 *
	 *     # Read logs for a specific agent
	 *     wp datamachine logs read --agent=1
	 *
	 *     # Read error logs filtered by job
	 *     wp datamachine logs read --level=error --job-id=844
	 *
	 *     # Search logs
	 *     wp datamachine logs read --search="timeout"
	 *
	 *     # Read logs since a specific time
	 *     wp datamachine logs read --since="2026-03-01 00:00:00"
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		$input = array(
			'per_page' => (int) ( $assoc_args['limit'] ?? 50 ),
			'page'     => (int) ( $assoc_args['page'] ?? 1 ),
		);

		if ( isset( $assoc_args['agent'] ) ) {
			$input['agent_id'] = (int) $assoc_args['agent'];
		}
		if ( isset( $assoc_args['level'] ) ) {
			$valid_levels = array( 'debug', 'info', 'warning', 'error', 'critical' );
			if ( ! in_array( $assoc_args['level'], $valid_levels, true ) ) {
				WP_CLI::error( sprintf( 'Invalid level "%s". Must be one of: %s', $assoc_args['level'], implode( ', ', $valid_levels ) ) );
				return;
			}
			$input['level'] = $assoc_args['level'];
		}
		if ( isset( $assoc_args['job-id'] ) ) {
			$input['job_id'] = (int) $assoc_args['job-id'];
		}
		if ( isset( $assoc_args['pipeline-id'] ) ) {
			$input['pipeline_id'] = (int) $assoc_args['pipeline-id'];
		}
		if ( isset( $assoc_args['flow-id'] ) ) {
			$input['flow_id'] = (int) $assoc_args['flow-id'];
		}
		if ( isset( $assoc_args['search'] ) ) {
			$input['search'] = $assoc_args['search'];
		}
		if ( isset( $assoc_args['since'] ) ) {
			$input['since'] = $assoc_args['since'];
		}
		if ( isset( $assoc_args['before'] ) ) {
			$input['before'] = $assoc_args['before'];
		}

		$result = LogAbilities::readLogs( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$items = $result['items'] ?? array();

		if ( empty( $items ) ) {
			WP_CLI::log( 'No log entries found.' );
			return;
		}

		// Flatten items for table display.
		$display_items = array();
		foreach ( $items as $item ) {
			$context_str = '';
			if ( ! empty( $item['context'] ) ) {
				$context_str = wp_json_encode( $item['context'] );
				if ( strlen( $context_str ) > 120 ) {
					$context_str = substr( $context_str, 0, 117 ) . '...';
				}
			}

			$display_items[] = array(
				'id'         => $item['id'],
				'level'      => strtoupper( $item['level'] ),
				'message'    => mb_substr( $item['message'], 0, 120 ),
				'agent_id'   => $item['agent_id'] ?? '-',
				'context'    => $context_str,
				'created_at' => $item['created_at'],
			);
		}

		$fields = array( 'id', 'level', 'message', 'agent_id', 'created_at' );
		$format = $assoc_args['format'] ?? 'table';

		// Include context in non-table formats.
		if ( 'table' !== $format ) {
			$fields[] = 'context';
		}

		$this->format_items( $display_items, $fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			$total = $result['total'] ?? 0;
			$page  = $result['page'] ?? 1;
			$pages = $result['pages'] ?? 1;
			WP_CLI::log( sprintf( 'Page %d of %d (%d total entries)', $page, $pages, $total ) );
		}
	}

	/**
	 * Show log metadata (entry counts, time range).
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<id>]
	 * : Agent ID to get info for. If omitted, shows global info.
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
	 *     # Show global log info
	 *     wp datamachine logs info
	 *
	 *     # Show log info for agent 1
	 *     wp datamachine logs info --agent=1
	 *
	 * @subcommand info
	 */
	public function info( array $args, array $assoc_args ): void {
		$input = array();

		if ( isset( $assoc_args['agent'] ) ) {
			$input['agent_id'] = (int) $assoc_args['agent'];
		}

		$result = LogAbilities::getMetadata( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$items = array(
			array(
				'metric' => 'Total Entries',
				'value'  => $result['total_entries'],
			),
			array(
				'metric' => 'Oldest Entry',
				'value'  => $result['oldest'] ?? 'N/A',
			),
			array(
				'metric' => 'Newest Entry',
				'value'  => $result['newest'] ?? 'N/A',
			),
		);

		// Add level distribution.
		if ( ! empty( $result['level_counts'] ) ) {
			foreach ( $result['level_counts'] as $level => $count ) {
				$items[] = array(
					'metric' => strtoupper( $level ) . ' count',
					'value'  => $count,
				);
			}
		}

		$fields = array( 'metric', 'value' );
		$this->format_items( $items, $fields, $assoc_args );
	}

	/**
	 * Clear log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<id>]
	 * : Agent ID to clear logs for. If omitted, clears all logs.
	 *
	 * [--before=<datetime>]
	 * : Clear entries before this datetime (ISO format). Overrides --agent.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all logs
	 *     wp datamachine logs clear --yes
	 *
	 *     # Clear logs for agent 1
	 *     wp datamachine logs clear --agent=1 --yes
	 *
	 *     # Clear logs older than 30 days
	 *     wp datamachine logs clear --before="2026-02-01 00:00:00" --yes
	 *
	 * @subcommand clear
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'Use --yes to confirm clearing logs.' );
			return;
		}

		// Prune by date takes precedence.
		if ( isset( $assoc_args['before'] ) ) {
			$repo    = new \DataMachine\Core\Database\Logs\LogRepository();
			$deleted = $repo->prune_before( $assoc_args['before'] );

			if ( false === $deleted ) {
				WP_CLI::error( 'Failed to prune logs.' );
				return;
			}

			WP_CLI::success( sprintf( 'Pruned %d log entries before %s.', $deleted, $assoc_args['before'] ) );
			return;
		}

		$input = array();
		if ( isset( $assoc_args['agent'] ) ) {
			$input['agent_id'] = (int) $assoc_args['agent'];
		}

		$result = LogAbilities::clear( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear logs' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}
}
