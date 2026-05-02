<?php
/**
 * WP-CLI Data Machine drain command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Drain due Data Machine Action Scheduler work.
 */
class DrainCommand extends BaseCommand {

	private const GROUP = 'data-machine';

	private const HOOK_BATCH_CHUNK = 'datamachine_pipeline_batch_chunk';

	private const HOOK_EXECUTE_STEP = 'datamachine_execute_step';

	/**
	 * Drain due Data Machine actions until empty or a budget is reached.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum actions to execute. 0 means no action-count limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Maximum actions to ask Action Scheduler to claim per batch.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--time-limit=<seconds>]
	 * : Maximum wall-clock seconds to drain. 0 means no time limit.
	 * ---
	 * default: 0
	 * ---
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
	 *     wp datamachine drain
	 *     wp datamachine drain --limit=500 --time-limit=300 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$stats = self::drain(
			array(
				'limit'      => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
				'batch_size' => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 25,
				'time_limit' => isset( $assoc_args['time-limit'] ) ? (int) $assoc_args['time-limit'] : 0,
			)
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Drain due Data Machine actions and return a compact summary.
	 *
	 * @param array{limit?:int,batch_size?:int,time_limit?:int} $options Drain options.
	 * @return array<string,int|string> Drain stats.
	 */
	public static function drain( array $options = array() ): array {
		$limit      = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$batch_size = max( 1, (int) ( $options['batch_size'] ?? 25 ) );
		$time_limit = max( 0, (int) ( $options['time_limit'] ?? 0 ) );

		$started_at    = time();
		$before_counts = self::getStatusCounts();
		$batches       = 0;
		$warnings      = 0;

		while ( self::getDuePendingCount() > 0 ) {
			$stats = self::buildStats( $before_counts, self::getStatusCounts(), $batches, $warnings );
			if ( $limit > 0 && (int) $stats['actions_processed'] >= $limit ) {
				break;
			}

			if ( $time_limit > 0 && ( time() - $started_at ) >= $time_limit ) {
				break;
			}

			$current_batch_size = $batch_size;
			if ( $limit > 0 ) {
				$current_batch_size = min( $batch_size, $limit - (int) $stats['actions_processed'] );
			}

			$due_before    = self::getDuePendingCount();
			$status_before = self::getStatusCounts();
			$result        = self::runActionSchedulerBatch( $current_batch_size );
			++$batches;

			if ( 0 !== (int) ( $result->return_code ?? 1 ) ) {
				++$warnings;
				$message = trim( (string) ( $result->stderr ?? '' ) );
				WP_CLI::warning( '' === $message ? 'Action Scheduler CLI drain failed.' : $message );
				break;
			}

			$status_after = self::getStatusCounts();
			$progress     = self::processedDelta( $status_before, $status_after );
			if ( 0 === $progress && self::getDuePendingCount() >= $due_before ) {
				++$warnings;
				WP_CLI::warning( 'Drain stopped because Action Scheduler made no observable progress.' );
				break;
			}
		}

		return self::buildStats( $before_counts, self::getStatusCounts(), $batches, $warnings );
	}

	/**
	 * Run one scoped Action Scheduler batch.
	 *
	 * @param int $batch_size Batch size.
	 * @return object WP_CLI::runcommand result object.
	 */
	private static function runActionSchedulerBatch( int $batch_size ): object {
		return WP_CLI::runcommand(
			sprintf(
				'action-scheduler run --hooks=%s --group=%s --batch-size=%d --batches=1 --quiet --force',
				implode( ',', self::hooks() ),
				self::GROUP,
				$batch_size
			),
			array(
				'exit_error' => false,
				'return'     => 'all',
			)
		);
	}

	/**
	 * Build operator-facing stats.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before drain.
	 * @param array<string,array<string,int>> $after_counts  Counts after drain.
	 * @param int                             $batches       Batch count.
	 * @param int                             $warnings      Warning count.
	 * @return array<string,int|string> Stats.
	 */
	private static function buildStats( array $before_counts, array $after_counts, int $batches, int $warnings ): array {
		$batch_completed = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'complete' );
		$batch_failed    = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'failed' );
		$step_completed  = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'complete' );
		$step_failed     = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'failed' );

		return array(
			'batches'                    => $batches,
			'batch_chunks'               => $batch_completed + $batch_failed,
			'step_executions'            => $step_completed + $step_failed,
			'completions'                => $batch_completed + $step_completed,
			'failures'                   => $batch_failed + $step_failed,
			'batch_chunk_completions'    => $batch_completed,
			'batch_chunk_failures'       => $batch_failed,
			'step_execution_completions' => $step_completed,
			'step_execution_failures'    => $step_failed,
			'actions_processed'          => $batch_completed + $batch_failed + $step_completed + $step_failed,
			'remaining_pending'          => self::getDuePendingCount(),
			'total_pending'              => self::getPendingCount(),
			'warnings'                   => $warnings,
			'hooks'                      => implode( ',', self::hooks() ),
		);
	}

	/**
	 * Count status deltas that indicate an action was processed.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before a batch.
	 * @param array<string,array<string,int>> $after_counts  Counts after a batch.
	 * @return int Processed action count.
	 */
	private static function processedDelta( array $before_counts, array $after_counts ): int {
		$total = 0;
		foreach ( self::hooks() as $hook ) {
			$total += self::delta( $before_counts, $after_counts, $hook, 'complete' );
			$total += self::delta( $before_counts, $after_counts, $hook, 'failed' );
		}
		return $total;
	}

	/**
	 * Get a status count delta.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @param string                          $hook          Hook name.
	 * @param string                          $status        Action status.
	 * @return int Non-negative delta.
	 */
	private static function delta( array $before_counts, array $after_counts, string $hook, string $status ): int {
		return max( 0, ( $after_counts[ $hook ][ $status ] ?? 0 ) - ( $before_counts[ $hook ][ $status ] ?? 0 ) );
	}

	/**
	 * Count due pending Data Machine actions.
	 *
	 * @return int Due pending count.
	 */
	private static function getDuePendingCount(): int {
		return self::countActions( true );
	}

	/**
	 * Count all pending Data Machine actions.
	 *
	 * @return int Pending count.
	 */
	private static function getPendingCount(): int {
		return self::countActions( false );
	}

	/**
	 * Count pending Data Machine actions.
	 *
	 * @param bool $due_only Whether to count only due actions.
	 * @return int Pending count.
	 */
	private static function countActions( bool $due_only ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		if ( $due_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*)
					FROM %i a
					INNER JOIN %i g ON g.group_id = a.group_id
					WHERE a.hook IN (%s, %s)
					AND a.status = \'pending\'
					AND g.slug = %s
					AND a.scheduled_date_gmt <= %s',
					$actions_table,
					$groups_table,
					self::HOOK_BATCH_CHUNK,
					self::HOOK_EXECUTE_STEP,
					self::GROUP,
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE a.hook IN (%s, %s)
				AND a.status = \'pending\'
				AND g.slug = %s',
				$actions_table,
				$groups_table,
				self::HOOK_BATCH_CHUNK,
				self::HOOK_EXECUTE_STEP,
				self::GROUP
			)
		);
	}

	/**
	 * Get action counts grouped by hook and status.
	 *
	 * @return array<string,array<string,int>> Counts by hook and status.
	 */
	private static function getStatusCounts(): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.hook, a.status, COUNT(*) AS count
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE a.hook IN (%s, %s)
				AND g.slug = %s
				GROUP BY a.hook, a.status',
				$actions_table,
				$groups_table,
				self::HOOK_BATCH_CHUNK,
				self::HOOK_EXECUTE_STEP,
				self::GROUP
			)
		);

		$counts = array();
		foreach ( self::hooks() as $hook ) {
			$counts[ $hook ] = array(
				'pending'  => 0,
				'complete' => 0,
				'failed'   => 0,
			);
		}

		foreach ( $rows as $row ) {
			$hook   = (string) $row->hook;
			$status = (string) $row->status;
			if ( isset( $counts[ $hook ] ) ) {
				$counts[ $hook ][ $status ] = (int) $row->count;
			}
		}

		return $counts;
	}

	/**
	 * Get hooks drained by Data Machine.
	 *
	 * @return string[] Hook names.
	 */
	private static function hooks(): array {
		return array( self::HOOK_BATCH_CHUNK, self::HOOK_EXECUTE_STEP );
	}
}
