<?php
/**
 * WP-CLI Batch Command
 *
 * Provides CLI access to batch operation management — listing active
 * batches, checking status, and cancelling running batches.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.33.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Engine\AI\System\SystemAgent;

defined( 'ABSPATH' ) || exit;

class BatchCommand extends BaseCommand {

	/**
	 * List all batch operations.
	 *
	 * Shows batch parent jobs with their current status and progress.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by batch status (processing, completed, cancelled, failed).
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
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
	 *     # List all batches
	 *     $ wp datamachine batch list
	 *
	 *     # List only running batches
	 *     $ wp datamachine batch list --status=processing
	 *
	 * @subcommand list
	 */
	public function list_batches( $args, $assoc_args ): void {
		$system_agent = SystemAgent::getInstance();
		$batches      = $system_agent->listBatches();

		if ( empty( $batches ) ) {
			WP_CLI::log( 'No batch operations found.' );
			return;
		}

		$status_filter = $assoc_args['status'] ?? '';

		$rows = array();
		foreach ( $batches as $batch ) {
			$engine_data = $batch['engine_data'] ?? array();
			$status      = $batch['status'] ?? 'unknown';

			// Apply status filter.
			if ( ! empty( $status_filter ) && $status !== $status_filter ) {
				continue;
			}

			// Check for cancelled flag.
			if ( ! empty( $engine_data['cancelled'] ) && 'cancelled' !== $status ) {
				$status = 'cancelled';
			}

			$total     = $engine_data['total'] ?? 0;
			$scheduled = $engine_data['tasks_scheduled'] ?? 0;
			$progress  = $total > 0 ? round( ( $scheduled / $total ) * 100 ) : 0;

			$rows[] = array(
				'batch_id'   => $batch['job_id'] ?? 0,
				'task_type'  => $engine_data['task_type'] ?? '',
				'total'      => $total,
				'scheduled'  => $scheduled,
				'progress'   => $progress . '%',
				'status'     => $status,
				'started_at' => $engine_data['started_at'] ?? '',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No batches match the filter.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		$this->format_items(
			$format,
			$rows,
			array( 'batch_id', 'task_type', 'total', 'scheduled', 'progress', 'status', 'started_at' )
		);
	}

	/**
	 * Show detailed status of a batch operation.
	 *
	 * Displays the batch metadata and child job statistics.
	 *
	 * ## OPTIONS
	 *
	 * <batch_id>
	 * : The batch parent job ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show batch status
	 *     $ wp datamachine batch status 42
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ): void {
		$batch_job_id = absint( $args[0] ?? 0 );

		if ( $batch_job_id <= 0 ) {
			WP_CLI::error( 'Please provide a valid batch job ID.' );
		}

		$system_agent = SystemAgent::getInstance();
		$status       = $system_agent->getBatchStatus( $batch_job_id );

		if ( null === $status ) {
			WP_CLI::error( "Batch #{$batch_job_id} not found or not a batch job." );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			return;
		}

		// Human-readable output.
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( "%GBatch #{$batch_job_id}%n" ) );
		WP_CLI::log( str_repeat( '─', 50 ) );
		WP_CLI::log( sprintf( '  Task type:      %s', $status['task_type'] ) );
		WP_CLI::log( sprintf( '  Status:         %s', $status['cancelled'] ? 'cancelled' : $status['status'] ) );
		WP_CLI::log( sprintf( '  Total items:    %d', $status['total_items'] ) );
		WP_CLI::log( sprintf( '  Scheduled:      %d', $status['tasks_scheduled'] ) );
		WP_CLI::log( sprintf( '  Chunk size:     %d', $status['chunk_size'] ) );
		WP_CLI::log( sprintf( '  Started:        %s', $status['started_at'] ?: 'N/A' ) );

		if ( ! empty( $status['completed_at'] ) ) {
			WP_CLI::log( sprintf( '  Completed:      %s', $status['completed_at'] ) );
		}

		// Child job statistics.
		$children = $status['child_jobs'];
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%YChild Jobs%n' ) );
		WP_CLI::log( str_repeat( '─', 50 ) );
		WP_CLI::log( sprintf( '  Total:          %d', $children['total'] ) );
		WP_CLI::log( sprintf( '  Completed:      %s', WP_CLI::colorize( '%G' . $children['completed'] . '%n' ) ) );
		WP_CLI::log( sprintf( '  Failed:         %s', $children['failed'] > 0 ? WP_CLI::colorize( '%R' . $children['failed'] . '%n' ) : '0' ) );
		WP_CLI::log( sprintf( '  Processing:     %d', $children['processing'] ) );
		WP_CLI::log( sprintf( '  Pending:        %d', $children['pending'] ) );

		// Progress bar.
		$total_items = $status['total_items'];
		if ( $total_items > 0 ) {
			$done    = $children['completed'] + $children['failed'];
			$percent = round( ( $done / $total_items ) * 100 );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '  Progress:       %d%% (%d/%d done)', $percent, $done, $total_items ) );
		}

		WP_CLI::log( '' );
	}

	/**
	 * Cancel a running batch operation.
	 *
	 * Sets the cancelled flag so no more chunks will be processed.
	 * Tasks already scheduled will still complete.
	 *
	 * ## OPTIONS
	 *
	 * <batch_id>
	 * : The batch parent job ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Cancel a running batch
	 *     $ wp datamachine batch cancel 42
	 *
	 * @subcommand cancel
	 */
	public function cancel( $args, $assoc_args ): void {
		$batch_job_id = absint( $args[0] ?? 0 );

		if ( $batch_job_id <= 0 ) {
			WP_CLI::error( 'Please provide a valid batch job ID.' );
		}

		$system_agent = SystemAgent::getInstance();
		$result       = $system_agent->cancelBatch( $batch_job_id );

		if ( ! $result ) {
			WP_CLI::error( "Could not cancel batch #{$batch_job_id}. Not found or not a batch job." );
		}

		WP_CLI::success( "Batch #{$batch_job_id} cancelled. Already-scheduled tasks will still complete." );
	}
}
