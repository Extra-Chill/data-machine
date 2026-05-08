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

	public const HOOK_BATCH_CHUNK = 'datamachine_pipeline_batch_chunk';

	public const HOOK_EXECUTE_STEP = 'datamachine_execute_step';

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
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Drain due Data Machine actions and return a compact summary.
	 *
	 * Scheduler health checks count the full Data Machine Action Scheduler group,
	 * so this drain runs concrete due action IDs from that same group instead of
	 * a hand-maintained hook allow-list.
	 *
	 * @param array{limit?:int,batch_size?:int,time_limit?:int,hooks?:string[]} $options Drain options.
	 * @return array<string,int|string> Drain stats.
	 */
	public static function drain( array $options = array() ): array {
		$limit      = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$batch_size = max( 1, (int) ( $options['batch_size'] ?? 25 ) );
		$time_limit = max( 0, (int) ( $options['time_limit'] ?? 0 ) );
		$hooks      = self::normalizeHooks( $options['hooks'] ?? null );

		$started_at    = time();
		$before_counts = self::getStatusCounts( $hooks );
		$batches       = 0;
		$warnings      = 0;

		while ( self::getDuePendingCount( $hooks ) > 0 ) {
			$stats = self::buildStats( $before_counts, self::getStatusCounts( $hooks ), $batches, $warnings, $hooks );
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
			if ( $current_batch_size <= 0 ) {
				break;
			}

			$action_ids = self::getDuePendingActionIds( $current_batch_size, $hooks );
			if ( empty( $action_ids ) ) {
				break;
			}

			$due_before    = self::getDuePendingCount( $hooks );
			$status_before = self::getStatusCounts( $hooks );
			$result        = self::runActionSchedulerActions( $action_ids );
			++$batches;

			if ( 0 !== (int) ( $result->return_code ?? 1 ) ) {
				++$warnings;
				$message = trim( (string) ( $result->stderr ?? '' ) );
				WP_CLI::warning( '' === $message ? 'Action Scheduler CLI drain failed.' : $message );
				break;
			}

			$status_after = self::getStatusCounts( $hooks );
			$progress     = self::processedDelta( $status_before, $status_after );
			if ( 0 === $progress && self::getDuePendingCount( $hooks ) >= $due_before ) {
				++$warnings;
				WP_CLI::warning( 'Drain stopped because Action Scheduler made no observable progress.' );
				break;
			}
		}

		return self::buildStats( $before_counts, self::getStatusCounts( $hooks ), $batches, $warnings, $hooks );
	}

	/**
	 * Run specific due Action Scheduler actions.
	 *
	 * @param int[] $action_ids Action IDs.
	 * @return object WP_CLI::runcommand result object.
	 */
	private static function runActionSchedulerActions( array $action_ids ): object {
		return WP_CLI::runcommand(
			'action-scheduler action run ' . implode( ' ', array_map( 'intval', $action_ids ) ),
			array(
				'exit_error' => false,
				'launch'     => false,
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
	private static function buildStats( array $before_counts, array $after_counts, int $batches, int $warnings, ?array $hooks = null ): array {
		$batch_completed   = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'complete' );
		$batch_failed      = self::delta( $before_counts, $after_counts, self::HOOK_BATCH_CHUNK, 'failed' );
		$step_completed    = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'complete' );
		$step_failed       = self::delta( $before_counts, $after_counts, self::HOOK_EXECUTE_STEP, 'failed' );
		$total_completed   = self::statusDelta( $before_counts, $after_counts, 'complete' );
		$total_failed      = self::statusDelta( $before_counts, $after_counts, 'failed' );
		$total_processed   = $total_completed + $total_failed;
		$tracked_processed = $batch_completed + $batch_failed + $step_completed + $step_failed;

		return array(
			'batches'                    => $batches,
			'batch_chunks'               => $batch_completed + $batch_failed,
			'step_executions'            => $step_completed + $step_failed,
			'completions'                => $total_completed,
			'failures'                   => $total_failed,
			'batch_chunk_completions'    => $batch_completed,
			'batch_chunk_failures'       => $batch_failed,
			'step_execution_completions' => $step_completed,
			'step_execution_failures'    => $step_failed,
			'actions_processed'          => $total_processed,
			'other_actions'              => max( 0, $total_processed - $tracked_processed ),
			'remaining_pending'          => self::getDuePendingCount( $hooks ),
			'total_pending'              => self::getPendingCount( $hooks ),
			'warnings'                   => $warnings,
			'hooks'                      => implode( ',', self::processedHooks( $before_counts, $after_counts ) ),
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
		return self::statusDelta( $before_counts, $after_counts, 'complete' ) + self::statusDelta( $before_counts, $after_counts, 'failed' );
	}

	/**
	 * Count status deltas across all Data Machine hooks.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @param string                          $status        Action status.
	 * @return int Non-negative delta.
	 */
	private static function statusDelta( array $before_counts, array $after_counts, string $status ): int {
		$total = 0;
		foreach ( self::allHooks( $before_counts, $after_counts ) as $hook ) {
			$total += self::delta( $before_counts, $after_counts, $hook, $status );
		}
		return $total;
	}

	/**
	 * Get hooks with observed processed deltas.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @return string[] Hook names.
	 */
	private static function processedHooks( array $before_counts, array $after_counts ): array {
		$hooks = array();
		foreach ( self::allHooks( $before_counts, $after_counts ) as $hook ) {
			if ( self::delta( $before_counts, $after_counts, $hook, 'complete' ) > 0 || self::delta( $before_counts, $after_counts, $hook, 'failed' ) > 0 ) {
				$hooks[] = $hook;
			}
		}
		return $hooks;
	}

	/**
	 * Get hooks seen in either status snapshot.
	 *
	 * @param array<string,array<string,int>> $before_counts Counts before.
	 * @param array<string,array<string,int>> $after_counts  Counts after.
	 * @return string[] Hook names.
	 */
	private static function allHooks( array $before_counts, array $after_counts ): array {
		return array_values( array_unique( array_merge( array_keys( $before_counts ), array_keys( $after_counts ) ) ) );
	}

	/**
	 * Normalize hook-scope options.
	 *
	 * @param mixed $hooks Optional hook list.
	 * @return string[]|null Hook list, or null for all Data Machine hooks.
	 */
	private static function normalizeHooks( mixed $hooks ): ?array {
		if ( ! is_array( $hooks ) ) {
			return null;
		}

		$hooks = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $hooks ),
					static fn( string $hook ): bool => '' !== $hook
				)
			)
		);

		return empty( $hooks ) ? null : $hooks;
	}

	/**
	 * Build optional hook WHERE SQL for prepared Action Scheduler queries.
	 *
	 * @param string[]|null $hooks Hook scope, or null for all hooks in the group.
	 * @return array{sql:string,values:string[]} SQL fragment and placeholder values.
	 */
	private static function hookWhereSql( ?array $hooks ): array {
		if ( empty( $hooks ) ) {
			return array(
				'sql'    => '',
				'values' => array(),
			);
		}

		return array(
			'sql'    => 'a.hook IN (' . implode( ', ', array_fill( 0, count( $hooks ), '%s' ) ) . ') AND ',
			'values' => $hooks,
		);
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
	private static function getDuePendingCount( ?array $hooks = null ): int {
		return self::countActions( true, $hooks );
	}

	/**
	 * Count all pending Data Machine actions.
	 *
	 * @return int Pending count.
	 */
	private static function getPendingCount( ?array $hooks = null ): int {
		return self::countActions( false, $hooks );
	}

	/**
	 * Get due pending Data Machine action IDs in execution order.
	 *
	 * @param int $limit Maximum IDs to return.
	 * @return int[] Action IDs.
	 */
	private static function getDuePendingActionIds( int $limit, ?array $hooks = null ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = self::hookWhereSql( $hooks );
		$values        = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP, gmdate( 'Y-m-d H:i:s' ), $limit )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT a.action_id
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
				AND g.slug = %s
				AND a.scheduled_date_gmt <= %s
				ORDER BY a.scheduled_date_gmt ASC, a.action_id ASC
				LIMIT %d',
				$values
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Count pending Data Machine actions.
	 *
	 * @param bool $due_only Whether to count only due actions.
	 * @return int Pending count.
	 */
	private static function countActions( bool $due_only, ?array $hooks = null ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = self::hookWhereSql( $hooks );

		if ( $due_only ) {
			$values = array_merge(
				array( $actions_table, $groups_table ),
				$hook_sql['values'],
				array( self::GROUP, gmdate( 'Y-m-d H:i:s' ) )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*)
					FROM %i a
					INNER JOIN %i g ON g.group_id = a.group_id
					WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
					AND g.slug = %s
					AND a.scheduled_date_gmt <= %s',
					$values
				)
			);
		}

		$values = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'a.status = \'pending\'
				AND g.slug = %s',
				$values
			)
		);
	}

	/**
	 * Get action counts grouped by hook and status.
	 *
	 * @return array<string,array<string,int>> Counts by hook and status.
	 */
	private static function getStatusCounts( ?array $hooks = null ): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
		$hook_sql      = self::hookWhereSql( $hooks );
		$values        = array_merge(
			array( $actions_table, $groups_table ),
			$hook_sql['values'],
			array( self::GROUP )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.hook, a.status, COUNT(*) AS count
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE ' . $hook_sql['sql'] . 'g.slug = %s
				GROUP BY a.hook, a.status',
				$values
			)
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$hook   = (string) $row->hook;
			$status = (string) $row->status;
			if ( ! isset( $counts[ $hook ] ) ) {
				$counts[ $hook ] = array(
					'pending'  => 0,
					'complete' => 0,
					'failed'   => 0,
				);
			}
			$counts[ $hook ][ $status ] = (int) $row->count;
		}

		return $counts;
	}
}
