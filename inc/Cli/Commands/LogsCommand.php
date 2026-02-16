<?php
/**
 * WP-CLI Logs Command
 *
 * Provides CLI access to log management operations including
 * reading, clearing, and configuring log levels.
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
	 * Valid agent types for input validation.
	 *
	 * @var array
	 */
	private array $valid_agent_types = array( 'pipeline', 'system', 'chat' );

	/**
	 * Valid log levels.
	 *
	 * @var array
	 */
	private array $valid_levels = array( 'debug', 'error', 'none' );

	/**
	 * Read log entries for a specific agent type.
	 *
	 * ## OPTIONS
	 *
	 * <agent_type>
	 * : The agent type to read logs for (pipeline, system, chat).
	 *
	 * [--job-id=<id>]
	 * : Filter by job ID.
	 *
	 * [--pipeline-id=<id>]
	 * : Filter by pipeline ID.
	 *
	 * [--flow-id=<id>]
	 * : Filter by flow ID.
	 *
	 * [--mode=<mode>]
	 * : Content mode.
	 * ---
	 * default: recent
	 * options:
	 *   - recent
	 *   - full
	 * ---
	 *
	 * [--limit=<n>]
	 * : Number of recent entries to show.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Read recent pipeline logs
	 *     wp datamachine logs read pipeline
	 *
	 *     # Read system logs filtered by job
	 *     wp datamachine logs read system --job-id=844
	 *
	 *     # Read full chat logs
	 *     wp datamachine logs read chat --mode=full
	 *
	 *     # Read recent pipeline logs with limit
	 *     wp datamachine logs read pipeline --limit=100
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Agent type is required (pipeline, system, chat).' );
			return;
		}

		$agent_type = $args[0];

		if ( ! in_array( $agent_type, $this->valid_agent_types, true ) ) {
			WP_CLI::error( sprintf( 'Invalid agent type "%s". Must be one of: %s', $agent_type, implode( ', ', $this->valid_agent_types ) ) );
			return;
		}

		$input = array(
			'agent_type' => $agent_type,
			'mode'       => $assoc_args['mode'] ?? 'recent',
			'limit'      => (int) ( $assoc_args['limit'] ?? 50 ),
		);

		if ( isset( $assoc_args['job-id'] ) ) {
			$input['job_id'] = (int) $assoc_args['job-id'];
		}
		if ( isset( $assoc_args['pipeline-id'] ) ) {
			$input['pipeline_id'] = (int) $assoc_args['pipeline-id'];
		}
		if ( isset( $assoc_args['flow-id'] ) ) {
			$input['flow_id'] = (int) $assoc_args['flow-id'];
		}

		$result = LogAbilities::readLogs( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$content = $result['content'] ?? '';

		if ( empty( $content ) ) {
			WP_CLI::log( $result['message'] ?? 'No log entries found.' );
			return;
		}

		WP_CLI::log( $content );
		WP_CLI::log( '' );
		WP_CLI::log( $result['message'] ?? '' );
	}

	/**
	 * Show log file metadata and configuration.
	 *
	 * ## OPTIONS
	 *
	 * [<agent_type>]
	 * : Agent type to get info for (pipeline, system, chat). If omitted, shows all.
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
	 *     # Show all log info
	 *     wp datamachine logs info
	 *
	 *     # Show pipeline log info
	 *     wp datamachine logs info pipeline
	 *
	 *     # Output as JSON
	 *     wp datamachine logs info --format=json
	 *
	 * @subcommand info
	 */
	public function info( array $args, array $assoc_args ): void {
		$agent_type = $args[0] ?? null;

		if ( null !== $agent_type && ! in_array( $agent_type, $this->valid_agent_types, true ) ) {
			WP_CLI::error( sprintf( 'Invalid agent type "%s". Must be one of: %s', $agent_type, implode( ', ', $this->valid_agent_types ) ) );
			return;
		}

		$input = array();
		if ( null !== $agent_type ) {
			$input['agent_type'] = $agent_type;
		}

		$result = LogAbilities::getMetadata( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$items  = array();
		$fields = array( 'agent_type', 'level', 'file_size', 'file_path' );

		if ( isset( $result['agent_types'] ) ) {
			foreach ( $result['agent_types'] as $type => $meta ) {
				$items[] = array(
					'agent_type' => $type,
					'level'      => $meta['configuration']['current_level'] ?? 'unknown',
					'file_size'  => $meta['log_file']['size_formatted'] ?? '0 bytes',
					'file_path'  => $meta['log_file']['path'] ?? 'N/A',
				);
			}
		} else {
			$items[] = array(
				'agent_type' => $result['agent_type'],
				'level'      => $result['configuration']['current_level'] ?? 'unknown',
				'file_size'  => $result['log_file']['size_formatted'] ?? '0 bytes',
				'file_path'  => $result['log_file']['path'] ?? 'N/A',
			);
		}

		$this->format_items( $items, $fields, $assoc_args );
	}

	/**
	 * Get or set the log level for an agent type.
	 *
	 * ## OPTIONS
	 *
	 * <agent_type>
	 * : The agent type (pipeline, system, chat).
	 *
	 * [<level>]
	 * : Log level to set (debug, error, none). If omitted, shows current level.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get current pipeline log level
	 *     wp datamachine logs level pipeline
	 *
	 *     # Set system log level to debug
	 *     wp datamachine logs level system debug
	 *
	 *     # Disable chat logging
	 *     wp datamachine logs level chat none
	 *
	 * @subcommand level
	 */
	public function level( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Agent type is required (pipeline, system, chat).' );
			return;
		}

		$agent_type = $args[0];

		if ( ! in_array( $agent_type, $this->valid_agent_types, true ) ) {
			WP_CLI::error( sprintf( 'Invalid agent type "%s". Must be one of: %s', $agent_type, implode( ', ', $this->valid_agent_types ) ) );
			return;
		}

		// Set level.
		if ( ! empty( $args[1] ) ) {
			$level = $args[1];

			if ( ! in_array( $level, $this->valid_levels, true ) ) {
				WP_CLI::error( sprintf( 'Invalid level "%s". Must be one of: %s', $level, implode( ', ', $this->valid_levels ) ) );
				return;
			}

			$result = LogAbilities::setLevel(
				array(
					'agent_type' => $agent_type,
					'level'      => $level,
				)
			);

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['message'] ?? $result['error'] ?? 'Failed to set log level' );
				return;
			}

			WP_CLI::success( $result['message'] );
			return;
		}

		// Get level.
		$result = LogAbilities::getLevel( array( 'agent_type' => $agent_type ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? $result['error'] ?? 'Failed to get log level' );
			return;
		}

		WP_CLI::log( sprintf( '%s log level: %s', ucfirst( $agent_type ), $result['level'] ) );
	}

	/**
	 * Clear log files.
	 *
	 * ## OPTIONS
	 *
	 * <agent_type>
	 * : Agent type to clear (pipeline, system, chat, all).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear pipeline logs
	 *     wp datamachine logs clear pipeline --yes
	 *
	 *     # Clear all logs
	 *     wp datamachine logs clear all --yes
	 *
	 * @subcommand clear
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Agent type is required (pipeline, system, chat, all).' );
			return;
		}

		$agent_type     = $args[0];
		$valid_for_clear = array_merge( $this->valid_agent_types, array( 'all' ) );

		if ( ! in_array( $agent_type, $valid_for_clear, true ) ) {
			WP_CLI::error( sprintf( 'Invalid agent type "%s". Must be one of: %s', $agent_type, implode( ', ', $valid_for_clear ) ) );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'Use --yes to confirm clearing logs.' );
			return;
		}

		$result = LogAbilities::clear( array( 'agent_type' => $agent_type ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear logs' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}
}
